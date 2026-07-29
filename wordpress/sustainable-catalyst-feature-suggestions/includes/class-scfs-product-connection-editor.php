<?php
/**
 * Canonical Product Connection Editor.
 *
 * Provides one governed editor for product identity, active WordPress plugin,
 * GitHub repository, Release Console presentation, routes, aliases, validation,
 * and connection history.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Product_Connection_Editor {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-product-connection-editor/1.0';
    const ADMIN_SLUG = 'scfs-product-connection-editor';
    const HISTORY_OPTION = 'scfs_product_connection_history';
    const VALIDATION_PREFIX = 'scfs_product_connection_validation_';
    const NONCE_ACTION = 'scfs_product_connection_editor';
    const MAX_HISTORY = 300;

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
        add_action('admin_post_scfs_save_product_connection_editor', array($this, 'save_action'));
        add_action('admin_post_scfs_test_product_connection', array($this, 'test_action'));
        add_action('admin_post_scfs_sync_product_connection', array($this, 'sync_action'));
        add_action('admin_post_scfs_remove_product_plugin', array($this, 'remove_plugin_action'));
        add_action('admin_post_scfs_remove_product_repository', array($this, 'remove_repository_action'));
        add_action('admin_post_scfs_reset_product_connection', array($this, 'reset_action'));
        add_action('admin_post_scfs_validate_product_connection', array($this, 'validate_action'));
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('scfs products connection', array($this, 'cli_connection'));
        }
    }

    public static function activate() {
        if (!is_array(get_option(self::HISTORY_OPTION))) {
            add_option(self::HISTORY_OPTION, array(), '', false);
        }
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'canonical_identity_immutable' => true,
            'single_product_editor' => true,
            'all_active_plugins_selectable' => true,
            'github_repository_editable' => true,
            'console_presentation_editable' => true,
            'documentation_routes_editable' => true,
            'aliases_editable' => true,
            'connection_test' => true,
            'sync_now' => true,
            'remove_plugin_mapping' => true,
            'remove_repository_mapping' => true,
            'reset_inherited_values' => true,
            'connection_history' => true,
            'product_validation' => true,
            'legacy_shortcodes_preserved' => true,
        );
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Product Connection Editor', 'sustainable-catalyst-feature-suggestions'),
            __('Product Connections', 'sustainable-catalyst-feature-suggestions'),
            'manage_options',
            self::ADMIN_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_assets($hook_suffix) {
        if (strpos((string) $hook_suffix, self::ADMIN_SLUG) === false) {
            return;
        }
        $css = 'assets/product-connection-editor-v7.8.1.css';
        $js = 'assets/product-connection-editor-v7.8.1.js';
        $base = plugin_dir_path(dirname(__FILE__));
        wp_enqueue_style('scfs-product-connection-editor', plugins_url($css, dirname(__FILE__)), array(), is_file($base . $css) ? (string) filemtime($base . $css) : self::VERSION);
        wp_enqueue_script('scfs-product-connection-editor', plugins_url($js, dirname(__FILE__)), array(), is_file($base . $js) ? (string) filemtime($base . $js) : self::VERSION, true);
    }

    private function can_manage() {
        return current_user_can('manage_options');
    }

    private function registry_service() {
        return SCFS_Canonical_Product_Registry::instance();
    }

    private function discovery_service() {
        return SCFS_Installed_Plugin_Discovery::instance();
    }

    private function registry() {
        return $this->registry_service()->registry();
    }

    private function redirect_url($product_id, $args = array()) {
        return add_query_arg(array_merge(array(
            'post_type' => Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            'page' => self::ADMIN_SLUG,
            'product_id' => sanitize_key($product_id),
        ), $args), admin_url('edit.php'));
    }

    private function selected_product_id($registry) {
        $requested = sanitize_key(wp_unslash($_GET['product_id'] ?? ''));
        if ($requested !== '' && isset($registry[$requested])) {
            return $requested;
        }
        reset($registry);
        return (string) key($registry);
    }

    public function mapped_plugin_for_product($product_id, $record = array()) {
        foreach ($this->discovery_service()->manual_mappings() as $plugin_file => $mapping) {
            if (($mapping['product_id'] ?? '') === $product_id) {
                return (string) $plugin_file;
            }
        }
        return sanitize_text_field($record['plugin_file'] ?? '');
    }

    public function connection_snapshot($product_id, $record, $active_plugins = array()) {
        $plugin_file = $this->mapped_plugin_for_product($product_id, $record);
        $plugin = isset($active_plugins[$plugin_file]) ? $active_plugins[$plugin_file] : array();
        return array(
            'canonical_id' => sanitize_key($product_id),
            'name' => sanitize_text_field($record['name'] ?? $product_id),
            'short_name' => sanitize_text_field($record['short_name'] ?? ''),
            'description' => sanitize_textarea_field($record['product_description'] ?? ''),
            'family' => sanitize_key($record['family'] ?? 'foundation'),
            'lifecycle_state' => sanitize_key($record['lifecycle_state'] ?? 'active'),
            'status' => sanitize_key($record['status'] ?? 'unverified'),
            'plugin_file' => $plugin_file,
            'plugin_name' => sanitize_text_field($plugin['name'] ?? ($record['discovered_plugin_name'] ?? '')),
            'plugin_active' => isset($active_plugins[$plugin_file]),
            'installed_version' => sanitize_text_field($plugin['version'] ?? ($record['installed_version'] ?? '')),
            'repository_url' => esc_url_raw($record['github_repository_url'] ?? ''),
            'default_branch' => sanitize_text_field($record['github_default_branch'] ?? 'main'),
            'github_version' => sanitize_text_field($record['github_latest_version'] ?? ''),
            'github_version_source' => sanitize_key($record['github_version_source'] ?? ''),
            'github_sync_state' => sanitize_key($record['github_sync_state'] ?? 'unconfigured'),
            'github_last_synced_at' => sanitize_text_field($record['github_last_synced_at'] ?? ''),
            'product_url' => esc_url_raw($record['product_url'] ?? ''),
            'documentation_url' => esc_url_raw($record['documentation_url'] ?? ''),
            'support_url' => esc_url_raw($record['support_url'] ?? ''),
            'release_notes_url' => esc_url_raw($record['release_notes_url'] ?? ''),
            'console_screen' => sanitize_key($record['console_screen'] ?? ($record['family'] ?? 'foundation')),
            'console_included' => !empty($record['homepage_visible']),
            'public_visible' => !empty($record['public_visible']),
            'display_order' => absint($record['display_order'] ?? 999),
            'console_badges' => array_values(array_filter(array_map('sanitize_text_field', (array) ($record['console_badges'] ?? array())))),
            'legacy_names' => array_values((array) ($record['legacy_names'] ?? array())),
            'legacy_plugin_files' => array_values((array) ($record['legacy_plugin_files'] ?? array())),
            'legacy_plugin_slugs' => array_values((array) ($record['legacy_plugin_slugs'] ?? array())),
            'legacy_text_domains' => array_values((array) ($record['legacy_text_domains'] ?? array())),
            'validation_state' => sanitize_key($record['validation_state'] ?? 'pending'),
            'documentation_state' => sanitize_key($record['documentation_state'] ?? 'unavailable'),
            'version_precedence' => sanitize_key($record['version_precedence'] ?? 'github'),
            'manual_notes' => sanitize_textarea_field($record['manual_notes'] ?? ''),
        );
    }

    private function csv_values($value, $sanitize = 'sanitize_text_field') {
        $items = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
        $clean = array();
        foreach ((array) $items as $item) {
            $item = call_user_func($sanitize, trim((string) $item));
            if ($item !== '') {
                $clean[] = $item;
            }
        }
        return array_values(array_unique($clean));
    }

    public function merge_editable_fields($record, $input) {
        $record['name'] = sanitize_text_field($input['name'] ?? ($record['name'] ?? ''));
        $record['short_name'] = sanitize_text_field($input['short_name'] ?? ($record['short_name'] ?? ''));
        $record['product_description'] = sanitize_textarea_field($input['product_description'] ?? ($record['product_description'] ?? ''));
        $record['family'] = sanitize_key($input['family'] ?? ($record['family'] ?? 'foundation'));
        $record['console_screen'] = sanitize_key($input['console_screen'] ?? ($record['console_screen'] ?? $record['family']));
        $record['lifecycle_state'] = sanitize_key($input['lifecycle_state'] ?? ($record['lifecycle_state'] ?? 'active'));
        $record['status'] = sanitize_key($input['status'] ?? ($record['status'] ?? 'unverified'));
        $record['version_precedence'] = sanitize_key($input['version_precedence'] ?? ($record['version_precedence'] ?? 'github'));
        $record['github_default_branch'] = sanitize_text_field($input['github_default_branch'] ?? ($record['github_default_branch'] ?? 'main'));
        $record['product_url'] = esc_url_raw($input['product_url'] ?? '');
        $record['documentation_url'] = esc_url_raw($input['documentation_url'] ?? '');
        $record['support_url'] = esc_url_raw($input['support_url'] ?? '');
        $record['release_notes_url'] = esc_url_raw($input['release_notes_url'] ?? '');
        $record['display_order'] = absint($input['display_order'] ?? ($record['display_order'] ?? 999));
        $record['public_visible'] = !empty($input['public_visible']) ? '1' : '';
        $record['homepage_visible'] = !empty($input['homepage_visible']) ? '1' : '';
        $record['console_badges'] = $this->csv_values($input['console_badges'] ?? '');
        $record['legacy_names'] = $this->csv_values($input['legacy_names'] ?? '');
        $record['legacy_plugin_files'] = $this->csv_values($input['legacy_plugin_files'] ?? '');
        $record['legacy_plugin_slugs'] = $this->csv_values($input['legacy_plugin_slugs'] ?? '', 'sanitize_key');
        $record['legacy_text_domains'] = $this->csv_values($input['legacy_text_domains'] ?? '', 'sanitize_key');
        $record['validation_state'] = sanitize_key($input['validation_state'] ?? ($record['validation_state'] ?? 'pending'));
        $record['documentation_state'] = sanitize_key($input['documentation_state'] ?? ($record['documentation_state'] ?? 'unavailable'));
        $record['manual_notes'] = sanitize_textarea_field($input['manual_notes'] ?? '');
        $record['record_updated_at'] = gmdate('c');
        $record['verification_source'] = 'administrator';
        $record['source_verified_at'] = gmdate('c');
        return $record;
    }

    public function validate_connection($product_id, $record, $active_plugins = array()) {
        $issues = array();
        if (sanitize_key($product_id) === '') {
            $issues[] = array('severity' => 'error', 'code' => 'canonical_id_missing', 'message' => 'Canonical product ID is missing.');
        }
        if (trim((string) ($record['name'] ?? '')) === '') {
            $issues[] = array('severity' => 'error', 'code' => 'name_missing', 'message' => 'Public product name is required.');
        }
        $plugin_file = $this->mapped_plugin_for_product($product_id, $record);
        if ($plugin_file !== '' && !isset($active_plugins[$plugin_file])) {
            $issues[] = array('severity' => 'warning', 'code' => 'plugin_not_active', 'message' => 'The mapped WordPress plugin is not currently active.');
        }
        $repository = trim((string) ($record['github_repository_url'] ?? ''));
        if ($repository !== '' && class_exists('SCFS_Canonical_Product_GitHub_Sync') && !SCFS_Canonical_Product_GitHub_Sync::instance()->parse_repository_url($repository)) {
            $issues[] = array('severity' => 'error', 'code' => 'repository_invalid', 'message' => 'The GitHub repository URL is invalid.');
        }
        if (!empty($record['homepage_visible']) && empty($record['public_visible'])) {
            $issues[] = array('severity' => 'warning', 'code' => 'console_not_public', 'message' => 'The product is included in the console but excluded from the public registry.');
        }
        if (!empty($record['public_visible']) && trim((string) ($record['support_url'] ?? '')) === '') {
            $issues[] = array('severity' => 'warning', 'code' => 'support_url_missing', 'message' => 'A public product should normally have a support destination.');
        }
        if (($record['documentation_state'] ?? '') === 'ready' && trim((string) ($record['documentation_url'] ?? '')) === '') {
            $issues[] = array('severity' => 'warning', 'code' => 'documentation_url_missing', 'message' => 'Documentation is marked ready but no documentation URL is configured.');
        }
        $errors = array_filter($issues, function ($issue) { return ($issue['severity'] ?? '') === 'error'; });
        return array(
            'valid' => count($errors) === 0,
            'product_id' => sanitize_key($product_id),
            'issue_count' => count($issues),
            'error_count' => count($errors),
            'issues' => array_values($issues),
            'generated_at' => gmdate('c'),
        );
    }

    private function add_history($product_id, $event, $context = array()) {
        $history = get_option(self::HISTORY_OPTION, array());
        $history = is_array($history) ? $history : array();
        array_unshift($history, array(
            'event_id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('scfs-', true),
            'product_id' => sanitize_key($product_id),
            'event' => sanitize_key($event),
            'occurred_at' => gmdate('c'),
            'actor_user_id' => function_exists('get_current_user_id') ? absint(get_current_user_id()) : 0,
            'context' => is_array($context) ? $context : array(),
        ));
        update_option(self::HISTORY_OPTION, array_slice($history, 0, self::MAX_HISTORY), false);
    }

    public function history_for_product($product_id, $limit = 20) {
        $events = array_filter((array) get_option(self::HISTORY_OPTION, array()), function ($event) use ($product_id) {
            return ($event['product_id'] ?? '') === $product_id;
        });
        return array_slice(array_values($events), 0, max(1, absint($limit)));
    }

    private function save_validation($product_id, $report) {
        set_transient(self::VALIDATION_PREFIX . get_current_user_id() . '_' . sanitize_key($product_id), $report, 10 * MINUTE_IN_SECONDS);
    }

    private function latest_validation($product_id) {
        return get_transient(self::VALIDATION_PREFIX . get_current_user_id() . '_' . sanitize_key($product_id));
    }

    private function require_product() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION);
        $product_id = sanitize_key(wp_unslash($_POST['product_id'] ?? $_GET['product_id'] ?? ''));
        $registry = $this->registry();
        if ($product_id === '' || !isset($registry[$product_id])) {
            wp_die(esc_html__('Canonical product not found.', 'sustainable-catalyst-feature-suggestions'));
        }
        return array($product_id, $registry);
    }

    public function save_action() {
        list($product_id, $registry) = $this->require_product();
        $input = isset($_POST['product']) && is_array($_POST['product']) ? wp_unslash($_POST['product']) : array();
        $plugin_file = wp_unslash($_POST['plugin_file'] ?? '');
        $repository_url = wp_unslash($_POST['github_repository_url'] ?? '');
        $candidate = $this->merge_editable_fields($registry[$product_id], $input);
        $candidate['github_repository_url'] = esc_url_raw($repository_url);
        $candidate['github_sync_enabled'] = $candidate['github_repository_url'] !== '' ? '1' : '';
        $proposed = $registry;
        $proposed[$product_id] = $candidate;
        $normalized = $this->registry_service()->normalize_registry($proposed);
        $integrity = $this->registry_service()->integrity_report($normalized);
        if (!$integrity['valid']) {
            $this->save_validation($product_id, array('valid' => false, 'issues' => $integrity['issues'], 'issue_count' => count($integrity['issues']), 'error_count' => $integrity['error_count'], 'generated_at' => gmdate('c')));
            wp_safe_redirect($this->redirect_url($product_id, array('connection_error' => 'integrity')));
            exit;
        }
        update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $normalized, false);
        $connection = $this->discovery_service()->save_product_connection($product_id, $plugin_file, $repository_url);
        if (is_wp_error($connection)) {
            update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $registry, false);
            wp_safe_redirect($this->redirect_url($product_id, array('connection_error' => rawurlencode($connection->get_error_code()))));
            exit;
        }
        $updated = $this->registry();
        $report = $this->validate_connection($product_id, $updated[$product_id], $this->discovery_service()->active_plugins_for_mapping());
        $this->save_validation($product_id, $report);
        $this->add_history($product_id, 'connection_saved', array(
            'plugin_file' => sanitize_text_field($plugin_file),
            'repository_url' => esc_url_raw($repository_url),
            'validation_issue_count' => $report['issue_count'],
        ));
        do_action('scfs_product_connection_editor_saved', $product_id, $updated[$product_id]);
        wp_safe_redirect($this->redirect_url($product_id, array('connection_saved' => 1)));
        exit;
    }

    public function test_action() {
        list($product_id, $registry) = $this->require_product();
        $report = $this->validate_connection($product_id, $registry[$product_id], $this->discovery_service()->active_plugins_for_mapping());
        if ($report['valid'] && !empty($registry[$product_id]['github_repository_url']) && class_exists('SCFS_Canonical_Product_GitHub_Sync')) {
            $sync = SCFS_Canonical_Product_GitHub_Sync::instance()->sync_product($product_id, 'connection_editor_test');
            if (is_wp_error($sync)) {
                $report['valid'] = false;
                $report['error_count']++;
                $report['issue_count']++;
                $report['issues'][] = array('severity' => 'error', 'code' => $sync->get_error_code(), 'message' => $sync->get_error_message());
            }
        }
        $this->save_validation($product_id, $report);
        $this->add_history($product_id, 'connection_tested', array('valid' => $report['valid'], 'issue_count' => $report['issue_count']));
        wp_safe_redirect($this->redirect_url($product_id, array('connection_tested' => 1)));
        exit;
    }

    public function sync_action() {
        list($product_id) = $this->require_product();
        $result = SCFS_Canonical_Product_GitHub_Sync::instance()->sync_product($product_id, 'connection_editor_manual');
        $this->add_history($product_id, is_wp_error($result) ? 'sync_failed' : 'sync_completed', array('message' => is_wp_error($result) ? $result->get_error_message() : ''));
        $notice_key = is_wp_error($result) ? 'sync_error' : 'sync_completed';
        wp_safe_redirect($this->redirect_url($product_id, array($notice_key => 1)));
        exit;
    }

    public function remove_plugin_action() {
        list($product_id, $registry) = $this->require_product();
        $result = $this->discovery_service()->save_product_connection($product_id, '', $registry[$product_id]['github_repository_url'] ?? '');
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        $this->add_history($product_id, 'plugin_mapping_removed');
        wp_safe_redirect($this->redirect_url($product_id, array('plugin_removed' => 1)));
        exit;
    }

    public function remove_repository_action() {
        list($product_id, $registry) = $this->require_product();
        $plugin_file = $this->mapped_plugin_for_product($product_id, $registry[$product_id]);
        $result = $this->discovery_service()->save_product_connection($product_id, $plugin_file, '');
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        $this->add_history($product_id, 'repository_mapping_removed');
        wp_safe_redirect($this->redirect_url($product_id, array('repository_removed' => 1)));
        exit;
    }

    public function reset_action() {
        list($product_id, $registry) = $this->require_product();
        $defaults = $this->registry_service()->default_products();
        if (!isset($defaults[$product_id])) {
            wp_safe_redirect($this->redirect_url($product_id, array('reset_error' => 1)));
            exit;
        }
        $preserve = array(
            'plugin_file', 'plugin_slug', 'plugin_text_domain', 'legacy_plugin_files', 'legacy_plugin_slugs', 'legacy_text_domains',
            'discovered_active', 'discovered_activation_scope', 'discovered_plugin_name', 'discovered_plugin_version', 'discovered_plugin_version_raw',
            'discovered_version_state', 'discovered_text_domain', 'last_discovered_at', 'installed_version',
            'github_repository_url', 'github_default_branch', 'github_sync_enabled', 'github_sync_state', 'github_latest_version',
            'github_latest_release_name', 'github_latest_release_url', 'github_latest_release_at', 'github_latest_tag_name', 'github_latest_tag_url',
            'github_version_source', 'github_latest_commit_sha', 'github_repository_updated_at', 'github_last_synced_at', 'github_connection_state',
        );
        $record = $defaults[$product_id];
        foreach ($preserve as $key) {
            if (array_key_exists($key, $registry[$product_id])) {
                $record[$key] = $registry[$product_id][$key];
            }
        }
        $record['record_updated_at'] = gmdate('c');
        $record['verification_source'] = 'administrator';
        $registry[$product_id] = $record;
        update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $this->registry_service()->normalize_registry($registry), false);
        do_action('scfs_product_registry_updated', $this->registry());
        $this->add_history($product_id, 'inherited_values_reset');
        wp_safe_redirect($this->redirect_url($product_id, array('connection_reset' => 1)));
        exit;
    }

    public function validate_action() {
        list($product_id, $registry) = $this->require_product();
        $report = $this->validate_connection($product_id, $registry[$product_id], $this->discovery_service()->active_plugins_for_mapping());
        $integrity = $this->registry_service()->integrity_report($registry);
        foreach ((array) ($integrity['issues'] ?? array()) as $issue) {
            if (($issue['product_id'] ?? '') !== $product_id) {
                continue;
            }
            $report['issues'][] = $issue;
            $report['issue_count']++;
            if (($issue['severity'] ?? '') === 'error') {
                $report['error_count']++;
                $report['valid'] = false;
            }
        }
        $this->save_validation($product_id, $report);
        $this->add_history($product_id, 'connection_validated', array('valid' => $report['valid'], 'issue_count' => $report['issue_count']));
        wp_safe_redirect($this->redirect_url($product_id, array('connection_validated' => 1)));
        exit;
    }

    private function render_notice() {
        $messages = array(
            'connection_saved' => __('Product connection saved.', 'sustainable-catalyst-feature-suggestions'),
            'connection_tested' => __('Connection test completed.', 'sustainable-catalyst-feature-suggestions'),
            'sync_completed' => __('GitHub synchronization completed.', 'sustainable-catalyst-feature-suggestions'),
            'plugin_removed' => __('WordPress plugin mapping removed.', 'sustainable-catalyst-feature-suggestions'),
            'repository_removed' => __('GitHub repository mapping removed.', 'sustainable-catalyst-feature-suggestions'),
            'connection_reset' => __('Inherited product values reset while connection evidence was preserved.', 'sustainable-catalyst-feature-suggestions'),
            'connection_validated' => __('Product connection validation completed.', 'sustainable-catalyst-feature-suggestions'),
        );
        foreach ($messages as $key => $message) {
            if (!empty($_GET[$key])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
                return;
            }
        }
        if (!empty($_GET['connection_error']) || !empty($_GET['sync_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('The product connection could not be completed. Review validation and synchronization evidence below.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        }
    }

    private function select($name, $selected, $options) {
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private function action_form($action, $product_id, $label, $class = 'secondary', $confirm = '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-product-editor__inline-form">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        $attrs = $confirm !== '' ? ' data-scfs-confirm="' . esc_attr($confirm) . '"' : '';
        echo '<button type="submit" class="button button-' . esc_attr($class) . '"' . $attrs . '>' . esc_html($label) . '</button></form>';
    }

    public function render_admin_page() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        $registry = $this->registry();
        $product_id = $this->selected_product_id($registry);
        $record = $registry[$product_id];
        $plugins = $this->discovery_service()->active_plugins_for_mapping();
        $snapshot = $this->connection_snapshot($product_id, $record, $plugins);
        $validation = $this->latest_validation($product_id);
        $history = $this->history_for_product($product_id, 12);

        echo '<div class="wrap scfs-product-editor">';
        echo '<h1>' . esc_html__('Product Connection Editor', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p>' . esc_html__('Edit one canonical product, its active WordPress implementation, GitHub source, Release Console presentation, public routes, aliases, and validation evidence from one governed screen.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        $this->render_notice();
        echo '<div class="scfs-product-editor__layout">';
        echo '<nav class="scfs-product-editor__products" aria-label="' . esc_attr__('Canonical products', 'sustainable-catalyst-feature-suggestions') . '"><label for="scfs-product-editor-search" class="screen-reader-text">' . esc_html__('Filter products', 'sustainable-catalyst-feature-suggestions') . '</label><input id="scfs-product-editor-search" type="search" placeholder="' . esc_attr__('Filter products', 'sustainable-catalyst-feature-suggestions') . '" data-scfs-product-filter>';
        echo '<ul>';
        foreach ($registry as $id => $product) {
            $url = $this->redirect_url($id);
            echo '<li data-scfs-product-item><a href="' . esc_url($url) . '" ' . ($id === $product_id ? 'aria-current="page"' : '') . '><strong>' . esc_html($product['short_name'] ?: $product['name']) . '</strong><code>' . esc_html($id) . '</code></a></li>';
        }
        echo '</ul></nav>';
        echo '<main class="scfs-product-editor__main">';
        echo '<header class="scfs-product-editor__header"><div><span class="scfs-product-editor__eyebrow">' . esc_html__('Canonical product', 'sustainable-catalyst-feature-suggestions') . '</span><h2>' . esc_html($snapshot['name']) . '</h2><code>' . esc_html($product_id) . '</code></div>';
        echo '<div class="scfs-product-editor__status"><span>' . esc_html($snapshot['plugin_active'] ? __('Active plugin mapped', 'sustainable-catalyst-feature-suggestions') : __('No active plugin mapped', 'sustainable-catalyst-feature-suggestions')) . '</span><span>' . esc_html($snapshot['github_sync_state'] ? str_replace('_', ' ', $snapshot['github_sync_state']) : __('Repository unconfigured', 'sustainable-catalyst-feature-suggestions')) . '</span></div></header>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-scfs-product-editor-form>';
        echo '<input type="hidden" name="action" value="scfs_save_product_connection_editor"><input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">';
        wp_nonce_field(self::NONCE_ACTION);

        echo '<section class="scfs-product-editor__section"><h3>' . esc_html__('Identity and lifecycle', 'sustainable-catalyst-feature-suggestions') . '</h3><div class="scfs-product-editor__grid">';
        echo '<label><span>' . esc_html__('Public product name', 'sustainable-catalyst-feature-suggestions') . '</span><input class="large-text" name="product[name]" required value="' . esc_attr($record['name']) . '"></label>';
        echo '<label><span>' . esc_html__('Short name', 'sustainable-catalyst-feature-suggestions') . '</span><input name="product[short_name]" value="' . esc_attr($record['short_name']) . '"></label>';
        echo '<label class="scfs-product-editor__wide"><span>' . esc_html__('Product description', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="product[product_description]" rows="3">' . esc_textarea($record['product_description'] ?? '') . '</textarea></label>';
        echo '<label><span>' . esc_html__('Product family', 'sustainable-catalyst-feature-suggestions') . '</span>'; $this->select('product[family]', $record['family'], $this->registry_service()->families()); echo '</label>';
        echo '<label><span>' . esc_html__('Lifecycle state', 'sustainable-catalyst-feature-suggestions') . '</span>'; $this->select('product[lifecycle_state]', $record['lifecycle_state'], $this->registry_service()->lifecycle_states()); echo '</label>';
        echo '<label><span>' . esc_html__('Release state', 'sustainable-catalyst-feature-suggestions') . '</span>'; $this->select('product[status]', $record['status'], $this->registry_service()->statuses()); echo '</label>';
        echo '<label><span>' . esc_html__('Version authority', 'sustainable-catalyst-feature-suggestions') . '</span>'; $this->select('product[version_precedence]', $record['version_precedence'], $this->registry_service()->version_precedence_options()); echo '</label>';
        echo '</div></section>';

        echo '<section class="scfs-product-editor__section"><h3>' . esc_html__('WordPress and GitHub connection', 'sustainable-catalyst-feature-suggestions') . '</h3><div class="scfs-product-editor__grid">';
        echo '<label class="scfs-product-editor__wide"><span>' . esc_html__('Active WordPress plugin', 'sustainable-catalyst-feature-suggestions') . '</span><select name="plugin_file"><option value="">' . esc_html__('No active plugin mapping', 'sustainable-catalyst-feature-suggestions') . '</option>';
        foreach ($plugins as $plugin_file => $plugin) {
            $label = sprintf('%1$s — %2$s (%3$s)', $plugin['name'] ?? $plugin_file, $plugin['version'] ?? __('version unknown', 'sustainable-catalyst-feature-suggestions'), $plugin_file);
            echo '<option value="' . esc_attr($plugin_file) . '" ' . selected($snapshot['plugin_file'], $plugin_file, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><small>' . esc_html__('Every currently active site or network plugin is available.', 'sustainable-catalyst-feature-suggestions') . '</small></label>';
        echo '<label class="scfs-product-editor__wide"><span>' . esc_html__('GitHub repository', 'sustainable-catalyst-feature-suggestions') . '</span><input type="url" class="large-text code" name="github_repository_url" value="' . esc_attr($record['github_repository_url']) . '" placeholder="https://github.com/owner/repository"></label>';
        echo '<label><span>' . esc_html__('Default branch', 'sustainable-catalyst-feature-suggestions') . '</span><input name="product[github_default_branch]" value="' . esc_attr($record['github_default_branch']) . '"></label>';
        echo '<div class="scfs-product-editor__evidence"><strong>' . esc_html__('Installed version', 'sustainable-catalyst-feature-suggestions') . '</strong><code>' . esc_html($snapshot['installed_version'] ?: '—') . '</code></div>';
        echo '<div class="scfs-product-editor__evidence"><strong>' . esc_html__('GitHub version', 'sustainable-catalyst-feature-suggestions') . '</strong><code>' . esc_html($snapshot['github_version'] ?: '—') . '</code><small>' . esc_html(str_replace('_', ' ', $snapshot['github_version_source'])) . '</small></div>';
        echo '<div class="scfs-product-editor__evidence"><strong>' . esc_html__('Last synchronized', 'sustainable-catalyst-feature-suggestions') . '</strong><span>' . esc_html($snapshot['github_last_synced_at'] ?: __('Never', 'sustainable-catalyst-feature-suggestions')) . '</span></div>';
        echo '</div></section>';

        echo '<section class="scfs-product-editor__section"><h3>' . esc_html__('Release Console presentation', 'sustainable-catalyst-feature-suggestions') . '</h3><div class="scfs-product-editor__grid">';
        echo '<label><span>' . esc_html__('Console screen', 'sustainable-catalyst-feature-suggestions') . '</span>'; $this->select('product[console_screen]', $record['console_screen'], $this->registry_service()->families()); echo '</label>';
        echo '<label><span>' . esc_html__('Display order', 'sustainable-catalyst-feature-suggestions') . '</span><input type="number" min="0" max="9999" name="product[display_order]" value="' . esc_attr($record['display_order']) . '"></label>';
        echo '<label class="scfs-product-editor__wide"><span>' . esc_html__('Intelligence badges', 'sustainable-catalyst-feature-suggestions') . '</span><input class="large-text" name="product[console_badges]" value="' . esc_attr(implode(', ', (array) ($record['console_badges'] ?? array()))) . '"><small>' . esc_html__('Comma-separated badges shown beneath the product name.', 'sustainable-catalyst-feature-suggestions') . '</small></label>';
        echo '<label class="scfs-product-editor__check"><input type="checkbox" name="product[public_visible]" value="1" ' . checked(!empty($record['public_visible']), true, false) . '> <span>' . esc_html__('Include in public registry', 'sustainable-catalyst-feature-suggestions') . '</span></label>';
        echo '<label class="scfs-product-editor__check"><input type="checkbox" name="product[homepage_visible]" value="1" ' . checked(!empty($record['homepage_visible']), true, false) . '> <span>' . esc_html__('Include in Release Console', 'sustainable-catalyst-feature-suggestions') . '</span></label>';
        echo '</div></section>';

        echo '<section class="scfs-product-editor__section"><h3>' . esc_html__('Public routes and documentation', 'sustainable-catalyst-feature-suggestions') . '</h3><div class="scfs-product-editor__grid">';
        foreach (array('product_url' => __('Product URL', 'sustainable-catalyst-feature-suggestions'), 'documentation_url' => __('Documentation URL', 'sustainable-catalyst-feature-suggestions'), 'support_url' => __('Support URL', 'sustainable-catalyst-feature-suggestions'), 'release_notes_url' => __('Release notes URL', 'sustainable-catalyst-feature-suggestions')) as $key => $label) {
            echo '<label><span>' . esc_html($label) . '</span><input class="large-text" name="product[' . esc_attr($key) . ']" value="' . esc_attr($record[$key]) . '"></label>';
        }
        echo '<label><span>' . esc_html__('Documentation state', 'sustainable-catalyst-feature-suggestions') . '</span>'; $this->select('product[documentation_state]', $record['documentation_state'], $this->registry_service()->documentation_states()); echo '</label>';
        echo '<label><span>' . esc_html__('Validation state', 'sustainable-catalyst-feature-suggestions') . '</span>'; $this->select('product[validation_state]', $record['validation_state'], $this->registry_service()->validation_states()); echo '</label>';
        echo '</div></section>';

        echo '<section class="scfs-product-editor__section"><h3>' . esc_html__('Aliases and administrative context', 'sustainable-catalyst-feature-suggestions') . '</h3><div class="scfs-product-editor__grid">';
        foreach (array('legacy_names' => __('Legacy names', 'sustainable-catalyst-feature-suggestions'), 'legacy_plugin_files' => __('Legacy plugin files', 'sustainable-catalyst-feature-suggestions'), 'legacy_plugin_slugs' => __('Legacy folder slugs', 'sustainable-catalyst-feature-suggestions'), 'legacy_text_domains' => __('Legacy text domains', 'sustainable-catalyst-feature-suggestions')) as $key => $label) {
            echo '<label class="scfs-product-editor__wide"><span>' . esc_html($label) . '</span><input class="large-text code" name="product[' . esc_attr($key) . ']" value="' . esc_attr(implode(', ', (array) $record[$key])) . '"></label>';
        }
        echo '<label class="scfs-product-editor__wide"><span>' . esc_html__('Administrative notes', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="product[manual_notes]" rows="3">' . esc_textarea($record['manual_notes']) . '</textarea></label>';
        echo '</div></section>';

        echo '<div class="scfs-product-editor__save">'; submit_button(__('Save and validate connection', 'sustainable-catalyst-feature-suggestions'), 'primary', 'submit', false); echo '<span data-scfs-dirty-message hidden>' . esc_html__('Unsaved changes', 'sustainable-catalyst-feature-suggestions') . '</span></div>';
        echo '</form>';

        echo '<section class="scfs-product-editor__actions" aria-labelledby="scfs-product-actions-title"><h3 id="scfs-product-actions-title">' . esc_html__('Connection actions', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        $this->action_form('scfs_test_product_connection', $product_id, __('Test connection', 'sustainable-catalyst-feature-suggestions'));
        $this->action_form('scfs_sync_product_connection', $product_id, __('Sync GitHub now', 'sustainable-catalyst-feature-suggestions'));
        $this->action_form('scfs_validate_product_connection', $product_id, __('Validate product record', 'sustainable-catalyst-feature-suggestions'));
        $this->action_form('scfs_remove_product_plugin', $product_id, __('Remove plugin mapping', 'sustainable-catalyst-feature-suggestions'), 'secondary', __('Remove the active plugin mapping for this product?', 'sustainable-catalyst-feature-suggestions'));
        $this->action_form('scfs_remove_product_repository', $product_id, __('Remove GitHub mapping', 'sustainable-catalyst-feature-suggestions'), 'secondary', __('Remove the GitHub repository mapping for this product?', 'sustainable-catalyst-feature-suggestions'));
        $this->action_form('scfs_reset_product_connection', $product_id, __('Reset inherited values', 'sustainable-catalyst-feature-suggestions'), 'secondary', __('Reset editable product values while preserving current plugin and GitHub evidence?', 'sustainable-catalyst-feature-suggestions'));
        echo '</section>';

        if (is_array($validation)) {
            echo '<section class="scfs-product-editor__validation" aria-labelledby="scfs-product-validation-title"><h3 id="scfs-product-validation-title">' . esc_html__('Latest validation', 'sustainable-catalyst-feature-suggestions') . '</h3><p><strong>' . esc_html($validation['valid'] ? __('Valid', 'sustainable-catalyst-feature-suggestions') : __('Needs attention', 'sustainable-catalyst-feature-suggestions')) . '</strong> · ' . esc_html(sprintf(__('%d finding(s)', 'sustainable-catalyst-feature-suggestions'), absint($validation['issue_count'] ?? 0))) . '</p>';
            if (!empty($validation['issues'])) {
                echo '<ul>'; foreach ((array) $validation['issues'] as $issue) { echo '<li class="scfs-product-editor__issue scfs-product-editor__issue--' . esc_attr($issue['severity'] ?? 'notice') . '"><strong>' . esc_html(strtoupper($issue['severity'] ?? 'notice')) . '</strong> ' . esc_html($issue['message'] ?? '') . '</li>'; } echo '</ul>';
            }
            echo '</section>';
        }

        echo '<section class="scfs-product-editor__history" aria-labelledby="scfs-product-history-title"><h3 id="scfs-product-history-title">' . esc_html__('Connection history', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        if (!$history) {
            echo '<p>' . esc_html__('No connection-editor changes have been recorded for this product yet.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        } else {
            echo '<ol>'; foreach ($history as $event) { echo '<li><strong>' . esc_html(str_replace('_', ' ', $event['event'] ?? 'updated')) . '</strong><time datetime="' . esc_attr($event['occurred_at'] ?? '') . '">' . esc_html($event['occurred_at'] ?? '') . '</time></li>'; } echo '</ol>';
        }
        echo '</section>';
        echo '</main></div></div>';
    }

    public function cli_connection($args, $assoc_args) {
        $product_id = sanitize_key($assoc_args['product'] ?? ($args[0] ?? ''));
        $registry = $this->registry();
        if ($product_id === '' || !isset($registry[$product_id])) {
            WP_CLI::error('Use --product=<canonical-id>.');
        }
        $snapshot = $this->connection_snapshot($product_id, $registry[$product_id], $this->discovery_service()->active_plugins_for_mapping());
        $snapshot['validation'] = $this->validate_connection($product_id, $registry[$product_id], $this->discovery_service()->active_plugins_for_mapping());
        WP_CLI::line(wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
