# xExtension-DeduplicateEntries

A FreshRSS extension to automatically prevent duplicate articles from appearing across multiple feeds.

## Problem

When you subscribe to multiple RSS feeds that cover the same news sources (e.g., several Indian news outlets all carrying the same wire story), FreshRSS stores each copy as a separate entry — even though the article is identical. This clutters your reading list with duplicates that share the same title and link.

FreshRSS's built-in `same_title_in_feed` setting only works within a single feed. There is no native mechanism to deduplicate articles **across** different feeds.

## Solution

This extension hooks into FreshRSS's `entry_before_insert` event and checks whether an entry with the same URL already exists in the database. If a duplicate is found, the new entry is silently skipped — only the first occurrence is kept. It also handles URL variations (e.g., fragments like `#publisher=newsstand`) by comparing URLs with fragments stripped.

### Key features

- **Automatic** — no configuration required. Install and enable; it just works.
- **Cross-feed** — deduplicates across all feeds, not just within a single feed.
- **URL-based** — compares article URLs (with fragments stripped), so it reliably identifies the same article even when feeds use different GUIDs or URL parameters.
- **Lightweight** — one or two database queries per new entry. No background jobs, no cron, no external services.
- **Non-destructive** — existing duplicates are not automatically deleted. Only new duplicates are prevented. (You can clean up existing duplicates manually or with a one-time script.)

## Requirements

- FreshRSS 1.25.0+
- PHP 8.1+

## Installation

### Via FreshRSS Extension Manager (recommended)

1. Go to **Settings → Extensions** in your FreshRSS instance.
2. Click **Install a new extension**.
3. Enter the repository URL: `https://github.com/ViVi93/xExtension-DeduplicateEntries`
4. Click **Install**.

### Manual installation

1. Clone or download this repository into your FreshRSS extensions directory:

   ```bash
   cd /path/to/FreshRSS/extensions
   git clone https://github.com/ViVi93/xExtension-DeduplicateEntries.git
   ```

2. The extension directory must be named `xExtension-DeduplicateEntries` (FreshRSS convention).

3. **Enable the extension in the system config.** This extension is type `system`, so it must be enabled in the system configuration file (`data/config.php`), not the user config. Add the following to the `extensions_enabled` array in `data/config.php`:

   ```php
   'extensions_enabled' => [
       // ... other extensions ...
       'DeduplicateEntries' => true,  // must be boolean true, not 1 or '1'
   ],
   ```

   **Important:** The value must be boolean `true`, not integer `1` or string `'1'`. FreshRSS's ExtensionManager filters with `is_bool($value)`, so non-boolean values are silently ignored.

   Alternatively, you can change the extension type to `"user"` in `metadata.json` if you prefer to enable it per-user via the web UI.

4. Go to **Settings → Extensions** in your FreshRSS and verify **Deduplicate Entries** is listed and enabled.

## Configuration

No configuration is needed. The extension works automatically once enabled.

## How it works

When FreshRSS fetches new articles from your feeds, each article has a URL (link). Before inserting a new entry, this extension queries the database to check if an entry with the same URL already exists:

- **If the exact URL exists** → the entry is skipped (not inserted). A warning is logged.
- **If the URL without fragment exists** (e.g., `article.html#publisher=newsstand` matches `article.html`) → the entry is also skipped.
- **If the URL is new** → the entry is inserted normally.

This means if feeds A, B, and C all carry the same wire story, only the first one fetched will appear in your reading list. The other two will be silently dropped.

## Cleaning up existing duplicates

This extension only prevents **new** duplicates. To remove duplicates that already exist in your database, you can run a one-time SQL query:

```sql
-- Delete duplicates by exact link match (empty links are left untouched)
DELETE FROM entry
WHERE link <> ''
  AND id NOT IN (
    SELECT MIN(id)
    FROM entry
    WHERE link <> ''
    GROUP BY link
);

-- Delete duplicates by link without fragment (empty links are left untouched)
DELETE FROM entry
WHERE link <> ''
  AND id NOT IN (
    SELECT MIN(id)
    FROM entry
    WHERE link <> ''
    GROUP BY CASE WHEN instr(link, '#') > 0 THEN substr(link, 1, instr(link, '#') - 1) ELSE link END
);
```

The `link <> ''` guard is important — without it every entry with an empty link would
collapse into a single row.

**Always back up your database before running this query.**

## Compatibility

- Works with SQLite, MySQL, and PostgreSQL backends.
- Compatible with FreshRSS 1.25.0 and later.
- No conflicts with other extensions.

## Changelog

### 1.1.1

- **Fixed same-batch duplicates.** New entries are staged in FreshRSS's `entrytmp`
  table and only merged into `entry` by `commitNewEntries()` at the end of a refresh
  batch. The dedup check previously queried only `entry`, so two copies of the same
  article arriving in the **same refresh batch** (e.g. the same NDTV story carried by
  two feeds) could not see each other and were both committed. The check now covers
  both `entry` and `entrytmp`.

### 1.1.0

- Switched deduplication from FreshRSS's entry **hash** to the article **URL (link)**,
  with fragments stripped, so URL variations such as `article.html#publisher=newsstand`
  are recognised as the same article.
- **Fixed a fatal error** (`ValueError: PDOStatement::bindValue(): Argument #1 ($param)
  must be greater than or equal to 1`) that aborted feed refreshes on PHP 8.1+.
  `Minz_ModelPdo::fetchAssoc()` binds parameters by array **key name**, so the queries now
  use named placeholders (`link = :link` with `[':link' => $link]`) instead of a positional
  array (`link = ?` with `[$link]`).
- Added a second check for the fragment-stripped URL.

### 1.0.0

- Initial release — hash-based cross-feed duplicate prevention.

## License

AGPL-3.0 — same as FreshRSS itself.

## Author

[ViVi93](https://github.com/ViVi93)

## Contributing

Pull requests and issues are welcome. Please keep the code style consistent with FreshRSS conventions.
