<?php
$root = dirname(__DIR__);
define('ABSPATH', sys_get_temp_dir() . '/scfs-v761-github-diagnostics/');
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
function wp_remote_retrieve_headers($response) { return (array) ($response['headers'] ?? array()); }
function do_action() { return true; }
function __($text) { return $text; }
class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
    public function add_data($data) { $this->data = $data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php';
require $root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-github-sync.php';
SCFS_Canonical_Product_Registry::activate();
$registry_service = SCFS_Canonical_Product_Registry::instance();
$registry = $registry_service->registry();
$product = $registry['contact-engagement'];
$product['github_repository_url'] = 'https://github.com/Content-Catalyst-LLC/sustainable-catalyst-engagement-intake';
$product['github_sync_enabled'] = '1';
$product['github_sync_state'] = 'error';
$product['github_sync_message'] = 'Old stale error';
$product['github_sync_http_status'] = '500';
$product['github_sync_endpoint'] = 'old_endpoint';
$registry['contact-engagement'] = $product;
update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $registry_service->normalize_registry($registry), false);
$base = 'https://api.github.com/repos/Content-Catalyst-LLC/sustainable-catalyst-engagement-intake';
$GLOBALS['scfs_http'][$base] = array(
    'response' => array('code' => 404),
    'headers' => array('x-ratelimit-remaining' => '4998'),
    'body' => json_encode(array('message' => 'Not Found')),
);
$sync = SCFS_Canonical_Product_GitHub_Sync::instance();
$failed = $sync->sync_product('contact-engagement', 'diagnostic_failure');
if (!is_wp_error($failed)) { fwrite(STDERR, "FAIL expected repository error\n"); exit(1); }
$stored = $registry_service->get_product('contact-engagement');
$failure_checks = array(
    'classified unavailable' => ($stored['github_connection_state'] ?? '') === 'repository_unavailable',
    'exact status' => ($stored['github_sync_http_status'] ?? '') === '404',
    'exact endpoint' => ($stored['github_sync_endpoint'] ?? '') === 'repository_metadata',
    'endpoint url' => ($stored['github_sync_endpoint_url'] ?? '') === $base,
    'error code' => ($stored['github_sync_error_code'] ?? '') === 'scfs_canonical_github_repository_unavailable',
    'failed timestamp' => !empty($stored['github_sync_failed_at']),
);
foreach ($failure_checks as $label => $passed) { if (!$passed) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); } }
$GLOBALS['scfs_http'][$base] = array('response' => array('code' => 200), 'body' => json_encode(array('default_branch' => 'main', 'private' => true)));
$GLOBALS['scfs_http'][$base . '/releases?per_page=20'] = array('response' => array('code' => 200), 'body' => '[]');
$GLOBALS['scfs_http'][$base . '/tags?per_page=100'] = array('response' => array('code' => 200), 'body' => '[]');
$GLOBALS['scfs_http'][$base . '/commits/main'] = array('response' => array('code' => 200), 'body' => json_encode(array('sha' => '7617617617617617617617617617617617617617')));
$recovered = $sync->sync_product('contact-engagement', 'diagnostic_recovery');
if (is_wp_error($recovered)) { fwrite(STDERR, 'FAIL recovery: ' . $recovered->get_error_message() . "\n"); exit(1); }
$recovery_checks = array(
    'sync current' => ($recovered['github_sync_state'] ?? '') === 'current',
    'connected no release' => ($recovered['github_connection_state'] ?? '') === 'connected_no_release',
    'stale status cleared' => ($recovered['github_sync_http_status'] ?? '') === '',
    'stale endpoint cleared' => ($recovered['github_sync_endpoint'] ?? '') === '',
    'stale code cleared' => ($recovered['github_sync_error_code'] ?? '') === '',
    'stale failure time cleared' => ($recovered['github_sync_failed_at'] ?? '') === '',
    'clear success message' => strpos((string) ($recovered['github_sync_message'] ?? ''), 'No GitHub Release or semantic version tag') !== false,
);
foreach ($recovery_checks as $label => $passed) { if (!$passed) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); } }
echo 'v7.8.1 GitHub diagnostics and stale-error recovery runtime passed (' . (count($failure_checks) + count($recovery_checks)) . " checks).\n";
