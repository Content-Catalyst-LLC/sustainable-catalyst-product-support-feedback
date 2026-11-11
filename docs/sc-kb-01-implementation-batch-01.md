# SC-KB-01 implementation batch 01 — development package

This batch implements research-area browsing inside the existing Support Center. It is not completion of SC-KB-01/02 and is not a production release candidate.

## Behavior

Eight versioned research areas precede a native All Applications disclosure. All 16 legacy identities remain selectable even when no eligible articles exist. One article can have multiple area slugs in `_scfs_kb_research_areas`; an explicit empty array preserves an unmapped review decision. Otherwise the deterministic product-to-primary-area contract applies at read time. This is navigation classification, not verification of the article or application's capabilities. No migration mutates posts, editorial states or URLs.

`scfs_kb_area` persists through search, filtering, deep links and refresh. Area links, search and application disclosure work without JavaScript. Counts include published non-password-protected records returned through WordPress filters; authenticated private/draft records are never included. The former 500-record cap is removed. At very large scale the current in-memory browser needs query-level facets/pagination and performance acceptance before release.

## Source audit

Baseline Support main: d454431946d1b376c9b37af89bd1b9d87efea4d2 (7.8.1). The source recount finds 96 bundled guides, 16 applications and 32 sample files. These are source counts, not installed WordPress counts or scientifically verified guides. The generic importer has a 2,097,152-byte upload limit and 500-record JSON limit. Existing Support draft PRs #1/#2 concern CI and documentation; this implementation does not merge or overwrite them.

Documentation #20, Data #8 and Handoffs #8 are draft planning branches with reported conflicts against main. Their navigation, coverage and handoff contracts were consulted; this batch does not silently merge those proposals.

**Critical existing behavior:** integrated `import_documentation()` forces publication, including on activation and some admin initialization paths; its status parameter is ignored. It preserves body edits via hashes but may change editorial status, metadata and taxonomy. This batch does not add AI articles to that historical importer or invoke it against a site. Its legacy automatic-publication behavior must be isolated or replaced before shipping a new article corpus. The old test harness explicitly expects 96 published articles; passing it does not certify the new required review-only importer.

## Validation and scope

Run `php tests/test-sc-kb-navigation.php`, `php tests/test-v525-library-browser.php`, and `php tests/test-v529-search-quality.php`. The first test includes the existing in-memory importer regression and adds actual mapping, many-to-many, unknown mapping and >500 article/password/draft behavior. These are PHP harness tests, not real WordPress integration, scientific validation, browser accessibility or staging evidence.

`python3 tools/knowledge-base/build.py --output dist` validates mapping/IDs, inventories source and produces a deterministic plugin ZIP, package-file checksums and source inventory. CI repeats the build and compares ZIP bytes. The package retains the existing plugin slug. Its prerelease version is 7.8.2-alpha.1.

## Installation and rollback boundary

Do not install this development artifact on production. An isolated WordPress test site requires a sanitized database, saved plugin version, database/media backup and successful restore receipt. Preserve post IDs and permalink exports before any upgrade. Activation may execute the historical publisher described above; it must be addressed and tested before staging installation. File rollback alone cannot reverse WordPress mutations: restore the database and previous plugin together in isolation. No installation or publication has been performed.

## Remaining acceptance

- Live read-only corpus, manual edits, installed version and deployment/backup inventory.
- Source audits of every application; complete 20-area/14-dimension feature matrix.
- First 10–25 substantial source-verified layered drafts and reproducible scientific fixtures.
- Review-only resumable/import preview/conflict/rollback manager and CLI with integration tests.
- Guided Workflow, reader level and source-status facets; related recommendations and full accessibility/browser screenshots.
- Privacy/license/link/security checks, actual WordPress upgrade/import/rollback tests and staging gates.
- Owner approval separately for production installation and public article publication.
