<?php
if (!extension_loaded('openssl')) {
    fwrite(STDERR, "FAIL: OpenSSL extension unavailable\n");
    exit(1);
}

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);

$key_resource = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
if ($key_resource === false || !openssl_pkey_export($key_resource, $private_key)) {
    fwrite(STDERR, "FAIL: test key generation\n");
    exit(1);
}
define('SCFS_GITHUB_APP_ID', '123456');
define('SCFS_GITHUB_APP_INSTALLATION_ID', '987654');
define('SCFS_GITHUB_APP_PRIVATE_KEY', $private_key);

$GLOBALS['scfs_test_options'] = array();
$GLOBALS['scfs_test_transients'] = array();
$GLOBALS['scfs_test_post_count'] = 0;
$GLOBALS['scfs_test_last_post'] = array();

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = array()) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($value) { return $value; }
function add_action() {}
function add_option($key, $value) { $GLOBALS['scfs_test_options'][$key] = $value; return true; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['scfs_test_options']) ? $GLOBALS['scfs_test_options'][$key] : $default; }
function update_option($key, $value) { $GLOBALS['scfs_test_options'][$key] = $value; return true; }
function wp_salt() { return 'private-repository-release-bridge-test-salt'; }
function wp_json_encode($value) { return json_encode($value); }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function get_transient($key) { return $GLOBALS['scfs_test_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['scfs_test_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['scfs_test_transients'][$key]); return true; }
function wp_remote_post($url, $args) {
    $GLOBALS['scfs_test_post_count']++;
    $GLOBALS['scfs_test_last_post'] = array('url' => $url, 'args' => $args);
    return array(
        'response' => array('code' => 201),
        'body' => json_encode(array(
            'token' => 'ghs_123456_stateless_format_is_not_fixed_length',
            'expires_at' => gmdate('c', time() + 3600),
            'permissions' => array('contents' => 'read', 'metadata' => 'read'),
        )),
    );
}
function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }

require_once dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-github-connection-settings.php';
$settings = SCFS_GitHub_Connection_Settings::instance();
$token = $settings->authorization_token();
$cached = $settings->authorization_token();
$post = $GLOBALS['scfs_test_last_post'];
$authorization = (string) ($post['args']['headers']['Authorization'] ?? '');
$jwt = preg_replace('/^Bearer\s+/', '', $authorization);
$segments = explode('.', $jwt);
$payload = array();
if (count($segments) === 3) {
    $encoded = strtr($segments[1], '-_', '+/');
    $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
    $payload = json_decode(base64_decode($encoded), true) ?: array();
}
$body = json_decode((string) ($post['args']['body'] ?? ''), true);
$source = file_get_contents(dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
$sync = file_get_contents(dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-github-sync.php');
$board = file_get_contents(dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-release-board.php');
$manifest = json_decode(file_get_contents(dirname(__DIR__) . '/feature_suggestions_manifest-v7.8.1.json'), true);

$checks = array(
    'github app selected' => $settings->authentication_source() === 'github_app_wp_config',
    'installation token returned' => $token === 'ghs_123456_stateless_format_is_not_fixed_length',
    'installation token cached' => $cached === $token && $GLOBALS['scfs_test_post_count'] === 1,
    'installation endpoint' => $post['url'] === 'https://api.github.com/app/installations/987654/access_tokens',
    'bearer jwt' => count($segments) === 3 && strpos($authorization, 'Bearer ') === 0,
    'jwt issuer' => ($payload['iss'] ?? '') === '123456',
    'jwt clock drift' => ($payload['iat'] ?? PHP_INT_MAX) <= time() - 50,
    'jwt max lifetime' => ($payload['exp'] ?? 0) <= time() + 600 && ($payload['exp'] ?? 0) > time(),
    'read only contents' => ($body['permissions']['contents'] ?? '') === 'read',
    'read only metadata' => ($body['permissions']['metadata'] ?? '') === 'read',
    'current api version' => ($post['args']['headers']['X-GitHub-Api-Version'] ?? '') === '2026-03-10',
    '401 refresh path' => strpos($sync, "if (\$code === 401 && \$retry_authentication") !== false && strpos($sync, 'invalidate_installation_token') !== false,
    'private repository stored internally' => strpos($sync, "github_repository_private") !== false && strpos($sync, "github_repository_visibility") !== false,
    'unknown visibility fails closed' => strpos($source, "\$repository_visibility !== 'public'") !== false,
    'public repository url redacted' => strpos($source, "'github_repository_url' => \$private_repository ? ''") !== false,
    'public release url redacted' => strpos($source, "'github_latest_release_url' => \$private_repository ? ''") !== false,
    'public commit redacted' => strpos($source, "'github_latest_commit_sha' => \$private_repository ? ''") !== false,
    'non admin rest redacted' => strpos($source, "\$administrator ? \$this->registry() : \$this->public_products(false)") !== false,
    'console private commit suppressed' => strpos($board, "\$github_commit = \$private_repository ? ''") !== false,
    'manifest bridge capability' => !empty($manifest['validation']['private_repository_release_bridge']),
    'manifest app authentication' => !empty($manifest['github_connection_settings']['github_app_installation_authentication']),
    'fallback preserved' => !empty($manifest['github_connection_settings']['fine_grained_token_fallback_preserved']),
);
foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}
echo 'v7.8.1 private repository release bridge runtime contract passed (' . count($checks) . " checks).\n";
