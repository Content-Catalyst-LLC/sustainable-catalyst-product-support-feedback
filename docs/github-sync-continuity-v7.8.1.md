# GitHub Synchronization Continuity v7.8.1

## Version authority

1. Published stable GitHub Release
2. Published prerelease when the synchronized release is explicitly marked prerelease
3. Newest valid semantic version Git tag
4. Existing governed registry version when no GitHub version exists

A branch commit remains activity evidence and never becomes a release version.

## Endpoint diagnostics

The synchronization service identifies each request as one of:

- `repository_metadata`
- `published_releases`
- `semantic_tags`
- `default_branch_commit`

Failures record the exact endpoint, API URL, HTTP status, connection state, and timestamp. A successful retry clears all stale failure fields.

## Classified failures

- HTTP 401 or non-rate-limit HTTP 403: `authentication_required`
- HTTP 404: `repository_unavailable`
- HTTP 429, zero remaining allowance, or a GitHub rate-limit message: `rate_limited`
- Transport failure before an HTTP response: `network_error`
- Invalid JSON: `invalid_response`

A default-branch commit failure is retained as a warning but does not invalidate otherwise valid release or semantic-tag evidence.
