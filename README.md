# xExtension-DeduplicateEntries

A FreshRSS extension to automatically prevent duplicate articles from appearing across multiple feeds.

## Problem

When you subscribe to multiple RSS feeds that cover the same news sources (e.g., several Indian news outlets all carrying the same wire story), FreshRSS stores each copy as a separate entry — even though the article is identical. This clutters your reading list with duplicates that share the same title and link.

FreshRSS's built-in `same_title_in_feed` setting only works within a single feed. There is no native mechanism to deduplicate articles **across** different feeds.

## Solution

This extension hooks into FreshRSS's `entry_before_insert` event and checks whether an entry with the same hash (MD5 of the article link) already exists in the database. If a duplicate is found, the new entry is silently skipped — only the first occurrence is kept.

### Key features

- **Automatic** — no configuration required. Install and enable; it just works.
- **Cross-feed** — deduplicates across all feeds, not just within a single feed.
- **Hash-based** — uses FreshRSS's built-in entry hash (derived from the article GUID/link), so it reliably identifies the same article even when titles differ slightly between feeds.
- **Lightweight** — one database query per new entry. No background jobs, no cron, no external services.
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

3. Go to **Settings → Extensions** in your FreshRSS and enable **Deduplicate Entries**.

## Configuration

No configuration is needed. The extension works automatically once enabled.

## How it works

When FreshRSS fetches new articles from your feeds, each article is assigned a hash based on its GUID/link. Before inserting a new entry, this extension queries the database to check if an entry with the same hash already exists:

- **If the hash exists** → the entry is skipped (not inserted). A warning is logged.
- **If the hash is new** → the entry is inserted normally.

This means if feeds A, B, and C all carry the same wire story, only the first one fetched will appear in your reading list. The other two will be silently dropped.

## Cleaning up existing duplicates

This extension only prevents **new** duplicates. To remove duplicates that already exist in your database, you can run a one-time SQL query:

```sql
DELETE FROM entry
WHERE id NOT IN (
    SELECT MIN(id)
    FROM entry
    GROUP BY hash
);
```

**Always back up your database before running this query.**

## Compatibility

- Works with SQLite, MySQL, and PostgreSQL backends.
- Compatible with FreshRSS 1.25.0 and later.
- No conflicts with other extensions.

## License

AGPL-3.0 — same as FreshRSS itself.

## Author

[ViVi93](https://github.com/ViVi93)

## Contributing

Pull requests and issues are welcome. Please keep the code style consistent with FreshRSS conventions.
