<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/Url.php';
function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function database(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (['entry', 'entrytmp'] as $table) {
        $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, link TEXT NOT NULL, guid TEXT, id_feed INTEGER)");
    }
    return $pdo;
}
function seed(PDO $pdo, string $table, int $id, string $link): void {
    $s = $pdo->prepare("INSERT INTO $table VALUES (?, ?, ?, 7)");
    $s->execute([$id, $link, 'guid-' . $id]);
}
$tests = [];
$tests['backfill existing retained and staged rows with canonical PHP'] = function (): void {
    check(is_file(dirname(__DIR__) . '/SqliteIndex.php'), 'SQLite identity index implementation missing');
    require_once dirname(__DIR__) . '/SqliteIndex.php';
    $pdo = database();
    seed($pdo, 'entry', 1, 'https://example.com/a&#98;?utm_source=rss');
    seed($pdo, 'entrytmp', 2, 'https://www.ndtv.com/story#publisher=newsstand');
    $index = new DeduplicateSqliteIndex($pdo);
    $index->sync();
    check($index->find('https://example.com/ab')['id'] === '1', 'Retained backfill missed entity');
    check($index->find('https://www.ndtv.com/story')['table'] === 'entrytmp', 'Staged backfill missed');
    check($index->find('https://example.com/other') === null, 'Unrelated row matched');
};
$tests['raw trigger queue tracks actual row lifecycle and transaction rollback'] = function (): void {
    require_once dirname(__DIR__) . '/SqliteIndex.php';
    $pdo = database();
    $index = new DeduplicateSqliteIndex($pdo);
    seed($pdo, 'entrytmp', 10, 'https://example.com/new?utm_source=x');
    check($index->find('https://example.com/new')['id'] === '10', 'New staging row not indexed');
    $pdo->exec("UPDATE entrytmp SET link='https://example.com/edited', id=11 WHERE id=10");
    check($index->find('https://example.com/new') === null, 'Old identity survived update');
    check($index->find('https://example.com/edited')['id'] === '11', 'ID/link update missed');
    $pdo->beginTransaction();
    seed($pdo, 'entry', 12, 'https://example.com/rollback');
    $pdo->rollBack();
    check($index->find('https://example.com/rollback') === null, 'Rolled-back insertion reserved identity');
    $pdo->exec('INSERT OR IGNORE INTO entrytmp SELECT * FROM entrytmp');
    $pdo->exec('DELETE FROM entrytmp');
    check($index->find('https://example.com/edited') === null, 'Deleted row reserved identity');
    seed($pdo, 'entrytmp', 13, 'https://example.com/edited');
    check($index->find('https://example.com/edited')['id'] === '13', 'Reimport suppressed');
};
$tests['partial index loss rebuilds instead of silently missing retained rows'] = function (): void {
    require_once dirname(__DIR__) . '/SqliteIndex.php';
    $pdo = database();
    seed($pdo, 'entry', 1, 'https://example.com/retained');
    $index = new DeduplicateSqliteIndex($pdo);
    $index->sync();
    $pdo->exec('DROP TABLE _dedup_identity');
    $index = new DeduplicateSqliteIndex($pdo);
    check($index->find('https://example.com/retained') !== null, 'Partial index loss silently disabled lookup');
};
$tests['persistent reopen is incremental and ignores heartbeat-only updates'] = function (): void {
    require_once dirname(__DIR__) . '/SqliteIndex.php';
    $pdo = database();
    $pdo->exec('ALTER TABLE entry ADD COLUMN lastSeen INTEGER');
    $pdo->exec("INSERT INTO entry (id,link,guid,id_feed) VALUES (1,'https://example.com/retained','g',1)");
    $index = new DeduplicateSqliteIndex($pdo);
    $index->sync();
    $index = new DeduplicateSqliteIndex($pdo);
    check((int)$pdo->query('SELECT COUNT(*) FROM _dedup_pending')->fetchColumn() === 0, 'Reopen requeued full backfill');
    $pdo->exec('UPDATE entry SET lastSeen=1234');
    check((int)$pdo->query('SELECT COUNT(*) FROM _dedup_pending')->fetchColumn() === 0, 'Heartbeat queued unchanged identity');
    $pdo->exec("UPDATE entry SET link='https://example.com/edited'");
    check((int)$pdo->query('SELECT COUNT(*) FROM _dedup_pending')->fetchColumn() === 1, 'Link update not queued');
    check($index->find('https://example.com/retained') === null, 'Edited link kept old identity');
    check($index->find('https://example.com/edited') !== null, 'Edited link missed');
};
$tests['hash candidate query uses covering index and verifies raw canonical equality'] = function (): void {
    require_once dirname(__DIR__) . '/SqliteIndex.php';
    $pdo = database();
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO entry VALUES (?, ?, ?, 7)');
    for ($id = 1; $id <= 10000; ++$id) { $insert->execute([$id, 'https://example.com/' . $id, 'g' . $id]); }
    $pdo->commit();
    $index = new DeduplicateSqliteIndex($pdo);
    $index->sync();
    $hash = hash('sha256', 'https://example.com/missing');
    $pdo->exec("UPDATE _dedup_identity SET key_hash='$hash' WHERE source_id='1'");
    check($index->find('https://example.com/missing') === null, 'Hash collision caused false duplicate');
    $plan = $pdo->prepare('EXPLAIN QUERY PLAN SELECT source, source_id FROM _dedup_identity WHERE key_hash = ? ORDER BY source, source_id');
    $plan->execute([$hash]);
    $detail = implode(' ', array_column($plan->fetchAll(PDO::FETCH_ASSOC), 'detail'));
    check(str_contains($detail, 'SEARCH') && str_contains($detail, 'COVERING INDEX _dedup_identity_key') && !str_contains($detail, 'SCAN'), 'Identity lookup scans: ' . $detail);
    echo 'Query plan: ' . $detail . "\n";
    $rawPlan = $pdo->query('EXPLAIN QUERY PLAN SELECT id,link,guid,id_feed FROM entry WHERE id=1')->fetchAll(PDO::FETCH_ASSOC);
    check(!str_contains(implode(' ', array_column($rawPlan, 'detail')), 'SCAN'), 'Raw candidate verification scans entries');
    check($index->find('https://example.com/10000')['id'] === '10000', 'Indexed lookup missed tail row');
};
$tests['sync composes with maintenance transactions and failure preserves pending work'] = function (): void {
    require_once dirname(__DIR__) . '/SqliteIndex.php';
    $pdo = database();
    $index = new DeduplicateSqliteIndex($pdo);
    $pdo->beginTransaction();
    seed($pdo, 'entrytmp', 1, 'https://example.com/rollback');
    check($index->find('https://example.com/rollback') !== null, 'Own transaction row not visible');
    check($pdo->inTransaction(), 'Index committed maintenance-owned work');
    $pdo->rollBack();
    check($index->find('https://example.com/rollback') === null, 'Rolled-back index identity persisted');
    seed($pdo, 'entrytmp', 2, 'https://example.com/retry');
    $pdo->exec("CREATE TRIGGER broken_index BEFORE INSERT ON _dedup_identity BEGIN SELECT RAISE(ABORT, 'test fault'); END");
    $failed = false;
    try { $index->sync(); } catch (PDOException $e) { $failed = true; }
    check($failed, 'Index write failure was hidden');
    check((int)$pdo->query('SELECT COUNT(*) FROM _dedup_pending')->fetchColumn() === 1, 'Failed sync lost pending work');
    $pdo->exec('DROP TRIGGER broken_index');
    check($index->find('https://example.com/retry') !== null, 'Failed sync could not recover');
};
$tests['hook coordination uses SQLite and fails closed'] = function (): void {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/concurrency-hooks.php'), $status);
    check($status === 0, 'Hook coordination subprocess failed');
};
$tests['multiprocess lookup insert commit and crash recovery'] = function (): void {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/concurrency.php'), $status);
    check($status === 0, 'Concurrency subprocess failed');
};
$passed = $failed = 0;
foreach ($tests as $name => $test) {
    if (isset($argv[1]) && !str_contains($name, $argv[1])) { continue; }
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch (Throwable $e) { ++$failed; echo "FAIL $name: {$e->getMessage()}\n"; }
}
echo json_encode(['passed' => $passed, 'failed' => $failed]), "\n";
exit($failed || !$passed ? 1 : 0);
