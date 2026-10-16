<?php
declare(strict_types=1);

// No FreshRSS installation or production DB is needed for these unit tests.
class Minz_Extension {
    public array $hooks = [];
    protected function registerTranslates(): void {}
    protected function registerHook($name, $callback, $priority = 0): void { $this->hooks[$name] = $callback; }
}
class Minz_Log {
    public static array $messages = [];
    public static function warning($message): void { self::$messages[] = $message; }
    public static function error($message): void { self::$messages[] = $message; }
}
function _t($key, ...$args) { return $key; }
class TestDao {
    public PDO $pdo;
    public bool $fail = false;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['entry', 'entrytmp'] as $table) {
            $this->pdo->exec("CREATE TABLE $table (id TEXT PRIMARY KEY, link TEXT, guid TEXT, id_feed INTEGER, title TEXT)");
        }
    }
    public function fetchAssoc(string $sql, array $values = []): ?array {
        if ($this->fail) { return null; }
        $sql = str_replace(['`_entrytmp`', '`_entry`'], ['`entrytmp`', '`entry`'], $sql);
        $s = $this->pdo->prepare($sql);
        foreach ($values as $name => $value) { $s->bindValue($name, $value); }
        $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function seed(string $table, string $link, string $title = 'Original headline'): void {
        $s = $this->pdo->prepare("INSERT INTO $table VALUES ('101', :link, 'original-guid', 78, :title)");
        $s->execute([':link' => $link, ':title' => $title]);
    }
}
class FreshRSS_Factory {
    public static TestDao $dao;
    public static function createEntryDao(): TestDao { return self::$dao; }
}
class TestEntry {
    public function __construct(private string $url, private string $headline = 'Changed headline') {}
    public function link(): string { return $this->url; }
    public function title(): string { return $this->headline; }
    public function feedId(): int { return 81; }
    public function guid(): string { return 'incoming-guid'; }
    public function isUpdated(): bool { return false; }
}
require dirname(__DIR__) . '/extension.php';

$tests = [];
function test(string $name, callable $fn): void {
    global $tests;
    $tests[$name] = $fn;
}
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function canonical(string $url): string {
    return DeduplicateUrl::canonical($url);
}
function same(string $a, string $b): void {
    check(canonical($a) === canonical($b), "Expected equal identities: $a / $b");
}
function different(string $a, string $b): void {
    check(canonical($a) !== canonical($b), "Unexpected identity collision: $a / $b");
}

test('NDTV publisher marker has symmetric identity', function (): void {
    foreach (['www.ndtv.com', 'www.ndtvprofit.com', 'sports.ndtv.com'] as $host) {
        same("https://$host/story-12187207", "https://$host/story-12187207#publisher=newsstand");
    }
});

test('canonicalization strips only explicit trackers and preserves page identity', function (): void {
    same('HTTPS://EXAMPLE.COM/Story?id=5', 'https://example.com/Story?utm_source=rss&id=5&fbclid=x');
    same('https://example.com/Story?id=5&lang=en', 'https://example.com/Story?id=5&amp;utm_medium=feed&amp;lang=en');
    same('https://example.com/Story', 'https://example.com/Story?utm_campaign=x&gclid=y');
    foreach ([
        ['https://example.com/Story', 'https://example.com/story'],
        ['https://example.com/story?id=1', 'https://example.com/story?id=2'],
        ['https://example.com/story', 'https://example.com/story#chapter2'],
        ['https://example.com/#/one', 'https://example.com/#/two'],
        ['https://www.ndtv.com/story#one', 'https://www.ndtv.com/story#two'],
        ['https://not-ndtv.com/story', 'https://not-ndtv.com/story#publisher=newsstand'],
        ['https://www.ndtv.com.evil.test/story', 'https://www.ndtv.com.evil.test/story#publisher=newsstand'],
        ['http://example.com/story', 'https://example.com/story'],
        ['https://example.com/story', 'https://example.com/story/'],
        ['https://example.com/story?ref=one', 'https://example.com/story?ref=two'],
        ['https://example.com/story?a=1&a=2', 'https://example.com/story?a=2&a=1'],
    ] as [$a, $b]) { different($a, $b); }
    foreach (['', '/relative#chapter', 'mailto:a@example.com', 'http://[invalid', 'https://user:pass@example.com/a?utm_source=x'] as $url) {
        check(canonical($url) === $url, 'Ambiguous/non-web input must be unchanged');
    }
});

test('raw port spellings remain conservative and candidate decisions agree', function (): void {
    foreach ([
        ['https://example.com:0443/story', 'https://example.com:443/story'],
        ['https://example.com:/story', 'https://example.com/story'],
        ['https://[::1]:0443/story', 'https://[::1]:443/story'],
    ] as [$a, $b]) {
        different($a, $b);
        foreach (['entry', 'entrytmp'] as $table) {
            foreach ([[$a, $b], [$b, $a]] as [$stored, $incoming]) {
                FreshRSS_Factory::$dao = new TestDao();
                FreshRSS_Factory::$dao->seed($table, $stored);
                $entry = new TestEntry($incoming);
                check((new DeduplicateEntriesExtension())->deduplicateEntry($entry) === $entry, 'Port syntax was merged');
            }
        }
    }
    same('HTTPS://EXAMPLE.COM:0443/story?utm_source=x', 'https://example.com:0443/story');
    same('https://example.com&colon;0443/story', 'https://example.com:0443/story');
});

test('new-only hook prevents self-update suppression', function (): void {
    $ext = new DeduplicateEntriesExtension();
    $ext->init();
    check(isset($ext->hooks['entry_before_add']), 'Missing new-entry-only hook');
    check(!isset($ext->hooks['entry_before_insert']), 'Update path must not be intercepted');
});

test('both arrival orders and both tables block the reported NDTV duplicates', function (): void {
    foreach ([
        'https://www.ndtv.com/world-news/anthropics-q3-revenue-surges-45-to-nearly-17-billion-12187207',
        'https://www.ndtv.com/india-news/chenab-rail-bridge-to-be-new-tourist-destination-in-jammu-and-kashmir-12187213',
        'https://www.ndtv.com/delhi-news/can-ai-help-delhi-tackle-water-leaks-new-system-to-flag-pipe-burst-risks-12187225',
    ] as $base) {
        foreach (['entry', 'entrytmp'] as $table) {
            foreach ([[$base, $base . '#publisher=newsstand'], [$base . '#publisher=newsstand', $base]] as [$stored, $incoming]) {
                FreshRSS_Factory::$dao = new TestDao();
                FreshRSS_Factory::$dao->seed($table, $stored);
                $ext = new DeduplicateEntriesExtension();
                check($ext->deduplicateEntry(new TestEntry($incoming)) === null, "Duplicate allowed in $table: $stored -> $incoming");
            }
        }
    }
});

test('candidate query handles trackers without collapsing meaningful differences', function (): void {
    $cases = [
        ['https://EXAMPLE.com/Story?utm_source=rss&id=5', 'https://example.com/Story?id=5', true],
        ['https://example.com/a%20b_!c?utm_source=x', 'https://example.com/a%20b_!c', true],
        ['https://example.com/aX20bZ!c', 'https://example.com/a%20b_!c', false],
        ['https://example.com/story?id=2', 'https://example.com/story?id=1', false],
        ['https://example.com/Story', 'https://example.com/story', false],
        ['https://www.ndtv.com/story#chapter2', 'https://www.ndtv.com/story', false],
        ['https://example.com/#/one', 'https://example.com/#/two', false],
        ['https://example.com/episode1', 'https://example.com/episode2', false],
        ['https://example.com/story', '', false],
        ['https://example.com/story#one', 'https://example.com/story#two', false],
        ['https://example.com/a&amp;b?utm_source=rss', 'https://example.com/a&b', true],
        ['https://www.ndtv.com/story?utm_source=x#publisher=newsstand', 'https://www.ndtv.com/story', true],
    ];
    foreach (['entry', 'entrytmp'] as $table) {
        foreach ($cases as [$stored, $incoming, $duplicate]) {
            FreshRSS_Factory::$dao = new TestDao();
            FreshRSS_Factory::$dao->seed($table, $stored, 'Inside Health');
            $entry = new TestEntry($incoming, 'Inside Health');
            $result = (new DeduplicateEntriesExtension())->deduplicateEntry($entry);
            check(($result === null) === $duplicate, "Incorrect decision: $stored / $incoming in $table");
            check($entry->link() === $incoming && $entry->guid() === 'incoming-guid', 'Original identity mutated');
        }
    }
});

test('entity candidates preserve canonical identity in both tables and arrival orders', function (): void {
    $cases = [
        ['https://example.com/a&#98;', 'https://example.com/ab', true],
        ['https://example.com/a&#x62;', 'https://example.com/ab', true],
        ['https://example.com/a&apos;b', "https://example.com/a'b", true],
        ['https://example.com/a&amp;b', 'https://example.com/a&b', true],
        ['https://ex&#97;mple.com/ab', 'https://example.com/ab', true],
        ['https://example&period;com/ab', 'https://example.com/ab', true],
        ['&#104;ttps://example.com/ab', 'https://example.com/ab', true],
        ['https&colon;&sol;&sol;example.com/ab', 'https://example.com/ab', true],
        ['https:/&#47;example.com/ab', 'https://example.com/ab', true],
        ['https://example.com&colon;443/ab', 'https://example.com:443/ab', true],
        ['https://example.com/a&#98;', 'https://example.com/ac', false],
        ['https://example.com/a&apos;b', "https://example.com/A'b", false],
        ['https://other&period;com/ab', 'https://example.com/ab', false],
        ['http&colon;//example.com/ab', 'https://example.com/ab', false],
        ['https://example.com/ab?id=&#50;', 'https://example.com/ab?id=1', false],
        ['https://example.com/ab#chapter&#50;', 'https://example.com/ab#chapter1', false],
    ];
    $failures = [];
    $checks = 0;
    foreach (['entry', 'entrytmp'] as $table) {
        foreach ($cases as [$a, $b, $duplicate]) {
            check((canonical($a) === canonical($b)) === $duplicate, "Invalid entity fixture: $a / $b");
            foreach ([[$a, $b], [$b, $a]] as [$stored, $incoming]) {
                FreshRSS_Factory::$dao = new TestDao();
                FreshRSS_Factory::$dao->seed($table, $stored);
                $entry = new TestEntry($incoming);
                $result = (new DeduplicateEntriesExtension())->deduplicateEntry($entry);
                if (($result === null) !== $duplicate) {
                    $failures[] = "Incorrect entity decision in $table: $stored -> $incoming";
                }
                check($entry->link() === $incoming && $entry->guid() === 'incoming-guid', 'Original identity mutated');
                $checks++;
            }
        }
    }
    check($failures === [], count($failures) . " failed entity checks of $checks: " . implode('; ', $failures));
    echo "Entity candidate cases passed: $checks\n";
});

test('failed insertion leaves no phantom identity and deletion permits reimport', function (): void {
    FreshRSS_Factory::$dao = new TestDao();
    $ext = new DeduplicateEntriesExtension();
    $entry = new TestEntry('https://www.ndtv.com/story#publisher=newsstand');
    check($ext->deduplicateEntry($entry) === $entry, 'First insertion should be allowed');
    // Caller did not insert the allowed row. No reservation/cache may suppress a retry.
    check($ext->deduplicateEntry(new TestEntry('https://www.ndtv.com/story')) !== null, 'Phantom reservation');
    FreshRSS_Factory::$dao->seed('entrytmp', $entry->link());
    check($ext->deduplicateEntry(new TestEntry('https://www.ndtv.com/story')) === null, 'Staged row must count');
    FreshRSS_Factory::$dao->pdo->exec('DELETE FROM entrytmp');
    check($ext->deduplicateEntry(new TestEntry('https://www.ndtv.com/story')) !== null, 'Deleted entry must not reserve identity');
});

test('database lookup failures preserve ingestion with an explicit error', function (): void {
    FreshRSS_Factory::$dao = new TestDao();
    FreshRSS_Factory::$dao->fail = true;
    Minz_Log::$messages = [];
    $entry = new TestEntry('https://example.com/story');
    check((new DeduplicateEntriesExtension())->deduplicateEntry($entry) === $entry, 'DB failure must preserve entry');
    check(str_contains(implode(' ', Minz_Log::$messages), 'lookup_error'), 'Missing explicit error');
});

test('decisions identify keeper without leaking article URL or credentials', function (): void {
    FreshRSS_Factory::$dao = new TestDao();
    FreshRSS_Factory::$dao->seed('entry', 'https://example.com/story');
    Minz_Log::$messages = [];
    check((new DeduplicateEntriesExtension())->deduplicateEntry(new TestEntry('https://example.com/story?utm_source=x')) === null, 'Expected duplicate');
    $log = implode(' ', Minz_Log::$messages);
    check(str_contains($log, 'canonical_url') && str_contains($log, 'keeper_id'), 'Missing keeper diagnostics');
    check(!str_contains($log, 'https://'), 'URL leaked into log');
});

if (($replay = getenv('DEDUP_REPLAY_JSON')) !== false && $replay !== '') {
    test('captured URL pairs replay both arrival orders in both tables', function () use ($replay): void {
        $groups = json_decode(file_get_contents($replay), true, flags: JSON_THROW_ON_ERROR);
        $cases = 0;
        foreach ($groups as $group) {
            foreach (['entry', 'entrytmp'] as $table) {
                foreach ([[$group[0]['link'], $group[1]['link']], [$group[1]['link'], $group[0]['link']]] as [$stored, $incoming]) {
                    FreshRSS_Factory::$dao = new TestDao();
                    FreshRSS_Factory::$dao->seed($table, $stored);
                    check((new DeduplicateEntriesExtension())->deduplicateEntry(new TestEntry($incoming)) === null,
                        "Captured duplicate missed in $table: $stored -> $incoming");
                    $cases++;
                }
            }
        }
        check($cases > 0, 'Empty replay is not verification');
        echo "Replay cases passed: $cases\n";
    });
}

$filter = $argv[1] ?? '';
$passed = $failed = 0;
foreach ($tests as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) { continue; }
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; }
}
echo json_encode(['passed' => $passed, 'failed' => $failed]), "\n";
exit($failed > 0 || $passed === 0 ? 1 : 0);
