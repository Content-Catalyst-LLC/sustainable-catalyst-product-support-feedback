<?php
/**
 * Help Desk Reliability, Security, Privacy, and Production Hardening.
 *
 * This additive control plane records security, privacy, backup, recovery, and
 * release-gate evidence. It never performs destructive privacy actions,
 * permanent user blocking, production restores, or automatic deployments.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Production_Hardening {
    const VERSION = '7.8.1';
    const DB_VERSION = '3.0.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_production_hardening_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_production_hardening_settings';
    const SCHEMA = 'scfs-help-desk-production-hardening/1.0';
    const ADMIN_PAGE = 'scfs-help-desk-production-hardening';
    const CRON_HOOK = 'scfs_help_desk_production_hardening_tick';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'scheduled_hardening_tick'));
        add_filter('wp_headers', array($this, 'apply_private_response_headers'));
        if (defined('WP_CLI') && WP_CLI) {
            $this->register_cli();
        }
    }

    public static function activate() {
        $self = self::instance();
        $self->install_schema();
        $self->grant_capabilities();
        if (!get_option(self::SETTINGS_OPTION)) {
            add_option(self::SETTINGS_OPTION, $self->default_settings(), '', false);
        }
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 600, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public function default_settings() {
        return array(
            'rate_limit_window_seconds' => 300,
            'authenticated_request_limit' => 300,
            'portal_request_limit' => 60,
            'failed_authentication_review_threshold' => 8,
            'security_event_retention_days' => 730,
            'audit_export_retention_days' => 90,
            'backup_freshness_hours' => 24,
            'backup_retention_days' => 30,
            'recovery_drill_max_age_days' => 90,
            'minimum_accessibility_score' => 95,
            'maximum_page_weight_kb' => 1800,
            'maximum_server_response_ms' => 1200,
            'automatic_permanent_block' => '0',
            'automatic_destructive_privacy_action' => '0',
            'automatic_production_restore' => '0',
            'automatic_deployment' => '0',
            'public_security_event_api' => '0',
            'private_content_in_audit_exports' => '0',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function capabilities() {
        return array(
            'scfs_view_help_desk_hardening',
            'scfs_manage_help_desk_security',
            'scfs_manage_help_desk_privacy_operations',
            'scfs_manage_help_desk_backups',
            'scfs_manage_help_desk_recovery_drills',
            'scfs_manage_help_desk_production_gates',
            'scfs_export_help_desk_audit_evidence',
        );
    }

    private function grant_capabilities() {
        $administrator = get_role('administrator');
        if ($administrator) {
            foreach ($this->capabilities() as $capability) {
                $administrator->add_cap($capability);
            }
        }
        $agent = get_role('scfs_help_desk_agent');
        if ($agent) {
            $agent->add_cap('scfs_view_help_desk_hardening');
        }
    }

    public function table_names() {
        global $wpdb;
        return array(
            'rate_limits' => $wpdb->prefix . 'scfs_help_desk_rate_limits',
            'security_events' => $wpdb->prefix . 'scfs_help_desk_security_events',
            'privacy_requests' => $wpdb->prefix . 'scfs_help_desk_privacy_requests',
            'audit_exports' => $wpdb->prefix . 'scfs_help_desk_audit_exports',
            'backup_snapshots' => $wpdb->prefix . 'scfs_help_desk_backup_snapshots',
            'recovery_drills' => $wpdb->prefix . 'scfs_help_desk_recovery_drills',
            'production_gates' => $wpdb->prefix . 'scfs_help_desk_production_gates',
            'health_snapshots' => $wpdb->prefix . 'scfs_help_desk_hardening_health_snapshots',
        );
    }

    public function install_schema() {
        global $wpdb;
        $t = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = array();
        $sql[] = "CREATE TABLE {$t['rate_limits']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_hash char(64) NOT NULL,
            operation_key varchar(120) NOT NULL,
            window_started_at datetime NOT NULL,
            window_seconds int(10) unsigned NOT NULL DEFAULT 300,
            request_count bigint(20) unsigned NOT NULL DEFAULT 0,
            limit_value bigint(20) unsigned NOT NULL DEFAULT 60,
            decision_state varchar(40) NOT NULL DEFAULT 'allow',
            last_seen_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY actor_operation_window (actor_hash,operation_key,window_started_at),
            KEY operation_state (operation_key,decision_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['security_events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_uuid char(36) NOT NULL,
            event_type varchar(120) NOT NULL,
            severity varchar(30) NOT NULL DEFAULT 'info',
            actor_hash char(64) NOT NULL DEFAULT '',
            case_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_component varchar(120) NOT NULL DEFAULT '',
            event_summary text NOT NULL,
            evidence_json longtext NOT NULL,
            evidence_sha256 char(64) NOT NULL,
            review_state varchar(40) NOT NULL DEFAULT 'recorded',
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_uuid (event_uuid),
            KEY severity_state (severity,review_state),
            KEY case_created (case_id,created_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['privacy_requests']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_uuid char(36) NOT NULL,
            operation_type varchar(40) NOT NULL,
            requester_reference_hash char(64) NOT NULL,
            contact_engagement_reference varchar(191) NOT NULL DEFAULT '',
            identity_verification_state varchar(40) NOT NULL DEFAULT 'pending',
            legal_hold_state varchar(40) NOT NULL DEFAULT 'not_checked',
            request_state varchar(40) NOT NULL DEFAULT 'received',
            scope_json longtext NOT NULL,
            scope_sha256 char(64) NOT NULL,
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            approved_at datetime NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY request_uuid (request_uuid),
            KEY operation_state (operation_type,request_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['audit_exports']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            export_uuid char(36) NOT NULL,
            export_type varchar(60) NOT NULL,
            scope_json longtext NOT NULL,
            scope_sha256 char(64) NOT NULL,
            record_count bigint(20) unsigned NOT NULL DEFAULT 0,
            payload_sha256 char(64) NOT NULL DEFAULT '',
            storage_reference varchar(191) NOT NULL DEFAULT '',
            export_state varchar(40) NOT NULL DEFAULT 'requested',
            requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            expires_at datetime NULL,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY export_uuid (export_uuid),
            KEY state_created (export_state,created_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['backup_snapshots']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            snapshot_uuid char(36) NOT NULL,
            snapshot_type varchar(60) NOT NULL DEFAULT 'application',
            source_version varchar(40) NOT NULL,
            database_sha256 char(64) NOT NULL DEFAULT '',
            files_sha256 char(64) NOT NULL DEFAULT '',
            manifest_sha256 char(64) NOT NULL DEFAULT '',
            storage_reference varchar(191) NOT NULL DEFAULT '',
            encryption_state varchar(40) NOT NULL DEFAULT 'unknown',
            offsite_copy_state varchar(40) NOT NULL DEFAULT 'unknown',
            restore_test_state varchar(40) NOT NULL DEFAULT 'not_tested',
            retention_until datetime NULL,
            created_at datetime NOT NULL,
            verified_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY snapshot_uuid (snapshot_uuid),
            KEY source_created (source_version,created_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['recovery_drills']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            drill_uuid char(36) NOT NULL,
            snapshot_id bigint(20) unsigned NOT NULL DEFAULT 0,
            environment_key varchar(120) NOT NULL DEFAULT 'isolated-staging',
            drill_state varchar(40) NOT NULL DEFAULT 'planned',
            database_restore_state varchar(40) NOT NULL DEFAULT 'pending',
            file_restore_state varchar(40) NOT NULL DEFAULT 'pending',
            integrity_state varchar(40) NOT NULL DEFAULT 'pending',
            smoke_test_state varchar(40) NOT NULL DEFAULT 'pending',
            recovery_time_minutes int(10) unsigned NOT NULL DEFAULT 0,
            recovery_point_minutes int(10) unsigned NOT NULL DEFAULT 0,
            evidence_json longtext NOT NULL,
            evidence_sha256 char(64) NOT NULL,
            authorized_by bigint(20) unsigned NOT NULL DEFAULT 0,
            started_at datetime NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY drill_uuid (drill_uuid),
            KEY state_created (drill_state,created_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['production_gates']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            gate_uuid char(36) NOT NULL,
            release_version varchar(40) NOT NULL,
            release_commit varchar(64) NOT NULL DEFAULT '',
            gate_state varchar(40) NOT NULL DEFAULT 'draft',
            readiness_score decimal(5,2) NOT NULL DEFAULT 0,
            checks_json longtext NOT NULL,
            checks_sha256 char(64) NOT NULL,
            blocking_findings_json longtext NOT NULL,
            authorized_by bigint(20) unsigned NOT NULL DEFAULT 0,
            authorized_at datetime NULL,
            evaluated_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY gate_uuid (gate_uuid),
            KEY release_state (release_version,gate_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['health_snapshots']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            snapshot_date date NOT NULL,
            overall_state varchar(40) NOT NULL DEFAULT 'unknown',
            readiness_score decimal(5,2) NOT NULL DEFAULT 0,
            metrics_json longtext NOT NULL,
            metrics_sha256 char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY snapshot_date (snapshot_date),
            KEY state_date (overall_state,snapshot_date)
        ) $charset;";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=sc_feature_suggest',
            __('Production Hardening', 'sustainable-catalyst-feature-suggestions'),
            __('Production Hardening', 'sustainable-catalyst-feature-suggestions'),
            'scfs_view_help_desk_hardening',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false) {
            return;
        }
        $base = plugin_dir_url(dirname(__FILE__)) . 'assets/';
        wp_enqueue_style('scfs-help-desk-production-hardening', $base . 'help-desk-production-hardening-v6.12.0.css', array(), self::VERSION);
        wp_enqueue_script('scfs-help-desk-production-hardening', $base . 'help-desk-production-hardening-v6.12.0.js', array(), self::VERSION, true);
    }

    public function can_view() {
        return current_user_can('scfs_view_help_desk_hardening') || current_user_can('manage_options');
    }

    public function can_manage_security() {
        return current_user_can('scfs_manage_help_desk_security') || current_user_can('manage_options');
    }

    public function can_manage_privacy() {
        return current_user_can('scfs_manage_help_desk_privacy_operations') || current_user_can('manage_options');
    }

    public function can_manage_release() {
        return current_user_can('scfs_manage_help_desk_production_gates') || current_user_can('manage_options');
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/help-desk/production-hardening/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/production-hardening/overview', array('methods' => 'GET', 'callback' => array($this, 'rest_overview'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/production-hardening/rate-limits/evaluate', array('methods' => 'POST', 'callback' => array($this, 'rest_evaluate_rate_limit'), 'permission_callback' => array($this, 'can_manage_security')));
        register_rest_route($namespace, '/help-desk/production-hardening/security-events', array(
            array('methods' => 'GET', 'callback' => array($this, 'rest_security_events'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => 'POST', 'callback' => array($this, 'rest_record_security_event'), 'permission_callback' => array($this, 'can_manage_security')),
        ));
        register_rest_route($namespace, '/help-desk/production-hardening/privacy-requests', array(
            array('methods' => 'GET', 'callback' => array($this, 'rest_privacy_requests'), 'permission_callback' => array($this, 'can_manage_privacy')),
            array('methods' => 'POST', 'callback' => array($this, 'rest_create_privacy_request'), 'permission_callback' => array($this, 'can_manage_privacy')),
        ));
        register_rest_route($namespace, '/help-desk/production-hardening/audit-exports', array('methods' => 'POST', 'callback' => array($this, 'rest_request_audit_export'), 'permission_callback' => array($this, 'can_manage_privacy')));
        register_rest_route($namespace, '/help-desk/production-hardening/backups', array(
            array('methods' => 'GET', 'callback' => array($this, 'rest_backups'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => 'POST', 'callback' => array($this, 'rest_register_backup'), 'permission_callback' => array($this, 'can_manage_release')),
        ));
        register_rest_route($namespace, '/help-desk/production-hardening/recovery-drills', array('methods' => 'POST', 'callback' => array($this, 'rest_record_recovery_drill'), 'permission_callback' => array($this, 'can_manage_release')));
        register_rest_route($namespace, '/help-desk/production-hardening/production-gates/evaluate', array('methods' => 'POST', 'callback' => array($this, 'rest_evaluate_production_gate'), 'permission_callback' => array($this, 'can_manage_release')));
        register_rest_route($namespace, '/help-desk/production-hardening/health', array('methods' => 'GET', 'callback' => array($this, 'rest_health'), 'permission_callback' => array($this, 'can_view')));
    }

    public function rest_schema() {
        return rest_ensure_response(array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'db_version' => self::DB_VERSION,
            'additive_tables' => array_values($this->table_names()),
            'public_security_event_api' => false,
            'automatic_permanent_block' => false,
            'automatic_destructive_privacy_action' => false,
            'automatic_production_restore' => false,
            'automatic_deployment' => false,
            'human_release_authorization_required' => true,
        ));
    }

    public function rest_overview() {
        return rest_ensure_response($this->health());
    }

    public function rest_evaluate_rate_limit(WP_REST_Request $request) {
        return rest_ensure_response($this->evaluate_rate_limit($request->get_json_params()));
    }

    public function evaluate_rate_limit($input) {
        $settings = $this->settings();
        $count = max(0, absint($input['request_count'] ?? 0));
        $limit = max(1, absint($input['limit'] ?? $settings['portal_request_limit']));
        $window = max(60, absint($input['window_seconds'] ?? $settings['rate_limit_window_seconds']));
        $state = 'allow';
        $allowed = true;
        $retry = 0;
        if ($count >= $limit * 2) {
            $state = 'block_review';
            $allowed = false;
            $retry = $window;
        } elseif ($count >= $limit) {
            $state = 'throttle';
            $allowed = false;
            $retry = $window;
        }
        return array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'allowed' => $allowed,
            'state' => $state,
            'remaining' => max(0, $limit - $count),
            'retry_after_seconds' => $retry,
            'automatic_permanent_block' => false,
            'human_review_required' => true,
        );
    }

    public function rest_record_security_event(WP_REST_Request $request) {
        return $this->record_security_event($request->get_json_params());
    }

    public function record_security_event($input) {
        global $wpdb;
        $t = $this->table_names();
        $severity = sanitize_key($input['severity'] ?? 'info');
        if (!in_array($severity, array('info', 'low', 'medium', 'high', 'critical'), true)) {
            $severity = 'info';
        }
        $evidence = $this->privacy_minimize((array) ($input['evidence'] ?? array()));
        $encoded = wp_json_encode($evidence, JSON_UNESCAPED_SLASHES);
        $ok = $wpdb->insert($t['security_events'], array(
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => sanitize_text_field($input['event_type'] ?? 'security.observation'),
            'severity' => $severity,
            'actor_hash' => $this->hash_reference($input['actor_reference'] ?? ''),
            'case_id' => absint($input['case_id'] ?? 0),
            'source_component' => sanitize_key($input['source_component'] ?? 'help-desk'),
            'event_summary' => sanitize_text_field($input['event_summary'] ?? 'Security observation recorded.'),
            'evidence_json' => $encoded,
            'evidence_sha256' => hash('sha256', $encoded),
            'review_state' => in_array($severity, array('high', 'critical'), true) ? 'review_required' : 'recorded',
            'created_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            return new WP_Error('scfs_security_event_write_failed', __('Could not record security evidence.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        return rest_ensure_response(array('ok' => true, 'event_id' => (int) $wpdb->insert_id, 'private_content_exposed' => false, 'human_review_required' => in_array($severity, array('high', 'critical'), true)));
    }

    public function rest_security_events() {
        global $wpdb;
        $t = $this->table_names();
        return rest_ensure_response($wpdb->get_results("SELECT id,event_uuid,event_type,severity,case_id,source_component,event_summary,evidence_sha256,review_state,created_at FROM {$t['security_events']} ORDER BY id DESC LIMIT 200", ARRAY_A));
    }

    public function rest_create_privacy_request(WP_REST_Request $request) {
        return $this->create_privacy_request($request->get_json_params());
    }

    public function create_privacy_request($input) {
        global $wpdb;
        $t = $this->table_names();
        $operation = sanitize_key($input['operation_type'] ?? 'access');
        if (!in_array($operation, array('access', 'export', 'rectify', 'restrict', 'delete', 'retention_review'), true)) {
            return new WP_Error('scfs_invalid_privacy_operation', __('Unsupported privacy operation.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
        }
        $reference = sanitize_text_field($input['requester_reference'] ?? '');
        if (!$reference) {
            return new WP_Error('scfs_missing_requester_reference', __('A requester reference is required.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
        }
        $scope = $this->privacy_minimize((array) ($input['scope'] ?? array()));
        $encoded = wp_json_encode($scope, JSON_UNESCAPED_SLASHES);
        $ok = $wpdb->insert($t['privacy_requests'], array(
            'request_uuid' => wp_generate_uuid4(),
            'operation_type' => $operation,
            'requester_reference_hash' => $this->hash_reference($reference),
            'contact_engagement_reference' => sanitize_text_field($input['contact_engagement_reference'] ?? ''),
            'identity_verification_state' => 'pending',
            'legal_hold_state' => 'not_checked',
            'request_state' => 'received',
            'scope_json' => $encoded,
            'scope_sha256' => hash('sha256', $encoded),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            return new WP_Error('scfs_privacy_request_write_failed', __('Could not create privacy request.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        return rest_ensure_response(array('ok' => true, 'request_id' => (int) $wpdb->insert_id, 'destructive_action_executed' => false, 'identity_authority' => 'contact-engagement', 'human_authorization_required' => true));
    }

    public function rest_privacy_requests() {
        global $wpdb;
        $t = $this->table_names();
        return rest_ensure_response($wpdb->get_results("SELECT id,request_uuid,operation_type,identity_verification_state,legal_hold_state,request_state,scope_sha256,created_at,updated_at FROM {$t['privacy_requests']} ORDER BY id DESC LIMIT 200", ARRAY_A));
    }

    public function rest_request_audit_export(WP_REST_Request $request) {
        global $wpdb;
        $t = $this->table_names();
        $scope = $this->privacy_minimize((array) ($request->get_json_params()['scope'] ?? array()));
        $encoded = wp_json_encode($scope, JSON_UNESCAPED_SLASHES);
        $expires = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS * absint($this->settings()['audit_export_retention_days']));
        $ok = $wpdb->insert($t['audit_exports'], array(
            'export_uuid' => wp_generate_uuid4(),
            'export_type' => 'privacy_minimized_audit',
            'scope_json' => $encoded,
            'scope_sha256' => hash('sha256', $encoded),
            'export_state' => 'review_required',
            'requested_by' => get_current_user_id(),
            'expires_at' => $expires,
            'created_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            return new WP_Error('scfs_audit_export_write_failed', __('Could not create audit export request.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        return rest_ensure_response(array('ok' => true, 'export_id' => (int) $wpdb->insert_id, 'private_message_content_included' => false, 'attachment_content_included' => false, 'human_review_required' => true));
    }

    public function rest_register_backup(WP_REST_Request $request) {
        return $this->register_backup($request->get_json_params());
    }

    public function register_backup($input) {
        global $wpdb;
        $t = $this->table_names();
        foreach (array('database_sha256', 'files_sha256', 'manifest_sha256') as $field) {
            $value = strtolower(sanitize_text_field($input[$field] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
                return new WP_Error('scfs_invalid_backup_digest', __('Backup SHA-256 metadata is required.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
            }
        }
        $retention = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS * absint($this->settings()['backup_retention_days']));
        $ok = $wpdb->insert($t['backup_snapshots'], array(
            'snapshot_uuid' => wp_generate_uuid4(),
            'snapshot_type' => sanitize_key($input['snapshot_type'] ?? 'application'),
            'source_version' => sanitize_text_field($input['source_version'] ?? self::VERSION),
            'database_sha256' => strtolower($input['database_sha256']),
            'files_sha256' => strtolower($input['files_sha256']),
            'manifest_sha256' => strtolower($input['manifest_sha256']),
            'storage_reference' => sanitize_text_field($input['storage_reference'] ?? ''),
            'encryption_state' => !empty($input['encrypted']) ? 'encrypted' : 'not_verified',
            'offsite_copy_state' => !empty($input['offsite_copy']) ? 'verified' : 'not_verified',
            'restore_test_state' => !empty($input['restore_tested']) ? 'passed' : 'not_tested',
            'retention_until' => $retention,
            'created_at' => current_time('mysql', true),
            'verified_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            return new WP_Error('scfs_backup_write_failed', __('Could not register backup evidence.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        return rest_ensure_response(array('ok' => true, 'snapshot_id' => (int) $wpdb->insert_id, 'automatic_restore' => false, 'human_restore_authorization_required' => true));
    }

    public function rest_backups() {
        global $wpdb;
        $t = $this->table_names();
        return rest_ensure_response($wpdb->get_results("SELECT id,snapshot_uuid,snapshot_type,source_version,database_sha256,files_sha256,manifest_sha256,encryption_state,offsite_copy_state,restore_test_state,retention_until,created_at FROM {$t['backup_snapshots']} ORDER BY id DESC LIMIT 100", ARRAY_A));
    }

    public function rest_record_recovery_drill(WP_REST_Request $request) {
        global $wpdb;
        $t = $this->table_names();
        $input = $request->get_json_params();
        $evidence = $this->privacy_minimize((array) ($input['evidence'] ?? array()));
        $encoded = wp_json_encode($evidence, JSON_UNESCAPED_SLASHES);
        $checks = array('database_restore_state', 'file_restore_state', 'integrity_state', 'smoke_test_state');
        $passed = true;
        foreach ($checks as $field) {
            if (($input[$field] ?? '') !== 'passed') {
                $passed = false;
            }
        }
        $ok = $wpdb->insert($t['recovery_drills'], array(
            'drill_uuid' => wp_generate_uuid4(),
            'snapshot_id' => absint($input['snapshot_id'] ?? 0),
            'environment_key' => sanitize_key($input['environment_key'] ?? 'isolated-staging'),
            'drill_state' => $passed ? 'passed' : 'review_required',
            'database_restore_state' => sanitize_key($input['database_restore_state'] ?? 'pending'),
            'file_restore_state' => sanitize_key($input['file_restore_state'] ?? 'pending'),
            'integrity_state' => sanitize_key($input['integrity_state'] ?? 'pending'),
            'smoke_test_state' => sanitize_key($input['smoke_test_state'] ?? 'pending'),
            'recovery_time_minutes' => absint($input['recovery_time_minutes'] ?? 0),
            'recovery_point_minutes' => absint($input['recovery_point_minutes'] ?? 0),
            'evidence_json' => $encoded,
            'evidence_sha256' => hash('sha256', $encoded),
            'authorized_by' => get_current_user_id(),
            'started_at' => current_time('mysql', true),
            'completed_at' => current_time('mysql', true),
            'created_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            return new WP_Error('scfs_recovery_drill_write_failed', __('Could not record recovery drill.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        return rest_ensure_response(array('ok' => true, 'drill_id' => (int) $wpdb->insert_id, 'passed' => $passed, 'production_restore_allowed' => false, 'automatic_production_restore' => false));
    }

    public function rest_evaluate_production_gate(WP_REST_Request $request) {
        return $this->evaluate_production_gate($request->get_json_params());
    }

    public function evaluate_production_gate($input) {
        global $wpdb;
        $t = $this->table_names();
        $required = array('source_validation', 'package_validation', 'database_migrations', 'backup_current', 'recovery_drill', 'security_controls', 'privacy_review', 'rollback_plan', 'change_authorization');
        $advisory = array('accessibility_review', 'performance_budget', 'monitoring');
        $checks = array();
        foreach (array_merge($required, $advisory) as $key) {
            $checks[$key] = !empty($input[$key]);
        }
        $blocking = array();
        foreach ($required as $key) {
            if (!$checks[$key]) {
                $blocking[] = $key;
            }
        }
        $advisory_missing = array();
        foreach ($advisory as $key) {
            if (!$checks[$key]) {
                $advisory_missing[] = $key;
            }
        }
        $score = round(array_sum(array_map(function ($value) { return $value ? 1 : 0; }, $checks)) / count($checks) * 100, 2);
        $state = $blocking ? 'blocked' : ($advisory_missing ? 'conditional' : 'ready');
        $encoded = wp_json_encode($checks, JSON_UNESCAPED_SLASHES);
        $wpdb->insert($t['production_gates'], array(
            'gate_uuid' => wp_generate_uuid4(),
            'release_version' => sanitize_text_field($input['release_version'] ?? self::VERSION),
            'release_commit' => sanitize_text_field($input['release_commit'] ?? ''),
            'gate_state' => $state,
            'readiness_score' => $score,
            'checks_json' => $encoded,
            'checks_sha256' => hash('sha256', $encoded),
            'blocking_findings_json' => wp_json_encode($blocking),
            'authorized_by' => $state === 'ready' && !empty($input['change_authorization']) ? get_current_user_id() : 0,
            'authorized_at' => $state === 'ready' && !empty($input['change_authorization']) ? current_time('mysql', true) : null,
            'evaluated_at' => current_time('mysql', true),
            'created_at' => current_time('mysql', true),
        ));
        return rest_ensure_response(array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'passed' => !$blocking,
            'state' => $state,
            'score' => $score,
            'blocking_checks' => $blocking,
            'advisory_checks' => $advisory_missing,
            'automatic_deployment' => false,
            'human_release_authorization_required' => true,
        ));
    }

    public function rest_health() {
        return rest_ensure_response($this->health());
    }

    public function health() {
        global $wpdb;
        $t = $this->table_names();
        $security_review = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['security_events']} WHERE review_state='review_required'");
        $privacy_open = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['privacy_requests']} WHERE request_state NOT IN ('completed','denied','cancelled')");
        $backup = $wpdb->get_row("SELECT source_version,encryption_state,offsite_copy_state,restore_test_state,created_at FROM {$t['backup_snapshots']} ORDER BY id DESC LIMIT 1", ARRAY_A);
        $gate = $wpdb->get_row("SELECT release_version,gate_state,readiness_score,evaluated_at FROM {$t['production_gates']} ORDER BY id DESC LIMIT 1", ARRAY_A);
        return array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'security_events_requiring_review' => $security_review,
            'open_privacy_requests' => $privacy_open,
            'latest_backup' => $backup ?: null,
            'latest_production_gate' => $gate ?: null,
            'automatic_permanent_block' => false,
            'automatic_destructive_privacy_action' => false,
            'automatic_production_restore' => false,
            'automatic_deployment' => false,
            'human_release_authorization_required' => true,
        );
    }

    public function scheduled_hardening_tick() {
        global $wpdb;
        $t = $this->table_names();
        $health = $this->health();
        $metrics = wp_json_encode($health, JSON_UNESCAPED_SLASHES);
        $state = ((int) $health['security_events_requiring_review'] > 0 || (int) $health['open_privacy_requests'] > 0) ? 'review' : 'healthy';
        $score = $state === 'healthy' ? 100 : 80;
        $wpdb->replace($t['health_snapshots'], array(
            'snapshot_date' => gmdate('Y-m-d'),
            'overall_state' => $state,
            'readiness_score' => $score,
            'metrics_json' => $metrics,
            'metrics_sha256' => hash('sha256', $metrics),
            'created_at' => current_time('mysql', true),
        ));
    }

    public function apply_private_response_headers($headers) {
        if (is_admin() || strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/support/cases/') !== false) {
            $headers['X-Content-Type-Options'] = 'nosniff';
            $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
            $headers['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=()';
            $headers['Cache-Control'] = 'private, no-store, no-cache, must-revalidate, max-age=0';
        }
        return $headers;
    }

    private function privacy_minimize($payload) {
        $blocked = array('requester_name', 'requester_email', 'email', 'phone', 'message_body', 'private_message', 'internal_note', 'attachment_bytes', 'attachment_url', 'download_url', 'access_token', 'secret', 'password', 'authorization', 'cookie');
        $safe = array();
        foreach ((array) $payload as $key => $value) {
            $normalized = sanitize_key($key);
            if (in_array($normalized, $blocked, true)) {
                continue;
            }
            if (is_array($value)) {
                $safe[$normalized] = $this->privacy_minimize($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$normalized] = is_string($value) ? sanitize_text_field($value) : $value;
            }
        }
        $safe['privacy_profile'] = 'privacy_minimized';
        $safe['requester_identity_included'] = false;
        $safe['private_message_content_included'] = false;
        $safe['attachment_content_included'] = false;
        return $safe;
    }

    private function hash_reference($reference) {
        $reference = strtolower(trim((string) $reference));
        return hash_hmac('sha256', $reference, wp_salt('auth'));
    }

    public function render_admin_page() {
        if (!$this->can_view()) {
            wp_die(esc_html__('You do not have permission to view production hardening.', 'sustainable-catalyst-feature-suggestions'));
        }
        $health = $this->health();
        ?>
        <div class="wrap scfs-hardening-console">
            <h1><?php esc_html_e('Reliability, Security, Privacy, and Production Hardening', 'sustainable-catalyst-feature-suggestions'); ?></h1>
            <p><?php esc_html_e('Review operational security, privacy requests, backup evidence, recovery drills, and release gates without exposing private support content or automating destructive actions.', 'sustainable-catalyst-feature-suggestions'); ?></p>
            <div class="scfs-hardening-metrics">
                <article><strong><?php echo esc_html((string) $health['security_events_requiring_review']); ?></strong><span><?php esc_html_e('Security reviews', 'sustainable-catalyst-feature-suggestions'); ?></span></article>
                <article><strong><?php echo esc_html((string) $health['open_privacy_requests']); ?></strong><span><?php esc_html_e('Privacy operations', 'sustainable-catalyst-feature-suggestions'); ?></span></article>
                <article><strong><?php echo esc_html(isset($health['latest_production_gate']['gate_state']) ? $health['latest_production_gate']['gate_state'] : 'not evaluated'); ?></strong><span><?php esc_html_e('Latest release gate', 'sustainable-catalyst-feature-suggestions'); ?></span></article>
            </div>
            <section class="scfs-hardening-boundary">
                <h2><?php esc_html_e('Human-control boundary', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                <p><?php esc_html_e('Permanent blocking, destructive privacy operations, production restores, incident declarations, and deployments always require separate human authorization.', 'sustainable-catalyst-feature-suggestions'); ?></p>
            </section>
        </div>
        <?php
    }

    public function register_cli() {
        WP_CLI::add_command('scfs help-desk hardening status', function () { WP_CLI::success(wp_json_encode($this->health(), JSON_PRETTY_PRINT)); });
        WP_CLI::add_command('scfs help-desk hardening security-events', function () { global $wpdb; $t=$this->table_names(); WP_CLI::print_value($wpdb->get_results("SELECT id,event_type,severity,review_state,created_at FROM {$t['security_events']} ORDER BY id DESC LIMIT 100", ARRAY_A), array('format'=>'table')); });
        WP_CLI::add_command('scfs help-desk hardening privacy-requests', function () { global $wpdb; $t=$this->table_names(); WP_CLI::print_value($wpdb->get_results("SELECT id,operation_type,identity_verification_state,legal_hold_state,request_state,created_at FROM {$t['privacy_requests']} ORDER BY id DESC LIMIT 100", ARRAY_A), array('format'=>'table')); });
        WP_CLI::add_command('scfs help-desk hardening backups', function () { global $wpdb; $t=$this->table_names(); WP_CLI::print_value($wpdb->get_results("SELECT id,snapshot_uuid,source_version,encryption_state,offsite_copy_state,restore_test_state,created_at FROM {$t['backup_snapshots']} ORDER BY id DESC LIMIT 100", ARRAY_A), array('format'=>'table')); });
        WP_CLI::add_command('scfs help-desk hardening recovery-drills', function () { global $wpdb; $t=$this->table_names(); WP_CLI::print_value($wpdb->get_results("SELECT id,drill_uuid,drill_state,recovery_time_minutes,recovery_point_minutes,created_at FROM {$t['recovery_drills']} ORDER BY id DESC LIMIT 100", ARRAY_A), array('format'=>'table')); });
        WP_CLI::add_command('scfs help-desk hardening production-gates', function () { global $wpdb; $t=$this->table_names(); WP_CLI::print_value($wpdb->get_results("SELECT id,release_version,gate_state,readiness_score,evaluated_at FROM {$t['production_gates']} ORDER BY id DESC LIMIT 100", ARRAY_A), array('format'=>'table')); });
        WP_CLI::add_command('scfs help-desk hardening snapshot', function () { $this->scheduled_hardening_tick(); WP_CLI::success('Production-hardening health snapshot recorded.'); });
    }
}
