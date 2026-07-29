<?php
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['scfs_options'] = array();
function add_action() {}
function add_submenu_page() {}
function add_option($key, $value) { $GLOBALS['scfs_options'][$key] = $value; return true; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['scfs_options']) ? $GLOBALS['scfs_options'][$key] : $default; }
function update_option($key, $value) { $GLOBALS['scfs_options'][$key] = $value; return true; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function esc_url_raw($value) { return trim((string) $value); }
function apply_filters($hook, $value) { return $value; }
function __($value) { return $value; }
require dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-release-operations-admin.php';

$service = SCFS_Release_Operations_Admin::instance();
$now = gmdate('c');
$old = gmdate('c', time() - (30 * HOUR_IN_SECONDS));
$registry = array(
    'product-a' => array(
        'name' => 'Product A',
        'lifecycle_state' => 'active',
        'public_visible' => '1',
        'homepage_visible' => '1',
        'plugin_file' => 'product-a/product-a.php',
        'discovered_plugin_name' => 'Product A Plugin',
        'discovered_active' => '1',
        'discovered_activation_scope' => 'site',
        'installed_version' => '1.0.0',
        'github_repository_url' => 'https://github.com/example/shared',
        'github_sync_enabled' => '1',
        'github_sync_state' => 'current',
        'github_latest_version' => '1.1.0',
        'github_version_source' => 'github_release',
        'public_version' => '1.1.0',
        'status' => 'update_available',
        'github_last_synced_at' => $now,
    ),
    'product-b' => array(
        'name' => 'Product B',
        'lifecycle_state' => 'active',
        'plugin_file' => 'product-a/product-a.php',
        'discovery_enabled' => '1',
        'github_repository_url' => 'https://github.com/example/shared',
        'github_sync_enabled' => '1',
        'github_sync_state' => 'current',
        'github_latest_version' => '2.0.0',
        'github_version_source' => 'github_tag',
        'public_version' => '2.0.0',
        'status' => 'stable',
        'github_last_synced_at' => $old,
    ),
    'product-c' => array(
        'name' => 'Product C',
        'lifecycle_state' => 'active',
        'github_sync_enabled' => '1',
        'github_sync_state' => 'error',
        'github_sync_message' => 'HTTP 404',
        'status' => 'unverified',
    ),
    'retired-product' => array(
        'name' => 'Retired',
        'lifecycle_state' => 'retired',
    ),
);
$report = $service->operations_report($registry);
$audit = $service->audit_registry($registry);
$checks = array(
    'current freshness' => $service->freshness_state($now, 'current') === 'current',
    'stale freshness' => $service->freshness_state($old, 'current') === 'stale',
    'error precedence' => $service->freshness_state($now, 'error') === 'error',
    'retired excluded' => $report['summary']['products'] === 3,
    'active plugin counted' => $report['summary']['active_plugins'] === 1,
    'repositories counted' => $report['summary']['repositories'] === 2,
    'update counted' => $report['summary']['updates'] === 1,
    'error counted' => $report['summary']['errors'] === 1,
    'stale counted' => $report['summary']['stale'] === 1,
    'credentials absent' => strpos(json_encode($report), 'github_pat_') === false,
    'duplicate findings' => $audit['finding_count'] >= 4,
    'audit persisted' => isset($GLOBALS['scfs_options'][SCFS_Release_Operations_Admin::AUDIT_OPTION]),
);
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}
echo 'v7.8.1 Release Operations runtime contract passed (' . count($checks) . " checks).\n";
