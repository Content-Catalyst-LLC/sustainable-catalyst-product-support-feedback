<?php
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/scfs-v780-runtime-' . getmypid();
@mkdir($tmp . '/plugins/support', 0777, true);
@mkdir($tmp . '/mu-plugins', 0777, true);
@mkdir($tmp . '/wp-content', 0777, true);
file_put_contents($tmp . '/plugins/support/support.php', "<?php
");
file_put_contents($tmp . '/mu-plugins/security.php', "<?php
");
file_put_contents($tmp . '/wp-content/object-cache.php', "<?php
");
define('ABSPATH', $tmp . '/');
define('WP_PLUGIN_DIR', $tmp . '/plugins');
define('WPMU_PLUGIN_DIR', $tmp . '/mu-plugins');
define('WP_CONTENT_DIR', $tmp . '/wp-content');
$GLOBALS['scfs_options'] = array('active_plugins' => array('support/support.php'));
$GLOBALS['scfs_transients'] = array();
function add_action() { return true; }
function apply_filters($hook, $value) { return $value; }
function current_user_can() { return true; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['scfs_options']) ? $GLOBALS['scfs_options'][$key] : $default; }
function add_option($key, $value) { if (!array_key_exists($key, $GLOBALS['scfs_options'])) $GLOBALS['scfs_options'][$key] = $value; return true; }
function update_option($key, $value) { $GLOBALS['scfs_options'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['scfs_transients'][$key]); return true; }
function get_transient($key) { return $GLOBALS['scfs_transients'][$key] ?? false; }
function set_transient($key, $value) { $GLOBALS['scfs_transients'][$key] = $value; return true; }
function get_site_option($key, $default = false) { return $default; }
function is_multisite() { return false; }
function get_plugins() { return array('support/support.php' => array('Name'=>'Sustainable Catalyst Product Support and Feedback Platform','Version'=>'7.7.1','AuthorName'=>'Content Catalyst LLC','TextDomain'=>'sustainable-catalyst-feature-suggestions','PluginURI'=>'https://github.com/Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback')); }
function get_mu_plugins() { return array('security.php' => array('Name'=>'Sustainable Catalyst Security Guard','Version'=>'1.2.0','AuthorName'=>'Content Catalyst LLC','TextDomain'=>'catalyst-security')); }
function get_dropins() { return array('object-cache.php' => array('Name'=>'Object Cache','Version'=>'','AuthorName'=>'','TextDomain'=>'')); }
function get_file_data() { return array('SCProductID'=>'','SCProduct'=>'','SCPublicRelease'=>''); }
function sanitize_key($value) { return trim(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value))); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function esc_url_raw($value) { return trim((string) $value); }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function do_action() { return true; }
function __($text) { return $text; }
function wp_get_current_user() { return new class { public $user_login='runtime-admin'; public function exists(){return true;} }; }
class WP_Error { private $message; public function __construct($code='', $message=''){ $this->message=$message; } public function get_error_message(){return $this->message;} }
function is_wp_error($value) { return $value instanceof WP_Error; }
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php';
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php';
SCFS_Canonical_Product_Registry::activate();
$registry = SCFS_Canonical_Product_Registry::instance()->registry();
$registry['product-support-feedback']['plugin_file'] = 'support/support.php';
$registry['product-support-feedback']['github_repository_url'] = 'https://github.com/Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback';
$registry['product-support-feedback']['github_latest_version'] = '7.8.1';
update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, SCFS_Canonical_Product_Registry::instance()->normalize_registry($registry));
SCFS_Installed_Plugin_Discovery::activate();
$discovery = SCFS_Installed_Plugin_Discovery::instance();
$snapshot = $discovery->refresh(true, 'v780_runtime');
$inventory = array_column($snapshot['inventory'], null, 'plugin_file');
$checks = array(
    'complete inventory' => $snapshot['installed_plugin_count'] === 3 && count($inventory) === 3,
    'site active classification' => $snapshot['site_active_plugin_count'] === 1,
    'must use classification' => $snapshot['must_use_plugin_count'] === 1 && ($inventory['mu-plugins/security.php']['plugin_type'] ?? '') === 'must-use',
    'dropin classification' => $snapshot['dropin_count'] === 1 && ($inventory['drop-ins/object-cache.php']['plugin_type'] ?? '') === 'drop-in',
    'version comparison' => ($inventory['support/support.php']['version_comparison'] ?? '') === 'update_available',
    'repository match signal' => in_array('exact_plugin_file', $inventory['support/support.php']['suggestion_signals'] ?? array(), true) || ($inventory['support/support.php']['match_strategy'] ?? '') === 'exact_plugin_file',
    'schema capability' => $discovery->schema_record()['complete_plugin_inventory'] === true && $discovery->schema_record()['bulk_map_suggested'] === true,
);
$reflection = new ReflectionMethod($discovery, 'compare_plugin_versions');
$reflection->setAccessible(true);
$checks['ahead comparison'] = $reflection->invoke($discovery, '8.0.0', '7.8.1') === 'ahead';
$checks['current comparison'] = $reflection->invoke($discovery, '7.8.1', '7.8.1') === 'current';
foreach ($checks as $label => $passed) { if (!$passed) { fwrite(STDERR, "FAIL - {$label}
"); exit(1); } }
echo "v7.8.1 Private Repository Release Bridge runtime contract passed (" . count($checks) . " checks).
";
