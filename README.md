# xExtension-DeduplicateEntries

Prevent new cross-feed copies with conservative canonical URLs. **SQLite adds an indexed lifecycle-aware matcher and per-user serialized refreshes.** No external services or AI calls.

## Requirements and installation

- FreshRSS **1.30.0+**, PHP 8.1+, PDO SQLite for the protected path.
- Tested with SQLite/FreshRSS 1.30.0. MySQL/PostgreSQL retain an unindexed legacy matcher and are **not** covered by SQLite locking/index guarantees or cleanup.
- Install the whole repository as `extensions/xExtension-DeduplicateEntries`. Replace all files on upgrade, not just `extension.php`.

Enable this system extension in `data/config.php`:

```php
'extensions_enabled' => [
    // Other extensions...
    'DeduplicateEntries' => true,
],
```

Use boolean `true`, not `1` or `'1'`. The service account needs write access to each user's database and directory. No separate cron is required.

## Conservative URL identity

The same normalizer compares stored and incoming links:

- Decode FreshRSS HTML encoding once; lowercase HTTP(S) scheme/hostname.
- Ignore exactly `#publisher=newsstand` on `www.ndtv.com`, `www.ndtvprofit.com`, `sports.ndtv.com`.
- Remove only explicit trackers: `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `utm_id`, `gclid`, `fbclid`, `msclkid`.
- Preserve meaningful parameters and repeated ordering, paths/case, raw port spellings (including leading zeroes/empty colon), trailing slash, HTTP versus HTTPS and all other fragments.
- Leave ambiguous/non-web/credential-bearing URLs unchanged.

NDTV plain/publisher-marked copies match in either arrival order, including while staged, even if headlines differ. Original links/GUIDs stay unchanged. New-only `entry_before_add` leaves legitimate updates unblocked.

**Not fuzzy story clustering:** recurring titles can identify different episodes/reports. Different publisher URLs, arbitrary AMP/mobile aliases or different article paths/IDs are not automatically merged. Automatic title suppression is deliberately disabled; title-only candidates require review.

## SQLite index and lifecycle

Extension-owned database objects:

- `_dedup_identity`: SHA-256 canonical-key index mapped to real source table/ID.
- `_dedup_pending`: changed row IDs, not speculative reservations.
- `_dedup_meta`: schema/canonical-policy version.
- Six SQL-only triggers observe INSERT/DELETE and UPDATE OF id/link on `entry` and `entrytmp`.

First use backfills retained/staged rows. Later lookups process pending changes only. Staging commits rewrite IDs; triggers track removal/insertion. Retention/deletion remove membership; rolled-back insertions leave no phantom key. Heartbeat-only `lastSeen` changes do not enqueue work. Missing schema objects trigger repair/backfill. Raw canonical equality is verified after hash lookup; hash collisions are not duplicate decisions.

Lookups use a covering index, not full entry scans. Savepoints do not commit caller-owned transactions. The live hook refuses separate-connection lookup inside a core-owned transaction rather than waiting on its own write lock.

## Concurrent refreshes and failures

`feeds_list_before_actualize` acquires `<user-directory>/.deduplicate-refresh.lock` before per-feed locks, retaining it through final staging commit and PHP shutdown. Locks are per user, reentrant within a request, and released by the OS on exit/crash. Never unlink or manually close a held lock file.

CLI/web startup commits can precede this hook; raw triggers track staging moves. This is tested with the real SQLite commit routine. **Protection covers normal plugin-enabled CLI/web/WebSub refreshes and this maintenance tool, not raw database writers, disabled extensions or non-SQLite backends.**

SQLite initialization failures block refreshes with `refresh_error`; protected lookup failures block candidates with `lookup_error`. Resolve those errors before expecting normal ingestion. Non-SQLite fallback retains fail-open lookup behavior without concurrency guarantees. Skip logs identify rule, keeper and incoming feed, without URLs, credentials or SQL exception messages.

## Explicit, backed-up duplicate cleanup

Cleanup never runs automatically during refresh. Run as the FreshRSS service account with an absolute resolved user directory. Keep reports/backups private: they contain reading data.

```sh
# Dry run: no article deletion
php maintenance.php --user-dir=/path/to/FreshRSS/data/users/USERNAME > /private/plan.json

# Review the plan, then use its exact fingerprint and a NEW backup file
php maintenance.php --user-dir=/path/to/FreshRSS/data/users/USERNAME \
  --apply=FINGERPRINT_FROM_PLAN --backup=/private/new-backup.sqlite

# Optional index initialization/check
php maintenance.php --user-dir=/path/to/FreshRSS/data/users/USERNAME --index
```

The tool takes the refresh lock. Apply refuses nonempty staging, stale plans, symlinked DB paths and existing backup files. A consistent SQLite backup passes integrity verification **before** merging. In one immediate transaction it rechecks full original rows/labels, then:

- Prefers favourites, labelled entries and richer content for the keeper.
- Preserves keeper URL/GUID/title; ORs favourites and retains unread if any copy is unread.
- Unions article tags/labels and retains useful content.
- Archives full original duplicate rows and original keeper row in keeper attributes. Invalid UTF-8/binary fields use `{ "deduplicate_binary_base64": "..." }`, recoverable with strict base64 decoding. Valid text/scalar types stay unchanged.
- Deletes only confirmed canonical duplicates, updates feed counters, syncs the index and checks remaining duplicates plus DB/foreign-key integrity.

Malformed keeper/archive JSON causes rollback, not lossy substitution. Tag parsing matches FreshRSS's comma/hash parser; original spellings remain archived. Failed transactions restore entries, labels and counters. Backups preserve the complete pre-cleanup database.

`Cleanup.php` is a library whose callers must enforce lock/backup prerequisites; prefer `maintenance.php`. API clients may retain locally cached deleted entries until resync. Invalidate FreshRSS HTTP/user caches using core tooling after maintenance as appropriate.

## Tests

```sh
php tests/run.php
php tests/index.php
php tests/cleanup.php
```

Includes synthetic URL edge cases, full-row/binary archive preservation, rollback, covering query plans, incremental lifecycle, real multi-process insertion barriers, shutdown-lock retention and killed-process recovery. When installed FreshRSS code exists, the concurrency suite also exercises its unmodified SQLite staging commit. User snapshots are never bundled. GitHub Actions runs synthetic suites on PHP 8.1/8.3.

`tests/integration.php` exercises the real parser/controller/staging/update paths against a disposable installation. Prepare user `tester` without default feeds under `/tmp/dedup-integration/data`, copy this extension into `/tmp/dedup-integration/extensions/xExtension-DeduplicateEntries`, create writable `/tmp/dedup-integration/locks`, then run:

```sh
DATA_PATH=/tmp/dedup-integration/data php tests/integration.php
```

Resolved-path guards reject traversal/symlink DB escapes. This test resets its own fixture entries/feeds: never point it at real data.

## Other-extension compatibility

`compatibility/filtertitle-literal-keywords.patch` fixes warnings in independently installed CNTools FilterTitle 0.1.0 without changing keyword rules. It is separate from deduplication and not auto-installed. See `compatibility/README.md`.

## Changelog

### 1.3.0

Persistent SQLite identity index, incremental lifecycle triggers, serialized refresh/maintenance through shutdown, crash recovery, fail-closed protected path, fingerprinted transactional cleanup with lossless archives, lifecycle/concurrency/binary tests and GitHub Actions.

### 1.2.0

Symmetric conservative matching fixes fragment-first NDTV duplicates. New-only hook preserves updates. Entity/raw-port edge cases, corrected translations and diagnostics.

### 1.1.1

Added staging checks; matching remained asymmetric.

### 1.1.0

Switched hash checks to URLs and fixed named parameter binding.

### 1.0.0

Initial hash-based duplicate prevention.

## License

AGPL-3.0, matching FreshRSS. Author: [ViVi93](https://github.com/ViVi93).
