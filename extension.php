<?php
declare(strict_types=1);

require_once __DIR__ . '/Url.php';
require_once __DIR__ . '/SqliteIndex.php';

class DeduplicateEntriesExtension extends Minz_Extension {
    /** @var array<string,DeduplicateSqliteIndex> */
    private array $indexes = [];

    public function init(): void {
        $this->registerTranslates();
        // Unlike entry_before_insert, this hook does not run on article updates.
        $this->registerHook('entry_before_add', [$this, 'deduplicateEntry']);
        // FreshRSS 1.30 calls this for CLI, web and WebSub before per-feed
        // locks/new-entry hooks. Hold through the later core commit. CLI/web
        // startup commits can precede this hook: raw triggers track their moves.
        $this->registerHook('feeds_list_before_actualize', [$this, 'prepareRefresh']);
    }

    /** @param array<mixed> $feeds @return array<mixed> */
    public function prepareRefresh(array $feeds): array {
        try {
            $directory = $this->sqliteDirectory();
            if ($directory !== null) { $this->sqliteIndex($directory)->sync(); }
            return $feeds;
        } catch (Throwable $e) {
            Minz_Log::error('DeduplicateEntries refresh_error: refresh blocked; ' . get_class($e));
            return [];
        }
    }

    /** Null means a non-SQLite backend, or a lightweight framework test double. */
    private function sqliteDirectory(): ?string {
        if (!defined('USERS_PATH') || !class_exists('Minz_User') || !class_exists('FreshRSS_Context')) {
            return null;
        }
        if ((FreshRSS_Context::systemConf()->db['type'] ?? '') !== 'sqlite') { return null; }
        $user = Minz_User::name();
        if (!is_string($user) || $user === '' || $user === '_' || $user === '.' || $user === '..'
            || strpbrk($user, "/\\\0") !== false) {
            throw new RuntimeException('Invalid refresh user');
        }
        $root = realpath(USERS_PATH);
        $directory = $root === false ? false : realpath($root . '/' . $user);
        if ($directory === false || $directory !== $root . '/' . $user || !is_dir($directory)) {
            throw new RuntimeException('Refresh user path unavailable');
        }
        // Core's SQLite DSN uses DATA_PATH/users. Do not silently index a
        // different database if an installation overrides USERS_PATH alone.
        if (defined('DATA_PATH') && realpath(DATA_PATH . '/users') !== $root) {
            throw new RuntimeException('Refresh database root mismatch');
        }
        return $directory;
    }

    private function sqliteIndex(string $directory): DeduplicateSqliteIndex {
        DeduplicateRefreshLock::acquire($directory);
        $dao = FreshRSS_Factory::createEntryDao();
        if ($dao->inTransaction()) {
            // A second writer connection cannot sync behind our own core
            // transaction. Never wait on ourselves or commit core-owned work.
            throw new RuntimeException('Core transaction active during refresh lookup');
        }
        $file = $directory . '/db.sqlite';
        if (!is_file($file) || is_link($file) || realpath($file) !== $file) {
            throw new RuntimeException('Refresh database path unavailable');
        }
        if (!isset($this->indexes[$file])) {
            $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $this->indexes[$file] = new DeduplicateSqliteIndex($pdo);
        }
        return $this->indexes[$file];
    }

    public function deduplicateEntry($entry) {
        if (!is_object($entry)) {
            return $entry;
        }
        $link = $entry->link();
        if ($link === '') {
            return $entry;
        }
        $key = DeduplicateUrl::canonical($link);
        try {
            $directory = $this->sqliteDirectory();
            if ($directory !== null) {
                $row = $this->sqliteIndex($directory)->find($key);
                if ($row === null) { return $entry; }
                $this->logDuplicate($entry, $link, $row['table'], $row);
                return null;
            }
        } catch (Throwable $e) {
            // SQLite's protected path fails closed: an index or lock failure
            // cannot silently turn a serialized refresh into unsafe ingestion.
            Minz_Log::error('DeduplicateEntries lookup_error: blocked entry; ' . get_class($e));
            return null;
        }
        // Portable fallback only; no indexed/concurrent-refresh guarantee.
        // A coarse prefix only narrows candidates. Never use LIKE as identity.
        // Truncate at '&' so encoded incoming links can find decoded stored links.
        // Also scan every stored link containing '&': an entity can change even
        // the scheme/host, so a raw prefix (or domain filter) is not a superset.
        // These unbounded coarse predicates still require a table scan.
        $base = substr($link, 0, strcspn($link, '?#&'));
        $prefix = strtr(strtolower($base), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        try {
            $dao = FreshRSS_Factory::createEntryDao();
            foreach (['entry', 'entrytmp'] as $table) {
                // `_` is FreshRSS's portable table-prefix placeholder.
                $rows = $dao->fetchAssoc(
                    "SELECT id, link, guid, id_feed FROM `_{$table}` WHERE LOWER(link) LIKE :prefix ESCAPE '!' OR LOWER(link) LIKE :entity ESCAPE '!'",
                    [':prefix' => $prefix, ':entity' => '%!&%']
                );
                if ($rows === null) {
                    throw new RuntimeException('Candidate lookup failed');
                }
                foreach ($rows as $row) {
                    if (DeduplicateUrl::canonical((string)$row['link']) !== $key) {
                        continue;
                    }
                    $this->logDuplicate($entry, $link, $table, $row);
                    return null;
                }
            }
        } catch (Throwable $e) {
            // Preserve news ingestion on database/framework errors. Do not leak SQL,
            // URLs with credentials, or exception contents into diagnostic logs.
            Minz_Log::error('DeduplicateEntries lookup_error: allowed entry; ' . get_class($e));
        }
        return $entry;
    }

    /** @param array<string,mixed> $row */
    private function logDuplicate($entry, string $link, string $table, array $row): void {
        Minz_Log::warning('DeduplicateEntries ' . json_encode([
            'reason' => $row['link'] === $link ? 'exact_url' : 'canonical_url',
            'incoming_feed' => $entry->feedId(),
            'keeper_table' => $table,
            'keeper_id' => (string)$row['id'],
            'keeper_feed' => (int)$row['id_feed'],
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function handleConfigureAction(): void {
        $this->registerTranslates();
    }
}
