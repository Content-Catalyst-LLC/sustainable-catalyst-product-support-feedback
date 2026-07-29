# Release Operations v7.8.1

Release Operations is the operational view connecting a canonical product, its active WordPress implementation, its GitHub repository, and its Release Console state.

## Stabilize release operations

The primary action performs a governed recovery pass:

- Refresh installed-plugin discovery from WordPress’s current active plugin lists.
- Verify and repair the hourly GitHub synchronization schedule.
- Synchronize all products with enabled and mapped GitHub repositories.
- Clear stale error diagnostics only for products that synchronize successfully.
- Invalidate the Release Console cache epoch.
- Run the integrity audit and retain the findings.

The action never changes canonical IDs, plugin folders, repository mappings, aliases, or public copy automatically.

## Connection states

- **Current** — a published GitHub Release is synchronized.
- **Connected · semantic tag** — no published release exists; the newest semantic version tag is authoritative.
- **Connected · no release** — repository and commit evidence are accessible, but no release or semantic tag exists.
- **Authentication required** — the WordPress GitHub credential cannot access the endpoint.
- **Repository unavailable** — GitHub returned HTTP 404 for the mapped repository.
- **GitHub rate limited** — GitHub rejected the request because the API allowance was exhausted.
- **Network error** — WordPress could not receive an HTTP response.
- **Update available** — the active installed plugin is behind the governed GitHub version.
- **Stale** — the last successful synchronization is older than the configured freshness threshold.

## Error evidence

A failed synchronization retains:

- GitHub endpoint label
- GitHub API URL
- HTTP status
- normalized error code
- connection classification
- failure timestamp
- rate-limit evidence when available

No credentials are included.

## Integrity audit

The audit checks:

- duplicate repository mappings
- duplicate plugin mappings
- GitHub-enabled products without repositories
- stale or failed repository synchronization
- active-plugin mappings that disagree with WordPress’s current active list
- invalid repository or Support footer destinations
- missing active plugin implementations for discovery-enabled products

## Cache behavior

The Release Console uses a cache epoch. Registry changes, Plugin Discovery refreshes, GitHub synchronization, footer-copy updates, bulk operations, and stabilization increment that epoch so public output cannot retain stale product or link data.


## Product Connection Editor handoff

Each Release Operations product row now includes **Edit connection**, opening the v7.8.1 single-product editor with that canonical product selected.
