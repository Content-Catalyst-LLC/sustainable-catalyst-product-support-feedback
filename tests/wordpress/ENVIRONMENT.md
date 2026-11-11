# Isolated WordPress integration environment

Use a fresh synthetic database, never a production connection or an unsanitized copy.
The setup script deliberately does not activate the Support plugin.

## MySQL CI (required compatibility gate)

Provide `SC_KB_WP_ROOT`, `SC_KB_DB_ENGINE=mysql`, `SC_KB_DB_NAME`,
`SC_KB_DB_USER`, `SC_KB_DB_PASSWORD`, and `SC_KB_DB_HOST` for an isolated MySQL
service, then run `bash tools/knowledge-base/setup-wordpress.sh`.
The DB user must be scoped to the disposable test database.
Activate only after publication safety fixes are present.

Use `wp db export /isolated/evidence/before.sql --path="$SC_KB_WP_ROOT"`, hash the
export, deliberately mutate a synthetic sentinel, then restore with
`wp db import /isolated/evidence/before.sql --path="$SC_KB_WP_ROOT"` and assert
sentinel restoration. Save the plugin ZIP as well as the DB backup; DB rollback
and plugin rollback are separate operations. Run restore while no test web
requests/workers are active.

## Local SQLite fallback

`SC_KB_DB_ENGINE=sqlite` installs the official pinned SQLite integration 3.1.3.
WordPress version defaults to 6.8.3. This is real WordPress lifecycle/API testing,
but it does not establish MySQL compatibility. WP-CLI `wp db` commands invoke
MySQL and must not be used against this fallback.

```sh
python3 tools/knowledge-base/sqlite-backup.py backup --wordpress "$SC_KB_WP_ROOT" --backup /isolated/evidence/before.sqlite
wp option update sc_kb_restore_probe changed --path="$SC_KB_WP_ROOT"
python3 tools/knowledge-base/sqlite-backup.py restore --wordpress "$SC_KB_WP_ROOT" --backup /isolated/evidence/before.sqlite
wp eval 'if (get_option("sc_kb_restore_probe", "absent") !== "absent") { throw new Exception("Restoration failed"); }' --path="$SC_KB_WP_ROOT"
```

The backup helper uses SQLite's consistent backup API, checks the saved SHA-256
before restoration, and runs `PRAGMA integrity_check`. The database path is
resolved through the official drop-in's generated configuration. Use an offline
fixture to avoid concurrent writes.

## Local receipt, Batch 2

- WordPress 6.8.3, PHP 8.4.13, official SQLite integration 3.1.3, SQLite 3.53.4.
- Fresh synthetic install succeeded; Support not activated during setup.
- Pre-Support backup + SHA-256 saved locally.
- Sentinel changed, database restored, sentinel absent: PASS.
- SQLite integrity checks before/after restore: PASS.
- Isolated Homebrew MySQL 9.4 `--initialize-insecure` crashed with SIGSEGV before
  creating the data directory. Existing user MySQL was not modified or stopped.
  Local SQLite results must not be reported as MySQL acceptance.
