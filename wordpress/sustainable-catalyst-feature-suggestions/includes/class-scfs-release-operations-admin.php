<?php
/**
 * Release Operations administration.
 *
 * Provides one operational view across canonical products, active WordPress
 * implementations, GitHub release intelligence, and the public Release Console.
 * No credentials or private plugin paths are exported publicly.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Release_Operations_Admin {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-release-operations/1.0';
    const ADMIN_SLUG = 'scfs-release-operations';
    const AUDIT_OPTION = 'scfs_release_operations_audit';
    const NOTICE_PREFIX = 'scfs_release_operations_notice_';
    const NONCE_ACTION = 'scfs_release_operations';
    const FRESH_HOURS = 2;
    const STALE_HOURS = 24;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_page'), 21);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_scfs_release_operations_bulk', array($this, 'bulk_action'));
        add_action('admin_post_scfs_release_operations_audit', array($this, 'audit_action'));
        add_action('admin_post_scfs_release_operations_export', array($this, 'export_action'));
        add_action('admin_post_scfs_release_operations_stabilize', array($this, 'stabilize_action'));
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('scfs products operations-report', array($this, 'cli_report'));
        }
    }

    public static function activate() {
        if (!is_array(get_option(self::AUDIT_OPTION))) {
            add_option(self::AUDIT_OPTION, array(), '', false);
        }
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'identity_authority' => 'scfs_canonical_product_registry',
            'plugin_state_source' => 'scfs_installed_plugin_discovery',
            'release_source' => 'scfs_canonical_product_github_sync',
            'console_source' => 'scfs_release_board',
            'all_products_operational_table' => true,
            'freshness_health' => true,
            'bulk_sync' => true,
            'bulk_error_clear' => true,
            'integrity_audit' => true,
            'nonsecret_json_export' => true,
            'wp_cli_report' => true,
            'credentials_excluded' => true,
            'legacy_shortcodes_preserved' => true,
            'exact_endpoint_diagnostics' => true,
            'stale_status_clearing' => true,
            'plugin_mapping_integrity' => true,
            'footer_link_verification' => true,
            'cache_invalidation_verification' => true,
            'one_click_stabilization' => true,
        );
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Release Operations', 'sustainable-catalyst-feature-suggestions'),
            __('Release Operations', 'sustainable-catalyst-feature-suggestions'),
            'manage_options',
            self::ADMIN_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_assets($hook_suffix) {
        if (strpos((string) $hook_suffix, self::ADMIN_SLUG) === false) {
            return;
        }
        $css = 'assets/release-operations-v7.8.1.css';
        $js = 'assets/release-operations-v7.8.1.js';
        $css_path = plugin_dir_path(dirname(__FILE__)) . $css;
        $js_path = plugin_dir_path(dirname(__FILE__)) . $js;
        wp_enqueue_style(
            'scfs-release-operations',
            plugins_url($css, dirname(__FILE__)),
            array(),
            is_file($css_path) ? (string) filemtime($css_path) : self::VERSION
        );
        wp_enqueue_script(
            'scfs-release-operations',
            plugins_url($js, dirname(__FILE__)),
            array(),
            is_file($js_path) ? (string) filemtime($js_path) : self::VERSION,
            true
        );
    }

    private function registry() {
        return class_exists('SCFS_Canonical_Product_Registry')
            ? SCFS_Canonical_Product_Registry::instance()->registry()
            : array();
    }

    public function freshness_state($timestamp, $sync_state = '') {
        $sync_state = sanitize_key((string) $sync_state);
        if ($sync_state === 'error') {
            return 'error';
        }
        $timestamp = trim((string) $timestamp);
        if ($timestamp === '') {
            return 'never';
        }
        $then = strtotime($timestamp);
        if (!$then) {
            return 'unknown';
        }
        $age = max(0, time() - $then);
        $fresh_seconds = (int) apply_filters('scfs_release_operations_fresh_seconds', self::FRESH_HOURS * HOUR_IN_SECONDS);
        $stale_seconds = (int) apply_filters('scfs_release_operations_stale_seconds', self::STALE_HOURS * HOUR_IN_SECONDS);
        if ($age <= $fresh_seconds) {
            return 'current';
        }
        if ($age <= $stale_seconds) {
            return 'aging';
        }
        return 'stale';
    }

    private function product_operational_state($record) {
        $connection_state = sanitize_key($record['github_connection_state'] ?? '');
        if (($record['github_sync_state'] ?? '') === 'error') {
            return in_array($connection_state, array('authentication_required', 'repository_unavailable', 'rate_limited', 'network_error'), true)
                ? $connection_state
                : 'error';
        }
        if (($record['status'] ?? '') === 'update_available') {
            return 'update_available';
        }
        if (empty($record['github_repository_url'])) {
            return 'unconfigured';
        }
        $freshness = $this->freshness_state($record['github_last_synced_at'] ?? '', $record['github_sync_state'] ?? '');
        if (in_array($freshness, array('stale', 'never', 'unknown'), true)) {
            return $freshness;
        }
        if ($connection_state === 'semantic_tag') {
            return 'semantic_tag';
        }
        if ($connection_state === 'connected_no_release') {
            return 'connected_no_release';
        }
        return 'current';
    }

    private function state_label($state) {
        $labels = array(
            'current' => __('Current', 'sustainable-catalyst-feature-suggestions'),
            'aging' => __('Aging', 'sustainable-catalyst-feature-suggestions'),
            'stale' => __('Stale', 'sustainable-catalyst-feature-suggestions'),
            'never' => __('Never synchronized', 'sustainable-catalyst-feature-suggestions'),
            'unknown' => __('Unknown', 'sustainable-catalyst-feature-suggestions'),
            'error' => __('Synchronization error', 'sustainable-catalyst-feature-suggestions'),
            'update_available' => __('Update available', 'sustainable-catalyst-feature-suggestions'),
            'unconfigured' => __('Repository not connected', 'sustainable-catalyst-feature-suggestions'),
            'semantic_tag' => __('Connected · semantic tag', 'sustainable-catalyst-feature-suggestions'),
            'connected_no_release' => __('Connected · no release', 'sustainable-catalyst-feature-suggestions'),
            'authentication_required' => __('Authentication required', 'sustainable-catalyst-feature-suggestions'),
            'repository_unavailable' => __('Repository unavailable', 'sustainable-catalyst-feature-suggestions'),
            'rate_limited' => __('GitHub rate limited', 'sustainable-catalyst-feature-suggestions'),
            'network_error' => __('Network error', 'sustainable-catalyst-feature-suggestions'),
        );
        return $labels[$state] ?? ucfirst(str_replace('_', ' ', $state));
    }

    private function safe_product_row($product_id, $record) {
        $freshness = $this->freshness_state($record['github_last_synced_at'] ?? '', $record['github_sync_state'] ?? '');
        return array(
            'canonical_id' => sanitize_key($product_id),
            'name' => sanitize_text_field($record['name'] ?? $product_id),
            'lifecycle_state' => sanitize_key($record['lifecycle_state'] ?? 'active'),
            'public_visible' => !empty($record['public_visible']),
            'homepage_visible' => !empty($record['homepage_visible']),
            'plugin_name' => sanitize_text_field($record['discovered_plugin_name'] ?? ''),
            'plugin_file' => sanitize_text_field($record['plugin_file'] ?? ''),
            'plugin_active' => !empty($record['discovered_active']),
            'activation_scope' => sanitize_key($record['discovered_activation_scope'] ?? 'inactive'),
            'installed_version' => sanitize_text_field($record['installed_version'] ?? ''),
            'repository_url' => esc_url_raw($record['github_repository_url'] ?? ''),
            'github_sync_enabled' => !empty($record['github_sync_enabled']),
            'github_sync_state' => sanitize_key($record['github_sync_state'] ?? 'unconfigured'),
            'github_sync_message' => sanitize_text_field($record['github_sync_message'] ?? ''),
            'github_connection_state' => sanitize_key($record['github_connection_state'] ?? ''),
            'github_sync_error_code' => sanitize_key($record['github_sync_error_code'] ?? ''),
            'github_sync_http_status' => sanitize_text_field($record['github_sync_http_status'] ?? ''),
            'github_sync_endpoint' => sanitize_key($record['github_sync_endpoint'] ?? ''),
            'github_sync_endpoint_url' => esc_url_raw($record['github_sync_endpoint_url'] ?? ''),
            'github_sync_failed_at' => sanitize_text_field($record['github_sync_failed_at'] ?? ''),
            'github_commit_sync_warning' => sanitize_text_field($record['github_commit_sync_warning'] ?? ''),
            'github_version' => sanitize_text_field($record['github_latest_version'] ?? ''),
            'github_version_source' => sanitize_key($record['github_version_source'] ?? ''),
            'public_version' => sanitize_text_field($record['public_version'] ?? ''),
            'status' => sanitize_key($record['status'] ?? 'unverified'),
            'last_synced_at' => sanitize_text_field($record['github_last_synced_at'] ?? ''),
            'freshness' => $freshness,
            'operational_state' => $this->product_operational_state($record),
        );
    }

    public function operations_report($registry = null) {
        $registry = is_array($registry) ? $registry : $this->registry();
        $products = array();
        $summary = array(
            'products' => 0,
            'active_plugins' => 0,
            'repositories' => 0,
            'current' => 0,
            'updates' => 0,
            'errors' => 0,
            'stale' => 0,
            'unconfigured' => 0,
            'semantic_tags' => 0,
            'no_releases' => 0,
        );
        foreach ($registry as $product_id => $record) {
            if (!is_array($record) || ($record['lifecycle_state'] ?? '') === 'retired') {
                continue;
            }
            $row = $this->safe_product_row($product_id, $record);
            $products[] = $row;
            $summary['products']++;
            if ($row['plugin_active']) {
                $summary['active_plugins']++;
            }
            if ($row['repository_url'] !== '') {
                $summary['repositories']++;
            }
            if ($row['operational_state'] === 'current') {
                $summary['current']++;
            }
            if ($row['operational_state'] === 'update_available') {
                $summary['updates']++;
            }
            if (in_array($row['operational_state'], array('error', 'authentication_required', 'repository_unavailable', 'rate_limited', 'network_error'), true)) {
                $summary['errors']++;
            }
            if ($row['operational_state'] === 'semantic_tag') {
                $summary['semantic_tags']++;
            }
            if ($row['operational_state'] === 'connected_no_release') {
                $summary['no_releases']++;
            }
            if (in_array($row['operational_state'], array('stale', 'never', 'unknown'), true)) {
                $summary['stale']++;
            }
            if ($row['operational_state'] === 'unconfigured') {
                $summary['unconfigured']++;
            }
        }
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'generated_at' => gmdate('c'),
            'summary' => $summary,
            'products' => $products,
        );
    }

    private function valid_link_target($value, $allow_blank = false) {
        $value = trim((string) $value);
        if ($value === '') {
            return $allow_blank;
        }
        if (strpos($value, '/') === 0) {
            return true;
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($value) : parse_url($value);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true) && !empty($parts['host']);
    }

    public function footer_health($registry = null) {
        $registry = is_array($registry) ? $registry : $this->registry();
        $settings = class_exists('SCFS_Release_Console_Copy')
            ? SCFS_Release_Console_Copy::instance()->footer_settings()
            : array(
                'footer_repository' => 'repository',
                'footer_repository_url' => '',
                'footer_support' => 'support',
                'footer_support_url' => '/support/',
            );
        $fallback = '';
        if (!empty($registry['product-support-feedback']['github_repository_url'])) {
            $fallback = esc_url_raw($registry['product-support-feedback']['github_repository_url']);
        }
        $configured_repository = trim((string) ($settings['footer_repository_url'] ?? ''));
        $resolved_repository = $configured_repository !== '' ? $configured_repository : $fallback;
        $support = trim((string) ($settings['footer_support_url'] ?? ''));
        return array(
            'repository_label' => sanitize_text_field($settings['footer_repository'] ?? 'repository'),
            'repository_configured' => $configured_repository,
            'repository_resolved' => esc_url_raw($resolved_repository),
            'repository_valid' => $this->valid_link_target($resolved_repository, false),
            'repository_uses_canonical_fallback' => $configured_repository === '' && $fallback !== '',
            'support_label' => sanitize_text_field($settings['footer_support'] ?? 'support'),
            'support_resolved' => $support,
            'support_valid' => $this->valid_link_target($support, false),
        );
    }

    public function audit_registry($registry = null) {
        $registry = is_array($registry) ? $registry : $this->registry();
        $findings = array();
        $repositories = array();
        $plugins = array();
        foreach ($registry as $product_id => $record) {
            if (!is_array($record) || ($record['lifecycle_state'] ?? '') === 'retired') {
                continue;
            }
            $name = sanitize_text_field($record['name'] ?? $product_id);
            $repository = strtolower(trim((string) ($record['github_repository_url'] ?? '')));
            $plugin_file = strtolower(trim((string) ($record['plugin_file'] ?? '')));
            if (!empty($record['github_sync_enabled']) && $repository === '') {
                $findings[] = array('severity' => 'warning', 'product_id' => $product_id, 'message' => __('GitHub synchronization is enabled but no repository is connected.', 'sustainable-catalyst-feature-suggestions'));
            }
            if (($record['github_sync_state'] ?? '') === 'error') {
                $findings[] = array('severity' => 'error', 'product_id' => $product_id, 'message' => sanitize_text_field($record['github_sync_message'] ?? __('GitHub synchronization failed.', 'sustainable-catalyst-feature-suggestions')));
            }
            $freshness = $this->freshness_state($record['github_last_synced_at'] ?? '', $record['github_sync_state'] ?? '');
            if ($repository !== '' && in_array($freshness, array('stale', 'never', 'unknown'), true)) {
                $findings[] = array('severity' => 'warning', 'product_id' => $product_id, 'message' => sprintf(__('Repository synchronization is %s.', 'sustainable-catalyst-feature-suggestions'), $this->state_label($freshness)));
            }
            if (!empty($record['discovery_enabled']) && empty($record['discovered_active'])) {
                $findings[] = array('severity' => 'notice', 'product_id' => $product_id, 'message' => __('No active WordPress plugin is currently connected.', 'sustainable-catalyst-feature-suggestions'));
            }
            if ($repository !== '') {
                if (isset($repositories[$repository])) {
                    $findings[] = array('severity' => 'error', 'product_id' => $product_id, 'message' => sprintf(__('Repository is also connected to %s.', 'sustainable-catalyst-feature-suggestions'), $repositories[$repository]));
                } else {
                    $repositories[$repository] = $name;
                }
            }
            if ($plugin_file !== '') {
                if (isset($plugins[$plugin_file])) {
                    $findings[] = array('severity' => 'error', 'product_id' => $product_id, 'message' => sprintf(__('Plugin implementation is also connected to %s.', 'sustainable-catalyst-feature-suggestions'), $plugins[$plugin_file]));
                } else {
                    $plugins[$plugin_file] = $name;
                }
            }
        }
        if (class_exists('SCFS_Installed_Plugin_Discovery')) {
            $active_plugins = SCFS_Installed_Plugin_Discovery::instance()->active_plugins_for_mapping();
            foreach ($registry as $product_id => $record) {
                if (!is_array($record) || ($record['lifecycle_state'] ?? '') === 'retired') {
                    continue;
                }
                $plugin_file = trim((string) ($record['plugin_file'] ?? ''));
                if (!empty($record['discovered_active']) && ($plugin_file === '' || !isset($active_plugins[$plugin_file]))) {
                    $findings[] = array(
                        'severity' => 'error',
                        'product_id' => $product_id,
                        'message' => __('The registry says the plugin is active, but it is not in WordPress’s current active-plugin list. Rescan installed plugins.', 'sustainable-catalyst-feature-suggestions'),
                    );
                } elseif ($plugin_file !== '' && isset($active_plugins[$plugin_file]) && empty($record['discovered_active'])) {
                    $findings[] = array(
                        'severity' => 'warning',
                        'product_id' => $product_id,
                        'message' => __('The mapped plugin is active in WordPress, but the registry snapshot is stale. Rescan installed plugins.', 'sustainable-catalyst-feature-suggestions'),
                    );
                }
            }
        }
        $footer = $this->footer_health($registry);
        if (!$footer['repository_valid']) {
            $findings[] = array(
                'severity' => 'error',
                'product_id' => 'release-console-footer',
                'message' => __('The Release Console repository link has no valid configured destination or canonical fallback.', 'sustainable-catalyst-feature-suggestions'),
            );
        }
        if (!$footer['support_valid']) {
            $findings[] = array(
                'severity' => 'error',
                'product_id' => 'release-console-footer',
                'message' => __('The Release Console Support link is missing or invalid.', 'sustainable-catalyst-feature-suggestions'),
            );
        }

        $audit = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'generated_at' => gmdate('c'),
            'finding_count' => count($findings),
            'findings' => $findings,
        );
        update_option(self::AUDIT_OPTION, $audit, false);
        return $audit;
    }

    private function selected_product_ids() {
        $selected = isset($_POST['product_ids']) ? (array) wp_unslash($_POST['product_ids']) : array();
        return array_values(array_unique(array_filter(array_map('sanitize_key', $selected))));
    }

    private function set_notice($type, $message) {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        set_transient(self::NOTICE_PREFIX . $user_id, array(
            'type' => sanitize_key($type),
            'message' => sanitize_text_field($message),
        ), 5 * MINUTE_IN_SECONDS);
    }

    private function redirect_url($args = array()) {
        return add_query_arg($args, admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG));
    }

    public function bulk_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);
        $operation = sanitize_key(wp_unslash($_POST['bulk_operation'] ?? ''));
        $product_ids = $this->selected_product_ids();
        $registry = $this->registry();
        if ($operation === 'sync_all') {
            $product_ids = array_keys(array_filter($registry, function ($record) {
                return !empty($record['github_sync_enabled']) && !empty($record['github_repository_url']);
            }));
            $operation = 'sync';
        }
        if (!$product_ids) {
            $this->set_notice('warning', __('Select at least one product.', 'sustainable-catalyst-feature-suggestions'));
            wp_safe_redirect($this->redirect_url());
            exit;
        }
        if ($operation === 'sync') {
            $ok = 0;
            $failed = 0;
            foreach ($product_ids as $product_id) {
                if (!isset($registry[$product_id])) {
                    continue;
                }
                $result = SCFS_Canonical_Product_GitHub_Sync::instance()->sync_product($product_id, 'release_operations_bulk');
                is_wp_error($result) ? $failed++ : $ok++;
            }
            $this->invalidate_operational_caches('bulk_sync');
            $this->audit_registry();
            $this->set_notice($failed ? 'warning' : 'success', sprintf(__('Synchronized %1$d product(s); %2$d failed. Successful retries cleared stale error diagnostics.', 'sustainable-catalyst-feature-suggestions'), $ok, $failed));
        } elseif ($operation === 'clear_errors') {
            $cleared = 0;
            foreach ($product_ids as $product_id) {
                if (!isset($registry[$product_id]) || ($registry[$product_id]['github_sync_state'] ?? '') !== 'error') {
                    continue;
                }
                $registry[$product_id]['github_sync_state'] = !empty($registry[$product_id]['github_repository_url']) ? 'pending' : 'unconfigured';
                $registry[$product_id]['github_sync_message'] = '';
                $registry[$product_id]['record_updated_at'] = gmdate('c');
                $cleared++;
            }
            if ($cleared) {
                $service = SCFS_Canonical_Product_Registry::instance();
                update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $service->normalize_registry($registry), false);
                do_action('scfs_product_registry_updated', $registry);
            }
            $this->invalidate_operational_caches('clear_errors');
            $this->set_notice('success', sprintf(__('Cleared %d synchronization error(s). Re-sync those products to restore a verified state.', 'sustainable-catalyst-feature-suggestions'), $cleared));
        } else {
            $this->set_notice('warning', __('Choose a valid release operation.', 'sustainable-catalyst-feature-suggestions'));
        }
        wp_safe_redirect($this->redirect_url());
        exit;
    }

    public function invalidate_operational_caches($reason = 'release_operations') {
        if (class_exists('SCFS_Installed_Plugin_Discovery')) {
            SCFS_Installed_Plugin_Discovery::instance()->invalidate_cache($reason);
        }
        if (class_exists('SCFS_Release_Board')) {
            SCFS_Release_Board::instance()->invalidate_cache();
        }
        do_action('scfs_release_operations_cache_invalidated', sanitize_key($reason));
    }

    public function stabilize_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);
        $plugin_scan = false;
        if (class_exists('SCFS_Installed_Plugin_Discovery')) {
            $plugin_scan = SCFS_Installed_Plugin_Discovery::instance()->refresh(true, 'release_operations_stabilize');
        }
        if (class_exists('SCFS_Canonical_Product_GitHub_Sync')) {
            SCFS_Canonical_Product_GitHub_Sync::instance()->ensure_schedule();
            $sync_results = SCFS_Canonical_Product_GitHub_Sync::instance()->sync_all('release_operations_stabilize');
        } else {
            $sync_results = array();
        }
        $this->invalidate_operational_caches('stabilize');
        $audit = $this->audit_registry();
        $failed = count(array_filter($sync_results, function ($result) {
            return empty($result['ok']);
        }));
        $message = sprintf(
            __('Stabilization completed: plugin discovery refreshed, %1$d repositories checked, %2$d failed, and the integrity audit found %3$d item(s).', 'sustainable-catalyst-feature-suggestions'),
            count($sync_results),
            $failed,
            (int) $audit['finding_count']
        );
        $this->set_notice($failed || $audit['finding_count'] ? 'warning' : 'success', $message);
        wp_safe_redirect($this->redirect_url(array('show_audit' => 1)));
        exit;
    }

    public function audit_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);
        $audit = $this->audit_registry();
        $this->set_notice($audit['finding_count'] ? 'warning' : 'success', sprintf(__('Release operations audit completed with %d finding(s).', 'sustainable-catalyst-feature-suggestions'), $audit['finding_count']));
        wp_safe_redirect($this->redirect_url(array('show_audit' => 1)));
        exit;
    }

    public function export_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);
        $report = $this->operations_report();
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=scfs-release-operations-' . gmdate('Ymd-His') . '.json');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function render_notice() {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $key = self::NOTICE_PREFIX . $user_id;
        $notice = get_transient($key);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient($key);
        $type = in_array($notice['type'] ?? '', array('success', 'warning', 'error', 'info'), true) ? $notice['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    private function filtered_products($products, $filter) {
        $filter = sanitize_key($filter);
        if ($filter === '' || $filter === 'all') {
            return $products;
        }
        return array_values(array_filter($products, function ($row) use ($filter) {
            if ($filter === 'stale') {
                return in_array($row['operational_state'], array('stale', 'never', 'unknown'), true);
            }
            if ($filter === 'error') {
                return in_array($row['operational_state'], array('error', 'authentication_required', 'repository_unavailable', 'rate_limited', 'network_error'), true);
            }
            return $row['operational_state'] === $filter;
        }));
    }

    private function render_time($timestamp) {
        $timestamp = trim((string) $timestamp);
        if ($timestamp === '') {
            return __('Never', 'sustainable-catalyst-feature-suggestions');
        }
        $unix = strtotime($timestamp);
        if (!$unix) {
            return $timestamp;
        }
        return sprintf(
            __('%1$s ago', 'sustainable-catalyst-feature-suggestions'),
            human_time_diff($unix, time())
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage release operations.', 'sustainable-catalyst-feature-suggestions'));
        }
        $report = $this->operations_report();
        $filter = sanitize_key(wp_unslash($_GET['state'] ?? 'all'));
        $products = $this->filtered_products($report['products'], $filter);
        $audit = get_option(self::AUDIT_OPTION, array());
        $base = admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG);
        $links = array(
            'all' => __('All', 'sustainable-catalyst-feature-suggestions'),
            'current' => __('Current', 'sustainable-catalyst-feature-suggestions'),
            'update_available' => __('Updates', 'sustainable-catalyst-feature-suggestions'),
            'error' => __('Errors', 'sustainable-catalyst-feature-suggestions'),
            'stale' => __('Stale', 'sustainable-catalyst-feature-suggestions'),
            'unconfigured' => __('Unconfigured', 'sustainable-catalyst-feature-suggestions'),
            'semantic_tag' => __('Semantic tags', 'sustainable-catalyst-feature-suggestions'),
            'connected_no_release' => __('No releases', 'sustainable-catalyst-feature-suggestions'),
        );
        echo '<div class="wrap scfs-release-operations">';
        echo '<h1>' . esc_html__('Release Operations', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p class="description">' . esc_html__('Operate the connection between each canonical product, its active WordPress plugin, its GitHub repository, and the public Release Console.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        $this->render_notice();

        echo '<div class="scfs-release-operations__links">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-release-operations__stabilize"><input type="hidden" name="action" value="scfs_release_operations_stabilize">';
        wp_nonce_field(self::NONCE_ACTION);
        submit_button(__('Stabilize release operations', 'sustainable-catalyst-feature-suggestions'), 'primary', 'submit', false);
        echo '</form>';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . SCFS_Installed_Plugin_Discovery::ADMIN_SLUG)) . '">' . esc_html__('Plugin connections', 'sustainable-catalyst-feature-suggestions') . '</a>';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . SCFS_GitHub_Connection_Settings::ADMIN_SLUG)) . '">' . esc_html__('GitHub connection', 'sustainable-catalyst-feature-suggestions') . '</a>';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . SCFS_Canonical_Product_Registry::ADMIN_SLUG)) . '">' . esc_html__('Product Registry', 'sustainable-catalyst-feature-suggestions') . '</a>';
        echo '</div>';

        echo '<div class="scfs-release-operations__summary" role="list" aria-label="' . esc_attr__('Release operations summary', 'sustainable-catalyst-feature-suggestions') . '">';
        foreach (array(
            'products' => __('Products', 'sustainable-catalyst-feature-suggestions'),
            'active_plugins' => __('Active plugins', 'sustainable-catalyst-feature-suggestions'),
            'repositories' => __('Repositories', 'sustainable-catalyst-feature-suggestions'),
            'current' => __('Current', 'sustainable-catalyst-feature-suggestions'),
            'updates' => __('Updates', 'sustainable-catalyst-feature-suggestions'),
            'errors' => __('Errors', 'sustainable-catalyst-feature-suggestions'),
            'stale' => __('Stale', 'sustainable-catalyst-feature-suggestions'),
            'semantic_tags' => __('Semantic tags', 'sustainable-catalyst-feature-suggestions'),
            'no_releases' => __('No releases', 'sustainable-catalyst-feature-suggestions'),
        ) as $key => $label) {
            echo '<div class="scfs-release-operations__metric" role="listitem"><strong>' . esc_html((string) $report['summary'][$key]) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div>';

        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Filter release operations', 'sustainable-catalyst-feature-suggestions') . '">';
        foreach ($links as $key => $label) {
            $class = $filter === $key || ($key === 'all' && $filter === '') ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(add_query_arg('state', $key, $base)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-release-operations__form">';
        echo '<input type="hidden" name="action" value="scfs_release_operations_bulk">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<div class="tablenav top"><div class="alignleft actions bulkactions">';
        echo '<label class="screen-reader-text" for="scfs-release-operation">' . esc_html__('Choose release operation', 'sustainable-catalyst-feature-suggestions') . '</label>';
        echo '<select id="scfs-release-operation" name="bulk_operation">';
        echo '<option value="">' . esc_html__('Bulk actions', 'sustainable-catalyst-feature-suggestions') . '</option>';
        echo '<option value="sync">' . esc_html__('Sync selected', 'sustainable-catalyst-feature-suggestions') . '</option>';
        echo '<option value="clear_errors">' . esc_html__('Clear selected errors', 'sustainable-catalyst-feature-suggestions') . '</option>';
        echo '<option value="sync_all">' . esc_html__('Sync all connected products', 'sustainable-catalyst-feature-suggestions') . '</option>';
        echo '</select> ';
        submit_button(__('Apply', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false);
        echo '</div></div>';

        echo '<div class="scfs-release-operations__table-wrap"><table class="widefat fixed striped scfs-release-operations__table">';
        echo '<thead><tr>';
        echo '<td class="manage-column check-column"><input type="checkbox" data-scfs-select-all aria-label="' . esc_attr__('Select all products', 'sustainable-catalyst-feature-suggestions') . '"></td>';
        foreach (array(
            __('Product', 'sustainable-catalyst-feature-suggestions'),
            __('Active plugin', 'sustainable-catalyst-feature-suggestions'),
            __('Installed', 'sustainable-catalyst-feature-suggestions'),
            __('GitHub repository', 'sustainable-catalyst-feature-suggestions'),
            __('Release', 'sustainable-catalyst-feature-suggestions'),
            __('Console state', 'sustainable-catalyst-feature-suggestions'),
            __('Last sync', 'sustainable-catalyst-feature-suggestions'),
            __('Actions', 'sustainable-catalyst-feature-suggestions'),
        ) as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$products) {
            echo '<tr><td colspan="9">' . esc_html__('No products match this filter.', 'sustainable-catalyst-feature-suggestions') . '</td></tr>';
        }
        foreach ($products as $row) {
            $state = $row['operational_state'];
            $repository_label = $row['repository_url'] !== '' ? preg_replace('#^https://github\.com/#i', '', $row['repository_url']) : __('Not connected', 'sustainable-catalyst-feature-suggestions');
            $sync_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action' => 'scfs_sync_canonical_github',
                        'product_id' => $row['canonical_id'],
                        'return_page' => self::ADMIN_SLUG,
                    ),
                    admin_url('admin-post.php')
                ),
                'scfs_sync_canonical_github'
            );
            echo '<tr>';
            echo '<th scope="row" class="check-column"><input type="checkbox" name="product_ids[]" value="' . esc_attr($row['canonical_id']) . '" aria-label="' . esc_attr(sprintf(__('Select %s', 'sustainable-catalyst-feature-suggestions'), $row['name'])) . '"></th>';
            echo '<td data-label="' . esc_attr__('Product', 'sustainable-catalyst-feature-suggestions') . '"><strong>' . esc_html($row['name']) . '</strong><code>' . esc_html($row['canonical_id']) . '</code></td>';
            echo '<td data-label="' . esc_attr__('Active plugin', 'sustainable-catalyst-feature-suggestions') . '">' . ($row['plugin_active'] ? esc_html($row['plugin_name'] ?: __('Mapped active plugin', 'sustainable-catalyst-feature-suggestions')) : '<span class="scfs-release-operations__muted">' . esc_html__('No active plugin', 'sustainable-catalyst-feature-suggestions') . '</span>') . '</td>';
            echo '<td data-label="' . esc_attr__('Installed', 'sustainable-catalyst-feature-suggestions') . '"><code>' . esc_html($row['installed_version'] ?: '—') . '</code></td>';
            echo '<td data-label="' . esc_attr__('GitHub repository', 'sustainable-catalyst-feature-suggestions') . '">' . ($row['repository_url'] !== '' ? '<a href="' . esc_url($row['repository_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($repository_label) . '</a>' : '<span class="scfs-release-operations__muted">' . esc_html($repository_label) . '</span>') . '</td>';
            echo '<td data-label="' . esc_attr__('Release', 'sustainable-catalyst-feature-suggestions') . '"><code>' . esc_html($row['github_version'] ?: $row['public_version'] ?: '—') . '</code><small>' . esc_html($row['github_version_source'] ? str_replace('_', ' ', $row['github_version_source']) : '') . '</small></td>';
            $diagnostic = '';
            if ($row['github_sync_http_status'] !== '' || $row['github_sync_endpoint'] !== '') {
                $diagnostic = '<small class="scfs-release-operations__diagnostic"><code>'
                    . esc_html(trim(($row['github_sync_http_status'] !== '' ? 'HTTP ' . $row['github_sync_http_status'] : '') . ' ' . str_replace('_', ' ', $row['github_sync_endpoint'])))
                    . '</code>';
                if ($row['github_sync_endpoint_url'] !== '') {
                    $diagnostic .= '<br><code>' . esc_html($row['github_sync_endpoint_url']) . '</code>';
                }
                $diagnostic .= '</small>';
            }
            if ($row['github_commit_sync_warning'] !== '') {
                $diagnostic .= '<small class="scfs-release-operations__warning">' . esc_html($row['github_commit_sync_warning']) . '</small>';
            }
            echo '<td data-label="' . esc_attr__('Console state', 'sustainable-catalyst-feature-suggestions') . '"><span class="scfs-release-operations__state scfs-release-operations__state--' . esc_attr($state) . '">' . esc_html($this->state_label($state)) . '</span>' . ($row['github_sync_message'] ? '<small>' . esc_html($row['github_sync_message']) . '</small>' : '') . $diagnostic . '</td>';
            echo '<td data-label="' . esc_attr__('Last sync', 'sustainable-catalyst-feature-suggestions') . '">' . esc_html($this->render_time($row['last_synced_at'])) . '</td>';

            $editor_url = add_query_arg(array('post_type' => Sustainable_Catalyst_Feature_Suggestions::POST_TYPE, 'page' => SCFS_Product_Connection_Editor::ADMIN_SLUG, 'product_id' => $row['canonical_id']), admin_url('edit.php'));
            echo '<td data-label="' . esc_attr__('Actions', 'sustainable-catalyst-feature-suggestions') . '"><a class="button button-small" href="' . esc_url($editor_url) . '">' . esc_html__('Edit connection', 'sustainable-catalyst-feature-suggestions') . '</a> <a class="button button-small" href="' . esc_url($sync_url) . '">' . esc_html__('Sync now', 'sustainable-catalyst-feature-suggestions') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></form>';

        $footer = $this->footer_health();
        echo '<section class="scfs-release-operations__footer-health" aria-labelledby="scfs-release-operations-footer-title">';
        echo '<h2 id="scfs-release-operations-footer-title">' . esc_html__('Release Console footer verification', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<dl><div><dt>./' . esc_html($footer['repository_label']) . '</dt><dd>' . ($footer['repository_valid'] ? '<code>' . esc_html($footer['repository_resolved']) . '</code>' : '<strong>' . esc_html__('Invalid or missing destination', 'sustainable-catalyst-feature-suggestions') . '</strong>') . ($footer['repository_uses_canonical_fallback'] ? '<small>' . esc_html__('Using canonical repository fallback', 'sustainable-catalyst-feature-suggestions') . '</small>' : '') . '</dd></div>';
        echo '<div><dt>./' . esc_html($footer['support_label']) . '</dt><dd>' . ($footer['support_valid'] ? '<code>' . esc_html($footer['support_resolved']) . '</code>' : '<strong>' . esc_html__('Invalid or missing destination', 'sustainable-catalyst-feature-suggestions') . '</strong>') . '</dd></div></dl>';
        echo '</section>';

        echo '<div class="scfs-release-operations__secondary-actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_release_operations_audit">';
        wp_nonce_field(self::NONCE_ACTION);
        submit_button(__('Run integrity audit', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false);
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_release_operations_export">';
        wp_nonce_field(self::NONCE_ACTION);
        submit_button(__('Export operational report', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false);
        echo '</form></div>';

        if (is_array($audit) && !empty($audit['generated_at'])) {
            echo '<section class="scfs-release-operations__audit" aria-labelledby="scfs-release-operations-audit-title">';
            echo '<h2 id="scfs-release-operations-audit-title">' . esc_html__('Latest integrity audit', 'sustainable-catalyst-feature-suggestions') . '</h2>';
            echo '<p>' . esc_html(sprintf(__('Generated %1$s with %2$d finding(s).', 'sustainable-catalyst-feature-suggestions'), $this->render_time($audit['generated_at']), (int) ($audit['finding_count'] ?? 0))) . '</p>';
            if (empty($audit['findings'])) {
                echo '<p><strong>' . esc_html__('No release-operation integrity problems were found.', 'sustainable-catalyst-feature-suggestions') . '</strong></p>';
            } else {
                echo '<ul>';
                foreach ((array) $audit['findings'] as $finding) {
                    echo '<li class="scfs-release-operations__finding scfs-release-operations__finding--' . esc_attr($finding['severity'] ?? 'notice') . '"><strong>' . esc_html($finding['product_id'] ?? '') . '</strong>: ' . esc_html($finding['message'] ?? '') . '</li>';
                }
                echo '</ul>';
            }
            echo '</section>';
        }
        echo '</div>';
    }

    public function cli_report($args, $assoc_args) {
        $report = $this->operations_report();
        $format = sanitize_key($assoc_args['format'] ?? 'json');
        if ($format === 'summary') {
            foreach ($report['summary'] as $key => $value) {
                WP_CLI::line($key . ': ' . $value);
            }
            return;
        }
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
