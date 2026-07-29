<?php
$root = dirname(__DIR__);
define('ABSPATH', sys_get_temp_dir() . '/scfs-v755-github-tag-runtime/');
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
    private $message;
    public function __construct($code = '', $message = '', $data = null) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function add_data($data) { return true; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php';
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-github-sync.php';
SCFS_Canonical_Product_Registry::activate();
$service = SCFS_Canonical_Product_Registry::instance();
$registry = $service->registry();
$product = $registry['contact-engagement'];
$product['github_repository_url'] = 'https://github.com/Content-Catalyst-LLC/sustainable-catalyst-engagement-intake';
$product['github_sync_enabled'] = '1';
$product['installed_version'] = '2.0.0';
$product['discovered_active'] = '1';
$registry['contact-engagement'] = $product;
update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $service->normalize_registry($registry), false);
$base = 'https://api.github.com/repos/Content-Catalyst-LLC/sustainable-catalyst-engagement-intake';
$GLOBALS['scfs_http'][$base] = array('response' => array('code' => 200), 'body' => json_encode(array('default_branch' => 'main', 'private' => true, 'pushed_at' => '2026-07-23T05:00:00Z')));
$GLOBALS['scfs_http'][$base . '/releases?per_page=20'] = array('response' => array('code' => 200), 'body' => '[]');
$GLOBALS['scfs_http'][$base . '/tags?per_page=100'] = array('response' => array('code' => 200), 'body' => json_encode(array(
    array('name' => 'build-2026-07-23', 'commit' => array('sha' => 'not-semver')),
    array('name' => 'v2.0.9', 'commit' => array('sha' => '2092092092092092092092092092092092092092')),
    array('name' => 'v2.1.0', 'commit' => array('sha' => '2102102102102102102102102102102102102102')),
)));
$GLOBALS['scfs_http'][$base . '/commits/main'] = array('response' => array('code' => 200), 'body' => json_encode(array('sha' => 'abcdefabcdefabcdefabcdefabcdefabcdefabcd')));
$result = SCFS_Canonical_Product_GitHub_Sync::instance()->sync_product('contact-engagement', 'tag_fallback_test');
if (is_wp_error($result)) { fwrite(STDERR, 'FAIL sync: ' . $result->get_error_message() . "\n"); exit(1); }
$checks = array(
    'tag version selected' => ($result['public_version'] ?? '') === '2.1.0',
    'tag source' => ($result['version_source'] ?? '') === 'github_tag',
    'github source record' => ($result['github_version_source'] ?? '') === 'github_tag',
    'tag name' => ($result['github_latest_tag_name'] ?? '') === 'v2.1.0',
    'tag URL' => ($result['github_latest_tag_url'] ?? '') === 'https://github.com/Content-Catalyst-LLC/sustainable-catalyst-engagement-intake/tree/v2.1.0',
    'release URL empty' => ($result['github_latest_release_url'] ?? '') === '',
    'release date empty' => ($result['release_date'] ?? '') === '',
    'update available' => ($result['status'] ?? '') === 'update_available',
    'commit evidence' => ($result['github_latest_commit_sha'] ?? '') === 'abcdefabcdefabcdefabcdefabcdefabcdefabcd',
    'exact tag fallback message' => strpos((string) ($result['github_sync_message'] ?? ''), 'console version synchronized from tag v2.1.0') !== false,
    'tag change summary' => strpos((string) ($result['change_summary'] ?? ''), 'Version tag v2.1.0 synchronized from GitHub') !== false,
);
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
}
echo 'v7.8.1 GitHub semantic-tag fallback runtime contract passed (' . count($checks) . " checks).\n";
