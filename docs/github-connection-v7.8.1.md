# GitHub Connection v7.8.1

The GitHub Connection screen remains the credential and repository-test surface. v7.8.1 improves the records consumed by that screen and Release Operations.

- WordPress can use an encrypted saved token, `SCFS_GITHUB_TOKEN`, or the environment variable of the same name.
- Constants and environment variables retain precedence over WordPress-stored credentials.
- Tokens and webhook secrets are never rendered after saving.
- Public repositories can be tested without a token.
- Private repository failures now retain the exact endpoint and HTTP status.
- A repository with no GitHub Release remains a successful connection when metadata, tags, and branch evidence are accessible.
- Successful repository retries clear old failure evidence immediately.
