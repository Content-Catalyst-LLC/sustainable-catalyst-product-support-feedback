<?php
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['scfs_options'] = array();
function add_action() {}
function add_submenu_page() {}
function add_option($key, $value) { $GLOBALS['scfs_options'][$key] = $value; return true; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['scfs_options']) ? $GLOBALS['scfs_options'][$key] : $default; }
function update_option($key, $value) { $GLOBALS['scfs_options'][$key] = $value; return true; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function esc_url_raw($value) { return trim((string) $value); }
function absint($value) { return abs((int) $value); }
function __($value) { return $value; }
function get_current_user_id() { return 7; }
class SCFS_Installed_Plugin_Discovery {
    public static function instance() { static $instance; return $instance ?: $instance = new self(); }
    public function manual_mappings() { return array('alpha/alpha.php' => array('product_id' => 'alpha')); }
    public function active_plugins_for_mapping() { return array('alpha/alpha.php' => array('name' => 'Alpha Plugin', 'version' => '1.2.3')); }
}
class SCFS_Canonical_Product_GitHub_Sync {
    public static function instance() { static $instance; return $instance ?: $instance = new self(); }
    public function parse_repository_url($url) { return preg_match('#^https://github\.com/[^/]+/[^/]+/?$#', $url) ? array('owner' => 'example', 'repository' => 'alpha') : false; }
}
require dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-product-connection-editor.php';
$service = SCFS_Product_Connection_Editor::instance();
$record = array(
    'name' => 'Alpha', 'short_name' => 'A', 'family' => 'foundation', 'lifecycle_state' => 'active', 'status' => 'current',
    'plugin_file' => 'alpha/alpha.php', 'installed_version' => '1.2.3', 'github_repository_url' => 'https://github.com/example/alpha',
    'github_default_branch' => 'main', 'github_latest_version' => '1.3.0', 'github_version_source' => 'github_release',
    'github_sync_state' => 'current', 'github_last_synced_at' => gmdate('c'), 'support_url' => '/support/', 'documentation_url' => '/docs/',
    'documentation_state' => 'ready', 'validation_state' => 'validated', 'public_visible' => '1', 'homepage_visible' => '1',
    'display_order' => 10, 'legacy_names' => array(), 'legacy_plugin_files' => array(), 'legacy_plugin_slugs' => array(),
    'legacy_text_domains' => array(), 'console_badges' => array('connected'), 'manual_notes' => '', 'version_precedence' => 'github',
);
$plugins = SCFS_Installed_Plugin_Discovery::instance()->active_plugins_for_mapping();
$snapshot = $service->connection_snapshot('alpha', $record, $plugins);
$merged = $service->merge_editable_fields($record, array(
    'name' => 'Alpha Platform', 'product_description' => 'Unified product.', 'console_badges' => "connected, validated, connected",
    'legacy_names' => 'Old Alpha, Alpha Legacy', 'homepage_visible' => '1', 'public_visible' => '1', 'support_url' => '/help/',
));
$valid = $service->validate_connection('alpha', $record, $plugins);
$invalid = $record; $invalid['github_repository_url'] = 'https://example.com/not-github'; $invalid['documentation_url'] = ''; $invalid['documentation_state'] = 'ready';
$invalid_report = $service->validate_connection('alpha', $invalid, $plugins);
$GLOBALS['scfs_options'][SCFS_Product_Connection_Editor::HISTORY_OPTION] = array(
    array('product_id' => 'beta', 'event' => 'connection_saved'),
    array('product_id' => 'alpha', 'event' => 'connection_tested'),
    array('product_id' => 'alpha', 'event' => 'connection_saved'),
);
$history = $service->history_for_product('alpha', 10);
$checks = array(
    'active plugin resolved' => $snapshot['plugin_active'] === true && $snapshot['plugin_name'] === 'Alpha Plugin',
    'versions connected' => $snapshot['installed_version'] === '1.2.3' && $snapshot['github_version'] === '1.3.0',
    'identity merged' => $merged['name'] === 'Alpha Platform' && $merged['product_description'] === 'Unified product.',
    'badges normalized' => $merged['console_badges'] === array('connected', 'validated'),
    'aliases normalized' => $merged['legacy_names'] === array('Old Alpha', 'Alpha Legacy'),
    'valid connection' => $valid['valid'] === true && $valid['error_count'] === 0,
    'invalid repository caught' => $invalid_report['valid'] === false && $invalid_report['error_count'] === 1,
    'documentation warning caught' => $invalid_report['issue_count'] >= 2,
    'history filtered' => count($history) === 2 && $history[0]['event'] === 'connection_tested',
    'schema declares controls' => $service->schema_record()['single_product_editor'] === true && $service->schema_record()['remove_repository_mapping'] === true,
);
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo 'v7.8.1 Product Connection Editor runtime contract passed (' . count($checks) . " checks).\n";
