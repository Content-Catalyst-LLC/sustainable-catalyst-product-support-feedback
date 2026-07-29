<?php
/**
 * GitHub connection settings.
 *
 * Provides an administrator-managed, encrypted credential option for private
 * repositories while preserving wp-config.php and environment-variable
 * overrides. Credentials are never rendered back into the browser or logged.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_GitHub_Connection_Settings {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-github-connection-settings/1.1';
    const OPTION_KEY = 'scfs_github_connection_settings';
    const ADMIN_SLUG = 'scfs-github-connection';
    const NONCE_ACTION = 'scfs_save_github_connection';
    const TEST_NONCE_ACTION = 'scfs_test_github_connection';
    const TEST_TRANSIENT_PREFIX = 'scfs_github_connection_test_';
    const INSTALLATION_TOKEN_TRANSIENT_PREFIX = 'scfs_github_app_installation_token_';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_page'), 22);
        add_action('admin_post_scfs_save_github_connection', array($this, 'save_action'));
        add_action('admin_post_scfs_test_github_connection', array($this, 'test_action'));
        add_action('admin_post_scfs_sync_all_canonical_github', array($this, 'sync_all_action'));
    }

    public static function activate() {
        if (!is_array(get_option(self::OPTION_KEY))) {
            add_option(self::OPTION_KEY, array(), '', false);
        }
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'authentication_modes' => array('github_app_installation', 'fine_grained_personal_access_token', 'anonymous_public'),
            'preferred_authentication' => 'github_app_installation',
            'github_app_sources' => array('wp_config_constant', 'environment_variable', 'encrypted_wordpress_option'),
            'github_app_installation_tokens' => true,
            'github_app_installation_token_ttl_seconds' => 3600,
            'github_app_permissions' => array('contents' => 'read', 'metadata' => 'read'),
            'github_app_selected_repository_installation' => true,
            'token_sources' => array('wp_config_constant', 'environment_variable', 'encrypted_wordpress_option'),
            'token_source_precedence' => array('github_app_installation', 'wp_config_constant', 'environment_variable', 'encrypted_wordpress_option'),
            'credentials_rendered' => false,
            'credentials_logged' => false,
            'autoload' => false,
            'administrator_test_supported' => true,
            'mapped_repository_diagnostics' => true,
            'console_footer_controls' => true,
            'automatic_sync_health_visible' => true,
            'public_repository_testing_without_token' => true,
        );
    }

    private function settings() {
        $settings = get_option(self::OPTION_KEY, array());
        return is_array($settings) ? $settings : array();
    }

    private function key_material() {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }
        if (defined('AUTH_KEY') && AUTH_KEY) {
            return (string) AUTH_KEY;
        }
        return __FILE__;
    }

    private function encryption_key() {
        return hash('sha256', $this->key_material() . '|scfs-github-connection-settings', true);
    }

    private function encrypt_secret($secret) {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            return new WP_Error('scfs_github_encryption_unavailable', __('Secure credential storage is unavailable on this server. Use wp-config.php instead.', 'sustainable-catalyst-feature-suggestions'));
        }
        try {
            $iv = random_bytes(12);
        } catch (Exception $exception) {
            return new WP_Error('scfs_github_random_unavailable', __('Secure random-number generation is unavailable on this server.', 'sustainable-catalyst-feature-suggestions'));
        }
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, 'scfs-github-credential');
        if ($ciphertext === false || strlen($tag) !== 16) {
            return new WP_Error('scfs_github_encryption_failed', __('The GitHub credential could not be encrypted.', 'sustainable-catalyst-feature-suggestions'));
        }
        return 'v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt_secret($payload) {
        $payload = (string) $payload;
        if (strpos($payload, 'v1:') !== 0 || !function_exists('openssl_decrypt')) {
            return '';
        }
        $decoded = base64_decode(substr($payload, 3), true);
        if (!is_string($decoded) || strlen($decoded) < 29) {
            return '';
        }
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, 'scfs-github-credential');
        return is_string($plain) ? trim($plain) : '';
    }

    public function token_source() {
        if (defined('SCFS_GITHUB_TOKEN') && trim((string) SCFS_GITHUB_TOKEN) !== '') {
            return 'wp_config';
        }
        $environment = getenv('SCFS_GITHUB_TOKEN');
        if (is_string($environment) && trim($environment) !== '') {
            return 'environment';
        }
        $settings = $this->settings();
        if ($this->decrypt_secret($settings['token_encrypted'] ?? '') !== '') {
            return 'wordpress';
        }
        return 'none';
    }

    public function token() {
        if (defined('SCFS_GITHUB_TOKEN') && trim((string) SCFS_GITHUB_TOKEN) !== '') {
            return trim((string) SCFS_GITHUB_TOKEN);
        }
        $environment = getenv('SCFS_GITHUB_TOKEN');
        if (is_string($environment) && trim($environment) !== '') {
            return trim($environment);
        }
        $settings = $this->settings();
        return $this->decrypt_secret($settings['token_encrypted'] ?? '');
    }

    private function external_value($constant_name, $environment_name) {
        if (defined($constant_name)) {
            $value = constant($constant_name);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        $value = getenv($environment_name);
        return is_string($value) ? trim($value) : '';
    }

    private function normalize_private_key($private_key) {
        $private_key = trim((string) $private_key);
        if ($private_key === '') {
            return '';
        }
        return str_replace(array("\r\n", '\\n'), array("\n", "\n"), $private_key);
    }

    public function github_app_id_source() {
        if ($this->external_value('SCFS_GITHUB_APP_ID', 'SCFS_GITHUB_APP_ID') !== '') {
            return defined('SCFS_GITHUB_APP_ID') ? 'wp_config' : 'environment';
        }
        $settings = $this->settings();
        return !empty($settings['github_app_id']) ? 'wordpress' : 'none';
    }

    public function github_app_id() {
        $external = $this->external_value('SCFS_GITHUB_APP_ID', 'SCFS_GITHUB_APP_ID');
        if ($external !== '') {
            return preg_replace('/[^A-Za-z0-9_-]/', '', $external);
        }
        $settings = $this->settings();
        return preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($settings['github_app_id'] ?? ''));
    }

    public function github_app_installation_id_source() {
        if ($this->external_value('SCFS_GITHUB_APP_INSTALLATION_ID', 'SCFS_GITHUB_APP_INSTALLATION_ID') !== '') {
            return defined('SCFS_GITHUB_APP_INSTALLATION_ID') ? 'wp_config' : 'environment';
        }
        $settings = $this->settings();
        return !empty($settings['github_app_installation_id']) ? 'wordpress' : 'none';
    }

    public function github_app_installation_id() {
        $external = $this->external_value('SCFS_GITHUB_APP_INSTALLATION_ID', 'SCFS_GITHUB_APP_INSTALLATION_ID');
        if ($external !== '') {
            return absint($external);
        }
        $settings = $this->settings();
        return absint($settings['github_app_installation_id'] ?? 0);
    }

    public function github_app_private_key_source() {
        if ($this->external_value('SCFS_GITHUB_APP_PRIVATE_KEY', 'SCFS_GITHUB_APP_PRIVATE_KEY') !== '') {
            return defined('SCFS_GITHUB_APP_PRIVATE_KEY') ? 'wp_config' : 'environment';
        }
        $settings = $this->settings();
        return $this->decrypt_secret($settings['github_app_private_key_encrypted'] ?? '') !== '' ? 'wordpress' : 'none';
    }

    public function github_app_private_key() {
        $external = $this->external_value('SCFS_GITHUB_APP_PRIVATE_KEY', 'SCFS_GITHUB_APP_PRIVATE_KEY');
        if ($external !== '') {
            return $this->normalize_private_key($external);
        }
        $settings = $this->settings();
        return $this->normalize_private_key($this->decrypt_secret($settings['github_app_private_key_encrypted'] ?? ''));
    }

    public function github_app_configured() {
        return $this->github_app_id() !== '' && $this->github_app_installation_id() > 0 && $this->github_app_private_key() !== '';
    }

    public function authentication_source() {
        if ($this->github_app_configured()) {
            return 'github_app_' . $this->github_app_private_key_source();
        }
        return $this->token_source();
    }

    private function base64url_encode($value) {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    private function github_app_jwt() {
        if (!$this->github_app_configured()) {
            return new WP_Error('scfs_github_app_incomplete', __('The GitHub App ID, installation ID, and private key must all be configured.', 'sustainable-catalyst-feature-suggestions'));
        }
        if (!function_exists('openssl_sign') || !function_exists('openssl_pkey_get_private')) {
            return new WP_Error('scfs_github_app_openssl_unavailable', __('OpenSSL signing is required for GitHub App authentication.', 'sustainable-catalyst-feature-suggestions'));
        }
        $private_key = openssl_pkey_get_private($this->github_app_private_key());
        if ($private_key === false) {
            return new WP_Error('scfs_github_app_private_key_invalid', __('The stored GitHub App private key is invalid.', 'sustainable-catalyst-feature-suggestions'));
        }
        $now = time();
        $header = $this->base64url_encode(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $payload = $this->base64url_encode(wp_json_encode(array(
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => (string) $this->github_app_id(),
        )));
        $signing_input = $header . '.' . $payload;
        $signature = '';
        $signed = openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
        if (function_exists('openssl_pkey_free')) {
            @openssl_pkey_free($private_key);
        }
        if (!$signed) {
            return new WP_Error('scfs_github_app_jwt_signing_failed', __('The GitHub App JWT could not be signed.', 'sustainable-catalyst-feature-suggestions'));
        }
        return $signing_input . '.' . $this->base64url_encode($signature);
    }

    private function installation_token_transient_key() {
        $fingerprint = hash('sha256', $this->github_app_id() . '|' . $this->github_app_installation_id() . '|' . $this->github_app_private_key());
        return self::INSTALLATION_TOKEN_TRANSIENT_PREFIX . substr($fingerprint, 0, 24);
    }

    public function invalidate_installation_token() {
        if ($this->github_app_configured()) {
            delete_transient($this->installation_token_transient_key());
        }
    }

    public function installation_token($force_refresh = false) {
        if (!$this->github_app_configured()) {
            return new WP_Error('scfs_github_app_not_configured', __('GitHub App authentication is not configured.', 'sustainable-catalyst-feature-suggestions'));
        }
        $cache_key = $this->installation_token_transient_key();
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && !empty($cached['token']) && (int) ($cached['expires_at_unix'] ?? 0) > time() + 120) {
                return (string) $cached['token'];
            }
        }
        $jwt = $this->github_app_jwt();
        if (is_wp_error($jwt)) {
            return $jwt;
        }
        $endpoint = 'https://api.github.com/app/installations/' . $this->github_app_installation_id() . '/access_tokens';
        $response = wp_remote_post($endpoint, array(
            'timeout' => 15,
            'redirection' => 0,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer ' . $jwt,
                'Content-Type' => 'application/json',
                'User-Agent' => 'Sustainable-Catalyst-Product-Support/' . self::VERSION,
                'X-GitHub-Api-Version' => '2026-03-10',
            ),
            'body' => wp_json_encode(array(
                'permissions' => array(
                    'contents' => 'read',
                    'metadata' => 'read',
                ),
            )),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('scfs_github_app_token_network_error', sprintf(__('GitHub App token request failed: %s', 'sustainable-catalyst-feature-suggestions'), $response->get_error_message()));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['token'])) {
            $message = is_array($decoded) && !empty($decoded['message']) ? sanitize_text_field($decoded['message']) : __('GitHub did not return an installation access token.', 'sustainable-catalyst-feature-suggestions');
            return new WP_Error('scfs_github_app_token_rejected', sprintf(__('GitHub App token request returned HTTP %1$d: %2$s', 'sustainable-catalyst-feature-suggestions'), $status, $message), array('status' => $status, 'endpoint' => 'app_installation_token'));
        }
        $expires_at_unix = !empty($decoded['expires_at']) ? (int) strtotime($decoded['expires_at']) : time() + HOUR_IN_SECONDS;
        $ttl = max(60, min(55 * MINUTE_IN_SECONDS, $expires_at_unix - time() - 300));
        set_transient($cache_key, array(
            'token' => (string) $decoded['token'],
            'expires_at' => sanitize_text_field($decoded['expires_at'] ?? ''),
            'expires_at_unix' => $expires_at_unix,
        ), $ttl);
        return (string) $decoded['token'];
    }

    public function authorization_token($force_refresh = false) {
        if ($this->github_app_configured()) {
            return $this->installation_token($force_refresh);
        }
        return $this->token();
    }

    public function webhook_secret_source() {
        if (defined('SCFS_GITHUB_WEBHOOK_SECRET') && trim((string) SCFS_GITHUB_WEBHOOK_SECRET) !== '') {
            return 'wp_config';
        }
        $environment = getenv('SCFS_GITHUB_WEBHOOK_SECRET');
        if (is_string($environment) && trim($environment) !== '') {
            return 'environment';
        }
        $settings = $this->settings();
        if ($this->decrypt_secret($settings['webhook_secret_encrypted'] ?? '') !== '') {
            return 'wordpress';
        }
        return 'none';
    }

    public function webhook_secret() {
        if (defined('SCFS_GITHUB_WEBHOOK_SECRET') && trim((string) SCFS_GITHUB_WEBHOOK_SECRET) !== '') {
            return trim((string) SCFS_GITHUB_WEBHOOK_SECRET);
        }
        $environment = getenv('SCFS_GITHUB_WEBHOOK_SECRET');
        if (is_string($environment) && trim($environment) !== '') {
            return trim($environment);
        }
        $settings = $this->settings();
        return $this->decrypt_secret($settings['webhook_secret_encrypted'] ?? '');
    }

    private function source_label($source) {
        $labels = array(
            'wp_config' => __('Configured in wp-config.php', 'sustainable-catalyst-feature-suggestions'),
            'environment' => __('Configured as a server environment variable', 'sustainable-catalyst-feature-suggestions'),
            'wordpress' => __('Stored securely in WordPress', 'sustainable-catalyst-feature-suggestions'),
            'github_app_wp_config' => __('GitHub App configured in wp-config.php', 'sustainable-catalyst-feature-suggestions'),
            'github_app_environment' => __('GitHub App configured as server environment variables', 'sustainable-catalyst-feature-suggestions'),
            'github_app_wordpress' => __('GitHub App stored securely in WordPress', 'sustainable-catalyst-feature-suggestions'),
            'none' => __('Not configured', 'sustainable-catalyst-feature-suggestions'),
        );
        return $labels[$source] ?? $labels['none'];
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('GitHub Connection', 'sustainable-catalyst-feature-suggestions'),
            __('GitHub Connection', 'sustainable-catalyst-feature-suggestions'),
            'manage_options',
            self::ADMIN_SLUG,
            array($this, 'render_admin_page')
        );
    }

    private function test_transient_key() {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        return self::TEST_TRANSIENT_PREFIX . $user_id;
    }

    private function mapped_repositories() {
        $repositories = array();
        if (!class_exists('SCFS_Canonical_Product_Registry')) {
            return $repositories;
        }
        foreach (SCFS_Canonical_Product_Registry::instance()->registry() as $product_id => $record) {
            $url = trim((string) ($record['github_repository_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $repositories[$product_id] = array(
                'name' => sanitize_text_field($record['name'] ?? $product_id),
                'url' => esc_url_raw($url),
                'state' => sanitize_key($record['github_sync_state'] ?? 'never'),
                'message' => sanitize_text_field($record['github_sync_message'] ?? ''),
                'last_synced_at' => sanitize_text_field($record['github_last_synced_at'] ?? ''),
                'latest_version' => sanitize_text_field($record['github_latest_version'] ?? ''),
            );
        }
        return $repositories;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage the GitHub connection.', 'sustainable-catalyst-feature-suggestions'));
        }
        $token_source = $this->token_source();
        $authentication_source = $this->authentication_source();
        $webhook_source = $this->webhook_secret_source();
        $footer = class_exists('SCFS_Release_Console_Copy') ? SCFS_Release_Console_Copy::instance()->footer_settings() : array();
        $next_sync = class_exists('SCFS_Canonical_Product_GitHub_Sync') ? SCFS_Canonical_Product_GitHub_Sync::instance()->next_scheduled_sync() : false;
        $test_result = get_transient($this->test_transient_key());
        if ($test_result !== false) {
            delete_transient($this->test_transient_key());
        }
        echo '<div class="wrap scfs-github-connection"><h1>' . esc_html__('GitHub Connection', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p>' . esc_html__('Connect selected private GitHub repositories through a least-privilege GitHub App. Installation tokens are minted server-side, cached briefly, and never sent to the browser. A fine-grained token remains available as a fallback.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        if (class_exists('SCFS_Release_Operations_Admin')) {
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . SCFS_Release_Operations_Admin::ADMIN_SLUG)) . '">' . esc_html__('Open Release Operations', 'sustainable-catalyst-feature-suggestions') . '</a></p>';
        }
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('GitHub connection settings saved.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        }
        if (isset($_GET['sync_complete'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Repository synchronization finished. Review the repository table for individual results.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        }
        if (is_array($test_result)) {
            $ok = !empty($test_result['ok']);
            echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p><strong>' . esc_html($ok ? __('GitHub connection test passed.', 'sustainable-catalyst-feature-suggestions') : __('GitHub connection test found a problem.', 'sustainable-catalyst-feature-suggestions')) . '</strong></p>';
            if (!empty($test_result['message'])) {
                echo '<p>' . esc_html($test_result['message']) . '</p>';
            }
            if (!empty($test_result['results']) && is_array($test_result['results'])) {
                echo '<ul>';
                foreach ($test_result['results'] as $name => $result) {
                    echo '<li><strong>' . esc_html($name) . ':</strong> ' . esc_html($result['message'] ?? '') . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        echo '<h2>' . esc_html__('Private Repository Release Bridge', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__('Current authentication', 'sustainable-catalyst-feature-suggestions') . '</th><td><strong>' . esc_html($this->source_label($authentication_source)) . '</strong>';
        if (strpos($authentication_source, 'github_app_') === 0) {
            echo '<p class="description">' . esc_html(sprintf(__('GitHub App %1$s · installation %2$d · read-only Contents and Metadata permissions.', 'sustainable-catalyst-feature-suggestions'), $this->github_app_id(), $this->github_app_installation_id())) . '</p>';
        } elseif ($token_source === 'wp_config' || $token_source === 'environment') {
            echo '<p class="description">' . esc_html__('The external token takes priority over a WordPress-stored fallback token.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        }
        echo '</td></tr><tr><th scope="row">' . esc_html__('Automatic synchronization', 'sustainable-catalyst-feature-suggestions') . '</th><td><strong>' . esc_html($next_sync ? __('Scheduled hourly', 'sustainable-catalyst-feature-suggestions') : __('Schedule will be repaired automatically', 'sustainable-catalyst-feature-suggestions')) . '</strong>';
        if ($next_sync) {
            $next_label = function_exists('wp_date') ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_sync) : date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_sync);
            echo '<p class="description">' . esc_html(sprintf(__('Next scheduled check: %s', 'sustainable-catalyst-feature-suggestions'), $next_label)) . '</p>';
        }
        echo '</td></tr></tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_save_github_connection">';
        wp_nonce_field(self::NONCE_ACTION, 'scfs_github_connection_nonce');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="scfs-github-app-id">' . esc_html__('GitHub App ID', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input id="scfs-github-app-id" class="regular-text code" name="github_app_id" value="' . esc_attr($this->github_app_id()) . '" inputmode="numeric"><p class="description">' . esc_html__('Use the App ID from the GitHub App settings page, not the client secret.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-github-app-installation-id">' . esc_html__('Installation ID', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input id="scfs-github-app-installation-id" class="regular-text code" name="github_app_installation_id" value="' . esc_attr($this->github_app_installation_id()) . '" inputmode="numeric"><p class="description">' . esc_html__('Install the app only on the repositories Release Console is allowed to read.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-github-app-private-key">' . esc_html__('GitHub App private key', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><textarea id="scfs-github-app-private-key" class="large-text code" rows="6" name="github_app_private_key" autocomplete="new-password" placeholder="-----BEGIN RSA PRIVATE KEY-----"></textarea><p class="description">' . esc_html__('Paste the PEM private key once. It is encrypted before storage and is never rendered back into this page.', 'sustainable-catalyst-feature-suggestions') . ' ' . esc_html(sprintf(__('Current status: %s.', 'sustainable-catalyst-feature-suggestions'), $this->source_label($this->github_app_private_key_source()))) . '</p><label><input type="checkbox" name="remove_github_app" value="1"> ' . esc_html__('Remove the WordPress-stored GitHub App configuration', 'sustainable-catalyst-feature-suggestions') . '</label></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-github-token">' . esc_html__('Fallback GitHub token', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input id="scfs-github-token" class="regular-text code" type="password" name="github_token" value="" autocomplete="new-password" placeholder="github_pat_…"><p class="description">' . esc_html__('Optional fallback for sites that cannot use a GitHub App. Leave blank to keep the current stored token.', 'sustainable-catalyst-feature-suggestions') . '</p><label><input type="checkbox" name="remove_github_token" value="1"> ' . esc_html__('Remove the WordPress-stored fallback token', 'sustainable-catalyst-feature-suggestions') . '</label></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-github-webhook-secret">' . esc_html__('Webhook secret', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input id="scfs-github-webhook-secret" class="regular-text code" type="password" name="webhook_secret" value="" autocomplete="new-password"><p class="description">' . esc_html__('Optional. Use the same secret in GitHub webhooks for immediate updates. Hourly synchronization works without it.', 'sustainable-catalyst-feature-suggestions') . ' ' . esc_html(sprintf(__('Current status: %s.', 'sustainable-catalyst-feature-suggestions'), $this->source_label($webhook_source))) . '</p><label><input type="checkbox" name="remove_webhook_secret" value="1"> ' . esc_html__('Remove the WordPress-stored webhook secret', 'sustainable-catalyst-feature-suggestions') . '</label></td></tr>';
        echo '</tbody></table>';
        echo '<h2>' . esc_html__('Release Console footer links', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<p>' . esc_html__('Edit the two terminal footer links here. These are the same settings used by Release Console Copy.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="scfs-github-footer-repository">' . esc_html__('Repository label', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input class="regular-text" id="scfs-github-footer-repository" name="console_footer[footer_repository]" value="' . esc_attr($footer['footer_repository'] ?? 'repository') . '"><p class="description">' . esc_html__('Displayed with the terminal prefix, for example ./repository.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-github-footer-repository-url">' . esc_html__('Repository destination', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input class="large-text code" id="scfs-github-footer-repository-url" name="console_footer[footer_repository_url]" value="' . esc_attr($footer['footer_repository_url'] ?? '') . '" placeholder="Automatic from the mapped canonical repository"><p class="description">' . esc_html__('Leave blank to use the Product Support and Feedback GitHub repository automatically.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-github-footer-support">' . esc_html__('Support label', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input class="regular-text" id="scfs-github-footer-support" name="console_footer[footer_support]" value="' . esc_attr($footer['footer_support'] ?? 'support') . '"></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-github-footer-support-url">' . esc_html__('Support destination', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input class="large-text code" id="scfs-github-footer-support-url" name="console_footer[footer_support_url]" value="' . esc_attr($footer['footer_support_url'] ?? '/support/') . '" placeholder="/support/"><p class="description">' . esc_html__('Use a site-relative path or a complete URL.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save GitHub Connection and Console Links', 'sustainable-catalyst-feature-suggestions'));
        echo '</form>';

        $repositories = $this->mapped_repositories();
        echo '<h2>' . esc_html__('Connection check', 'sustainable-catalyst-feature-suggestions') . '</h2><p>' . esc_html__('Test the current credential against one mapped repository or all mapped repositories. No repository content is changed.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input type="hidden" name="action" value="scfs_test_github_connection">';
        wp_nonce_field(self::TEST_NONCE_ACTION);
        echo '<label for="scfs-github-test-product" class="screen-reader-text">' . esc_html__('Repository to test', 'sustainable-catalyst-feature-suggestions') . '</label><select id="scfs-github-test-product" name="product_id"><option value="">' . esc_html__('Choose a mapped repository…', 'sustainable-catalyst-feature-suggestions') . '</option>';
        foreach ($repositories as $product_id => $repository) {
            echo '<option value="' . esc_attr($product_id) . '">' . esc_html($repository['name']) . '</option>';
        }
        if (count($repositories) > 1) {
            echo '<option value="__all__">' . esc_html__('All mapped repositories (may take longer)', 'sustainable-catalyst-feature-suggestions') . '</option>';
        }
        echo '</select><button class="button button-primary" type="submit">' . esc_html__('Test repository access', 'sustainable-catalyst-feature-suggestions') . '</button> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_sync_all_canonical_github'), 'scfs_sync_all_canonical_github')) . '">' . esc_html__('Sync all repositories now', 'sustainable-catalyst-feature-suggestions') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . SCFS_Installed_Plugin_Discovery::ADMIN_SLUG)) . '">' . esc_html__('Open product connections', 'sustainable-catalyst-feature-suggestions') . '</a></form>';

        echo '<h2>' . esc_html__('Mapped repositories', 'sustainable-catalyst-feature-suggestions') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Product', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Repository', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Sync status', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Action', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        if (!$repositories) {
            echo '<tr><td colspan="4">' . esc_html__('No GitHub repositories are mapped yet.', 'sustainable-catalyst-feature-suggestions') . '</td></tr>';
        }
        foreach ($repositories as $product_id => $repository) {
            $message = $repository['message'];
            if ($message === '' && $repository['state'] === 'current' && $repository['latest_version'] === '') {
                $message = __('Repository connected. No GitHub Release is published yet.', 'sustainable-catalyst-feature-suggestions');
            } elseif ($message === '' && $repository['state'] === 'current') {
                $message = sprintf(__('Current release: %s', 'sustainable-catalyst-feature-suggestions'), $repository['latest_version']);
            } elseif ($message === '') {
                $message = __('Not synchronized yet.', 'sustainable-catalyst-feature-suggestions');
            }
            echo '<tr><td><strong>' . esc_html($repository['name']) . '</strong></td><td><a href="' . esc_url($repository['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($repository['url']) . '</a></td><td><code>' . esc_html($repository['state']) . '</code><br>' . esc_html($message);
            if ($repository['last_synced_at'] !== '') {
                echo '<br><small>' . esc_html(sprintf(__('Last attempt: %s', 'sustainable-catalyst-feature-suggestions'), $repository['last_synced_at'])) . '</small>';
            }
            $sync_url = wp_nonce_url(admin_url('admin-post.php?action=scfs_sync_canonical_github&product_id=' . rawurlencode($product_id)), 'scfs_sync_canonical_github');
            echo '</td><td><a class="button button-small" href="' . esc_url($sync_url) . '">' . esc_html__('Sync now', 'sustainable-catalyst-feature-suggestions') . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function save_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION, 'scfs_github_connection_nonce');
        $settings = $this->settings();
        $this->invalidate_installation_token();

        if (!empty($_POST['remove_github_app'])) {
            unset($settings['github_app_id'], $settings['github_app_installation_id'], $settings['github_app_private_key_encrypted'], $settings['github_app_private_key_updated_at']);
        }
        $github_app_id = isset($_POST['github_app_id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) wp_unslash($_POST['github_app_id'])) : '';
        $github_app_installation_id = isset($_POST['github_app_installation_id']) ? absint(wp_unslash($_POST['github_app_installation_id'])) : 0;
        if ($github_app_id !== '') {
            $settings['github_app_id'] = $github_app_id;
        }
        if ($github_app_installation_id > 0) {
            $settings['github_app_installation_id'] = $github_app_installation_id;
        }
        $github_app_private_key = isset($_POST['github_app_private_key']) ? $this->normalize_private_key(wp_unslash($_POST['github_app_private_key'])) : '';
        if ($github_app_private_key !== '') {
            if (strpos($github_app_private_key, '-----BEGIN ') !== 0 || strpos($github_app_private_key, 'PRIVATE KEY-----') === false || !function_exists('openssl_pkey_get_private') || openssl_pkey_get_private($github_app_private_key) === false) {
                wp_die(esc_html__('The GitHub App private key is not a valid PEM private key.', 'sustainable-catalyst-feature-suggestions'));
            }
            $encrypted = $this->encrypt_secret($github_app_private_key);
            if (is_wp_error($encrypted)) {
                wp_die(esc_html($encrypted->get_error_message()));
            }
            $settings['github_app_private_key_encrypted'] = $encrypted;
            $settings['github_app_private_key_updated_at'] = gmdate('c');
        }

        if (!empty($_POST['remove_github_token'])) {
            unset($settings['token_encrypted'], $settings['token_updated_at']);
        }
        $token = isset($_POST['github_token']) ? trim((string) wp_unslash($_POST['github_token'])) : '';
        if ($token !== '') {
            if (strlen($token) < 20 || preg_match('/\s/', $token)) {
                wp_die(esc_html__('The GitHub token format is invalid.', 'sustainable-catalyst-feature-suggestions'));
            }
            $encrypted = $this->encrypt_secret($token);
            if (is_wp_error($encrypted)) {
                wp_die(esc_html($encrypted->get_error_message()));
            }
            $settings['token_encrypted'] = $encrypted;
            $settings['token_updated_at'] = gmdate('c');
        }

        if (!empty($_POST['remove_webhook_secret'])) {
            unset($settings['webhook_secret_encrypted'], $settings['webhook_secret_updated_at']);
        }
        $webhook_secret = isset($_POST['webhook_secret']) ? trim((string) wp_unslash($_POST['webhook_secret'])) : '';
        if ($webhook_secret !== '') {
            if (strlen($webhook_secret) < 16) {
                wp_die(esc_html__('Use a webhook secret of at least 16 characters.', 'sustainable-catalyst-feature-suggestions'));
            }
            $encrypted = $this->encrypt_secret($webhook_secret);
            if (is_wp_error($encrypted)) {
                wp_die(esc_html($encrypted->get_error_message()));
            }
            $settings['webhook_secret_encrypted'] = $encrypted;
            $settings['webhook_secret_updated_at'] = gmdate('c');
        }
        update_option(self::OPTION_KEY, $settings, false);
        if (class_exists('SCFS_Release_Console_Copy') && isset($_POST['console_footer']) && is_array($_POST['console_footer'])) {
            SCFS_Release_Console_Copy::instance()->update_footer_settings($_POST['console_footer']);
        }
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&updated=1'));
        exit;
    }

    public function test_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::TEST_NONCE_ACTION);
        $repositories = $this->mapped_repositories();
        $product_id = sanitize_key(wp_unslash($_GET['product_id'] ?? ''));
        if ($product_id === '') {
            $repositories = array();
        } elseif ($product_id !== '__all__') {
            $repositories = isset($repositories[$product_id]) ? array($product_id => $repositories[$product_id]) : array();
        }
        $results = array();
        $all_ok = true;
        if (!$repositories) {
            $all_ok = false;
            $message = __('No mapped repositories are available to test.', 'sustainable-catalyst-feature-suggestions');
        } else {
            $message = $this->authentication_source() === 'none'
                ? __('Repositories were checked without authentication. Public repositories can pass; private repositories require the GitHub App bridge or a fallback token.', 'sustainable-catalyst-feature-suggestions')
                : __('Every mapped repository was checked with the same server-side credential used by WordPress synchronization.', 'sustainable-catalyst-feature-suggestions');
            foreach ($repositories as $repository) {
                $diagnostic = SCFS_Canonical_Product_GitHub_Sync::instance()->diagnose_repository($repository['url']);
                if (is_wp_error($diagnostic)) {
                    $all_ok = false;
                    $results[$repository['name']] = array('ok' => false, 'message' => $diagnostic->get_error_message());
                } else {
                    $results[$repository['name']] = array('ok' => true, 'message' => $diagnostic['message']);
                }
            }
        }
        set_transient($this->test_transient_key(), array('ok' => $all_ok, 'message' => $message, 'results' => $results), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG));
        exit;
    }

    public function sync_all_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('scfs_sync_all_canonical_github');
        SCFS_Canonical_Product_GitHub_Sync::instance()->sync_all('admin_settings');
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&sync_complete=1'));
        exit;
    }
}
