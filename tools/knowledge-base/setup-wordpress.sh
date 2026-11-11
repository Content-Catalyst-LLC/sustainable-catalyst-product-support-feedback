#!/usr/bin/env bash
# Create a fresh synthetic WordPress installation. Never points at production by default.
set -euo pipefail
: "${SC_KB_WP_ROOT:?Set SC_KB_WP_ROOT to an isolated, writable WordPress directory}"
SC_KB_DB_ENGINE="${SC_KB_DB_ENGINE:-mysql}"
SC_KB_WP_VERSION="${SC_KB_WP_VERSION:-6.8.3}"
SC_KB_SITE_URL="${SC_KB_SITE_URL:-http://sc-kb-batch2.test}"
SC_KB_PLUGIN_SOURCE="$(cd "$(dirname "$0")/../../wordpress/sustainable-catalyst-feature-suggestions" && pwd)"
mkdir -p "$SC_KB_WP_ROOT"
export WP_CLI_CACHE_DIR="${WP_CLI_CACHE_DIR:-$SC_KB_WP_ROOT/.wp-cli-cache}"
wp core download --path="$SC_KB_WP_ROOT" --version="$SC_KB_WP_VERSION" --skip-content
if [[ "$SC_KB_DB_ENGINE" == sqlite ]]; then
  SC_KB_SQLITE_VERSION="${SC_KB_SQLITE_VERSION:-3.1.3}"
  mkdir -p "$SC_KB_WP_ROOT/wp-content/plugins"
  curl --fail --location "https://downloads.wordpress.org/plugin/sqlite-database-integration.$SC_KB_SQLITE_VERSION.zip" --output "$SC_KB_WP_ROOT/sqlite-integration.zip"
  unzip -q "$SC_KB_WP_ROOT/sqlite-integration.zip" -d "$SC_KB_WP_ROOT/wp-content/plugins"
  rm "$SC_KB_WP_ROOT/sqlite-integration.zip"
  cp "$SC_KB_WP_ROOT/wp-content/plugins/sqlite-database-integration/db.copy" "$SC_KB_WP_ROOT/wp-content/db.php"
  wp config create --path="$SC_KB_WP_ROOT" --dbname=sc_kb_batch2 --dbuser=sc_kb_test --dbpass=local-synthetic-fixture --dbhost=127.0.0.1 --skip-check
else
  : "${SC_KB_DB_NAME:?Set synthetic test DB name}"
  : "${SC_KB_DB_USER:?Set synthetic test DB user}"
  : "${SC_KB_DB_PASSWORD:?Set synthetic test DB password}"
  : "${SC_KB_DB_HOST:?Set isolated DB host, optionally with port}"
  wp config create --path="$SC_KB_WP_ROOT" --dbname="$SC_KB_DB_NAME" --dbuser="$SC_KB_DB_USER" --dbpass="$SC_KB_DB_PASSWORD" --dbhost="$SC_KB_DB_HOST"
fi
# Random local-only credential is kept out of command output and source control.
SC_KB_PASSWORD_FILE="$SC_KB_WP_ROOT/.sc-kb-test-admin-password"
php -r 'file_put_contents($argv[1], bin2hex(random_bytes(24))."\n"); chmod($argv[1], 0600);' "$SC_KB_PASSWORD_FILE"
SC_KB_ADMIN_PASSWORD="$(cat "$SC_KB_PASSWORD_FILE")"
wp core install --path="$SC_KB_WP_ROOT" --url="$SC_KB_SITE_URL" --title='SC KB isolated synthetic test' --admin_user=sc_kb_test_admin --admin_email=kb-test@example.invalid --skip-email --admin_password="$SC_KB_ADMIN_PASSWORD"
unset SC_KB_ADMIN_PASSWORD
mkdir -p "$SC_KB_WP_ROOT/wp-content/plugins"
ln -s "$SC_KB_PLUGIN_SOURCE" "$SC_KB_WP_ROOT/wp-content/plugins/sustainable-catalyst-feature-suggestions"
echo 'Isolated WordPress installed. Support plugin is linked but deliberately not activated.'
