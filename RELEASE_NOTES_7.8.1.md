# Sustainable Catalyst Product Support and Feedback Platform v7.8.1

## Private Repository Release Bridge

v7.8.1 adds a server-side bridge that lets Release Console synchronize approved release data from selected private GitHub repositories without exposing repository credentials, private URLs, release assets, branch names, or commit identifiers to public output.

## GitHub App authentication

- Adds GitHub App ID, installation ID, and encrypted private-key controls to **Support & Feedback → GitHub Connection**.
- Signs RS256 JSON Web Tokens server-side with a clock-drift-safe issued-at time and a sub-ten-minute expiry.
- Exchanges the JWT for a read-only installation access token limited to Contents and Metadata permissions.
- Uses the repository selection configured on the GitHub App installation as the primary access boundary.
- Caches installation tokens only until five minutes before expiry and refreshes once automatically after an HTTP 401 response.
- Preserves the encrypted fine-grained personal access token path as an optional fallback.

## Public privacy boundary

- Private repository and release URLs are removed from public canonical product records.
- Private semantic-tag URLs and commit identifiers are removed from Release Console output.
- Product names remain non-navigating console labels.
- Editors receive the redacted public registry view; only administrators receive the complete operational registry through REST.
- Release version, public-safe title, approved summary, release date, status, and last-synchronized time remain available to Release Console.

## Continuity and diagnostics

- Existing hourly reconciliation, signed release webhooks, manual synchronization, last-known-good records, and per-repository diagnostics remain intact.
- Authentication diagnostics identify the credential source without returning installation tokens, JWTs, private keys, personal tokens, or webhook secrets.
- Public repositories can still be tested without authentication.

## Compatibility

- The WordPress plugin folder remains `sustainable-catalyst-feature-suggestions`.
- The shortcode remains `[sc_release_board]`.
- Terminal, blackboard, compact, and directory layouts remain supported.
- The rotating five-screen Release Console, seven-second configurable interval, controls, pause behavior, reduced-motion support, and fixed footer remain unchanged.
