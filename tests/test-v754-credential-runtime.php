<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['scfs_test_options'] = array();
function add_action() {}
function add_submenu_page() {}
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['scfs_test_options']) ? $GLOBALS['scfs_test_options'][$key] : $default; }
function add_option($key, $value) { $GLOBALS['scfs_test_options'][$key] = $value; return true; }
function update_option($key, $value) { $GLOBALS['scfs_test_options'][$key] = $value; return true; }
function wp_salt() { return 'v7.8.1-test-auth-salt-with-sufficient-entropy'; }
function __($text) { return $text; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error {
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
require_once dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-github-connection-settings.php';
$service = SCFS_GitHub_Connection_Settings::instance();
$encrypt = new ReflectionMethod($service, 'encrypt_secret');
$encrypt->setAccessible(true);
$decrypt = new ReflectionMethod($service, 'decrypt_secret');
$decrypt->setAccessible(true);
$token = 'github_pat_v754_runtime_test_token_abcdefghijklmnopqrstuvwxyz';
$cipher = $encrypt->invoke($service, $token);
if (is_wp_error($cipher) || !is_string($cipher) || $cipher === $token || strpos($cipher, $token) !== false) {
    fwrite(STDERR, "FAIL: credential was not encrypted\n");
    exit(1);
}
if ($decrypt->invoke($service, $cipher) !== $token) {
    fwrite(STDERR, "FAIL: credential decryption round trip\n");
    exit(1);
}
$GLOBALS['scfs_test_options'][SCFS_GitHub_Connection_Settings::OPTION_KEY] = array('token_encrypted' => $cipher);
putenv('SCFS_GITHUB_TOKEN');
if ($service->token_source() !== 'wordpress' || $service->token() !== $token) {
    fwrite(STDERR, "FAIL: WordPress credential source\n");
    exit(1);
}
$environment = 'github_pat_v754_environment_token_abcdefghijklmnopqrstuvwxyz';
putenv('SCFS_GITHUB_TOKEN=' . $environment);
if ($service->token_source() !== 'environment' || $service->token() !== $environment) {
    fwrite(STDERR, "FAIL: environment credential precedence\n");
    exit(1);
}
putenv('SCFS_GITHUB_TOKEN');
echo "v7.8.1 encrypted credential runtime contract passed.\n";
