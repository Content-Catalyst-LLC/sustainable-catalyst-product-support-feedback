<?php
/**
 * Help Desk API, Webhooks, and External Integrations.
 *
 * Private integration operations remain additive. Raw secrets, requester identity,
 * private correspondence, and attachment bytes are never exposed through public APIs.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_API_Integrations {
    const VERSION = '7.8.1';
    const DB_VERSION = '2.0.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_api_integrations_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_api_integrations_settings';
    const SCHEMA = 'scfs-help-desk-api-integrations/1.0';
    const ADMIN_PAGE = 'scfs-help-desk-api-integrations';
    const CRON_HOOK = 'scfs_help_desk_process_integration_deliveries';
    const EVENT_HOOK = 'scfs_help_desk_integration_event';

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
        add_action(self::CRON_HOOK, array($this, 'process_delivery_queue'));
        add_action(self::EVENT_HOOK, array($this, 'queue_event'), 10, 2);
        add_action('scfs_case_event_recorded', array($this, 'capture_case_event'), 20, 2);
        add_action('scfs_known_issue_updated', array($this, 'capture_known_issue_event'), 20, 2);
        add_action('scfs_release_record_updated', array($this, 'capture_release_event'), 20, 2);
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
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
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
            'maximum_attempts' => 6,
            'initial_retry_seconds' => 60,
            'maximum_retry_seconds' => 21600,
            'delivery_timeout_seconds' => 15,
            'event_retention_days' => 365,
            'credential_authority' => 'environment-or-contact-engagement',
            'public_case_api' => '0',
            'public_inbound_webhook' => '0',
            'raw_secrets_stored' => '0',
            'private_message_content_allowed' => '0',
            'requester_identity_allowed' => '0',
            'attachment_content_allowed' => '0',
            'automatic_external_issue_creation' => '0',
            'automatic_case_transition' => '0',
            'automatic_customer_communication' => '0',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function capabilities() {
        return array(
            'scfs_view_help_desk_integrations',
            'scfs_manage_help_desk_integrations',
            'scfs_manage_help_desk_api_scopes',
            'scfs_dispatch_help_desk_integrations',
            'scfs_retry_help_desk_integrations',
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
            $agent->add_cap('scfs_view_help_desk_integrations');
        }
    }

    public function table_names() {
        global $wpdb;
        return array(
            'integrations' => $wpdb->prefix . 'scfs_help_desk_integrations',
            'credentials' => $wpdb->prefix . 'scfs_help_desk_integration_credentials',
            'subscriptions' => $wpdb->prefix . 'scfs_help_desk_webhook_subscriptions',
            'deliveries' => $wpdb->prefix . 'scfs_help_desk_webhook_deliveries',
            'attempts' => $wpdb->prefix . 'scfs_help_desk_webhook_attempts',
            'dead_letters' => $wpdb->prefix . 'scfs_help_desk_webhook_dead_letters',
            'external_links' => $wpdb->prefix . 'scfs_help_desk_external_links',
            'checkpoints' => $wpdb->prefix . 'scfs_help_desk_integration_checkpoints',
            'audit' => $wpdb->prefix . 'scfs_help_desk_integration_audit_events',
        );
    }

    public function install_schema() {
        global $wpdb;
        $t = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = array();
        $sql[] = "CREATE TABLE {$t['integrations']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_key varchar(120) NOT NULL,
            integration_type varchar(60) NOT NULL,
            display_name varchar(191) NOT NULL,
            provider_key varchar(120) NOT NULL DEFAULT '',
            base_url varchar(255) NOT NULL DEFAULT '',
            state varchar(40) NOT NULL DEFAULT 'draft',
            allowed_scopes_json longtext NOT NULL,
            privacy_profile_json longtext NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY integration_key (integration_key),
            KEY type_state (integration_type,state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['credentials']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_id bigint(20) unsigned NOT NULL,
            credential_key varchar(120) NOT NULL,
            secret_reference varchar(191) NOT NULL,
            secret_sha256 char(64) NOT NULL,
            scopes_json longtext NOT NULL,
            credential_state varchar(40) NOT NULL DEFAULT 'active',
            expires_at datetime NULL,
            rotated_at datetime NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY integration_credential (integration_id,credential_key),
            KEY integration_state (integration_id,credential_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['subscriptions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_id bigint(20) unsigned NOT NULL,
            subscription_key varchar(120) NOT NULL,
            event_pattern varchar(191) NOT NULL,
            endpoint_url varchar(255) NOT NULL,
            signing_credential_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payload_profile varchar(60) NOT NULL DEFAULT 'privacy_minimized',
            subscription_state varchar(40) NOT NULL DEFAULT 'draft',
            maximum_attempts int(10) unsigned NOT NULL DEFAULT 6,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY integration_subscription (integration_id,subscription_key),
            KEY event_state (event_pattern,subscription_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['deliveries']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subscription_id bigint(20) unsigned NOT NULL,
            event_id varchar(120) NOT NULL,
            event_type varchar(191) NOT NULL,
            payload_json longtext NOT NULL,
            payload_sha256 char(64) NOT NULL,
            delivery_state varchar(40) NOT NULL DEFAULT 'queued',
            attempt_count int(10) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NULL,
            delivered_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY subscription_event (subscription_id,event_id),
            KEY state_next (delivery_state,next_attempt_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['attempts']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            delivery_id bigint(20) unsigned NOT NULL,
            attempt_number int(10) unsigned NOT NULL,
            request_sha256 char(64) NOT NULL,
            response_status int(10) unsigned NOT NULL DEFAULT 0,
            response_sha256 char(64) NOT NULL DEFAULT '',
            outcome varchar(40) NOT NULL,
            error_code varchar(120) NOT NULL DEFAULT '',
            duration_ms int(10) unsigned NOT NULL DEFAULT 0,
            attempted_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY delivery_attempt (delivery_id,attempt_number),
            KEY outcome_attempted (outcome,attempted_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['dead_letters']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            delivery_id bigint(20) unsigned NOT NULL,
            reason_code varchar(120) NOT NULL,
            review_state varchar(40) NOT NULL DEFAULT 'review_required',
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime NULL,
            resolution_note varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY delivery_dead_letter (delivery_id),
            KEY review_created (review_state,created_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['external_links']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(60) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            integration_id bigint(20) unsigned NOT NULL,
            external_object_type varchar(80) NOT NULL,
            external_reference varchar(191) NOT NULL,
            external_url varchar(255) NOT NULL DEFAULT '',
            link_state varchar(40) NOT NULL DEFAULT 'active',
            relationship_type varchar(80) NOT NULL DEFAULT 'related',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY integration_external_reference (integration_id,external_object_type,external_reference),
            KEY case_state (case_id,link_state),
            KEY local_object (object_type,object_id)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['checkpoints']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_id bigint(20) unsigned NOT NULL,
            checkpoint_key varchar(120) NOT NULL,
            cursor_value varchar(255) NOT NULL DEFAULT '',
            state_sha256 char(64) NOT NULL,
            last_success_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY integration_checkpoint (integration_id,checkpoint_key)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['audit']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(120) NOT NULL,
            object_type varchar(60) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_summary varchar(255) NOT NULL,
            evidence_json longtext NOT NULL,
            evidence_sha256 char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY integration_created (integration_id,created_at),
            KEY object_event (object_type,object_id,event_type)
        ) $charset;";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=sc_feature_suggest',
            __('API & Integrations', 'sustainable-catalyst-feature-suggestions'),
            __('API & Integrations', 'sustainable-catalyst-feature-suggestions'),
            'scfs_view_help_desk_integrations',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false) {
            return;
        }
        wp_enqueue_style('scfs-help-desk-api-integrations', plugins_url('../assets/help-desk-api-integrations-v6.12.0.css', __FILE__), array(), self::VERSION);
        wp_enqueue_script('scfs-help-desk-api-integrations', plugins_url('../assets/help-desk-api-integrations-v6.12.0.js', __FILE__), array(), self::VERSION, true);
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/help-desk/integrations/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/integrations', array(
            array('methods' => 'GET', 'callback' => array($this, 'rest_integrations'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => 'POST', 'callback' => array($this, 'rest_create_integration'), 'permission_callback' => array($this, 'can_manage')),
        ));
        register_rest_route($namespace, '/help-desk/integrations/credentials', array('methods' => 'POST', 'callback' => array($this, 'rest_register_credential'), 'permission_callback' => array($this, 'can_manage_scopes')));
        register_rest_route($namespace, '/help-desk/integrations/subscriptions', array('methods' => 'POST', 'callback' => array($this, 'rest_create_subscription'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/integrations/events/dispatch', array('methods' => 'POST', 'callback' => array($this, 'rest_dispatch_event'), 'permission_callback' => array($this, 'can_dispatch')));
        register_rest_route($namespace, '/help-desk/integrations/deliveries', array('methods' => 'GET', 'callback' => array($this, 'rest_deliveries'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/integrations/deliveries/(?P<id>\\d+)/retry', array('methods' => 'POST', 'callback' => array($this, 'rest_retry_delivery'), 'permission_callback' => array($this, 'can_retry')));
        register_rest_route($namespace, '/help-desk/integrations/dead-letters', array('methods' => 'GET', 'callback' => array($this, 'rest_dead_letters'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/integrations/external-links', array('methods' => 'POST', 'callback' => array($this, 'rest_create_external_link'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/integrations/health', array('methods' => 'GET', 'callback' => array($this, 'rest_health'), 'permission_callback' => array($this, 'can_view')));
    }

    public function can_view() { return current_user_can('scfs_view_help_desk_integrations') || current_user_can('manage_options'); }
    public function can_manage() { return current_user_can('scfs_manage_help_desk_integrations') || current_user_can('manage_options'); }
    public function can_manage_scopes() { return current_user_can('scfs_manage_help_desk_api_scopes') || current_user_can('manage_options'); }
    public function can_dispatch() { return current_user_can('scfs_dispatch_help_desk_integrations') || current_user_can('manage_options'); }
    public function can_retry() { return current_user_can('scfs_retry_help_desk_integrations') || current_user_can('manage_options'); }

    public function schema_record() {
        return array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'db_version' => self::DB_VERSION,
            'scoped_case_api' => true,
            'signed_outbound_webhooks' => true,
            'delivery_retry_queue' => true,
            'dead_letter_review' => true,
            'external_system_links' => true,
            'integration_checkpoints' => true,
            'append_only_audit_events' => true,
            'github_repository_links' => true,
            'monitoring_handoffs' => true,
            'institutional_connectors' => true,
            'contact_engagement_interoperability' => true,
            'credential_authority' => 'environment-or-contact-engagement',
            'public_case_api' => false,
            'public_inbound_webhook' => false,
            'raw_secrets_stored' => false,
            'requester_identity_exposed' => false,
            'private_message_content_exposed' => false,
            'attachment_content_exposed' => false,
            'automatic_external_issue_creation' => false,
            'automatic_case_transition' => false,
            'automatic_customer_communication' => false,
            'human_authorization_required' => true,
            'additive_tables' => array_values($this->table_names()),
        );
    }

    public function rest_schema() { return rest_ensure_response($this->schema_record()); }

    public function rest_integrations() {
        global $wpdb;
        $t = $this->table_names();
        return rest_ensure_response($wpdb->get_results("SELECT id,integration_key,integration_type,display_name,provider_key,state,updated_at FROM {$t['integrations']} ORDER BY display_name ASC", ARRAY_A));
    }

    public function rest_create_integration(WP_REST_Request $request) {
        return $this->create_integration($request->get_json_params());
    }

    public function create_integration($input) {
        global $wpdb;
        $t = $this->table_names();
        $key = sanitize_key($input['integration_key'] ?? '');
        $type = sanitize_key($input['integration_type'] ?? 'custom');
        $name = sanitize_text_field($input['display_name'] ?? '');
        if (!$key || !$name) {
            return new WP_Error('scfs_invalid_integration', __('Integration key and name are required.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
        }
        $allowed_types = array('github', 'repository', 'monitoring', 'contact-engagement', 'institutional', 'custom');
        if (!in_array($type, $allowed_types, true)) {
            return new WP_Error('scfs_invalid_integration_type', __('Unsupported integration type.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
        }
        $scopes = $this->sanitize_scopes($input['allowed_scopes'] ?? array());
        $privacy = array(
            'requester_identity' => false,
            'private_messages' => false,
            'attachments' => false,
            'customer_send' => false,
        );
        $now = current_time('mysql', true);
        $ok = $wpdb->replace($t['integrations'], array(
            'integration_key' => $key,
            'integration_type' => $type,
            'display_name' => $name,
            'provider_key' => sanitize_key($input['provider_key'] ?? ''),
            'base_url' => esc_url_raw($input['base_url'] ?? ''),
            'state' => in_array(($input['state'] ?? 'draft'), array('draft','active','paused','revoked'), true) ? $input['state'] : 'draft',
            'allowed_scopes_json' => wp_json_encode($scopes),
            'privacy_profile_json' => wp_json_encode($privacy),
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if ($ok === false) {
            return new WP_Error('scfs_integration_write_failed', __('Could not save integration.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;
        $this->audit($id, 'integration.saved', 'integration', $id, 'Integration configuration saved.', array('integration_key' => $key, 'scopes' => $scopes));
        return rest_ensure_response(array('ok' => true, 'id' => $id, 'integration_key' => $key, 'scopes' => $scopes, 'human_authorization_required' => true));
    }

    public function rest_register_credential(WP_REST_Request $request) {
        return $this->register_credential($request->get_json_params());
    }

    public function register_credential($input) {
        global $wpdb;
        $t = $this->table_names();
        $integration_id = absint($input['integration_id'] ?? 0);
        $credential_key = sanitize_key($input['credential_key'] ?? 'default');
        $secret_reference = sanitize_text_field($input['secret_reference'] ?? '');
        $secret_fingerprint = strtolower(sanitize_text_field($input['secret_sha256'] ?? ''));
        if (!$integration_id || !$secret_reference || !preg_match('/^[a-f0-9]{64}$/', $secret_fingerprint)) {
            return new WP_Error('scfs_invalid_credential_reference', __('A secret reference and SHA-256 fingerprint are required. Raw secrets are not accepted.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
        }
        $scopes = $this->sanitize_scopes($input['scopes'] ?? array());
        $ok = $wpdb->replace($t['credentials'], array(
            'integration_id' => $integration_id,
            'credential_key' => $credential_key,
            'secret_reference' => $secret_reference,
            'secret_sha256' => $secret_fingerprint,
            'scopes_json' => wp_json_encode($scopes),
            'credential_state' => 'active',
            'expires_at' => !empty($input['expires_at']) ? sanitize_text_field($input['expires_at']) : null,
            'rotated_at' => current_time('mysql', true),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            return new WP_Error('scfs_credential_write_failed', __('Could not register credential reference.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;
        $this->audit($integration_id, 'credential.registered', 'credential', $id, 'Credential reference registered without storing the raw secret.', array('credential_key' => $credential_key, 'scopes' => $scopes));
        return rest_ensure_response(array('ok' => true, 'id' => $id, 'raw_secret_stored' => false, 'credential_authority' => 'environment-or-contact-engagement'));
    }

    public function rest_create_subscription(WP_REST_Request $request) {
        return $this->create_subscription($request->get_json_params());
    }

    public function create_subscription($input) {
        global $wpdb;
        $t = $this->table_names();
        $integration_id = absint($input['integration_id'] ?? 0);
        $endpoint = esc_url_raw($input['endpoint_url'] ?? '');
        $pattern = sanitize_text_field($input['event_pattern'] ?? '');
        if (!$integration_id || !$endpoint || strpos($endpoint, 'https://') !== 0 || !$pattern) {
            return new WP_Error('scfs_invalid_subscription', __('Active subscriptions require an integration, HTTPS endpoint, and event pattern.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
        }
        $ok = $wpdb->insert($t['subscriptions'], array(
            'integration_id' => $integration_id,
            'subscription_key' => sanitize_key($input['subscription_key'] ?? wp_generate_uuid4()),
            'event_pattern' => $pattern,
            'endpoint_url' => $endpoint,
            'signing_credential_id' => absint($input['signing_credential_id'] ?? 0),
            'payload_profile' => 'privacy_minimized',
            'subscription_state' => !empty($input['activate']) ? 'active' : 'draft',
            'maximum_attempts' => min(12, max(1, absint($input['maximum_attempts'] ?? 6))),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            return new WP_Error('scfs_subscription_write_failed', __('Could not create webhook subscription.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;
        $this->audit($integration_id, 'subscription.created', 'subscription', $id, 'Signed outbound webhook subscription created.', array('event_pattern' => $pattern, 'payload_profile' => 'privacy_minimized'));
        return rest_ensure_response(array('ok' => true, 'id' => $id, 'signed_outbound_only' => true, 'public_inbound_webhook' => false));
    }

    public function rest_dispatch_event(WP_REST_Request $request) {
        $input = $request->get_json_params();
        $event_type = sanitize_text_field($input['event_type'] ?? '');
        if (!$event_type) {
            return new WP_Error('scfs_invalid_event_type', __('Event type is required.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
        }
        $queued = $this->queue_event($event_type, (array) ($input['payload'] ?? array()));
        return rest_ensure_response(array('ok' => true, 'queued' => $queued, 'event_type' => $event_type));
    }

    public function queue_event($event_type, $payload = array()) {
        global $wpdb;
        $t = $this->table_names();
        $event_type = sanitize_text_field($event_type);
        $safe_payload = $this->privacy_minimize_payload((array) $payload);
        $event_id = isset($safe_payload['event_id']) ? sanitize_text_field($safe_payload['event_id']) : wp_generate_uuid4();
        $safe_payload['event_id'] = $event_id;
        $safe_payload['event_type'] = $event_type;
        $safe_payload['schema'] = 'scfs-help-desk-integration-event/1.0';
        $safe_payload['occurred_at'] = gmdate('c');
        $encoded = wp_json_encode($safe_payload, JSON_UNESCAPED_SLASHES);
        $subscriptions = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['subscriptions']} WHERE subscription_state='active' AND (%s LIKE REPLACE(event_pattern,'*','%%') OR event_pattern=%s)", $event_type, $event_type), ARRAY_A);
        $queued = 0;
        foreach ((array) $subscriptions as $subscription) {
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$t['deliveries']} (subscription_id,event_id,event_type,payload_json,payload_sha256,delivery_state,attempt_count,next_attempt_at,created_at,updated_at) VALUES (%d,%s,%s,%s,%s,'queued',0,%s,%s,%s)",
                absint($subscription['id']), $event_id, $event_type, $encoded, hash('sha256', $encoded), current_time('mysql', true), current_time('mysql', true), current_time('mysql', true)
            ));
            if ($inserted) { $queued++; }
        }
        return $queued;
    }

    public function capture_case_event($case_id, $event) {
        $this->queue_event('help_desk.case.event', array('case_id' => absint($case_id), 'event_summary' => sanitize_text_field(is_array($event) ? ($event['event_type'] ?? 'updated') : $event)));
    }

    public function capture_known_issue_event($issue_id, $state = '') {
        $this->queue_event('support.known_issue.updated', array('known_issue_id' => absint($issue_id), 'state' => sanitize_key($state)));
    }

    public function capture_release_event($release_id, $state = '') {
        $this->queue_event('support.release.updated', array('release_id' => absint($release_id), 'state' => sanitize_key($state)));
    }

    public function rest_deliveries(WP_REST_Request $request) {
        global $wpdb;
        $t = $this->table_names();
        $state = sanitize_key($request->get_param('state') ?: '');
        if ($state) {
            return rest_ensure_response($wpdb->get_results($wpdb->prepare("SELECT id,subscription_id,event_id,event_type,delivery_state,attempt_count,next_attempt_at,delivered_at,updated_at FROM {$t['deliveries']} WHERE delivery_state=%s ORDER BY id DESC LIMIT 200", $state), ARRAY_A));
        }
        return rest_ensure_response($wpdb->get_results("SELECT id,subscription_id,event_id,event_type,delivery_state,attempt_count,next_attempt_at,delivered_at,updated_at FROM {$t['deliveries']} ORDER BY id DESC LIMIT 200", ARRAY_A));
    }

    public function rest_retry_delivery(WP_REST_Request $request) {
        return $this->retry_delivery(absint($request['id']));
    }

    public function retry_delivery($delivery_id) {
        global $wpdb;
        $t = $this->table_names();
        $updated = $wpdb->update($t['deliveries'], array('delivery_state' => 'queued', 'next_attempt_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)), array('id' => absint($delivery_id)));
        if ($updated === false) {
            return new WP_Error('scfs_retry_failed', __('Could not queue delivery retry.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        $wpdb->update($t['dead_letters'], array('review_state' => 'retry_approved', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql', true)), array('delivery_id' => absint($delivery_id)));
        $this->audit(0, 'delivery.retry_approved', 'delivery', $delivery_id, 'A human reviewer approved a delivery retry.', array());
        return rest_ensure_response(array('ok' => true, 'delivery_id' => absint($delivery_id), 'human_retry_approved' => true));
    }

    public function rest_dead_letters() {
        global $wpdb;
        $t = $this->table_names();
        return rest_ensure_response($wpdb->get_results("SELECT d.id,d.delivery_id,d.reason_code,d.review_state,d.reviewed_at,d.created_at,w.event_type,w.payload_sha256 FROM {$t['dead_letters']} d JOIN {$t['deliveries']} w ON w.id=d.delivery_id ORDER BY d.id DESC LIMIT 200", ARRAY_A));
    }

    public function rest_create_external_link(WP_REST_Request $request) {
        return $this->create_external_link($request->get_json_params());
    }

    public function create_external_link($input) {
        global $wpdb;
        $t = $this->table_names();
        $integration_id = absint($input['integration_id'] ?? 0);
        $reference = sanitize_text_field($input['external_reference'] ?? '');
        if (!$integration_id || !$reference) {
            return new WP_Error('scfs_invalid_external_link', __('Integration and external reference are required.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
        }
        $ok = $wpdb->replace($t['external_links'], array(
            'case_id' => absint($input['case_id'] ?? 0),
            'object_type' => sanitize_key($input['object_type'] ?? 'case'),
            'object_id' => absint($input['object_id'] ?? 0),
            'integration_id' => $integration_id,
            'external_object_type' => sanitize_key($input['external_object_type'] ?? 'issue'),
            'external_reference' => $reference,
            'external_url' => esc_url_raw($input['external_url'] ?? ''),
            'link_state' => 'active',
            'relationship_type' => sanitize_key($input['relationship_type'] ?? 'related'),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            return new WP_Error('scfs_external_link_failed', __('Could not save external link.', 'sustainable-catalyst-feature-suggestions'), array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;
        $this->audit($integration_id, 'external_link.saved', 'external_link', $id, 'Human-authorized external system relationship saved.', array('external_reference' => $reference));
        return rest_ensure_response(array('ok' => true, 'id' => $id, 'automatic_external_issue_creation' => false));
    }

    public function rest_health() { return rest_ensure_response($this->health()); }

    public function health() {
        global $wpdb;
        $t = $this->table_names();
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['integrations']} WHERE state='active'");
        $queued = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['deliveries']} WHERE delivery_state IN ('queued','retry_wait')");
        $dead = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['dead_letters']} WHERE review_state='review_required'");
        return array('ok' => true, 'version' => self::VERSION, 'schema' => self::SCHEMA, 'active_integrations' => $active, 'queued_deliveries' => $queued, 'dead_letters_requiring_review' => $dead, 'public_inbound_webhook' => false, 'raw_secrets_stored' => false);
    }

    public function process_delivery_queue() {
        global $wpdb;
        $t = $this->table_names();
        $rows = $wpdb->get_results("SELECT d.*,s.endpoint_url,s.signing_credential_id,s.maximum_attempts,s.integration_id FROM {$t['deliveries']} d JOIN {$t['subscriptions']} s ON s.id=d.subscription_id WHERE d.delivery_state IN ('queued','retry_wait') AND (d.next_attempt_at IS NULL OR d.next_attempt_at<=UTC_TIMESTAMP()) ORDER BY d.id ASC LIMIT 25", ARRAY_A);
        foreach ((array) $rows as $row) {
            $this->deliver($row);
        }
    }

    private function deliver($row) {
        global $wpdb;
        $t = $this->table_names();
        $credential = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['credentials']} WHERE id=%d AND credential_state='active'", absint($row['signing_credential_id'])), ARRAY_A);
        $secret = $credential ? apply_filters('scfs_resolve_integration_secret', '', $credential['secret_reference'], $credential) : '';
        if (!$secret) {
            $this->record_attempt($row, 0, '', 'credential_unavailable', 'credential_unavailable', 0);
            $this->schedule_retry_or_dead_letter($row, 'credential_unavailable');
            return;
        }
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $row['payload_json'], $secret);
        $started = microtime(true);
        $response = wp_remote_post($row['endpoint_url'], array(
            'timeout' => absint($this->settings()['delivery_timeout_seconds']),
            'redirection' => 0,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'Sustainable-Catalyst-Support/' . self::VERSION,
                'X-SCFS-Event' => $row['event_type'],
                'X-SCFS-Event-ID' => $row['event_id'],
                'X-SCFS-Timestamp' => $timestamp,
                'X-SCFS-Signature' => 'sha256=' . $signature,
            ),
            'body' => $row['payload_json'],
        ));
        $duration = (int) round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) {
            $this->record_attempt($row, 0, '', 'transport_error', $response->get_error_code(), $duration);
            $this->schedule_retry_or_dead_letter($row, $response->get_error_code());
            return;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);
        if ($status >= 200 && $status < 300) {
            $this->record_attempt($row, $status, $response_body, 'delivered', '', $duration);
            $wpdb->update($t['deliveries'], array('delivery_state' => 'delivered', 'attempt_count' => absint($row['attempt_count']) + 1, 'delivered_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)), array('id' => absint($row['id'])));
            return;
        }
        $this->record_attempt($row, $status, $response_body, 'http_error', 'http_' . $status, $duration);
        $this->schedule_retry_or_dead_letter($row, 'http_' . $status);
    }

    private function record_attempt($row, $status, $response_body, $outcome, $error_code, $duration) {
        global $wpdb;
        $t = $this->table_names();
        $attempt = absint($row['attempt_count']) + 1;
        $wpdb->insert($t['attempts'], array(
            'delivery_id' => absint($row['id']),
            'attempt_number' => $attempt,
            'request_sha256' => hash('sha256', $row['payload_json']),
            'response_status' => absint($status),
            'response_sha256' => $response_body !== '' ? hash('sha256', $response_body) : '',
            'outcome' => sanitize_key($outcome),
            'error_code' => sanitize_key($error_code),
            'duration_ms' => absint($duration),
            'attempted_at' => current_time('mysql', true),
        ));
    }

    private function schedule_retry_or_dead_letter($row, $reason) {
        global $wpdb;
        $t = $this->table_names();
        $attempt = absint($row['attempt_count']) + 1;
        $maximum = min(12, max(1, absint($row['maximum_attempts'])));
        if ($attempt >= $maximum) {
            $wpdb->update($t['deliveries'], array('delivery_state' => 'dead_letter', 'attempt_count' => $attempt, 'updated_at' => current_time('mysql', true)), array('id' => absint($row['id'])));
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$t['dead_letters']} (delivery_id,reason_code,review_state,created_at) VALUES (%d,%s,'review_required',%s)", absint($row['id']), sanitize_key($reason), current_time('mysql', true)));
            return;
        }
        $settings = $this->settings();
        $delay = min(absint($settings['maximum_retry_seconds']), absint($settings['initial_retry_seconds']) * (2 ** max(0, $attempt - 1)));
        $next = gmdate('Y-m-d H:i:s', time() + $delay);
        $wpdb->update($t['deliveries'], array('delivery_state' => 'retry_wait', 'attempt_count' => $attempt, 'next_attempt_at' => $next, 'updated_at' => current_time('mysql', true)), array('id' => absint($row['id'])));
    }

    private function privacy_minimize_payload($payload) {
        $blocked = array('requester_name','requester_email','email','phone','message_body','private_message','internal_note','attachment_bytes','attachment_url','download_url','access_token','secret','password','authorization','cookie');
        $safe = array();
        foreach ($payload as $key => $value) {
            $normalized = sanitize_key($key);
            if (in_array($normalized, $blocked, true)) {
                continue;
            }
            if (is_array($value)) {
                $safe[$normalized] = $this->privacy_minimize_payload($value);
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

    private function sanitize_scopes($scopes) {
        $allowed = array('cases.read_summary','cases.write_relationships','known_issues.read','releases.read','support_articles.read','events.subscribe','deliveries.read','institutional_reports.read_aggregate','contact_engagement.handoff','monitoring.handoff','repository.link');
        return array_values(array_intersect($allowed, array_map('sanitize_text_field', (array) $scopes)));
    }

    private function audit($integration_id, $event_type, $object_type, $object_id, $summary, $evidence) {
        global $wpdb;
        $t = $this->table_names();
        $encoded = wp_json_encode($this->privacy_minimize_payload((array) $evidence));
        $wpdb->insert($t['audit'], array(
            'integration_id' => absint($integration_id),
            'actor_user_id' => get_current_user_id(),
            'event_type' => sanitize_text_field($event_type),
            'object_type' => sanitize_key($object_type),
            'object_id' => absint($object_id),
            'event_summary' => sanitize_text_field($summary),
            'evidence_json' => $encoded,
            'evidence_sha256' => hash('sha256', $encoded),
            'created_at' => current_time('mysql', true),
        ));
    }

    public function render_admin_page() {
        if (!$this->can_view()) { wp_die(esc_html__('You do not have permission to view integrations.', 'sustainable-catalyst-feature-suggestions')); }
        $health = $this->health();
        ?>
        <div class="wrap scfs-integration-console">
            <h1><?php esc_html_e('API, Webhooks, and External Integrations', 'sustainable-catalyst-feature-suggestions'); ?></h1>
            <p><?php esc_html_e('Govern scoped integrations, signed outbound events, delivery retries, dead letters, and external system relationships. Private case content and raw secrets remain outside public interfaces.', 'sustainable-catalyst-feature-suggestions'); ?></p>
            <div class="scfs-integration-metrics">
                <article><strong><?php echo esc_html((string) $health['active_integrations']); ?></strong><span><?php esc_html_e('Active integrations', 'sustainable-catalyst-feature-suggestions'); ?></span></article>
                <article><strong><?php echo esc_html((string) $health['queued_deliveries']); ?></strong><span><?php esc_html_e('Queued deliveries', 'sustainable-catalyst-feature-suggestions'); ?></span></article>
                <article><strong><?php echo esc_html((string) $health['dead_letters_requiring_review']); ?></strong><span><?php esc_html_e('Dead letters', 'sustainable-catalyst-feature-suggestions'); ?></span></article>
            </div>
            <section class="scfs-integration-boundary"><h2><?php esc_html_e('Governance boundary', 'sustainable-catalyst-feature-suggestions'); ?></h2><p><?php esc_html_e('Outbound payloads are privacy-minimized and signed. Inbound public webhooks, raw credential storage, automatic external issue creation, automatic case transitions, and customer communication are disabled.', 'sustainable-catalyst-feature-suggestions'); ?></p></section>
        </div>
        <?php
    }

    public function register_cli() {
        WP_CLI::add_command('scfs help-desk integrations status', function () { WP_CLI::success(wp_json_encode($this->health(), JSON_PRETTY_PRINT)); });
        WP_CLI::add_command('scfs help-desk integrations list', function () { global $wpdb; $t=$this->table_names(); WP_CLI::print_value($wpdb->get_results("SELECT integration_key,integration_type,display_name,state FROM {$t['integrations']} ORDER BY display_name", ARRAY_A), array('format'=>'table')); });
        WP_CLI::add_command('scfs help-desk integrations deliveries', function () { global $wpdb; $t=$this->table_names(); WP_CLI::print_value($wpdb->get_results("SELECT id,event_type,delivery_state,attempt_count,next_attempt_at FROM {$t['deliveries']} ORDER BY id DESC LIMIT 100", ARRAY_A), array('format'=>'table')); });
        WP_CLI::add_command('scfs help-desk integrations retry', function ($args) { if (empty($args[0])) WP_CLI::error('Delivery ID required.'); $result=$this->retry_delivery(absint($args[0])); if (is_wp_error($result)) WP_CLI::error($result->get_error_message()); WP_CLI::success('Delivery retry queued for human-authorized processing.'); });
        WP_CLI::add_command('scfs help-desk integrations dispatch', function ($args, $assoc) { $type=$assoc['event']??($args[0]??''); if (!$type) WP_CLI::error('Use --event=<event.type>.'); $queued=$this->queue_event($type, array('source'=>'wp-cli','manual_authorization'=>true)); WP_CLI::success(sprintf('%d delivery records queued.', $queued)); });
        WP_CLI::add_command('scfs help-desk integrations dead-letters', function () { global $wpdb; $t=$this->table_names(); WP_CLI::print_value($wpdb->get_results("SELECT id,delivery_id,reason_code,review_state,created_at FROM {$t['dead_letters']} ORDER BY id DESC LIMIT 100", ARRAY_A), array('format'=>'table')); });
    }
}
