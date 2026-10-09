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

    public function deduplicateEntry($entry) {
        if (is_object($entry) === false) {
            return $entry;
        }

        $link = $entry->link();
        if ($link === null || $link === '') {
            return $entry;
        }

        $entryDao = FreshRSS_Factory::createEntryDao();

        // Note: Minz_ModelPdo::fetchAssoc() binds parameters by array key name,
        // so named placeholders + an associative array are required. A positional
        // array (e.g. [$link]) throws ValueError on PHP 8.1+.

        // Check 1: exact link match
        $result = $entryDao->fetchAssoc(
            "SELECT COUNT(*) as cnt FROM entry WHERE link = :link",
            [':link' => $link]
        );

        if ($result !== null && isset($result[0]['cnt']) && (int)$result[0]['cnt'] > 0) {
            Minz_Log::warning(_t('ext.deduplicate.warning.duplicate_skipped', $entry->title()));
            return null;
        }

        // Check 2: link without fragment (handles URL variations like #publisher=newsstand)
        $linkNoFragment = $this->stripFragment($link);
        if ($linkNoFragment !== $link) {
            $result = $entryDao->fetchAssoc(
                "SELECT COUNT(*) as cnt FROM entry WHERE link = :link",
                [':link' => $linkNoFragment]
            );

            if ($result !== null && isset($result[0]['cnt']) && (int)$result[0]['cnt'] > 0) {
                Minz_Log::warning(_t('ext.deduplicate.warning.duplicate_skipped', $entry->title()));
                return null;
            }
        }

        return $entry;
    }

    public function handleConfigureAction(): void {
        $this->registerTranslates();
        // No configuration needed — this extension works automatically
    }
}
