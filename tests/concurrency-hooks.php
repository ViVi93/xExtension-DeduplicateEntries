<?php
declare(strict_types=1);
// Scratch-only framework doubles. SQLite writes are real; no production bootstrap.
class Minz_Extension {
    public array $hooks = [];
    protected function registerTranslates(): void {}
    protected function registerHook($name, $callback, $priority = 0): void { $this->hooks[$name] = $callback; }
}
class Minz_Log {
    public static array $messages = [];
    public static function error(string $message): void { self::$messages[] = $message; }
    public static function warning(string $message): void { self::$messages[] = $message; }
}
class Minz_User { public static string $user = 'alice'; public static function name(): string { return self::$user; } }
class FreshRSS_Context { public static function systemConf(): object { return (object)['db' => ['type' => 'sqlite']]; } }
class FreshRSS_Factory {
    public static ?object $dao = null;
    public static function createEntryDao(): object {
        return self::$dao ??= new class {
            public bool $transaction = false;
            public function inTransaction(): bool { return $this->transaction; }
            public function fetchAssoc(string $sql, array $values = []): array { throw new RuntimeException('Portable matcher must not run for SQLite'); }
        };
    }
}
class TestEntry {
    public function __construct(private string $url) {}
    public function link(): string { return $this->url; }
    public function feedId(): int { return 2; }
}
require_once dirname(__DIR__) . '/extension.php';
function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
$root = sys_get_temp_dir() . '/dedup-hook-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/alice', 0700);
define('USERS_PATH', $root);
$pdo = new PDO('sqlite:' . $root . '/alice/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['entry', 'entrytmp'] as $table) { $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, link TEXT, guid TEXT, id_feed INTEGER)"); }
$ext = new DeduplicateEntriesExtension();
$ext->init();
check(isset($ext->hooks['feeds_list_before_actualize']), 'Refresh coordination hook missing');
check(!isset($ext->hooks['entry_before_insert']), 'Updates must remain unaffected');
check(($ext->hooks['feeds_list_before_actualize'])([17]) === [17], 'Refresh initialization failed');
check(($ext->hooks['feeds_list_before_actualize'])([18]) === [18], 'Refresh lock not reentrant');
$pdo->exec("INSERT INTO entrytmp VALUES (1, 'https://www.ndtv.com/story#publisher=newsstand', 'g', 1)");
check($ext->deduplicateEntry(new TestEntry('https://www.ndtv.com/story')) === null, 'SQLite index not used');
$entry = new TestEntry('https://example.com/new');
check($ext->deduplicateEntry($entry) === $entry, 'Unrelated new entry blocked');
check($ext->deduplicateEntry($entry) === $entry, 'Failed insertion left phantom reservation');
// Releasing every query transaction is required: this core PDO must write immediately.
$pdo->beginTransaction();
$pdo->exec("INSERT INTO entrytmp VALUES (2, 'https://example.com/new', 'g2', 1)");
$pdo->commit();
check($ext->deduplicateEntry($entry) === null, 'New raw staging event not seen');
mkdir($root . '/bob', 0700);
mkdir($root . '/bob/.deduplicate-refresh.lock', 0700);
Minz_User::$user = 'bob';
check(($ext->hooks['feeds_list_before_actualize'])([17]) === [], 'Cannot-open lock did not stop refresh');
check($ext->deduplicateEntry($entry) === null, 'Cannot-open lock allowed unprotected insert');
Minz_User::$user = '../alice';
check(($ext->hooks['feeds_list_before_actualize'])([17]) === [], 'Invalid user path did not stop refresh');
check($ext->deduplicateEntry($entry) === null, 'Invalid user path allowed unprotected insert');
Minz_User::$user = 'alice';
FreshRSS_Factory::createEntryDao()->transaction = true;
check(($ext->hooks['feeds_list_before_actualize'])([17]) === [], 'Active core transaction risks separate-PDO deadlock');
check($ext->deduplicateEntry(new TestEntry('https://example.com/unsafe')) === null, 'Core transaction allowed unsafe lookup');
FreshRSS_Factory::createEntryDao()->transaction = false;
$pdo->exec('DROP TABLE _dedup_identity');
check($ext->deduplicateEntry(new TestEntry('https://example.com/unsafe')) === null, 'Broken index did not fail closed');
check(str_contains(implode(' ', Minz_Log::$messages), 'refresh_error'), 'Missing refresh error diagnostic');
check(!str_contains(implode(' ', Minz_Log::$messages), $root), 'User directory leaked into logs');
echo "PASS SQLite hook coordination, lifecycle, fail-closed path/index/core transaction checks\n";
