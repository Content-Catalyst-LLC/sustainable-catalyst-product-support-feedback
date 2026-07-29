<?php
/**
 * Help Desk Case Foundation.
 *
 * Provides private, auditable help-desk case records without changing the
 * public Support Article, Known Issue, release, suggestion, or Knowledge Base
 * data models. Requester identity, consent, and uploaded-file authority remain
 * with the Contact and Engagement Platform.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Case_Foundation {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-case/1.0';
    const DB_VERSION = '1.0.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_settings';
    const SEQUENCE_OPTION = 'scfs_help_desk_case_sequence';
    const ADMIN_PAGE = 'scfs-help-desk-cases';
    const NONCE_ACTION = 'scfs_help_desk_case_action';
    const CRON_HOOK = 'scfs_help_desk_case_maintenance_daily';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 5);
        add_action('admin_menu', array($this, 'register_admin_page'), 50);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_post_scfs_help_desk_create_case', array($this, 'admin_create_case'));
        add_action('admin_post_scfs_help_desk_transition_case', array($this, 'admin_transition_case'));
        add_action('admin_post_scfs_help_desk_add_message', array($this, 'admin_add_message'));
        add_action('admin_post_scfs_help_desk_assign_case', array($this, 'admin_assign_case'));
        add_action(self::CRON_HOOK, array($this, 'daily_maintenance'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk schema', array($this, 'cli_schema'));
            WP_CLI::add_command('scfs help-desk create', array($this, 'cli_create_case'));
            WP_CLI::add_command('scfs help-desk case', array($this, 'cli_case'));
            WP_CLI::add_command('scfs help-desk transition', array($this, 'cli_transition'));
            WP_CLI::add_command('scfs help-desk integrity', array($this, 'cli_integrity'));
        }
    }

    public static function activate() {
        $instance = self::instance();
        $instance->install_schema();
        $instance->install_capabilities();
        if (!get_option(self::SETTINGS_OPTION)) {
            add_option(self::SETTINGS_OPTION, $instance->default_settings(), '', false);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 1800, 'daily', self::CRON_HOOK);
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
            'case_prefix' => 'SC',
            'audit_retention_days' => 2555,
            'resolved_reopen_days' => 30,
            'attachment_authority' => 'contact-engagement',
            'identity_authority' => 'contact-engagement',
            'require_consent_for_private_case' => '1',
            'public_case_api' => '0',
            'automatic_case_creation' => '0',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'cases' => $wpdb->prefix . 'scfs_cases',
            'participants' => $wpdb->prefix . 'scfs_case_participants',
            'messages' => $wpdb->prefix . 'scfs_case_messages',
            'events' => $wpdb->prefix . 'scfs_case_events',
            'assignments' => $wpdb->prefix . 'scfs_case_assignments',
            'relationships' => $wpdb->prefix . 'scfs_case_relationships',
            'attachments' => $wpdb->prefix . 'scfs_case_attachments',
            'sla_events' => $wpdb->prefix . 'scfs_case_sla_events',
        );
    }

    public function install_schema() {
        global $wpdb;
        $tables = $this->table_names();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = array();

        $sql[] = "CREATE TABLE {$tables['cases']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_number varchar(40) NOT NULL,
            requester_ref varchar(191) NOT NULL DEFAULT '',
            organization_ref varchar(191) NOT NULL DEFAULT '',
            product varchar(120) NOT NULL DEFAULT '',
            product_version varchar(120) NOT NULL DEFAULT '',
            component varchar(160) NOT NULL DEFAULT '',
            subject text NOT NULL,
            description longtext NOT NULL,
            case_type varchar(80) NOT NULL DEFAULT 'other',
            priority varchar(40) NOT NULL DEFAULT 'normal',
            severity varchar(40) NOT NULL DEFAULT 'informational',
            status varchar(40) NOT NULL DEFAULT 'new',
            source varchar(60) NOT NULL DEFAULT 'admin',
            privacy_classification varchar(60) NOT NULL DEFAULT 'private_support',
            consent_state varchar(40) NOT NULL DEFAULT 'not_recorded',
            assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            assigned_team varchar(120) NOT NULL DEFAULT '',
            parent_case_id bigint(20) unsigned NOT NULL DEFAULT 0,
            duplicate_of_case_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            first_response_at datetime NULL,
            resolved_at datetime NULL,
            closed_at datetime NULL,
            last_requester_message_at datetime NULL,
            last_support_message_at datetime NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY case_number (case_number),
            KEY status_priority (status,priority),
            KEY product_component (product,component),
            KEY assigned_user_id (assigned_user_id),
            KEY requester_ref (requester_ref),
            KEY updated_at (updated_at)
        ) $charset;";

        $sql[] = "CREATE TABLE {$tables['participants']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            participant_ref varchar(191) NOT NULL DEFAULT '',
            participant_type varchar(40) NOT NULL DEFAULT 'requester',
            display_label varchar(191) NOT NULL DEFAULT '',
            permission_level varchar(40) NOT NULL DEFAULT 'participant',
            added_by bigint(20) unsigned NOT NULL DEFAULT 0,
            added_at datetime NOT NULL,
            removed_at datetime NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY case_id (case_id),
            KEY participant_ref (participant_ref),
            KEY participant_type (participant_type)
        ) $charset;";

        $sql[] = "CREATE TABLE {$tables['messages']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            author_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            author_ref varchar(191) NOT NULL DEFAULT '',
            message_type varchar(40) NOT NULL DEFAULT 'support_reply',
            visibility varchar(40) NOT NULL DEFAULT 'participants',
            body longtext NOT NULL,
            body_format varchar(30) NOT NULL DEFAULT 'plain',
            source_message_ref varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            edited_at datetime NULL,
            redacted_at datetime NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY case_id_created (case_id,created_at),
            KEY visibility (visibility),
            KEY source_message_ref (source_message_ref)
        ) $charset;";

        $sql[] = "CREATE TABLE {$tables['events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            event_type varchar(80) NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_ref varchar(191) NOT NULL DEFAULT '',
            event_data_json longtext NULL,
            created_at datetime NOT NULL,
            integrity_hash char(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY case_id_created (case_id,created_at),
            KEY event_type (event_type),
            KEY integrity_hash (integrity_hash)
        ) $charset;";

        $sql[] = "CREATE TABLE {$tables['assignments']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            assigned_team varchar(120) NOT NULL DEFAULT '',
            assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
            assignment_reason text NULL,
            assigned_at datetime NOT NULL,
            ended_at datetime NULL,
            PRIMARY KEY  (id),
            KEY case_id (case_id),
            KEY assigned_user_id (assigned_user_id),
            KEY assigned_team (assigned_team),
            KEY ended_at (ended_at)
        ) $charset;";

        $sql[] = "CREATE TABLE {$tables['relationships']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            relationship_type varchar(80) NOT NULL,
            related_record_type varchar(80) NOT NULL,
            related_record_id bigint(20) unsigned NOT NULL DEFAULT 0,
            related_record_key varchar(191) NOT NULL DEFAULT '',
            public_context_only tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY relationship_unique (case_id,relationship_type,related_record_type,related_record_id,related_record_key),
            KEY related_record (related_record_type,related_record_id),
            KEY case_id (case_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$tables['attachments']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            message_id bigint(20) unsigned NOT NULL DEFAULT 0,
            authority varchar(80) NOT NULL DEFAULT 'contact-engagement',
            external_attachment_ref varchar(191) NOT NULL,
            filename varchar(255) NOT NULL DEFAULT '',
            mime_type varchar(120) NOT NULL DEFAULT '',
            size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            sha256 char(64) NOT NULL DEFAULT '',
            classification varchar(60) NOT NULL DEFAULT 'private_support',
            retention_until datetime NULL,
            redaction_state varchar(40) NOT NULL DEFAULT 'not_reviewed',
            uploaded_by_ref varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            deleted_at datetime NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY authority_ref (authority,external_attachment_ref),
            KEY case_id (case_id),
            KEY message_id (message_id),
            KEY retention_until (retention_until)
        ) $charset;";

        $sql[] = "CREATE TABLE {$tables['sla_events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            event_type varchar(60) NOT NULL,
            target_type varchar(60) NOT NULL DEFAULT '',
            target_at datetime NULL,
            occurred_at datetime NOT NULL,
            paused_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
            state varchar(40) NOT NULL DEFAULT 'recorded',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY case_id_occurred (case_id,occurred_at),
            KEY target_at (target_at),
            KEY event_type (event_type)
        ) $charset;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        $this->record_system_event('schema_installed', array(
            'db_version' => self::DB_VERSION,
            'tables' => array_values($tables),
        ));
    }

    public function maybe_upgrade_schema() {
        if ((string) get_option(self::DB_VERSION_OPTION, '') !== self::DB_VERSION) {
            $this->install_schema();
        }
    }

    public function install_capabilities() {
        $roles = array('administrator', 'editor');
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            $role->add_cap('scfs_view_help_desk');
            $role->add_cap('scfs_reply_help_desk');
            if ($role_name === 'administrator') {
                $role->add_cap('scfs_manage_help_desk');
            }
        }
    }

    public function can_view() {
        return current_user_can('scfs_view_help_desk') || current_user_can('manage_options');
    }

    public function can_reply() {
        return current_user_can('scfs_reply_help_desk') || current_user_can('manage_options');
    }

    public function can_manage() {
        return current_user_can('scfs_manage_help_desk') || current_user_can('manage_options');
    }

    public function statuses() {
        return array(
            'new' => 'New',
            'open' => 'Open',
            'waiting_support' => 'Waiting for Support',
            'waiting_requester' => 'Waiting for Requester',
            'escalated' => 'Escalated',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
            'duplicate' => 'Duplicate',
            'cancelled' => 'Cancelled',
        );
    }

    public function priorities() {
        return array(
            'p1_critical' => 'P1 Critical',
            'p2_high' => 'P2 High',
            'normal' => 'P3 Normal',
            'low' => 'P4 Low',
        );
    }

    public function severities() {
        return array(
            'critical' => 'Critical',
            'major' => 'Major',
            'minor' => 'Minor',
            'informational' => 'Informational',
        );
    }

    public function case_types() {
        return array(
            'how_to' => 'How-to Question',
            'configuration' => 'Configuration',
            'unexpected_behavior' => 'Unexpected Behavior',
            'defect_report' => 'Defect Report',
            'access_problem' => 'Access Problem',
            'documentation_problem' => 'Documentation Problem',
            'data_problem' => 'Data Problem',
            'integration_problem' => 'Integration Problem',
            'security_privacy' => 'Security or Privacy Concern',
            'feature_request' => 'Feature Request',
            'other' => 'Other',
        );
    }

    public function sources() {
        return array(
            'admin' => 'Administrator',
            'contact_engagement' => 'Contact and Engagement Platform',
            'authenticated_portal' => 'Authenticated Portal',
            'email' => 'Email',
            'api' => 'Authenticated API',
            'import' => 'Controlled Import',
        );
    }

    public function privacy_classifications() {
        return array(
            'private_support' => 'Private Support',
            'restricted' => 'Restricted',
            'security_privacy' => 'Security or Privacy Restricted',
            'institutional_private' => 'Institutional Private',
        );
    }

    public function consent_states() {
        return array(
            'not_recorded' => 'Not Recorded',
            'recorded' => 'Recorded',
            'withdrawn' => 'Withdrawn',
            'not_required' => 'Not Required',
        );
    }

    public function allowed_transitions() {
        return array(
            'new' => array('open', 'waiting_requester', 'escalated', 'cancelled', 'duplicate'),
            'open' => array('waiting_support', 'waiting_requester', 'escalated', 'resolved', 'cancelled', 'duplicate'),
            'waiting_support' => array('open', 'waiting_requester', 'escalated', 'resolved', 'cancelled'),
            'waiting_requester' => array('open', 'waiting_support', 'resolved', 'cancelled'),
            'escalated' => array('open', 'waiting_support', 'waiting_requester', 'resolved', 'cancelled'),
            'resolved' => array('open', 'closed'),
            'closed' => array('open'),
            'duplicate' => array('open', 'closed'),
            'cancelled' => array('open'),
        );
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'db_version' => self::DB_VERSION,
            'source_of_truth' => 'wordpress-private-help-desk',
            'case_number_format' => 'SC-YYYY-000001',
            'tables' => array_values($this->table_names()),
            'statuses' => array_keys($this->statuses()),
            'priorities' => array_keys($this->priorities()),
            'severities' => array_keys($this->severities()),
            'case_types' => array_keys($this->case_types()),
            'sources' => array_keys($this->sources()),
            'capabilities' => array(
                'private_case_records',
                'human_readable_case_numbers',
                'case_status_workflow',
                'priority_and_severity',
                'participants',
                'threaded_messages',
                'internal_notes',
                'assignments',
                'public_record_relationships',
                'external_attachment_metadata',
                'sla_event_foundation',
                'append_only_audit_history',
                'rest_and_wp_cli_operations',
            ),
            'privacy' => array(
                'public_case_api' => false,
                'public_case_shortcode' => false,
                'private_case_content_exposed' => false,
                'contact_records_imported' => false,
                'uploaded_files_stored_in_media_library' => false,
                'requester_identity_authority' => 'contact-engagement',
                'attachment_authority' => 'contact-engagement',
            ),
            'governance' => array(
                'human_review_required' => true,
                'automatic_case_creation' => false,
                'automatic_case_resolution' => false,
                'automatic_publication' => false,
                'public_support_records_unchanged' => true,
                'additive_schema_only' => true,
            ),
        );
    }

    public function validate_case_input($input) {
        $input = is_array($input) ? $input : array();
        $errors = array();
        $warnings = array();

        $subject = sanitize_text_field(isset($input['subject']) ? $input['subject'] : '');
        $description = wp_kses_post(isset($input['description']) ? $input['description'] : '');
        $case_type = sanitize_key(isset($input['case_type']) ? $input['case_type'] : 'other');
        $priority = sanitize_key(isset($input['priority']) ? $input['priority'] : 'normal');
        $severity = sanitize_key(isset($input['severity']) ? $input['severity'] : 'informational');
        $source = sanitize_key(isset($input['source']) ? $input['source'] : 'admin');
        $privacy = sanitize_key(isset($input['privacy_classification']) ? $input['privacy_classification'] : 'private_support');
        $consent = sanitize_key(isset($input['consent_state']) ? $input['consent_state'] : 'not_recorded');

        if ($subject === '') {
            $errors[] = 'subject_required';
        }
        if (trim(wp_strip_all_tags($description)) === '') {
            $errors[] = 'description_required';
        }
        if (!isset($this->case_types()[$case_type])) {
            $errors[] = 'invalid_case_type';
        }
        if (!isset($this->priorities()[$priority])) {
            $errors[] = 'invalid_priority';
        }
        if (!isset($this->severities()[$severity])) {
            $errors[] = 'invalid_severity';
        }
        if (!isset($this->sources()[$source])) {
            $errors[] = 'invalid_source';
        }
        if (!isset($this->privacy_classifications()[$privacy])) {
            $errors[] = 'invalid_privacy_classification';
        }
        if (!isset($this->consent_states()[$consent])) {
            $errors[] = 'invalid_consent_state';
        }
        if ($this->settings()['require_consent_for_private_case'] === '1' && $consent === 'not_recorded') {
            $warnings[] = 'consent_not_recorded';
        }
        if ($source === 'contact_engagement' && sanitize_text_field(isset($input['requester_ref']) ? $input['requester_ref'] : '') === '') {
            $errors[] = 'contact_engagement_requester_ref_required';
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'normalized' => array(
                'requester_ref' => sanitize_text_field(isset($input['requester_ref']) ? $input['requester_ref'] : ''),
                'organization_ref' => sanitize_text_field(isset($input['organization_ref']) ? $input['organization_ref'] : ''),
                'product' => sanitize_key(isset($input['product']) ? $input['product'] : ''),
                'product_version' => sanitize_text_field(isset($input['product_version']) ? $input['product_version'] : ''),
                'component' => sanitize_key(isset($input['component']) ? $input['component'] : ''),
                'subject' => $subject,
                'description' => $description,
                'case_type' => $case_type,
                'priority' => $priority,
                'severity' => $severity,
                'source' => $source,
                'privacy_classification' => $privacy,
                'consent_state' => $consent,
                'assigned_user_id' => absint(isset($input['assigned_user_id']) ? $input['assigned_user_id'] : 0),
                'assigned_team' => sanitize_text_field(isset($input['assigned_team']) ? $input['assigned_team'] : ''),
                'parent_case_id' => absint(isset($input['parent_case_id']) ? $input['parent_case_id'] : 0),
                'duplicate_of_case_id' => absint(isset($input['duplicate_of_case_id']) ? $input['duplicate_of_case_id'] : 0),
                'metadata' => isset($input['metadata']) && is_array($input['metadata']) ? $input['metadata'] : array(),
            ),
        );
    }

    private function next_sequence() {
        global $wpdb;
        $option = self::SEQUENCE_OPTION;
        $current = (int) get_option($option, 0);
        if ($current < 1) {
            add_option($option, 0, '', false);
        }
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
                $option
            )
        );
        wp_cache_delete($option, 'options');
        return max(1, (int) get_option($option, 1));
    }

    public function generate_case_number($year = null) {
        $settings = $this->settings();
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $settings['case_prefix']));
        if ($prefix === '') {
            $prefix = 'SC';
        }
        $year = $year ? absint($year) : absint(gmdate('Y'));
        return sprintf('%s-%04d-%06d', $prefix, $year, $this->next_sequence());
    }

    public function create_case($input, $actor_user_id = 0) {
        global $wpdb;
        $validation = $this->validate_case_input($input);
        if (!$validation['valid']) {
            return new WP_Error('scfs_invalid_case', 'The case could not be created.', $validation);
        }

        $tables = $this->table_names();
        $data = $validation['normalized'];
        $now = current_time('mysql', true);
        $case_number = '';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $case_number = $this->generate_case_number();
            $inserted = $wpdb->insert(
                $tables['cases'],
                array(
                    'case_number' => $case_number,
                    'requester_ref' => $data['requester_ref'],
                    'organization_ref' => $data['organization_ref'],
                    'product' => $data['product'],
                    'product_version' => $data['product_version'],
                    'component' => $data['component'],
                    'subject' => $data['subject'],
                    'description' => $data['description'],
                    'case_type' => $data['case_type'],
                    'priority' => $data['priority'],
                    'severity' => $data['severity'],
                    'status' => 'new',
                    'source' => $data['source'],
                    'privacy_classification' => $data['privacy_classification'],
                    'consent_state' => $data['consent_state'],
                    'assigned_user_id' => $data['assigned_user_id'],
                    'assigned_team' => $data['assigned_team'],
                    'parent_case_id' => $data['parent_case_id'],
                    'duplicate_of_case_id' => $data['duplicate_of_case_id'],
                    'created_by' => absint($actor_user_id),
                    'created_at' => $now,
                    'updated_at' => $now,
                    'metadata_json' => wp_json_encode($data['metadata']),
                ),
                array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%d','%d','%s','%s','%s')
            );
            if ($inserted) {
                break;
            }
        }

        if (!$inserted) {
            return new WP_Error('scfs_case_insert_failed', 'Unable to create the private help-desk case.');
        }

        $case_id = (int) $wpdb->insert_id;
        $this->add_event($case_id, 'case_created', array(
            'case_number' => $case_number,
            'source' => $data['source'],
            'privacy_classification' => $data['privacy_classification'],
            'warnings' => $validation['warnings'],
        ), $actor_user_id);

        if ($data['requester_ref'] !== '') {
            $this->add_participant($case_id, array(
                'participant_ref' => $data['requester_ref'],
                'participant_type' => 'requester',
                'display_label' => 'Requester',
                'permission_level' => 'participant',
            ), $actor_user_id);
        }
        if ($data['assigned_user_id'] || $data['assigned_team'] !== '') {
            $this->assign_case($case_id, $data['assigned_user_id'], $data['assigned_team'], 'Initial case assignment', $actor_user_id);
        }
        if ($data['description'] !== '') {
            $this->add_message($case_id, array(
                'author_ref' => $data['requester_ref'],
                'message_type' => 'requester_message',
                'visibility' => 'participants',
                'body' => $data['description'],
                'body_format' => 'html',
            ), $actor_user_id);
        }

        return $this->get_case($case_id);
    }

    public function get_case($case_id) {
        global $wpdb;
        $tables = $this->table_names();
        $case = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['cases']} WHERE id = %d", absint($case_id)), ARRAY_A);
        if (!$case) {
            return null;
        }
        $case['metadata'] = $case['metadata_json'] ? json_decode($case['metadata_json'], true) : array();
        unset($case['metadata_json']);
        return $case;
    }

    public function get_case_by_number($case_number) {
        global $wpdb;
        $tables = $this->table_names();
        $case = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['cases']} WHERE case_number = %s", sanitize_text_field($case_number)), ARRAY_A);
        if (!$case) {
            return null;
        }
        $case['metadata'] = $case['metadata_json'] ? json_decode($case['metadata_json'], true) : array();
        unset($case['metadata_json']);
        return $case;
    }

    public function list_cases($args = array()) {
        global $wpdb;
        $tables = $this->table_names();
        $args = wp_parse_args($args, array(
            'status' => '',
            'priority' => '',
            'product' => '',
            'assigned_user_id' => 0,
            'limit' => 50,
            'offset' => 0,
        ));
        $where = array('1=1');
        $values = array();

        if ($args['status'] !== '' && isset($this->statuses()[sanitize_key($args['status'])])) {
            $where[] = 'status = %s';
            $values[] = sanitize_key($args['status']);
        }
        if ($args['priority'] !== '' && isset($this->priorities()[sanitize_key($args['priority'])])) {
            $where[] = 'priority = %s';
            $values[] = sanitize_key($args['priority']);
        }
        if ($args['product'] !== '') {
            $where[] = 'product = %s';
            $values[] = sanitize_key($args['product']);
        }
        if (absint($args['assigned_user_id'])) {
            $where[] = 'assigned_user_id = %d';
            $values[] = absint($args['assigned_user_id']);
        }

        $limit = min(200, max(1, absint($args['limit'])));
        $offset = max(0, absint($args['offset']));
        $sql = "SELECT * FROM {$tables['cases']} WHERE " . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d';
        $values[] = $limit;
        $values[] = $offset;
        $prepared = $wpdb->prepare($sql, $values);
        return (array) $wpdb->get_results($prepared, ARRAY_A);
    }

    public function transition_case($case_id, $to_status, $reason = '', $actor_user_id = 0) {
        global $wpdb;
        $case = $this->get_case($case_id);
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }

        $from = sanitize_key($case['status']);
        $to = sanitize_key($to_status);
        $allowed = $this->allowed_transitions();
        if (!isset($this->statuses()[$to])) {
            return new WP_Error('scfs_invalid_status', 'Invalid case status.');
        }
        if ($from !== $to && (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true))) {
            return new WP_Error('scfs_invalid_transition', 'The requested case transition is not allowed.', array(
                'from' => $from,
                'to' => $to,
                'allowed' => isset($allowed[$from]) ? $allowed[$from] : array(),
            ));
        }

        $now = current_time('mysql', true);
        $updates = array(
            'status' => $to,
            'updated_at' => $now,
        );
        $formats = array('%s', '%s');
        if ($to === 'resolved') {
            $updates['resolved_at'] = $now;
            $formats[] = '%s';
        }
        if ($to === 'closed') {
            $updates['closed_at'] = $now;
            $formats[] = '%s';
        }
        if ($to === 'open' && in_array($from, array('resolved', 'closed'), true)) {
            $updates['resolved_at'] = null;
            $updates['closed_at'] = null;
            $formats[] = null;
            $formats[] = null;
        }

        $tables = $this->table_names();
        $wpdb->update($tables['cases'], $updates, array('id' => absint($case_id)), $formats, array('%d'));
        $this->add_event($case_id, 'status_transition', array(
            'from' => $from,
            'to' => $to,
            'reason' => sanitize_textarea_field($reason),
        ), $actor_user_id);
        return $this->get_case($case_id);
    }

    public function add_participant($case_id, $participant, $actor_user_id = 0) {
        global $wpdb;
        if (!$this->get_case($case_id)) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $participant = is_array($participant) ? $participant : array();
        $ref = sanitize_text_field(isset($participant['participant_ref']) ? $participant['participant_ref'] : '');
        if ($ref === '') {
            return new WP_Error('scfs_participant_ref_required', 'Participant reference is required.');
        }
        $tables = $this->table_names();
        $wpdb->insert($tables['participants'], array(
            'case_id' => absint($case_id),
            'participant_ref' => $ref,
            'participant_type' => sanitize_key(isset($participant['participant_type']) ? $participant['participant_type'] : 'requester'),
            'display_label' => sanitize_text_field(isset($participant['display_label']) ? $participant['display_label'] : ''),
            'permission_level' => sanitize_key(isset($participant['permission_level']) ? $participant['permission_level'] : 'participant'),
            'added_by' => absint($actor_user_id),
            'added_at' => current_time('mysql', true),
            'metadata_json' => wp_json_encode(isset($participant['metadata']) && is_array($participant['metadata']) ? $participant['metadata'] : array()),
        ), array('%d','%s','%s','%s','%s','%d','%s','%s'));
        $id = (int) $wpdb->insert_id;
        $this->add_event($case_id, 'participant_added', array('participant_id' => $id, 'participant_type' => sanitize_key($participant['participant_type'] ?? 'requester')), $actor_user_id);
        return $id;
    }

    public function add_message($case_id, $message, $actor_user_id = 0) {
        global $wpdb;
        $case = $this->get_case($case_id);
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $message = is_array($message) ? $message : array();
        $body = isset($message['body']) ? (string) $message['body'] : '';
        $visibility = sanitize_key(isset($message['visibility']) ? $message['visibility'] : 'participants');
        $type = sanitize_key(isset($message['message_type']) ? $message['message_type'] : 'support_reply');

        if (trim(wp_strip_all_tags($body)) === '') {
            return new WP_Error('scfs_message_body_required', 'Message body is required.');
        }
        if (!in_array($visibility, array('participants', 'internal'), true)) {
            return new WP_Error('scfs_invalid_message_visibility', 'Invalid message visibility.');
        }
        if (!in_array($type, array('requester_message', 'support_reply', 'internal_note', 'system_note'), true)) {
            return new WP_Error('scfs_invalid_message_type', 'Invalid message type.');
        }
        if ($type === 'internal_note') {
            $visibility = 'internal';
        }

        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $wpdb->insert($tables['messages'], array(
            'case_id' => absint($case_id),
            'author_user_id' => absint($actor_user_id),
            'author_ref' => sanitize_text_field(isset($message['author_ref']) ? $message['author_ref'] : ''),
            'message_type' => $type,
            'visibility' => $visibility,
            'body' => $visibility === 'internal' ? wp_kses_post($body) : wp_kses_post($body),
            'body_format' => sanitize_key(isset($message['body_format']) ? $message['body_format'] : 'html'),
            'source_message_ref' => sanitize_text_field(isset($message['source_message_ref']) ? $message['source_message_ref'] : ''),
            'created_at' => $now,
            'metadata_json' => wp_json_encode(isset($message['metadata']) && is_array($message['metadata']) ? $message['metadata'] : array()),
        ), array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s'));
        $message_id = (int) $wpdb->insert_id;

        $updates = array('updated_at' => $now);
        $formats = array('%s');
        if ($type === 'requester_message') {
            $updates['last_requester_message_at'] = $now;
            $formats[] = '%s';
        } elseif ($type === 'support_reply') {
            $updates['last_support_message_at'] = $now;
            $formats[] = '%s';
            if (empty($case['first_response_at'])) {
                $updates['first_response_at'] = $now;
                $formats[] = '%s';
            }
        }
        $wpdb->update($tables['cases'], $updates, array('id' => absint($case_id)), $formats, array('%d'));
        $this->add_event($case_id, 'message_added', array(
            'message_id' => $message_id,
            'message_type' => $type,
            'visibility' => $visibility,
        ), $actor_user_id);
        return $message_id;
    }

    public function assign_case($case_id, $user_id, $team = '', $reason = '', $actor_user_id = 0) {
        global $wpdb;
        if (!$this->get_case($case_id)) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$tables['assignments']} SET ended_at = %s WHERE case_id = %d AND ended_at IS NULL",
            $now,
            absint($case_id)
        ));
        $wpdb->insert($tables['assignments'], array(
            'case_id' => absint($case_id),
            'assigned_user_id' => absint($user_id),
            'assigned_team' => sanitize_text_field($team),
            'assigned_by' => absint($actor_user_id),
            'assignment_reason' => sanitize_textarea_field($reason),
            'assigned_at' => $now,
        ), array('%d','%d','%s','%d','%s','%s'));
        $assignment_id = (int) $wpdb->insert_id;
        $wpdb->update($tables['cases'], array(
            'assigned_user_id' => absint($user_id),
            'assigned_team' => sanitize_text_field($team),
            'updated_at' => $now,
        ), array('id' => absint($case_id)), array('%d','%s','%s'), array('%d'));
        $this->add_event($case_id, 'case_assigned', array(
            'assignment_id' => $assignment_id,
            'assigned_user_id' => absint($user_id),
            'assigned_team' => sanitize_text_field($team),
            'reason' => sanitize_textarea_field($reason),
        ), $actor_user_id);
        return $assignment_id;
    }

    public function add_relationship($case_id, $relationship, $actor_user_id = 0) {
        global $wpdb;
        if (!$this->get_case($case_id)) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $relationship = is_array($relationship) ? $relationship : array();
        $allowed_types = array('support_article', 'known_issue', 'release_record', 'feature_suggestion', 'documentation_gap', 'parent_case', 'duplicate_case', 'related_case', 'product_handoff');
        $record_type = sanitize_key(isset($relationship['related_record_type']) ? $relationship['related_record_type'] : '');
        if (!in_array($record_type, $allowed_types, true)) {
            return new WP_Error('scfs_invalid_related_record_type', 'Unsupported related record type.');
        }
        $tables = $this->table_names();
        $wpdb->replace($tables['relationships'], array(
            'case_id' => absint($case_id),
            'relationship_type' => sanitize_key(isset($relationship['relationship_type']) ? $relationship['relationship_type'] : 'related'),
            'related_record_type' => $record_type,
            'related_record_id' => absint(isset($relationship['related_record_id']) ? $relationship['related_record_id'] : 0),
            'related_record_key' => sanitize_text_field(isset($relationship['related_record_key']) ? $relationship['related_record_key'] : ''),
            'public_context_only' => isset($relationship['public_context_only']) ? (int) (bool) $relationship['public_context_only'] : 1,
            'created_by' => absint($actor_user_id),
            'created_at' => current_time('mysql', true),
            'metadata_json' => wp_json_encode(isset($relationship['metadata']) && is_array($relationship['metadata']) ? $relationship['metadata'] : array()),
        ), array('%d','%s','%s','%d','%s','%d','%d','%s','%s'));
        $id = (int) $wpdb->insert_id;
        $this->add_event($case_id, 'relationship_added', array('relationship_id' => $id, 'related_record_type' => $record_type), $actor_user_id);
        return $id;
    }

    public function register_attachment_metadata($case_id, $attachment, $actor_user_id = 0) {
        global $wpdb;
        if (!$this->get_case($case_id)) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $attachment = is_array($attachment) ? $attachment : array();
        $external_ref = sanitize_text_field(isset($attachment['external_attachment_ref']) ? $attachment['external_attachment_ref'] : '');
        if ($external_ref === '') {
            return new WP_Error('scfs_external_attachment_ref_required', 'External attachment reference is required.');
        }
        $authority = sanitize_key(isset($attachment['authority']) ? $attachment['authority'] : 'contact-engagement');
        if ($authority !== 'contact-engagement') {
            return new WP_Error('scfs_invalid_attachment_authority', 'Private attachment authority must remain Contact and Engagement.');
        }
        $tables = $this->table_names();
        $wpdb->replace($tables['attachments'], array(
            'case_id' => absint($case_id),
            'message_id' => absint(isset($attachment['message_id']) ? $attachment['message_id'] : 0),
            'authority' => $authority,
            'external_attachment_ref' => $external_ref,
            'filename' => sanitize_file_name(isset($attachment['filename']) ? $attachment['filename'] : ''),
            'mime_type' => sanitize_mime_type(isset($attachment['mime_type']) ? $attachment['mime_type'] : ''),
            'size_bytes' => absint(isset($attachment['size_bytes']) ? $attachment['size_bytes'] : 0),
            'sha256' => preg_match('/^[a-f0-9]{64}$/', (string) ($attachment['sha256'] ?? '')) ? strtolower($attachment['sha256']) : '',
            'classification' => sanitize_key(isset($attachment['classification']) ? $attachment['classification'] : 'private_support'),
            'retention_until' => !empty($attachment['retention_until']) ? sanitize_text_field($attachment['retention_until']) : null,
            'redaction_state' => sanitize_key(isset($attachment['redaction_state']) ? $attachment['redaction_state'] : 'not_reviewed'),
            'uploaded_by_ref' => sanitize_text_field(isset($attachment['uploaded_by_ref']) ? $attachment['uploaded_by_ref'] : ''),
            'created_at' => current_time('mysql', true),
            'metadata_json' => wp_json_encode(isset($attachment['metadata']) && is_array($attachment['metadata']) ? $attachment['metadata'] : array()),
        ), array('%d','%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s'));
        $id = (int) $wpdb->insert_id;
        $this->add_event($case_id, 'attachment_metadata_registered', array(
            'attachment_id' => $id,
            'authority' => $authority,
            'external_attachment_ref' => $external_ref,
        ), $actor_user_id);
        return $id;
    }

    public function add_sla_event($case_id, $event, $actor_user_id = 0) {
        global $wpdb;
        if (!$this->get_case($case_id)) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $event = is_array($event) ? $event : array();
        $tables = $this->table_names();
        $wpdb->insert($tables['sla_events'], array(
            'case_id' => absint($case_id),
            'event_type' => sanitize_key(isset($event['event_type']) ? $event['event_type'] : 'recorded'),
            'target_type' => sanitize_key(isset($event['target_type']) ? $event['target_type'] : ''),
            'target_at' => !empty($event['target_at']) ? sanitize_text_field($event['target_at']) : null,
            'occurred_at' => current_time('mysql', true),
            'paused_seconds' => absint(isset($event['paused_seconds']) ? $event['paused_seconds'] : 0),
            'state' => sanitize_key(isset($event['state']) ? $event['state'] : 'recorded'),
            'created_by' => absint($actor_user_id),
            'metadata_json' => wp_json_encode(isset($event['metadata']) && is_array($event['metadata']) ? $event['metadata'] : array()),
        ), array('%d','%s','%s','%s','%s','%d','%s','%d','%s'));
        $id = (int) $wpdb->insert_id;
        $this->add_event($case_id, 'sla_event_added', array('sla_event_id' => $id, 'event_type' => sanitize_key($event['event_type'] ?? 'recorded')), $actor_user_id);
        return $id;
    }

    public function add_event($case_id, $event_type, $data = array(), $actor_user_id = 0, $actor_ref = '') {
        global $wpdb;
        $tables = $this->table_names();
        $created_at = current_time('mysql', true);
        $payload = array(
            'case_id' => absint($case_id),
            'event_type' => sanitize_key($event_type),
            'actor_user_id' => absint($actor_user_id),
            'actor_ref' => sanitize_text_field($actor_ref),
            'event_data' => is_array($data) ? $data : array(),
            'created_at' => $created_at,
        );
        $hash = hash('sha256', wp_json_encode($payload));
        $wpdb->insert($tables['events'], array(
            'case_id' => absint($case_id),
            'event_type' => $payload['event_type'],
            'actor_user_id' => $payload['actor_user_id'],
            'actor_ref' => $payload['actor_ref'],
            'event_data_json' => wp_json_encode($payload['event_data']),
            'created_at' => $created_at,
            'integrity_hash' => $hash,
        ), array('%d','%s','%d','%s','%s','%s','%s'));
        $event_id = (int) $wpdb->insert_id;
        do_action('scfs_help_desk_case_event_recorded', absint($case_id), $payload['event_type'], $payload['event_data'], $payload['actor_user_id'], $event_id);
        return $event_id;
    }

    private function record_system_event($event_type, $data) {
        if (!did_action('plugins_loaded')) {
            return 0;
        }
        return $this->add_event(0, $event_type, $data, get_current_user_id(), 'system');
    }

    public function case_timeline($case_id, $include_internal = true) {
        global $wpdb;
        $tables = $this->table_names();
        $case_id = absint($case_id);
        $events = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id,event_type,actor_user_id,actor_ref,event_data_json,created_at,integrity_hash FROM {$tables['events']} WHERE case_id = %d ORDER BY created_at ASC,id ASC",
            $case_id
        ), ARRAY_A);
        $messages_sql = "SELECT id,author_user_id,author_ref,message_type,visibility,body,body_format,created_at,edited_at,redacted_at FROM {$tables['messages']} WHERE case_id = %d";
        if (!$include_internal) {
            $messages_sql .= " AND visibility = 'participants'";
        }
        $messages_sql .= ' ORDER BY created_at ASC,id ASC';
        $messages = (array) $wpdb->get_results($wpdb->prepare($messages_sql, $case_id), ARRAY_A);
        return array('events' => $events, 'messages' => $messages);
    }

    public function integrity_report() {
        global $wpdb;
        $tables = $this->table_names();
        $missing = array();
        foreach ($tables as $key => $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                $missing[] = $key;
            }
        }
        $duplicate_case_numbers = 0;
        $orphan_messages = 0;
        $orphan_events = 0;
        if (empty($missing)) {
            $duplicate_case_numbers = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT case_number FROM {$tables['cases']} GROUP BY case_number HAVING COUNT(*) > 1) duplicate_numbers");
            $orphan_messages = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['messages']} m LEFT JOIN {$tables['cases']} c ON c.id=m.case_id WHERE c.id IS NULL");
            $orphan_events = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['events']} e LEFT JOIN {$tables['cases']} c ON c.id=e.case_id WHERE e.case_id<>0 AND c.id IS NULL");
        }
        $report = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'db_version' => self::DB_VERSION,
            'generated_at' => gmdate('c'),
            'missing_tables' => $missing,
            'duplicate_case_numbers' => $duplicate_case_numbers,
            'orphan_messages' => $orphan_messages,
            'orphan_events' => $orphan_events,
            'healthy' => empty($missing) && $duplicate_case_numbers === 0 && $orphan_messages === 0 && $orphan_events === 0,
        );
        $report['integrity_hash'] = hash('sha256', wp_json_encode($report));
        return $report;
    }

    public function daily_maintenance() {
        $this->integrity_report();
        do_action('scfs_help_desk_daily_maintenance', self::VERSION);
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Help Desk Cases', 'sustainable-catalyst-feature-suggestions'),
            __('Help Desk Cases', 'sustainable-catalyst-feature-suggestions'),
            'scfs_view_help_desk',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false) {
            return;
        }
        $css = dirname(__DIR__) . '/assets/help-desk-case-foundation.css';
        wp_enqueue_style(
            'scfs-help-desk-case-foundation',
            plugins_url('../assets/help-desk-case-foundation.css', __FILE__),
            array(),
            is_readable($css) ? (string) filemtime($css) : self::VERSION
        );
    }

    public function render_admin_page() {
        if (!$this->can_view()) {
            wp_die(esc_html__('You do not have permission to view the Help Desk.', 'sustainable-catalyst-feature-suggestions'));
        }
        $case_id = isset($_GET['case_id']) ? absint($_GET['case_id']) : 0;
        echo '<div class="wrap scfs-help-desk">';
        echo '<h1>' . esc_html__('Help Desk Cases', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p class="description">' . esc_html__('Private case operations. Public Support Articles, Known Issues, releases, and suggestions remain separate records.', 'sustainable-catalyst-feature-suggestions') . '</p>';

        if ($case_id) {
            $this->render_case_detail($case_id);
        } else {
            $this->render_case_list();
        }
        echo '</div>';
    }

    private function render_case_list() {
        $cases = $this->list_cases(array(
            'status' => isset($_GET['status']) ? sanitize_key($_GET['status']) : '',
            'priority' => isset($_GET['priority']) ? sanitize_key($_GET['priority']) : '',
            'product' => isset($_GET['product']) ? sanitize_key($_GET['product']) : '',
            'limit' => 100,
        ));
        echo '<div class="scfs-help-desk__toolbar">';
        if ($this->can_manage()) {
            echo '<a class="button button-primary" href="#scfs-new-case">' . esc_html__('Create private case', 'sustainable-catalyst-feature-suggestions') . '</a>';
        }
        echo '<span class="scfs-help-desk__privacy">' . esc_html__('Private records are never exposed through public shortcodes or public REST routes.', 'sustainable-catalyst-feature-suggestions') . '</span>';
        echo '</div>';
        echo '<table class="widefat striped scfs-help-desk__table"><thead><tr>';
        foreach (array('Case','Subject','Product','Priority','Status','Assigned','Updated') as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$cases) {
            echo '<tr><td colspan="7">' . esc_html__('No private help-desk cases found.', 'sustainable-catalyst-feature-suggestions') . '</td></tr>';
        }
        foreach ($cases as $case) {
            $url = add_query_arg(array('post_type' => Sustainable_Catalyst_Feature_Suggestions::POST_TYPE, 'page' => self::ADMIN_PAGE, 'case_id' => absint($case['id'])), admin_url('edit.php'));
            echo '<tr>';
            echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html($case['case_number']) . '</strong></a></td>';
            echo '<td>' . esc_html($case['subject']) . '</td>';
            echo '<td>' . esc_html($case['product']) . '</td>';
            echo '<td>' . esc_html($this->priorities()[$case['priority']] ?? $case['priority']) . '</td>';
            echo '<td>' . esc_html($this->statuses()[$case['status']] ?? $case['status']) . '</td>';
            echo '<td>' . esc_html($case['assigned_team'] ?: ($case['assigned_user_id'] ? 'User #' . absint($case['assigned_user_id']) : 'Unassigned')) . '</td>';
            echo '<td>' . esc_html($case['updated_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($this->can_manage()) {
            echo '<section id="scfs-new-case" class="scfs-help-desk__panel">';
            echo '<h2>' . esc_html__('Create private case', 'sustainable-catalyst-feature-suggestions') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="scfs_help_desk_create_case">';
            $this->render_case_fields();
            submit_button(__('Create case', 'sustainable-catalyst-feature-suggestions'));
            echo '</form></section>';
        }
    }

    private function render_case_fields() {
        echo '<div class="scfs-help-desk__grid">';
        echo '<label><span>' . esc_html__('Subject', 'sustainable-catalyst-feature-suggestions') . '</span><input name="subject" required></label>';
        echo '<label><span>' . esc_html__('Requester reference', 'sustainable-catalyst-feature-suggestions') . '</span><input name="requester_ref" placeholder="Contact and Engagement record reference"></label>';
        echo '<label><span>' . esc_html__('Product', 'sustainable-catalyst-feature-suggestions') . '</span><input name="product"></label>';
        echo '<label><span>' . esc_html__('Version', 'sustainable-catalyst-feature-suggestions') . '</span><input name="product_version"></label>';
        echo '<label><span>' . esc_html__('Component', 'sustainable-catalyst-feature-suggestions') . '</span><input name="component"></label>';
        echo '<label><span>' . esc_html__('Case type', 'sustainable-catalyst-feature-suggestions') . '</span><select name="case_type">';
        foreach ($this->case_types() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>' . esc_html__('Priority', 'sustainable-catalyst-feature-suggestions') . '</span><select name="priority">';
        foreach ($this->priorities() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>' . esc_html__('Severity', 'sustainable-catalyst-feature-suggestions') . '</span><select name="severity">';
        foreach ($this->severities() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>' . esc_html__('Consent state', 'sustainable-catalyst-feature-suggestions') . '</span><select name="consent_state">';
        foreach ($this->consent_states() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '</div>';
        echo '<label class="scfs-help-desk__full"><span>' . esc_html__('Description', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="description" rows="7" required></textarea></label>';
    }

    private function render_case_detail($case_id) {
        $case = $this->get_case($case_id);
        if (!$case) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Case not found.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
            return;
        }
        $timeline = $this->case_timeline($case_id, true);
        $back = add_query_arg(array('post_type' => Sustainable_Catalyst_Feature_Suggestions::POST_TYPE, 'page' => self::ADMIN_PAGE), admin_url('edit.php'));
        echo '<p><a href="' . esc_url($back) . '">&larr; ' . esc_html__('All cases', 'sustainable-catalyst-feature-suggestions') . '</a></p>';
        echo '<header class="scfs-help-desk__case-header"><div><p class="scfs-help-desk__eyebrow">' . esc_html($case['case_number']) . '</p><h2>' . esc_html($case['subject']) . '</h2></div>';
        echo '<div class="scfs-help-desk__badges"><span>' . esc_html($this->priorities()[$case['priority']] ?? $case['priority']) . '</span><span>' . esc_html($this->statuses()[$case['status']] ?? $case['status']) . '</span></div></header>';
        echo '<div class="scfs-help-desk__case-layout"><main>';
        echo '<section class="scfs-help-desk__panel"><h3>' . esc_html__('Case description', 'sustainable-catalyst-feature-suggestions') . '</h3>' . wp_kses_post(wpautop($case['description'])) . '</section>';
        echo '<section class="scfs-help-desk__panel"><h3>' . esc_html__('Conversation', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        foreach ($timeline['messages'] as $message) {
            $class = $message['visibility'] === 'internal' ? ' is-internal' : '';
            echo '<article class="scfs-help-desk__message' . esc_attr($class) . '"><header><strong>' . esc_html(ucwords(str_replace('_', ' ', $message['message_type']))) . '</strong><time>' . esc_html($message['created_at']) . '</time></header><div>' . wp_kses_post(wpautop($message['body'])) . '</div></article>';
        }
        if (!$timeline['messages']) {
            echo '<p>' . esc_html__('No messages have been recorded.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        }
        if ($this->can_reply()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-help-desk__reply">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="scfs_help_desk_add_message"><input type="hidden" name="case_id" value="' . absint($case_id) . '">';
            echo '<label><span>' . esc_html__('Reply or internal note', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="body" rows="6" required></textarea></label>';
            echo '<label><input type="checkbox" name="internal_note" value="1"> ' . esc_html__('Internal note — never visible to requester', 'sustainable-catalyst-feature-suggestions') . '</label>';
            submit_button(__('Add message', 'sustainable-catalyst-feature-suggestions'));
            echo '</form>';
        }
        echo '</section></main><aside>';
        echo '<section class="scfs-help-desk__panel"><h3>' . esc_html__('Case metadata', 'sustainable-catalyst-feature-suggestions') . '</h3><dl>';
        $meta = array(
            'Product' => $case['product'],
            'Version' => $case['product_version'],
            'Component' => $case['component'],
            'Type' => $this->case_types()[$case['case_type']] ?? $case['case_type'],
            'Severity' => $this->severities()[$case['severity']] ?? $case['severity'],
            'Source' => $case['source'],
            'Privacy' => $case['privacy_classification'],
            'Consent' => $case['consent_state'],
        );
        foreach ($meta as $label => $value) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value ?: '—') . '</dd></div>';
        }
        echo '</dl></section>';
        if ($this->can_manage()) {
            echo '<section class="scfs-help-desk__panel"><h3>' . esc_html__('Change status', 'sustainable-catalyst-feature-suggestions') . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="scfs_help_desk_transition_case"><input type="hidden" name="case_id" value="' . absint($case_id) . '"><select name="status">';
            foreach ($this->statuses() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($case['status'], $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select><textarea name="reason" rows="3" placeholder="' . esc_attr__('Reason for transition', 'sustainable-catalyst-feature-suggestions') . '"></textarea>';
            submit_button(__('Update status', 'sustainable-catalyst-feature-suggestions'), 'secondary');
            echo '</form></section>';
        }
        echo '</aside></div>';
    }

    private function verify_admin_action($capability = 'manage') {
        check_admin_referer(self::NONCE_ACTION);
        $allowed = $capability === 'reply' ? $this->can_reply() : $this->can_manage();
        if (!$allowed) {
            wp_die(esc_html__('You do not have permission to perform this help-desk action.', 'sustainable-catalyst-feature-suggestions'));
        }
    }

    private function admin_case_url($case_id = 0) {
        $args = array('post_type' => Sustainable_Catalyst_Feature_Suggestions::POST_TYPE, 'page' => self::ADMIN_PAGE);
        if ($case_id) {
            $args['case_id'] = absint($case_id);
        }
        return add_query_arg($args, admin_url('edit.php'));
    }

    public function admin_create_case() {
        $this->verify_admin_action('manage');
        $case = $this->create_case(wp_unslash($_POST), get_current_user_id());
        if (is_wp_error($case)) {
            wp_safe_redirect(add_query_arg('scfs_help_desk_error', rawurlencode($case->get_error_code()), $this->admin_case_url()));
            exit;
        }
        wp_safe_redirect($this->admin_case_url($case['id']));
        exit;
    }

    public function admin_transition_case() {
        $this->verify_admin_action('manage');
        $case_id = absint(isset($_POST['case_id']) ? $_POST['case_id'] : 0);
        $result = $this->transition_case($case_id, isset($_POST['status']) ? wp_unslash($_POST['status']) : '', isset($_POST['reason']) ? wp_unslash($_POST['reason']) : '', get_current_user_id());
        $url = $this->admin_case_url($case_id);
        if (is_wp_error($result)) {
            $url = add_query_arg('scfs_help_desk_error', rawurlencode($result->get_error_code()), $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public function admin_add_message() {
        $this->verify_admin_action('reply');
        $case_id = absint(isset($_POST['case_id']) ? $_POST['case_id'] : 0);
        $internal = !empty($_POST['internal_note']);
        $result = $this->add_message($case_id, array(
            'message_type' => $internal ? 'internal_note' : 'support_reply',
            'visibility' => $internal ? 'internal' : 'participants',
            'body' => isset($_POST['body']) ? wp_unslash($_POST['body']) : '',
            'body_format' => 'html',
        ), get_current_user_id());
        $url = $this->admin_case_url($case_id);
        if (is_wp_error($result)) {
            $url = add_query_arg('scfs_help_desk_error', rawurlencode($result->get_error_code()), $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public function admin_assign_case() {
        $this->verify_admin_action('manage');
        $case_id = absint(isset($_POST['case_id']) ? $_POST['case_id'] : 0);
        $this->assign_case(
            $case_id,
            absint(isset($_POST['assigned_user_id']) ? $_POST['assigned_user_id'] : 0),
            isset($_POST['assigned_team']) ? wp_unslash($_POST['assigned_team']) : '',
            isset($_POST['reason']) ? wp_unslash($_POST['reason']) : '',
            get_current_user_id()
        );
        wp_safe_redirect($this->admin_case_url($case_id));
        exit;
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;

        register_rest_route($namespace, '/help-desk/schema', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_schema'),
            'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route($namespace, '/help-desk/cases', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'rest_list_cases'),
                'permission_callback' => array($this, 'can_view'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'rest_create_case'),
                'permission_callback' => array($this, 'can_manage'),
            ),
        ));
        register_rest_route($namespace, '/help-desk/cases/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_get_case'),
            'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route($namespace, '/help-desk/cases/(?P<id>\d+)/transition', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_transition_case'),
            'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route($namespace, '/help-desk/cases/(?P<id>\d+)/messages', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_add_message'),
            'permission_callback' => array($this, 'can_reply'),
        ));
        register_rest_route($namespace, '/help-desk/cases/(?P<id>\d+)/assignments', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_assign_case'),
            'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route($namespace, '/help-desk/cases/(?P<id>\d+)/relationships', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_add_relationship'),
            'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route($namespace, '/help-desk/cases/(?P<id>\d+)/attachments', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_register_attachment'),
            'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route($namespace, '/help-desk/integrity', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_integrity'),
            'permission_callback' => array($this, 'can_manage'),
        ));
    }

    public function rest_schema() {
        return rest_ensure_response($this->schema_record());
    }

    public function rest_list_cases(WP_REST_Request $request) {
        return rest_ensure_response(array(
            'schema' => self::SCHEMA,
            'records' => $this->list_cases(array(
                'status' => $request->get_param('status'),
                'priority' => $request->get_param('priority'),
                'product' => $request->get_param('product'),
                'assigned_user_id' => $request->get_param('assigned_user_id'),
                'limit' => $request->get_param('limit') ?: 50,
                'offset' => $request->get_param('offset') ?: 0,
            )),
        ));
    }

    public function rest_create_case(WP_REST_Request $request) {
        $result = $this->create_case((array) $request->get_json_params(), get_current_user_id());
        return is_wp_error($result) ? $result : new WP_REST_Response($result, 201);
    }

    public function rest_get_case(WP_REST_Request $request) {
        $case = $this->get_case(absint($request['id']));
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'Case not found.', array('status' => 404));
        }
        $case['timeline'] = $this->case_timeline($case['id'], true);
        return rest_ensure_response($case);
    }

    public function rest_transition_case(WP_REST_Request $request) {
        return $this->transition_case(
            absint($request['id']),
            sanitize_key($request->get_param('status')),
            sanitize_textarea_field($request->get_param('reason')),
            get_current_user_id()
        );
    }

    public function rest_add_message(WP_REST_Request $request) {
        return $this->add_message(absint($request['id']), (array) $request->get_json_params(), get_current_user_id());
    }

    public function rest_assign_case(WP_REST_Request $request) {
        $params = (array) $request->get_json_params();
        return $this->assign_case(
            absint($request['id']),
            absint(isset($params['assigned_user_id']) ? $params['assigned_user_id'] : 0),
            isset($params['assigned_team']) ? $params['assigned_team'] : '',
            isset($params['reason']) ? $params['reason'] : '',
            get_current_user_id()
        );
    }

    public function rest_add_relationship(WP_REST_Request $request) {
        return $this->add_relationship(absint($request['id']), (array) $request->get_json_params(), get_current_user_id());
    }

    public function rest_register_attachment(WP_REST_Request $request) {
        return $this->register_attachment_metadata(absint($request['id']), (array) $request->get_json_params(), get_current_user_id());
    }

    public function rest_integrity() {
        return rest_ensure_response($this->integrity_report());
    }

    public function cli_schema() {
        WP_CLI::line(wp_json_encode($this->schema_record(), JSON_PRETTY_PRINT));
    }

    public function cli_create_case($args, $assoc_args) {
        unset($args);
        $result = $this->create_case(array(
            'subject' => isset($assoc_args['subject']) ? $assoc_args['subject'] : '',
            'description' => isset($assoc_args['description']) ? $assoc_args['description'] : '',
            'requester_ref' => isset($assoc_args['requester-ref']) ? $assoc_args['requester-ref'] : '',
            'organization_ref' => isset($assoc_args['organization-ref']) ? $assoc_args['organization-ref'] : '',
            'product' => isset($assoc_args['product']) ? $assoc_args['product'] : '',
            'product_version' => isset($assoc_args['version']) ? $assoc_args['version'] : '',
            'component' => isset($assoc_args['component']) ? $assoc_args['component'] : '',
            'case_type' => isset($assoc_args['type']) ? $assoc_args['type'] : 'other',
            'priority' => isset($assoc_args['priority']) ? $assoc_args['priority'] : 'normal',
            'severity' => isset($assoc_args['severity']) ? $assoc_args['severity'] : 'informational',
            'source' => 'admin',
            'consent_state' => isset($assoc_args['consent']) ? $assoc_args['consent'] : 'not_recorded',
        ), get_current_user_id());
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success('Created ' . $result['case_number']);
    }

    public function cli_case($args) {
        $identifier = isset($args[0]) ? $args[0] : '';
        $case = ctype_digit((string) $identifier) ? $this->get_case(absint($identifier)) : $this->get_case_by_number($identifier);
        if (!$case) {
            WP_CLI::error('Case not found.');
        }
        $case['timeline'] = $this->case_timeline($case['id'], true);
        WP_CLI::line(wp_json_encode($case, JSON_PRETTY_PRINT));
    }

    public function cli_transition($args, $assoc_args) {
        $identifier = isset($args[0]) ? $args[0] : '';
        $status = isset($args[1]) ? $args[1] : '';
        $case = ctype_digit((string) $identifier) ? $this->get_case(absint($identifier)) : $this->get_case_by_number($identifier);
        if (!$case) {
            WP_CLI::error('Case not found.');
        }
        $result = $this->transition_case($case['id'], $status, isset($assoc_args['reason']) ? $assoc_args['reason'] : '', get_current_user_id());
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success($result['case_number'] . ' is now ' . $result['status']);
    }

    public function cli_integrity() {
        $report = $this->integrity_report();
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT));
        if (!$report['healthy']) {
            WP_CLI::halt(1);
        }
    }
}
