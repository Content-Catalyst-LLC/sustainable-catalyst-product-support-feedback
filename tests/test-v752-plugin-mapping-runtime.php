<?php
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/scfs-v752-runtime-' . getmypid();
@mkdir($tmp . '/plugins/sustainable-catalyst-feature-suggestions', 0777, true);
@mkdir($tmp . '/plugins/catalyst-unknown', 0777, true);
@mkdir($tmp . '/plugins/catalyst-unrelated', 0777, true);
file_put_contents($tmp . '/plugins/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php', "<?php\n");
file_put_contents($tmp . '/plugins/catalyst-unknown/catalyst-unknown.php', "<?php\n");
file_put_contents($tmp . '/plugins/catalyst-unrelated/catalyst-unrelated.php', "<?php\n");
define('ABSPATH', $tmp . '/');
define('WP_PLUGIN_DIR', $tmp . '/plugins');
$GLOBALS['scfs_options'] = array(
    'active_plugins' => array(
        'sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php',
        'catalyst-unknown/catalyst-unknown.php',
        'catalyst-unrelated/catalyst-unrelated.php',
    ),
);
$GLOBALS['scfs_transients'] = array();
$GLOBALS['scfs_plugins'] = array(
    'sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php' => array(
        'Name' => 'Sustainable Catalyst Product Support and Feedback Platform',
        'Version' => '7.8.1',
        'AuthorName' => 'Content Catalyst LLC',
        'TextDomain' => 'sustainable-catalyst-feature-suggestions',
    ),
    'catalyst-unknown/catalyst-unknown.php' => array(
        'Name' => 'Catalyst Unknown',
        'Version' => '1.2.3',
        'AuthorName' => 'Content Catalyst LLC',
        'TextDomain' => 'catalyst-unknown',
    ),
    'catalyst-unrelated/catalyst-unrelated.php' => array(
        'Name' => 'Catalyst Unrelated',
        'Version' => '0.4.0',
        'AuthorName' => 'Content Catalyst LLC',
        'TextDomain' => 'catalyst-unrelated',
    ),
);
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
function get_plugins() { return $GLOBALS['scfs_plugins']; }
function get_file_data() { return array('SCProductID' => '', 'SCProduct' => '', 'SCPublicRelease' => ''); }
function sanitize_key($value) { $value = strtolower((string) $value); return trim(preg_replace('/[^a-z0-9_\-]/', '', $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function esc_url_raw($value) { return (string) $value; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function do_action() { return true; }
function __($text) { return $text; }
function wp_get_current_user() { return new class { public $user_login = 'runtime-admin'; public function exists() { return true; } }; }
class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
    public function add_data($data) { $this->data = $data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }

require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php';
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php';
SCFS_Canonical_Product_Registry::activate();
SCFS_Installed_Plugin_Discovery::activate();
$discovery = SCFS_Installed_Plugin_Discovery::instance();
$snapshot = $discovery->refresh(true, 'runtime_seed');
if ($snapshot['pending_candidate_count'] !== 2) { fwrite(STDERR, "FAIL initial pending count\n"); exit(1); }
$method = new ReflectionMethod($discovery, 'apply_candidate_decision');
$method->setAccessible(true);
$result = $method->invoke($discovery, 'catalyst-unknown/catalyst-unknown.php', 'catalyst-canvas');
if (is_wp_error($result)) { fwrite(STDERR, "FAIL map: " . $result->get_error_message() . "\n"); exit(1); }
$mappings = $discovery->manual_mappings();
if (($mappings['catalyst-unknown/catalyst-unknown.php']['product_id'] ?? '') !== 'catalyst-canvas') { fwrite(STDERR, "FAIL mapping store\n"); exit(1); }
$canvas = SCFS_Canonical_Product_Registry::instance()->get_product('catalyst-canvas');
if (($canvas['plugin_file'] ?? '') !== 'catalyst-unknown/catalyst-unknown.php' && !in_array('catalyst-unknown/catalyst-unknown.php', $canvas['legacy_plugin_files'], true)) { fwrite(STDERR, "FAIL canonical identifier persistence\n"); exit(1); }
$matches = array_column($discovery->snapshot()['matches'], null, 'plugin_file');
if (($matches['catalyst-unknown/catalyst-unknown.php']['match_strategy'] ?? '') !== 'administrator_mapping') { fwrite(STDERR, "FAIL mapping precedence\n"); exit(1); }
$result = $method->invoke($discovery, 'catalyst-unrelated/catalyst-unrelated.php', '__ignore__');
if (is_wp_error($result) || count($discovery->ignored_candidates()) !== 1) { fwrite(STDERR, "FAIL ignore\n"); exit(1); }
$result = $method->invoke($discovery, 'catalyst-unrelated/catalyst-unrelated.php', '__restore__');
if (is_wp_error($result) || count($discovery->ignored_candidates()) !== 0) { fwrite(STDERR, "FAIL restore\n"); exit(1); }
$result = $method->invoke($discovery, 'catalyst-unknown/catalyst-unknown.php', '__remove_mapping__');
if (is_wp_error($result) || count($discovery->manual_mappings()) !== 0) { fwrite(STDERR, "FAIL remove mapping\n"); exit(1); }
$canvas = SCFS_Canonical_Product_Registry::instance()->get_product('catalyst-canvas');
if (($canvas['plugin_file'] ?? '') === 'catalyst-unknown/catalyst-unknown.php' || in_array('catalyst-unknown/catalyst-unknown.php', $canvas['legacy_plugin_files'], true)) { fwrite(STDERR, "FAIL identifier rollback\n"); exit(1); }
echo "v7.8.1 plugin mapping runtime simulation passed.\n";
