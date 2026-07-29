# GitHub Release Intelligence v7.8.1

GitHub Release Intelligence connects governed GitHub evidence to each canonical Sustainable Catalyst product without allowing repository activity to silently redefine product identity or publish public communications.

## Version authority

The authority order is:

1. Latest published stable GitHub Release.
2. Latest published prerelease when explicitly enabled.
3. Latest valid semantic Git tag.
4. Installed WordPress plugin version.
5. Governed manual version.

Draft releases are never eligible. Commits remain activity evidence only.

## Repository intelligence

Each successful synchronization can record the repository URL, resolved GitHub URL, visibility, private/public state, default branch, archive status, disabled status, fork status, latest repository update time, and rename or transfer evidence. When GitHub resolves a renamed or transferred repository, the canonical connection can follow the resolved repository while preserving audit evidence of the configured URL.

## Release intelligence

Eligible releases provide the governed tag, version, release name, release URL, author, publication time, prerelease state, and asset inventory. Asset records contain public GitHub release metadata only; credentials and private token values are never included.

## Rate limits and token diagnostics

The administration screen reports available GitHub rate-limit headers, reset timing, accepted OAuth scopes, and organization or SSO approval hints. These diagnostics are intended to explain repository-specific access failures without exposing the saved token.

## Synchronization history

Each product retains bounded synchronization history with timestamps, outcomes, source authority, repository evidence, and failure information. Administrators can retry failed repositories without resynchronizing every healthy connection.

## Polling and webhooks

Polling can be configured as hourly, twice daily, daily, or disabled. Signed GitHub webhooks remain the immediate update path. Delivery IDs are retained for bounded replay protection, duplicate deliveries are ignored, and delivery outcomes are visible to administrators. Invalid signatures are logged but do not reserve a delivery ID.

## Human control

GitHub synchronization may update governed product and console evidence. It does not publish release notes, customer messages, or irreversible product-identity changes automatically.
