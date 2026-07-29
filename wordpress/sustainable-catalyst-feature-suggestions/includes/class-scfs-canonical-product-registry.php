<?php
/**
 * Canonical Product Registry.
 *
 * Maintains the governed product catalog used by future release-board,
 * documentation, support, and product-intelligence surfaces. Product
 * classifications remain separate from the existing support taxonomies so
 * public product identity can evolve without disturbing case relationships.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Canonical_Product_Registry {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-canonical-product-registry/2.1';
    const OPTION_KEY = 'scfs_canonical_product_registry';
    const SCHEMA_OPTION = 'scfs_canonical_product_registry_schema';
    const AUDIT_OPTION = 'scfs_canonical_product_registry_audit';
    const ADMIN_SLUG = 'scfs-product-registry';
    const NONCE_ACTION = 'scfs_save_product_registry';
    const MAX_AUDIT_ENTRIES = 250;
    const MIGRATION_OPTION = 'scfs_canonical_product_registry_migrations';
    const STALE_AFTER_DAYS = 90;
    const RECENT_RELEASE_DAYS = 45;
    const RELEASE_MIGRATION_OPTION = 'scfs_product_registry_release_intelligence_version';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade'), 3);
        add_action('admin_menu', array($this, 'register_admin_page'), 21);
        add_action('admin_post_scfs_save_product_registry', array($this, 'save_registry_action'));
        add_action('admin_post_scfs_reset_product_registry', array($this, 'reset_registry_action'));
        add_action('admin_post_scfs_export_product_registry', array($this, 'export_registry_action'));
        add_action('admin_post_scfs_validate_product_registry', array($this, 'validate_registry_action'));
        add_action('admin_post_scfs_migrate_product_registry', array($this, 'migrate_registry_action'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('scfs products list', array($this, 'cli_list'));
            WP_CLI::add_command('scfs products export', array($this, 'cli_export'));
            WP_CLI::add_command('scfs products seed', array($this, 'cli_seed'));
            WP_CLI::add_command('scfs products validate', array($this, 'cli_validate'));
            WP_CLI::add_command('scfs products migrate', array($this, 'cli_migrate'));
        }
    }

    public static function activate() {
        $instance = self::instance();
        $existing = get_option(self::OPTION_KEY, array());
        $needs_upgrade = get_option(self::SCHEMA_OPTION) !== self::SCHEMA;
        $registry = (!is_array($existing) || !$existing)
            ? $instance->default_products()
            : $instance->merge_defaults($existing);
        if ($needs_upgrade) {
            $registry = $instance->apply_v740_governance_migrations($registry, (string) get_option(self::SCHEMA_OPTION, 'legacy'));
        }
        $registry = $instance->apply_v750_release_intelligence_migrations($registry);
        if (!is_array($existing) || !$existing) {
            add_option(self::OPTION_KEY, $registry, '', false);
        } else {
            update_option(self::OPTION_KEY, $registry, false);
        }
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
        update_option(self::RELEASE_MIGRATION_OPTION, self::VERSION, false);
        if (!get_option(self::AUDIT_OPTION)) {
            add_option(self::AUDIT_OPTION, array(), '', false);
        }
    }

    public function maybe_upgrade() {
        $schema_current = get_option(self::SCHEMA_OPTION) === self::SCHEMA;
        $release_current = get_option(self::RELEASE_MIGRATION_OPTION) === self::VERSION;
        if ($schema_current && $release_current) {
            return;
        }
        $existing = get_option(self::OPTION_KEY, array());
        $registry = $this->merge_defaults(is_array($existing) ? $existing : array());
        $from_schema = (string) get_option(self::SCHEMA_OPTION, 'legacy');
        if (!$schema_current) {
            $registry = $this->apply_v740_governance_migrations($registry, $from_schema);
        }
        $registry = $this->apply_v750_release_intelligence_migrations($registry);
        update_option(self::OPTION_KEY, $registry, false);
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
        update_option(self::RELEASE_MIGRATION_OPTION, self::VERSION, false);
        $this->audit('registry_release_intelligence_upgraded', array(
            'from_schema' => $from_schema,
            'schema' => self::SCHEMA,
            'release_migration' => 'v7.6.1',
            'knowledge_library_homepage_visible' => true,
            'analytics_r_public_label' => 'Analytics R',
        ));
        do_action('scfs_product_registry_updated', $registry);
    }

    public function capability() {
        return apply_filters('scfs_product_registry_capability', 'manage_options');
    }

    public function can_manage() {
        return current_user_can($this->capability());
    }

    public function families() {
        return array(
            'foundation' => __('Foundation', 'sustainable-catalyst-feature-suggestions'),
            'research-intelligence' => __('Research and Intelligence', 'sustainable-catalyst-feature-suggestions'),
            'data-analysis' => __('Data and Analysis', 'sustainable-catalyst-feature-suggestions'),
            'creation-systems' => __('Creation and Systems', 'sustainable-catalyst-feature-suggestions'),
            'commercial' => __('Commercial Release', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function version_sources() {
        return array(
            'wordpress_plugin' => __('Installed WordPress plugin', 'sustainable-catalyst-feature-suggestions'),
            'github_release' => __('GitHub release', 'sustainable-catalyst-feature-suggestions'),
            'github_prerelease' => __('GitHub prerelease', 'sustainable-catalyst-feature-suggestions'),
            'github_tag' => __('GitHub version tag', 'sustainable-catalyst-feature-suggestions'),
            'manual' => __('Manual entry', 'sustainable-catalyst-feature-suggestions'),
            'remote_manifest' => __('Remote manifest (reserved)', 'sustainable-catalyst-feature-suggestions'),
            'service_endpoint' => __('Service endpoint (reserved)', 'sustainable-catalyst-feature-suggestions'),
            'package_manifest' => __('Package manifest (reserved)', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function statuses() {
        return array(
            'current' => __('Current', 'sustainable-catalyst-feature-suggestions'),
            'stable' => __('Stable', 'sustainable-catalyst-feature-suggestions'),
            'development' => __('Development', 'sustainable-catalyst-feature-suggestions'),
            'preview' => __('Preview', 'sustainable-catalyst-feature-suggestions'),
            'private_beta' => __('Private beta', 'sustainable-catalyst-feature-suggestions'),
            'release_candidate' => __('Release candidate', 'sustainable-catalyst-feature-suggestions'),
            'maintenance' => __('Maintenance', 'sustainable-catalyst-feature-suggestions'),
            'update_available' => __('Update available', 'sustainable-catalyst-feature-suggestions'),
            'inactive' => __('Inactive', 'sustainable-catalyst-feature-suggestions'),
            'deprecated' => __('Deprecated', 'sustainable-catalyst-feature-suggestions'),
            'unavailable' => __('Unavailable', 'sustainable-catalyst-feature-suggestions'),
            'unverified' => __('Unverified', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function channels() {
        return array(
            'stable' => __('Stable', 'sustainable-catalyst-feature-suggestions'),
            'development' => __('Development', 'sustainable-catalyst-feature-suggestions'),
            'preview' => __('Preview', 'sustainable-catalyst-feature-suggestions'),
            'private_beta' => __('Private beta', 'sustainable-catalyst-feature-suggestions'),
            'release_candidate' => __('Release candidate', 'sustainable-catalyst-feature-suggestions'),
            'maintenance' => __('Maintenance', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function product_types() {
        return array(
            'wordpress_plugin' => __('WordPress plugin', 'sustainable-catalyst-feature-suggestions'),
            'python_application' => __('Python application', 'sustainable-catalyst-feature-suggestions'),
            'r_package' => __('R package', 'sustainable-catalyst-feature-suggestions'),
            'hosted_service' => __('Hosted service', 'sustainable-catalyst-feature-suggestions'),
            'platform_module' => __('Platform module', 'sustainable-catalyst-feature-suggestions'),
            'multi_runtime_platform' => __('Multi-runtime platform', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function lifecycle_states() {
        return array(
            'planned' => __('Planned', 'sustainable-catalyst-feature-suggestions'),
            'experimental' => __('Experimental', 'sustainable-catalyst-feature-suggestions'),
            'active' => __('Active', 'sustainable-catalyst-feature-suggestions'),
            'maintenance' => __('Maintenance', 'sustainable-catalyst-feature-suggestions'),
            'deprecated' => __('Deprecated', 'sustainable-catalyst-feature-suggestions'),
            'superseded' => __('Superseded', 'sustainable-catalyst-feature-suggestions'),
            'retired' => __('Retired', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function version_precedence_options() {
        return array(
            'github' => __('GitHub release first', 'sustainable-catalyst-feature-suggestions'),
            'manual' => __('Configured public version first', 'sustainable-catalyst-feature-suggestions'),
            'discovered' => __('Discovered version first', 'sustainable-catalyst-feature-suggestions'),
            'installed' => __('Installed version first', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function verification_sources() {
        return array(
            'registry_seed' => __('Registry seed', 'sustainable-catalyst-feature-suggestions'),
            'administrator' => __('Administrator review', 'sustainable-catalyst-feature-suggestions'),
            'wordpress_plugin' => __('Installed WordPress plugin', 'sustainable-catalyst-feature-suggestions'),
            'github' => __('GitHub repository', 'sustainable-catalyst-feature-suggestions'),
            'release_manifest' => __('Governed release manifest', 'sustainable-catalyst-feature-suggestions'),
            'service_endpoint' => __('Service endpoint', 'sustainable-catalyst-feature-suggestions'),
            'package_manifest' => __('Package manifest', 'sustainable-catalyst-feature-suggestions'),
            'migration' => __('Schema migration', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function validation_states() {
        return array(
            'validated' => __('Validated', 'sustainable-catalyst-feature-suggestions'),
            'partial' => __('Partial', 'sustainable-catalyst-feature-suggestions'),
            'pending' => __('Pending', 'sustainable-catalyst-feature-suggestions'),
            'failed' => __('Failed', 'sustainable-catalyst-feature-suggestions'),
            'unavailable' => __('Unavailable', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function documentation_states() {
        return array(
            'ready' => __('Ready', 'sustainable-catalyst-feature-suggestions'),
            'partial' => __('Partial', 'sustainable-catalyst-feature-suggestions'),
            'missing' => __('Missing', 'sustainable-catalyst-feature-suggestions'),
            'unavailable' => __('Unavailable', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    private function product($id, $name, $short_name, $family, $order, $overrides = array()) {
        return array_merge(array(
            'canonical_id' => $id,
            'name' => $name,
            'short_name' => $short_name,
            'internal_name' => $name,
            'product_description' => '',
            'repository_slug' => '',
            'github_repository_url' => '',
            'github_repository_private' => '',
            'github_repository_visibility' => '',
            'github_authentication_source' => '',
            'github_default_branch' => 'main',
            'github_sync_enabled' => '1',
            'github_sync_locked' => '',
            'github_sync_state' => 'unconfigured',
            'github_latest_version' => '',
            'github_latest_release_name' => '',
            'github_latest_release_url' => '',
            'github_latest_release_at' => '',
            'github_latest_tag_name' => '',
            'github_latest_tag_url' => '',
            'github_version_source' => '',
            'github_latest_commit_sha' => '',
            'github_repository_updated_at' => '',
            'github_last_synced_at' => '',
            'github_sync_message' => '',
            'github_connection_state' => 'unconfigured',
            'github_sync_error_code' => '',
            'github_sync_http_status' => '',
            'github_sync_endpoint' => '',
            'github_sync_endpoint_url' => '',
            'github_sync_failed_at' => '',
            'github_rate_limit_remaining' => '',
            'github_rate_limit_reset' => '',
            'github_commit_sync_warning' => '',
            'github_commit_sync_http_status' => '',
            'github_commit_sync_endpoint' => '',
            'legacy_names' => array(),
            'family' => $family,
            'console_screen' => $family,
            'console_badges' => array(),
            'product_type' => 'wordpress_plugin',
            'version_source' => 'wordpress_plugin',
            'version_precedence' => 'discovered',
            'plugin_file' => '',
            'plugin_slug' => '',
            'plugin_text_domain' => '',
            'legacy_plugin_files' => array(),
            'legacy_plugin_slugs' => array(),
            'legacy_text_domains' => array(),
            'discovery_enabled' => '1',
            'discovery_locked' => '',
            'discovery_state' => 'unscanned',
            'discovery_match' => '',
            'discovered_active' => '',
            'discovered_activation_scope' => 'inactive',
            'discovered_plugin_name' => '',
            'discovered_plugin_version' => '',
            'discovered_plugin_version_raw' => '',
            'discovered_version_state' => 'unscanned',
            'discovered_text_domain' => '',
            'last_discovered_at' => '',
            'installed_version' => '',
            'public_version' => '',
            'release_channel' => 'stable',
            'status' => 'unverified',
            'lifecycle_state' => 'active',
            'superseded_by' => '',
            'public_visible' => '1',
            'homepage_visible' => '1',
            'display_order' => $order,
            'product_url' => '',
            'documentation_url' => '',
            'support_url' => '/support/',
            'release_notes_url' => '',
            'previous_version' => '',
            'release_date' => '',
            'change_summary' => '',
            'validation_state' => 'pending',
            'documentation_state' => 'unavailable',
            'known_issue_count' => 0,
            'owner' => 'Content Catalyst LLC',
            'commercial' => '',
            'public_interest' => '1',
            'manual_notes' => '',
            'verification_source' => 'registry_seed',
            'source_verified_at' => '',
            'record_updated_at' => '',
            'last_verified_at' => '',
            'archived' => '',
            'archived_at' => '',
            'archived_by' => '',
        ), $overrides);
    }

    public function new_product_record($id, $name, $short_name, $family, $order, $overrides = array()) {
        $id = sanitize_key($id);
        $families = array_keys($this->families());
        $family = in_array(sanitize_key($family), $families, true) ? sanitize_key($family) : 'foundation';
        return $this->normalize_record($id, $this->product($id, sanitize_text_field($name), sanitize_text_field($short_name), $family, absint($order), $overrides));
    }

    public function default_products() {
        $plugin_file = 'sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php';
        return array(
            'sustainable-catalyst-core' => $this->product('sustainable-catalyst-core', 'Sustainable Catalyst Core', 'Core', 'foundation', 10, array('plugin_slug' => 'sustainable-catalyst-core')),
            'product-support-feedback' => $this->product('product-support-feedback', 'Sustainable Catalyst Product Support and Feedback Platform', 'Support and Feedback', 'foundation', 20, array(
                'legacy_names' => array('Feature Suggestions', 'Sustainable Catalyst Feature Suggestions'),
                'repository_slug' => 'sustainable-catalyst-product-support-feedback',
                'github_repository_url' => 'https://github.com/Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback',
                'plugin_file' => $plugin_file,
                'plugin_slug' => 'sustainable-catalyst-feature-suggestions',
                'legacy_plugin_files' => array(
                    'sustainable-catalyst-product-support-feedback/sustainable-catalyst-product-support-feedback.php',
                    'sustainable-catalyst-product-support-feedback-platform/sustainable-catalyst-product-support-feedback-platform.php',
                ),
                'legacy_plugin_slugs' => array('sustainable-catalyst-product-support-feedback', 'sustainable-catalyst-product-support-feedback-platform'),
                'legacy_text_domains' => array('sustainable-catalyst-product-support-feedback', 'sustainable-catalyst-product-support-feedback-platform'),
                'installed_version' => self::VERSION,
                'public_version' => self::VERSION,
                'status' => 'current',
                'last_verified_at' => gmdate('c'),
            )),
            'contact-engagement' => $this->product('contact-engagement', 'Sustainable Catalyst Contact and Engagement Platform', 'Contact and Engagement', 'foundation', 30, array('plugin_slug' => 'sustainable-catalyst-contact-engagement')),
            'knowledge-library' => $this->product('knowledge-library', 'Sustainable Catalyst Knowledge Library', 'Knowledge Library', 'foundation', 40, array('plugin_slug' => 'sustainable-catalyst-library', 'product_url' => '/library/')),
            'research-librarian' => $this->product('research-librarian', 'Sustainable Catalyst Research Librarian', 'Research Librarian', 'research-intelligence', 110, array('plugin_slug' => 'sustainable-catalyst-research-librarian')),
            'site-intelligence' => $this->product('site-intelligence', 'Sustainable Catalyst Site Intelligence', 'Site Intelligence', 'research-intelligence', 120, array('plugin_slug' => 'sustainable-catalyst-site-intelligence')),
            'decision-studio' => $this->product('decision-studio', 'Sustainable Catalyst Decision Studio', 'Decision Studio', 'research-intelligence', 130, array('plugin_slug' => 'sustainable-catalyst-decision-studio')),
            'narrative-risk' => $this->product('narrative-risk', 'Catalyst Narrative Risk', 'Narrative Risk', 'research-intelligence', 140, array('plugin_slug' => 'catalyst-narrative-risk')),
            'catalyst-data' => $this->product('catalyst-data', 'Catalyst Data', 'Catalyst Data', 'data-analysis', 210, array('plugin_slug' => 'catalyst-data')),
            'catalyst-analytics-r' => $this->product('catalyst-analytics-r', 'Catalyst Analytics R', 'Analytics R', 'data-analysis', 220, array('product_type' => 'r_package', 'version_source' => 'manual', 'discovery_enabled' => '', 'version_precedence' => 'manual')),
            'catalyst-finance' => $this->product('catalyst-finance', 'Catalyst Finance', 'Catalyst Finance', 'data-analysis', 230, array('plugin_slug' => 'catalyst-finance')),
            'global-impact-catalyst' => $this->product('global-impact-catalyst', 'Global Impact Catalyst', 'Global Impact Catalyst', 'data-analysis', 240, array('plugin_slug' => 'global-impact-catalyst')),
            'catalyst-canvas' => $this->product('catalyst-canvas', 'Catalyst Canvas', 'Catalyst Canvas', 'creation-systems', 310, array('plugin_slug' => 'catalyst-canvas')),
            'catalyst-grit' => $this->product('catalyst-grit', 'Catalyst Grit', 'Catalyst Grit', 'creation-systems', 320, array('plugin_slug' => 'catalyst-grit')),
            'workbench' => $this->product('workbench', 'Sustainable Catalyst Workbench', 'Workbench', 'creation-systems', 330, array('plugin_slug' => 'sustainable-catalyst-workbench')),
            'sustainable-catalyst-lab' => $this->product('sustainable-catalyst-lab', 'Sustainable Catalyst Lab', 'Lab', 'creation-systems', 340, array('plugin_slug' => 'sustainable-catalyst-lab')),
            'catalyst-intelligence' => $this->product('catalyst-intelligence', 'Catalyst Intelligence Platform', 'Catalyst Intelligence', 'commercial', 410, array(
                'product_type' => 'multi_runtime_platform',
                'version_source' => 'manual',
                'version_precedence' => 'manual',
                'discovery_enabled' => '',
                'installed_version' => '',
                'public_version' => '0.23.1',
                'release_channel' => 'development',
                'status' => 'development',
                'commercial' => '1',
                'public_interest' => '',
                'manual_notes' => 'Private commercial platform. Public version is maintained manually until a governed release manifest is available.',
                'last_verified_at' => gmdate('c'),
            )),
        );
    }

    public function merge_defaults($existing) {
        $merged = is_array($existing) ? $existing : array();
        foreach ($this->default_products() as $id => $record) {
            if (!isset($merged[$id]) || !is_array($merged[$id])) {
                $merged[$id] = $record;
                continue;
            }
            $merged[$id] = array_merge($record, $merged[$id]);
        }
        $merged['product-support-feedback']['installed_version'] = self::VERSION;
        $merged['product-support-feedback']['public_version'] = self::VERSION;
        $merged['product-support-feedback']['status'] = 'current';
        $merged['product-support-feedback']['previous_version'] = '7.5.4';
        $merged['product-support-feedback']['release_date'] = '2026-07-23';
        $merged['product-support-feedback']['change_summary'] = 'Single-record Product Connection Editor for canonical identity, active plugin, GitHub, console presentation, routes, aliases, validation, and history.';
        $merged['product-support-feedback']['validation_state'] = 'validated';
        $merged['product-support-feedback']['documentation_state'] = 'ready';
        return $this->normalize_registry($merged);
    }

    private function apply_v731_presentation_migrations($records) {
        $records = is_array($records) ? $records : array();
        if (isset($records['knowledge-library']) && is_array($records['knowledge-library'])) {
            $records['knowledge-library']['public_visible'] = '1';
            $records['knowledge-library']['homepage_visible'] = '1';
            $records['knowledge-library']['short_name'] = 'Knowledge Library';
        }
        if (isset($records['catalyst-analytics-r']) && is_array($records['catalyst-analytics-r'])) {
            $legacy_names = isset($records['catalyst-analytics-r']['legacy_names'])
                ? (array) $records['catalyst-analytics-r']['legacy_names']
                : array();
            $legacy_names[] = 'Catalyst AnalyticsR';
            $records['catalyst-analytics-r']['legacy_names'] = array_values(array_unique($legacy_names));
            $records['catalyst-analytics-r']['name'] = 'Catalyst Analytics R';
            $records['catalyst-analytics-r']['short_name'] = 'Analytics R';
        }
        return $this->normalize_registry($records);
    }

    public function apply_v740_governance_migrations($records, $from_schema = 'legacy') {
        $records = $this->apply_v731_presentation_migrations($records);
        $migrated_at = gmdate('c');
        foreach ($records as $id => &$record) {
            if (!is_array($record)) {
                continue;
            }
            $record['internal_name'] = isset($record['internal_name']) && $record['internal_name'] !== '' ? $record['internal_name'] : ($record['name'] ?? $id);
            $record['repository_slug'] = isset($record['repository_slug']) ? $record['repository_slug'] : '';
            $record['console_screen'] = isset($record['console_screen']) && $record['console_screen'] !== '' ? $record['console_screen'] : ($record['family'] ?? 'foundation');
            $record['lifecycle_state'] = isset($record['lifecycle_state']) && $record['lifecycle_state'] !== '' ? $record['lifecycle_state'] : $this->legacy_lifecycle_state($record);
            $record['version_precedence'] = isset($record['version_precedence']) && $record['version_precedence'] !== ''
                ? $record['version_precedence']
                : (($record['version_source'] ?? '') === 'manual' ? 'manual' : 'discovered');
            $record['verification_source'] = isset($record['verification_source']) && $record['verification_source'] !== '' ? $record['verification_source'] : 'migration';
            $record['source_verified_at'] = isset($record['source_verified_at']) && $record['source_verified_at'] !== ''
                ? $record['source_verified_at']
                : ($record['last_verified_at'] ?? '');
            $record['record_updated_at'] = isset($record['record_updated_at']) && $record['record_updated_at'] !== '' ? $record['record_updated_at'] : $migrated_at;
            $record['superseded_by'] = isset($record['superseded_by']) ? $record['superseded_by'] : '';
        }
        unset($record);
        $normalized = $this->normalize_registry($records);
        $this->record_migration($from_schema, self::SCHEMA, count($normalized));
        return $normalized;
    }

    public function apply_v750_release_intelligence_migrations($records) {
        $records = is_array($records) ? $records : array();
        foreach ($records as $id => &$record) {
            if (!is_array($record)) {
                continue;
            }
            $record['previous_version'] = isset($record['previous_version']) ? $record['previous_version'] : '';
            $record['release_date'] = isset($record['release_date']) ? $record['release_date'] : '';
            $record['change_summary'] = isset($record['change_summary']) ? $record['change_summary'] : '';
            $record['validation_state'] = isset($record['validation_state']) && $record['validation_state'] !== '' ? $record['validation_state'] : 'pending';
            $record['documentation_state'] = isset($record['documentation_state']) && $record['documentation_state'] !== '' ? $record['documentation_state'] : (!empty($record['documentation_url']) ? 'ready' : 'unavailable');
            $record['known_issue_count'] = isset($record['known_issue_count']) ? absint($record['known_issue_count']) : 0;
        }
        unset($record);
        if (isset($records['product-support-feedback']) && is_array($records['product-support-feedback'])) {
            $records['product-support-feedback']['previous_version'] = '7.5.2';
            $records['product-support-feedback']['release_date'] = '2026-07-22';
            $records['product-support-feedback']['change_summary'] = 'Active plugin connections, GitHub release synchronization, and repository-linked Release Console evidence.';
            $records['product-support-feedback']['validation_state'] = 'validated';
            $records['product-support-feedback']['documentation_state'] = 'ready';
        }
        return $this->normalize_registry($records);
    }

    private function legacy_lifecycle_state($record) {
        $status = sanitize_key($record['status'] ?? 'unverified');
        if ($status === 'maintenance') {
            return 'maintenance';
        }
        if (in_array($status, array('inactive', 'deprecated', 'unavailable'), true)) {
            return 'retired';
        }
        if (in_array($status, array('development', 'preview', 'private_beta', 'release_candidate'), true)) {
            return 'planned';
        }
        return 'active';
    }

    private function record_migration($from_schema, $to_schema, $product_count) {
        $history = (array) get_option(self::MIGRATION_OPTION, array());
        $last = $history ? end($history) : array();
        if (($last['to_schema'] ?? '') === $to_schema && absint($last['product_count'] ?? 0) === absint($product_count)) {
            return;
        }
        $history[] = array(
            'migrated_at' => gmdate('c'),
            'from_schema' => sanitize_text_field($from_schema ?: 'legacy'),
            'to_schema' => sanitize_text_field($to_schema),
            'product_count' => absint($product_count),
        );
        update_option(self::MIGRATION_OPTION, array_slice($history, -50), false);
    }

    public function registry() {
        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved) || !$saved) {
            return $this->default_products();
        }
        return $this->normalize_registry($saved);
    }

    public function get_product($product_id) {
        $product_id = sanitize_key($product_id);
        $registry = $this->registry();
        return isset($registry[$product_id]) ? $registry[$product_id] : null;
    }

    public function normalize_registry($records) {
        $normalized = array();
        foreach ((array) $records as $key => $record) {
            if (!is_array($record)) {
                continue;
            }
            $key_id = is_string($key) ? sanitize_key($key) : '';
            $record_id = sanitize_key(isset($record['canonical_id']) ? $record['canonical_id'] : '');
            $id = $record_id !== '' ? $record_id : $key_id;
            if (!$id || isset($normalized[$id])) {
                continue;
            }
            $normalized[$id] = $this->normalize_record($id, $record);
        }
        uasort($normalized, function ($left, $right) {
            $order = absint($left['display_order']) <=> absint($right['display_order']);
            return $order !== 0 ? $order : strcasecmp($left['name'], $right['name']);
        });
        return $normalized;
    }

    private function normalize_record($id, $record) {
        $families = array_keys($this->families());
        $sources = array_keys($this->version_sources());
        $statuses = array_keys($this->statuses());
        $channels = array_keys($this->channels());
        $types = array_keys($this->product_types());
        $lifecycle_states = array_keys($this->lifecycle_states());
        $precedence_options = array_keys($this->version_precedence_options());
        $verification_sources = array_keys($this->verification_sources());
        $legacy = isset($record['legacy_names']) ? $record['legacy_names'] : array();
        if (is_string($legacy)) {
            $legacy = preg_split('/[\r\n,]+/', $legacy);
        }
        $legacy = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $legacy))));
        $legacy_plugin_files = isset($record['legacy_plugin_files']) ? $record['legacy_plugin_files'] : array();
        $legacy_plugin_slugs = isset($record['legacy_plugin_slugs']) ? $record['legacy_plugin_slugs'] : array();
        $legacy_text_domains = isset($record['legacy_text_domains']) ? $record['legacy_text_domains'] : array();
        foreach (array('legacy_plugin_files', 'legacy_plugin_slugs', 'legacy_text_domains') as $list_key) {
            if (is_string($$list_key)) {
                $$list_key = preg_split('/[\r\n,]+/', $$list_key);
            }
        }
        $legacy_plugin_files = array_values(array_unique(array_filter(array_map(function ($value) {
            $value = str_replace('\\', '/', sanitize_text_field($value));
            return ltrim(preg_replace('#/+#', '/', $value), '/');
        }, (array) $legacy_plugin_files))));
        $legacy_plugin_slugs = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $legacy_plugin_slugs))));
        $legacy_text_domains = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $legacy_text_domains))));
        $family = sanitize_key(isset($record['family']) ? $record['family'] : 'foundation');
        $source = sanitize_key(isset($record['version_source']) ? $record['version_source'] : 'manual');
        $status = sanitize_key(isset($record['status']) ? $record['status'] : 'unverified');
        $channel = sanitize_key(isset($record['release_channel']) ? $record['release_channel'] : 'stable');
        $type = sanitize_key(isset($record['product_type']) ? $record['product_type'] : 'platform_module');
        $lifecycle_state = sanitize_key(isset($record['lifecycle_state']) ? $record['lifecycle_state'] : 'active');
        $version_precedence = sanitize_key(isset($record['version_precedence']) ? $record['version_precedence'] : (($source === 'manual') ? 'manual' : 'discovered'));
        $verification_source = sanitize_key(isset($record['verification_source']) ? $record['verification_source'] : 'registry_seed');
        $validation_state = sanitize_key(isset($record['validation_state']) ? $record['validation_state'] : 'pending');
        $documentation_state = sanitize_key(isset($record['documentation_state']) ? $record['documentation_state'] : 'unavailable');
        $activation_scope = sanitize_key(isset($record['discovered_activation_scope']) ? $record['discovered_activation_scope'] : 'inactive');
        $version_state = sanitize_key(isset($record['discovered_version_state']) ? $record['discovered_version_state'] : 'unscanned');
        return array(
            'canonical_id' => $id,
            'name' => sanitize_text_field(isset($record['name']) ? $record['name'] : $id),
            'short_name' => sanitize_text_field(isset($record['short_name']) ? $record['short_name'] : ''),
            'internal_name' => sanitize_text_field(isset($record['internal_name']) ? $record['internal_name'] : (isset($record['name']) ? $record['name'] : $id)),
            'product_description' => sanitize_textarea_field(isset($record['product_description']) ? $record['product_description'] : ''),
            'repository_slug' => sanitize_key(isset($record['repository_slug']) ? $record['repository_slug'] : ''),
            'github_repository_url' => esc_url_raw(isset($record['github_repository_url']) ? $record['github_repository_url'] : ''),
            'github_repository_private' => !empty($record['github_repository_private']) ? '1' : '',
            'github_repository_visibility' => sanitize_key(isset($record['github_repository_visibility']) ? $record['github_repository_visibility'] : ''),
            'github_authentication_source' => sanitize_key(isset($record['github_authentication_source']) ? $record['github_authentication_source'] : ''),
            'github_default_branch' => sanitize_text_field(isset($record['github_default_branch']) && $record['github_default_branch'] !== '' ? $record['github_default_branch'] : 'main'),
            'github_sync_enabled' => !empty($record['github_sync_enabled']) ? '1' : '',
            'github_sync_locked' => !empty($record['github_sync_locked']) ? '1' : '',
            'github_sync_state' => sanitize_key(isset($record['github_sync_state']) ? $record['github_sync_state'] : 'unconfigured'),
            'github_latest_version' => sanitize_text_field(isset($record['github_latest_version']) ? $record['github_latest_version'] : ''),
            'github_latest_release_name' => sanitize_text_field(isset($record['github_latest_release_name']) ? $record['github_latest_release_name'] : ''),
            'github_latest_release_url' => esc_url_raw(isset($record['github_latest_release_url']) ? $record['github_latest_release_url'] : ''),
            'github_latest_release_at' => sanitize_text_field(isset($record['github_latest_release_at']) ? $record['github_latest_release_at'] : ''),
            'github_latest_tag_name' => sanitize_text_field(isset($record['github_latest_tag_name']) ? $record['github_latest_tag_name'] : ''),
            'github_latest_tag_url' => esc_url_raw(isset($record['github_latest_tag_url']) ? $record['github_latest_tag_url'] : ''),
            'github_version_source' => sanitize_key(isset($record['github_version_source']) ? $record['github_version_source'] : ''),
            'github_latest_commit_sha' => sanitize_text_field(isset($record['github_latest_commit_sha']) ? $record['github_latest_commit_sha'] : ''),
            'github_repository_updated_at' => sanitize_text_field(isset($record['github_repository_updated_at']) ? $record['github_repository_updated_at'] : ''),
            'github_last_synced_at' => sanitize_text_field(isset($record['github_last_synced_at']) ? $record['github_last_synced_at'] : ''),
            'github_sync_message' => sanitize_text_field(isset($record['github_sync_message']) ? $record['github_sync_message'] : ''),
            'github_connection_state' => sanitize_key(isset($record['github_connection_state']) ? $record['github_connection_state'] : 'unconfigured'),
            'github_sync_error_code' => sanitize_key(isset($record['github_sync_error_code']) ? $record['github_sync_error_code'] : ''),
            'github_sync_http_status' => sanitize_text_field(isset($record['github_sync_http_status']) ? $record['github_sync_http_status'] : ''),
            'github_sync_endpoint' => sanitize_key(isset($record['github_sync_endpoint']) ? $record['github_sync_endpoint'] : ''),
            'github_sync_endpoint_url' => esc_url_raw(isset($record['github_sync_endpoint_url']) ? $record['github_sync_endpoint_url'] : ''),
            'github_sync_failed_at' => sanitize_text_field(isset($record['github_sync_failed_at']) ? $record['github_sync_failed_at'] : ''),
            'github_rate_limit_remaining' => sanitize_text_field(isset($record['github_rate_limit_remaining']) ? $record['github_rate_limit_remaining'] : ''),
            'github_rate_limit_reset' => sanitize_text_field(isset($record['github_rate_limit_reset']) ? $record['github_rate_limit_reset'] : ''),
            'github_commit_sync_warning' => sanitize_text_field(isset($record['github_commit_sync_warning']) ? $record['github_commit_sync_warning'] : ''),
            'github_commit_sync_http_status' => sanitize_text_field(isset($record['github_commit_sync_http_status']) ? $record['github_commit_sync_http_status'] : ''),
            'github_commit_sync_endpoint' => sanitize_key(isset($record['github_commit_sync_endpoint']) ? $record['github_commit_sync_endpoint'] : ''),
            'legacy_names' => $legacy,
            'family' => in_array($family, $families, true) ? $family : 'foundation',
            'console_screen' => in_array(sanitize_key(isset($record['console_screen']) ? $record['console_screen'] : $family), $families, true) ? sanitize_key(isset($record['console_screen']) ? $record['console_screen'] : $family) : (in_array($family, $families, true) ? $family : 'foundation'),
            'console_badges' => array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) (isset($record['console_badges']) ? $record['console_badges'] : array()))))),
            'product_type' => in_array($type, $types, true) ? $type : 'platform_module',
            'version_source' => in_array($source, $sources, true) ? $source : 'manual',
            'version_precedence' => in_array($version_precedence, $precedence_options, true) ? $version_precedence : (($source === 'manual') ? 'manual' : 'discovered'),
            'plugin_file' => sanitize_text_field(isset($record['plugin_file']) ? $record['plugin_file'] : ''),
            'plugin_slug' => sanitize_key(isset($record['plugin_slug']) ? $record['plugin_slug'] : ''),
            'plugin_text_domain' => sanitize_key(isset($record['plugin_text_domain']) ? $record['plugin_text_domain'] : ''),
            'legacy_plugin_files' => $legacy_plugin_files,
            'legacy_plugin_slugs' => $legacy_plugin_slugs,
            'legacy_text_domains' => $legacy_text_domains,
            'discovery_enabled' => !empty($record['discovery_enabled']) ? '1' : '',
            'discovery_locked' => !empty($record['discovery_locked']) ? '1' : '',
            'discovery_state' => sanitize_key(isset($record['discovery_state']) ? $record['discovery_state'] : 'unscanned'),
            'discovery_match' => sanitize_key(isset($record['discovery_match']) ? $record['discovery_match'] : ''),
            'discovered_active' => !empty($record['discovered_active']) ? '1' : '',
            'discovered_activation_scope' => in_array($activation_scope, array('inactive', 'site', 'network', 'both'), true) ? $activation_scope : 'inactive',
            'discovered_plugin_name' => sanitize_text_field(isset($record['discovered_plugin_name']) ? $record['discovered_plugin_name'] : ''),
            'discovered_plugin_version' => sanitize_text_field(isset($record['discovered_plugin_version']) ? $record['discovered_plugin_version'] : ''),
            'discovered_plugin_version_raw' => sanitize_text_field(isset($record['discovered_plugin_version_raw']) ? $record['discovered_plugin_version_raw'] : ''),
            'discovered_version_state' => in_array($version_state, array('unscanned', 'valid', 'development', 'missing', 'malformed'), true) ? $version_state : 'unscanned',
            'discovered_text_domain' => sanitize_key(isset($record['discovered_text_domain']) ? $record['discovered_text_domain'] : ''),
            'last_discovered_at' => sanitize_text_field(isset($record['last_discovered_at']) ? $record['last_discovered_at'] : ''),
            'installed_version' => sanitize_text_field(isset($record['installed_version']) ? $record['installed_version'] : ''),
            'public_version' => sanitize_text_field(isset($record['public_version']) ? $record['public_version'] : ''),
            'release_channel' => in_array($channel, $channels, true) ? $channel : 'stable',
            'status' => in_array($status, $statuses, true) ? $status : 'unverified',
            'lifecycle_state' => in_array($lifecycle_state, $lifecycle_states, true) ? $lifecycle_state : 'active',
            'superseded_by' => sanitize_key(isset($record['superseded_by']) ? $record['superseded_by'] : ''),
            'public_visible' => !empty($record['public_visible']) ? '1' : '',
            'homepage_visible' => !empty($record['homepage_visible']) ? '1' : '',
            'display_order' => absint(isset($record['display_order']) ? $record['display_order'] : 999),
            'product_url' => esc_url_raw(isset($record['product_url']) ? $record['product_url'] : ''),
            'documentation_url' => esc_url_raw(isset($record['documentation_url']) ? $record['documentation_url'] : ''),
            'support_url' => esc_url_raw(isset($record['support_url']) ? $record['support_url'] : ''),
            'release_notes_url' => esc_url_raw(isset($record['release_notes_url']) ? $record['release_notes_url'] : ''),
            'previous_version' => sanitize_text_field(isset($record['previous_version']) ? $record['previous_version'] : ''),
            'release_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($record['release_date'] ?? '')) ? sanitize_text_field($record['release_date']) : '',
            'change_summary' => sanitize_text_field(isset($record['change_summary']) ? $record['change_summary'] : ''),
            'validation_state' => in_array($validation_state, array('validated', 'partial', 'pending', 'failed', 'unavailable'), true) ? $validation_state : 'pending',
            'documentation_state' => in_array($documentation_state, array('ready', 'partial', 'missing', 'unavailable'), true) ? $documentation_state : 'unavailable',
            'known_issue_count' => absint(isset($record['known_issue_count']) ? $record['known_issue_count'] : 0),
            'owner' => sanitize_text_field(isset($record['owner']) ? $record['owner'] : ''),
            'commercial' => !empty($record['commercial']) ? '1' : '',
            'public_interest' => !empty($record['public_interest']) ? '1' : '',
            'manual_notes' => sanitize_textarea_field(isset($record['manual_notes']) ? $record['manual_notes'] : ''),
            'verification_source' => in_array($verification_source, $verification_sources, true) ? $verification_source : 'administrator',
            'source_verified_at' => sanitize_text_field(isset($record['source_verified_at']) ? $record['source_verified_at'] : ''),
            'record_updated_at' => sanitize_text_field(isset($record['record_updated_at']) ? $record['record_updated_at'] : ''),
            'last_verified_at' => sanitize_text_field(isset($record['last_verified_at']) ? $record['last_verified_at'] : ''),
            'archived' => !empty($record['archived']) ? '1' : '',
            'archived_at' => sanitize_text_field(isset($record['archived_at']) ? $record['archived_at'] : ''),
            'archived_by' => sanitize_text_field(isset($record['archived_by']) ? $record['archived_by'] : ''),
        );
    }

    public function resolved_version($record) {
        $precedence = sanitize_key($record['version_precedence'] ?? 'manual');
        $candidates = array(
            'github' => array($record['github_latest_version'] ?? '', $record['public_version'] ?? '', $record['installed_version'] ?? '', $record['discovered_plugin_version'] ?? ''),
            'manual' => array($record['public_version'] ?? '', $record['github_latest_version'] ?? '', $record['installed_version'] ?? '', $record['discovered_plugin_version'] ?? ''),
            'discovered' => array($record['discovered_plugin_version'] ?? '', $record['installed_version'] ?? '', $record['github_latest_version'] ?? '', $record['public_version'] ?? ''),
            'installed' => array($record['installed_version'] ?? '', $record['discovered_plugin_version'] ?? '', $record['github_latest_version'] ?? '', $record['public_version'] ?? ''),
        );
        foreach ($candidates[$precedence] ?? $candidates['manual'] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    private function verification_timestamp($record) {
        foreach (array('source_verified_at', 'last_verified_at', 'last_discovered_at', 'record_updated_at') as $field) {
            $value = trim((string) ($record[$field] ?? ''));
            if ($value !== '') {
                $timestamp = strtotime($value);
                if ($timestamp) {
                    return $timestamp;
                }
            }
        }
        return 0;
    }

    public function is_stale_record($record, $now = null) {
        $timestamp = $this->verification_timestamp($record);
        if (!$timestamp) {
            return true;
        }
        $now = $now === null ? time() : absint($now);
        $day_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        return ($now - $timestamp) > (self::STALE_AFTER_DAYS * $day_seconds);
    }

    public function is_recent_release($record, $now = null) {
        $release_date = trim((string) ($record['release_date'] ?? ''));
        $timestamp = $release_date !== '' ? strtotime($release_date . ' 00:00:00 UTC') : 0;
        if (!$timestamp) {
            return false;
        }
        $now = $now === null ? time() : absint($now);
        $day_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        return $timestamp <= $now && ($now - $timestamp) <= (self::RECENT_RELEASE_DAYS * $day_seconds);
    }

    public function registry_fingerprint($records = null) {
        $records = $records === null ? $this->registry() : $this->normalize_registry($records);
        $canonical = array();
        foreach ($records as $id => $record) {
            $canonical[$id] = array(
                'canonical_id' => $record['canonical_id'],
                'name' => $record['name'],
                'short_name' => $record['short_name'],
                'family' => $record['family'],
                'console_screen' => $record['console_screen'],
                'product_type' => $record['product_type'],
                'version_source' => $record['version_source'],
                'version_precedence' => $record['version_precedence'],
                'resolved_version' => $this->resolved_version($record),
                'release_channel' => $record['release_channel'],
                'status' => $record['status'],
                'lifecycle_state' => $record['lifecycle_state'],
                'archived' => $record['archived'],
                'superseded_by' => $record['superseded_by'],
                'public_visible' => $record['public_visible'],
                'homepage_visible' => $record['homepage_visible'],
                'display_order' => absint($record['display_order']),
                'previous_version' => $record['previous_version'],
                'release_date' => $record['release_date'],
                'change_summary' => $record['change_summary'],
                'validation_state' => $record['validation_state'],
                'documentation_state' => $record['documentation_state'],
                'known_issue_count' => absint($record['known_issue_count']),
            );
        }
        return hash('sha256', wp_json_encode($canonical));
    }

    private function integrity_issue($level, $code, $message, $product_id = '', $context = array()) {
        return array(
            'level' => in_array($level, array('error', 'warning', 'info'), true) ? $level : 'warning',
            'code' => sanitize_key($code),
            'product_id' => sanitize_key($product_id),
            'message' => sanitize_text_field($message),
            'context' => is_array($context) ? $context : array(),
        );
    }

    public function integrity_report($records = null) {
        $raw = $records === null ? $this->registry() : (array) $records;
        $issues = array();
        $id_counts = array();
        foreach ($raw as $key => $record) {
            if (!is_array($record)) {
                $issues[] = $this->integrity_issue('error', 'invalid_record', 'Registry records must be objects.', is_string($key) ? $key : '');
                continue;
            }
            $id = sanitize_key($record['canonical_id'] ?? (is_string($key) ? $key : ''));
            if ($id === '') {
                $issues[] = $this->integrity_issue('error', 'canonical_id_missing', 'Every product requires a canonical identifier.');
                continue;
            }
            $id_counts[$id] = ($id_counts[$id] ?? 0) + 1;
        }
        foreach ($id_counts as $id => $count) {
            if ($count > 1) {
                $issues[] = $this->integrity_issue('error', 'duplicate_canonical_id', 'Canonical product identifiers must be unique.', $id, array('count' => $count));
            }
        }

        $registry = $this->normalize_registry($raw);
        $aliases = array();
        $orders = array();
        $stale_count = 0;
        $missing_version_count = 0;
        foreach ($registry as $id => $record) {
            $label_values = array_merge(array($record['name'], $record['short_name']), (array) $record['legacy_names']);
            foreach ($label_values as $label) {
                $alias = strtolower(trim((string) $label));
                if ($alias === '') {
                    continue;
                }
                $aliases[$alias][$id] = true;
            }
            $order_key = $record['console_screen'] . ':' . absint($record['display_order']);
            $orders[$order_key][] = $id;

            if (!empty($record['homepage_visible']) && empty($record['public_visible'])) {
                $issues[] = $this->integrity_issue('error', 'homepage_requires_public_visibility', 'Homepage-visible products must also be public registry products.', $id);
            }
            if (in_array($record['lifecycle_state'], array('retired', 'superseded'), true) && !empty($record['homepage_visible'])) {
                $issues[] = $this->integrity_issue('warning', 'inactive_lifecycle_homepage_visible', 'Retired or superseded products should normally be removed from the homepage console.', $id);
            }
            if ($record['lifecycle_state'] === 'superseded') {
                $target = sanitize_key($record['superseded_by']);
                if ($target === '' || !isset($registry[$target]) || $target === $id) {
                    $issues[] = $this->integrity_issue('error', 'invalid_supersession_target', 'Superseded products require a different registered replacement product.', $id, array('superseded_by' => $target));
                }
            }
            if ($record['version_source'] === 'manual' && $record['version_precedence'] !== 'manual') {
                $issues[] = $this->integrity_issue('warning', 'manual_source_precedence_mismatch', 'Manually maintained products should normally use configured public version precedence.', $id);
            }
            if (!empty($record['public_visible']) && in_array($record['lifecycle_state'], array('active', 'maintenance'), true)) {
                if ($this->resolved_version($record) === '') {
                    $missing_version_count++;
                    $issues[] = $this->integrity_issue('warning', 'public_version_missing', 'Active public products should have a resolved version.', $id);
                }
                if ($this->is_stale_record($record)) {
                    $stale_count++;
                    $issues[] = $this->integrity_issue('warning', 'verification_stale', 'Product verification evidence is missing or older than the governance threshold.', $id, array('stale_after_days' => self::STALE_AFTER_DAYS));
                }
            }
        }
        foreach ($aliases as $alias => $products) {
            if (count($products) > 1) {
                $ids = array_keys($products);
                foreach ($ids as $id) {
                    $issues[] = $this->integrity_issue('warning', 'duplicate_public_alias', 'A public or legacy product label is assigned to multiple products.', $id, array('alias' => $alias, 'products' => $ids));
                }
            }
        }
        foreach ($orders as $order_key => $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $issues[] = $this->integrity_issue('warning', 'duplicate_screen_order', 'Products on the same console screen share a display order.', $id, array('order_key' => $order_key, 'products' => $ids));
                }
            }
        }
        $levels = array('error' => 0, 'warning' => 0, 'info' => 0);
        foreach ($issues as $issue) {
            $levels[$issue['level']]++;
        }
        return array(
            'schema' => 'scfs-product-registry-integrity/1.0',
            'version' => self::VERSION,
            'registry_schema' => self::SCHEMA,
            'valid' => $levels['error'] === 0,
            'product_count' => count($registry),
            'error_count' => $levels['error'],
            'warning_count' => $levels['warning'],
            'info_count' => $levels['info'],
            'stale_product_count' => $stale_count,
            'missing_version_count' => $missing_version_count,
            'stale_after_days' => self::STALE_AFTER_DAYS,
            'fingerprint' => $this->registry_fingerprint($registry),
            'generated_at' => gmdate('c'),
            'issues' => $issues,
            'human_review_required' => true,
        );
    }

    public function public_products($homepage_only = false) {
        $records = array();
        foreach ($this->registry() as $record) {
            if (!empty($record['archived']) || empty($record['public_visible']) || ($homepage_only && empty($record['homepage_visible']))) {
                continue;
            }
            $records[] = $this->public_record($record);
        }
        return $records;
    }

    private function public_record($record) {
        $repository_visibility = sanitize_key($record['github_repository_visibility'] ?? '');
        $private_repository = !empty($record['github_repository_private']) || $repository_visibility !== 'public';
        return array(
            'canonical_id' => $record['canonical_id'],
            'name' => $record['name'],
            'short_name' => $record['short_name'],
            'product_description' => $record['product_description'],
            'family' => $record['family'],
            'console_screen' => $record['console_screen'],
            'console_badges' => $record['console_badges'],
            'product_type' => $record['product_type'],
            'version_source' => $record['version_source'],
            'version_precedence' => $record['version_precedence'],
            'version' => $this->resolved_version($record),
            'release_channel' => $record['release_channel'],
            'status' => $record['status'],
            'lifecycle_state' => $record['lifecycle_state'],
            'superseded_by' => $record['superseded_by'],
            'display_order' => absint($record['display_order']),
            'product_url' => $record['product_url'],
            'documentation_url' => $record['documentation_url'],
            'support_url' => $record['support_url'],
            'release_notes_url' => $private_repository ? '' : $record['release_notes_url'],
            'github_repository_url' => $private_repository ? '' : $record['github_repository_url'],
            'github_latest_release_url' => $private_repository ? '' : $record['github_latest_release_url'],
            'github_latest_tag_name' => $record['github_latest_tag_name'],
            'github_latest_tag_url' => $private_repository ? '' : $record['github_latest_tag_url'],
            'github_version_source' => $record['github_version_source'],
            'github_latest_commit_sha' => $private_repository ? '' : $record['github_latest_commit_sha'],
            'github_repository_updated_at' => $record['github_repository_updated_at'],
            'github_sync_state' => $record['github_sync_state'],
            'github_last_synced_at' => $record['github_last_synced_at'],
            'previous_version' => $record['previous_version'],
            'release_date' => $record['release_date'],
            'change_summary' => $record['change_summary'],
            'validation_state' => $record['validation_state'],
            'documentation_state' => $record['documentation_state'],
            'known_issue_count' => absint($record['known_issue_count']),
            'recently_updated' => $this->is_recent_release($record),
            'commercial' => !empty($record['commercial']),
            'public_interest' => !empty($record['public_interest']),
            'verification_source' => $record['verification_source'],
            'source_verified_at' => $record['source_verified_at'],
            'last_verified_at' => $record['last_verified_at'],
        );
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'storage' => 'wordpress_option',
            'option_key' => self::OPTION_KEY,
            'source_of_truth' => 'product_support_feedback_platform',
            'families' => array_keys($this->families()),
            'console_screens' => array_keys($this->families()),
            'version_sources' => array_keys($this->version_sources()),
            'active_version_sources' => array('wordpress_plugin', 'github_release', 'github_prerelease', 'github_tag', 'manual'),
            'reserved_version_sources' => array('remote_manifest', 'service_endpoint', 'package_manifest'),
            'statuses' => array_keys($this->statuses()),
            'release_channels' => array_keys($this->channels()),
            'lifecycle_states' => array_keys($this->lifecycle_states()),
            'version_precedence_options' => array_keys($this->version_precedence_options()),
            'verification_sources' => array_keys($this->verification_sources()),
            'product_types' => array_keys($this->product_types()),
            'canonical_id_immutable' => true,
            'internal_name_governed' => true,
            'private_repository_identity_governed' => true,
            'github_repository_connection_governed' => true,
            'github_release_sync_supported' => true,
            'github_semantic_tag_fallback_supported' => true,
            'github_webhook_and_polling_supported' => true,
            'console_screen_assignment_governed' => true,
            'console_badges_governed' => true,
            'product_description_governed' => true,
            'single_product_connection_editor' => true,
            'canonical_registry_administration' => true,
            'registry_search_and_filters' => true,
            'registry_drag_drop_ordering' => true,
            'registry_dry_run_import' => true,
            'registry_backup_restore' => true,
            'lifecycle_state_governed' => true,
            'version_precedence_explicit' => true,
            'verification_provenance_governed' => true,
            'release_intelligence_governed' => true,
            'previous_version_comparison' => true,
            'release_date_governed' => true,
            'change_summary_governed' => true,
            'validation_state_governed' => true,
            'documentation_state_governed' => true,
            'known_issue_count_governed' => true,
            'recent_release_days' => self::RECENT_RELEASE_DAYS,
            'stale_after_days' => self::STALE_AFTER_DAYS,
            'integrity_report_schema' => 'scfs-product-registry-integrity/1.0',
            'migration_history_option' => self::MIGRATION_OPTION,
            'schema_migration_supported' => true,
            'public_visibility_governed' => true,
            'homepage_visibility_governed' => true,
            'private_repository_fields_publicly_exposed' => false,
            'private_fields' => array('internal_name', 'repository_slug', 'plugin_file', 'plugin_slug', 'plugin_text_domain', 'manual_notes', 'github_repository_url', 'github_latest_release_url', 'github_latest_tag_url', 'github_latest_commit_sha', 'github_authentication_source'),
            'automatic_publication' => false,
            'human_review_required' => true,
            'installed_plugin_discovery' => true,
            'unknown_plugins_auto_registered' => false,
            'discovery_respects_product_lock' => true,
        );
    }

    public function summary_record() {
        $registry = $this->registry();
        $families = array_fill_keys(array_keys($this->families()), 0);
        $sources = array_fill_keys(array_keys($this->version_sources()), 0);
        $manual = 0;
        $visible = 0;
        $lifecycle = array_fill_keys(array_keys($this->lifecycle_states()), 0);
        foreach ($registry as $record) {
            if (isset($families[$record['family']])) {
                $families[$record['family']]++;
            }
            if (isset($sources[$record['version_source']])) {
                $sources[$record['version_source']]++;
            }
            if ($record['version_source'] === 'manual') {
                $manual++;
            }
            if (!empty($record['public_visible'])) {
                $visible++;
            }
            if (isset($lifecycle[$record['lifecycle_state']])) {
                $lifecycle[$record['lifecycle_state']]++;
            }
        }
        $integrity = $this->integrity_report($registry);
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'product_count' => count($registry),
            'public_product_count' => $visible,
            'manual_product_count' => $manual,
            'families' => $families,
            'version_sources' => $sources,
            'lifecycle_states' => $lifecycle,
            'stale_product_count' => $integrity['stale_product_count'],
            'integrity_error_count' => $integrity['error_count'],
            'integrity_warning_count' => $integrity['warning_count'],
            'fingerprint' => $integrity['fingerprint'],
            'generated_at' => gmdate('c'),
        );
    }

    public function register_admin_page() {
        $callback = class_exists('SCFS_Canonical_Product_Registry_Admin')
            ? array(SCFS_Canonical_Product_Registry_Admin::instance(), 'render_admin_page')
            : array($this, 'render_admin_page');
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Canonical Product Registry Administration', 'sustainable-catalyst-feature-suggestions'),
            __('Product Registry', 'sustainable-catalyst-feature-suggestions'),
            $this->capability(),
            self::ADMIN_SLUG,
            $callback
        );
    }

    private function select($name, $selected_value, $options) {
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected_value, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function render_admin_page() {
        if (!$this->can_manage()) {
            wp_die(esc_html__('You do not have permission to manage the product registry.', 'sustainable-catalyst-feature-suggestions'));
        }
        $registry = $this->registry();
        $summary = $this->summary_record();
        $integrity = $this->integrity_report($registry);
        echo '<div class="wrap"><h1>' . esc_html__('Canonical Product Registry', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p>' . esc_html__('This registry is the governed source of product identity for release boards, support documentation, release records, and future product discovery. v7.8.1 adds governed catalog administration with search, lifecycle controls, drag-and-drop console ordering, duplicate merges, alias collision review, archive and restore, dry-run import, automatic backups, and administrator-attributed history.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Product registry saved.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        }
        echo '<div class="notice notice-info inline"><p><strong>' . esc_html(sprintf(__('%d products registered', 'sustainable-catalyst-feature-suggestions'), $summary['product_count'])) . '</strong> · ' . esc_html(sprintf(__('%d public', 'sustainable-catalyst-feature-suggestions'), $summary['public_product_count'])) . ' · ' . esc_html(sprintf(__('%d manually maintained', 'sustainable-catalyst-feature-suggestions'), $summary['manual_product_count'])) . ' · ' . esc_html(sprintf(__('%d integrity errors', 'sustainable-catalyst-feature-suggestions'), $integrity['error_count'])) . ' · ' . esc_html(sprintf(__('%d warnings', 'sustainable-catalyst-feature-suggestions'), $integrity['warning_count'])) . '</p></div>';
        if (isset($_GET['validated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Registry integrity validation completed.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        }
        if (isset($_GET['migrated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Registry schema migration completed.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION, 'scfs_product_registry_nonce');
        echo '<input type="hidden" name="action" value="scfs_save_product_registry">';
        foreach ($registry as $id => $record) {
            $field = 'products[' . $id . ']';
            echo '<details id="scfs-product-' . esc_attr($id) . '" style="background:#fff;border:1px solid #ccd0d4;margin:12px 0;padding:12px" ' . ($id === 'product-support-feedback' || $id === 'catalyst-intelligence' ? 'open' : '') . '>';
            echo '<summary style="cursor:pointer;font-size:15px"><strong>' . esc_html($record['name']) . '</strong> <code>' . esc_html($id) . '</code> — ' . esc_html($record['public_version'] ?: __('version not verified', 'sustainable-catalyst-feature-suggestions')) . '</summary>';
            echo '<input type="hidden" name="' . esc_attr($field . '[canonical_id]') . '" value="' . esc_attr($id) . '">';
            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th><label>' . esc_html__('Public name', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input class="regular-text" name="' . esc_attr($field . '[name]') . '" value="' . esc_attr($record['name']) . '"></td><th><label>' . esc_html__('Short name', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input name="' . esc_attr($field . '[short_name]') . '" value="' . esc_attr($record['short_name']) . '"></td></tr>';
            echo '<tr><th>' . esc_html__('Internal name', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[internal_name]') . '" value="' . esc_attr($record['internal_name']) . '"></td><th>' . esc_html__('Private repository slug', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[repository_slug]') . '" value="' . esc_attr($record['repository_slug']) . '"><p class="description">' . esc_html__('Stored privately and excluded from public registry records.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
            echo '<tr><th>' . esc_html__('Family', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[family]', $record['family'], $this->families());
            echo '</td><th>' . esc_html__('Console screen', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[console_screen]', $record['console_screen'], $this->families());
            echo '</td></tr>';
            echo '<tr><th>' . esc_html__('Product type', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[product_type]', $record['product_type'], $this->product_types());
            echo '</td><th>' . esc_html__('Lifecycle state', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[lifecycle_state]', $record['lifecycle_state'], $this->lifecycle_states());
            echo '</td></tr>';
            echo '<tr><th>' . esc_html__('Version source', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[version_source]', $record['version_source'], $this->version_sources());
            echo '</td><th>' . esc_html__('Version precedence', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[version_precedence]', $record['version_precedence'], $this->version_precedence_options());
            echo '</td></tr>';
            echo '<tr><th>' . esc_html__('Display order', 'sustainable-catalyst-feature-suggestions') . '</th><td><input type="number" min="0" name="' . esc_attr($field . '[display_order]') . '" value="' . esc_attr($record['display_order']) . '"></td><th>' . esc_html__('Superseded by', 'sustainable-catalyst-feature-suggestions') . '</th><td><input name="' . esc_attr($field . '[superseded_by]') . '" value="' . esc_attr($record['superseded_by']) . '" placeholder="canonical-product-id"></td></tr>';
            echo '<tr><th>' . esc_html__('Installed version', 'sustainable-catalyst-feature-suggestions') . '</th><td><input name="' . esc_attr($field . '[installed_version]') . '" value="' . esc_attr($record['installed_version']) . '"></td><th>' . esc_html__('Public version', 'sustainable-catalyst-feature-suggestions') . '</th><td><input name="' . esc_attr($field . '[public_version]') . '" value="' . esc_attr($record['public_version']) . '"></td></tr>';
            echo '<tr><th>' . esc_html__('Release channel', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[release_channel]', $record['release_channel'], $this->channels());
            echo '</td><th>' . esc_html__('Status', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[status]', $record['status'], $this->statuses());
            echo '</td></tr>';
            echo '<tr><th>' . esc_html__('Plugin slug', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[plugin_slug]') . '" value="' . esc_attr($record['plugin_slug']) . '"></td><th>' . esc_html__('Plugin file', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[plugin_file]') . '" value="' . esc_attr($record['plugin_file']) . '"></td></tr>';
            echo '<tr><th>' . esc_html__('GitHub repository', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text code" type="url" name="' . esc_attr($field . '[github_repository_url]') . '" value="' . esc_attr($record['github_repository_url']) . '" placeholder="https://github.com/owner/repository"></td><th>' . esc_html__('Default branch', 'sustainable-catalyst-feature-suggestions') . '</th><td><input name="' . esc_attr($field . '[github_default_branch]') . '" value="' . esc_attr($record['github_default_branch']) . '"></td></tr>';
            echo '<tr><th>' . esc_html__('GitHub synchronization', 'sustainable-catalyst-feature-suggestions') . '</th><td colspan="3"><label style="margin-right:18px"><input type="checkbox" name="' . esc_attr($field . '[github_sync_enabled]') . '" value="1" ' . checked(!empty($record['github_sync_enabled']), true, false) . '> ' . esc_html__('Update the Release Console from GitHub releases', 'sustainable-catalyst-feature-suggestions') . '</label><label><input type="checkbox" name="' . esc_attr($field . '[github_sync_locked]') . '" value="1" ' . checked(!empty($record['github_sync_locked']), true, false) . '> ' . esc_html__('Keep GitHub evidence but do not replace public release fields', 'sustainable-catalyst-feature-suggestions') . '</label><p class="description">' . esc_html__('Signed webhooks update immediately; hourly polling provides a fallback. Private repositories use the GitHub App bridge when configured; SCFS_GITHUB_TOKEN remains an optional fallback.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
            echo '<tr><th>' . esc_html__('GitHub state', 'sustainable-catalyst-feature-suggestions') . '</th><td><code>' . esc_html($record['github_sync_state']) . '</code> ' . esc_html($record['github_latest_version']) . '</td><th>' . esc_html__('Last GitHub sync', 'sustainable-catalyst-feature-suggestions') . '</th><td><code>' . esc_html($record['github_last_synced_at'] ?: __('Never', 'sustainable-catalyst-feature-suggestions')) . '</code></td></tr>';
            echo '<tr><th>' . esc_html__('Plugin text domain', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[plugin_text_domain]') . '" value="' . esc_attr($record['plugin_text_domain']) . '"></td><th>' . esc_html__('Discovery state', 'sustainable-catalyst-feature-suggestions') . '</th><td><code>' . esc_html($record['discovery_state']) . '</code> ' . esc_html($record['discovered_plugin_version']) . '</td></tr>';
            echo '<tr><th>' . esc_html__('Plugin discovery', 'sustainable-catalyst-feature-suggestions') . '</th><td colspan="3"><label style="margin-right:18px"><input type="checkbox" name="' . esc_attr($field . '[discovery_enabled]') . '" value="1" ' . checked(!empty($record['discovery_enabled']), true, false) . '> ' . esc_html__('Allow automatic installed-version detection', 'sustainable-catalyst-feature-suggestions') . '</label><label><input type="checkbox" name="' . esc_attr($field . '[discovery_locked]') . '" value="1" ' . checked(!empty($record['discovery_locked']), true, false) . '> ' . esc_html__('Lock version and status overrides', 'sustainable-catalyst-feature-suggestions') . '</label><p class="description">' . esc_html__('A lock keeps the discovery evidence current without replacing the configured installed version, public version, or status.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
            echo '<tr><th>' . esc_html__('Product URL', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[product_url]') . '" value="' . esc_attr($record['product_url']) . '"></td><th>' . esc_html__('Documentation URL', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[documentation_url]') . '" value="' . esc_attr($record['documentation_url']) . '"></td></tr>';
            echo '<tr><th>' . esc_html__('Support URL', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[support_url]') . '" value="' . esc_attr($record['support_url']) . '"></td><th>' . esc_html__('Release notes URL', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[release_notes_url]') . '" value="' . esc_attr($record['release_notes_url']) . '"></td></tr>';
            echo '<tr><th>' . esc_html__('Previous version', 'sustainable-catalyst-feature-suggestions') . '</th><td><input name="' . esc_attr($field . '[previous_version]') . '" value="' . esc_attr($record['previous_version']) . '"></td><th>' . esc_html__('Release date', 'sustainable-catalyst-feature-suggestions') . '</th><td><input type="date" name="' . esc_attr($field . '[release_date]') . '" value="' . esc_attr($record['release_date']) . '"></td></tr>';
            echo '<tr><th>' . esc_html__('Change summary', 'sustainable-catalyst-feature-suggestions') . '</th><td colspan="3"><input class="large-text" maxlength="240" name="' . esc_attr($field . '[change_summary]') . '" value="' . esc_attr($record['change_summary']) . '"><p class="description">' . esc_html__('Concise public summary; product identity and version remain governed separately.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
            echo '<tr><th>' . esc_html__('Validation state', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[validation_state]', $record['validation_state'], $this->validation_states());
            echo '</td><th>' . esc_html__('Documentation state', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[documentation_state]', $record['documentation_state'], $this->documentation_states());
            echo '</td></tr>';
            echo '<tr><th>' . esc_html__('Known issue count', 'sustainable-catalyst-feature-suggestions') . '</th><td><input type="number" min="0" max="9999" name="' . esc_attr($field . '[known_issue_count]') . '" value="' . esc_attr($record['known_issue_count']) . '"></td><th>' . esc_html__('Recently updated', 'sustainable-catalyst-feature-suggestions') . '</th><td><code>' . esc_html($this->is_recent_release($record) ? __('yes', 'sustainable-catalyst-feature-suggestions') : __('no', 'sustainable-catalyst-feature-suggestions')) . '</code></td></tr>';
            echo '<tr><th>' . esc_html__('Owner', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[owner]') . '" value="' . esc_attr($record['owner']) . '"></td><th>' . esc_html__('Verification source', 'sustainable-catalyst-feature-suggestions') . '</th><td>';
            $this->select($field . '[verification_source]', $record['verification_source'], $this->verification_sources());
            echo '</td></tr>';
            echo '<tr><th>' . esc_html__('Source verified at', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[source_verified_at]') . '" value="' . esc_attr($record['source_verified_at']) . '"></td><th>' . esc_html__('Last verified', 'sustainable-catalyst-feature-suggestions') . '</th><td><input class="regular-text" name="' . esc_attr($field . '[last_verified_at]') . '" value="' . esc_attr($record['last_verified_at']) . '"></td></tr>';
            echo '<input type="hidden" name="' . esc_attr($field . '[record_updated_at]') . '" value="' . esc_attr($record['record_updated_at']) . '">';
            echo '<tr><th>' . esc_html__('Visibility and role', 'sustainable-catalyst-feature-suggestions') . '</th><td colspan="3">';
            foreach (array('public_visible' => __('Public registry', 'sustainable-catalyst-feature-suggestions'), 'homepage_visible' => __('Homepage board', 'sustainable-catalyst-feature-suggestions'), 'commercial' => __('Commercial', 'sustainable-catalyst-feature-suggestions'), 'public_interest' => __('Public-interest infrastructure', 'sustainable-catalyst-feature-suggestions')) as $key => $label) {
                echo '<label style="margin-right:18px"><input type="checkbox" name="' . esc_attr($field . '[' . $key . ']') . '" value="1" ' . checked(!empty($record[$key]), true, false) . '> ' . esc_html($label) . '</label>';
            }
            echo '</td></tr>';
            echo '<tr><th>' . esc_html__('Legacy names', 'sustainable-catalyst-feature-suggestions') . '</th><td colspan="3"><input class="large-text" name="' . esc_attr($field . '[legacy_names]') . '" value="' . esc_attr(implode(', ', $record['legacy_names'])) . '"><p class="description">' . esc_html__('Comma-separated aliases used for migration and matching.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
            echo '<tr><th>' . esc_html__('Legacy plugin identifiers', 'sustainable-catalyst-feature-suggestions') . '</th><td colspan="3">';
            echo '<label>' . esc_html__('Plugin files', 'sustainable-catalyst-feature-suggestions') . '<br><input class="large-text" name="' . esc_attr($field . '[legacy_plugin_files]') . '" value="' . esc_attr(implode(', ', $record['legacy_plugin_files'])) . '"></label><br>';
            echo '<label>' . esc_html__('Folder slugs', 'sustainable-catalyst-feature-suggestions') . '<br><input class="large-text" name="' . esc_attr($field . '[legacy_plugin_slugs]') . '" value="' . esc_attr(implode(', ', $record['legacy_plugin_slugs'])) . '"></label><br>';
            echo '<label>' . esc_html__('Text domains', 'sustainable-catalyst-feature-suggestions') . '<br><input class="large-text" name="' . esc_attr($field . '[legacy_text_domains]') . '" value="' . esc_attr(implode(', ', $record['legacy_text_domains'])) . '"></label>';
            echo '<p class="description">' . esc_html__('Comma-separated compatibility identifiers used only for controlled discovery matching.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
            echo '<tr><th>' . esc_html__('Administrative notes', 'sustainable-catalyst-feature-suggestions') . '</th><td colspan="3"><textarea class="large-text" rows="3" name="' . esc_attr($field . '[manual_notes]') . '">' . esc_textarea($record['manual_notes']) . '</textarea></td></tr>';
            echo '</tbody></table></details>';
        }
        submit_button(__('Save Product Registry', 'sustainable-catalyst-feature-suggestions'));
        echo '</form>';
        echo '<hr><p><a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_validate_product_registry'), 'scfs_validate_product_registry')) . '">' . esc_html__('Validate integrity', 'sustainable-catalyst-feature-suggestions') . '</a> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_migrate_product_registry'), 'scfs_migrate_product_registry')) . '">' . esc_html__('Run schema migration', 'sustainable-catalyst-feature-suggestions') . '</a> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_export_product_registry'), 'scfs_export_product_registry')) . '">' . esc_html__('Export registry JSON', 'sustainable-catalyst-feature-suggestions') . '</a> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_reset_product_registry'), 'scfs_reset_product_registry')) . '" onclick="return confirm(\'' . esc_js(__('Reset the product registry to the v7.8.1 defaults?', 'sustainable-catalyst-feature-suggestions')) . '\')">' . esc_html__('Reset defaults', 'sustainable-catalyst-feature-suggestions') . '</a></p>';
        echo '</div>';
    }

    public function save_registry_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION, 'scfs_product_registry_nonce');
        $products = isset($_POST['products']) && is_array($_POST['products']) ? wp_unslash($_POST['products']) : array();
        $saved_at = gmdate('c');
        foreach ($products as &$product) {
            if (!is_array($product)) {
                continue;
            }
            $product['record_updated_at'] = $saved_at;
            if (empty($product['verification_source'])) {
                $product['verification_source'] = 'administrator';
            }
            if (empty($product['source_verified_at']) && !empty($product['last_verified_at'])) {
                $product['source_verified_at'] = $product['last_verified_at'];
            }
        }
        unset($product);
        $normalized = $this->normalize_registry($products);
        if (!$normalized) {
            wp_die(esc_html__('The registry cannot be empty.', 'sustainable-catalyst-feature-suggestions'));
        }
        $integrity = $this->integrity_report($normalized);
        if (!$integrity['valid']) {
            wp_die(esc_html__('The registry contains integrity errors. Run validation and repair the reported canonical IDs before saving.', 'sustainable-catalyst-feature-suggestions'));
        }
        update_option(self::OPTION_KEY, $normalized, false);
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
        $this->audit('registry_saved', array('product_count' => count($normalized), 'warning_count' => $integrity['warning_count'], 'fingerprint' => $integrity['fingerprint']));
        do_action('scfs_product_registry_updated', $normalized);
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&updated=1'));
        exit;
    }

    public function reset_registry_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer('scfs_reset_product_registry');
        $defaults = $this->apply_v750_release_intelligence_migrations($this->apply_v740_governance_migrations($this->default_products(), self::SCHEMA));
        update_option(self::OPTION_KEY, $defaults, false);
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
        $this->audit('registry_reset', array('product_count' => count($defaults)));
        do_action('scfs_product_registry_updated', $defaults);
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&updated=1'));
        exit;
    }

    public function export_registry_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer('scfs_export_product_registry');
        $payload = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'exported_at' => gmdate('c'),
            'summary' => $this->summary_record(),
            'integrity' => $this->integrity_report(),
            'migration_history' => array_values((array) get_option(self::MIGRATION_OPTION, array())),
            'products' => array_values($this->registry()),
        );
        $this->audit('registry_exported', array('product_count' => count($payload['products'])));
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=scfs-product-registry-' . gmdate('Y-m-d') . '.json');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function validate_registry_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer('scfs_validate_product_registry');
        $report = $this->integrity_report();
        $this->audit('registry_validated', array(
            'valid' => $report['valid'],
            'error_count' => $report['error_count'],
            'warning_count' => $report['warning_count'],
            'fingerprint' => $report['fingerprint'],
        ));
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&validated=1'));
        exit;
    }

    public function migrate_registry_action() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer('scfs_migrate_product_registry');
        $from_schema = (string) get_option(self::SCHEMA_OPTION, 'legacy');
        $registry = $this->apply_v750_release_intelligence_migrations($this->apply_v740_governance_migrations(get_option(self::OPTION_KEY, array()), $from_schema));
        update_option(self::OPTION_KEY, $registry, false);
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
        update_option(self::RELEASE_MIGRATION_OPTION, self::VERSION, false);
        $this->audit('registry_migrated', array('from_schema' => $from_schema, 'to_schema' => self::SCHEMA, 'product_count' => count($registry)));
        do_action('scfs_product_registry_updated', $registry);
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&migrated=1'));
        exit;
    }

    public function register_rest_routes() {
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry/schema', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_schema'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_registry'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry/integrity', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_integrity'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry/migrations', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_migrations'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route(Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE, '/product-registry/(?P<product_id>[a-z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_product'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
    }

    public function rest_permission() {
        return current_user_can('edit_posts');
    }

    public function rest_schema() {
        return rest_ensure_response($this->schema_record());
    }

    public function rest_registry() {
        $administrator = current_user_can('manage_options');
        return rest_ensure_response(array(
            'ok' => true,
            'summary' => $this->summary_record(),
            'integrity' => $administrator ? $this->integrity_report() : array(),
            'migration_history' => $administrator ? array_values((array) get_option(self::MIGRATION_OPTION, array())) : array(),
            'products' => array_values($administrator ? $this->registry() : $this->public_products(false)),
        ));
    }

    public function rest_integrity() {
        return rest_ensure_response(array('ok' => true, 'integrity' => $this->integrity_report()));
    }

    public function rest_migrations() {
        return rest_ensure_response(array(
            'ok' => true,
            'schema' => self::SCHEMA,
            'migrations' => array_values((array) get_option(self::MIGRATION_OPTION, array())),
        ));
    }

    public function rest_product($request) {
        $record = $this->get_product($request['product_id']);
        if (!$record || (!current_user_can('manage_options') && (empty($record['public_visible']) || !empty($record['archived'])))) {
            return new WP_Error('scfs_product_not_found', __('Product not found.', 'sustainable-catalyst-feature-suggestions'), array('status' => 404));
        }
        if (!current_user_can('manage_options')) {
            foreach ($this->public_products(false) as $public_record) {
                if (($public_record['canonical_id'] ?? '') === ($record['canonical_id'] ?? '')) {
                    $record = $public_record;
                    break;
                }
            }
        }
        return rest_ensure_response(array('ok' => true, 'product' => $record));
    }

    public function cli_list($args, $assoc_args) {
        $rows = array();
        foreach ($this->registry() as $record) {
            $rows[] = array(
                'id' => $record['canonical_id'],
                'name' => $record['name'],
                'family' => $record['family'],
                'screen' => $record['console_screen'],
                'lifecycle' => $record['lifecycle_state'],
                'source' => $record['version_source'],
                'version' => $this->resolved_version($record),
                'status' => $record['status'],
                'public' => !empty($record['public_visible']) ? 'yes' : 'no',
            );
        }
        WP_CLI\Utils\format_items(isset($assoc_args['format']) ? $assoc_args['format'] : 'table', $rows, array('id', 'name', 'family', 'screen', 'lifecycle', 'source', 'version', 'status', 'public'));
    }

    public function cli_export($args, $assoc_args) {
        WP_CLI::line(wp_json_encode(array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'products' => array_values($this->registry()),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function cli_seed($args, $assoc_args) {
        $force = !empty($assoc_args['force']);
        $existing = get_option(self::OPTION_KEY, array());
        if ($existing && !$force) {
            WP_CLI::warning('Registry already exists. Use --force to reset it.');
            return;
        }
        $defaults = $this->apply_v740_governance_migrations($this->default_products(), self::SCHEMA);
        update_option(self::OPTION_KEY, $defaults, false);
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
        $this->audit('registry_seeded_cli', array('force' => $force, 'product_count' => count($defaults)));
        WP_CLI::success('Canonical product registry seeded with ' . count($defaults) . ' products.');
    }

    public function cli_validate($args, $assoc_args) {
        $report = $this->integrity_report();
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        if ($format === 'json') {
            WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            WP_CLI::line(sprintf('Products: %d | Errors: %d | Warnings: %d | Stale: %d', $report['product_count'], $report['error_count'], $report['warning_count'], $report['stale_product_count']));
            if ($report['issues']) {
                WP_CLI\Utils\format_items('table', $report['issues'], array('level', 'code', 'product_id', 'message'));
            }
        }
        if (!$report['valid']) {
            WP_CLI::error('Canonical product registry integrity validation failed.');
        }
        WP_CLI::success('Canonical product registry integrity validation passed.');
    }

    public function cli_migrate($args, $assoc_args) {
        $from_schema = (string) get_option(self::SCHEMA_OPTION, 'legacy');
        $registry = $this->apply_v740_governance_migrations(get_option(self::OPTION_KEY, array()), $from_schema);
        update_option(self::OPTION_KEY, $registry, false);
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
        $this->audit('registry_migrated_cli', array('from_schema' => $from_schema, 'to_schema' => self::SCHEMA, 'product_count' => count($registry)));
        do_action('scfs_product_registry_updated', $registry);
        WP_CLI::success('Canonical product registry migrated to ' . self::SCHEMA . '.');
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
