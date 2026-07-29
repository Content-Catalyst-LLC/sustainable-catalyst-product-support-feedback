<?php
/**
 * Help Desk Institutional Workspaces and Access Governance.
 *
 * Provides private organization workspaces, department boundaries, hashed
 * member references, support entitlements, explicit case access, private
 * knowledge collections, audit evidence, retention controls, and privacy-safe
 * institutional reports. Contact and Engagement remains authoritative for
 * requester identity, contact records, secure files, and consent.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Institutional_Workspaces {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-institutional-workspaces/1.0';
    const DB_VERSION = '1.9.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_institutional_workspaces_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_institutional_workspaces_settings';
    const ADMIN_PAGE = 'scfs-help-desk-institutional-workspaces';
    const NONCE_ACTION = 'scfs_help_desk_institutional_workspaces_action';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 8);
        add_action('admin_menu', array($this, 'register_admin_page'), 58);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('scfs_help_desk_case_workspace_after', array($this, 'render_case_panel'), 80);
        add_action('admin_post_scfs_help_desk_institutional_action', array($this, 'admin_action'));
        add_action('admin_post_scfs_help_desk_institutional_export', array($this, 'export_action'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk institutions status', array($this, 'cli_status'));
            WP_CLI::add_command('scfs help-desk institutions list', array($this, 'cli_list'));
            WP_CLI::add_command('scfs help-desk institutions workspace', array($this, 'cli_workspace'));
            WP_CLI::add_command('scfs help-desk institutions report', array($this, 'cli_report'));
            WP_CLI::add_command('scfs help-desk institutions audit', array($this, 'cli_audit'));
        }
    }

    public static function activate() {
        $instance = self::instance();
        $instance->install_schema();
        $instance->install_capabilities();
        if (!get_option(self::SETTINGS_OPTION)) {
            add_option(self::SETTINGS_OPTION, $instance->default_settings(), '', false);
        }
    }

    public static function deactivate() {
        // Private workspace data and audit records remain intact.
    }

    public function default_settings() {
        return array(
            'minimum_report_cohort' => 5,
            'default_retention_days' => 730,
            'identity_authority' => 'contact-engagement',
            'storage_authority' => 'contact-engagement',
            'public_workspace_api' => '0',
            'public_institutional_branding' => '0',
            'sponsor_influence' => '0',
            'automatic_access_grants' => '0',
            'automatic_case_sharing' => '0',
            'automatic_entitlement_changes' => '0',
            'automatic_publication' => '0',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'institutions' => $wpdb->prefix . 'scfs_help_desk_institutions',
            'departments' => $wpdb->prefix . 'scfs_help_desk_institution_departments',
            'members' => $wpdb->prefix . 'scfs_help_desk_institution_members',
            'entitlements' => $wpdb->prefix . 'scfs_help_desk_institution_entitlements',
            'products' => $wpdb->prefix . 'scfs_help_desk_institution_products',
            'case_access' => $wpdb->prefix . 'scfs_help_desk_institution_case_access',
            'collections' => $wpdb->prefix . 'scfs_help_desk_institution_collections',
            'collection_items' => $wpdb->prefix . 'scfs_help_desk_institution_collection_items',
            'audit' => $wpdb->prefix . 'scfs_help_desk_institution_audit_events',
            'reports' => $wpdb->prefix . 'scfs_help_desk_institution_reports',
        );
    }

    public function install_schema() {
        global $wpdb;
        $t = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = array();
        $sql[] = "CREATE TABLE {$t['institutions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_key varchar(120) NOT NULL,
            organization_name varchar(191) NOT NULL,
            workspace_state varchar(40) NOT NULL DEFAULT 'active',
            identity_authority varchar(80) NOT NULL DEFAULT 'contact-engagement',
            storage_authority varchar(80) NOT NULL DEFAULT 'contact-engagement',
            service_policy_key varchar(120) NOT NULL DEFAULT '',
            retention_days int(10) unsigned NOT NULL DEFAULT 730,
            private_workspace tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY workspace_key (workspace_key),
            KEY state_updated (workspace_state,updated_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['departments']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            institution_id bigint(20) unsigned NOT NULL,
            department_key varchar(120) NOT NULL,
            department_name varchar(191) NOT NULL,
            visibility_scope varchar(40) NOT NULL DEFAULT 'department',
            state varchar(40) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY institution_department (institution_id,department_key),
            KEY institution_state (institution_id,state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['members']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            institution_id bigint(20) unsigned NOT NULL,
            department_id bigint(20) unsigned NOT NULL DEFAULT 0,
            external_subject_hash char(64) NOT NULL,
            member_role varchar(40) NOT NULL DEFAULT 'requester',
            capabilities_json longtext NOT NULL,
            member_state varchar(40) NOT NULL DEFAULT 'active',
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            approved_at datetime NULL,
            expires_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY institution_subject (institution_id,external_subject_hash),
            KEY institution_role (institution_id,member_role),
            KEY department_state (department_id,member_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['entitlements']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            institution_id bigint(20) unsigned NOT NULL,
            entitlement_key varchar(120) NOT NULL,
            entitlement_state varchar(40) NOT NULL DEFAULT 'active',
            service_policy_key varchar(120) NOT NULL DEFAULT '',
            case_limit int(10) unsigned NOT NULL DEFAULT 0,
            starts_at datetime NULL,
            ends_at datetime NULL,
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            approved_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY institution_entitlement (institution_id,entitlement_key),
            KEY institution_state (institution_id,entitlement_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['products']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entitlement_id bigint(20) unsigned NOT NULL,
            product_key varchar(120) NOT NULL,
            version_constraint varchar(120) NOT NULL DEFAULT '*',
            component_scope varchar(191) NOT NULL DEFAULT '*',
            dedicated_team_key varchar(120) NOT NULL DEFAULT '',
            coverage_state varchar(40) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entitlement_product_version (entitlement_id,product_key,version_constraint),
            KEY product_state (product_key,coverage_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['case_access']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            institution_id bigint(20) unsigned NOT NULL,
            case_id bigint(20) unsigned NOT NULL,
            member_id bigint(20) unsigned NOT NULL DEFAULT 0,
            department_id bigint(20) unsigned NOT NULL DEFAULT 0,
            access_scope varchar(40) NOT NULL DEFAULT 'view',
            grant_state varchar(40) NOT NULL DEFAULT 'active',
            reason_code varchar(120) NOT NULL DEFAULT '',
            granted_by bigint(20) unsigned NOT NULL DEFAULT 0,
            granted_at datetime NOT NULL,
            expires_at datetime NULL,
            revoked_at datetime NULL,
            PRIMARY KEY  (id),
            KEY case_state (case_id,grant_state),
            KEY institution_case (institution_id,case_id),
            KEY member_state (member_id,grant_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['collections']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            institution_id bigint(20) unsigned NOT NULL,
            department_id bigint(20) unsigned NOT NULL DEFAULT 0,
            collection_key varchar(120) NOT NULL,
            collection_name varchar(191) NOT NULL,
            visibility_scope varchar(40) NOT NULL DEFAULT 'workspace',
            collection_state varchar(40) NOT NULL DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY institution_collection (institution_id,collection_key),
            KEY institution_state (institution_id,collection_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['collection_items']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            collection_id bigint(20) unsigned NOT NULL,
            object_type varchar(60) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_key varchar(191) NOT NULL DEFAULT '',
            added_by bigint(20) unsigned NOT NULL DEFAULT 0,
            added_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY collection_object (collection_id,object_type,object_id,object_key),
            KEY collection_type (collection_id,object_type)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['audit']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            institution_id bigint(20) unsigned NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(120) NOT NULL,
            object_type varchar(60) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_summary varchar(255) NOT NULL,
            evidence_json longtext NOT NULL,
            evidence_sha256 char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY institution_created (institution_id,created_at),
            KEY object_event (object_type,object_id,event_type)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['reports']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            institution_id bigint(20) unsigned NOT NULL,
            report_key varchar(120) NOT NULL,
            period_key varchar(40) NOT NULL,
            cohort_size int(10) unsigned NOT NULL DEFAULT 0,
            suppressed tinyint(1) unsigned NOT NULL DEFAULT 0,
            metrics_json longtext NOT NULL,
            report_sha256 char(64) NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY institution_report (institution_id,report_key),
            KEY institution_period (institution_id,period_key)
        ) $charset;";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public function maybe_upgrade_schema() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            $this->install_schema();
            $this->install_capabilities();
        }
    }

    public function install_capabilities() {
        $caps = array(
            'scfs_view_help_desk_institutional_workspaces',
            'scfs_manage_help_desk_institutional_workspaces',
            'scfs_manage_help_desk_institution_members',
            'scfs_manage_help_desk_entitlements',
            'scfs_manage_help_desk_case_access',
            'scfs_view_help_desk_institution_audit',
            'scfs_export_help_desk_institutional_reports',
        );
        $administrator = get_role('administrator');
        if ($administrator) {
            foreach ($caps as $cap) {
                $administrator->add_cap($cap);
            }
        }
        $agent = get_role('scfs_help_desk_agent');
        if ($agent) {
            $agent->add_cap('scfs_view_help_desk_institutional_workspaces');
            $agent->add_cap('scfs_manage_help_desk_case_access');
        }
    }

    private function clean_key($value) {
        return sanitize_key((string) $value);
    }

    private function clean_evidence($evidence) {
        $blocked = array('email', 'name', 'phone', 'address', 'message_body', 'private_note', 'attachment', 'token', 'secret', 'password', 'cookie');
        $clean = array();
        foreach ((array) $evidence as $key => $value) {
            $key = sanitize_key($key);
            if (in_array($key, $blocked, true)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->clean_evidence($value);
            } elseif (is_bool($value) || is_numeric($value)) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = sanitize_text_field((string) $value);
            }
        }
        return $clean;
    }

    public function append_audit_event($institution_id, $event_type, $object_type, $object_id, $summary, $evidence = array()) {
        global $wpdb;
        $tables = $this->table_names();
        $clean = $this->clean_evidence($evidence);
        $json = wp_json_encode($clean, JSON_UNESCAPED_SLASHES);
        $wpdb->insert($tables['audit'], array(
            'institution_id' => absint($institution_id),
            'actor_user_id' => get_current_user_id(),
            'event_type' => $this->clean_key($event_type),
            'object_type' => $this->clean_key($object_type),
            'object_id' => absint($object_id),
            'event_summary' => sanitize_text_field($summary),
            'evidence_json' => $json,
            'evidence_sha256' => hash('sha256', $json),
            'created_at' => current_time('mysql', true),
        ), array('%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s'));
        return (int) $wpdb->insert_id;
    }

    public function create_workspace($data) {
        global $wpdb;
        $t = $this->table_names();
        $key = $this->clean_key(isset($data['workspace_key']) ? $data['workspace_key'] : '');
        $name = sanitize_text_field(isset($data['organization_name']) ? $data['organization_name'] : '');
        if (!$key || !$name) {
            return new WP_Error('scfs_invalid_workspace', __('Workspace key and organization name are required.', 'sustainable-catalyst-feature-suggestions'));
        }
        $now = current_time('mysql', true);
        $settings = $this->settings();
        $ok = $wpdb->insert($t['institutions'], array(
            'workspace_key' => $key,
            'organization_name' => $name,
            'workspace_state' => 'active',
            'identity_authority' => 'contact-engagement',
            'storage_authority' => 'contact-engagement',
            'service_policy_key' => $this->clean_key(isset($data['service_policy_key']) ? $data['service_policy_key'] : ''),
            'retention_days' => max(30, absint(isset($data['retention_days']) ? $data['retention_days'] : $settings['default_retention_days'])),
            'private_workspace' => 1,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if (!$ok) {
            return new WP_Error('scfs_workspace_create_failed', $wpdb->last_error ?: __('Unable to create workspace.', 'sustainable-catalyst-feature-suggestions'));
        }
        $id = (int) $wpdb->insert_id;
        $this->append_audit_event($id, 'workspace_created', 'workspace', $id, 'Institutional workspace created.', array('workspace_key' => $key));
        return $this->get_workspace($id);
    }

    public function add_member($institution_id, $data) {
        global $wpdb;
        $t = $this->table_names();
        $hash = strtolower(sanitize_text_field(isset($data['external_subject_hash']) ? $data['external_subject_hash'] : ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return new WP_Error('scfs_invalid_subject_hash', __('A SHA-256 external subject hash is required.', 'sustainable-catalyst-feature-suggestions'));
        }
        $roles = array('workspace_admin', 'support_manager', 'requester', 'auditor', 'observer');
        $role = sanitize_key(isset($data['member_role']) ? $data['member_role'] : 'requester');
        if (!in_array($role, $roles, true)) {
            $role = 'requester';
        }
        $caps = array_values(array_unique(array_map('sanitize_key', (array) (isset($data['capabilities']) ? $data['capabilities'] : array()))));
        $now = current_time('mysql', true);
        $ok = $wpdb->insert($t['members'], array(
            'institution_id' => absint($institution_id),
            'department_id' => absint(isset($data['department_id']) ? $data['department_id'] : 0),
            'external_subject_hash' => $hash,
            'member_role' => $role,
            'capabilities_json' => wp_json_encode($caps),
            'member_state' => 'active',
            'approved_by' => get_current_user_id(),
            'approved_at' => $now,
            'expires_at' => !empty($data['expires_at']) ? sanitize_text_field($data['expires_at']) : null,
            'created_at' => $now,
        ));
        if (!$ok) {
            return new WP_Error('scfs_member_create_failed', $wpdb->last_error ?: __('Unable to add member.', 'sustainable-catalyst-feature-suggestions'));
        }
        $id = (int) $wpdb->insert_id;
        $this->append_audit_event($institution_id, 'member_authorized', 'member', $id, 'Institutional member access authorized.', array('role' => $role, 'department_id' => absint($data['department_id'] ?? 0)));
        return array('id' => $id, 'institution_id' => absint($institution_id), 'role' => $role, 'capabilities' => $caps, 'identity_copied' => false);
    }

    public function save_entitlement($institution_id, $data) {
        global $wpdb;
        $t = $this->table_names();
        $key = $this->clean_key($data['entitlement_key'] ?? '');
        if (!$key) {
            return new WP_Error('scfs_invalid_entitlement', __('Entitlement key is required.', 'sustainable-catalyst-feature-suggestions'));
        }
        $now = current_time('mysql', true);
        $wpdb->replace($t['entitlements'], array(
            'institution_id' => absint($institution_id),
            'entitlement_key' => $key,
            'entitlement_state' => sanitize_key($data['entitlement_state'] ?? 'active'),
            'service_policy_key' => $this->clean_key($data['service_policy_key'] ?? ''),
            'case_limit' => absint($data['case_limit'] ?? 0),
            'starts_at' => !empty($data['starts_at']) ? sanitize_text_field($data['starts_at']) : null,
            'ends_at' => !empty($data['ends_at']) ? sanitize_text_field($data['ends_at']) : null,
            'approved_by' => get_current_user_id(),
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        $this->append_audit_event($institution_id, 'entitlement_recorded', 'entitlement', $id, 'Support entitlement recorded.', array('entitlement_key' => $key, 'case_limit' => absint($data['case_limit'] ?? 0)));
        return array('id' => $id, 'entitlement_key' => $key, 'automatic_entitlement_change' => false);
    }

    public function grant_case_access($institution_id, $case_id, $data) {
        global $wpdb;
        $t = $this->table_names();
        $case_id = absint($case_id);
        if (!$case_id) {
            return new WP_Error('scfs_invalid_case', __('A valid case ID is required.', 'sustainable-catalyst-feature-suggestions'));
        }
        $now = current_time('mysql', true);
        $wpdb->insert($t['case_access'], array(
            'institution_id' => absint($institution_id),
            'case_id' => $case_id,
            'member_id' => absint($data['member_id'] ?? 0),
            'department_id' => absint($data['department_id'] ?? 0),
            'access_scope' => sanitize_key($data['access_scope'] ?? 'view'),
            'grant_state' => 'active',
            'reason_code' => $this->clean_key($data['reason_code'] ?? 'authorized_support'),
            'granted_by' => get_current_user_id(),
            'granted_at' => $now,
            'expires_at' => !empty($data['expires_at']) ? sanitize_text_field($data['expires_at']) : null,
        ));
        $id = (int) $wpdb->insert_id;
        $this->append_audit_event($institution_id, 'case_access_granted', 'case', $case_id, 'Explicit institutional case access granted.', array('grant_id' => $id, 'scope' => sanitize_key($data['access_scope'] ?? 'view')));
        return array('id' => $id, 'case_id' => $case_id, 'explicit_grant' => true, 'automatic_case_sharing' => false);
    }

    public function create_collection($institution_id, $data) {
        global $wpdb;
        $t = $this->table_names();
        $key = $this->clean_key($data['collection_key'] ?? '');
        $name = sanitize_text_field($data['collection_name'] ?? '');
        if (!$key || !$name) {
            return new WP_Error('scfs_invalid_collection', __('Collection key and name are required.', 'sustainable-catalyst-feature-suggestions'));
        }
        $now = current_time('mysql', true);
        $wpdb->insert($t['collections'], array(
            'institution_id' => absint($institution_id),
            'department_id' => absint($data['department_id'] ?? 0),
            'collection_key' => $key,
            'collection_name' => $name,
            'visibility_scope' => sanitize_key($data['visibility_scope'] ?? 'workspace'),
            'collection_state' => 'active',
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        $this->append_audit_event($institution_id, 'collection_created', 'collection', $id, 'Private institutional knowledge collection created.', array('collection_key' => $key));
        return array('id' => $id, 'collection_key' => $key, 'private_case_content_copied' => false, 'attachment_content_copied' => false);
    }

    public function get_workspace($id) {
        global $wpdb;
        $t = $this->table_names();
        $workspace = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['institutions']} WHERE id = %d", absint($id)), ARRAY_A);
        if (!$workspace) {
            return null;
        }
        $workspace['departments'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['departments']} WHERE institution_id = %d ORDER BY department_name", absint($id)), ARRAY_A);
        $workspace['members'] = $wpdb->get_results($wpdb->prepare("SELECT id, institution_id, department_id, external_subject_hash, member_role, capabilities_json, member_state, approved_at, expires_at FROM {$t['members']} WHERE institution_id = %d ORDER BY id DESC", absint($id)), ARRAY_A);
        $workspace['entitlements'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['entitlements']} WHERE institution_id = %d ORDER BY id DESC", absint($id)), ARRAY_A);
        $workspace['collections'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['collections']} WHERE institution_id = %d ORDER BY collection_name", absint($id)), ARRAY_A);
        $workspace['privacy'] = array('identity_authority' => 'contact-engagement', 'requester_identity_copied' => false, 'attachment_content_copied' => false);
        return $workspace;
    }

    public function list_workspaces($limit = 100) {
        global $wpdb;
        $t = $this->table_names();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['institutions']} ORDER BY updated_at DESC LIMIT %d", max(1, min(500, absint($limit)))), ARRAY_A);
    }

    public function audit_events($institution_id, $limit = 100) {
        global $wpdb;
        $t = $this->table_names();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['audit']} WHERE institution_id = %d ORDER BY id DESC LIMIT %d", absint($institution_id), max(1, min(500, absint($limit)))), ARRAY_A);
    }

    public function generate_report($institution_id, $period_key = '') {
        global $wpdb;
        $t = $this->table_names();
        $minimum = max(2, absint($this->settings()['minimum_report_cohort']));
        $period_key = sanitize_text_field($period_key ?: gmdate('Y-m'));
        $case_table = $wpdb->prefix . 'scfs_cases';
        $case_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT case_id) FROM {$t['case_access']} WHERE institution_id = %d AND grant_state = 'active'", absint($institution_id)));
        $member_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['members']} WHERE institution_id = %d AND member_state = 'active'", absint($institution_id)));
        $collection_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['collections']} WHERE institution_id = %d AND collection_state = 'active'", absint($institution_id)));
        $suppressed = $case_count < $minimum;
        $metrics = $suppressed ? array() : array(
            'authorized_cases' => $case_count,
            'authorized_members' => $member_count,
            'private_collections' => $collection_count,
        );
        $payload = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'institution_id' => absint($institution_id),
            'period_key' => $period_key,
            'cohort_size' => $case_count,
            'suppressed' => $suppressed,
            'metrics' => $metrics,
            'requester_identity_included' => false,
            'private_message_content_included' => false,
            'attachment_content_included' => false,
        );
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sha = hash('sha256', $json);
        $report_key = 'institution-' . absint($institution_id) . '-' . sanitize_key($period_key);
        $wpdb->replace($t['reports'], array(
            'institution_id' => absint($institution_id),
            'report_key' => $report_key,
            'period_key' => $period_key,
            'cohort_size' => $case_count,
            'suppressed' => $suppressed ? 1 : 0,
            'metrics_json' => wp_json_encode($metrics),
            'report_sha256' => $sha,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ));
        $payload['report_key'] = $report_key;
        $payload['report_sha256'] = $sha;
        $this->append_audit_event($institution_id, 'report_generated', 'report', (int) $wpdb->insert_id, 'Privacy-safe institutional report generated.', array('period_key' => $period_key, 'cohort_size' => $case_count, 'suppressed' => $suppressed));
        return $payload;
    }

    public function health() {
        global $wpdb;
        $tables = $this->table_names();
        $missing = array();
        foreach ($tables as $key => $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                $missing[] = $key;
            }
        }
        return array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'db_version' => get_option(self::DB_VERSION_OPTION),
            'state' => empty($missing) ? 'ready' : 'schema_attention',
            'missing_tables' => $missing,
            'identity_authority' => 'contact-engagement',
            'public_workspace_api' => false,
            'public_institutional_branding' => false,
            'sponsor_influence' => false,
        );
    }

    public function register_admin_page() {
        add_submenu_page('edit.php?post_type=sc_feature_suggest', __('Institutional Workspaces', 'sustainable-catalyst-feature-suggestions'), __('Institutional Workspaces', 'sustainable-catalyst-feature-suggestions'), 'scfs_view_help_desk_institutional_workspaces', self::ADMIN_PAGE, array($this, 'render_admin_page'));
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false) {
            return;
        }
        wp_enqueue_style('scfs-help-desk-institutional-workspaces', plugins_url('../assets/help-desk-institutional-workspaces-v6.12.0.css', __FILE__), array(), self::VERSION);
        wp_enqueue_script('scfs-help-desk-institutional-workspaces', plugins_url('../assets/help-desk-institutional-workspaces-v6.12.0.js', __FILE__), array(), self::VERSION, true);
    }

    public function render_admin_page() {
        if (!current_user_can('scfs_view_help_desk_institutional_workspaces')) {
            wp_die(esc_html__('You do not have permission to view institutional workspaces.', 'sustainable-catalyst-feature-suggestions'));
        }
        $workspaces = $this->list_workspaces();
        $selected_id = isset($_GET['workspace_id']) ? absint($_GET['workspace_id']) : 0;
        $selected = $selected_id ? $this->get_workspace($selected_id) : null;
        ?>
        <div class="wrap scfs-institutional-workspaces">
            <h1><?php esc_html_e('Institutional Workspaces', 'sustainable-catalyst-feature-suggestions'); ?></h1>
            <p class="description"><?php esc_html_e('Private, governed support workspaces. Identity and secure-file authority remain with Contact and Engagement.', 'sustainable-catalyst-feature-suggestions'); ?></p>
            <div class="scfs-iw-grid">
                <section class="scfs-iw-card">
                    <h2><?php esc_html_e('Workspaces', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                    <?php if (!$workspaces) : ?>
                        <p><?php esc_html_e('No institutional workspaces have been created.', 'sustainable-catalyst-feature-suggestions'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Organization', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('State', 'sustainable-catalyst-feature-suggestions'); ?></th></tr></thead><tbody>
                        <?php foreach ($workspaces as $workspace) : ?>
                            <tr><td><a href="<?php echo esc_url(add_query_arg(array('post_type' => 'sc_feature_suggest', 'page' => self::ADMIN_PAGE, 'workspace_id' => $workspace['id']), admin_url('edit.php'))); ?>"><?php echo esc_html($workspace['organization_name']); ?></a><br><code><?php echo esc_html($workspace['workspace_key']); ?></code></td><td><?php echo esc_html($workspace['workspace_state']); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>
                </section>
                <section class="scfs-iw-card">
                    <h2><?php esc_html_e('Create Workspace', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                    <?php if (current_user_can('scfs_manage_help_desk_institutional_workspaces')) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="scfs_help_desk_institutional_action">
                        <input type="hidden" name="operation" value="create_workspace">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <p><label><?php esc_html_e('Workspace key', 'sustainable-catalyst-feature-suggestions'); ?><br><input class="regular-text" name="workspace_key" required></label></p>
                        <p><label><?php esc_html_e('Organization name', 'sustainable-catalyst-feature-suggestions'); ?><br><input class="regular-text" name="organization_name" required></label></p>
                        <p><label><?php esc_html_e('Service policy key', 'sustainable-catalyst-feature-suggestions'); ?><br><input class="regular-text" name="service_policy_key"></label></p>
                        <?php submit_button(__('Create Private Workspace', 'sustainable-catalyst-feature-suggestions')); ?>
                    </form>
                    <?php endif; ?>
                </section>
            </div>
            <?php if ($selected) : ?>
            <section class="scfs-iw-card scfs-iw-detail">
                <h2><?php echo esc_html($selected['organization_name']); ?></h2>
                <div class="scfs-iw-facts">
                    <span><strong><?php esc_html_e('Workspace', 'sustainable-catalyst-feature-suggestions'); ?>:</strong> <code><?php echo esc_html($selected['workspace_key']); ?></code></span>
                    <span><strong><?php esc_html_e('Identity authority', 'sustainable-catalyst-feature-suggestions'); ?>:</strong> Contact and Engagement</span>
                    <span><strong><?php esc_html_e('Private', 'sustainable-catalyst-feature-suggestions'); ?>:</strong> <?php esc_html_e('Yes', 'sustainable-catalyst-feature-suggestions'); ?></span>
                </div>
                <h3><?php esc_html_e('Entitlements', 'sustainable-catalyst-feature-suggestions'); ?></h3>
                <pre><?php echo esc_html(wp_json_encode($selected['entitlements'], JSON_PRETTY_PRINT)); ?></pre>
                <h3><?php esc_html_e('Collections', 'sustainable-catalyst-feature-suggestions'); ?></h3>
                <pre><?php echo esc_html(wp_json_encode($selected['collections'], JSON_PRETTY_PRINT)); ?></pre>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'scfs_help_desk_institutional_export', 'workspace_id' => $selected_id), admin_url('admin-post.php')), self::NONCE_ACTION)); ?>"><?php esc_html_e('Export Privacy-Safe Report', 'sustainable-catalyst-feature-suggestions'); ?></a></p>
            </section>
            <?php endif; ?>
        </div>
        <?php
    }

    public function admin_action() {
        if (!current_user_can('scfs_manage_help_desk_institutional_workspaces')) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        $operation = sanitize_key($_POST['operation'] ?? '');
        if ($operation === 'create_workspace') {
            $result = $this->create_workspace(wp_unslash($_POST));
            $args = array('post_type' => 'sc_feature_suggest', 'page' => self::ADMIN_PAGE);
            if (!is_wp_error($result)) {
                $args['workspace_id'] = absint($result['id']);
                $args['scfs_notice'] = 'workspace_created';
            } else {
                $args['scfs_error'] = rawurlencode($result->get_error_message());
            }
            wp_safe_redirect(add_query_arg($args, admin_url('edit.php')));
            exit;
        }
        wp_safe_redirect(add_query_arg(array('post_type' => 'sc_feature_suggest', 'page' => self::ADMIN_PAGE), admin_url('edit.php')));
        exit;
    }

    public function export_action() {
        if (!current_user_can('scfs_export_help_desk_institutional_reports')) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        $report = $this->generate_report(absint($_GET['workspace_id'] ?? 0));
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="institutional-support-report-' . sanitize_file_name($report['report_key']) . '.json"');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function render_case_panel($case) {
        if (!current_user_can('scfs_view_help_desk_institutional_workspaces')) {
            return;
        }
        global $wpdb;
        $t = $this->table_names();
        $case_id = is_array($case) ? absint($case['id'] ?? 0) : absint($case);
        if (!$case_id) {
            return;
        }
        $grants = $wpdb->get_results($wpdb->prepare("SELECT a.*, i.organization_name FROM {$t['case_access']} a INNER JOIN {$t['institutions']} i ON i.id = a.institution_id WHERE a.case_id = %d AND a.grant_state = 'active' ORDER BY a.id DESC", $case_id), ARRAY_A);
        echo '<section class="scfs-case-panel scfs-institutional-case-panel"><h3>' . esc_html__('Institutional Access', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        if (!$grants) {
            echo '<p>' . esc_html__('No institutional workspace has explicit access to this case.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        } else {
            echo '<ul>';
            foreach ($grants as $grant) {
                echo '<li><strong>' . esc_html($grant['organization_name']) . '</strong> — ' . esc_html($grant['access_scope']) . '</li>';
            }
            echo '</ul>';
        }
        echo '<p class="description">' . esc_html__('Case sharing is explicit and never automatic.', 'sustainable-catalyst-feature-suggestions') . '</p></section>';
    }

    public function register_rest_routes() {
        $ns = 'scfs/v1';
        register_rest_route($ns, '/help-desk/institutional-workspaces/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($ns, '/help-desk/institutional-workspaces', array(
            array('methods' => 'GET', 'callback' => array($this, 'rest_list'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => 'POST', 'callback' => array($this, 'rest_create'), 'permission_callback' => array($this, 'can_manage')),
        ));
        register_rest_route($ns, '/help-desk/institutional-workspaces/(?P<id>\d+)', array('methods' => 'GET', 'callback' => array($this, 'rest_workspace'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($ns, '/help-desk/institutional-workspaces/(?P<id>\d+)/members', array('methods' => 'POST', 'callback' => array($this, 'rest_member'), 'permission_callback' => array($this, 'can_manage_members')));
        register_rest_route($ns, '/help-desk/institutional-workspaces/(?P<id>\d+)/entitlements', array('methods' => 'POST', 'callback' => array($this, 'rest_entitlement'), 'permission_callback' => array($this, 'can_manage_entitlements')));
        register_rest_route($ns, '/help-desk/institutional-workspaces/(?P<id>\d+)/case-access', array('methods' => 'POST', 'callback' => array($this, 'rest_case_access'), 'permission_callback' => array($this, 'can_manage_case_access')));
        register_rest_route($ns, '/help-desk/institutional-workspaces/(?P<id>\d+)/collections', array('methods' => 'POST', 'callback' => array($this, 'rest_collection'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($ns, '/help-desk/institutional-workspaces/(?P<id>\d+)/reports', array('methods' => 'GET', 'callback' => array($this, 'rest_report'), 'permission_callback' => array($this, 'can_export')));
        register_rest_route($ns, '/help-desk/institutional-workspaces/(?P<id>\d+)/audit', array('methods' => 'GET', 'callback' => array($this, 'rest_audit'), 'permission_callback' => array($this, 'can_audit')));
        register_rest_route($ns, '/help-desk/institutional-workspaces/health', array('methods' => 'GET', 'callback' => array($this, 'rest_health'), 'permission_callback' => array($this, 'can_view')));
    }

    public function can_view() { return current_user_can('scfs_view_help_desk_institutional_workspaces'); }
    public function can_manage() { return current_user_can('scfs_manage_help_desk_institutional_workspaces'); }
    public function can_manage_members() { return current_user_can('scfs_manage_help_desk_institution_members'); }
    public function can_manage_entitlements() { return current_user_can('scfs_manage_help_desk_entitlements'); }
    public function can_manage_case_access() { return current_user_can('scfs_manage_help_desk_case_access'); }
    public function can_audit() { return current_user_can('scfs_view_help_desk_institution_audit'); }
    public function can_export() { return current_user_can('scfs_export_help_desk_institutional_reports'); }

    public function rest_schema() { return rest_ensure_response(array('version' => self::VERSION, 'schema' => self::SCHEMA, 'db_version' => self::DB_VERSION, 'roles' => array('workspace_admin', 'support_manager', 'requester', 'auditor', 'observer'), 'public_workspace_api' => false)); }
    public function rest_list($request) { return rest_ensure_response($this->list_workspaces(absint($request->get_param('limit') ?: 100))); }
    public function rest_create($request) { return $this->respond($this->create_workspace($request->get_json_params())); }
    public function rest_workspace($request) { $workspace = $this->get_workspace(absint($request['id'])); return $workspace ? rest_ensure_response($workspace) : new WP_Error('scfs_workspace_not_found', __('Workspace not found.', 'sustainable-catalyst-feature-suggestions'), array('status' => 404)); }
    public function rest_member($request) { return $this->respond($this->add_member(absint($request['id']), $request->get_json_params())); }
    public function rest_entitlement($request) { return $this->respond($this->save_entitlement(absint($request['id']), $request->get_json_params())); }
    public function rest_case_access($request) { $data = $request->get_json_params(); return $this->respond($this->grant_case_access(absint($request['id']), absint($data['case_id'] ?? 0), $data)); }
    public function rest_collection($request) { return $this->respond($this->create_collection(absint($request['id']), $request->get_json_params())); }
    public function rest_report($request) { return rest_ensure_response($this->generate_report(absint($request['id']), sanitize_text_field($request->get_param('period') ?: ''))); }
    public function rest_audit($request) { return rest_ensure_response($this->audit_events(absint($request['id']), absint($request->get_param('limit') ?: 100))); }
    public function rest_health() { return rest_ensure_response($this->health()); }
    private function respond($result) { return is_wp_error($result) ? $result : rest_ensure_response($result); }

    public function cli_status() { WP_CLI::line(wp_json_encode($this->health(), JSON_PRETTY_PRINT)); }
    public function cli_list() { WP_CLI::line(wp_json_encode($this->list_workspaces(500), JSON_PRETTY_PRINT)); }
    public function cli_workspace($args) { if (empty($args[0])) { WP_CLI::error('A workspace ID is required.'); } WP_CLI::line(wp_json_encode($this->get_workspace(absint($args[0])), JSON_PRETTY_PRINT)); }
    public function cli_report($args, $assoc_args) { if (empty($args[0])) { WP_CLI::error('A workspace ID is required.'); } WP_CLI::line(wp_json_encode($this->generate_report(absint($args[0]), sanitize_text_field($assoc_args['period'] ?? '')), JSON_PRETTY_PRINT)); }
    public function cli_audit($args) { if (empty($args[0])) { WP_CLI::error('A workspace ID is required.'); } WP_CLI::line(wp_json_encode($this->audit_events(absint($args[0]), 500), JSON_PRETTY_PRINT)); }
}
