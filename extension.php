<?php

class DeduplicateEntriesExtension extends Minz_Extension {
    public function init(): void {
        $this->registerTranslates();
        $this->registerHook('entry_before_insert', [$this, 'deduplicateEntry']);
    }

    /**
     * Strip fragment from URL for comparison.
     */
    private function stripFragment(string $url): string {
        $pos = strpos($url, '#');
        if ($pos !== false) {
            return substr($url, 0, $pos);
        }
        return $url;
    }

    /**
     * Whether an entry with this link already exists.
     *
     * New entries are staged in the `entrytmp` table and only merged into
     * `entry` by commitNewEntries() at the end of a refresh batch. Checking
     * only `entry` therefore misses duplicates that arrive within the same
     * batch (e.g. the same NDTV story pulled from two feeds in one refresh),
     * so both tables are checked.
     *
     * NB: Minz_ModelPdo::fetchAssoc() binds parameters by array key name, so
     * named placeholders + an associative array are required. A positional
     * array (e.g. [$link]) throws ValueError on PHP 8.1+.
     */
    private function linkExists($entryDao, string $link): bool {
        foreach (['entry', 'entrytmp'] as $table) {
            $result = $entryDao->fetchAssoc(
                "SELECT COUNT(*) as cnt FROM {$table} WHERE link = :link",
                [':link' => $link]
            );
            if ($result !== null && isset($result[0]['cnt']) && (int)$result[0]['cnt'] > 0) {
                return true;
            }
        }
        return false;
    }

    public function deduplicateEntry($entry) {
        if (is_object($entry) === false) {
            return $entry;
        }

        $link = $entry->link();
        if ($link === null || $link === '') {
            return $entry;
        }

        $entryDao = FreshRSS_Factory::createEntryDao();

        // Check 1: exact link match
        if ($this->linkExists($entryDao, $link)) {
            Minz_Log::warning(_t('ext.deduplicate.warning.duplicate_skipped', $entry->title()));
            return null;
        }

        // Check 2: link without fragment (handles URL variations like #publisher=newsstand)
        $linkNoFragment = $this->stripFragment($link);
        if ($linkNoFragment !== $link && $this->linkExists($entryDao, $linkNoFragment)) {
            Minz_Log::warning(_t('ext.deduplicate.warning.duplicate_skipped', $entry->title()));
            return null;
        }

        return $entry;
    }

    public function handleConfigureAction(): void {
        $this->registerTranslates();
        // No configuration needed — this extension works automatically
    }
}
