<?php
declare(strict_types=1);
// Used only by concurrency.php in a private scratch tree.
[$script, $root, $user, $role] = $argv;
$resolved = realpath($root);
if ($resolved === false || $resolved !== $root || !str_starts_with(basename($root), 'dedup-process-')
    || !in_array($user, ['alice', 'bob'], true)) { throw new RuntimeException('Unsafe concurrency fixture path'); }
define('USERS_PATH', $root);
class Minz_Extension {
    public array $hooks = [];
    protected function registerTranslates(): void {}
    protected function registerHook($name, $callback, $priority = 0): void { $this->hooks[$name] = $callback; }
}
class Minz_Log {
    public static function error(string $message): void { fwrite(STDERR, $message . "\n"); }
    public static function warning(string $message): void {}
}
class Minz_User { public static string $user; public static function name(): string { return self::$user; } }
class FreshRSS_Context { public static function systemConf(): object { return (object)['db' => ['type' => 'sqlite']]; } }
Minz_User::$user = $user;
function invalidateHttpCache(): void {}
$core = getenv('FRESHRSS_TEST_ROOT') ?: '/var/www/FreshRSS';
$realCore = is_file($core . '/app/Models/EntryDAOSQLite.php');
if ($realCore) {
    foreach (['lib/Minz/Pdo.php', 'lib/Minz/PdoSqlite.php', 'lib/Minz/ModelPdo.php',
        'app/Models/EntryDAO.php', 'app/Models/EntryDAOSQLite.php'] as $file) { require_once $core . '/' . $file; }
}
class FreshRSS_Factory {
    public static object $dao;
    public static function createEntryDao(): object { return self::$dao; }
}
$pdo = $realCore ? new Minz_PdoSqlite('sqlite:' . $root . '/' . $user . '/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
    : new PDO('sqlite:' . $root . '/' . $user . '/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = 3000');
FreshRSS_Factory::$dao = $realCore ? new FreshRSS_EntryDAOSQLite($user, $pdo) : new class($pdo) {
    public function __construct(private PDO $pdo) {}
    public function inTransaction(): bool { return $this->pdo->inTransaction(); }
};
require_once dirname(__DIR__) . '/extension.php';
function waitFile(string $path): void {
    $until = microtime(true) + 15;
    while (!is_file($path)) {
        if (microtime(true) > $until) { throw new RuntimeException('Concurrency barrier timeout'); }
        usleep(10000);
    }
}
function commitRows(PDO $pdo, bool $realCore): void {
    if ($realCore) {
        if (!FreshRSS_Factory::createEntryDao()->commitNewEntries()) { throw new RuntimeException('Core commit failed'); }
    } else {
        // Portable CI exercises the same row move/ID rewrite; installed-core
        // runs invoke the unmodified FreshRSS EntryDAOSQLite method above.
        $pdo->beginTransaction();
        $pdo->exec('INSERT OR IGNORE INTO entry (id,link,guid,id_feed) SELECT id+100,link,guid,id_feed FROM entrytmp; DELETE FROM entrytmp');
        $pdo->commit();
    }
}
if ($role === 'unlocked-commit') {
    // actualize-user.php commits old staged rows BEFORE the feeds-list hook.
    // This writer intentionally does not cooperate with the refresh flock.
    commitRows($pdo, $realCore);
    file_put_contents($root . '/unlocked-commit', $realCore ? 'FreshRSS_EntryDAOSQLite::commitNewEntries' : 'portable row-move fixture');
    exit;
}
$extension = new DeduplicateEntriesExtension();
$extension->init();
file_put_contents($root . '/' . $role . '-attempted', '1');
if (($extension->hooks['feeds_list_before_actualize'])([1]) !== [1]) { throw new RuntimeException('Refresh initialization blocked'); }
file_put_contents($root . '/' . $role . '-entered', '1');
if ($role === 'crash') { waitFile($root . '/never'); exit; }
$entry = new class($role) {
    public function __construct(private string $role) {}
    public function link(): string {
        return 'https://www.ndtv.com/concurrent' . ($this->role === 'holder' ? '#publisher=newsstand' : '');
    }
    public function feedId(): int { return 1; }
};
$allowed = $extension->deduplicateEntry($entry) !== null;
if ($role === 'holder') {
    file_put_contents($root . '/lookup-allowed', $allowed ? '1' : '0');
    waitFile($root . '/finish-insert');
}
if ($allowed) {
    $s = $pdo->prepare('INSERT INTO entrytmp (id,link,guid,id_feed,date) VALUES (?, ?, ?, 1, 1)');
    $s->execute([10, $entry->link(), 'guid-' . $role]);
    if ($role === 'holder') {
        // The real SQLite commit reorders by publication date and rewrites IDs.
        $pdo->exec("INSERT INTO entrytmp (id,link,guid,id_feed,date) VALUES (20, 'https://example.com/older', 'older-guid', 1, 0)");
    }
}
file_put_contents($root . '/' . $role . '-result', $allowed ? 'inserted' : 'suppressed');
if ($role === 'holder') {
    // Lock must stay held not just through the hook, but after normal code has
    // returned and throughout the shutdown callback which commits core rows.
    register_shutdown_function(function () use ($root, $pdo, $realCore): void {
        file_put_contents($root . '/shutdown-entered', '1');
        waitFile($root . '/finish-shutdown');
        commitRows($pdo, $realCore);
        file_put_contents($root . '/shutdown-committed', '1');
    });
}
