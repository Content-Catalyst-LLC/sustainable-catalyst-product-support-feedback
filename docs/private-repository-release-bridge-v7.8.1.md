# Private Repository Release Bridge v7.8.1

## Runtime flow

1. WordPress loads the GitHub App ID, installation ID, and private key from constants, environment variables, or the encrypted non-autoloaded option.
2. The server signs a short-lived RS256 JWT.
3. GitHub exchanges the JWT for a one-hour installation token with read-only Contents and Metadata permissions.
4. WordPress retrieves repository metadata, published releases, semantic tags, and default-branch activity.
5. The canonical registry stores complete evidence for administrators and projects only approved fields into the public Release Console.
6. Signed GitHub release webhooks update the snapshot immediately; hourly reconciliation repairs missed deliveries.

## Required GitHub App permissions

- Repository permissions: **Contents — Read-only** and **Metadata — Read-only**.
- Repository access: **Only select repositories**.
- Webhook event: **Release** when immediate synchronization is desired.

## Server constants

```php
define('SCFS_GITHUB_APP_ID', 'YOUR_APP_ID');
define('SCFS_GITHUB_APP_INSTALLATION_ID', 'YOUR_INSTALLATION_ID');
define('SCFS_GITHUB_APP_PRIVATE_KEY', "-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----");
```

The WordPress administration screen can store the same values without editing `wp-config.php`. The private key is encrypted with AES-256-GCM and is never rendered back into the browser.

## Public allowlist

Release Console may publish product name, family, approved version, release title or summary, release date, status, validation/documentation state, and last synchronization time. Private repository URLs, release URLs, tag URLs, commit SHAs, branch names, assets, and credentials remain internal.
