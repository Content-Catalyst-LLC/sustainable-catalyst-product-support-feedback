<?php
$root = dirname(__DIR__);
define('ABSPATH', sys_get_temp_dir() . '/scfs-v753-github-runtime/');
$GLOBALS['scfs_options'] = array();
$GLOBALS['scfs_http'] = array();
function add_action() { return true; }
function apply_filters($hook, $value) { return $value; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['scfs_options']) ? $GLOBALS['scfs_options'][$key] : $default; }
function add_option($key, $value) { if (!array_key_exists($key, $GLOBALS['scfs_options'])) $GLOBALS['scfs_options'][$key] = $value; return true; }
function update_option($key, $value) { $GLOBALS['scfs_options'][$key] = $value; return true; }
function sanitize_key($value) { return trim(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value))); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function esc_url_raw($value) { return (string) $value; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_parse_url($value) { return parse_url($value); }
function wp_remote_get($url, $args = array()) { return $GLOBALS['scfs_http'][$url] ?? new WP_Error('missing_fixture', $url); }
function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
function do_action() { return true; }
function __($text) { return $text; }
class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
    public function add_data($data) { $this->data = $data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php';
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-github-sync.php';
SCFS_Canonical_Product_Registry::activate();
$service = SCFS_Canonical_Product_Registry::instance();
$registry = $service->registry();
$product = $registry['product-support-feedback'];
$product['github_repository_url'] = 'https://github.com/Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback';
$product['github_sync_enabled'] = '1';
$product['installed_version'] = '7.5.2';
$product['discovered_active'] = '1';
$registry['product-support-feedback'] = $product;
update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $service->normalize_registry($registry), false);
$base = 'https://api.github.com/repos/Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback';
$GLOBALS['scfs_http'][$base] = array('response' => array('code' => 200), 'body' => json_encode(array('default_branch' => 'main', 'pushed_at' => '2026-07-22T22:00:00Z')));
$GLOBALS['scfs_http'][$base . '/releases?per_page=20'] = array('response' => array('code' => 200), 'body' => json_encode(array(array('tag_name' => 'v7.8.1', 'name' => 'Product Connection Editor', 'html_url' => 'https://github.com/Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback/releases/tag/v7.8.1', 'published_at' => '2026-07-22T22:05:00Z', 'draft' => false, 'prerelease' => false))));
$GLOBALS['scfs_http'][$base . '/commits/main'] = array('response' => array('code' => 200), 'body' => json_encode(array('sha' => '1234567890abcdef1234567890abcdef12345678')));
$result = SCFS_Canonical_Product_GitHub_Sync::instance()->sync_product('product-support-feedback', 'runtime_test');
if (is_wp_error($result)) { fwrite(STDERR, 'FAIL sync: ' . $result->get_error_message() . "\n"); exit(1); }
$checks = array(
    'public version' => ($result['public_version'] ?? '') === '7.8.1',
    'github version source' => ($result['version_source'] ?? '') === 'github_release',
    'github precedence' => ($result['version_precedence'] ?? '') === 'github',
    'update available' => ($result['status'] ?? '') === 'update_available',
    'release URL' => ($result['github_latest_release_url'] ?? '') === 'https://github.com/Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback/releases/tag/v7.8.1',
    'commit SHA' => ($result['github_latest_commit_sha'] ?? '') === '1234567890abcdef1234567890abcdef12345678',
    'repository updated' => ($result['github_repository_updated_at'] ?? '') === '2026-07-22T22:00:00Z',
    'sync current' => ($result['github_sync_state'] ?? '') === 'current',
    'release label' => ($result['change_summary'] ?? '') === 'Product Connection Editor',
);
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
}
echo "v7.8.1 GitHub-to-console runtime synchronization passed (" . count($checks) . " checks).\n";
