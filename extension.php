<?php

class DeduplicateEntriesExtension extends Minz_Extension {
    public function init(): void {
        $this->registerTranslates();
        $this->registerHook('entry_before_insert', [$this, 'deduplicateEntry']);
    }

    public function deduplicateEntry($entry) {
        if (is_object($entry) === false) {
            return $entry;
        }

        $hash = $entry->hash();
        if ($hash === null || $hash === '') {
            return $entry;
        }

        // Check if an entry with this hash already exists
        $entryDao = FreshRSS_Factory::createEntryDao();
        $hexHash = $entryDao->sqlHexEncode('hash');
        $result = $entryDao->fetchAssoc(
            "SELECT COUNT(*) as cnt FROM entry WHERE {$hexHash} = ?",
            [$hash]
        );

        if ($result !== null && isset($result[0]['cnt']) && (int)$result[0]['cnt'] > 0) {
            // Duplicate found — skip this entry
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
