<?php
$root = dirname(__DIR__);
define('ABSPATH', sys_get_temp_dir() . '/scfs-v780-github-intelligence/');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['scfs_options'] = array();
function add_action() { return true; }
function add_submenu_page() { return true; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['scfs_options']) ? $GLOBALS['scfs_options'][$key] : $default; }
function add_option($key, $value) { if (!array_key_exists($key, $GLOBALS['scfs_options'])) $GLOBALS['scfs_options'][$key] = $value; return true; }
function update_option($key, $value) { $GLOBALS['scfs_options'][$key] = $value; return true; }
function sanitize_key($value) { return trim(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value))); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_file_name($value) { return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $value); }
function esc_url_raw($value) { return (string) $value; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function absint($value) { return abs((int) $value); }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, is_array($args) ? $args : array()); }
function wp_rand() { return 12345; }
function __($text) { return $text; }
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-github-release-intelligence.php';
SCFS_GitHub_Release_Intelligence::activate();
$service = SCFS_GitHub_Release_Intelligence::instance();
update_option(SCFS_GitHub_Release_Intelligence::SETTINGS_OPTION, array(
    'polling_schedule' => 'daily',
    'include_prereleases' => '',
    'preview_product_ids' => array('site-intelligence'),
    'history_limit' => 50,
    'webhook_history_limit' => 50,
    'delivery_retention_days' => 30,
));
if ($service->schedule_recurrence() !== 'daily') { fwrite(STDERR, "FAIL schedule\n"); exit(1); }
if (!$service->allow_prerelease('site-intelligence') || $service->allow_prerelease('product-support-feedback')) { fwrite(STDERR, "FAIL prerelease policy\n"); exit(1); }
$repository = array(
    'configured_url' => 'https://github.com/Content-Catalyst-LLC/old-repository',
    'canonical_url' => 'https://github.com/Content-Catalyst-LLC/new-repository',
    'repository' => 'new-repository',
);
$metadata = array(
    'html_url' => 'https://github.com/Content-Catalyst-LLC/new-repository',
    'full_name' => 'Content-Catalyst-LLC/new-repository',
    'name' => 'new-repository',
    'owner' => array('login' => 'Content-Catalyst-LLC'),
    'visibility' => 'private',
    'private' => true,
    'archived' => true,
    'disabled' => false,
    'default_branch' => 'main',
    'created_at' => '2025-01-01T00:00:00Z',
    'updated_at' => '2026-07-23T20:00:00Z',
    'pushed_at' => '2026-07-23T20:30:00Z',
);
$release = array(
    'id' => 780,
    'name' => 'Preview 7.8.1',
    'tag_name' => 'v7.8.1-rc.1',
    'html_url' => 'https://github.com/Content-Catalyst-LLC/new-repository/releases/tag/v7.8.1-rc.1',
    'author' => array('login' => 'release-author'),
    'created_at' => '2026-07-23T20:00:00Z',
    'published_at' => '2026-07-23T20:05:00Z',
    'target_commitish' => 'main',
    'prerelease' => true,
    'draft' => false,
    'assets' => array(array(
        'name' => 'plugin.zip',
        'label' => 'Plugin',
        'content_type' => 'application/zip',
        'size' => 4096,
        'download_count' => 12,
        'updated_at' => '2026-07-23T20:05:00Z',
        'browser_download_url' => 'https://example.test/plugin.zip',
    )),
);
$commit = array(
    'sha' => 'abcdefabcdefabcdefabcdefabcdefabcdefabcd',
    'author' => array('login' => 'committer'),
    'commit' => array(
        'author' => array('name' => 'Commit Author', 'date' => '2026-07-23T20:04:00Z'),
        'message' => "Release candidate\n\nDetails",
    ),
);
$response_meta = array(
    'repository_metadata' => array(
        'status' => 200,
        'endpoint_url' => 'https://api.github.com/repos/Content-Catalyst-LLC/new-repository',
        'rate_limit_remaining' => '4990',
        'rate_limit_limit' => '5000',
        'rate_limit_reset' => '1784844000',
        'rate_limit_resource' => 'core',
        'oauth_scopes' => 'repo',
        'accepted_oauth_scopes' => 'repo',
        'github_sso' => '',
    ),
);
$service->capture_sync('site-intelligence', $repository, $metadata, $release, array(), $commit, 'runtime_test', $response_meta);
$records = get_option(SCFS_GitHub_Release_Intelligence::OPTION_KEY, array());
$record = $records['site-intelligence'] ?? array();
$checks = array(
    'repository identity changed' => !empty($record['repository_identity_changed']),
    'previous repository retained' => ($record['previous_repository_url'] ?? '') === 'https://github.com/Content-Catalyst-LLC/old-repository',
    'private visibility' => ($record['repository_visibility'] ?? '') === 'private' && !empty($record['repository_private']),
    'archived repository' => !empty($record['repository_archived']),
    'prerelease state' => ($record['release_state'] ?? '') === 'prerelease' && !empty($record['release_prerelease']),
    'release author' => ($record['release_author'] ?? '') === 'release-author',
    'asset inventory' => ($record['release_asset_count'] ?? 0) === 1 && ($record['release_assets'][0]['download_count'] ?? 0) === 12,
    'commit evidence' => ($record['commit_author'] ?? '') === 'Commit Author' && strpos($record['commit_message'] ?? '', 'Release candidate') === 0,
    'rate limits' => ($record['rate_limit_remaining'] ?? '') === '4990' && ($record['rate_limit_limit'] ?? '') === '5000',
    'sync history' => count(get_option(SCFS_GitHub_Release_Intelligence::SYNC_HISTORY_OPTION, array())) === 1,
);
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
}
$delivery = 'delivery-780';
if ($service->webhook_delivery_seen($delivery)) { fwrite(STDERR, "FAIL delivery should be new\n"); exit(1); }
$service->record_webhook_delivery($delivery, 'release', $record['repository_url'], 'site-intelligence', 'accepted', 'ok');
if (!$service->webhook_delivery_seen($delivery)) { fwrite(STDERR, "FAIL delivery not recorded\n"); exit(1); }
$service->record_webhook_delivery($delivery, 'release', $record['repository_url'], 'site-intelligence', 'duplicate_ignored', 'duplicate');
if (count(get_option(SCFS_GitHub_Release_Intelligence::WEBHOOK_HISTORY_OPTION, array())) !== 2) { fwrite(STDERR, "FAIL webhook history\n"); exit(1); }
$service->capture_failure('site-intelligence', array(
    'code' => 'rate_limited',
    'message' => 'API rate limit exceeded',
    'status' => 403,
    'endpoint' => 'published_releases',
    'endpoint_url' => 'https://api.github.com/repos/Content-Catalyst-LLC/new-repository/releases',
    'connection_state' => 'rate_limited',
    'rate_limit_remaining' => '0',
    'rate_limit_reset' => '1784845000',
));
$failed = get_option(SCFS_GitHub_Release_Intelligence::OPTION_KEY, array())['site-intelligence'];
if (($failed['sync_state'] ?? '') !== 'error' || ($failed['error']['connection_state'] ?? '') !== 'rate_limited') { fwrite(STDERR, "FAIL failure capture\n"); exit(1); }
echo 'v7.8.1 Private Repository Release Bridge runtime contract passed (' . (count($checks) + 5) . " checks).\n";
