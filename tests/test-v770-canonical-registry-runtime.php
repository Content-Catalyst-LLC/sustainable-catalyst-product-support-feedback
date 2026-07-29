<?php
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['scfs_options'] = array();
$GLOBALS['scfs_actions'] = array();
function add_action() {}
function add_submenu_page() {}
function add_option($key, $value) { $GLOBALS['scfs_options'][$key] = $value; return true; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['scfs_options']) ? $GLOBALS['scfs_options'][$key] : $default; }
function update_option($key, $value) { $GLOBALS['scfs_options'][$key] = $value; return true; }
function apply_filters($hook, $value) { return $value; }
function current_user_can() { return true; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function esc_url_raw($value) { return trim((string) $value); }
function absint($value) { return abs((int) $value); }
function __($value) { return $value; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_generate_uuid4() { static $i = 0; $i++; return '00000000-0000-4000-8000-' . str_pad((string) $i, 12, '0', STR_PAD_LEFT); }
function get_current_user_id() { return 11; }
function wp_get_current_user() { return (object) array('ID' => 11, 'user_login' => 'registry-admin', 'display_name' => 'Registry Admin'); }
function do_action($hook) { $GLOBALS['scfs_actions'][] = $hook; }
class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code, $message, $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
require dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php';
require dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry-admin.php';
$registry_service = SCFS_Canonical_Product_Registry::instance();
$admin = SCFS_Canonical_Product_Registry_Admin::instance();
$product = $registry_service->new_product_record('alpha-platform', 'Alpha Platform', 'Alpha', 'research-intelligence', 120, array(
    'lifecycle_state' => 'experimental',
    'product_description' => 'Experimental governed product.',
    'public_visible' => '',
    'homepage_visible' => '',
));
$archived = $product;
$archived['archived'] = '1';
$archived['archived_at'] = '2026-07-23T12:00:00Z';
$normalized = $registry_service->normalize_registry(array('alpha-platform' => $archived));
$GLOBALS['scfs_options'][SCFS_Canonical_Product_Registry::OPTION_KEY] = $normalized;
$public = $registry_service->public_products();
$reflection = new ReflectionClass($admin);
$backup_method = $reflection->getMethod('create_backup');
$backup_method->setAccessible(true);
$backup = $backup_method->invoke($admin, 'runtime_test', $normalized);
$history_method = $reflection->getMethod('record_history');
$history_method->setAccessible(true);
$history_method->invoke($admin, 'runtime_change', array('product_id' => 'alpha-platform'));
$merge_method = $reflection->getMethod('merge_identifier_lists');
$merge_method->setAccessible(true);
$merged = $merge_method->invoke($admin, array('legacy_names' => array('Alpha')), array('legacy_names' => array('Old Alpha')), 'legacy_names', array('Alpha Legacy'));
$schema = $admin->schema_record();
$checks = array(
    'new product normalized' => $product['canonical_id'] === 'alpha-platform' && $product['family'] === 'research-intelligence',
    'expanded lifecycle accepted' => $product['lifecycle_state'] === 'experimental',
    'description retained' => $product['product_description'] === 'Experimental governed product.',
    'archive fields retained' => $normalized['alpha-platform']['archived'] === '1' && $normalized['alpha-platform']['archived_at'] !== '',
    'archived hidden publicly' => count($public) === 0,
    'backup contains fingerprint' => $backup['product_count'] === 1 && strlen($backup['fingerprint']) === 64,
    'history attributed' => $admin->history(1)[0]['administrator']['display_name'] === 'Registry Admin',
    'aliases merged uniquely' => $merged === array('Alpha', 'Old Alpha', 'Alpha Legacy'),
    'schema capabilities' => $schema['searchable_registry'] === true && $schema['dry_run_import'] === true && $schema['backup_restore'] === true,
);
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo 'v7.8.1 Private Repository Release Bridge runtime contract passed (' . count($checks) . " checks).\n";
