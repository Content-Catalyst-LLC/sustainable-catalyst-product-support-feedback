<?php
/**
 * Help Desk Email and Channel Operations.
 *
 * Registers authenticated email-channel metadata, matches messages to cases,
 * prepares customer-safe outbound drafts, records delivery events, and creates
 * governed Microsoft Teams handoffs. Contact and Engagement remains the
 * authority for identity, email transport, attachment bytes, and scheduling.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Email_Channel_Operations {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-email-channels/1.0';
    const DB_VERSION = '1.7.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_email_channels_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_email_channels_settings';
    const ADMIN_PAGE = 'scfs-help-desk-email-channels';
    const NONCE_ACTION = 'scfs_help_desk_email_channel_action';
    const CRON_HOOK = 'scfs_help_desk_email_channel_maintenance';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 8);
        add_action('admin_menu', array($this, 'register_admin_page'), 55);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'scheduled_maintenance'));
        add_action('scfs_help_desk_case_workspace_after', array($this, 'render_case_panel'), 60);
        add_action('admin_post_scfs_help_desk_email_channel_action', array($this, 'admin_action'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk channels status', array($this, 'cli_status'));
            WP_CLI::add_command('scfs help-desk channels match-case', array($this, 'cli_match_case'));
            WP_CLI::add_command('scfs help-desk channels prepare-reply', array($this, 'cli_prepare_reply'));
            WP_CLI::add_command('scfs help-desk channels delivery-event', array($this, 'cli_delivery_event'));
        }
    }

    public static function activate() {
        $instance = self::instance();
        $instance->install_schema();
        $instance->install_capabilities();
        $instance->seed_default_channel();
        if (!get_option(self::SETTINGS_OPTION)) {
            add_option(self::SETTINGS_OPTION, $instance->default_settings(), '', false);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
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
            'inbound_email_enabled' => '1',
            'outbound_email_enabled' => '1',
            'automatic_case_creation' => '0',
            'automatic_customer_send' => '0',
            'automatic_case_closure' => '0',
            'public_inbound_webhook' => '0',
            'require_case_number_for_direct_append' => '1',
            'require_agent_approval_for_outbound' => '1',
            'require_attachment_clearance' => '1',
            'supported_live_channel' => 'microsoft_teams',
            'transport_authority' => 'contact-engagement',
            'identity_authority' => 'contact-engagement',
            'attachment_authority' => 'contact-engagement',
            'scheduling_authority' => 'contact-engagement',
            'retention_days' => 365,
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'channels' => $wpdb->prefix . 'scfs_help_desk_email_channels',
            'threads' => $wpdb->prefix . 'scfs_help_desk_email_threads',
            'messages' => $wpdb->prefix . 'scfs_help_desk_email_messages',
            'delivery_events' => $wpdb->prefix . 'scfs_help_desk_email_delivery_events',
            'authorizations' => $wpdb->prefix . 'scfs_help_desk_channel_authorizations',
            'handoffs' => $wpdb->prefix . 'scfs_help_desk_channel_handoffs',
        );
    }

    public function install_schema() {
        global $wpdb;
        $tables = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = array();
        $sql[] = "CREATE TABLE {$tables['channels']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            channel_key varchar(120) NOT NULL,
            channel_type varchar(40) NOT NULL DEFAULT 'email',
            display_name varchar(191) NOT NULL,
            inbound_address_ref varchar(255) NOT NULL DEFAULT '',
            transport_authority varchar(120) NOT NULL DEFAULT 'contact-engagement',
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            configuration_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY channel_key (channel_key),
            KEY type_active (channel_type,active)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['threads']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            channel_id bigint(20) unsigned NOT NULL,
            case_id bigint(20) unsigned NOT NULL,
            provider_thread_ref varchar(255) NOT NULL DEFAULT '',
            normalized_subject varchar(255) NOT NULL DEFAULT '',
            state varchar(40) NOT NULL DEFAULT 'active',
            last_message_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY channel_provider_thread (channel_id,provider_thread_ref),
            KEY case_state (case_id,state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['messages']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            channel_id bigint(20) unsigned NOT NULL,
            thread_id bigint(20) unsigned NOT NULL DEFAULT 0,
            case_id bigint(20) unsigned NOT NULL DEFAULT 0,
            direction varchar(20) NOT NULL,
            provider_message_ref varchar(255) NOT NULL,
            in_reply_to_ref varchar(255) NOT NULL DEFAULT '',
            sender_ref varchar(255) NOT NULL DEFAULT '',
            recipient_ref varchar(255) NOT NULL DEFAULT '',
            subject varchar(998) NOT NULL DEFAULT '',
            content_sha256 char(64) NOT NULL,
            content_length bigint(20) unsigned NOT NULL DEFAULT 0,
            message_state varchar(40) NOT NULL DEFAULT 'received',
            case_match_method varchar(40) NOT NULL DEFAULT 'none',
            attachment_count int(11) unsigned NOT NULL DEFAULT 0,
            attachment_refs_json longtext NOT NULL,
            transport_handoff_ref varchar(255) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY channel_message (channel_id,provider_message_ref),
            KEY case_direction (case_id,direction),
            KEY state_created (message_state,created_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['delivery_events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            message_id bigint(20) unsigned NOT NULL,
            case_id bigint(20) unsigned NOT NULL DEFAULT 0,
            provider_event_ref varchar(255) NOT NULL,
            event_type varchar(40) NOT NULL,
            diagnostic_code varchar(120) NOT NULL DEFAULT '',
            event_data_json longtext NOT NULL,
            event_at datetime NOT NULL,
            received_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_event_ref (provider_event_ref),
            KEY message_event (message_id,event_type),
            KEY case_event (case_id,event_type)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['authorizations']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            authorization_ref varchar(255) NOT NULL,
            channel_id bigint(20) unsigned NOT NULL DEFAULT 0,
            secret_sha256 char(64) NOT NULL DEFAULT '',
            scopes_json longtext NOT NULL,
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            expires_at datetime NULL,
            last_used_at datetime NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            revoked_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY authorization_ref (authorization_ref),
            KEY active_expires (active,expires_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['handoffs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            handoff_type varchar(40) NOT NULL,
            provider varchar(80) NOT NULL,
            purpose text NOT NULL,
            attendee_refs_json longtext NOT NULL,
            consent_state varchar(40) NOT NULL DEFAULT 'not_recorded',
            state varchar(40) NOT NULL DEFAULT 'pending',
            external_handoff_ref varchar(255) NOT NULL DEFAULT '',
            requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY case_state (case_id,state),
            KEY provider_state (provider,state)
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
            $this->seed_default_channel();
        }
    }

    public function install_capabilities() {
        $caps = array(
            'scfs_view_help_desk_channels',
            'scfs_manage_help_desk_channels',
            'scfs_process_inbound_email',
            'scfs_prepare_outbound_email',
            'scfs_record_email_delivery',
            'scfs_manage_channel_authorizations',
            'scfs_request_teams_handoffs',
        );
        foreach (array('administrator', 'scfs_help_desk_agent') as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    public function can_view() {
        return current_user_can('scfs_view_help_desk_channels') || current_user_can('scfs_view_help_desk') || current_user_can('manage_options');
    }

    public function can_manage() {
        return current_user_can('scfs_manage_help_desk_channels') || current_user_can('manage_options');
    }

    public function can_process_inbound() {
        return current_user_can('scfs_process_inbound_email') || $this->can_manage();
    }

    public function can_prepare_outbound() {
        return current_user_can('scfs_prepare_outbound_email') || current_user_can('scfs_reply_help_desk') || $this->can_manage();
    }

    public function can_record_delivery() {
        return current_user_can('scfs_record_email_delivery') || $this->can_manage();
    }

    public function can_request_teams() {
        return current_user_can('scfs_request_teams_handoffs') || $this->can_manage();
    }

    public function issue_authorization($payload, $actor_user_id = 0) {
        global $wpdb;
        $tables = $this->table_names();
        $channel_id = absint($payload['channel_id'] ?? 0);
        $allowed_scopes = array('inbound_register', 'outbound_prepare', 'delivery_record', 'teams_handoff');
        $scopes = array_values(array_unique(array_intersect($allowed_scopes, array_map('sanitize_key', (array) ($payload['scopes'] ?? array())))));
        $expires_hours = min(8760, max(1, absint($payload['expires_hours'] ?? 168)));
        if (!$channel_id || !$scopes) {
            return new WP_Error('scfs_invalid_channel_authorization', 'An active channel and at least one supported scope are required.');
        }
        $channel_active = $wpdb->get_var($wpdb->prepare("SELECT active FROM {$tables['channels']} WHERE id=%d", $channel_id));
        if (!$channel_active) {
            return new WP_Error('scfs_channel_not_found', 'The requested channel is not active.');
        }
        try {
            $secret = bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            return new WP_Error('scfs_authorization_secret_failed', 'A secure authorization secret could not be generated.');
        }
        $authorization_ref = 'channel-auth:' . wp_generate_uuid4();
        $now = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + ($expires_hours * HOUR_IN_SECONDS));
        $inserted = $wpdb->insert($tables['authorizations'], array(
            'authorization_ref' => $authorization_ref,
            'channel_id' => $channel_id,
            'secret_sha256' => hash('sha256', $secret),
            'scopes_json' => wp_json_encode($scopes),
            'active' => 1,
            'expires_at' => $expires_at,
            'last_used_at' => null,
            'created_by' => absint($actor_user_id),
            'created_at' => $now,
            'revoked_at' => null,
        ));
        if (!$inserted) {
            return new WP_Error('scfs_channel_authorization_store_failed', 'The channel authorization could not be stored.');
        }
        return array(
            'ok' => true,
            'version' => self::VERSION,
            'authorization_ref' => $authorization_ref,
            'secret' => $secret,
            'secret_display' => 'one_time_only',
            'raw_secret_stored' => false,
            'scopes' => $scopes,
            'expires_at' => $expires_at,
        );
    }

    public function verify_authorization($authorization_ref, $secret, $required_scope) {
        global $wpdb;
        $tables = $this->table_names();
        $authorization_ref = sanitize_text_field($authorization_ref);
        $required_scope = sanitize_key($required_scope);
        if ($authorization_ref === '' || $secret === '' || $required_scope === '') {
            return array('allowed' => false, 'reasons' => array('authorization_evidence_required'));
        }
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['authorizations']} WHERE authorization_ref=%s", $authorization_ref), ARRAY_A);
        if (!$record) {
            return array('allowed' => false, 'reasons' => array('authorization_not_found'));
        }
        $reasons = array();
        if (!absint($record['active'])) {
            $reasons[] = 'authorization_inactive';
        }
        if (!empty($record['expires_at']) && strtotime($record['expires_at'] . ' UTC') <= time()) {
            $reasons[] = 'authorization_expired';
        }
        $scopes = json_decode((string) $record['scopes_json'], true);
        if (!is_array($scopes) || !in_array($required_scope, $scopes, true)) {
            $reasons[] = 'required_scope_missing';
        }
        if (!hash_equals((string) $record['secret_sha256'], hash('sha256', (string) $secret))) {
            $reasons[] = 'authorization_secret_invalid';
        }
        if (!$reasons) {
            $wpdb->update($tables['authorizations'], array('last_used_at' => current_time('mysql', true)), array('id' => absint($record['id'])));
        }
        return array('allowed' => !$reasons, 'reasons' => $reasons, 'authorization_ref' => $authorization_ref, 'required_scope' => $required_scope, 'raw_secret_stored' => false);
    }

    public function revoke_authorization($authorization_id) {
        global $wpdb;
        $tables = $this->table_names();
        $updated = $wpdb->update($tables['authorizations'], array('active' => 0, 'revoked_at' => current_time('mysql', true)), array('id' => absint($authorization_id)));
        return array('ok' => $updated !== false, 'authorization_id' => absint($authorization_id), 'revoked' => $updated !== false);
    }

    public function seed_default_channel() {
        global $wpdb;
        $tables = $this->table_names();
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['channels']} WHERE channel_key=%s", 'support-email'));
        if ($existing) {
            return;
        }
        $now = current_time('mysql', true);
        $wpdb->insert($tables['channels'], array(
            'channel_key' => 'support-email',
            'channel_type' => 'email',
            'display_name' => 'Support Email',
            'inbound_address_ref' => 'contact-engagement:support-inbox',
            'transport_authority' => 'contact-engagement',
            'active' => 1,
            'configuration_json' => wp_json_encode(array('automatic_case_creation' => false, 'automatic_customer_send' => false, 'case_number_required_for_direct_append' => true)),
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'db_version' => self::DB_VERSION,
            'tables' => array_values($this->table_names()),
            'capabilities' => array(
                'authenticated_inbound_email',
                'case_number_thread_matching',
                'message_reference_thread_matching',
                'outbound_draft_preparation',
                'delivery_and_bounce_tracking',
                'least_privilege_channel_authorizations',
                'microsoft_teams_handoffs',
                'append_only_channel_events',
                'rest_and_wp_cli_operations',
            ),
            'authorities' => array(
                'identity' => 'contact-engagement',
                'transport' => 'contact-engagement',
                'attachments' => 'contact-engagement',
                'scheduling' => 'contact-engagement',
            ),
            'governance' => array(
                'automatic_case_creation' => false,
                'automatic_customer_send' => false,
                'automatic_case_closure' => false,
                'public_inbound_webhook' => false,
                'raw_authorization_secrets_stored' => false,
                'raw_attachment_bytes_stored' => false,
                'raw_requester_identity_copied' => false,
                'zoom_supported' => false,
                'google_meet_supported' => false,
                'microsoft_teams_only' => true,
                'human_review_required' => true,
            ),
        );
    }

    public function extract_case_numbers($subject, $body = '') {
        preg_match_all('/\bSC-\d{4}-\d{6}\b/i', (string) $subject . ' ' . (string) $body, $matches);
        return array_values(array_unique(array_map('strtoupper', isset($matches[0]) ? $matches[0] : array())));
    }

    private function find_thread_case($channel_id, $provider_thread_ref, $in_reply_to_ref = '') {
        global $wpdb;
        $tables = $this->table_names();
        if ($in_reply_to_ref !== '') {
            $case_id = $wpdb->get_var($wpdb->prepare("SELECT case_id FROM {$tables['messages']} WHERE channel_id=%d AND provider_message_ref=%s AND case_id>0", $channel_id, $in_reply_to_ref));
            if ($case_id) {
                return array('case_id' => absint($case_id), 'method' => 'in_reply_to');
            }
        }
        if ($provider_thread_ref !== '') {
            $case_id = $wpdb->get_var($wpdb->prepare("SELECT case_id FROM {$tables['threads']} WHERE channel_id=%d AND provider_thread_ref=%s AND state='active'", $channel_id, $provider_thread_ref));
            if ($case_id) {
                return array('case_id' => absint($case_id), 'method' => 'provider_thread');
            }
        }
        return array('case_id' => 0, 'method' => 'none');
    }

    public function match_case($payload) {
        $foundation = $this->foundation();
        if (!$foundation) {
            return array('matched' => false, 'case_id' => 0, 'case_number' => '', 'method' => 'none', 'candidates' => array());
        }
        $numbers = $this->extract_case_numbers($payload['subject'] ?? '', $payload['body_preview'] ?? '');
        $matched = array();
        foreach ($numbers as $number) {
            $case = $foundation->get_case_by_number($number);
            if ($case) {
                $matched[$number] = absint($case['id']);
            }
        }
        if (count($matched) === 1) {
            $number = array_key_first($matched);
            return array('matched' => true, 'case_id' => $matched[$number], 'case_number' => $number, 'method' => 'case_number', 'candidates' => array_keys($matched));
        }
        if (count($matched) > 1) {
            return array('matched' => false, 'case_id' => 0, 'case_number' => '', 'method' => 'ambiguous', 'candidates' => array_keys($matched));
        }
        $thread = $this->find_thread_case(absint($payload['channel_id'] ?? 0), sanitize_text_field($payload['provider_thread_ref'] ?? ''), sanitize_text_field($payload['in_reply_to_ref'] ?? ''));
        if ($thread['case_id']) {
            $case = $foundation->get_case($thread['case_id']);
            return array('matched' => true, 'case_id' => $thread['case_id'], 'case_number' => $case ? $case['case_number'] : '', 'method' => $thread['method'], 'candidates' => array());
        }
        return array('matched' => false, 'case_id' => 0, 'case_number' => '', 'method' => 'none', 'candidates' => $numbers);
    }

    public function register_inbound_message($payload, $actor_user_id = 0) {
        global $wpdb;
        $tables = $this->table_names();
        $channel_id = absint($payload['channel_id'] ?? 0);
        $provider_message_ref = sanitize_text_field($payload['provider_message_ref'] ?? '');
        $sender_ref = sanitize_text_field($payload['sender_ref'] ?? '');
        $subject = sanitize_text_field($payload['subject'] ?? '');
        $body = wp_kses_post($payload['body'] ?? '');
        $body_sha256 = strtolower(sanitize_text_field($payload['body_sha256'] ?? hash('sha256', $body)));
        if (!$channel_id || $provider_message_ref === '' || $sender_ref === '' || !preg_match('/^[a-f0-9]{64}$/', $body_sha256)) {
            return new WP_Error('scfs_invalid_inbound_email', 'Authenticated channel, message reference, sender reference, and SHA-256 content digest are required.');
        }
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['messages']} WHERE channel_id=%d AND provider_message_ref=%s", $channel_id, $provider_message_ref))) {
            return new WP_Error('scfs_duplicate_email_message', 'This provider message has already been registered.');
        }
        $match = $this->match_case(array_merge($payload, array('channel_id' => $channel_id)));
        $case_id = $match['matched'] ? absint($match['case_id']) : 0;
        $state = $case_id ? 'received' : 'review_required';
        $now = current_time('mysql', true);
        $thread_id = 0;
        if ($case_id && sanitize_text_field($payload['provider_thread_ref'] ?? '') !== '') {
            $provider_thread_ref = sanitize_text_field($payload['provider_thread_ref']);
            $thread_id = absint($wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['threads']} WHERE channel_id=%d AND provider_thread_ref=%s", $channel_id, $provider_thread_ref)));
            if (!$thread_id) {
                $wpdb->insert($tables['threads'], array('channel_id'=>$channel_id,'case_id'=>$case_id,'provider_thread_ref'=>$provider_thread_ref,'normalized_subject'=>sanitize_title($subject),'state'=>'active','last_message_at'=>$now,'created_at'=>$now,'updated_at'=>$now));
                $thread_id = absint($wpdb->insert_id);
            }
        }
        $wpdb->insert($tables['messages'], array(
            'channel_id'=>$channel_id,'thread_id'=>$thread_id,'case_id'=>$case_id,'direction'=>'inbound','provider_message_ref'=>$provider_message_ref,
            'in_reply_to_ref'=>sanitize_text_field($payload['in_reply_to_ref'] ?? ''),'sender_ref'=>$sender_ref,'recipient_ref'=>sanitize_text_field($payload['recipient_ref'] ?? ''),
            'subject'=>$subject,'content_sha256'=>$body_sha256,'content_length'=>strlen(wp_strip_all_tags($body)),'message_state'=>$state,'case_match_method'=>$match['method'],
            'attachment_count'=>absint($payload['attachment_count'] ?? 0),'attachment_refs_json'=>wp_json_encode(array_map('sanitize_text_field',(array)($payload['attachment_refs'] ?? array()))),
            'transport_handoff_ref'=>sanitize_text_field($payload['transport_handoff_ref'] ?? ''),'created_by'=>absint($actor_user_id),'created_at'=>$now,'updated_at'=>$now,
        ));
        $message_id = absint($wpdb->insert_id);
        if ($case_id && $body !== '') {
            $foundation = $this->foundation();
            $foundation->add_message($case_id, array('message_type'=>'requester_message','visibility'=>'participant','author_ref'=>$sender_ref,'body'=>$body,'metadata'=>array('channel'=>'email','channel_message_id'=>$message_id,'provider_message_ref'=>$provider_message_ref)), $actor_user_id);
            $foundation->add_event($case_id, 'email_inbound_registered', array('channel_message_id'=>$message_id,'match_method'=>$match['method'],'attachment_count'=>absint($payload['attachment_count'] ?? 0)), $actor_user_id);
        }
        do_action('scfs_help_desk_email_inbound_registered', $message_id, $case_id, $match, $payload);
        return array('ok'=>true,'version'=>self::VERSION,'message_id'=>$message_id,'case_id'=>$case_id,'case_number'=>$match['case_number'],'state'=>$state,'match'=>$match,'automatic_case_creation'=>false);
    }

    public function prepare_outbound_draft($case_id, $payload, $actor_user_id = 0) {
        global $wpdb;
        $foundation = $this->foundation();
        $case = $foundation ? $foundation->get_case($case_id) : null;
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'The private help-desk case was not found.');
        }
        $recipient_ref = sanitize_text_field($payload['recipient_ref'] ?? '');
        $body = wp_kses_post($payload['body'] ?? '');
        $customer_safe = rest_sanitize_boolean($payload['customer_safe'] ?? false);
        $attachments_cleared = rest_sanitize_boolean($payload['attachments_cleared'] ?? true);
        if ($recipient_ref === '' || trim(wp_strip_all_tags($body)) === '' || !$customer_safe || !$attachments_cleared) {
            return new WP_Error('scfs_outbound_review_required', 'Recipient reference, customer-safe review, body, and attachment clearance are required.');
        }
        $tables = $this->table_names();
        $channel_id = absint($payload['channel_id'] ?? 0);
        $subject = sanitize_text_field($payload['subject'] ?? 'Support update');
        if (stripos($subject, $case['case_number']) === false) {
            $subject = '[' . $case['case_number'] . '] ' . $subject;
        }
        $digest = hash('sha256', $body);
        $message_ref = 'draft:' . wp_generate_uuid4();
        $now = current_time('mysql', true);
        $attachment_refs = array_map('sanitize_text_field', (array)($payload['attachment_refs'] ?? array()));
        $handoff = array('schema'=>self::SCHEMA,'case_id'=>absint($case_id),'case_number'=>$case['case_number'],'recipient_ref'=>$recipient_ref,'subject'=>$subject,'body'=>$body,'body_sha256'=>$digest,'attachment_refs'=>$attachment_refs,'automatic_send'=>false,'transport_authority'=>'contact-engagement');
        $wpdb->insert($tables['messages'], array('channel_id'=>$channel_id,'thread_id'=>0,'case_id'=>absint($case_id),'direction'=>'outbound','provider_message_ref'=>$message_ref,'in_reply_to_ref'=>sanitize_text_field($payload['in_reply_to_ref'] ?? ''),'sender_ref'=>'help-desk-agent:'.absint($actor_user_id),'recipient_ref'=>$recipient_ref,'subject'=>$subject,'content_sha256'=>$digest,'content_length'=>strlen(wp_strip_all_tags($body)),'message_state'=>'draft','case_match_method'=>'case_context','attachment_count'=>count($attachment_refs),'attachment_refs_json'=>wp_json_encode($attachment_refs),'transport_handoff_ref'=>'','created_by'=>absint($actor_user_id),'created_at'=>$now,'updated_at'=>$now));
        $message_id = absint($wpdb->insert_id);
        $handoff['channel_message_id'] = $message_id;
        $handoff['handoff_fingerprint'] = hash('sha256', wp_json_encode(array($case['case_number'],$recipient_ref,$subject,$digest,$attachment_refs)));
        $foundation->add_event($case_id, 'email_outbound_draft_prepared', array('channel_message_id'=>$message_id,'body_sha256'=>$digest,'attachment_count'=>count($attachment_refs),'automatic_send'=>false), $actor_user_id);
        do_action('scfs_contact_engagement_email_handoff_requested', $handoff, $actor_user_id);
        return array('ok'=>true,'version'=>self::VERSION,'message_id'=>$message_id,'case_number'=>$case['case_number'],'subject'=>$subject,'body_sha256'=>$digest,'draft_only'=>true,'automatic_send'=>false,'transport_authority'=>'contact-engagement','handoff_fingerprint'=>$handoff['handoff_fingerprint']);
    }

    public function record_delivery_event($message_id, $payload, $actor_user_id = 0) {
        global $wpdb;
        $tables = $this->table_names();
        $message = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['messages']} WHERE id=%d", absint($message_id)), ARRAY_A);
        if (!$message) {
            return new WP_Error('scfs_channel_message_not_found', 'The channel message was not found.');
        }
        $event_type = sanitize_key($payload['event_type'] ?? '');
        $allowed = array('queued','accepted','delivered','deferred','bounced','complaint','failed');
        $provider_event_ref = sanitize_text_field($payload['provider_event_ref'] ?? '');
        if (!in_array($event_type, $allowed, true) || $provider_event_ref === '') {
            return new WP_Error('scfs_invalid_delivery_event', 'A supported delivery event and provider event reference are required.');
        }
        $now = current_time('mysql', true);
        $wpdb->insert($tables['delivery_events'], array('message_id'=>absint($message_id),'case_id'=>absint($message['case_id']),'provider_event_ref'=>$provider_event_ref,'event_type'=>$event_type,'diagnostic_code'=>sanitize_text_field($payload['diagnostic_code'] ?? ''),'event_data_json'=>wp_json_encode((array)($payload['event_data'] ?? array())),'event_at'=>sanitize_text_field($payload['event_at'] ?? $now),'received_at'=>$now));
        $wpdb->update($tables['messages'], array('message_state'=>$event_type,'updated_at'=>$now), array('id'=>absint($message_id)));
        $review = in_array($event_type, array('bounced','complaint','failed'), true);
        if ($review && absint($message['case_id'])) {
            $foundation = $this->foundation();
            $foundation->add_event(absint($message['case_id']), 'email_delivery_review_required', array('channel_message_id'=>absint($message_id),'event_type'=>$event_type,'diagnostic_code'=>sanitize_text_field($payload['diagnostic_code'] ?? '')), $actor_user_id);
        }
        do_action('scfs_help_desk_email_delivery_event_recorded', absint($message_id), $event_type, $payload);
        return array('ok'=>true,'version'=>self::VERSION,'message_id'=>absint($message_id),'delivery_state'=>$event_type,'private_review_required'=>$review,'automatic_case_closure'=>false,'automatic_customer_notification'=>false);
    }

    public function request_teams_handoff($case_id, $payload, $actor_user_id = 0) {
        global $wpdb;
        $foundation = $this->foundation();
        $case = $foundation ? $foundation->get_case($case_id) : null;
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'The private help-desk case was not found.');
        }
        $provider = sanitize_key($payload['provider'] ?? 'microsoft_teams');
        $purpose = sanitize_textarea_field($payload['purpose'] ?? '');
        $consent = sanitize_key($payload['consent_state'] ?? 'not_recorded');
        if ($provider !== 'microsoft_teams' || $purpose === '' || $consent !== 'recorded') {
            return new WP_Error('scfs_teams_handoff_review_required', 'Microsoft Teams, a purpose, and recorded requester consent are required.');
        }
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $attendees = array_map('sanitize_text_field', (array)($payload['attendee_refs'] ?? array()));
        $wpdb->insert($tables['handoffs'], array('case_id'=>absint($case_id),'handoff_type'=>'live_support_session','provider'=>'microsoft_teams','purpose'=>$purpose,'attendee_refs_json'=>wp_json_encode($attendees),'consent_state'=>'recorded','state'=>'pending','external_handoff_ref'=>'','requested_by'=>absint($actor_user_id),'approved_by'=>absint($actor_user_id),'created_at'=>$now,'updated_at'=>$now));
        $handoff_id = absint($wpdb->insert_id);
        $handoff = array('schema'=>self::SCHEMA,'handoff_id'=>$handoff_id,'case_id'=>absint($case_id),'case_number'=>$case['case_number'],'provider'=>'microsoft_teams','purpose'=>$purpose,'attendee_refs'=>$attendees,'consent_state'=>'recorded','automatic_scheduling'=>false,'scheduling_authority'=>'contact-engagement');
        $foundation->add_event($case_id, 'microsoft_teams_handoff_requested', array('handoff_id'=>$handoff_id,'automatic_scheduling'=>false), $actor_user_id);
        do_action('scfs_contact_engagement_teams_handoff_requested', $handoff, $actor_user_id);
        return array('ok'=>true,'version'=>self::VERSION,'handoff_id'=>$handoff_id,'provider'=>'microsoft_teams','state'=>'pending','automatic_scheduling'=>false,'scheduling_authority'=>'contact-engagement');
    }

    public function case_channel_summary($case_id) {
        global $wpdb;
        $tables = $this->table_names();
        return array(
            'version'=>self::VERSION,
            'schema'=>self::SCHEMA,
            'case_id'=>absint($case_id),
            'threads'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['threads']} WHERE case_id=%d ORDER BY updated_at DESC LIMIT 20", absint($case_id)), ARRAY_A),
            'messages'=>$wpdb->get_results($wpdb->prepare("SELECT id,direction,subject,message_state,case_match_method,attachment_count,created_at,updated_at FROM {$tables['messages']} WHERE case_id=%d ORDER BY created_at DESC LIMIT 50", absint($case_id)), ARRAY_A),
            'handoffs'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['handoffs']} WHERE case_id=%d ORDER BY created_at DESC LIMIT 20", absint($case_id)), ARRAY_A),
        );
    }

    public function health_report() {
        global $wpdb;
        $tables = $this->table_names();
        return array(
            'ok'=>true,'version'=>self::VERSION,'schema'=>self::SCHEMA,'db_version'=>get_option(self::DB_VERSION_OPTION,''),
            'active_channels'=>absint($wpdb->get_var("SELECT COUNT(*) FROM {$tables['channels']} WHERE active=1")),
            'unmatched_inbound'=>absint($wpdb->get_var("SELECT COUNT(*) FROM {$tables['messages']} WHERE direction='inbound' AND case_id=0 AND message_state='review_required'")),
            'delivery_reviews'=>absint($wpdb->get_var("SELECT COUNT(*) FROM {$tables['delivery_events']} WHERE event_type IN ('bounced','complaint','failed')")),
            'pending_teams_handoffs'=>absint($wpdb->get_var("SELECT COUNT(*) FROM {$tables['handoffs']} WHERE provider='microsoft_teams' AND state='pending'")),
            'automatic_case_creation'=>false,'automatic_customer_send'=>false,'public_inbound_webhook'=>false,
        );
    }

    public function scheduled_maintenance() {
        global $wpdb;
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare("UPDATE {$tables['authorizations']} SET active=0,revoked_at=%s WHERE active=1 AND expires_at IS NOT NULL AND expires_at<=%s", $now, $now));
        do_action('scfs_help_desk_email_channel_maintenance_completed', self::VERSION);
    }

    public function register_assets() {
        wp_register_style('scfs-help-desk-email-channels', plugins_url('../assets/help-desk-email-channels.css', __FILE__), array(), self::VERSION);
        wp_register_script('scfs-help-desk-email-channels', plugins_url('../assets/help-desk-email-channels.js', __FILE__), array(), self::VERSION, true);
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook, self::ADMIN_PAGE) === false && strpos((string)$hook, 'scfs-help-desk-agent-workspace') === false) {
            return;
        }
        $this->register_assets();
        wp_enqueue_style('scfs-help-desk-email-channels');
        wp_enqueue_script('scfs-help-desk-email-channels');
    }

    public function register_admin_page() {
        add_submenu_page('edit.php?post_type='.Sustainable_Catalyst_Feature_Suggestions::POST_TYPE, 'Email & Channels', 'Email & Channels', 'scfs_view_help_desk_channels', self::ADMIN_PAGE, array($this, 'render_admin_page'));
    }

    public function render_admin_page() {
        if (!$this->can_view()) {
            wp_die('Permission denied.');
        }
        global $wpdb;
        $tables = $this->table_names();
        $health = $this->health_report();
        $messages = $wpdb->get_results("SELECT id,case_id,direction,subject,message_state,case_match_method,created_at FROM {$tables['messages']} ORDER BY created_at DESC LIMIT 30", ARRAY_A);
        $handoffs = $wpdb->get_results("SELECT id,case_id,provider,purpose,state,created_at FROM {$tables['handoffs']} ORDER BY created_at DESC LIMIT 20", ARRAY_A);
        echo '<div class="wrap scfs-channel-admin"><h1>Email and Channel Operations</h1><p>Authenticated email metadata, case-thread matching, delivery review, and Microsoft Teams handoffs. Contact and Engagement remains authoritative for transport, identity, files, and scheduling.</p>';
        echo '<div class="scfs-channel-metrics"><article><strong>'.esc_html($health['active_channels']).'</strong><span>Active channels</span></article><article><strong>'.esc_html($health['unmatched_inbound']).'</strong><span>Inbound review</span></article><article><strong>'.esc_html($health['delivery_reviews']).'</strong><span>Delivery reviews</span></article><article><strong>'.esc_html($health['pending_teams_handoffs']).'</strong><span>Teams handoffs</span></article></div>';
        echo '<h2>Recent channel messages</h2><table class="widefat striped"><thead><tr><th>Case</th><th>Direction</th><th>Subject</th><th>State</th><th>Match</th><th>Created</th></tr></thead><tbody>';
        if (!$messages) { echo '<tr><td colspan="6">No channel messages have been registered.</td></tr>'; }
        foreach ($messages as $message) { echo '<tr><td>'.esc_html($message['case_id'] ?: 'Review').'</td><td>'.esc_html($message['direction']).'</td><td>'.esc_html($message['subject']).'</td><td>'.esc_html($message['message_state']).'</td><td>'.esc_html($message['case_match_method']).'</td><td>'.esc_html($message['created_at']).'</td></tr>'; }
        echo '</tbody></table><h2>Live-support handoffs</h2><table class="widefat striped"><thead><tr><th>Case</th><th>Provider</th><th>Purpose</th><th>State</th><th>Created</th></tr></thead><tbody>';
        if (!$handoffs) { echo '<tr><td colspan="5">No live-support handoffs have been requested.</td></tr>'; }
        foreach ($handoffs as $handoff) { echo '<tr><td>'.esc_html($handoff['case_id']).'</td><td>'.esc_html($handoff['provider']).'</td><td>'.esc_html($handoff['purpose']).'</td><td>'.esc_html($handoff['state']).'</td><td>'.esc_html($handoff['created_at']).'</td></tr>'; }
        echo '</tbody></table><div class="notice notice-info inline"><p>Email never creates cases, sends replies, or closes cases automatically. Microsoft Teams is the only supported live-session provider.</p></div></div>';
    }

    public function render_case_panel($case) {
        if (!$this->can_view()) {
            return;
        }
        wp_enqueue_style('scfs-help-desk-email-channels');
        wp_enqueue_script('scfs-help-desk-email-channels');
        $summary = $this->case_channel_summary($case['id']);
        echo '<section class="scfs-agent-workspace__panel scfs-channel-panel" data-scfs-channel-panel><h3>Email and channels</h3><p>'.esc_html(count($summary['messages'])).' recent messages · '.esc_html(count($summary['threads'])).' threads · '.esc_html(count($summary['handoffs'])).' Teams handoffs</p><p class="description">Outbound replies remain drafts until an authorized Contact and Engagement delivery handoff is completed.</p></section>';
    }

    public function admin_action() {
        check_admin_referer(self::NONCE_ACTION);
        if (!$this->can_manage()) {
            wp_die('Permission denied.');
        }
        wp_safe_redirect(admin_url('edit.php?post_type='.Sustainable_Catalyst_Feature_Suggestions::POST_TYPE.'&page='.self::ADMIN_PAGE));
        exit;
    }

    public function register_rest_routes() {
        $ns = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($ns, '/help-desk/channels/schema', array('methods'=>'GET','callback'=>array($this,'rest_schema'),'permission_callback'=>array($this,'can_view')));
        register_rest_route($ns, '/help-desk/channels', array('methods'=>'GET','callback'=>array($this,'rest_channels'),'permission_callback'=>array($this,'can_view')));
        register_rest_route($ns, '/help-desk/channels/case/(?P<id>\d+)', array('methods'=>'GET','callback'=>array($this,'rest_case'),'permission_callback'=>array($this,'can_view')));
        register_rest_route($ns, '/help-desk/channels/inbound/register', array('methods'=>'POST','callback'=>array($this,'rest_inbound'),'permission_callback'=>array($this,'can_process_inbound')));
        register_rest_route($ns, '/help-desk/channels/outbound/prepare', array('methods'=>'POST','callback'=>array($this,'rest_outbound'),'permission_callback'=>array($this,'can_prepare_outbound')));
        register_rest_route($ns, '/help-desk/channels/delivery-events', array('methods'=>'POST','callback'=>array($this,'rest_delivery'),'permission_callback'=>array($this,'can_record_delivery')));
        register_rest_route($ns, '/help-desk/channels/teams/handoff', array('methods'=>'POST','callback'=>array($this,'rest_teams'),'permission_callback'=>array($this,'can_request_teams')));
        register_rest_route($ns, '/help-desk/channels/authorizations', array('methods'=>'POST','callback'=>array($this,'rest_issue_authorization'),'permission_callback'=>array($this,'can_manage')));
        register_rest_route($ns, '/help-desk/channels/authorizations/verify', array('methods'=>'POST','callback'=>array($this,'rest_verify_authorization'),'permission_callback'=>array($this,'can_manage')));
        register_rest_route($ns, '/help-desk/channels/authorizations/(?P<id>\d+)/revoke', array('methods'=>'POST','callback'=>array($this,'rest_revoke_authorization'),'permission_callback'=>array($this,'can_manage')));
        register_rest_route($ns, '/help-desk/channels/health', array('methods'=>'GET','callback'=>array($this,'rest_health'),'permission_callback'=>array($this,'can_view')));
    }

    public function rest_schema() { return rest_ensure_response($this->schema_record()); }
    public function rest_channels() { global $wpdb; $tables=$this->table_names(); return rest_ensure_response(array('version'=>self::VERSION,'channels'=>$wpdb->get_results("SELECT id,channel_key,channel_type,display_name,transport_authority,active FROM {$tables['channels']} ORDER BY display_name",ARRAY_A))); }
    public function rest_case($request) { return rest_ensure_response($this->case_channel_summary(absint($request['id']))); }
    public function rest_inbound($request) { return rest_ensure_response($this->register_inbound_message((array)$request->get_json_params(), get_current_user_id())); }
    public function rest_outbound($request) { $payload=(array)$request->get_json_params(); return rest_ensure_response($this->prepare_outbound_draft(absint($payload['case_id']??0), $payload, get_current_user_id())); }
    public function rest_delivery($request) { $payload=(array)$request->get_json_params(); return rest_ensure_response($this->record_delivery_event(absint($payload['message_id']??0), $payload, get_current_user_id())); }
    public function rest_teams($request) { $payload=(array)$request->get_json_params(); return rest_ensure_response($this->request_teams_handoff(absint($payload['case_id']??0), $payload, get_current_user_id())); }
    public function rest_issue_authorization($request) { $result=$this->issue_authorization((array)$request->get_json_params(),get_current_user_id()); return is_wp_error($result)?$result:rest_ensure_response($result); }
    public function rest_verify_authorization($request) { $payload=(array)$request->get_json_params(); return rest_ensure_response($this->verify_authorization((string)($payload['authorization_ref']??''),(string)($payload['secret']??''),(string)($payload['required_scope']??''))); }
    public function rest_revoke_authorization($request) { return rest_ensure_response($this->revoke_authorization(absint($request['id']))); }
    public function rest_health() { return rest_ensure_response($this->health_report()); }

    public function cli_status() { WP_CLI::log(wp_json_encode($this->health_report(), JSON_PRETTY_PRINT)); }
    public function cli_match_case($args, $assoc_args) { $result=$this->match_case(array('channel_id'=>absint($assoc_args['channel_id']??0),'subject'=>(string)($assoc_args['subject']??''),'provider_thread_ref'=>(string)($assoc_args['thread']??''),'in_reply_to_ref'=>(string)($assoc_args['in_reply_to']??''))); WP_CLI::log(wp_json_encode($result,JSON_PRETTY_PRINT)); }
    public function cli_prepare_reply($args, $assoc_args) { $id=absint($args[0]??0); $result=$this->prepare_outbound_draft($id,array('channel_id'=>absint($assoc_args['channel_id']??0),'recipient_ref'=>(string)($assoc_args['recipient']??''),'subject'=>(string)($assoc_args['subject']??'Support update'),'body'=>(string)($assoc_args['body']??''),'customer_safe'=>true,'attachments_cleared'=>true),get_current_user_id()); if(is_wp_error($result))WP_CLI::error($result->get_error_message()); WP_CLI::log(wp_json_encode($result,JSON_PRETTY_PRINT)); }
    public function cli_delivery_event($args, $assoc_args) { $id=absint($args[0]??0); $result=$this->record_delivery_event($id,array('event_type'=>(string)($assoc_args['event']??''),'provider_event_ref'=>(string)($assoc_args['provider_event']??wp_generate_uuid4()),'diagnostic_code'=>(string)($assoc_args['code']??'')),get_current_user_id()); if(is_wp_error($result))WP_CLI::error($result->get_error_message()); WP_CLI::log(wp_json_encode($result,JSON_PRETTY_PRINT)); }
}
