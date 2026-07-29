<?php
/**
 * Help Desk Secure Evidence, Attachments, and Diagnostic Intake.
 *
 * Keeps private file bytes outside the WordPress Media Library, delegates
 * storage and delivery to Contact and Engagement, and maintains governed
 * case-linked evidence metadata, consent, access, retention, redaction,
 * diagnostic manifests, and append-only evidence events.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Secure_Evidence {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-secure-evidence/1.0';
    const DB_VERSION = '1.4.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_secure_evidence_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_secure_evidence_settings';
    const ADMIN_PAGE = 'scfs-help-desk-secure-evidence';
    const NONCE_ACTION = 'scfs_help_desk_secure_evidence_action';
    const CRON_HOOK = 'scfs_help_desk_evidence_retention_scan';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 8);
        add_action('admin_menu', array($this, 'register_admin_page'), 50);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'retention_scan'));
        add_action('scfs_help_desk_case_workspace_after', array($this, 'render_case_panel'));
        add_filter('scfs_help_desk_portal_case_payload', array($this, 'extend_customer_payload'), 20, 2);
        add_action('admin_post_scfs_help_desk_evidence_case', array($this, 'admin_case_action'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk evidence status', array($this, 'cli_status'));
            WP_CLI::add_command('scfs help-desk evidence case', array($this, 'cli_case'));
            WP_CLI::add_command('scfs help-desk evidence request', array($this, 'cli_request'));
            WP_CLI::add_command('scfs help-desk evidence retention-scan', array($this, 'cli_retention_scan'));
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
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    private function foundation() {
        return class_exists('SCFS_Help_Desk_Case_Foundation') ? SCFS_Help_Desk_Case_Foundation::instance() : null;
    }

    public function default_settings() {
        return array(
            'attachment_authority' => 'contact-engagement',
            'default_classification' => 'private_support',
            'default_retention_days' => 90,
            'maximum_retention_days' => 365,
            'maximum_file_size_bytes' => 26214400,
            'intake_expiry_hours' => 168,
            'allowed_mime_types' => array(
                'image/png', 'image/jpeg', 'application/pdf', 'text/plain',
                'text/csv', 'application/json', 'application/zip',
            ),
            'blocked_filename_extensions' => array('php', 'phar', 'phtml', 'js', 'html', 'htm', 'exe', 'dmg', 'pkg', 'sh', 'command'),
            'require_sha256' => '1',
            'require_malware_scan' => '1',
            'automatic_redaction' => '0',
            'automatic_deletion' => '0',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'intakes' => $wpdb->prefix . 'scfs_help_desk_evidence_intakes',
            'diagnostics' => $wpdb->prefix . 'scfs_help_desk_diagnostic_bundles',
            'access' => $wpdb->prefix . 'scfs_help_desk_attachment_access',
            'events' => $wpdb->prefix . 'scfs_help_desk_attachment_events',
            'retention' => $wpdb->prefix . 'scfs_help_desk_retention_actions',
        );
    }

    public function install_schema() {
        global $wpdb;
        $tables = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = array();
        $sql[] = "CREATE TABLE {$tables['intakes']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            intake_key varchar(120) NOT NULL,
            purpose varchar(80) NOT NULL DEFAULT 'troubleshooting',
            instructions text NULL,
            classification varchar(60) NOT NULL DEFAULT 'private_support',
            consent_state varchar(40) NOT NULL DEFAULT 'required',
            status varchar(40) NOT NULL DEFAULT 'requested',
            requester_ref varchar(191) NOT NULL DEFAULT '',
            requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
            requested_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            submitted_at datetime NULL,
            closed_at datetime NULL,
            allowed_types_json longtext NOT NULL,
            maximum_size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY intake_key (intake_key),
            KEY case_status (case_id,status),
            KEY expires_at (expires_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['diagnostics']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            intake_id bigint(20) unsigned NOT NULL DEFAULT 0,
            bundle_key varchar(120) NOT NULL,
            product varchar(120) NOT NULL DEFAULT '',
            product_version varchar(120) NOT NULL DEFAULT '',
            environment_json longtext NOT NULL,
            manifest_json longtext NOT NULL,
            manifest_sha256 char(64) NOT NULL DEFAULT '',
            validation_state varchar(40) NOT NULL DEFAULT 'pending_review',
            redaction_state varchar(40) NOT NULL DEFAULT 'not_reviewed',
            scan_state varchar(40) NOT NULL DEFAULT 'pending',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY bundle_key (bundle_key),
            KEY case_created (case_id,created_at),
            KEY validation_state (validation_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['access']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            grant_hash char(64) NOT NULL,
            audience varchar(40) NOT NULL DEFAULT 'agent',
            granted_to_ref varchar(191) NOT NULL DEFAULT '',
            purpose varchar(80) NOT NULL DEFAULT 'case_review',
            max_uses int(10) unsigned NOT NULL DEFAULT 1,
            use_count int(10) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            last_used_at datetime NULL,
            revoked_at datetime NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY grant_hash (grant_hash),
            KEY attachment_active (attachment_id,revoked_at),
            KEY case_id (case_id),
            KEY expires_at (expires_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            intake_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(80) NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_ref varchar(191) NOT NULL DEFAULT '',
            event_data_json longtext NULL,
            integrity_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY case_created (case_id,created_at),
            KEY attachment_id (attachment_id),
            KEY event_type (event_type),
            KEY integrity_hash (integrity_hash)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['retention']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            action_type varchar(60) NOT NULL DEFAULT 'review',
            state varchar(40) NOT NULL DEFAULT 'scheduled',
            scheduled_for datetime NOT NULL,
            reason text NULL,
            requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
            requested_at datetime NOT NULL,
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime NULL,
            completed_at datetime NULL,
            authority_action_ref varchar(191) NOT NULL DEFAULT '',
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY scheduled_state (scheduled_for,state),
            KEY attachment_id (attachment_id),
            KEY case_id (case_id)
        ) $charset;";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public function maybe_upgrade_schema() {
        if ((string) get_option(self::DB_VERSION_OPTION, '') !== self::DB_VERSION) {
            $this->install_schema();
        }
    }

    public function install_capabilities() {
        $capabilities = array(
            'scfs_view_help_desk_evidence', 'scfs_manage_help_desk_evidence',
            'scfs_download_help_desk_evidence', 'scfs_redact_help_desk_evidence',
            'scfs_manage_help_desk_retention',
        );
        foreach (array('administrator', 'editor', 'scfs_help_desk_agent') as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            $role->add_cap('scfs_view_help_desk_evidence');
            $role->add_cap('scfs_download_help_desk_evidence');
            if ($role_name === 'administrator') {
                foreach ($capabilities as $capability) {
                    $role->add_cap($capability);
                }
            }
        }
    }

    public function can_view() {
        return current_user_can('scfs_view_help_desk_evidence') || current_user_can('manage_options');
    }

    public function can_manage() {
        return current_user_can('scfs_manage_help_desk_evidence') || current_user_can('manage_options');
    }

    private function allowed_classifications() {
        return array('private_support', 'confidential', 'security_sensitive', 'synthetic_sample');
    }

    private function allowed_redaction_states() {
        return array('not_reviewed', 'review_required', 'redacted', 'approved_unredacted', 'quarantined');
    }

    private function case_exists($case_id) {
        $foundation = $this->foundation();
        return $foundation ? $foundation->get_case(absint($case_id)) : null;
    }

    private function event_hash($case_id, $attachment_id, $event_type, $created_at, $data) {
        return hash('sha256', wp_json_encode(array(
            'case_id' => absint($case_id),
            'attachment_id' => absint($attachment_id),
            'event_type' => sanitize_key($event_type),
            'created_at' => $created_at,
            'data' => $data,
        )));
    }

    public function add_evidence_event($case_id, $event_type, $data = array(), $attachment_id = 0, $intake_id = 0, $actor_user_id = 0, $actor_ref = '') {
        global $wpdb;
        $tables = $this->table_names();
        $created_at = current_time('mysql', true);
        $data = is_array($data) ? $data : array();
        $wpdb->insert($tables['events'], array(
            'case_id' => absint($case_id),
            'attachment_id' => absint($attachment_id),
            'intake_id' => absint($intake_id),
            'event_type' => sanitize_key($event_type),
            'actor_user_id' => absint($actor_user_id),
            'actor_ref' => sanitize_text_field($actor_ref),
            'event_data_json' => wp_json_encode($data),
            'integrity_hash' => $this->event_hash($case_id, $attachment_id, $event_type, $created_at, $data),
            'created_at' => $created_at,
        ), array('%d','%d','%d','%s','%d','%s','%s','%s','%s'));
        $id = (int) $wpdb->insert_id;
        $foundation = $this->foundation();
        if ($foundation) {
            $foundation->add_event($case_id, 'secure_evidence_' . sanitize_key($event_type), array(
                'evidence_event_id' => $id,
                'attachment_id' => absint($attachment_id),
                'intake_id' => absint($intake_id),
            ), $actor_user_id, $actor_ref);
        }
        return $id;
    }

    public function create_intake($case_id, $payload, $actor_user_id = 0) {
        global $wpdb;
        $case = $this->case_exists($case_id);
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $payload = is_array($payload) ? $payload : array();
        $settings = $this->settings();
        $classification = sanitize_key($payload['classification'] ?? $settings['default_classification']);
        if (!in_array($classification, $this->allowed_classifications(), true)) {
            return new WP_Error('scfs_invalid_evidence_classification', 'Invalid evidence classification.');
        }
        $consent_state = sanitize_key($payload['consent_state'] ?? 'required');
        if (!in_array($consent_state, array('required', 'accepted', 'not_required'), true)) {
            return new WP_Error('scfs_invalid_consent_state', 'Invalid consent state.');
        }
        $hours = min(720, max(1, absint($payload['expires_in_hours'] ?? $settings['intake_expiry_hours'])));
        $intake_key = 'SC-EV-' . gmdate('Ymd') . '-' . strtoupper(wp_generate_password(10, false, false));
        $tables = $this->table_names();
        $wpdb->insert($tables['intakes'], array(
            'case_id' => absint($case_id),
            'intake_key' => $intake_key,
            'purpose' => sanitize_key($payload['purpose'] ?? 'troubleshooting'),
            'instructions' => sanitize_textarea_field($payload['instructions'] ?? ''),
            'classification' => $classification,
            'consent_state' => $consent_state,
            'status' => 'requested',
            'requester_ref' => sanitize_text_field($payload['requester_ref'] ?? $case['requester_ref']),
            'requested_by' => absint($actor_user_id),
            'requested_at' => current_time('mysql', true),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($hours * HOUR_IN_SECONDS)),
            'allowed_types_json' => wp_json_encode(array_values((array) $settings['allowed_mime_types'])),
            'maximum_size_bytes' => absint($settings['maximum_file_size_bytes']),
            'metadata_json' => wp_json_encode(array(
                'authority' => 'contact-engagement',
                'upload_bytes_accepted_by_this_plugin' => false,
                'malware_scan_required' => $settings['require_malware_scan'] === '1',
            )),
        ), array('%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%d','%s'));
        $id = (int) $wpdb->insert_id;
        $this->add_evidence_event($case_id, 'intake_requested', array('intake_key' => $intake_key, 'classification' => $classification), 0, $id, $actor_user_id);
        do_action('scfs_help_desk_evidence_intake_requested', array(
            'case_id' => absint($case_id),
            'intake_id' => $id,
            'intake_key' => $intake_key,
            'authority' => 'contact-engagement',
            'requester_ref' => sanitize_text_field($payload['requester_ref'] ?? $case['requester_ref']),
            'expires_at' => gmdate('c', time() + ($hours * HOUR_IN_SECONDS)),
        ));
        return $this->get_intake($id);
    }

    public function get_intake($intake_id) {
        global $wpdb;
        $tables = $this->table_names();
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['intakes']} WHERE id=%d", absint($intake_id)), ARRAY_A);
        if (!$record) {
            return null;
        }
        $record['allowed_types'] = json_decode($record['allowed_types_json'], true) ?: array();
        $record['metadata'] = json_decode($record['metadata_json'], true) ?: array();
        unset($record['allowed_types_json'], $record['metadata_json']);
        return $record;
    }

    private function validate_attachment_payload($payload) {
        $settings = $this->settings();
        $filename = sanitize_file_name($payload['filename'] ?? '');
        $mime_type = sanitize_mime_type($payload['mime_type'] ?? '');
        $size = absint($payload['size_bytes'] ?? 0);
        $sha256 = strtolower((string) ($payload['sha256'] ?? ''));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($filename === '' || $mime_type === '' || $size < 1) {
            return new WP_Error('scfs_attachment_metadata_incomplete', 'Filename, MIME type, and size are required.');
        }
        if (in_array($extension, (array) $settings['blocked_filename_extensions'], true)) {
            return new WP_Error('scfs_attachment_extension_blocked', 'This file extension is blocked.');
        }
        if (!in_array($mime_type, (array) $settings['allowed_mime_types'], true)) {
            return new WP_Error('scfs_attachment_mime_blocked', 'This MIME type is not allowed.');
        }
        if ($size > absint($settings['maximum_file_size_bytes'])) {
            return new WP_Error('scfs_attachment_too_large', 'The attachment exceeds the configured size limit.');
        }
        if ($settings['require_sha256'] === '1' && !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return new WP_Error('scfs_attachment_sha256_required', 'A valid SHA-256 checksum is required.');
        }
        $scan_state = sanitize_key($payload['scan_state'] ?? 'pending');
        if ($settings['require_malware_scan'] === '1' && !in_array($scan_state, array('pending', 'clean', 'quarantined', 'failed'), true)) {
            return new WP_Error('scfs_invalid_scan_state', 'Invalid malware scan state.');
        }
        return true;
    }

    public function register_attachment($case_id, $payload, $actor_user_id = 0) {
        global $wpdb;
        $case = $this->case_exists($case_id);
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $payload = is_array($payload) ? $payload : array();
        $valid = $this->validate_attachment_payload($payload);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $settings = $this->settings();
        $classification = sanitize_key($payload['classification'] ?? $settings['default_classification']);
        if (!in_array($classification, $this->allowed_classifications(), true)) {
            return new WP_Error('scfs_invalid_evidence_classification', 'Invalid evidence classification.');
        }
        $consent_state = sanitize_key($payload['consent_state'] ?? $case['consent_state']);
        if (!in_array($consent_state, array('accepted', 'not_required'), true)) {
            return new WP_Error('scfs_evidence_consent_required', 'Evidence consent must be accepted or not required before registration.');
        }
        $foundation = $this->foundation();
        $attachment_id = $foundation->register_attachment_metadata($case_id, array(
            'message_id' => absint($payload['message_id'] ?? 0),
            'authority' => 'contact-engagement',
            'external_attachment_ref' => sanitize_text_field($payload['external_attachment_ref'] ?? ''),
            'filename' => sanitize_file_name($payload['filename']),
            'mime_type' => sanitize_mime_type($payload['mime_type']),
            'size_bytes' => absint($payload['size_bytes']),
            'sha256' => strtolower((string) $payload['sha256']),
            'classification' => $classification,
            'retention_until' => sanitize_text_field($payload['retention_until'] ?? gmdate('Y-m-d H:i:s', time() + (absint($settings['default_retention_days']) * DAY_IN_SECONDS))),
            'redaction_state' => sanitize_key($payload['redaction_state'] ?? 'not_reviewed'),
            'uploaded_by_ref' => sanitize_text_field($payload['uploaded_by_ref'] ?? ''),
            'metadata' => array(
                'scan_state' => sanitize_key($payload['scan_state'] ?? 'pending'),
                'consent_state' => $consent_state,
                'intake_id' => absint($payload['intake_id'] ?? 0),
                'bytes_stored_by_scfs' => false,
            ),
        ), $actor_user_id);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        $this->add_evidence_event($case_id, 'attachment_registered', array(
            'authority' => 'contact-engagement',
            'filename' => sanitize_file_name($payload['filename']),
            'scan_state' => sanitize_key($payload['scan_state'] ?? 'pending'),
            'classification' => $classification,
        ), $attachment_id, absint($payload['intake_id'] ?? 0), $actor_user_id);
        if (!empty($payload['intake_id'])) {
            $tables = $this->table_names();
            $wpdb->update($tables['intakes'], array('status' => 'submitted', 'submitted_at' => current_time('mysql', true)), array('id' => absint($payload['intake_id']), 'case_id' => absint($case_id)), array('%s','%s'), array('%d','%d'));
        }
        return $this->get_attachment($attachment_id);
    }

    public function get_attachment($attachment_id) {
        global $wpdb;
        $foundation = $this->foundation();
        if (!$foundation) {
            return null;
        }
        $tables = $foundation->table_names();
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['attachments']} WHERE id=%d", absint($attachment_id)), ARRAY_A);
        if (!$record) {
            return null;
        }
        $record['metadata'] = json_decode($record['metadata_json'], true) ?: array();
        unset($record['metadata_json']);
        return $record;
    }

    public function register_diagnostic_bundle($case_id, $payload, $actor_user_id = 0) {
        global $wpdb;
        $case = $this->case_exists($case_id);
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $payload = is_array($payload) ? $payload : array();
        $environment = isset($payload['environment']) && is_array($payload['environment']) ? $payload['environment'] : array();
        $manifest = isset($payload['manifest']) && is_array($payload['manifest']) ? $payload['manifest'] : array();
        $forbidden = array('password', 'secret', 'token', 'api_key', 'authorization', 'cookie');
        $serialized = strtolower(wp_json_encode(array_keys($environment)) . wp_json_encode(array_keys($manifest)));
        foreach ($forbidden as $term) {
            if (strpos($serialized, $term) !== false) {
                return new WP_Error('scfs_diagnostic_secret_field_blocked', 'Diagnostic manifests may not contain credential or secret fields.');
            }
        }
        if (empty($manifest['files']) || !is_array($manifest['files'])) {
            return new WP_Error('scfs_diagnostic_manifest_required', 'A diagnostic file manifest is required.');
        }
        $canonical = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $bundle_key = 'SC-DIAG-' . gmdate('Ymd') . '-' . strtoupper(wp_generate_password(10, false, false));
        $tables = $this->table_names();
        $wpdb->insert($tables['diagnostics'], array(
            'case_id' => absint($case_id),
            'intake_id' => absint($payload['intake_id'] ?? 0),
            'bundle_key' => $bundle_key,
            'product' => sanitize_key($payload['product'] ?? $case['product']),
            'product_version' => sanitize_text_field($payload['product_version'] ?? $case['product_version']),
            'environment_json' => wp_json_encode($environment),
            'manifest_json' => $canonical,
            'manifest_sha256' => hash('sha256', $canonical),
            'validation_state' => 'pending_review',
            'redaction_state' => sanitize_key($payload['redaction_state'] ?? 'not_reviewed'),
            'scan_state' => sanitize_key($payload['scan_state'] ?? 'pending'),
            'created_by' => absint($actor_user_id),
            'created_at' => current_time('mysql', true),
            'metadata_json' => wp_json_encode(array('raw_file_bytes_stored' => false, 'human_review_required' => true)),
        ), array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s'));
        $id = (int) $wpdb->insert_id;
        $this->add_evidence_event($case_id, 'diagnostic_bundle_registered', array('bundle_key' => $bundle_key, 'manifest_sha256' => hash('sha256', $canonical)), 0, absint($payload['intake_id'] ?? 0), $actor_user_id);
        return array('id' => $id, 'bundle_key' => $bundle_key, 'manifest_sha256' => hash('sha256', $canonical), 'validation_state' => 'pending_review');
    }

    public function issue_access_grant($attachment_id, $payload, $actor_user_id = 0) {
        global $wpdb;
        $attachment = $this->get_attachment($attachment_id);
        if (!$attachment) {
            return new WP_Error('scfs_attachment_not_found', 'Attachment not found.');
        }
        if ($attachment['deleted_at'] || $attachment['redaction_state'] === 'quarantined') {
            return new WP_Error('scfs_attachment_unavailable', 'Attachment is not available for access.');
        }
        $payload = is_array($payload) ? $payload : array();
        $hours = min(72, max(1, absint($payload['expires_in_hours'] ?? 4)));
        $max_uses = min(25, max(1, absint($payload['max_uses'] ?? 1)));
        $secret = wp_generate_password(48, false, false);
        $tables = $this->table_names();
        $wpdb->insert($tables['access'], array(
            'case_id' => absint($attachment['case_id']),
            'attachment_id' => absint($attachment_id),
            'grant_hash' => hash('sha256', $secret),
            'audience' => sanitize_key($payload['audience'] ?? 'agent'),
            'granted_to_ref' => sanitize_text_field($payload['granted_to_ref'] ?? ''),
            'purpose' => sanitize_key($payload['purpose'] ?? 'case_review'),
            'max_uses' => $max_uses,
            'use_count' => 0,
            'created_by' => absint($actor_user_id),
            'created_at' => current_time('mysql', true),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($hours * HOUR_IN_SECONDS)),
            'metadata_json' => wp_json_encode(array('raw_download_url_stored' => false, 'authority' => 'contact-engagement')),
        ), array('%d','%d','%s','%s','%s','%s','%d','%d','%d','%s','%s','%s'));
        $grant_id = (int) $wpdb->insert_id;
        $this->add_evidence_event($attachment['case_id'], 'access_grant_issued', array('grant_id' => $grant_id, 'audience' => sanitize_key($payload['audience'] ?? 'agent'), 'expires_in_hours' => $hours), $attachment_id, 0, $actor_user_id);
        return array(
            'grant_id' => $grant_id,
            'grant_secret' => $secret,
            'shown_once' => true,
            'expires_at' => gmdate('c', time() + ($hours * HOUR_IN_SECONDS)),
            'download_resolution_authority' => 'contact-engagement',
        );
    }

    public function consume_access_grant($grant_id, $secret, $actor_user_id = 0) {
        global $wpdb;
        $tables = $this->table_names();
        $grant = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['access']} WHERE id=%d", absint($grant_id)), ARRAY_A);
        if (!$grant || !hash_equals((string) $grant['grant_hash'], hash('sha256', (string) $secret))) {
            return new WP_Error('scfs_invalid_access_grant', 'Invalid attachment access grant.');
        }
        if ($grant['revoked_at'] || strtotime((string) $grant['expires_at'] . ' UTC') <= time() || (int) $grant['use_count'] >= (int) $grant['max_uses']) {
            return new WP_Error('scfs_access_grant_expired', 'Attachment access grant is expired, revoked, or exhausted.');
        }
        $attachment = $this->get_attachment($grant['attachment_id']);
        if (!$attachment || $attachment['deleted_at'] || $attachment['redaction_state'] === 'quarantined') {
            return new WP_Error('scfs_attachment_unavailable', 'Attachment is unavailable.');
        }
        $metadata = is_array($attachment['metadata']) ? $attachment['metadata'] : array();
        if (($metadata['scan_state'] ?? 'pending') !== 'clean') {
            return new WP_Error('scfs_attachment_scan_incomplete', 'A clean malware scan is required before download.');
        }
        if (!in_array($attachment['redaction_state'], array('redacted', 'approved_unredacted'), true)) {
            return new WP_Error('scfs_attachment_redaction_review_incomplete', 'Redaction review must be complete before download.');
        }
        $resolution = apply_filters('scfs_help_desk_evidence_resolve_download', null, $attachment, $grant);
        if (!is_array($resolution) || empty($resolution['url'])) {
            return new WP_Error('scfs_attachment_authority_unavailable', 'Contact and Engagement did not provide a secure download resolution.');
        }
        $wpdb->query($wpdb->prepare("UPDATE {$tables['access']} SET use_count=use_count+1,last_used_at=%s WHERE id=%d", current_time('mysql', true), absint($grant_id)));
        $this->add_evidence_event($grant['case_id'], 'access_grant_consumed', array('grant_id' => absint($grant_id), 'audience' => $grant['audience']), $grant['attachment_id'], 0, $actor_user_id);
        return array(
            'url' => esc_url_raw($resolution['url']),
            'expires_at' => sanitize_text_field($resolution['expires_at'] ?? ''),
            'authority' => 'contact-engagement',
            'stored' => false,
        );
    }

    public function update_redaction($attachment_id, $state, $reason, $actor_user_id = 0) {
        global $wpdb;
        $attachment = $this->get_attachment($attachment_id);
        $state = sanitize_key($state);
        if (!$attachment) {
            return new WP_Error('scfs_attachment_not_found', 'Attachment not found.');
        }
        if (!in_array($state, $this->allowed_redaction_states(), true)) {
            return new WP_Error('scfs_invalid_redaction_state', 'Invalid redaction state.');
        }
        if (trim((string) $reason) === '') {
            return new WP_Error('scfs_redaction_reason_required', 'A redaction reason is required.');
        }
        $foundation = $this->foundation();
        $tables = $foundation->table_names();
        $wpdb->update($tables['attachments'], array('redaction_state' => $state), array('id' => absint($attachment_id)), array('%s'), array('%d'));
        $this->add_evidence_event($attachment['case_id'], 'redaction_state_changed', array('from' => $attachment['redaction_state'], 'to' => $state, 'reason' => sanitize_textarea_field($reason)), $attachment_id, 0, $actor_user_id);
        do_action('scfs_help_desk_evidence_redaction_changed', $attachment_id, $state, sanitize_textarea_field($reason));
        return $this->get_attachment($attachment_id);
    }

    public function schedule_retention_action($attachment_id, $payload, $actor_user_id = 0) {
        global $wpdb;
        $attachment = $this->get_attachment($attachment_id);
        if (!$attachment) {
            return new WP_Error('scfs_attachment_not_found', 'Attachment not found.');
        }
        $payload = is_array($payload) ? $payload : array();
        $action_type = sanitize_key($payload['action_type'] ?? 'review');
        if (!in_array($action_type, array('review', 'delete', 'extend', 'legal_hold'), true)) {
            return new WP_Error('scfs_invalid_retention_action', 'Invalid retention action.');
        }
        $reason = sanitize_textarea_field($payload['reason'] ?? '');
        if ($reason === '') {
            return new WP_Error('scfs_retention_reason_required', 'A retention reason is required.');
        }
        $scheduled = sanitize_text_field($payload['scheduled_for'] ?? $attachment['retention_until'] ?? gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS));
        $tables = $this->table_names();
        $wpdb->insert($tables['retention'], array(
            'case_id' => absint($attachment['case_id']),
            'attachment_id' => absint($attachment_id),
            'action_type' => $action_type,
            'state' => 'scheduled',
            'scheduled_for' => $scheduled,
            'reason' => $reason,
            'requested_by' => absint($actor_user_id),
            'requested_at' => current_time('mysql', true),
            'metadata_json' => wp_json_encode(array('automatic_execution' => false, 'authority' => 'contact-engagement')),
        ), array('%d','%d','%s','%s','%s','%s','%d','%s','%s'));
        $id = (int) $wpdb->insert_id;
        $this->add_evidence_event($attachment['case_id'], 'retention_action_scheduled', array('retention_action_id' => $id, 'action_type' => $action_type, 'scheduled_for' => $scheduled), $attachment_id, 0, $actor_user_id);
        return array('id' => $id, 'state' => 'scheduled', 'automatic_execution' => false);
    }

    public function retention_scan() {
        global $wpdb;
        $tables = $this->table_names();
        $due = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['retention']} WHERE state='scheduled' AND scheduled_for<=%s ORDER BY scheduled_for ASC LIMIT 200", current_time('mysql', true)), ARRAY_A);
        foreach ($due as $action) {
            $wpdb->update($tables['retention'], array('state' => 'review_required'), array('id' => absint($action['id'])), array('%s'), array('%d'));
            $this->add_evidence_event($action['case_id'], 'retention_review_due', array('retention_action_id' => absint($action['id']), 'action_type' => $action['action_type']), $action['attachment_id']);
            do_action('scfs_help_desk_evidence_retention_review_due', $action);
        }
        return count($due);
    }

    public function case_evidence($case_id) {
        global $wpdb;
        $foundation = $this->foundation();
        if (!$foundation) {
            return array();
        }
        $tables = $this->table_names();
        $case_tables = $foundation->table_names();
        return array(
            'intakes' => (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['intakes']} WHERE case_id=%d ORDER BY requested_at DESC,id DESC", absint($case_id)), ARRAY_A),
            'attachments' => (array) $wpdb->get_results($wpdb->prepare("SELECT id,case_id,message_id,authority,external_attachment_ref,filename,mime_type,size_bytes,sha256,classification,retention_until,redaction_state,created_at,deleted_at,metadata_json FROM {$case_tables['attachments']} WHERE case_id=%d ORDER BY created_at DESC,id DESC", absint($case_id)), ARRAY_A),
            'diagnostics' => (array) $wpdb->get_results($wpdb->prepare("SELECT id,case_id,intake_id,bundle_key,product,product_version,manifest_sha256,validation_state,redaction_state,scan_state,created_at,reviewed_at FROM {$tables['diagnostics']} WHERE case_id=%d ORDER BY created_at DESC,id DESC", absint($case_id)), ARRAY_A),
            'events' => (array) $wpdb->get_results($wpdb->prepare("SELECT id,attachment_id,intake_id,event_type,actor_user_id,actor_ref,integrity_hash,created_at FROM {$tables['events']} WHERE case_id=%d ORDER BY created_at DESC,id DESC LIMIT 100", absint($case_id)), ARRAY_A),
        );
    }

    public function extend_customer_payload($payload, $session) {
        if (empty($payload['case']['id'])) {
            return $payload;
        }
        global $wpdb;
        $tables = $this->table_names();
        $case_id = absint($payload['case']['id']);
        $intakes = (array) $wpdb->get_results($wpdb->prepare("SELECT intake_key,purpose,instructions,classification,consent_state,status,requested_at,expires_at,submitted_at FROM {$tables['intakes']} WHERE case_id=%d AND status IN ('requested','authorized','submitted') AND expires_at>%s ORDER BY requested_at DESC", $case_id, current_time('mysql', true)), ARRAY_A);
        $payload['evidence_intakes'] = $intakes;
        $payload['evidence_privacy'] = array(
            'attachment_authority' => 'contact-engagement',
            'raw_file_bytes_exposed' => false,
            'internal_evidence_events_exposed' => false,
            'download_grants_exposed' => false,
        );
        return $payload;
    }

    public function register_admin_page() {
        add_submenu_page('edit.php?post_type=sc_feature_suggest', __('Secure Evidence', 'sustainable-catalyst-feature-suggestions'), __('Secure Evidence', 'sustainable-catalyst-feature-suggestions'), 'scfs_view_help_desk_evidence', self::ADMIN_PAGE, array($this, 'render_admin_page'));
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false && strpos((string) $hook, 'scfs-help-desk-agent-workspace') === false) {
            return;
        }
        wp_enqueue_style('scfs-help-desk-secure-evidence', plugins_url('../assets/help-desk-secure-evidence.css', __FILE__), array(), self::VERSION);
        wp_enqueue_script('scfs-help-desk-secure-evidence', plugins_url('../assets/help-desk-secure-evidence.js', __FILE__), array(), self::VERSION, true);
    }

    public function render_case_panel($case) {
        if (!$this->can_view()) {
            return;
        }
        $evidence = $this->case_evidence($case['id']);
        echo '<section class="scfs-secure-evidence scfs-agent-workspace__panel" data-scfs-secure-evidence><h3>' . esc_html__('Secure evidence', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        echo '<p>' . esc_html__('File bytes remain with Contact and Engagement. This workspace stores governed evidence metadata, diagnostic manifests, access decisions, redaction state, and retention evidence.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<dl class="scfs-secure-evidence__metrics"><div><dt>' . esc_html__('Intakes', 'sustainable-catalyst-feature-suggestions') . '</dt><dd>' . esc_html(count($evidence['intakes'])) . '</dd></div><div><dt>' . esc_html__('Attachments', 'sustainable-catalyst-feature-suggestions') . '</dt><dd>' . esc_html(count($evidence['attachments'])) . '</dd></div><div><dt>' . esc_html__('Diagnostic bundles', 'sustainable-catalyst-feature-suggestions') . '</dt><dd>' . esc_html(count($evidence['diagnostics'])) . '</dd></div></dl>';
        if ($this->can_manage()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-secure-evidence__request">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="scfs_help_desk_evidence_case"><input type="hidden" name="operation" value="request_intake"><input type="hidden" name="case_id" value="' . esc_attr($case['id']) . '"><label><span>' . esc_html__('Evidence purpose', 'sustainable-catalyst-feature-suggestions') . '</span><select name="purpose"><option value="troubleshooting">' . esc_html__('Troubleshooting', 'sustainable-catalyst-feature-suggestions') . '</option><option value="diagnostic_bundle">' . esc_html__('Diagnostic bundle', 'sustainable-catalyst-feature-suggestions') . '</option><option value="configuration_review">' . esc_html__('Configuration review', 'sustainable-catalyst-feature-suggestions') . '</option><option value="security_review">' . esc_html__('Security or privacy review', 'sustainable-catalyst-feature-suggestions') . '</option></select></label><label><span>' . esc_html__('Classification', 'sustainable-catalyst-feature-suggestions') . '</span><select name="classification"><option value="private_support">Private support</option><option value="confidential">Confidential</option><option value="security_sensitive">Security sensitive</option><option value="synthetic_sample">Synthetic sample</option></select></label><label><span>' . esc_html__('Instructions', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="instructions" rows="3"></textarea></label><button class="button">' . esc_html__('Request secure evidence', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
        }
        foreach ($evidence['attachments'] as $attachment) {
            echo '<article class="scfs-secure-evidence__attachment"><strong>' . esc_html($attachment['filename']) . '</strong><span>' . esc_html(size_format((int) $attachment['size_bytes'])) . ' · ' . esc_html($attachment['classification']) . ' · ' . esc_html($attachment['redaction_state']) . '</span></article>';
        }
        echo '</section>';
    }

    public function render_admin_page() {
        if (!$this->can_view()) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        global $wpdb;
        $tables = $this->table_names();
        $intake_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['intakes']}");
        $diagnostic_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['diagnostics']}");
        $review_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['retention']} WHERE state='review_required'");
        $quarantine_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['diagnostics']} WHERE scan_state='quarantined' OR redaction_state='quarantined'");
        echo '<div class="wrap scfs-secure-evidence-admin"><h1>' . esc_html__('Secure Evidence, Attachments, and Diagnostic Intake', 'sustainable-catalyst-feature-suggestions') . '</h1><p>' . esc_html__('Govern private evidence metadata and delegated file operations without placing customer files in the WordPress Media Library.', 'sustainable-catalyst-feature-suggestions') . '</p><div class="scfs-secure-evidence-admin__metrics"><article><strong>' . esc_html($intake_count) . '</strong><span>' . esc_html__('Evidence intakes', 'sustainable-catalyst-feature-suggestions') . '</span></article><article><strong>' . esc_html($diagnostic_count) . '</strong><span>' . esc_html__('Diagnostic bundles', 'sustainable-catalyst-feature-suggestions') . '</span></article><article><strong>' . esc_html($review_count) . '</strong><span>' . esc_html__('Retention reviews due', 'sustainable-catalyst-feature-suggestions') . '</span></article><article><strong>' . esc_html($quarantine_count) . '</strong><span>' . esc_html__('Quarantined items', 'sustainable-catalyst-feature-suggestions') . '</span></article></div><section class="scfs-secure-evidence-admin__panel"><h2>' . esc_html__('Governance boundary', 'sustainable-catalyst-feature-suggestions') . '</h2><ul><li>' . esc_html__('File bytes and delivery URLs remain with Contact and Engagement.', 'sustainable-catalyst-feature-suggestions') . '</li><li>' . esc_html__('No attachment is added to the WordPress Media Library.', 'sustainable-catalyst-feature-suggestions') . '</li><li>' . esc_html__('Download grants store SHA-256 hashes, never raw secrets or raw URLs.', 'sustainable-catalyst-feature-suggestions') . '</li><li>' . esc_html__('Deletion, redaction, and legal-hold actions require human review.', 'sustainable-catalyst-feature-suggestions') . '</li></ul></section></div>';
    }

    private function verify_admin_action() {
        if (!$this->can_manage()) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
    }

    public function admin_case_action() {
        $this->verify_admin_action();
        $case_id = absint($_POST['case_id'] ?? 0);
        $operation = sanitize_key($_POST['operation'] ?? '');
        $result = null;
        if ($operation === 'request_intake') {
            $result = $this->create_intake($case_id, array(
                'purpose' => sanitize_key($_POST['purpose'] ?? 'troubleshooting'),
                'classification' => sanitize_key($_POST['classification'] ?? 'private_support'),
                'instructions' => sanitize_textarea_field($_POST['instructions'] ?? ''),
                'consent_state' => 'required',
            ), get_current_user_id());
        }
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        wp_safe_redirect(add_query_arg(array('post_type' => 'sc_feature_suggest', 'page' => 'scfs-help-desk-agent-workspace', 'case_id' => $case_id), admin_url('edit.php')));
        exit;
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/help-desk/evidence/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/evidence/case/(?P<id>\d+)', array('methods' => 'GET', 'callback' => array($this, 'rest_case'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/evidence/intakes', array('methods' => 'POST', 'callback' => array($this, 'rest_create_intake'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/evidence/attachments', array('methods' => 'POST', 'callback' => array($this, 'rest_register_attachment'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/evidence/attachments/(?P<id>\d+)/access', array('methods' => 'POST', 'callback' => array($this, 'rest_issue_access'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/evidence/access/resolve', array('methods' => 'POST', 'callback' => array($this, 'rest_resolve_access'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/evidence/attachments/(?P<id>\d+)/redaction', array('methods' => 'POST', 'callback' => array($this, 'rest_redaction'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/evidence/attachments/(?P<id>\d+)/retention', array('methods' => 'POST', 'callback' => array($this, 'rest_retention'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/evidence/diagnostics', array('methods' => 'POST', 'callback' => array($this, 'rest_diagnostic'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/evidence/health', array('methods' => 'GET', 'callback' => array($this, 'rest_health'), 'permission_callback' => array($this, 'can_view')));
    }

    public function rest_schema() {
        return rest_ensure_response(array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'db_version' => self::DB_VERSION,
            'attachment_authority' => 'contact-engagement',
            'media_library_storage' => false,
            'raw_download_urls_stored' => false,
            'automatic_deletion' => false,
            'human_review_required' => true,
        ));
    }

    public function rest_case(WP_REST_Request $request) {
        return rest_ensure_response($this->case_evidence(absint($request['id'])));
    }

    public function rest_create_intake(WP_REST_Request $request) {
        $payload = (array) $request->get_json_params();
        $result = $this->create_intake(absint($payload['case_id'] ?? 0), $payload, get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_register_attachment(WP_REST_Request $request) {
        $payload = (array) $request->get_json_params();
        $result = $this->register_attachment(absint($payload['case_id'] ?? 0), $payload, get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_issue_access(WP_REST_Request $request) {
        $result = $this->issue_access_grant(absint($request['id']), (array) $request->get_json_params(), get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_resolve_access(WP_REST_Request $request) {
        $payload = (array) $request->get_json_params();
        if (!(current_user_can('scfs_download_help_desk_evidence') || current_user_can('manage_options'))) {
            return new WP_Error('scfs_evidence_download_permission_required', 'Download permission required.', array('status' => 403));
        }
        $result = $this->consume_access_grant(absint($payload['grant_id'] ?? 0), (string) ($payload['grant_secret'] ?? ''), get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_redaction(WP_REST_Request $request) {
        $payload = (array) $request->get_json_params();
        $result = $this->update_redaction(absint($request['id']), $payload['state'] ?? '', $payload['reason'] ?? '', get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_retention(WP_REST_Request $request) {
        $result = $this->schedule_retention_action(absint($request['id']), (array) $request->get_json_params(), get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_diagnostic(WP_REST_Request $request) {
        $payload = (array) $request->get_json_params();
        $result = $this->register_diagnostic_bundle(absint($payload['case_id'] ?? 0), $payload, get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_health() {
        global $wpdb;
        $tables = $this->table_names();
        $health = array();
        foreach ($tables as $key => $table) {
            $health[$key] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }
        return rest_ensure_response(array('ok' => !in_array(false, $health, true), 'version' => self::VERSION, 'schema' => self::SCHEMA, 'tables' => $health));
    }

    public function cli_status() {
        $response = $this->rest_health();
        WP_CLI::line(wp_json_encode($response->get_data(), JSON_PRETTY_PRINT));
    }

    public function cli_case($args) {
        $case_id = absint($args[0] ?? 0);
        WP_CLI::line(wp_json_encode($this->case_evidence($case_id), JSON_PRETTY_PRINT));
    }

    public function cli_request($args, $assoc_args) {
        $case_id = absint($args[0] ?? 0);
        $result = $this->create_intake($case_id, array(
            'purpose' => sanitize_key($assoc_args['purpose'] ?? 'troubleshooting'),
            'classification' => sanitize_key($assoc_args['classification'] ?? 'private_support'),
            'instructions' => sanitize_textarea_field($assoc_args['instructions'] ?? ''),
            'consent_state' => sanitize_key($assoc_args['consent'] ?? 'required'),
        ), get_current_user_id());
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success(wp_json_encode($result));
    }

    public function cli_retention_scan() {
        WP_CLI::success(sprintf('%d retention actions moved to review_required.', $this->retention_scan()));
    }
}
