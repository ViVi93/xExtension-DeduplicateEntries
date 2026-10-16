<?php
declare(strict_types=1);
require_once __DIR__ . '/Url.php';

/**
 * A user-scoped refresh/maintenance lock. Reentrant in one PHP process; the
 * resource deliberately stays open through every shutdown callback and closes
 * only at process teardown (also on crashes). Never unlink the lock file.
 */
final class DeduplicateRefreshLock {
    /** @var array<string,resource> */
    private static array $held = [];

    /** @return resource Held until process exit; callers must not close/unlock it. */
    public static function acquire(string $userDirectory) {
        $directory = realpath($userDirectory);
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('Refresh lock user directory unavailable');
        }
        if (isset(self::$held[$directory])) { return self::$held[$directory]; }
        $path = $directory . '/.deduplicate-refresh.lock';
        if (is_link($path)) { throw new RuntimeException('Refresh lock path invalid'); }
        $handle = @fopen($path, 'c');
        if ($handle === false) { throw new RuntimeException('Refresh lock open failed'); }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Refresh lock acquisition failed');
        }
        self::$held[$directory] = $handle;
        return $handle;
    }
}

/**
 * Extension-owned SQLite index. Raw URLs are authoritative; hashes only select
 * candidates. Acquire DeduplicateRefreshLock before refresh/cleanup work. This
 * class never acquires a filesystem lock or commits a caller-owned transaction.
 * Triggers observe raw row changes even on core connections without PHP helpers.
 * Schema version 1 must be bumped/rebuilt if canonical identity rules change.
 */
final class DeduplicateSqliteIndex {
    public function __construct(private PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new InvalidArgumentException('SQLite required');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->transaction(function () use ($pdo): void {
            $required = ['_dedup_identity', '_dedup_identity_key', '_dedup_pending', '_dedup_meta'];
            foreach (['entry', 'entrytmp'] as $table) {
                foreach (['insert', 'update', 'delete'] as $event) { $required[] = "_dedup_{$table}_{$event}"; }
            }
            $existing = $pdo->query("SELECT name FROM sqlite_master WHERE name GLOB '_dedup_*'")->fetchAll(PDO::FETCH_COLUMN);
            $repair = array_diff($required, $existing) !== [];
            $pdo->exec('CREATE TABLE IF NOT EXISTS _dedup_identity (
                source TEXT NOT NULL, source_id TEXT NOT NULL, key_hash TEXT NOT NULL,
                PRIMARY KEY (source, source_id))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS _dedup_identity_key ON _dedup_identity (key_hash, source, source_id)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS _dedup_pending (
                source TEXT NOT NULL, source_id TEXT NOT NULL, PRIMARY KEY (source, source_id))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS _dedup_meta (version INTEGER NOT NULL)');
            $version = $pdo->query('SELECT version FROM _dedup_meta')->fetchColumn();
            if ($version !== false && (int)$version !== 1) {
                throw new RuntimeException('Unsupported identity index version');
            }
            $backfill = $repair || $version === false;
            if ($backfill) {
                // Also revisit indexed IDs whose raw rows may have disappeared
                // while one of the triggers was absent.
                $pdo->exec('INSERT OR IGNORE INTO _dedup_pending SELECT source, source_id FROM _dedup_identity');
            }
            foreach (['entry', 'entrytmp'] as $table) {
                // Only built-in SQL: even core connections unaware of this extension
                // must be able to write, delete, commit and roll back these rows.
                foreach (['INSERT' => 'NEW', 'DELETE' => 'OLD', 'UPDATE OF id, link' => 'OLD'] as $event => $ref) {
                    $suffix = str_starts_with($event, 'UPDATE') ? 'update' : strtolower($event);
                    $body = "INSERT OR IGNORE INTO _dedup_pending VALUES ('$table', CAST($ref.id AS TEXT));";
                    if ($suffix === 'update') {
                        $body .= " INSERT OR IGNORE INTO _dedup_pending VALUES ('$table', CAST(NEW.id AS TEXT));";
                    }
                    $pdo->exec("CREATE TRIGGER IF NOT EXISTS _dedup_{$table}_{$suffix} AFTER $event ON $table BEGIN $body END");
                }
                if ($backfill) {
                    $pdo->exec("INSERT OR IGNORE INTO _dedup_pending SELECT '$table', CAST(id AS TEXT) FROM $table");
                }
            }
            if ($version === false) { $pdo->exec('INSERT INTO _dedup_meta VALUES (1)'); }
        });
    }

    /** Savepoints compose with a caller's transaction without ever committing it. */
    private function transaction(callable $operation): void {
        $this->pdo->exec('SAVEPOINT dedup_index');
        try {
            $operation();
            $this->pdo->exec('RELEASE SAVEPOINT dedup_index');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT dedup_index');
            $this->pdo->exec('RELEASE SAVEPOINT dedup_index');
            throw $e;
        }
    }

    /** Apply only IDs changed by actual database writes, atomically. */
    public function sync(): void {
        $this->transaction(function (): void {
            // Obtain the writer reservation before reading the queue. Do not
            // hold a separate-PDO transaction across a core insertion/commit.
            $this->pdo->exec('DELETE FROM _dedup_pending WHERE 0');
            $remove = $this->pdo->prepare('DELETE FROM _dedup_identity WHERE source = ? AND source_id = ?');
            $insert = $this->pdo->prepare('INSERT OR REPLACE INTO _dedup_identity VALUES (?, ?, ?)');
            $done = $this->pdo->prepare('DELETE FROM _dedup_pending WHERE source = ? AND source_id = ?');
            do {
                $pending = $this->pdo->query('SELECT source, source_id FROM _dedup_pending LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pending as $change) {
                    $table = $change['source'];
                    if (!in_array($table, ['entry', 'entrytmp'], true)) {
                        throw new RuntimeException('Invalid index source');
                    }
                    $id = $change['source_id'];
                    $remove->execute([$table, $id]);
                    $get = $this->pdo->prepare("SELECT link FROM $table WHERE id = ?");
                    $get->execute([$id]);
                    $link = $get->fetchColumn();
                    if ($link !== false && $link !== '') {
                        $insert->execute([$table, $id, hash('sha256', DeduplicateUrl::canonical((string)$link))]);
                    }
                    $done->execute([$table, $id]);
                }
            } while (count($pending) === 500);
        });
    }

    /** @return array{table:string,id:string,link:string,guid:string,id_feed:int}|null */
    public function find(string $canonical): ?array {
        $found = null;
        $this->transaction(function () use ($canonical, &$found): void {
            $this->sync();
            $found = $this->lookup($canonical);
        });
        return $found;
    }

    /** @return array{table:string,id:string,link:string,guid:string,id_feed:int}|null */
    private function lookup(string $canonical): ?array {
        $s = $this->pdo->prepare('SELECT source, source_id FROM _dedup_identity WHERE key_hash = ? ORDER BY source, source_id');
        $s->execute([hash('sha256', $canonical)]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $table = $candidate['source'];
            if (!in_array($table, ['entry', 'entrytmp'], true)) { continue; }
            $row = $this->pdo->prepare("SELECT id, link, guid, id_feed FROM $table WHERE id = ?");
            $row->execute([$candidate['source_id']]);
            $raw = $row->fetch(PDO::FETCH_ASSOC);
            if ($raw && $raw['link'] !== '' && DeduplicateUrl::canonical($raw['link']) === $canonical) {
                return ['table' => $table, 'id' => (string)$raw['id'], 'link' => $raw['link'],
                    'guid' => (string)$raw['guid'], 'id_feed' => (int)$raw['id_feed']];
            }
        }
        return null;
    }
}
