<?php
declare(strict_types=1);

// Run only against disposable DATA_PATH, never a real user's FreshRSS database.
$dataPath = getenv('DATA_PATH') ?: '';
$root = '/tmp/dedup-integration';
$resolved = realpath($dataPath);
// Refuse traversal and all symlink escapes before bootstrapping any write path.
if (realpath($root) !== $root || $resolved === false || $resolved !== rtrim($dataPath, '/')
    || !str_starts_with($resolved, $root . '/') || is_link($dataPath)) {
    throw new RuntimeException('Set DATA_PATH to a resolved disposable /tmp/dedup-integration/... directory');
}
$expectedDb = $resolved . '/users/tester/db.sqlite';
if (realpath($expectedDb) !== $expectedDb) {
    throw new RuntimeException('Disposable tester database must exist without symlink components');
}
define('TMP_PATH', '/tmp/dedup-integration/locks');
define('THIRDPARTY_EXTENSIONS_PATH', '/tmp/dedup-integration/extensions');
require '/var/www/FreshRSS/cli/_cli.php';
cliInitUser('tester');
Minz_ExtensionManager::enableByList(['DeduplicateEntries' => true], 'system');
$pdo = new PDO('sqlite:' . USERS_PATH . '/tester/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DELETE FROM entrytmp; DELETE FROM entry; DELETE FROM feed;');
$category = (int)$pdo->query('SELECT MIN(id) FROM category')->fetchColumn();
$feedDao = FreshRSS_Factory::createFeedDao();
foreach ([101, 102] as $id) {
    $result = $feedDao->addFeed(['id' => $id, 'url' => "https://fixture.invalid/feed-$id", 'category' => $category,
        'name' => "Fixture $id", 'website' => 'https://fixture.invalid/', 'description' => 'Fixture', 'lastUpdate' => 0,
        'attributes' => ['pubsubhubbub_enabled' => false]]);
    if ($result !== $id) { throw new RuntimeException('Failed to insert fixture feed'); }
}
function expect(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}
function ingest(int $feed, string $guid, string $link, string $title, bool $commit): void {
    $escape = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $raw = '<?xml version="1.0"?><rss version="2.0"><channel><title>Fixture</title><link>https://fixture.invalid/</link><description>Offline fixture</description>'
        . '<item><guid isPermaLink="false">' . $escape($guid) . '</guid><link>' . $escape($link) . '</link><title>' . $escape($title)
        . '</title><description>Content ' . $escape($title) . '</description><pubDate>' . gmdate(DATE_RSS) . '</pubDate></item></channel></rss>';
    $sp = new FreshRSS_SimplePieCustom();
    $sp->enable_cache(false);
    $sp->set_raw_data($raw);
    if (!$sp->init()) { throw new RuntimeException('Fixture XML parse failed: ' . $sp->error()); }
    if ($commit) {
        FreshRSS_feed_Controller::actualizeFeedsAndCommit($feed, null, null, $sp);
    } else {
        FreshRSS_feed_Controller::actualizeFeeds($feed, null, null, $sp);
    }
}
$cases = 0;
foreach ([true, false] as $commitFirst) {
    foreach ([true, false] as $fragmentFirst) {
        $pdo->exec('DELETE FROM entrytmp; DELETE FROM entry;');
        $base = 'https://www.ndtv.com/world-news/fixture-12187207';
        $first = $base . ($fragmentFirst ? '#publisher=newsstand' : '');
        $second = $base . ($fragmentFirst ? '' : '#publisher=newsstand');
        ingest(101, 'fixture-a', $first, 'Original title', $commitFirst);
        ingest(102, 'fixture-b', $second, 'Edited headline', false);
        $total = (int)$pdo->query('SELECT (SELECT COUNT(*) FROM entry) + (SELECT COUNT(*) FROM entrytmp)')->fetchColumn();
        expect($total === 1, 'Real refresh deduplicates ' . ($fragmentFirst ? 'fragment-first' : 'plain-first') . ' against ' . ($commitFirst ? 'entry' : 'entrytmp'));
        FreshRSS_feed_Controller::commitNewEntries();
        $before = $pdo->query('SELECT id,link,guid FROM entry')->fetch(PDO::FETCH_ASSOC);
        $pdo->exec('UPDATE entry SET is_favorite=1, is_read=1');
        ingest(101, 'fixture-a', $first, 'Legitimate updated headline', true);
        $after = $pdo->query('SELECT id,link,guid,title,is_favorite FROM entry')->fetch(PDO::FETCH_ASSOC);
        expect($after['title'] === 'Legitimate updated headline' && $after['id'] === $before['id']
            && $after['link'] === $before['link'] && $after['guid'] === $before['guid'] && (int)$after['is_favorite'] === 1,
            'Real refresh persists article update and preserves identity/favourite');
        $cases += 2;
    }
}
// Exercise the real parser/hook path beyond publisher fragments. To reproduce
// legacy encoded-path rows exactly, seed their retained link representation only
// after the first real refresh, then ingest the second item through the controller.
foreach ([
    ['https://example.com/a&#98;', 'https://example.com/ab', true],
    ['https://example.com/a&apos;b', "https://example.com/a'b", true],
    ['HTTPS://EXAMPLE.COM/Story?utm_source=rss&id=5', 'https://example.com/Story?id=5', true],
    ['https://example.com/story?id=1', 'https://example.com/story?id=2', false],
    ['https://example.com/#/one', 'https://example.com/#/two', false],
    ['https://example.com/episode1', 'https://example.com/episode2', false],
] as [$left, $right, $duplicate]) {
    foreach ([[$left, $right], [$right, $left]] as [$stored, $incoming]) {
        foreach ([true, false] as $commitFirst) {
            $pdo->exec('DELETE FROM entrytmp; DELETE FROM entry;');
            ingest(101, 'expanded-a', 'https://example.com/seed', 'Inside Health', $commitFirst);
            $table = $commitFirst ? 'entry' : 'entrytmp';
            $s = $pdo->prepare("UPDATE $table SET link=:link");
            $s->execute([':link' => $stored]);
            // Incoming fixtures denote FreshRSS's retained HTML-encoded form;
            // the RSS XML must contain the corresponding URL, not double encoding.
            ingest(102, 'expanded-b', html_entity_decode($incoming, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'Inside Health', false);
            $total = (int)$pdo->query('SELECT (SELECT COUNT(*) FROM entry) + (SELECT COUNT(*) FROM entrytmp)')->fetchColumn();
            expect($total === ($duplicate ? 1 : 2), 'Expanded real refresh preserves canonical decision in ' . $table);
            $cases++;
        }
    }
}
expect(_t('ext.deduplicate.name') === 'Deduplicate Entries', 'FreshRSS translation resolves instead of displaying key');
$cases++;
echo json_encode(['integration_checks_passed' => $cases, 'data_path' => DATA_PATH]), "\n";
