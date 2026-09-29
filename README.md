# Sustainable Catalyst Product Support and Feedback Platform

Sustainable Catalyst Product Support and Feedback Platform is the support, documentation, feedback, help-desk, release-intelligence, and product-operations layer of the Sustainable Catalyst platform.

**Current release:** v7.8.1 — Private Repository Release Bridge

## Architecture

The platform combines public support, private support operations, governed product feedback, release intelligence, and a deterministic backend while preserving human control over consequential actions.

- **Public support** — searchable support center, knowledge base, unified search, known issues, release intelligence, product embeds, and customer-facing support routes.
- **Product feedback** — structured suggestions, surveys, prioritization evidence, roadmap signals, product-signal intelligence, and documentation-effectiveness analysis.
- **Help desk** — governed case intake, agent workspaces, queues, assignment, requester portals, conversations, service levels, secure evidence, knowledge-assisted resolution, workflow automation, email channels, quality analytics, and institutional workspaces.
- **Release intelligence** — canonical product registry, installed-plugin discovery, GitHub release synchronization, release-console projection, repository diagnostics, and release continuity.
- **Private repository bridge** — server-side GitHub App or approved token access for selected private repositories without exposing private repository URLs, credentials, branches, assets, or commit identifiers in public output.
- **Backend runtime** — FastAPI support-intelligence services and deterministic analysis contracts.
- **WordPress product** — public and administrative support interfaces, REST APIs, release console, product registry, and help-desk workflows.
- **Governed integrations** — scoped APIs, signed webhooks, retries, external-system links, and privacy-aware handoffs.

The platform does not automatically publish private information, make privileged administrative changes, or promote AI-generated support conclusions without the configured review and authorization boundaries.

## Repository layout

- `.github/` — repository automation.
- `backend/` — FastAPI support-intelligence service and backend tests.
- `docs/` — current architecture, feature, governance, and release documentation.
- `examples/` — synthetic and contract examples.
- `exports/` — export placeholder/state boundary.
- `schemas/` — versioned support and integration contracts.
- `tests/` — WordPress, integration, and release contract tests.
- `tools/` — maintained repository and registry tooling.
- `wordpress/` — canonical WordPress plugin source; the legacy-compatible plugin directory name remains `sustainable-catalyst-feature-suggestions`.
- `feature_suggestions_manifest.json` — current canonical product/repository capability manifest.

## Primary public shortcodes

- `[scfs_product_support_center]`
- `[scfs_connected_product_support_platform]`
- `[scfs_unified_support_search]`
- `[scfs_support_knowledge_base]`
- `[scfs_issue_release_intelligence]`
- `[scfs_cross_product_support_graph]`
- `[scfs_support_embed product="decision-studio"]`
- `[scfs_help_desk_customer_portal]`

## Release history

Historical root-level release notes, build validations, terminal-command files, versioned manifests, installer scripts, package receipts, and packaged WordPress ZIPs are intentionally not retained on `main`.

Canonical current documentation remains under `docs/`, while Git history preserves previous release artifacts.

The exact repository state immediately before the September 29, 2026 cleanup is preserved on:

`archive/pre-root-cleanup-2026-09-29-product-support-feedback`
