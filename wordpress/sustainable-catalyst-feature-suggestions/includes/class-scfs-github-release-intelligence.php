<?php
/**
 * GitHub Release Intelligence.
 *
 * Adds governed GitHub release, repository, rate-limit, synchronization, and
 * webhook-delivery intelligence around the canonical product registry.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_GitHub_Release_Intelligence {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-github-release-intelligence/1.0';
    const OPTION_KEY = 'scfs_github_release_intelligence';
    const SETTINGS_OPTION = 'scfs_github_release_intelligence_settings';
    const SYNC_HISTORY_OPTION = 'scfs_github_release_intelligence_sync_history';
    const WEBHOOK_HISTORY_OPTION = 'scfs_github_release_intelligence_webhook_history';
    const DELIVERY_OPTION = 'scfs_github_release_intelligence_deliveries';
    const ADMIN_SLUG = 'scfs-github-release-intelligence';
    const NONCE_ACTION = 'scfs_github_release_intelligence';
    const NOTICE_PREFIX = 'scfs_github_release_intelligence_notice_';
    const MAX_SYNC_HISTORY = 300;
    const MAX_WEBHOOK_HISTORY = 300;
    const MAX_DELIVERIES = 500;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_page'), 24);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_scfs_save_github_release_intelligence', array($this, 'save_settings_action'));
        add_action('admin_post_scfs_retry_failed_github_syncs', array($this, 'retry_failed_action'));
        add_action('admin_post_scfs_test_github_webhook', array($this, 'manual_webhook_test_action'));
        add_action('admin_post_scfs_clear_github_intelligence_history', array($this, 'clear_history_action'));
        add_action('scfs_canonical_product_github_sync_failed', array($this, 'capture_failure'), 10, 2);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('scfs products github-intelligence', array($this, 'cli_report'));
        }
    }

    public static function activate() {
        if (!is_array(get_option(self::OPTION_KEY))) {
            add_option(self::OPTION_KEY, array(), '', false);
        }
        if (!is_array(get_option(self::SETTINGS_OPTION))) {
            add_option(self::SETTINGS_OPTION, self::default_settings(), '', false);
        }
        if (!is_array(get_option(self::SYNC_HISTORY_OPTION))) {
            add_option(self::SYNC_HISTORY_OPTION, array(), '', false);
        }
        if (!is_array(get_option(self::WEBHOOK_HISTORY_OPTION))) {
            add_option(self::WEBHOOK_HISTORY_OPTION, array(), '', false);
        }
        if (!is_array(get_option(self::DELIVERY_OPTION))) {
            add_option(self::DELIVERY_OPTION, array(), '', false);
        }
    }

    public static function default_settings() {
        return array(
            'polling_schedule' => 'hourly',
            'include_prereleases' => '',
            'preview_product_ids' => array(),
            'history_limit' => 250,
            'webhook_history_limit' => 250,
            'delivery_retention_days' => 30,
        );
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'version_authority_order' => array('github_release', 'github_prerelease_when_enabled', 'github_tag', 'wordpress_plugin', 'manual'),
            'repository_metadata' => true,
            'release_author_and_date' => true,
            'release_asset_inventory' => true,
            'draft_release_visibility' => true,
            'prerelease_governance' => true,
            'repository_visibility' => true,
            'archived_repository_detection' => true,
            'repository_rename_transfer_detection' => true,
            'rate_limit_visibility' => true,
            'token_scope_diagnostics' => true,
            'organization_approval_diagnostics' => true,
            'per_repository_sync_history' => true,
            'retry_failed_only' => true,
            'configurable_polling_schedule' => true,
            'webhook_delivery_history' => true,
            'duplicate_webhook_protection' => true,
            'manual_webhook_test' => true,
            'automatic_publication' => false,
        );
    }

    private function settings() {
        $settings = get_option(self::SETTINGS_OPTION, array());
        return wp_parse_args(is_array($settings) ? $settings : array(), self::default_settings());
    }

    public function schedule_recurrence() {
        $schedule = sanitize_key($this->settings()['polling_schedule'] ?? 'hourly');
        return in_array($schedule, array('hourly', 'twicedaily', 'daily', 'disabled'), true) ? $schedule : 'hourly';
    }

    public function allow_prerelease($product_id = '') {
        $settings = $this->settings();
        if (!empty($settings['include_prereleases'])) {
            return true;
        }
        $product_id = sanitize_key($product_id);
        return $product_id !== '' && in_array($product_id, array_map('sanitize_key', (array) ($settings['preview_product_ids'] ?? array())), true);
    }

    public function intelligence() {
        $records = get_option(self::OPTION_KEY, array());
        return is_array($records) ? $records : array();
    }

    public function sync_history() {
        $history = get_option(self::SYNC_HISTORY_OPTION, array());
        return is_array($history) ? $history : array();
    }

    public function webhook_history() {
        $history = get_option(self::WEBHOOK_HISTORY_OPTION, array());
        return is_array($history) ? $history : array();
    }

    private function append_history($option, $entry, $maximum) {
        $history = get_option($option, array());
        $history = is_array($history) ? $history : array();
        $history[] = $entry;
        $history = array_slice($history, -absint($maximum));
        update_option($option, $history, false);
    }

    private function normalize_asset($asset) {
        return array(
            'name' => sanitize_file_name($asset['name'] ?? ''),
            'label' => sanitize_text_field($asset['label'] ?? ''),
            'content_type' => sanitize_text_field($asset['content_type'] ?? ''),
            'size' => absint($asset['size'] ?? 0),
            'download_count' => absint($asset['download_count'] ?? 0),
            'updated_at' => sanitize_text_field($asset['updated_at'] ?? ''),
            'download_url' => esc_url_raw($asset['browser_download_url'] ?? ''),
        );
    }

    private function compact_response_meta($response_meta) {
        $output = array();
        foreach ((array) $response_meta as $endpoint => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $output[sanitize_key($endpoint)] = array(
                'status' => absint($meta['status'] ?? 0),
                'authentication_source' => sanitize_key($meta['authentication_source'] ?? ''),
                'rate_limit_remaining' => sanitize_text_field($meta['rate_limit_remaining'] ?? ''),
                'rate_limit_limit' => sanitize_text_field($meta['rate_limit_limit'] ?? ''),
                'rate_limit_reset' => sanitize_text_field($meta['rate_limit_reset'] ?? ''),
                'rate_limit_resource' => sanitize_text_field($meta['rate_limit_resource'] ?? ''),
                'oauth_scopes' => sanitize_text_field($meta['oauth_scopes'] ?? ''),
                'accepted_oauth_scopes' => sanitize_text_field($meta['accepted_oauth_scopes'] ?? ''),
                'github_sso' => sanitize_text_field($meta['github_sso'] ?? ''),
                'endpoint_url' => esc_url_raw($meta['endpoint_url'] ?? ''),
            );
        }
        return $output;
    }

    private function rate_limit_summary($response_meta) {
        $summary = array(
            'remaining' => '',
            'limit' => '',
            'reset' => '',
            'resource' => '',
            'oauth_scopes' => '',
            'accepted_oauth_scopes' => '',
            'github_sso' => '',
        );
        foreach ((array) $response_meta as $meta) {
            if (!is_array($meta)) {
                continue;
            }
            foreach (array_keys($summary) as $field) {
                $source = $field === 'remaining' ? 'rate_limit_remaining' : ($field === 'limit' ? 'rate_limit_limit' : ($field === 'reset' ? 'rate_limit_reset' : ($field === 'resource' ? 'rate_limit_resource' : $field)));
                if (($meta[$source] ?? '') !== '') {
                    $summary[$field] = sanitize_text_field($meta[$source]);
                }
            }
        }
        return $summary;
    }

    public function capture_sync($product_id, $repository, $metadata, $release, $tag, $commit, $reason, $response_meta = array()) {
        $product_id = sanitize_key($product_id);
        if ($product_id === '') {
            return;
        }
        $records = $this->intelligence();
        $previous = is_array($records[$product_id] ?? null) ? $records[$product_id] : array();
        $configured_url = esc_url_raw($repository['configured_url'] ?? ($repository['canonical_url'] ?? ''));
        $actual_url = esc_url_raw($metadata['html_url'] ?? ($repository['canonical_url'] ?? ''));
        $assets = array();
        foreach ((array) ($release['assets'] ?? array()) as $asset) {
            if (is_array($asset)) {
                $assets[] = $this->normalize_asset($asset);
            }
        }
        $release_author = sanitize_text_field($release['author']['login'] ?? '');
        $commit_author = sanitize_text_field($commit['commit']['author']['name'] ?? ($commit['author']['login'] ?? ''));
        $commit_message = wp_strip_all_tags((string) ($commit['commit']['message'] ?? ''));
        $commit_message = trim(preg_replace('/\s+/', ' ', $commit_message));
        $rate_limit = $this->rate_limit_summary($response_meta);
        $identity_changed = $configured_url !== '' && $actual_url !== '' && strtolower($configured_url) !== strtolower($actual_url);
        $release_state = !empty($release)
            ? (!empty($release['prerelease']) ? 'prerelease' : 'stable_release')
            : (!empty($tag['version']) ? 'semantic_tag' : 'no_release');
        $record = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'product_id' => $product_id,
            'repository_url' => $actual_url ?: $configured_url,
            'configured_repository_url' => $configured_url,
            'previous_repository_url' => $identity_changed ? $configured_url : sanitize_text_field($previous['previous_repository_url'] ?? ''),
            'repository_identity_changed' => $identity_changed,
            'repository_full_name' => sanitize_text_field($metadata['full_name'] ?? ''),
            'repository_owner' => sanitize_text_field($metadata['owner']['login'] ?? ''),
            'repository_name' => sanitize_text_field($metadata['name'] ?? ($repository['repository'] ?? '')),
            'repository_visibility' => sanitize_key($metadata['visibility'] ?? (!empty($metadata['private']) ? 'private' : 'public')),
            'repository_private' => !empty($metadata['private']),
            'repository_archived' => !empty($metadata['archived']),
            'repository_disabled' => !empty($metadata['disabled']),
            'repository_fork' => !empty($metadata['fork']),
            'default_branch' => sanitize_text_field($metadata['default_branch'] ?? 'main'),
            'repository_created_at' => sanitize_text_field($metadata['created_at'] ?? ''),
            'repository_updated_at' => sanitize_text_field($metadata['updated_at'] ?? ''),
            'repository_pushed_at' => sanitize_text_field($metadata['pushed_at'] ?? ''),
            'release_state' => $release_state,
            'release_id' => absint($release['id'] ?? 0),
            'release_name' => sanitize_text_field($release['name'] ?? ''),
            'release_tag' => sanitize_text_field($release['tag_name'] ?? ($tag['name'] ?? '')),
            'release_url' => esc_url_raw($release['html_url'] ?? ($tag['url'] ?? '')),
            'release_author' => $release_author,
            'release_created_at' => sanitize_text_field($release['created_at'] ?? ''),
            'release_published_at' => sanitize_text_field($release['published_at'] ?? ''),
            'release_target_commitish' => sanitize_text_field($release['target_commitish'] ?? ''),
            'release_prerelease' => !empty($release['prerelease']),
            'release_draft' => !empty($release['draft']),
            'release_asset_count' => count($assets),
            'release_assets' => $assets,
            'semantic_tag_name' => sanitize_text_field($tag['name'] ?? ''),
            'semantic_tag_version' => sanitize_text_field($tag['version'] ?? ''),
            'commit_sha' => sanitize_text_field($commit['sha'] ?? ($tag['commit_sha'] ?? '')),
            'commit_author' => $commit_author,
            'commit_date' => sanitize_text_field($commit['commit']['author']['date'] ?? ''),
            'commit_message' => function_exists('mb_substr') ? mb_substr($commit_message, 0, 240) : substr($commit_message, 0, 240),
            'rate_limit_remaining' => $rate_limit['remaining'],
            'rate_limit_limit' => $rate_limit['limit'],
            'rate_limit_reset' => $rate_limit['reset'],
            'rate_limit_resource' => $rate_limit['resource'],
            'oauth_scopes' => $rate_limit['oauth_scopes'],
            'accepted_oauth_scopes' => $rate_limit['accepted_oauth_scopes'],
            'organization_approval_hint' => $rate_limit['github_sso'],
            'response_metadata' => $this->compact_response_meta($response_meta),
            'sync_state' => 'current',
            'sync_reason' => sanitize_key($reason),
            'last_synced_at' => gmdate('c'),
        );
        $records[$product_id] = $record;
        update_option(self::OPTION_KEY, $records, false);
        $this->append_history(
            self::SYNC_HISTORY_OPTION,
            array(
                'at' => gmdate('c'),
                'product_id' => $product_id,
                'repository_url' => $record['repository_url'],
                'state' => 'current',
                'reason' => sanitize_key($reason),
                'release_state' => $release_state,
                'version' => sanitize_text_field($release['tag_name'] ?? ($tag['version'] ?? '')),
                'message' => $identity_changed ? __('Repository identity changed and the canonical URL was refreshed.', 'sustainable-catalyst-feature-suggestions') : '',
            ),
            absint($this->settings()['history_limit'] ?? self::MAX_SYNC_HISTORY)
        );
    }

    public function capture_failure($product_id, $details) {
        $product_id = sanitize_key($product_id);
        $records = $this->intelligence();
        $record = is_array($records[$product_id] ?? null) ? $records[$product_id] : array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'product_id' => $product_id,
        );
        $record['sync_state'] = 'error';
        $record['last_synced_at'] = gmdate('c');
        $record['error'] = array(
            'code' => sanitize_key($details['code'] ?? ''),
            'message' => sanitize_text_field($details['message'] ?? ''),
            'status' => absint($details['status'] ?? 0),
            'endpoint' => sanitize_key($details['endpoint'] ?? ''),
            'endpoint_url' => esc_url_raw($details['endpoint_url'] ?? ''),
            'connection_state' => sanitize_key($details['connection_state'] ?? 'request_error'),
            'rate_limit_remaining' => sanitize_text_field($details['rate_limit_remaining'] ?? ''),
            'rate_limit_reset' => sanitize_text_field($details['rate_limit_reset'] ?? ''),
        );
        $records[$product_id] = $record;
        update_option(self::OPTION_KEY, $records, false);
        $this->append_history(
            self::SYNC_HISTORY_OPTION,
            array(
                'at' => gmdate('c'),
                'product_id' => $product_id,
                'repository_url' => esc_url_raw($record['repository_url'] ?? ''),
                'state' => 'error',
                'reason' => 'sync_failure',
                'release_state' => sanitize_key($record['release_state'] ?? ''),
                'version' => '',
                'message' => sanitize_text_field($details['message'] ?? ''),
            ),
            absint($this->settings()['history_limit'] ?? self::MAX_SYNC_HISTORY)
        );
    }

    private function prune_deliveries($deliveries) {
        $retention = max(1, absint($this->settings()['delivery_retention_days'] ?? 30));
        $cutoff = time() - ($retention * DAY_IN_SECONDS);
        $kept = array();
        foreach ((array) $deliveries as $delivery_id => $timestamp) {
            if ((int) $timestamp >= $cutoff) {
                $kept[sanitize_text_field($delivery_id)] = (int) $timestamp;
            }
        }
        if (count($kept) > self::MAX_DELIVERIES) {
            asort($kept);
            $kept = array_slice($kept, -self::MAX_DELIVERIES, null, true);
        }
        return $kept;
    }

    public function webhook_delivery_seen($delivery_id) {
        $delivery_id = sanitize_text_field($delivery_id);
        if ($delivery_id === '') {
            return false;
        }
        $deliveries = $this->prune_deliveries(get_option(self::DELIVERY_OPTION, array()));
        update_option(self::DELIVERY_OPTION, $deliveries, false);
        return isset($deliveries[$delivery_id]);
    }

    public function record_webhook_delivery($delivery_id, $event, $repository_url, $product_id, $status, $message = '', $manual = false, $mark_processed = true) {
        $delivery_id = sanitize_text_field($delivery_id);
        if ($delivery_id === '') {
            $delivery_id = 'generated-' . hash('sha256', gmdate('c') . '|' . $event . '|' . $repository_url . '|' . wp_rand());
        }
        if ($mark_processed) {
            $deliveries = $this->prune_deliveries(get_option(self::DELIVERY_OPTION, array()));
            $deliveries[$delivery_id] = time();
            update_option(self::DELIVERY_OPTION, $deliveries, false);
        }
        $this->append_history(
            self::WEBHOOK_HISTORY_OPTION,
            array(
                'at' => gmdate('c'),
                'delivery_id' => $delivery_id,
                'event' => sanitize_key($event),
                'repository_url' => esc_url_raw($repository_url),
                'product_id' => sanitize_key($product_id),
                'status' => sanitize_key($status),
                'message' => sanitize_text_field($message),
                'manual_test' => (bool) $manual,
            ),
            absint($this->settings()['webhook_history_limit'] ?? self::MAX_WEBHOOK_HISTORY)
        );
        return $delivery_id;
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('GitHub Release Intelligence', 'sustainable-catalyst-feature-suggestions'),
            __('GitHub Release Intelligence', 'sustainable-catalyst-feature-suggestions'),
            'manage_options',
            self::ADMIN_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_assets($hook_suffix) {
        if (strpos((string) $hook_suffix, self::ADMIN_SLUG) === false) {
            return;
        }
        $plugin_file = dirname(__DIR__) . '/sustainable-catalyst-feature-suggestions.php';
        $base = plugin_dir_url($plugin_file) . 'assets/';
        wp_enqueue_style('scfs-github-release-intelligence-v781', $base . 'github-release-intelligence-v7.8.1.css', array(), self::VERSION);
        wp_enqueue_script('scfs-github-release-intelligence-v781', $base . 'github-release-intelligence-v7.8.1.js', array(), self::VERSION, true);
    }

    private function notice_key() {
        return self::NOTICE_PREFIX . (function_exists('get_current_user_id') ? get_current_user_id() : 0);
    }

    private function set_notice($type, $message) {
        set_transient($this->notice_key(), array('type' => sanitize_key($type), 'message' => sanitize_text_field($message)), 5 * MINUTE_IN_SECONDS);
    }

    private function redirect_url() {
        return admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG);
    }

    public function save_settings_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);
        $schedule = sanitize_key(wp_unslash($_POST['polling_schedule'] ?? 'hourly'));
        if (!in_array($schedule, array('hourly', 'twicedaily', 'daily', 'disabled'), true)) {
            $schedule = 'hourly';
        }
        $preview_ids = array();
        foreach ((array) ($_POST['preview_product_ids'] ?? array()) as $product_id) {
            $product_id = sanitize_key(wp_unslash($product_id));
            if ($product_id !== '') {
                $preview_ids[] = $product_id;
            }
        }
        $settings = array(
            'polling_schedule' => $schedule,
            'include_prereleases' => !empty($_POST['include_prereleases']) ? '1' : '',
            'preview_product_ids' => array_values(array_unique($preview_ids)),
            'history_limit' => min(self::MAX_SYNC_HISTORY, max(25, absint($_POST['history_limit'] ?? 250))),
            'webhook_history_limit' => min(self::MAX_WEBHOOK_HISTORY, max(25, absint($_POST['webhook_history_limit'] ?? 250))),
            'delivery_retention_days' => min(365, max(1, absint($_POST['delivery_retention_days'] ?? 30))),
        );
        update_option(self::SETTINGS_OPTION, $settings, false);
        if (class_exists('SCFS_Canonical_Product_GitHub_Sync')) {
            SCFS_Canonical_Product_GitHub_Sync::instance()->reschedule($schedule);
        }
        $this->set_notice('success', __('GitHub release-intelligence settings were saved and the polling schedule was refreshed.', 'sustainable-catalyst-feature-suggestions'));
        wp_safe_redirect($this->redirect_url());
        exit;
    }

    public function retry_failed_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);
        $count = 0;
        $success = 0;
        foreach (SCFS_Canonical_Product_Registry::instance()->registry() as $product_id => $record) {
            if (($record['github_sync_state'] ?? '') !== 'error' || empty($record['github_repository_url']) || empty($record['github_sync_enabled'])) {
                continue;
            }
            $count++;
            $result = SCFS_Canonical_Product_GitHub_Sync::instance()->sync_product($product_id, 'retry_failed_only');
            if (!is_wp_error($result)) {
                $success++;
            }
        }
        $this->set_notice(
            $count === $success ? 'success' : 'warning',
            sprintf(__('Retried %1$d failed repositories; %2$d synchronized successfully.', 'sustainable-catalyst-feature-suggestions'), $count, $success)
        );
        wp_safe_redirect($this->redirect_url());
        exit;
    }

    public function manual_webhook_test_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);
        $product_id = sanitize_key(wp_unslash($_POST['product_id'] ?? ''));
        $record = SCFS_Canonical_Product_Registry::instance()->get_product($product_id);
        if (!$record || empty($record['github_repository_url'])) {
            $this->set_notice('error', __('Choose a canonical product with a mapped GitHub repository.', 'sustainable-catalyst-feature-suggestions'));
            wp_safe_redirect($this->redirect_url());
            exit;
        }
        $result = SCFS_Canonical_Product_GitHub_Sync::instance()->sync_product($product_id, 'manual_webhook_test');
        $status = is_wp_error($result) ? 'failed' : 'accepted';
        $message = is_wp_error($result) ? $result->get_error_message() : __('Synthetic webhook test synchronized the mapped repository.', 'sustainable-catalyst-feature-suggestions');
        $this->record_webhook_delivery('manual-' . gmdate('YmdHis') . '-' . $product_id, 'ping', $record['github_repository_url'], $product_id, $status, $message, true);
        $this->set_notice(is_wp_error($result) ? 'error' : 'success', $message);
        wp_safe_redirect($this->redirect_url());
        exit;
    }

    public function clear_history_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);
        update_option(self::SYNC_HISTORY_OPTION, array(), false);
        update_option(self::WEBHOOK_HISTORY_OPTION, array(), false);
        update_option(self::DELIVERY_OPTION, array(), false);
        $this->set_notice('success', __('GitHub synchronization and webhook-delivery history was cleared.', 'sustainable-catalyst-feature-suggestions'));
        wp_safe_redirect($this->redirect_url());
        exit;
    }

    private function render_notice() {
        $notice = get_transient($this->notice_key());
        if ($notice === false) {
            return;
        }
        delete_transient($this->notice_key());
        $type = in_array($notice['type'] ?? '', array('success', 'warning', 'error', 'info'), true) ? $notice['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message'] ?? '') . '</p></div>';
    }

    private function time_label($value) {
        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if (!$timestamp) {
            return __('Never', 'sustainable-catalyst-feature-suggestions');
        }
        return function_exists('wp_date') ? wp_date('Y-m-d H:i T', $timestamp) : gmdate('Y-m-d H:i', $timestamp);
    }

    private function token_diagnostics($records) {
        $source = class_exists('SCFS_GitHub_Connection_Settings') ? SCFS_GitHub_Connection_Settings::instance()->token_source() : 'none';
        $latest = array();
        foreach ((array) $records as $record) {
            if (!is_array($record)) {
                continue;
            }
            if (($record['rate_limit_remaining'] ?? '') !== '') {
                $latest = $record;
            }
        }
        return array(
            'source' => sanitize_key($source),
            'remaining' => sanitize_text_field($latest['rate_limit_remaining'] ?? ''),
            'limit' => sanitize_text_field($latest['rate_limit_limit'] ?? ''),
            'reset' => sanitize_text_field($latest['rate_limit_reset'] ?? ''),
            'resource' => sanitize_text_field($latest['rate_limit_resource'] ?? ''),
            'oauth_scopes' => sanitize_text_field($latest['oauth_scopes'] ?? ''),
            'accepted_oauth_scopes' => sanitize_text_field($latest['accepted_oauth_scopes'] ?? ''),
            'organization_approval_hint' => sanitize_text_field($latest['organization_approval_hint'] ?? ''),
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage GitHub release intelligence.', 'sustainable-catalyst-feature-suggestions'));
        }
        $registry = SCFS_Canonical_Product_Registry::instance()->registry();
        $records = $this->intelligence();
        $settings = $this->settings();
        $sync_history = array_reverse($this->sync_history());
        $webhook_history = array_reverse($this->webhook_history());
        $token = $this->token_diagnostics($records);
        $next_sync = class_exists('SCFS_Canonical_Product_GitHub_Sync') ? SCFS_Canonical_Product_GitHub_Sync::instance()->next_scheduled_sync() : false;

        echo '<div class="wrap scfs-github-release-intelligence">';
        echo '<h1>' . esc_html__('GitHub Release Intelligence', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p>' . esc_html__('Govern repository identity, releases, semantic tags, assets, API limits, synchronization history, and signed webhook delivery evidence for every canonical product.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        $this->render_notice();

        echo '<div class="scfs-gri-summary">';
        echo '<div><strong>' . esc_html__('Token source', 'sustainable-catalyst-feature-suggestions') . '</strong><span>' . esc_html(str_replace('_', ' ', $token['source'])) . '</span></div>';
        echo '<div><strong>' . esc_html__('API allowance', 'sustainable-catalyst-feature-suggestions') . '</strong><span>' . esc_html(($token['remaining'] !== '' ? $token['remaining'] : '—') . ($token['limit'] !== '' ? ' / ' . $token['limit'] : '')) . '</span></div>';
        echo '<div><strong>' . esc_html__('Polling', 'sustainable-catalyst-feature-suggestions') . '</strong><span>' . esc_html($settings['polling_schedule']) . '</span></div>';
        echo '<div><strong>' . esc_html__('Next sync', 'sustainable-catalyst-feature-suggestions') . '</strong><span>' . esc_html($next_sync ? $this->time_label($next_sync) : __('Disabled or unscheduled', 'sustainable-catalyst-feature-suggestions')) . '</span></div>';
        echo '</div>';
        if ($token['organization_approval_hint'] !== '') {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Organization authorization:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html($token['organization_approval_hint']) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-gri-settings">';
        echo '<input type="hidden" name="action" value="scfs_save_github_release_intelligence">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<h2>' . esc_html__('Synchronization policy', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="scfs-gri-polling">' . esc_html__('Polling schedule', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><select id="scfs-gri-polling" name="polling_schedule">';
        foreach (array('hourly' => __('Hourly', 'sustainable-catalyst-feature-suggestions'), 'twicedaily' => __('Twice daily', 'sustainable-catalyst-feature-suggestions'), 'daily' => __('Daily', 'sustainable-catalyst-feature-suggestions'), 'disabled' => __('Disabled', 'sustainable-catalyst-feature-suggestions')) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['polling_schedule'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>' . esc_html__('Prereleases', 'sustainable-catalyst-feature-suggestions') . '</th><td><label><input type="checkbox" name="include_prereleases" value="1" ' . checked(!empty($settings['include_prereleases']), true, false) . '> ' . esc_html__('Allow published prereleases to become the governed console version for every product', 'sustainable-catalyst-feature-suggestions') . '</label><p class="description">' . esc_html__('Draft releases are always excluded. Leave this off to enable previews only for selected products.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Preview-enabled products', 'sustainable-catalyst-feature-suggestions') . '</th><td><div class="scfs-gri-product-checks">';
        foreach ($registry as $product_id => $product) {
            if (empty($product['github_repository_url'])) {
                continue;
            }
            echo '<label><input type="checkbox" name="preview_product_ids[]" value="' . esc_attr($product_id) . '" ' . checked(in_array($product_id, (array) $settings['preview_product_ids'], true), true, false) . '> ' . esc_html($product['name'] ?? $product_id) . '</label>';
        }
        echo '</div></td></tr>';
        echo '<tr><th>' . esc_html__('History retention', 'sustainable-catalyst-feature-suggestions') . '</th><td><label>' . esc_html__('Sync entries', 'sustainable-catalyst-feature-suggestions') . ' <input type="number" min="25" max="300" name="history_limit" value="' . esc_attr($settings['history_limit']) . '"></label> <label>' . esc_html__('Webhook entries', 'sustainable-catalyst-feature-suggestions') . ' <input type="number" min="25" max="300" name="webhook_history_limit" value="' . esc_attr($settings['webhook_history_limit']) . '"></label> <label>' . esc_html__('Delivery IDs (days)', 'sustainable-catalyst-feature-suggestions') . ' <input type="number" min="1" max="365" name="delivery_retention_days" value="' . esc_attr($settings['delivery_retention_days']) . '"></label></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save release-intelligence policy', 'sustainable-catalyst-feature-suggestions'));
        echo '</form>';

        echo '<div class="scfs-gri-actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_retry_failed_github_syncs">';
        wp_nonce_field(self::NONCE_ACTION);
        submit_button(__('Retry failed repositories only', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false);
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_test_github_webhook">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<label for="scfs-gri-webhook-product" class="screen-reader-text">' . esc_html__('Product for webhook test', 'sustainable-catalyst-feature-suggestions') . '</label><select id="scfs-gri-webhook-product" name="product_id"><option value="">' . esc_html__('Choose product for webhook test…', 'sustainable-catalyst-feature-suggestions') . '</option>';
        foreach ($registry as $product_id => $product) {
            if (!empty($product['github_repository_url'])) {
                echo '<option value="' . esc_attr($product_id) . '">' . esc_html($product['name'] ?? $product_id) . '</option>';
            }
        }
        echo '</select> ';
        submit_button(__('Run manual webhook test', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<h2>' . esc_html__('Repository and release intelligence', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<p><label for="scfs-gri-search" class="screen-reader-text">' . esc_html__('Search repository intelligence', 'sustainable-catalyst-feature-suggestions') . '</label><input id="scfs-gri-search" type="search" data-scfs-gri-search placeholder="' . esc_attr__('Search products or repositories…', 'sustainable-catalyst-feature-suggestions') . '"></p>';
        echo '<div class="scfs-gri-table-wrap"><table class="widefat striped scfs-gri-table"><thead><tr><th>' . esc_html__('Product', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Repository', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Release authority', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Assets', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Health', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('API', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        foreach ($registry as $product_id => $product) {
            if (empty($product['github_repository_url'])) {
                continue;
            }
            $record = is_array($records[$product_id] ?? null) ? $records[$product_id] : array();
            $health = array();
            if (!empty($record['repository_archived'])) {
                $health[] = __('Archived', 'sustainable-catalyst-feature-suggestions');
            }
            if (!empty($record['repository_disabled'])) {
                $health[] = __('Disabled', 'sustainable-catalyst-feature-suggestions');
            }
            if (!empty($record['repository_identity_changed'])) {
                $health[] = __('Renamed or transferred', 'sustainable-catalyst-feature-suggestions');
            }
            if (!$health) {
                $health[] = ($record['sync_state'] ?? '') === 'error' ? __('Sync error', 'sustainable-catalyst-feature-suggestions') : __('Connected', 'sustainable-catalyst-feature-suggestions');
            }
            $search = strtolower(($product['name'] ?? '') . ' ' . ($product['github_repository_url'] ?? '') . ' ' . ($record['repository_full_name'] ?? ''));
            echo '<tr data-scfs-gri-row data-search="' . esc_attr($search) . '">';
            echo '<td><strong>' . esc_html($product['name'] ?? $product_id) . '</strong><code>' . esc_html($product_id) . '</code></td>';
            echo '<td><a href="' . esc_url($record['repository_url'] ?? $product['github_repository_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($record['repository_full_name'] ?? preg_replace('#^https://github\.com/#', '', $product['github_repository_url'])) . '</a><small>' . esc_html(($record['repository_visibility'] ?? '') ?: __('Unknown visibility', 'sustainable-catalyst-feature-suggestions')) . ' · ' . esc_html($record['default_branch'] ?? ($product['github_default_branch'] ?? 'main')) . '</small></td>';
            echo '<td><code>' . esc_html($product['github_latest_version'] ?? '—') . '</code><small>' . esc_html(str_replace('_', ' ', $product['github_version_source'] ?? 'none')) . ($record['release_author'] ?? '' ? ' · ' . esc_html($record['release_author']) : '') . '</small><small>' . esc_html($this->time_label($record['release_published_at'] ?? '')) . '</small></td>';
            echo '<td><strong>' . esc_html((string) ($record['release_asset_count'] ?? 0)) . '</strong>';
            if (!empty($record['release_assets'])) {
                echo '<details><summary>' . esc_html__('View assets', 'sustainable-catalyst-feature-suggestions') . '</summary><ul>';
                foreach ((array) $record['release_assets'] as $asset) {
                    echo '<li><a href="' . esc_url($asset['download_url'] ?? '') . '" target="_blank" rel="noopener noreferrer">' . esc_html($asset['name'] ?? '') . '</a> <small>' . esc_html(size_format(absint($asset['size'] ?? 0))) . '</small></li>';
                }
                echo '</ul></details>';
            }
            echo '</td>';
            echo '<td>' . esc_html(implode(' · ', $health)) . '<small>' . esc_html($this->time_label($record['last_synced_at'] ?? ($product['github_last_synced_at'] ?? ''))) . '</small></td>';
            echo '<td><code>' . esc_html(($record['rate_limit_remaining'] ?? '—') . (($record['rate_limit_limit'] ?? '') !== '' ? '/' . $record['rate_limit_limit'] : '')) . '</code><small>' . esc_html($record['rate_limit_resource'] ?? '') . '</small></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        echo '<div class="scfs-gri-history-grid">';
        echo '<section><h2>' . esc_html__('Synchronization history', 'sustainable-catalyst-feature-suggestions') . '</h2><ol class="scfs-gri-history">';
        foreach (array_slice($sync_history, 0, 25) as $entry) {
            echo '<li><strong>' . esc_html($entry['product_id'] ?? '') . '</strong> <code>' . esc_html($entry['state'] ?? '') . '</code><span>' . esc_html($this->time_label($entry['at'] ?? '')) . '</span><small>' . esc_html($entry['message'] ?? ($entry['reason'] ?? '')) . '</small></li>';
        }
        if (!$sync_history) {
            echo '<li>' . esc_html__('No synchronization history has been recorded.', 'sustainable-catalyst-feature-suggestions') . '</li>';
        }
        echo '</ol></section>';
        echo '<section><h2>' . esc_html__('Webhook delivery history', 'sustainable-catalyst-feature-suggestions') . '</h2><ol class="scfs-gri-history">';
        foreach (array_slice($webhook_history, 0, 25) as $entry) {
            echo '<li><strong>' . esc_html($entry['event'] ?? '') . '</strong> <code>' . esc_html($entry['status'] ?? '') . '</code><span>' . esc_html($this->time_label($entry['at'] ?? '')) . '</span><small>' . esc_html($entry['product_id'] ?? '') . ' · ' . esc_html($entry['delivery_id'] ?? '') . '</small></li>';
        }
        if (!$webhook_history) {
            echo '<li>' . esc_html__('No webhook deliveries have been recorded.', 'sustainable-catalyst-feature-suggestions') . '</li>';
        }
        echo '</ol></section></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_clear_github_intelligence_history">';
        wp_nonce_field(self::NONCE_ACTION);
        submit_button(__('Clear synchronization and webhook history', 'sustainable-catalyst-feature-suggestions'), 'delete', 'submit', false, array('onclick' => "return confirm('" . esc_js(__('Clear GitHub intelligence history?', 'sustainable-catalyst-feature-suggestions')) . "')"));
        echo '</form></div>';
    }

    public function cli_report($args, $assoc_args) {
        $report = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'settings' => $this->settings(),
            'repositories' => $this->intelligence(),
            'sync_history_count' => count($this->sync_history()),
            'webhook_history_count' => count($this->webhook_history()),
        );
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
