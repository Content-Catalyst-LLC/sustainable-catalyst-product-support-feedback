<?php
/**
 * Plugin Discovery Intelligence.
 *
 * Safely reconciles approved Sustainable Catalyst product records with the
 * WordPress plugins actually installed on the site. Unknown, malformed, and
 * duplicate candidates remain administrator-only and never publish directly.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Installed_Plugin_Discovery {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-installed-plugin-discovery/1.0';
    const DIAGNOSTICS_SCHEMA = 'scfs-plugin-discovery-diagnostics/1.0';
    const SCHEMA_OPTION = 'scfs_installed_plugin_discovery_schema';
    const SNAPSHOT_OPTION = 'scfs_installed_plugin_discovery_snapshot';
    const PENDING_OPTION = 'scfs_installed_plugin_discovery_pending';
    const IGNORED_OPTION = 'scfs_installed_plugin_discovery_ignored';
    const MAPPINGS_OPTION = 'scfs_installed_plugin_discovery_mappings';
    const DIAGNOSTICS_OPTION = 'scfs_installed_plugin_discovery_diagnostics';
    const AUDIT_OPTION = 'scfs_installed_plugin_discovery_audit';
    const CACHE_KEY = 'scfs_installed_plugin_discovery_cache';
    const ADMIN_SLUG = 'scfs-plugin-discovery';
    const DECISION_NONCE = 'scfs_plugin_discovery_decision';
    const BULK_NONCE = 'scfs_plugin_discovery_bulk';
    const MAX_AUDIT_ENTRIES = 250;
    const MAX_DIAGNOSTICS = 250;
    const CACHE_TTL = 900;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'maybe_upgrade'), 4);
        add_action('admin_menu', array($this, 'register_admin_page'), 22);
        add_action('admin_post_scfs_rescan_installed_plugins', array($this, 'rescan_action'));
        add_action('admin_post_scfs_clear_plugin_discovery', array($this, 'clear_action'));
        add_action('admin_post_scfs_plugin_discovery_decision', array($this, 'decision_action'));
        add_action('admin_post_scfs_plugin_discovery_bulk', array($this, 'bulk_action'));
        add_action('admin_post_scfs_save_product_connection', array($this, 'save_product_connection_action'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('activated_plugin', array($this, 'plugin_state_changed'), 10, 2);
        add_action('deactivated_plugin', array($this, 'plugin_state_changed'), 10, 2);
        add_action('deleted_plugin', array($this, 'plugin_deleted'), 10, 2);
        add_action('upgrader_process_complete', array($this, 'upgrader_complete'), 10, 2);
        add_action('scfs_product_registry_updated', array($this, 'registry_changed'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('scfs products discover', array($this, 'cli_discover'));
            WP_CLI::add_command('scfs products discovery-status', array($this, 'cli_status'));
            WP_CLI::add_command('scfs products discovery-diagnostics', array($this, 'cli_diagnostics'));
        }
    }

    public static function activate() {
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
        if (!get_option(self::SNAPSHOT_OPTION)) {
            add_option(self::SNAPSHOT_OPTION, self::instance()->empty_snapshot(), '', false);
        }
        if (!get_option(self::PENDING_OPTION)) {
            add_option(self::PENDING_OPTION, array(), '', false);
        }
        if (!get_option(self::DIAGNOSTICS_OPTION)) {
            add_option(self::DIAGNOSTICS_OPTION, self::instance()->empty_diagnostics(), '', false);
        }
        if (!get_option(self::IGNORED_OPTION)) {
            add_option(self::IGNORED_OPTION, array(), '', false);
        }
        if (!get_option(self::MAPPINGS_OPTION)) {
            add_option(self::MAPPINGS_OPTION, array(), '', false);
        }
        if (!get_option(self::AUDIT_OPTION)) {
            add_option(self::AUDIT_OPTION, array(), '', false);
        }
        self::instance()->invalidate_cache('activation');
    }

    public function maybe_upgrade() {
        if (get_option(self::SCHEMA_OPTION) === self::SCHEMA
            && is_array(get_option(self::DIAGNOSTICS_OPTION))
            && is_array(get_option(self::IGNORED_OPTION))
            && is_array(get_option(self::MAPPINGS_OPTION))) {
            return;
        }
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
        if (!is_array(get_option(self::SNAPSHOT_OPTION))) {
            update_option(self::SNAPSHOT_OPTION, $this->empty_snapshot(), false);
        }
        if (!is_array(get_option(self::PENDING_OPTION))) {
            update_option(self::PENDING_OPTION, array(), false);
        }
        if (!is_array(get_option(self::DIAGNOSTICS_OPTION))) {
            update_option(self::DIAGNOSTICS_OPTION, $this->empty_diagnostics(), false);
        }
        if (!is_array(get_option(self::IGNORED_OPTION))) {
            update_option(self::IGNORED_OPTION, array(), false);
        }
        if (!is_array(get_option(self::MAPPINGS_OPTION))) {
            update_option(self::MAPPINGS_OPTION, array(), false);
        }
        $this->invalidate_cache('schema_upgrade');
        $this->audit('discovery_schema_upgraded', array('schema' => self::SCHEMA, 'version' => self::VERSION));
    }

    public function capability() {
        return apply_filters('scfs_plugin_discovery_capability', 'manage_options');
    }

    public function can_manage() {
        return current_user_can($this->capability());
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'diagnostics_schema' => self::DIAGNOSTICS_SCHEMA,
            'version' => self::VERSION,
            'source' => 'installed_wordpress_plugins',
            'registry_authority' => 'scfs_canonical_product_registry',
            'matching_hierarchy' => array(
                'administrator_mapping',
                'exact_plugin_file',
                'legacy_plugin_file',
                'sc_product_id_header',
                'sc_product_header',
                'plugin_slug',
                'legacy_plugin_slug',
                'text_domain',
                'legacy_text_domain',
                'plugin_repository_url',
                'approved_name_alias',
            ),
            'approved_registry_only' => true,
            'unknown_plugins_auto_registered' => false,
            'unknown_plugins_publicly_exposed' => false,
            'absolute_plugin_paths_publicly_exposed' => false,
            'automatic_publication' => false,
            'manual_overrides_preserved' => true,
            'product_lock_supported' => true,
            'deterministic_duplicate_resolution' => true,
            'legacy_identifiers_supported' => true,
            'version_normalization' => true,
            'malformed_headers_quarantined' => true,
            'multisite_activation_scope' => true,
            'cache_ttl_seconds' => self::CACHE_TTL,
            'human_review_required' => true,
            'actionable_candidate_rows' => true,
            'zero_state_message' => 'No plugins awaiting review',
            'stale_status_reconciled' => true,
            'canonical_product_dropdown_mapping' => true,
            'all_active_plugins_selectable' => true,
            'canonical_plugin_github_console_connection' => true,
            'administrator_mapping_audit' => true,
            'ignored_plugin_restore' => true,
            'ajax_progressive_enhancement' => true,
            'alias_collision_protection' => true,
            'release_operations_integrity_source' => true,
            'active_mapping_stale_detection' => true,
            'rescan_clears_stale_status' => true,
            'complete_plugin_inventory' => true,
            'site_network_inactive_classification' => true,
            'must_use_plugin_detection' => true,
            'dropin_detection' => true,
            'confidence_ranked_suggestions' => true,
            'matching_signal_explanation' => true,
            'bulk_map_suggested' => true,
            'bulk_ignore' => true,
            'duplicate_mapping_detection' => true,
            'installed_vs_github_version_comparison' => true,
            'plugin_header_repository_consistency' => true,
            'inventory_search_and_filters' => true,
        );
    }

    private function empty_snapshot() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'scanned_at' => '',
            'reason' => 'not_scanned',
            'installed_plugin_count' => 0,
            'matched_product_count' => 0,
            'pending_candidate_count' => 0,
            'unmatched_candidate_count' => 0,
            'duplicate_candidate_count' => 0,
            'ignored_candidate_count' => 0,
            'manual_mapping_count' => 0,
            'active_match_count' => 0,
            'inactive_match_count' => 0,
            'network_active_match_count' => 0,
            'diagnostic_count' => 0,
            'standard_plugin_count' => 0,
            'site_active_plugin_count' => 0,
            'network_active_plugin_count' => 0,
            'both_active_plugin_count' => 0,
            'inactive_plugin_count' => 0,
            'must_use_plugin_count' => 0,
            'dropin_count' => 0,
            'update_available_count' => 0,
            'ahead_of_release_count' => 0,
            'unknown_version_count' => 0,
            'matches' => array(),
            'inventory' => array(),
        );
    }

    private function empty_diagnostics() {
        return array(
            'schema' => self::DIAGNOSTICS_SCHEMA,
            'version' => self::VERSION,
            'generated_at' => '',
            'error_count' => 0,
            'warning_count' => 0,
            'info_count' => 0,
            'items' => array(),
        );
    }

    private function normalize_pending_candidates($pending) {
        $normalized = array();
        foreach ((array) $pending as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $plugin_file = $this->normalize_plugin_file($candidate['plugin_file'] ?? '');
            $review_state = sanitize_key($candidate['review_state'] ?? 'pending');
            if ($plugin_file === '' || !in_array($review_state, array('pending', 'malformed_header', 'duplicate_match'), true)) {
                continue;
            }
            $candidate['plugin_file'] = $plugin_file;
            $candidate['review_state'] = $review_state;
            $normalized[$plugin_file . '|' . $review_state] = $candidate;
        }
        return array_values($normalized);
    }

    public function pending_candidates() {
        return $this->normalize_pending_candidates(get_option(self::PENDING_OPTION, array()));
    }

    public function unmatched_candidates() {
        return array_values(array_filter($this->pending_candidates(), function ($candidate) {
            return ($candidate['review_state'] ?? 'pending') !== 'duplicate_match';
        }));
    }

    public function duplicate_candidates() {
        return array_values(array_filter($this->pending_candidates(), function ($candidate) {
            return ($candidate['review_state'] ?? '') === 'duplicate_match';
        }));
    }

    private function normalize_ignored_candidates($ignored) {
        $normalized = array();
        foreach ((array) $ignored as $key => $candidate) {
            if (!is_array($candidate)) {
                $candidate = array('plugin_file' => is_string($key) ? $key : '');
            }
            $plugin_file = $this->normalize_plugin_file($candidate['plugin_file'] ?? (is_string($key) ? $key : ''));
            if ($plugin_file === '') {
                continue;
            }
            $candidate['plugin_file'] = $plugin_file;
            $candidate['ignored_at'] = sanitize_text_field($candidate['ignored_at'] ?? '');
            $candidate['ignored_by'] = sanitize_text_field($candidate['ignored_by'] ?? '');
            $normalized[$plugin_file] = $candidate;
        }
        ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);
        return $normalized;
    }

    public function ignored_candidates() {
        return array_values($this->normalize_ignored_candidates(get_option(self::IGNORED_OPTION, array())));
    }

    private function ignored_candidate_map() {
        return $this->normalize_ignored_candidates(get_option(self::IGNORED_OPTION, array()));
    }

    public function manual_mappings() {
        $registry = SCFS_Canonical_Product_Registry::instance()->registry();
        $normalized = array();
        foreach ((array) get_option(self::MAPPINGS_OPTION, array()) as $plugin_file => $mapping) {
            if (!is_array($mapping)) {
                continue;
            }
            $plugin_file = $this->normalize_plugin_file($mapping['plugin_file'] ?? $plugin_file);
            $product_id = sanitize_key($mapping['product_id'] ?? '');
            if ($plugin_file === '' || $product_id === '' || !isset($registry[$product_id])) {
                continue;
            }
            $mapping['plugin_file'] = $plugin_file;
            $mapping['product_id'] = $product_id;
            $mapping['mapped_at'] = sanitize_text_field($mapping['mapped_at'] ?? '');
            $mapping['mapped_by'] = sanitize_text_field($mapping['mapped_by'] ?? '');
            $normalized[$plugin_file] = $mapping;
        }
        ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);
        return $normalized;
    }

    private function pending_candidate($plugin_file) {
        $plugin_file = $this->normalize_plugin_file($plugin_file);
        foreach ($this->pending_candidates() as $candidate) {
            if (($candidate['plugin_file'] ?? '') === $plugin_file) {
                return $candidate;
            }
        }
        return null;
    }

    private function plugin_directory_slug($plugin_file) {
        $plugin_file = $this->normalize_plugin_file($plugin_file);
        if ($plugin_file === '') {
            return '';
        }
        $directory = dirname($plugin_file);
        return sanitize_key($directory === '.' ? pathinfo($plugin_file, PATHINFO_FILENAME) : $directory);
    }

    private function actor_label() {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        return $user && $user->exists() ? $user->user_login : 'system';
    }

    private function mapping_products() {
        $products = array();
        foreach (SCFS_Canonical_Product_Registry::instance()->registry() as $product_id => $record) {
            if (in_array($record['lifecycle_state'] ?? 'active', array('retired', 'superseded'), true)) {
                continue;
            }
            $products[$product_id] = $record;
        }
        uasort($products, function ($left, $right) {
            return strcasecmp($left['name'] ?? '', $right['name'] ?? '');
        });
        return $products;
    }

    public function active_plugins_for_mapping() {
        $active = array();
        foreach ($this->installed_plugins() as $plugin_file => $plugin) {
            if (!empty($plugin['active'])) {
                $active[$plugin_file] = $plugin;
            }
        }
        uasort($active, function ($left, $right) {
            return strcasecmp($left['name'] ?? '', $right['name'] ?? '');
        });
        return $active;
    }

    public function snapshot() {
        $snapshot = get_option(self::SNAPSHOT_OPTION, array());
        $snapshot = is_array($snapshot) ? array_merge($this->empty_snapshot(), $snapshot) : $this->empty_snapshot();
        $snapshot['pending_candidate_count'] = count($this->pending_candidates());
        $snapshot['unmatched_candidate_count'] = count($this->unmatched_candidates());
        $snapshot['duplicate_candidate_count'] = count($this->duplicate_candidates());
        $snapshot['ignored_candidate_count'] = count($this->ignored_candidates());
        $snapshot['manual_mapping_count'] = count($this->manual_mappings());
        return $snapshot;
    }

    public function diagnostics() {
        $diagnostics = get_option(self::DIAGNOSTICS_OPTION, array());
        return is_array($diagnostics) ? array_merge($this->empty_diagnostics(), $diagnostics) : $this->empty_diagnostics();
    }

    public function summary_record() {
        $snapshot = $this->snapshot();
        $diagnostics = $this->diagnostics();
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'scanned_at' => $snapshot['scanned_at'],
            'installed_plugin_count' => absint($snapshot['installed_plugin_count']),
            'matched_product_count' => absint($snapshot['matched_product_count']),
            'pending_candidate_count' => count($this->pending_candidates()),
            'unmatched_candidate_count' => count($this->unmatched_candidates()),
            'duplicate_candidate_count' => count($this->duplicate_candidates()),
            'ignored_candidate_count' => count($this->ignored_candidates()),
            'manual_mapping_count' => count($this->manual_mappings()),
            'active_match_count' => absint($snapshot['active_match_count']),
            'inactive_match_count' => absint($snapshot['inactive_match_count']),
            'network_active_match_count' => absint($snapshot['network_active_match_count']),
            'diagnostic_count' => count((array) $diagnostics['items']),
            'diagnostic_errors' => absint($diagnostics['error_count']),
            'diagnostic_warnings' => absint($diagnostics['warning_count']),
            'standard_plugin_count' => absint($snapshot['standard_plugin_count']),
            'must_use_plugin_count' => absint($snapshot['must_use_plugin_count']),
            'dropin_count' => absint($snapshot['dropin_count']),
            'update_available_count' => absint($snapshot['update_available_count']),
            'ahead_of_release_count' => absint($snapshot['ahead_of_release_count']),
            'unknown_version_count' => absint($snapshot['unknown_version_count']),
            'cache_ttl_seconds' => self::CACHE_TTL,
            'human_review_required' => true,
        );
    }

    public function invalidate_cache($reason = 'manual') {
        delete_transient(self::CACHE_KEY);
        do_action('scfs_plugin_discovery_cache_invalidated', sanitize_key($reason));
    }

    public function plugin_state_changed($plugin = '', $network_wide = false) {
        $this->invalidate_cache('plugin_state_changed');
        $this->refresh(true, $network_wide ? 'network_plugin_state_changed' : 'plugin_state_changed');
    }

    public function plugin_deleted($plugin_file = '', $deleted = false) {
        if ($deleted) {
            $this->invalidate_cache('plugin_deleted');
            $this->refresh(true, 'plugin_deleted');
        }
    }

    public function upgrader_complete($upgrader, $options) {
        if (!is_array($options) || ($options['type'] ?? '') !== 'plugin') {
            return;
        }
        $this->invalidate_cache('plugin_upgrade');
        $this->refresh(true, 'plugin_upgrade');
    }

    public function registry_changed($registry = array()) {
        $this->invalidate_cache('registry_changed');
    }

    private function load_plugin_api() {
        if (!function_exists('get_plugins') && defined('ABSPATH')) {
            $plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_file($plugin_api)) {
                require_once $plugin_api;
            }
        }
        return function_exists('get_plugins');
    }

    private function normalize_plugin_file($plugin_file) {
        $plugin_file = str_replace('\\', '/', (string) $plugin_file);
        $plugin_file = ltrim($plugin_file, '/');
        return preg_replace('#/+#', '/', $plugin_file);
    }

    private function active_plugin_scopes() {
        $scopes = array();
        foreach ((array) get_option('active_plugins', array()) as $plugin_file) {
            $plugin_file = $this->normalize_plugin_file($plugin_file);
            if ($plugin_file !== '') {
                $scopes[$plugin_file] = 'site';
            }
        }
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', array());
            foreach (array_keys($network) as $plugin_file) {
                $plugin_file = $this->normalize_plugin_file($plugin_file);
                if ($plugin_file === '') {
                    continue;
                }
                $scopes[$plugin_file] = isset($scopes[$plugin_file]) ? 'both' : 'network';
            }
        }
        return $scopes;
    }

    private function custom_headers($plugin_file, $plugin_type = 'standard') {
        $headers = array(
            'SCProductID' => 'SC Product ID',
            'SCProduct' => 'SC Product',
            'SCPublicRelease' => 'SC Public Release',
        );
        $full_path = '';
        if ($plugin_type === 'must-use' && defined('WPMU_PLUGIN_DIR')) {
            $full_path = rtrim(WPMU_PLUGIN_DIR, '/\\') . '/' . basename($plugin_file);
        } elseif ($plugin_type === 'drop-in' && defined('WP_CONTENT_DIR')) {
            $full_path = rtrim(WP_CONTENT_DIR, '/\\') . '/' . basename($plugin_file);
        } elseif (defined('WP_PLUGIN_DIR')) {
            $full_path = rtrim(WP_PLUGIN_DIR, '/\\') . '/' . $plugin_file;
        }
        if (!$full_path || !is_file($full_path) || !function_exists('get_file_data')) {
            return array_fill_keys(array_keys($headers), '');
        }
        $data = get_file_data($full_path, $headers, 'plugin');
        return is_array($data) ? $data : array_fill_keys(array_keys($headers), '');
    }

    private function clean_header($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function normalize_version($value) {
        $raw = $this->clean_header($value);
        if ($raw === '') {
            return array('raw' => '', 'normalized' => '', 'state' => 'missing');
        }
        $normalized = preg_replace('/^v(?=\d)/i', '', $raw);
        $normalized = preg_replace('/\s+/', '', $normalized);
        if (!preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $normalized)) {
            return array('raw' => $raw, 'normalized' => '', 'state' => 'malformed');
        }
        $state = preg_match('/(?:^|[-.])(dev|alpha|beta|rc|preview)(?:[.-]|$)/i', $normalized) ? 'development' : 'valid';
        return array('raw' => $raw, 'normalized' => $normalized, 'state' => $state);
    }

    private function plugin_record($plugin_file, $metadata, $plugin_type, $scope) {
        $plugin_file = $this->normalize_plugin_file($plugin_file);
        $custom = $this->custom_headers($plugin_file, $plugin_type);
        $directory = dirname($plugin_file);
        $directory_slug = sanitize_key($plugin_type === 'standard' && $directory !== '.' ? basename($directory) : pathinfo($plugin_file, PATHINFO_FILENAME));
        $name = $this->clean_header($metadata['Name'] ?? '');
        $version = $this->normalize_version($metadata['Version'] ?? '');
        return array(
            'plugin_file' => $plugin_file,
            'directory_slug' => $directory_slug,
            'name' => $name,
            'name_state' => $name === '' ? 'missing' : 'valid',
            'version' => $version['normalized'],
            'version_raw' => $version['raw'],
            'version_state' => $version['state'],
            'author' => $this->clean_header($metadata['AuthorName'] ?? ($metadata['Author'] ?? '')),
            'text_domain' => sanitize_key($metadata['TextDomain'] ?? ''),
            'plugin_uri' => esc_url_raw($metadata['PluginURI'] ?? ''),
            'active' => $scope !== 'inactive',
            'activation_scope' => $scope,
            'plugin_type' => $plugin_type,
            'sc_product_id' => sanitize_key($custom['SCProductID'] ?? ''),
            'sc_product' => $this->clean_header($custom['SCProduct'] ?? ''),
            'sc_public_release' => $this->clean_header($custom['SCPublicRelease'] ?? ''),
        );
    }

    private function installed_plugins() {
        if (!$this->load_plugin_api()) {
            return array();
        }
        $scopes = $this->active_plugin_scopes();
        $plugins = array();
        foreach ((array) get_plugins() as $plugin_file => $metadata) {
            $plugin_file = $this->normalize_plugin_file($plugin_file);
            if ($plugin_file === '') {
                continue;
            }
            $scope = isset($scopes[$plugin_file]) ? $scopes[$plugin_file] : 'inactive';
            $plugins[$plugin_file] = $this->plugin_record($plugin_file, $metadata, 'standard', $scope);
        }
        if (function_exists('get_mu_plugins')) {
            foreach ((array) get_mu_plugins() as $plugin_file => $metadata) {
                $key = 'mu-plugins/' . basename($this->normalize_plugin_file($plugin_file));
                $plugins[$key] = $this->plugin_record($key, $metadata, 'must-use', 'network');
            }
        }
        if (function_exists('get_dropins')) {
            foreach ((array) get_dropins() as $plugin_file => $metadata) {
                $key = 'drop-ins/' . basename($this->normalize_plugin_file($plugin_file));
                $plugins[$key] = $this->plugin_record($key, $metadata, 'drop-in', 'network');
            }
        }
        ksort($plugins, SORT_NATURAL | SORT_FLAG_CASE);
        return $plugins;
    }

    public function plugin_inventory() {
        $snapshot = $this->snapshot();
        if (!empty($snapshot['inventory']) && is_array($snapshot['inventory'])) {
            return $snapshot['inventory'];
        }
        $this->refresh(true, 'inventory_requested');
        $snapshot = $this->snapshot();
        return (array) ($snapshot['inventory'] ?? array());
    }

    private function normalize_name($name) {
        $name = strtolower(wp_strip_all_tags((string) $name));
        return preg_replace('/[^a-z0-9]+/', '', $name);
    }

    private function normalize_identifier_list($values, $type = 'slug') {
        $out = array();
        foreach ((array) $values as $value) {
            $value = $type === 'file' ? $this->normalize_plugin_file($value) : sanitize_key($value);
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }

    private function approved_aliases($record) {
        $aliases = array($record['name'] ?? '', $record['short_name'] ?? '');
        $aliases = array_merge($aliases, (array) ($record['legacy_names'] ?? array()));
        return array_values(array_unique(array_filter(array_map(array($this, 'normalize_name'), $aliases))));
    }

    private function normalize_repository_identity($url) {
        $url = strtolower(trim((string) $url));
        if ($url === '') {
            return '';
        }
        $url = preg_replace('#^git@github\.com:#', '', $url);
        $url = preg_replace('#^https?://github\.com/#', '', $url);
        $url = preg_replace('#\.git$#', '', $url);
        return trim($url, '/');
    }

    private function suggestion_candidates($plugin, $registry) {
        $suggestions = array();
        $manual = $this->manual_mappings();
        foreach ($registry as $product_id => $record) {
            if (empty($record['discovery_enabled'])) {
                continue;
            }
            $signals = array();
            $score = 0;
            $add = function ($value, $signal) use (&$signals, &$score) {
                if ($value > $score) {
                    $score = $value;
                }
                if ($value > 0) {
                    $signals[$signal] = $value;
                }
            };
            if (isset($manual[$plugin['plugin_file']]) && sanitize_key($manual[$plugin['plugin_file']]['product_id'] ?? '') === $product_id) {
                $add(100, 'administrator_mapping');
            }
            $configured_file = $this->normalize_plugin_file($record['plugin_file'] ?? '');
            if ($configured_file !== '' && hash_equals($configured_file, $plugin['plugin_file'])) {
                $add(100, 'exact_plugin_file');
            }
            if (in_array($plugin['plugin_file'], $this->normalize_identifier_list($record['legacy_plugin_files'] ?? array(), 'file'), true)) {
                $add(99, 'legacy_plugin_file');
            }
            if (!empty($plugin['sc_product_id']) && hash_equals($product_id, $plugin['sc_product_id'])) {
                $add(98, 'sc_product_id_header');
            }
            if (!empty($plugin['sc_product']) && in_array($this->normalize_name($plugin['sc_product']), $this->approved_aliases($record), true)) {
                $add(96, 'sc_product_header');
            }
            $slug = sanitize_key($record['plugin_slug'] ?? '');
            if ($slug !== '' && hash_equals($slug, $plugin['directory_slug'])) {
                $add(95, 'plugin_slug');
            }
            if (in_array($plugin['directory_slug'], $this->normalize_identifier_list($record['legacy_plugin_slugs'] ?? array()), true)) {
                $add(94, 'legacy_plugin_slug');
            }
            $text_domain = sanitize_key($record['plugin_text_domain'] ?? ($record['plugin_slug'] ?? ''));
            if ($text_domain !== '' && !empty($plugin['text_domain']) && hash_equals($text_domain, $plugin['text_domain'])) {
                $add(90, 'text_domain');
            }
            if (!empty($plugin['text_domain']) && in_array($plugin['text_domain'], $this->normalize_identifier_list($record['legacy_text_domains'] ?? array()), true)) {
                $add(89, 'legacy_text_domain');
            }
            $plugin_repo = $this->normalize_repository_identity($plugin['plugin_uri'] ?? '');
            $product_repo = $this->normalize_repository_identity($record['github_repository_url'] ?? '');
            if ($plugin_repo !== '' && $product_repo !== '' && hash_equals($product_repo, $plugin_repo)) {
                $add(86, 'plugin_repository_url');
            }
            $approved_author = stripos($plugin['author'] ?? '', 'Content Catalyst') !== false;
            if ($approved_author && ($plugin['name_state'] ?? '') === 'valid' && in_array($this->normalize_name($plugin['name']), $this->approved_aliases($record), true)) {
                $add(80, 'approved_name_alias');
            }
            if ($score > 0) {
                arsort($signals, SORT_NUMERIC);
                $suggestions[] = array(
                    'product_id' => $product_id,
                    'name' => sanitize_text_field($record['name'] ?? $product_id),
                    'confidence' => $score,
                    'signals' => array_keys($signals),
                    'primary_signal' => (string) array_key_first($signals),
                );
            }
        }
        usort($suggestions, function ($left, $right) {
            if ($left['confidence'] !== $right['confidence']) {
                return $right['confidence'] <=> $left['confidence'];
            }
            return strcasecmp($left['name'], $right['name']);
        });
        return array_slice($suggestions, 0, 5);
    }

    private function match_plugin($plugin, $registry) {
        $suggestions = $this->suggestion_candidates($plugin, $registry);
        if (!$suggestions || $suggestions[0]['confidence'] < 80) {
            return null;
        }
        if (isset($suggestions[1]) && $suggestions[1]['confidence'] === $suggestions[0]['confidence']) {
            return null;
        }
        $top = $suggestions[0];
        return array(
            'product_id' => $top['product_id'],
            'strategy' => $top['primary_signal'],
            'confidence' => absint($top['confidence']),
            'legacy' => strpos($top['primary_signal'], 'legacy_') === 0,
            'signals' => $top['signals'],
        );
    }

    private function compare_plugin_versions($installed, $latest) {
        $installed = trim((string) $installed);
        $latest = trim((string) $latest);
        if ($installed === '' || $latest === '') {
            return 'unknown';
        }
        if (version_compare($installed, $latest, '<')) {
            return 'update_available';
        }
        if (version_compare($installed, $latest, '>')) {
            return 'ahead';
        }
        return 'current';
    }

    private function catalyst_candidate($plugin) {
        return !empty($plugin['sc_product_id'])
            || !empty($plugin['sc_product'])
            || stripos($plugin['author'], 'Content Catalyst') !== false
            || strpos($plugin['text_domain'], 'sustainable-catalyst') === 0
            || strpos($plugin['text_domain'], 'catalyst-') === 0
            || strpos($plugin['directory_slug'], 'sustainable-catalyst') === 0
            || strpos($plugin['directory_slug'], 'catalyst-') === 0;
    }

    private function diagnostic($level, $code, $message, $plugin = array(), $product_id = '') {
        return array(
            'level' => in_array($level, array('error', 'warning', 'info'), true) ? $level : 'info',
            'code' => sanitize_key($code),
            'product_id' => sanitize_key($product_id),
            'plugin_file' => $this->normalize_plugin_file($plugin['plugin_file'] ?? ''),
            'message' => sanitize_text_field($message),
        );
    }

    private function registry_alias_diagnostics($registry) {
        $owners = array();
        $items = array();
        foreach ($registry as $product_id => $record) {
            if (empty($record['discovery_enabled'])) {
                continue;
            }
            foreach ($this->approved_aliases($record) as $alias) {
                if (isset($owners[$alias]) && $owners[$alias] !== $product_id) {
                    $items[] = $this->diagnostic('warning', 'registry_alias_collision', 'The same approved name alias is assigned to more than one product.', array(), $product_id);
                } else {
                    $owners[$alias] = $product_id;
                }
            }
        }
        return $items;
    }

    private function manual_mapping_diagnostics() {
        $by_product = array();
        $items = array();
        foreach ($this->manual_mappings() as $plugin_file => $mapping) {
            $product_id = sanitize_key($mapping['product_id'] ?? '');
            if ($product_id !== '') {
                $by_product[$product_id][] = $plugin_file;
            }
        }
        foreach ($by_product as $product_id => $plugin_files) {
            if (count($plugin_files) > 1) {
                $items[] = $this->diagnostic('warning', 'multiple_plugins_mapped_to_product', 'Multiple installed plugins are manually mapped to the same canonical product.', array('plugin_file' => implode(', ', $plugin_files)), $product_id);
            }
        }
        return $items;
    }

    private function candidate_score($candidate) {
        $state_score = array('valid' => 3, 'development' => 2, 'missing' => 1, 'malformed' => 0);
        return array(
            absint($candidate['confidence']),
            !empty($candidate['active']) ? 1 : 0,
            $state_score[$candidate['version_state']] ?? 0,
        );
    }

    private function compare_candidates($left, $right) {
        $left_score = $this->candidate_score($left);
        $right_score = $this->candidate_score($right);
        for ($i = 0; $i < count($left_score); $i++) {
            if ($left_score[$i] !== $right_score[$i]) {
                return $right_score[$i] <=> $left_score[$i];
            }
        }
        return strcasecmp($left['plugin_file'], $right['plugin_file']);
    }

    private function diagnostics_record($items, $generated_at) {
        $items = array_slice(array_values($items), 0, self::MAX_DIAGNOSTICS);
        $counts = array('error' => 0, 'warning' => 0, 'info' => 0);
        foreach ($items as $item) {
            $level = $item['level'] ?? 'info';
            if (isset($counts[$level])) {
                $counts[$level]++;
            }
        }
        return array(
            'schema' => self::DIAGNOSTICS_SCHEMA,
            'version' => self::VERSION,
            'generated_at' => $generated_at,
            'error_count' => $counts['error'],
            'warning_count' => $counts['warning'],
            'info_count' => $counts['info'],
            'items' => $items,
        );
    }

    public function refresh($force = false, $reason = 'manual') {
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $plugins = $this->installed_plugins();
        $ignored = $this->ignored_candidate_map();
        $installed_files = array_fill_keys(array_keys($plugins), true);
        $pruned_ignored = array_intersect_key($ignored, $installed_files);
        if (count($pruned_ignored) !== count($ignored)) {
            update_option(self::IGNORED_OPTION, $pruned_ignored, false);
            $ignored = $pruned_ignored;
        }
        $registry_service = SCFS_Canonical_Product_Registry::instance();
        $registry = $registry_service->registry();
        $candidate_groups = array();
        $matches = array();
        $pending = array();
        $diagnostics = array_merge($this->registry_alias_diagnostics($registry), $this->manual_mapping_diagnostics());
        $active_count = 0;
        $inactive_count = 0;
        $network_count = 0;
        $scanned_at = gmdate('c');

        foreach ($plugins as $plugin) {
            if (isset($ignored[$plugin['plugin_file']])) {
                continue;
            }
            if ($plugin['name_state'] === 'missing' && $this->catalyst_candidate($plugin)) {
                $diagnostics[] = $this->diagnostic('warning', 'plugin_name_missing', 'The plugin header does not provide a usable Plugin Name.', $plugin);
            }
            if ($plugin['version_state'] === 'missing' && $this->catalyst_candidate($plugin)) {
                $diagnostics[] = $this->diagnostic('warning', 'plugin_version_missing', 'The plugin header does not provide a version.', $plugin);
            } elseif ($plugin['version_state'] === 'malformed' && $this->catalyst_candidate($plugin)) {
                $diagnostics[] = $this->diagnostic('warning', 'plugin_version_malformed', 'The plugin version is malformed and will not update release information.', $plugin);
            } elseif ($plugin['version_state'] === 'development' && $this->catalyst_candidate($plugin)) {
                $diagnostics[] = $this->diagnostic('info', 'development_version_detected', 'A development or prerelease plugin version was detected.', $plugin);
            }

            $suggestions = $this->suggestion_candidates($plugin, $registry);
            $match = $this->match_plugin($plugin, $registry);
            if (!$match) {
                if ($this->catalyst_candidate($plugin)) {
                    $top_suggestion = $suggestions ? $suggestions[0] : array();
                    $pending[] = array(
                        'plugin_file' => $plugin['plugin_file'],
                        'directory_slug' => $plugin['directory_slug'],
                        'name' => $plugin['name'] !== '' ? $plugin['name'] : $plugin['directory_slug'],
                        'version' => $plugin['version'],
                        'version_raw' => $plugin['version_raw'],
                        'version_state' => $plugin['version_state'],
                        'author' => $plugin['author'],
                        'text_domain' => $plugin['text_domain'],
                        'active' => (bool) $plugin['active'],
                        'activation_scope' => $plugin['activation_scope'],
                        'sc_product_id' => $plugin['sc_product_id'],
                        'sc_product' => $plugin['sc_product'],
                        'sc_public_release' => $plugin['sc_public_release'],
                        'suggested_product_id' => sanitize_key($top_suggestion['product_id'] ?? $plugin['sc_product_id']),
                        'suggested_confidence' => absint($top_suggestion['confidence'] ?? 0),
                        'suggestion_signals' => (array) ($top_suggestion['signals'] ?? array()),
                        'suggestions' => $suggestions,
                        'plugin_type' => sanitize_key($plugin['plugin_type'] ?? 'standard'),
                        'plugin_uri' => esc_url_raw($plugin['plugin_uri'] ?? ''),
                        'review_state' => $plugin['name_state'] === 'missing' || $plugin['version_state'] === 'malformed' ? 'malformed_header' : 'pending',
                    );
                }
                continue;
            }
            $product_id = $match['product_id'];
            $candidate_groups[$product_id][] = array_merge($plugin, array(
                'product_id' => $product_id,
                'match_strategy' => $match['strategy'],
                'confidence' => absint($match['confidence']),
                'legacy_match' => !empty($match['legacy']),
                'match_signals' => (array) ($match['signals'] ?? array($match['strategy'])),
            ));
        }

        foreach ($candidate_groups as $product_id => $candidates) {
            usort($candidates, array($this, 'compare_candidates'));
            $winner = array_shift($candidates);
            if ($winner['legacy_match']) {
                $diagnostics[] = $this->diagnostic('info', 'legacy_identifier_match', 'A configured legacy plugin identifier was used to match this product.', $winner, $product_id);
            }
            $record = $registry[$product_id] ?? array();
            if ($winner['name_state'] === 'valid' && $this->normalize_name($winner['name']) !== $this->normalize_name($record['name'] ?? '')) {
                $diagnostics[] = $this->diagnostic('info', 'plugin_display_name_changed', 'The installed plugin display name differs from the canonical product name; the canonical public name is preserved.', $winner, $product_id);
            }
            if (!empty($winner['sc_product_id']) && $winner['sc_product_id'] !== $product_id) {
                $diagnostics[] = $this->diagnostic('warning', 'plugin_header_product_mismatch', 'The SC Product ID plugin header points to a different canonical product.', $winner, $product_id);
            }
            $plugin_repository = $this->normalize_repository_identity($winner['plugin_uri'] ?? '');
            $canonical_repository = $this->normalize_repository_identity($record['github_repository_url'] ?? '');
            if ($plugin_repository !== '' && $canonical_repository !== '' && $plugin_repository !== $canonical_repository) {
                $diagnostics[] = $this->diagnostic('warning', 'plugin_repository_mismatch', 'The plugin header repository does not match the canonical GitHub repository.', $winner, $product_id);
            }
            foreach ($candidates as $duplicate) {
                $pending[] = array(
                    'plugin_file' => $duplicate['plugin_file'],
                    'directory_slug' => $duplicate['directory_slug'],
                    'name' => $duplicate['name'] !== '' ? $duplicate['name'] : $duplicate['directory_slug'],
                    'version' => $duplicate['version'],
                    'version_raw' => $duplicate['version_raw'],
                    'version_state' => $duplicate['version_state'],
                    'author' => $duplicate['author'],
                    'text_domain' => $duplicate['text_domain'],
                    'active' => (bool) $duplicate['active'],
                    'activation_scope' => $duplicate['activation_scope'],
                    'sc_product_id' => $duplicate['sc_product_id'],
                    'sc_product' => $duplicate['sc_product'],
                    'sc_public_release' => $duplicate['sc_public_release'],
                    'plugin_type' => sanitize_key($duplicate['plugin_type'] ?? 'standard'),
                    'plugin_uri' => esc_url_raw($duplicate['plugin_uri'] ?? ''),
                    'suggested_product_id' => $product_id,
                    'suggested_confidence' => absint($duplicate['confidence']),
                    'suggestion_signals' => (array) ($duplicate['match_signals'] ?? array($duplicate['match_strategy'])),
                    'suggestions' => $this->suggestion_candidates($duplicate, $registry),
                    'selected_plugin_file' => $winner['plugin_file'],
                    'review_state' => 'duplicate_match',
                );
                $diagnostics[] = $this->diagnostic('warning', 'duplicate_product_match', 'Multiple plugins matched the same canonical product; the highest-confidence deterministic match was selected.', $duplicate, $product_id);
            }
            $winner['active'] ? $active_count++ : $inactive_count++;
            if (in_array($winner['activation_scope'], array('network', 'both'), true)) {
                $network_count++;
            }
            $matches[$product_id] = array(
                'product_id' => $product_id,
                'plugin_file' => $winner['plugin_file'],
                'plugin_name' => $winner['name'] !== '' ? $winner['name'] : $winner['directory_slug'],
                'version' => $winner['version'],
                'version_raw' => $winner['version_raw'],
                'version_state' => $winner['version_state'],
                'text_domain' => $winner['text_domain'],
                'active' => (bool) $winner['active'],
                'activation_scope' => $winner['activation_scope'],
                'match_strategy' => $winner['match_strategy'],
                'confidence' => absint($winner['confidence']),
                'legacy_match' => (bool) $winner['legacy_match'],
                'match_signals' => (array) ($winner['match_signals'] ?? array($winner['match_strategy'])),
                'plugin_type' => sanitize_key($winner['plugin_type'] ?? 'standard'),
                'plugin_uri' => esc_url_raw($winner['plugin_uri'] ?? ''),
                'latest_version' => trim((string) ($record['github_latest_version'] ?? ($record['public_version'] ?? ''))),
                'version_comparison' => $this->compare_plugin_versions($winner['version'], $record['github_latest_version'] ?? ($record['public_version'] ?? '')),
                'discovered_at' => $scanned_at,
            );
        }

        foreach ($registry as $product_id => &$record) {
            if (empty($record['discovery_enabled'])) {
                continue;
            }
            if (!isset($matches[$product_id])) {
                $record['discovery_state'] = 'not_found';
                $record['discovery_match'] = '';
                $record['discovered_active'] = '';
                $record['discovered_activation_scope'] = 'inactive';
                $record['discovered_plugin_name'] = '';
                $record['discovered_plugin_version'] = '';
                $record['discovered_plugin_version_raw'] = '';
                $record['discovered_version_state'] = 'missing';
                $record['discovered_text_domain'] = '';
                $record['last_discovered_at'] = $scanned_at;
                continue;
            }
            $found = $matches[$product_id];
            $previous_installed = (string) ($record['installed_version'] ?? '');
            $record['plugin_file'] = $found['plugin_file'];
            if (empty($record['plugin_slug'])) {
                $record['plugin_slug'] = sanitize_key(dirname($found['plugin_file']) === '.' ? pathinfo($found['plugin_file'], PATHINFO_FILENAME) : dirname($found['plugin_file']));
            }
            if (!empty($found['text_domain'])) {
                $record['plugin_text_domain'] = $found['text_domain'];
            }
            $record['discovery_state'] = $found['active'] ? 'active' : 'inactive';
            $record['discovery_match'] = $found['match_strategy'];
            $record['discovered_active'] = $found['active'] ? '1' : '';
            $record['discovered_activation_scope'] = $found['activation_scope'];
            $record['discovered_plugin_name'] = $found['plugin_name'];
            $record['discovered_plugin_version'] = $found['version'];
            $record['discovered_plugin_version_raw'] = $found['version_raw'];
            $record['discovered_version_state'] = $found['version_state'];
            $record['discovered_text_domain'] = $found['text_domain'];
            $record['last_discovered_at'] = $scanned_at;
            $record['last_verified_at'] = $scanned_at;
            $record['source_verified_at'] = $scanned_at;
            $record['record_updated_at'] = $scanned_at;
            $record['verification_source'] = 'wordpress_plugin';
            if (empty($record['discovery_locked'])) {
                if (in_array($found['version_state'], array('valid', 'development'), true)) {
                    $record['installed_version'] = $found['version'];
                    $precedence = sanitize_key($record['version_precedence'] ?? 'discovered');
                    if ($precedence === 'discovered' || ($precedence !== 'manual' && (empty($record['public_version']) || $record['public_version'] === $previous_installed))) {
                        $record['public_version'] = $found['version'];
                    }
                }
                $github_version = trim((string) ($record['github_latest_version'] ?? ''));
                if ($found['active'] && $github_version !== '' && $found['version'] !== '' && version_compare($found['version'], $github_version, '<')) {
                    $record['status'] = 'update_available';
                } elseif (in_array($record['status'] ?? 'unverified', array('', 'unverified', 'current', 'inactive', 'update_available'), true)) {
                    $record['status'] = $found['active'] ? 'current' : 'inactive';
                }
            }
        }
        unset($record);
        update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $registry_service->normalize_registry($registry), false);

        $match_by_file = array();
        foreach ($matches as $match_record) {
            $match_by_file[$match_record['plugin_file']] = $match_record;
        }
        $pending_by_file = array();
        foreach ($pending as $candidate) {
            $pending_by_file[$candidate['plugin_file']] = $candidate;
        }
        $inventory = array();
        $classification_counts = array('standard' => 0, 'site' => 0, 'network' => 0, 'both' => 0, 'inactive' => 0, 'must-use' => 0, 'drop-in' => 0);
        $comparison_counts = array('update_available' => 0, 'ahead' => 0, 'unknown' => 0);
        foreach ($plugins as $plugin_file => $plugin) {
            $plugin_type = sanitize_key($plugin['plugin_type'] ?? 'standard');
            $classification_counts[$plugin_type] = ($classification_counts[$plugin_type] ?? 0) + 1;
            $scope = sanitize_key($plugin['activation_scope'] ?? 'inactive');
            $classification_counts[$scope] = ($classification_counts[$scope] ?? 0) + 1;
            $matched = $match_by_file[$plugin_file] ?? array();
            $candidate = $pending_by_file[$plugin_file] ?? array();
            $suggestions = $candidate['suggestions'] ?? $this->suggestion_candidates($plugin, $registry);
            $top = $suggestions ? $suggestions[0] : array();
            $comparison = $matched['version_comparison'] ?? 'unknown';
            if (isset($comparison_counts[$comparison])) {
                $comparison_counts[$comparison]++;
            }
            $inventory[] = array(
                'plugin_file' => $plugin_file,
                'name' => $plugin['name'] !== '' ? $plugin['name'] : $plugin_file,
                'version' => $plugin['version'],
                'version_state' => $plugin['version_state'],
                'active' => (bool) $plugin['active'],
                'activation_scope' => $plugin['activation_scope'],
                'plugin_type' => $plugin_type,
                'text_domain' => $plugin['text_domain'],
                'plugin_uri' => $plugin['plugin_uri'],
                'mapping_state' => isset($match_by_file[$plugin_file]) ? 'matched' : (isset($ignored[$plugin_file]) ? 'ignored' : (isset($pending_by_file[$plugin_file]) ? 'review' : 'unclassified')),
                'product_id' => sanitize_key($matched['product_id'] ?? ''),
                'match_strategy' => sanitize_key($matched['match_strategy'] ?? ''),
                'confidence' => absint($matched['confidence'] ?? 0),
                'suggested_product_id' => sanitize_key($top['product_id'] ?? ''),
                'suggested_confidence' => absint($top['confidence'] ?? 0),
                'suggestion_signals' => (array) ($top['signals'] ?? array()),
                'latest_version' => $matched['latest_version'] ?? '',
                'version_comparison' => $comparison,
            );
        }

        $diagnostics_record = $this->diagnostics_record($diagnostics, $scanned_at);
        $snapshot = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'scanned_at' => $scanned_at,
            'reason' => sanitize_key($reason),
            'installed_plugin_count' => count($plugins),
            'matched_product_count' => count($matches),
            'pending_candidate_count' => count($pending),
            'unmatched_candidate_count' => count(array_filter($pending, function ($candidate) { return ($candidate['review_state'] ?? 'pending') !== 'duplicate_match'; })),
            'duplicate_candidate_count' => count(array_filter($pending, function ($candidate) { return ($candidate['review_state'] ?? '') === 'duplicate_match'; })),
            'ignored_candidate_count' => count($ignored),
            'manual_mapping_count' => count($this->manual_mappings()),
            'active_match_count' => $active_count,
            'inactive_match_count' => $inactive_count,
            'network_active_match_count' => $network_count,
            'diagnostic_count' => count($diagnostics_record['items']),
            'standard_plugin_count' => absint($classification_counts['standard'] ?? 0),
            'site_active_plugin_count' => absint($classification_counts['site'] ?? 0),
            'network_active_plugin_count' => absint($classification_counts['network'] ?? 0),
            'both_active_plugin_count' => absint($classification_counts['both'] ?? 0),
            'inactive_plugin_count' => absint($classification_counts['inactive'] ?? 0),
            'must_use_plugin_count' => absint($classification_counts['must-use'] ?? 0),
            'dropin_count' => absint($classification_counts['drop-in'] ?? 0),
            'update_available_count' => absint($comparison_counts['update_available'] ?? 0),
            'ahead_of_release_count' => absint($comparison_counts['ahead'] ?? 0),
            'unknown_version_count' => absint($comparison_counts['unknown'] ?? 0),
            'matches' => array_values($matches),
            'inventory' => $inventory,
        );
        update_option(self::SNAPSHOT_OPTION, $snapshot, false);
        update_option(self::PENDING_OPTION, array_values($pending), false);
        update_option(self::DIAGNOSTICS_OPTION, $diagnostics_record, false);
        delete_transient(self::CACHE_KEY);
        set_transient(self::CACHE_KEY, $snapshot, self::CACHE_TTL);
        $this->audit('plugin_discovery_completed', array(
            'reason' => sanitize_key($reason),
            'installed_plugin_count' => count($plugins),
            'matched_product_count' => count($matches),
            'pending_candidate_count' => count($pending),
            'diagnostic_count' => count($diagnostics_record['items']),
        ));
        do_action('scfs_installed_plugin_discovery_completed', $snapshot, $pending, $diagnostics_record);
        return $snapshot;
    }

    private function unique_text_values($values) {
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $values))));
    }

    private function unique_key_values($values) {
        return array_values(array_unique(array_filter(array_map('sanitize_key', (array) $values))));
    }

    private function unique_plugin_files($values) {
        $normalized = array();
        foreach ((array) $values as $value) {
            $value = $this->normalize_plugin_file($value);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function identifier_snapshot($record) {
        return array(
            'plugin_file' => $this->normalize_plugin_file($record['plugin_file'] ?? ''),
            'plugin_slug' => sanitize_key($record['plugin_slug'] ?? ''),
            'plugin_text_domain' => sanitize_key($record['plugin_text_domain'] ?? ''),
            'legacy_plugin_files' => $this->unique_plugin_files($record['legacy_plugin_files'] ?? array()),
            'legacy_plugin_slugs' => $this->unique_key_values($record['legacy_plugin_slugs'] ?? array()),
            'legacy_text_domains' => $this->unique_key_values($record['legacy_text_domains'] ?? array()),
            'legacy_names' => $this->unique_text_values($record['legacy_names'] ?? array()),
        );
    }

    private function restore_identifier_snapshot(&$record, $snapshot) {
        foreach (array('plugin_file', 'plugin_slug', 'plugin_text_domain', 'legacy_plugin_files', 'legacy_plugin_slugs', 'legacy_text_domains', 'legacy_names') as $key) {
            if (array_key_exists($key, (array) $snapshot)) {
                $record[$key] = $snapshot[$key];
            }
        }
    }

    private function identifier_owner_ids($registry, $plugin, $target_product_id) {
        $plugin_file = $this->normalize_plugin_file($plugin['plugin_file'] ?? '');
        $plugin_slug = sanitize_key($plugin['directory_slug'] ?? $this->plugin_directory_slug($plugin_file));
        $text_domain = sanitize_key($plugin['text_domain'] ?? '');
        $owners = array();
        foreach ((array) $registry as $product_id => $record) {
            if ($product_id === $target_product_id) {
                continue;
            }
            $owns_file = $plugin_file !== '' && ($this->normalize_plugin_file($record['plugin_file'] ?? '') === $plugin_file || in_array($plugin_file, $this->unique_plugin_files($record['legacy_plugin_files'] ?? array()), true));
            $owns_slug = $plugin_slug !== '' && (sanitize_key($record['plugin_slug'] ?? '') === $plugin_slug || in_array($plugin_slug, $this->unique_key_values($record['legacy_plugin_slugs'] ?? array()), true));
            $owns_domain = $text_domain !== '' && (sanitize_key($record['plugin_text_domain'] ?? '') === $text_domain || in_array($text_domain, $this->unique_key_values($record['legacy_text_domains'] ?? array()), true));
            if ($owns_file || $owns_slug || $owns_domain) {
                $owners[] = $product_id;
            }
        }
        return array_values(array_unique($owners));
    }

    private function detach_duplicate_identifiers(&$registry, $candidate, $source_product_id, $plugins) {
        if (!isset($registry[$source_product_id])) {
            return array();
        }
        $record = &$registry[$source_product_id];
        $before = $this->identifier_snapshot($record);
        $plugin_file = $this->normalize_plugin_file($candidate['plugin_file'] ?? '');
        $plugin_slug = sanitize_key($candidate['directory_slug'] ?? $this->plugin_directory_slug($plugin_file));
        $text_domain = sanitize_key($candidate['text_domain'] ?? '');
        $replacement_file = $this->normalize_plugin_file($candidate['selected_plugin_file'] ?? '');
        $replacement = $replacement_file !== '' && isset($plugins[$replacement_file]) ? $plugins[$replacement_file] : array();

        if ($this->normalize_plugin_file($record['plugin_file'] ?? '') === $plugin_file) {
            $record['plugin_file'] = $replacement_file;
            $record['plugin_slug'] = sanitize_key($replacement['directory_slug'] ?? $this->plugin_directory_slug($replacement_file));
            $record['plugin_text_domain'] = sanitize_key($replacement['text_domain'] ?? '');
        }
        $record['legacy_plugin_files'] = array_values(array_diff($this->unique_plugin_files($record['legacy_plugin_files'] ?? array()), array($plugin_file)));
        if (sanitize_key($record['plugin_slug'] ?? '') === $plugin_slug && $this->normalize_plugin_file($record['plugin_file'] ?? '') !== $plugin_file) {
            $record['plugin_slug'] = sanitize_key($replacement['directory_slug'] ?? $record['plugin_slug']);
        }
        $record['legacy_plugin_slugs'] = array_values(array_diff($this->unique_key_values($record['legacy_plugin_slugs'] ?? array()), array($plugin_slug)));
        if ($text_domain !== '' && sanitize_key($record['plugin_text_domain'] ?? '') === $text_domain && $this->normalize_plugin_file($record['plugin_file'] ?? '') !== $plugin_file) {
            $record['plugin_text_domain'] = sanitize_key($replacement['text_domain'] ?? '');
        }
        if ($text_domain !== '') {
            $record['legacy_text_domains'] = array_values(array_diff($this->unique_key_values($record['legacy_text_domains'] ?? array()), array($text_domain)));
        }
        $record['record_updated_at'] = gmdate('c');
        unset($record);
        return $before;
    }

    private function map_candidate_to_product($candidate, $product_id) {
        $registry_service = SCFS_Canonical_Product_Registry::instance();
        $registry = $registry_service->registry();
        $product_id = sanitize_key($product_id);
        if (!isset($registry[$product_id])) {
            return new WP_Error('scfs_mapping_product_missing', __('Select an existing canonical product.', 'sustainable-catalyst-feature-suggestions'));
        }
        if (empty($registry[$product_id]['discovery_enabled'])) {
            return new WP_Error('scfs_mapping_product_ineligible', __('The selected product does not accept installed WordPress plugin mappings.', 'sustainable-catalyst-feature-suggestions'));
        }

        $plugins = $this->installed_plugins();
        $plugin_file = $this->normalize_plugin_file($candidate['plugin_file'] ?? '');
        if ($plugin_file === '' || !isset($plugins[$plugin_file])) {
            $this->refresh(true, 'mapping_candidate_missing');
            return new WP_Error('scfs_mapping_candidate_missing', __('The plugin is no longer installed. The discovery queue was refreshed.', 'sustainable-catalyst-feature-suggestions'));
        }
        $plugin = array_merge($plugins[$plugin_file], $candidate);
        $plugin['plugin_file'] = $plugin_file;
        $plugin['directory_slug'] = sanitize_key($plugins[$plugin_file]['directory_slug'] ?? $this->plugin_directory_slug($plugin_file));
        $plugin['text_domain'] = sanitize_key($plugins[$plugin_file]['text_domain'] ?? ($candidate['text_domain'] ?? ''));

        $owners = $this->identifier_owner_ids($registry, $plugin, $product_id);
        $source_product_id = '';
        $source_before = array();
        if ($owners) {
            $suggested = sanitize_key($candidate['suggested_product_id'] ?? '');
            $is_duplicate_reassignment = ($candidate['review_state'] ?? '') === 'duplicate_match' && count($owners) === 1 && $owners[0] === $suggested;
            if (!$is_duplicate_reassignment) {
                return new WP_Error('scfs_mapping_alias_collision', __('This plugin identifier already belongs to another canonical product. Resolve that registry collision before mapping.', 'sustainable-catalyst-feature-suggestions'));
            }
            $source_product_id = $owners[0];
            $source_before = $this->detach_duplicate_identifiers($registry, $candidate, $source_product_id, $plugins);
        }

        $target_before = $this->identifier_snapshot($registry[$product_id]);
        $record = &$registry[$product_id];
        if ($this->normalize_plugin_file($record['plugin_file'] ?? '') === '') {
            $record['plugin_file'] = $plugin_file;
        } elseif ($this->normalize_plugin_file($record['plugin_file']) !== $plugin_file) {
            $record['legacy_plugin_files'] = $this->unique_plugin_files(array_merge((array) ($record['legacy_plugin_files'] ?? array()), array($plugin_file)));
        }
        if (empty($record['plugin_slug'])) {
            $record['plugin_slug'] = $plugin['directory_slug'];
        } elseif ($record['plugin_slug'] !== $plugin['directory_slug'] && $plugin['directory_slug'] !== '') {
            $record['legacy_plugin_slugs'] = $this->unique_key_values(array_merge((array) ($record['legacy_plugin_slugs'] ?? array()), array($plugin['directory_slug'])));
        }
        if (empty($record['plugin_text_domain']) && $plugin['text_domain'] !== '') {
            $record['plugin_text_domain'] = $plugin['text_domain'];
        } elseif ($plugin['text_domain'] !== '' && $record['plugin_text_domain'] !== $plugin['text_domain']) {
            $record['legacy_text_domains'] = $this->unique_key_values(array_merge((array) ($record['legacy_text_domains'] ?? array()), array($plugin['text_domain'])));
        }
        $candidate_name = sanitize_text_field($plugin['name'] ?? '');
        if ($candidate_name !== '' && $this->normalize_name($candidate_name) !== $this->normalize_name($record['name'] ?? '')) {
            $record['legacy_names'] = $this->unique_text_values(array_merge((array) ($record['legacy_names'] ?? array()), array($candidate_name)));
        }
        $mapped_at = gmdate('c');
        $record['discovery_enabled'] = '1';
        $record['verification_source'] = 'administrator';
        $record['source_verified_at'] = $mapped_at;
        $record['last_verified_at'] = $mapped_at;
        $record['record_updated_at'] = $mapped_at;
        unset($record);

        $normalized = $registry_service->normalize_registry($registry);
        $integrity = $registry_service->integrity_report($normalized);
        if (!$integrity['valid']) {
            return new WP_Error('scfs_mapping_integrity_failed', __('The mapping would create a registry integrity error and was not saved.', 'sustainable-catalyst-feature-suggestions'));
        }
        update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $normalized, false);
        $mappings = $this->manual_mappings();
        $mappings[$plugin_file] = array(
            'plugin_file' => $plugin_file,
            'product_id' => $product_id,
            'mapped_at' => $mapped_at,
            'mapped_by' => $this->actor_label(),
            'plugin_name' => $candidate_name,
            'plugin_slug' => $plugin['directory_slug'],
            'text_domain' => $plugin['text_domain'],
            'target_before' => $target_before,
            'source_product_id' => $source_product_id,
            'source_before' => $source_before,
        );
        update_option(self::MAPPINGS_OPTION, $mappings, false);
        $ignored = $this->ignored_candidate_map();
        unset($ignored[$plugin_file]);
        update_option(self::IGNORED_OPTION, $ignored, false);
        $this->audit('plugin_mapped_to_canonical_product', array(
            'plugin_file' => $plugin_file,
            'product_id' => $product_id,
            'reassigned_from' => $source_product_id,
        ));
        do_action('scfs_product_registry_updated', $normalized);
        $snapshot = $this->refresh(true, 'administrator_mapping');
        return array(
            'message' => sprintf(__('Mapped %1$s to %2$s.', 'sustainable-catalyst-feature-suggestions'), $candidate_name ?: $plugin_file, $normalized[$product_id]['name']),
            'snapshot' => $snapshot,
            'product_id' => $product_id,
        );
    }

    private function ignore_candidate($candidate) {
        $plugin_file = $this->normalize_plugin_file($candidate['plugin_file'] ?? '');
        if ($plugin_file === '') {
            return new WP_Error('scfs_ignore_candidate_missing', __('The plugin candidate could not be identified.', 'sustainable-catalyst-feature-suggestions'));
        }
        $ignored = $this->ignored_candidate_map();
        $candidate['plugin_file'] = $plugin_file;
        $candidate['ignored_at'] = gmdate('c');
        $candidate['ignored_by'] = $this->actor_label();
        $ignored[$plugin_file] = $candidate;
        update_option(self::IGNORED_OPTION, $ignored, false);
        $this->audit('plugin_discovery_candidate_ignored', array('plugin_file' => $plugin_file));
        $snapshot = $this->refresh(true, 'candidate_ignored');
        return array(
            'message' => __('Plugin marked as not part of the Sustainable Catalyst product registry.', 'sustainable-catalyst-feature-suggestions'),
            'snapshot' => $snapshot,
        );
    }

    private function restore_ignored_candidate($plugin_file) {
        $plugin_file = $this->normalize_plugin_file($plugin_file);
        $ignored = $this->ignored_candidate_map();
        if ($plugin_file === '' || !isset($ignored[$plugin_file])) {
            return new WP_Error('scfs_ignored_candidate_missing', __('The ignored plugin could not be found.', 'sustainable-catalyst-feature-suggestions'));
        }
        unset($ignored[$plugin_file]);
        update_option(self::IGNORED_OPTION, $ignored, false);
        $this->audit('plugin_discovery_candidate_restored', array('plugin_file' => $plugin_file));
        $snapshot = $this->refresh(true, 'ignored_candidate_restored');
        return array(
            'message' => __('Plugin returned to discovery review.', 'sustainable-catalyst-feature-suggestions'),
            'snapshot' => $snapshot,
        );
    }

    private function remove_manual_mapping($plugin_file) {
        $plugin_file = $this->normalize_plugin_file($plugin_file);
        $mappings = $this->manual_mappings();
        if ($plugin_file === '' || !isset($mappings[$plugin_file])) {
            return new WP_Error('scfs_manual_mapping_missing', __('The manual mapping could not be found.', 'sustainable-catalyst-feature-suggestions'));
        }
        $mapping = $mappings[$plugin_file];
        $registry_service = SCFS_Canonical_Product_Registry::instance();
        $registry = $registry_service->registry();
        $product_id = sanitize_key($mapping['product_id'] ?? '');
        if ($product_id !== '' && isset($registry[$product_id])) {
            $this->restore_identifier_snapshot($registry[$product_id], $mapping['target_before'] ?? array());
            $registry[$product_id]['record_updated_at'] = gmdate('c');
        }
        $source_product_id = sanitize_key($mapping['source_product_id'] ?? '');
        if ($source_product_id !== '' && isset($registry[$source_product_id]) && !empty($mapping['source_before'])) {
            $this->restore_identifier_snapshot($registry[$source_product_id], $mapping['source_before']);
            $registry[$source_product_id]['record_updated_at'] = gmdate('c');
        }
        unset($mappings[$plugin_file]);
        update_option(self::MAPPINGS_OPTION, $mappings, false);
        $normalized = $registry_service->normalize_registry($registry);
        update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $normalized, false);
        $this->audit('plugin_manual_mapping_removed', array('plugin_file' => $plugin_file, 'product_id' => $product_id));
        do_action('scfs_product_registry_updated', $normalized);
        $snapshot = $this->refresh(true, 'manual_mapping_removed');
        return array(
            'message' => __('Manual plugin mapping removed. Discovery has been recalculated.', 'sustainable-catalyst-feature-suggestions'),
            'snapshot' => $snapshot,
        );
    }

    private function apply_candidate_decision($plugin_file, $decision) {
        $plugin_file = $this->normalize_plugin_file($plugin_file);
        $decision = sanitize_text_field($decision);
        if ($decision === '__restore__') {
            return $this->restore_ignored_candidate($plugin_file);
        }
        if ($decision === '__remove_mapping__') {
            return $this->remove_manual_mapping($plugin_file);
        }
        $candidate = $this->pending_candidate($plugin_file);
        if (!$candidate) {
            $this->refresh(true, 'decision_queue_refresh');
            $candidate = $this->pending_candidate($plugin_file);
        }
        if (!$candidate) {
            return new WP_Error('scfs_discovery_candidate_missing', __('This plugin is no longer awaiting review.', 'sustainable-catalyst-feature-suggestions'));
        }
        if ($decision === '__review__' || $decision === '') {
            return array('message' => __('Plugin left awaiting review.', 'sustainable-catalyst-feature-suggestions'), 'snapshot' => $this->snapshot());
        }
        if ($decision === '__ignore__') {
            return $this->ignore_candidate($candidate);
        }
        return $this->map_candidate_to_product($candidate, sanitize_key($decision));
    }


    public function apply_bulk_decision($plugin_files, $bulk_decision) {
        $plugin_files = array_values(array_unique(array_filter(array_map(array($this, 'normalize_plugin_file'), (array) $plugin_files))));
        $bulk_decision = sanitize_key($bulk_decision);
        if (!$plugin_files || !in_array($bulk_decision, array('ignore', 'map_suggested'), true)) {
            return new WP_Error('scfs_bulk_decision_invalid', __('Select at least one plugin and a supported bulk action.', 'sustainable-catalyst-feature-suggestions'));
        }
        $processed = 0;
        $skipped = array();
        $used_products = array();
        foreach ($plugin_files as $plugin_file) {
            $candidate = $this->pending_candidate($plugin_file);
            if (!$candidate) {
                $skipped[] = $plugin_file;
                continue;
            }
            if ($bulk_decision === 'ignore') {
                $result = $this->ignore_candidate($candidate);
            } else {
                $product_id = sanitize_key($candidate['suggested_product_id'] ?? '');
                $confidence = absint($candidate['suggested_confidence'] ?? 0);
                if ($product_id === '' || $confidence < 80 || isset($used_products[$product_id])) {
                    $skipped[] = $plugin_file;
                    continue;
                }
                $result = $this->map_candidate_to_product($candidate, $product_id);
                $used_products[$product_id] = true;
            }
            if (is_wp_error($result)) {
                $skipped[] = $plugin_file;
            } else {
                $processed++;
            }
        }
        $this->refresh(true, 'bulk_' . $bulk_decision);
        $this->audit('plugin_discovery_bulk_decision', array('decision' => $bulk_decision, 'processed' => $processed, 'skipped' => count($skipped)));
        return array(
            'processed' => $processed,
            'skipped' => $skipped,
            'message' => sprintf(__('%1$d plugin decisions applied; %2$d skipped for review.', 'sustainable-catalyst-feature-suggestions'), $processed, count($skipped)),
        );
    }

    public function bulk_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::BULK_NONCE, 'scfs_plugin_discovery_bulk_nonce');
        $plugin_files = isset($_POST['plugin_files']) ? (array) wp_unslash($_POST['plugin_files']) : array();
        $bulk_decision = isset($_POST['bulk_decision']) ? wp_unslash($_POST['bulk_decision']) : '';
        $result = $this->apply_bulk_decision($plugin_files, $bulk_decision);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        set_transient('scfs_plugin_discovery_bulk_' . get_current_user_id(), $result['message'], 60);
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&bulk_updated=1'));
        exit;
    }

    public function save_product_connection($product_id, $plugin_file, $repository_url) {
        $product_id = sanitize_key($product_id);
        $plugin_file = $this->normalize_plugin_file($plugin_file);
        $repository_url = esc_url_raw($repository_url);
        $registry_service = SCFS_Canonical_Product_Registry::instance();
        $registry = $registry_service->registry();
        if ($product_id === '' || !isset($registry[$product_id])) {
            return new WP_Error('scfs_connection_product_missing', __('Select an existing canonical product.', 'sustainable-catalyst-feature-suggestions'));
        }
        if ($repository_url !== '') {
            if (!class_exists('SCFS_Canonical_Product_GitHub_Sync') || !SCFS_Canonical_Product_GitHub_Sync::instance()->parse_repository_url($repository_url)) {
                return new WP_Error('scfs_connection_repository_invalid', __('Use a GitHub repository URL in the form https://github.com/owner/repository.', 'sustainable-catalyst-feature-suggestions'));
            }
        }

        $original_registry = $registry;
        $record = &$registry[$product_id];
        $record['github_repository_url'] = $repository_url;
        $record['github_sync_enabled'] = $repository_url !== '' ? '1' : '';
        $record['github_sync_state'] = $repository_url !== '' ? 'pending' : 'unconfigured';
        $record['github_sync_message'] = '';
        if ($repository_url !== '' && class_exists('SCFS_Canonical_Product_GitHub_Sync')) {
            $parsed = SCFS_Canonical_Product_GitHub_Sync::instance()->parse_repository_url($repository_url);
            $record['repository_slug'] = sanitize_key($parsed['repository'] ?? '');
        }
        $record['record_updated_at'] = gmdate('c');
        $record['verification_source'] = 'administrator';
        $record['source_verified_at'] = gmdate('c');
        unset($record);
        update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $registry_service->normalize_registry($registry), false);

        if ($plugin_file !== '') {
            $plugins = $this->active_plugins_for_mapping();
            if (!isset($plugins[$plugin_file])) {
                update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $registry_service->normalize_registry($original_registry), false);
                return new WP_Error('scfs_connection_plugin_inactive', __('Select a currently active site or network plugin.', 'sustainable-catalyst-feature-suggestions'));
            }
            $registry = $registry_service->registry();
            $registry[$product_id]['discovery_enabled'] = '1';
            update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $registry_service->normalize_registry($registry), false);
            $candidate = array_merge($plugins[$plugin_file], array(
                'plugin_file' => $plugin_file,
                'review_state' => 'pending',
                'suggested_product_id' => $product_id,
            ));
            $mapped = $this->map_candidate_to_product($candidate, $product_id);
            if (is_wp_error($mapped)) {
                update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $registry_service->normalize_registry($original_registry), false);
                $this->refresh(true, 'connection_rollback');
                return $mapped;
            }
        } else {
            $mappings = $this->manual_mappings();
            foreach ($mappings as $mapped_plugin_file => $mapping) {
                if (($mapping['product_id'] ?? '') === $product_id) {
                    $removed = $this->remove_manual_mapping($mapped_plugin_file);
                    if (is_wp_error($removed)) {
                        return $removed;
                    }
                    break;
                }
            }
        }

        $registry = $registry_service->registry();
        if (isset($registry[$product_id])) {
            $registry[$product_id]['github_repository_url'] = $repository_url;
            $registry[$product_id]['github_sync_enabled'] = $repository_url !== '' ? '1' : '';
            $registry[$product_id]['github_sync_state'] = $repository_url !== '' ? 'pending' : 'unconfigured';
            $registry[$product_id]['record_updated_at'] = gmdate('c');
            update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $registry_service->normalize_registry($registry), false);
        }
        $this->audit('canonical_product_connection_saved', array(
            'product_id' => $product_id,
            'plugin_file' => $plugin_file,
            'repository_url' => $repository_url,
        ));
        do_action('scfs_product_registry_updated', $registry_service->registry());
        do_action('scfs_canonical_product_connection_saved', $product_id);
        return array(
            'message' => __('Canonical product connection saved.', 'sustainable-catalyst-feature-suggestions'),
            'product_id' => $product_id,
            'plugin_file' => $plugin_file,
            'repository_url' => $repository_url,
        );
    }

    public function save_product_connection_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer('scfs_save_product_connection', 'scfs_product_connection_nonce');
        $product_id = sanitize_key(wp_unslash($_POST['product_id'] ?? ''));
        $plugin_file = wp_unslash($_POST['plugin_file'] ?? '');
        $repository_url = wp_unslash($_POST['github_repository_url'] ?? '');
        $result = $this->save_product_connection($product_id, $plugin_file, $repository_url);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&connection_saved=1'));
        exit;
    }

    private function mapped_plugin_for_product($product_id) {
        foreach ($this->manual_mappings() as $plugin_file => $mapping) {
            if (($mapping['product_id'] ?? '') === $product_id) {
                return $plugin_file;
            }
        }
        $record = SCFS_Canonical_Product_Registry::instance()->get_product($product_id);
        $plugin_file = $this->normalize_plugin_file($record['plugin_file'] ?? '');
        return $plugin_file;
    }

    private function render_connections_panel() {
        $products = $this->mapping_products();
        $plugins = $this->active_plugins_for_mapping();
        $mapping_owners = array();
        foreach ($this->manual_mappings() as $plugin_file => $mapping) {
            $mapping_owners[$plugin_file] = $mapping['product_id'] ?? '';
        }
        echo '<section class="scfs-product-connections"><h2>' . esc_html__('Canonical product connections', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<p class="description">' . esc_html__('Connect one canonical console product to any currently active WordPress plugin and one GitHub repository. The plugin supplies installed-state evidence; GitHub supplies repository, release, and commit evidence; the canonical product remains the public identity.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<div class="scfs-discovery-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Console product', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Active WordPress plugin', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('GitHub repository', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Connection', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        foreach ($products as $product_id => $record) {
            $selected_plugin = $this->mapped_plugin_for_product($product_id);
            $sync_url = wp_nonce_url(admin_url('admin-post.php?action=scfs_sync_canonical_github&product_id=' . rawurlencode($product_id)), 'scfs_sync_canonical_github');
            echo '<tr><td><strong>' . esc_html($record['name']) . '</strong><br><code>' . esc_html($product_id) . '</code>';
            if (!empty($record['github_latest_version'])) {
                echo '<br><span class="scfs-discovery-chip">' . esc_html(sprintf(__('GitHub %s', 'sustainable-catalyst-feature-suggestions'), $record['github_latest_version'])) . '</span>';
            }
            echo '</td><td colspan="3"><form class="scfs-product-connection-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_save_product_connection"><input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">';
            wp_nonce_field('scfs_save_product_connection', 'scfs_product_connection_nonce');
            echo '<div class="scfs-product-connection-grid"><label><span class="screen-reader-text">' . esc_html__('Active WordPress plugin', 'sustainable-catalyst-feature-suggestions') . '</span><select name="plugin_file"><option value="">' . esc_html__('No active plugin connected', 'sustainable-catalyst-feature-suggestions') . '</option>';
            foreach ($plugins as $plugin_file => $plugin) {
                $owner = sanitize_key($mapping_owners[$plugin_file] ?? '');
                $label = ($plugin['name'] ?: $plugin_file) . ' — ' . ($plugin['version'] ?: __('version unavailable', 'sustainable-catalyst-feature-suggestions'));
                if ($owner !== '' && $owner !== $product_id) {
                    $owner_record = $products[$owner] ?? SCFS_Canonical_Product_Registry::instance()->get_product($owner);
                    $label .= ' · ' . sprintf(__('mapped to %s', 'sustainable-catalyst-feature-suggestions'), $owner_record['short_name'] ?? $owner);
                }
                echo '<option value="' . esc_attr($plugin_file) . '" ' . selected($selected_plugin, $plugin_file, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label><label><span class="screen-reader-text">' . esc_html__('GitHub repository URL', 'sustainable-catalyst-feature-suggestions') . '</span><input type="url" class="regular-text code" name="github_repository_url" value="' . esc_attr($record['github_repository_url'] ?? '') . '" placeholder="https://github.com/owner/repository"></label><span class="scfs-product-connection-actions"><button type="submit" class="button button-primary">' . esc_html__('Save connection', 'sustainable-catalyst-feature-suggestions') . '</button>';
            if (!empty($record['github_repository_url'])) {
                echo '<a class="button" href="' . esc_url($sync_url) . '">' . esc_html__('Sync GitHub now', 'sustainable-catalyst-feature-suggestions') . '</a>';
                echo '<a class="button-link" href="' . esc_url($record['github_repository_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Open repository', 'sustainable-catalyst-feature-suggestions') . '</a>';
            }
            echo '</span></div>';
            $sync_state = sanitize_key($record['github_sync_state'] ?? 'never');
            $sync_message = sanitize_text_field($record['github_sync_message'] ?? '');
            if ($sync_state === 'error' && $sync_message !== '') {
                echo '<p class="scfs-product-connection-status scfs-product-connection-status--error" role="status"><strong>' . esc_html__('Sync error:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html($sync_message) . '</p>';
            } elseif ($sync_state === 'current') {
                if ($sync_message === '') {
                    $sync_message = !empty($record['github_latest_version'])
                        ? sprintf(__('Synchronized with GitHub release %s.', 'sustainable-catalyst-feature-suggestions'), $record['github_latest_version'])
                        : __('Repository connected. No GitHub Release is published yet.', 'sustainable-catalyst-feature-suggestions');
                }
                echo '<p class="scfs-product-connection-status scfs-product-connection-status--current" role="status">' . esc_html($sync_message) . '</p>';
            }
            echo '</form></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function candidate_reason_label($candidate) {
        $state = sanitize_key($candidate['review_state'] ?? 'pending');
        if ($state === 'malformed_header') {
            return __('Plugin headers need correction before matching.', 'sustainable-catalyst-feature-suggestions');
        }
        if ($state === 'duplicate_match') {
            return __('Another installed plugin was selected for the same product.', 'sustainable-catalyst-feature-suggestions');
        }
        return __('No approved Product Registry match was found.', 'sustainable-catalyst-feature-suggestions');
    }

    public function enqueue_admin_assets($hook_suffix) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::ADMIN_SLUG) {
            return;
        }
        $plugin_file = dirname(__DIR__) . '/sustainable-catalyst-feature-suggestions.php';
        wp_enqueue_style(
            'scfs-plugin-discovery-v781',
            plugin_dir_url($plugin_file) . 'assets/plugin-discovery-v7.8.1.css',
            array(),
            self::VERSION
        );
        wp_enqueue_script(
            'scfs-plugin-discovery-v781',
            plugin_dir_url($plugin_file) . 'assets/plugin-discovery-v7.8.1.js',
            array(),
            self::VERSION,
            true
        );
        wp_localize_script('scfs-plugin-discovery-v781', 'SCFSPluginDiscovery', array(
            'restUrl' => esc_url_raw(rest_url(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE . '/product-registry/discovery/decision')),
            'nonce' => wp_create_nonce('wp_rest'),
            'saving' => __('Saving decision…', 'sustainable-catalyst-feature-suggestions'),
            'error' => __('The mapping could not be saved.', 'sustainable-catalyst-feature-suggestions'),
        ));
    }

    private function render_decision_form($candidate) {
        $plugin_file = $this->normalize_plugin_file($candidate['plugin_file'] ?? '');
        $field_id = 'scfs-map-' . substr(md5($plugin_file), 0, 12);
        $suggested = sanitize_key($candidate['suggested_product_id'] ?? '');
        $duplicate = ($candidate['review_state'] ?? '') === 'duplicate_match';
        echo '<form class="scfs-discovery-decision" data-scfs-discovery-decision method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="scfs_plugin_discovery_decision">';
        echo '<input type="hidden" name="plugin_file" value="' . esc_attr($plugin_file) . '">';
        wp_nonce_field(self::DECISION_NONCE, 'scfs_plugin_discovery_nonce');
        echo '<label for="' . esc_attr($field_id) . '"><strong>' . esc_html__('Map to canonical product', 'sustainable-catalyst-feature-suggestions') . '</strong></label>';
        echo '<select id="' . esc_attr($field_id) . '" name="decision">';
        echo '<option value="__review__">' . esc_html__('Leave awaiting review', 'sustainable-catalyst-feature-suggestions') . '</option>';
        foreach ($this->mapping_products() as $product_id => $product) {
            if ($duplicate && $product_id === $suggested) {
                continue;
            }
            $selected = !$duplicate && $suggested !== '' && $suggested === $product_id;
            echo '<option value="' . esc_attr($product_id) . '" ' . selected($selected, true, false) . '>' . esc_html($product['name']) . '</option>';
        }
        echo '<option value="__ignore__">' . esc_html__('Not a Sustainable Catalyst product', 'sustainable-catalyst-feature-suggestions') . '</option>';
        echo '</select>';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Save mapping decision', 'sustainable-catalyst-feature-suggestions') . '</button>';
        echo '<span class="spinner" aria-hidden="true"></span>';
        echo '</form>';
    }

    private function render_candidate_details($candidate) {
        $details = array(
            __('Plugin file', 'sustainable-catalyst-feature-suggestions') => $candidate['plugin_file'] ?? '',
            __('Folder slug', 'sustainable-catalyst-feature-suggestions') => $candidate['directory_slug'] ?? $this->plugin_directory_slug($candidate['plugin_file'] ?? ''),
            __('Text domain', 'sustainable-catalyst-feature-suggestions') => $candidate['text_domain'] ?? '',
            __('Author', 'sustainable-catalyst-feature-suggestions') => $candidate['author'] ?? '',
            __('SC Product ID header', 'sustainable-catalyst-feature-suggestions') => $candidate['sc_product_id'] ?? '',
            __('SC Product header', 'sustainable-catalyst-feature-suggestions') => $candidate['sc_product'] ?? '',
            __('SC Public Release header', 'sustainable-catalyst-feature-suggestions') => $candidate['sc_public_release'] ?? '',
            __('Plugin classification', 'sustainable-catalyst-feature-suggestions') => $candidate['plugin_type'] ?? 'standard',
            __('Plugin URI', 'sustainable-catalyst-feature-suggestions') => $candidate['plugin_uri'] ?? '',
            __('Suggestion signals', 'sustainable-catalyst-feature-suggestions') => implode(', ', (array) ($candidate['suggestion_signals'] ?? array())),
        );
        echo '<details class="scfs-discovery-details"><summary>' . esc_html__('View plugin evidence', 'sustainable-catalyst-feature-suggestions') . '</summary><dl>';
        foreach ($details as $label => $value) {
            if ($value === '') {
                continue;
            }
            echo '<div><dt>' . esc_html($label) . '</dt><dd><code>' . esc_html($value) . '</code></dd></div>';
        }
        echo '</dl></details>';
    }

    private function render_candidate_table($candidates) {
        $form_id = 'scfs-discovery-bulk-' . substr(md5(wp_json_encode(array_column((array) $candidates, 'plugin_file'))), 0, 10);
        echo '<form id="' . esc_attr($form_id) . '" class="scfs-discovery-bulk" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="scfs_plugin_discovery_bulk">';
        wp_nonce_field(self::BULK_NONCE, 'scfs_plugin_discovery_bulk_nonce');
        echo '<label><span class="screen-reader-text">' . esc_html__('Bulk action', 'sustainable-catalyst-feature-suggestions') . '</span><select name="bulk_decision"><option value="">' . esc_html__('Bulk actions', 'sustainable-catalyst-feature-suggestions') . '</option><option value="map_suggested">' . esc_html__('Map to high-confidence suggestions', 'sustainable-catalyst-feature-suggestions') . '</option><option value="ignore">' . esc_html__('Mark as not a Sustainable Catalyst product', 'sustainable-catalyst-feature-suggestions') . '</option></select></label> ';
        echo '<button type="submit" class="button">' . esc_html__('Apply', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
        echo '<div class="scfs-discovery-table-wrap"><table class="widefat striped scfs-discovery-candidates"><thead><tr><th class="check-column"><input type="checkbox" data-scfs-select-all aria-label="' . esc_attr__('Select all plugins in this table', 'sustainable-catalyst-feature-suggestions') . '"></th><th>' . esc_html__('Installed plugin', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Evidence', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Review status', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Mapping decision', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        foreach ((array) $candidates as $candidate) {
            $version = $candidate['version'] ?? ($candidate['version_raw'] ?? '');
            if ($version === '') {
                $version = __('Missing', 'sustainable-catalyst-feature-suggestions');
            }
            $suggested_id = sanitize_key($candidate['suggested_product_id'] ?? '');
            $suggested = $suggested_id !== '' ? SCFS_Canonical_Product_Registry::instance()->get_product($suggested_id) : null;
            $confidence = absint($candidate['suggested_confidence'] ?? 0);
            echo '<tr data-plugin-file="' . esc_attr($candidate['plugin_file']) . '"><th class="check-column"><input form="' . esc_attr($form_id) . '" type="checkbox" name="plugin_files[]" value="' . esc_attr($candidate['plugin_file']) . '" aria-label="' . esc_attr(sprintf(__('Select %s', 'sustainable-catalyst-feature-suggestions'), $candidate['name'] ?? $candidate['plugin_file'])) . '"></th><td><strong>' . esc_html($candidate['name'] ?? $candidate['plugin_file']) . '</strong><br><code>' . esc_html($candidate['plugin_file']) . '</code><br><span class="scfs-discovery-chip">' . esc_html($candidate['plugin_type'] ?? 'standard') . '</span> <span class="scfs-discovery-chip">' . esc_html($candidate['activation_scope'] ?? 'inactive') . '</span></td>';
            echo '<td><strong>' . esc_html($version) . '</strong> <small>' . esc_html($candidate['version_state'] ?? 'missing') . '</small>';
            $this->render_candidate_details($candidate);
            echo '</td><td>' . esc_html($this->candidate_reason_label($candidate)) . '<br><code>' . esc_html($candidate['review_state'] ?? 'pending') . '</code>';
            if ($suggested) {
                echo '<p><strong>' . esc_html__('Suggested:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html($suggested['name']) . ' <span class="scfs-discovery-confidence">' . esc_html($confidence) . '%</span></p>';
                if (!empty($candidate['suggestion_signals'])) {
                    echo '<small>' . esc_html(implode(' · ', (array) $candidate['suggestion_signals'])) . '</small>';
                }
            }
            if (!empty($candidate['selected_plugin_file'])) {
                echo '<p><small>' . esc_html(sprintf(__('Current winner: %s', 'sustainable-catalyst-feature-suggestions'), $candidate['selected_plugin_file'])) . '</small></p>';
            }
            echo '</td><td>';
            $this->render_decision_form($candidate);
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function render_inventory_panel() {
        $snapshot = $this->snapshot();
        $registry = SCFS_Canonical_Product_Registry::instance();
        echo '<section data-scfs-plugin-inventory><div class="scfs-discovery-section-heading"><div><h2>' . esc_html__('Complete plugin inventory', 'sustainable-catalyst-feature-suggestions') . '</h2><p class="description">' . esc_html__('All standard, must-use, and drop-in plugins are classified. Matching suggestions remain advisory until an administrator confirms them.', 'sustainable-catalyst-feature-suggestions') . '</p></div><div class="scfs-discovery-inventory-filters"><label><span class="screen-reader-text">' . esc_html__('Search plugin inventory', 'sustainable-catalyst-feature-suggestions') . '</span><input type="search" data-scfs-inventory-search placeholder="' . esc_attr__('Search plugins', 'sustainable-catalyst-feature-suggestions') . '"></label><label><span class="screen-reader-text">' . esc_html__('Filter plugin classification', 'sustainable-catalyst-feature-suggestions') . '</span><select data-scfs-inventory-filter><option value="all">' . esc_html__('All classifications', 'sustainable-catalyst-feature-suggestions') . '</option><option value="site">' . esc_html__('Site active', 'sustainable-catalyst-feature-suggestions') . '</option><option value="network">' . esc_html__('Network active', 'sustainable-catalyst-feature-suggestions') . '</option><option value="inactive">' . esc_html__('Inactive', 'sustainable-catalyst-feature-suggestions') . '</option><option value="must-use">' . esc_html__('Must-use', 'sustainable-catalyst-feature-suggestions') . '</option><option value="drop-in">' . esc_html__('Drop-ins', 'sustainable-catalyst-feature-suggestions') . '</option></select></label></div></div>';
        echo '<div class="scfs-discovery-table-wrap"><table class="widefat striped scfs-plugin-inventory"><thead><tr><th>' . esc_html__('Plugin', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Classification', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Canonical mapping', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Version intelligence', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        foreach ((array) $snapshot['inventory'] as $item) {
            $product = !empty($item['product_id']) ? $registry->get_product($item['product_id']) : null;
            $suggested = !empty($item['suggested_product_id']) ? $registry->get_product($item['suggested_product_id']) : null;
            $haystack = strtolower(($item['name'] ?? '') . ' ' . ($item['plugin_file'] ?? '') . ' ' . ($item['text_domain'] ?? ''));
            echo '<tr data-scfs-inventory-row data-search="' . esc_attr($haystack) . '" data-scope="' . esc_attr($item['activation_scope'] ?? 'inactive') . '" data-type="' . esc_attr($item['plugin_type'] ?? 'standard') . '"><td><strong>' . esc_html($item['name']) . '</strong><br><code>' . esc_html($item['plugin_file']) . '</code></td><td><span class="scfs-discovery-chip">' . esc_html($item['plugin_type']) . '</span> <span class="scfs-discovery-chip">' . esc_html($item['activation_scope']) . '</span></td><td>';
            if ($product) {
                echo '<strong>' . esc_html($product['name']) . '</strong><br><small>' . esc_html($item['match_strategy']) . ' · ' . esc_html($item['confidence']) . '%</small>';
            } elseif ($suggested) {
                echo esc_html(sprintf(__('Suggested: %1$s (%2$d%%)', 'sustainable-catalyst-feature-suggestions'), $suggested['name'], $item['suggested_confidence']));
            } else {
                echo esc_html(ucwords(str_replace('_', ' ', $item['mapping_state'])));
            }
            echo '</td><td><strong>' . esc_html($item['version'] ?: __('Missing', 'sustainable-catalyst-feature-suggestions')) . '</strong>';
            if (!empty($item['latest_version'])) {
                echo '<br><small>' . esc_html(sprintf(__('GitHub: %s', 'sustainable-catalyst-feature-suggestions'), $item['latest_version'])) . '</small>';
            }
            echo '<br><span class="scfs-discovery-version-state scfs-discovery-version-state--' . esc_attr($item['version_comparison']) . '">' . esc_html(str_replace('_', ' ', $item['version_comparison'])) . '</span></td></tr>';
        }
        echo '</tbody></table></div><p data-scfs-inventory-empty hidden>' . esc_html__('No plugins match the current inventory filters.', 'sustainable-catalyst-feature-suggestions') . '</p></section>';
    }

    private function render_summary_panel() {
        $snapshot = $this->snapshot();
        $pending_count = count($this->pending_candidates());
        echo '<div class="notice notice-info inline scfs-discovery-summary" data-scfs-discovery-summary><p><strong>' . esc_html(sprintf(__('%d approved products matched', 'sustainable-catalyst-feature-suggestions'), $snapshot['matched_product_count'])) . '</strong> · ' . esc_html(sprintf(__('%d site active', 'sustainable-catalyst-feature-suggestions'), $snapshot['site_active_plugin_count'])) . ' · ' . esc_html(sprintf(__('%d network active', 'sustainable-catalyst-feature-suggestions'), $snapshot['network_active_plugin_count'] + $snapshot['both_active_plugin_count'])) . ' · ' . esc_html(sprintf(__('%d inactive', 'sustainable-catalyst-feature-suggestions'), $snapshot['inactive_plugin_count'])) . ' · ' . esc_html(sprintf(__('%d must-use', 'sustainable-catalyst-feature-suggestions'), $snapshot['must_use_plugin_count'])) . ' · ' . esc_html(sprintf(__('%d drop-ins', 'sustainable-catalyst-feature-suggestions'), $snapshot['dropin_count'])) . ' · <strong data-scfs-pending-count>' . esc_html(sprintf(__('%d pending review', 'sustainable-catalyst-feature-suggestions'), $pending_count)) . '</strong></p><p>' . esc_html(sprintf(__('%1$d updates available · %2$d ahead of GitHub · %3$d unknown comparisons', 'sustainable-catalyst-feature-suggestions'), $snapshot['update_available_count'], $snapshot['ahead_of_release_count'], $snapshot['unknown_version_count'])) . '</p></div>';
    }

    private function render_matches_panel() {
        $snapshot = $this->snapshot();
        $mappings = $this->manual_mappings();
        echo '<section data-scfs-discovery-matches><h2>' . esc_html__('Matched products', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<div class="scfs-discovery-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Product', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Installed plugin', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Version', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Activation', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Match', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        if (!$snapshot['matches']) {
            echo '<tr><td colspan="5">' . esc_html__('No approved products have been matched yet.', 'sustainable-catalyst-feature-suggestions') . '</td></tr>';
        } else {
            foreach ($snapshot['matches'] as $match) {
                $product = SCFS_Canonical_Product_Registry::instance()->get_product($match['product_id']);
                $version = $match['version'] !== '' ? $match['version'] : __('Missing', 'sustainable-catalyst-feature-suggestions');
                echo '<tr><td><strong>' . esc_html($product ? $product['name'] : $match['product_id']) . '</strong></td><td>' . esc_html($match['plugin_name']) . '<br><code>' . esc_html($match['plugin_file']) . '</code>';
                if (isset($mappings[$match['plugin_file']])) {
                    echo '<form class="scfs-discovery-inline-form" data-scfs-discovery-decision method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_plugin_discovery_decision"><input type="hidden" name="plugin_file" value="' . esc_attr($match['plugin_file']) . '"><input type="hidden" name="decision" value="__remove_mapping__">';
                    wp_nonce_field(self::DECISION_NONCE, 'scfs_plugin_discovery_nonce');
                    echo '<button class="button-link-delete" type="submit">' . esc_html__('Remove manual mapping', 'sustainable-catalyst-feature-suggestions') . '</button><span class="spinner" aria-hidden="true"></span></form>';
                }
                echo '</td><td>' . esc_html($version) . '<br><small>' . esc_html($match['version_state']) . '</small>';
                if (!empty($match['latest_version'])) { echo '<br><small>' . esc_html(sprintf(__('GitHub: %s', 'sustainable-catalyst-feature-suggestions'), $match['latest_version'])) . '</small>'; }
                echo '<br><span class="scfs-discovery-version-state scfs-discovery-version-state--' . esc_attr($match['version_comparison'] ?? 'unknown') . '">' . esc_html(str_replace('_', ' ', $match['version_comparison'] ?? 'unknown')) . '</span></td><td>' . esc_html($match['activation_scope']) . '</td><td><code>' . esc_html($match['match_strategy']) . '</code> ' . esc_html($match['confidence']) . '%</td></tr>';
            }
        }
        echo '</tbody></table></div></section>';
    }

    private function render_review_panel() {
        $unmatched = $this->unmatched_candidates();
        $duplicates = $this->duplicate_candidates();
        $pending_count = count($unmatched) + count($duplicates);
        echo '<section data-scfs-discovery-review aria-live="polite">';
        if ($pending_count === 0) {
            echo '<div class="notice notice-success inline scfs-discovery-zero"><p><strong>' . esc_html__('No plugins awaiting review', 'sustainable-catalyst-feature-suggestions') . '</strong><br>' . esc_html__('All installed Sustainable Catalyst plugins are mapped to the Canonical Product Registry or intentionally excluded.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        } else {
            if ($unmatched) {
                echo '<div class="scfs-discovery-review-section"><h2>' . esc_html__('Pending private review', 'sustainable-catalyst-feature-suggestions') . '</h2>';
                echo '<p class="description">' . esc_html__('Choose the canonical product for each unmatched plugin. Saving adds governed aliases, records the administrator decision, refreshes discovery, and never changes the installed plugin folder.', 'sustainable-catalyst-feature-suggestions') . '</p>';
                $this->render_candidate_table($unmatched);
                echo '</div>';
            }
            if ($duplicates) {
                echo '<div class="scfs-discovery-review-section"><h2>' . esc_html__('Duplicate mapping review', 'sustainable-catalyst-feature-suggestions') . '</h2>';
                echo '<p class="description">' . esc_html__('Choose a different canonical product to reassign the extra plugin, leave it for later, or mark it as outside the Sustainable Catalyst registry.', 'sustainable-catalyst-feature-suggestions') . '</p>';
                $this->render_candidate_table($duplicates);
                echo '</div>';
            }
        }
        echo '</section>';
    }

    private function render_ignored_panel() {
        $ignored = $this->ignored_candidates();
        echo '<section data-scfs-discovery-ignored>';
        if ($ignored) {
            echo '<details class="scfs-discovery-ignored"><summary>' . esc_html(sprintf(_n('%d ignored plugin', '%d ignored plugins', count($ignored), 'sustainable-catalyst-feature-suggestions'), count($ignored))) . '</summary>';
            echo '<p class="description">' . esc_html__('Ignored plugins stay private and do not contribute to pending-review status. Restore one to evaluate it again.', 'sustainable-catalyst-feature-suggestions') . '</p><ul>';
            foreach ($ignored as $candidate) {
                echo '<li><strong>' . esc_html($candidate['name'] ?? $candidate['plugin_file']) . '</strong> <code>' . esc_html($candidate['plugin_file']) . '</code>';
                echo '<form class="scfs-discovery-inline-form" data-scfs-discovery-decision method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_plugin_discovery_decision"><input type="hidden" name="plugin_file" value="' . esc_attr($candidate['plugin_file']) . '"><input type="hidden" name="decision" value="__restore__">';
                wp_nonce_field(self::DECISION_NONCE, 'scfs_plugin_discovery_nonce');
                echo '<button type="submit" class="button button-small">' . esc_html__('Restore to review', 'sustainable-catalyst-feature-suggestions') . '</button><span class="spinner" aria-hidden="true"></span></form></li>';
            }
            echo '</ul></details>';
        }
        echo '</section>';
    }

    private function render_diagnostics_panel() {
        $diagnostics = $this->diagnostics();
        echo '<section><h2>' . esc_html__('Discovery diagnostics', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<p>' . esc_html(sprintf(__('%d errors · %d warnings · %d informational notices', 'sustainable-catalyst-feature-suggestions'), $diagnostics['error_count'], $diagnostics['warning_count'], $diagnostics['info_count'])) . '</p>';
        echo '<div class="scfs-discovery-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Level', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Code', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Product or plugin', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Message', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        if (empty($diagnostics['items'])) {
            echo '<tr><td colspan="4">' . esc_html__('No discovery diagnostics.', 'sustainable-catalyst-feature-suggestions') . '</td></tr>';
        } else {
            foreach ($diagnostics['items'] as $item) {
                $subject = $item['product_id'] ?: $item['plugin_file'];
                echo '<tr><td>' . esc_html($item['level']) . '</td><td><code>' . esc_html($item['code']) . '</code></td><td>' . esc_html($subject) . '</td><td>' . esc_html($item['message']) . '</td></tr>';
            }
        }
        echo '</tbody></table></div></section>';
    }

    private function render_fragment($method) {
        ob_start();
        $this->$method();
        return ob_get_clean();
    }

    private function fragment_payload() {
        return array(
            'summary' => $this->render_fragment('render_summary_panel'),
            'matches' => $this->render_fragment('render_matches_panel'),
            'review' => $this->render_fragment('render_review_panel'),
            'ignored' => $this->render_fragment('render_ignored_panel'),
            'inventory' => $this->render_fragment('render_inventory_panel'),
        );
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Plugin Discovery Intelligence', 'sustainable-catalyst-feature-suggestions'),
            __('Plugin Discovery', 'sustainable-catalyst-feature-suggestions'),
            $this->capability(),
            self::ADMIN_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!$this->can_manage()) {
            wp_die(esc_html__('You do not have permission to manage plugin discovery.', 'sustainable-catalyst-feature-suggestions'));
        }
        $snapshot = $this->snapshot();
        echo '<div class="wrap scfs-plugin-discovery"><h1>' . esc_html__('Installed Plugin Discovery', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p>' . esc_html__('Map installed plugins directly to governed Canonical Product Registry records. Automatic evidence remains advisory; administrator decisions are explicit, reversible, and private.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<div class="notice notice-success inline scfs-discovery-live-notice" data-scfs-discovery-notice role="status" aria-live="polite" hidden><p></p></div>';
        if (isset($_GET['rescanned'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Installed plugins rescanned and registry evidence refreshed.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        } elseif (isset($_GET['decision_updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Plugin discovery decision saved and the registry was refreshed.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        } elseif (isset($_GET['bulk_updated'])) {
            $message = get_transient('scfs_plugin_discovery_bulk_' . get_current_user_id());
            delete_transient('scfs_plugin_discovery_bulk_' . get_current_user_id());
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message ?: __('Bulk plugin discovery decisions applied.', 'sustainable-catalyst-feature-suggestions')) . '</p></div>';
        } elseif (isset($_GET['connection_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Canonical product, active plugin, and GitHub repository connection saved.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        } elseif (isset($_GET['github_synced'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('GitHub release intelligence synchronized with the Release Console.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        } elseif (isset($_GET['github_sync_error'])) {
            $error_key = 'scfs_github_sync_error_' . get_current_user_id();
            $error_message = get_transient($error_key);
            delete_transient($error_key);
            echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__('GitHub synchronization failed.', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html($error_message ?: __('Open GitHub Connection to test repository access.', 'sustainable-catalyst-feature-suggestions')) . '</p></div>';
        }
        $this->render_summary_panel();
        echo '<p class="scfs-discovery-actions"><a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_rescan_installed_plugins'), 'scfs_rescan_installed_plugins')) . '">' . esc_html__('Rescan installed plugins', 'sustainable-catalyst-feature-suggestions') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . SCFS_GitHub_Connection_Settings::ADMIN_SLUG)) . '">' . esc_html__('GitHub Connection', 'sustainable-catalyst-feature-suggestions') . '</a> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_clear_plugin_discovery'), 'scfs_clear_plugin_discovery')) . '">' . esc_html__('Clear cached snapshot', 'sustainable-catalyst-feature-suggestions') . '</a></p>';
        echo '<p><strong>' . esc_html__('Last scan:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html($snapshot['scanned_at'] ?: __('Not yet scanned', 'sustainable-catalyst-feature-suggestions')) . '</p>';
        $this->render_inventory_panel();
        $this->render_connections_panel();
        $this->render_matches_panel();
        $this->render_review_panel();
        $this->render_ignored_panel();
        $this->render_diagnostics_panel();
        echo '</div>';
    }

    public function rescan_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer('scfs_rescan_installed_plugins');
        $this->refresh(true, 'admin_rescan');
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&rescanned=1'));
        exit;
    }

    public function clear_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer('scfs_clear_plugin_discovery');
        $this->invalidate_cache('admin_clear');
        update_option(self::SNAPSHOT_OPTION, $this->empty_snapshot(), false);
        update_option(self::PENDING_OPTION, array(), false);
        update_option(self::DIAGNOSTICS_OPTION, $this->empty_diagnostics(), false);
        $this->audit('plugin_discovery_cleared');
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG));
        exit;
    }

    public function decision_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::DECISION_NONCE, 'scfs_plugin_discovery_nonce');
        $plugin_file = isset($_POST['plugin_file']) ? wp_unslash($_POST['plugin_file']) : '';
        $decision = isset($_POST['decision']) ? wp_unslash($_POST['decision']) : '__review__';
        $result = $this->apply_candidate_decision($plugin_file, $decision);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&decision_updated=1'));
        exit;
    }

    public function register_rest_routes() {
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry/discovery', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_status'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry/discovery/rescan', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_rescan'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry/discovery/bulk', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_bulk'),
            'permission_callback' => array($this, 'rest_permission'),
            'args' => array(
                'plugin_files' => array('required' => true, 'type' => 'array'),
                'bulk_decision' => array('required' => true, 'type' => 'string'),
            ),
        ));
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry/discovery/diagnostics', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_diagnostics'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry/discovery/decision', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_decision'),
            'permission_callback' => array($this, 'rest_permission'),
            'args' => array(
                'plugin_file' => array('required' => true, 'type' => 'string'),
                'decision' => array('required' => true, 'type' => 'string'),
            ),
        ));
    }

    public function rest_permission() {
        return current_user_can($this->capability());
    }

    public function rest_status() {
        return rest_ensure_response(array(
            'ok' => true,
            'schema' => $this->schema_record(),
            'snapshot' => $this->snapshot(),
            'pending' => $this->pending_candidates(),
            'ignored' => $this->ignored_candidates(),
            'manual_mappings' => array_values($this->manual_mappings()),
            'diagnostics' => $this->diagnostics(),
            'inventory' => $this->plugin_inventory(),
        ));
    }

    public function rest_rescan() {
        return rest_ensure_response(array(
            'ok' => true,
            'snapshot' => $this->refresh(true, 'rest_rescan'),
            'pending' => $this->pending_candidates(),
            'ignored' => $this->ignored_candidates(),
            'manual_mappings' => array_values($this->manual_mappings()),
            'fragments' => $this->fragment_payload(),
            'diagnostics' => $this->diagnostics(),
        ));
    }

    public function rest_decision($request) {
        $result = $this->apply_candidate_decision($request->get_param('plugin_file'), $request->get_param('decision'));
        if (is_wp_error($result)) {
            $result->add_data(array('status' => 400));
            return $result;
        }
        return rest_ensure_response(array(
            'ok' => true,
            'message' => $result['message'] ?? __('Plugin discovery decision saved.', 'sustainable-catalyst-feature-suggestions'),
            'snapshot' => $this->snapshot(),
            'pending' => $this->pending_candidates(),
            'ignored' => $this->ignored_candidates(),
            'manual_mappings' => array_values($this->manual_mappings()),
            'fragments' => $this->fragment_payload(),
        ));
    }

    public function rest_bulk($request) {
        $result = $this->apply_bulk_decision($request->get_param('plugin_files'), $request->get_param('bulk_decision'));
        if (is_wp_error($result)) {
            $result->add_data(array('status' => 400));
            return $result;
        }
        return rest_ensure_response(array(
            'ok' => true,
            'message' => $result['message'],
            'snapshot' => $this->snapshot(),
            'fragments' => $this->fragment_payload(),
        ));
    }

    public function rest_diagnostics() {
        return rest_ensure_response(array('ok' => true, 'diagnostics' => $this->diagnostics()));
    }

    public function cli_discover($args, $assoc_args) {
        $snapshot = $this->refresh(true, 'cli_rescan');
        WP_CLI::success(sprintf('Plugin discovery matched %d approved products; %d candidates require review; %d diagnostics recorded.', $snapshot['matched_product_count'], $snapshot['pending_candidate_count'], $snapshot['diagnostic_count']));
        if (!empty($assoc_args['format']) && $assoc_args['format'] === 'json') {
            WP_CLI::line(wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function cli_status($args, $assoc_args) {
        WP_CLI::line(wp_json_encode(array(
            'schema' => $this->schema_record(),
            'summary' => $this->summary_record(),
            'matches' => $this->snapshot()['matches'],
            'pending' => $this->pending_candidates(),
            'ignored' => $this->ignored_candidates(),
            'manual_mappings' => array_values($this->manual_mappings()),
            'diagnostics' => $this->diagnostics(),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function cli_diagnostics($args, $assoc_args) {
        WP_CLI::line(wp_json_encode($this->diagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function audit($action, $details = array()) {
        $log = (array) get_option(self::AUDIT_OPTION, array());
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $log[] = array(
            'timestamp' => gmdate('c'),
            'action' => sanitize_key($action),
            'user' => $user && $user->exists() ? $user->user_login : 'system',
            'details' => $details,
        );
        if (count($log) > self::MAX_AUDIT_ENTRIES) {
            $log = array_slice($log, -self::MAX_AUDIT_ENTRIES);
        }
        update_option(self::AUDIT_OPTION, $log, false);
    }
}
