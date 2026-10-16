<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/SqliteIndex.php';
function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function waitFile(string $path): void {
    $until = microtime(true) + 12;
    while (!is_file($path)) {
        if (microtime(true) > $until) { throw new RuntimeException('Barrier timeout: ' . basename($path)); }
        usleep(10000);
    }
}
$root = sys_get_temp_dir() . '/dedup-process-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$children = [];
function spawn(string $user, string $role) {
    global $root, $children;
    $process = proc_open([PHP_BINARY, __DIR__ . '/concurrency-worker.php', $root, $user, $role],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/' . $role . '.out', 'w'],
            2 => ['file', $root . '/' . $role . '.err', 'w']], $pipes);
    check(is_resource($process), 'Cannot spawn worker');
    $children[$role] = $process;
    return $process;
}
function joinWorker(string $role): void {
    global $children, $root;
    $status = proc_close($children[$role]);
    unset($children[$role]);
    check($status === 0, "$role failed: " . file_get_contents($root . '/' . $role . '.err'));
}
try {
    foreach (['alice', 'bob'] as $user) {
        mkdir($root . '/' . $user, 0700);
        $pdo = new PDO('sqlite:' . $root . '/' . $user . '/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['entry', 'entrytmp'] as $table) {
            $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, link TEXT NOT NULL, guid TEXT, id_feed INTEGER,
                title TEXT, author TEXT, content TEXT, date INTEGER DEFAULT 0, lastSeen INTEGER, hash BLOB,
                is_read INTEGER, is_favorite INTEGER, tags TEXT, attributes TEXT)");
        }
        unset($pdo);
    }
    check(is_resource(DeduplicateRefreshLock::acquire($root . '/bob')), 'Lock API did not return held resource');
    DeduplicateRefreshLock::acquire($root . '/bob');
    $other = fopen($root . '/bob/.deduplicate-refresh.lock', 'c');
    check(!flock($other, LOCK_EX | LOCK_NB), 'Reentrant lock lost original resource');
    fclose($other);
    $failed = false;
    try { DeduplicateRefreshLock::acquire($root . '/missing'); }
    catch (RuntimeException $e) { $failed = true; }
    check($failed, 'Missing directory did not fail closed');
    echo "PASS reentrant process-lifetime lock and fail-closed initialization\n";

    spawn('alice', 'holder');
    waitFile($root . '/lookup-allowed');
    check(file_get_contents($root . '/lookup-allowed') === '1', 'First lookup was not allowed');
    // Bob remains locked by this parent, yet Alice entered successfully.
    echo "PASS locks are user-scoped, not global\n";
    spawn('alice', 'contender');
    waitFile($root . '/contender-attempted');
    usleep(200000);
    check(!is_file($root . '/contender-entered'), 'Second refresh entered between first lookup and insertion');
    file_put_contents($root . '/finish-insert', '1');
    waitFile($root . '/shutdown-entered');
    check(file_get_contents($root . '/holder-result') === 'inserted', 'First lookup did not insert');
    check(!is_file($root . '/contender-entered'), 'Second refresh entered during first shutdown commit');

    spawn('alice', 'unlocked-commit');
    joinWorker('unlocked-commit');
    check(is_file($root . '/unlocked-commit'), 'Initial CLI commit did not complete outside lock');
    $pdo = new PDO('sqlite:' . $root . '/alice/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $row = $pdo->query("SELECT id FROM entry WHERE guid='guid-holder'")->fetch(PDO::FETCH_ASSOC);
    check($row !== false && (int)$row['id'] !== 10, 'Staged-to-retained commit did not rewrite keeper ID');
    check((int)$pdo->query('SELECT COUNT(*) FROM entrytmp')->fetchColumn() === 0, 'Initial CLI commit left staged rows');
    unset($pdo);
    check(!is_file($root . '/contender-entered'), 'Core commit unexpectedly released refresh lock');
    file_put_contents($root . '/finish-shutdown', '1');
    joinWorker('holder');
    joinWorker('contender');
    check(file_get_contents($root . '/contender-result') === 'suppressed', 'Concurrent canonical duplicate inserted');
    $pdo = new PDO('sqlite:' . $root . '/alice/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $index = new DeduplicateSqliteIndex($pdo);
    $keeper = $index->find('https://www.ndtv.com/concurrent');
    check($keeper !== null && $keeper['table'] === 'entry' && $keeper['id'] === (string)$row['id'], 'Index missed rewritten retained ID');
    check((int)$pdo->query("SELECT COUNT(*) FROM entry WHERE link LIKE 'https://www.ndtv.com/concurrent%'")->fetchColumn() === 1,
        'Concurrent refresh persisted multiple copies');
    echo 'PASS multiprocess SQLite lookup+insert serialized through shutdown and ' . file_get_contents($root . '/unlocked-commit') . " outside lock\n";
    unset($index, $pdo);

    $crash = spawn('alice', 'crash');
    waitFile($root . '/crash-entered');
    proc_terminate($crash, 9);
    proc_close($crash);
    unset($children['crash']);
    spawn('alice', 'after-crash');
    joinWorker('after-crash');
    check(file_get_contents($root . '/after-crash-result') === 'suppressed', 'OS did not release crashed process lock');
    echo "PASS killed refresher releases lock without stale claims\n";
} finally {
    // All files are private synthetic fixtures. Never remove a live lock inode.
    foreach ($children as $child) { proc_terminate($child, 9); proc_close($child); }
}
