# SC-KB-02 Batch 2: review-only content installation

Version 7.8.2-alpha.2 extends the same WordPress plugin and Support Article store. It is a development package for isolated acceptance, not production approval.

## Safety and operator flow

Activation/reactivation and admin initialization no longer install article content. The historical `import_documentation()` compatibility entry point returns an error without mutations, and its publication exception and approval-stamping implementation are removed. Existing approved/published records and human edits remain unchanged.

Open **Support & Feedback → KB Content Updates**. Select a bundled release to see new, updated, unchanged, conflicting and review-pending counts, per-record reasons, content previews and body diffs. Review the pinned manifest fingerprint. Create and verify an offline database backup, then record its SHA-256 before starting. The recorded hash is an operator attestation: the importer does not establish that an arbitrary external backup is valid. Restore rehearsal is a separate gate.

Begin explicitly, then process bounded batches (default 10; hard maximum 20). Refresh or invoke the next batch to resume the persisted cursor. New content is always a draft with human review required. The system never approves or publishes a record. Existing pristine managed drafts may receive body/title/excerpt changes through an atomic compare-and-swap; concurrent human changes or approval win. IDs, slugs and editorial metadata remain intact. Changed approved/published/pending content is a protected review proposal, visible in preview; it is not silently replaced. Human/unknown-baseline changes and duplicate identities are conflicts.

Release files are local, version-pinned, SHA-256 checked, limited to 2 MiB per JSON file and 500 articles per release. No network content is fetched. Manifest changes invalidate previews and active runs. A WordPress option lock serializes mutation requests; interrupted locks require explicit privileged recovery after ten minutes. A record intent is stored before writes. Interrupted receipts reconcile unchanged known posts; uncertain writes become conflicts rather than duplicate/replay mutations. Audit events retain actor, time and action in private non-autoloaded run options. Previous runs remain available from the private audit history.

Rollback runs in bounded batches. It restores importer-managed draft text from snapshots if body and editorial state are unchanged. Newly created unchanged drafts are retained and marked retired; no existing records or permalinks are deleted. Post-import human edits, approvals, status changes and missing records block rollback for those records and remain untouched. Resolve those conflicts explicitly. Database plus plugin restoration remains the disaster-recovery procedure; an import rollback is not a substitute for a tested backup.

The admin POST handler requires both `manage_options` and `edit_posts`, plus a WordPress nonce. Read/CLI methods also enforce capabilities. WP-CLI must use an explicit authorized `--user`; anonymous and subscriber calls are rejected.

## CLI

```sh
wp --user=ADMIN scfs kb-release preview sc-kb-batch2-drafts-v1
wp --user=ADMIN scfs kb-release begin sc-kb-batch2-drafts-v1 --fingerprint=PREVIEW_MANIFEST_SHA256 --backup-sha256=VERIFIED_BACKUP_SHA256
wp --user=ADMIN scfs kb-release batch RUN_UUID --limit=10
wp --user=ADMIN scfs kb-release status RUN_UUID
wp --user=ADMIN scfs kb-release rollback RUN_UUID --limit=10
wp --user=ADMIN scfs kb-release recover
```

These are placeholders for real reviewed fingerprints, backup receipts and run IDs. No publishing command exists.

## Evidence and content

The bundled `sc-kb-batch2-drafts-v1` release contains ten source-linked layered drafts: three Workbench, three Site Intelligence, two Lab, two Decision Studio. They include numbered local/source exercises, mathematical assumptions, units/uncertainty limits, synthetic JSON, expected values, error interpretation and developer references. Twenty-two local source assertions pass. Full application coverage, browser controls, authentication, UI screenshots and deployed workflows are not certified. Workbench startup has a tracked missing-module blocker (issue 47); its direct numerical module examples pass, while complete app test collection fails.

The private source inventory extracts symbols and literal routes as coverage candidates, not as verified product capabilities. Publication requires independent technical/scientific/editorial review. Scientific software checks do not certify scientific conclusions.

## Verification commands

`tests/integration-kb-releases.php`, `tests/integration-kb-pagination.php` and `tests/integration-kb-upgrade-snapshot.php` execute through `wp --user=... eval-file` in real, disposable WordPress. They do not stub WordPress/database behavior. The release suite covers lifecycle safety, preview classifications, bounded resume, replay, manual edits, approvals, rollback, permissions/nonces, checksum pinning, concurrent edits and locks.

The pagination suite creates 5,003 synthetic public matching records plus privacy fixtures, verifies exact totals/disjoint bounded pages and facets, and measures actual browser query/rendering. No full article corpus is hydrated. The browser uses a hard maximum of 50 results per request, with 20 per normal page; grouped facet/count queries share the public visibility conditions. Counts and filters operate in the database.

Use `tools/knowledge-base/setup-wordpress.sh` with an isolated MySQL service or the explicit official SQLite fallback. SQLite passes are not MySQL compatibility proof. CI includes MySQL 8.4, historical alpha1→alpha2 ZIP upgrade, preservation of 97 seeded records, backup integrity/restoration and the two WordPress suites. CI also checks immutable public application revisions and all 22 computational assertions. No production credentials are needed or stored.
