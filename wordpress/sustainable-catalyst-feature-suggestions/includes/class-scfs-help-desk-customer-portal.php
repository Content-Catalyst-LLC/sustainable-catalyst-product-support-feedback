<?php
/**
 * Help Desk Customer Portal and Conversations.
 *
 * Provides a secure, token-to-session requester portal above the private
 * help-desk case foundation. Access tokens and session secrets are stored as
 * SHA-256 hashes only. Public support records remain public; private case
 * identity, messages, attachments, and consent remain private.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Customer_Portal {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-customer-portal/1.0';
    const DB_VERSION = '1.2.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_portal_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_portal_settings';
    const PORTAL_PAGE_OPTION = 'scfs_help_desk_portal_page_id';
    const ADMIN_PAGE = 'scfs-help-desk-customer-portal';
    const SHORTCODE = 'scfs_help_desk_customer_portal';
    const SHORTCODE_ALIAS = 'scfs_customer_support_portal';
    const LINK_QUERY_ARG = 'scfs_case_access';
    const SESSION_COOKIE = 'scfs_help_desk_portal_session';
    const NONCE_ACTION = 'scfs_help_desk_portal_action';
    const CRON_HOOK = 'scfs_help_desk_portal_maintenance_daily';

    private static $instance = null;
    private $rest_session = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 6);
        add_action('init', array($this, 'register_shortcodes'), 20);
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('template_redirect', array($this, 'bootstrap_session_from_link'), 1);
        add_action('template_redirect', array($this, 'send_private_response_headers'), 2);
        add_action('admin_menu', array($this, 'register_admin_page'), 48);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('scfs_help_desk_case_workspace_after', array($this, 'render_agent_portal_panel'));
        add_action('admin_post_scfs_help_desk_portal_issue_link', array($this, 'admin_issue_link'));
        add_action('admin_post_scfs_help_desk_portal_revoke_link', array($this, 'admin_revoke_link'));
        add_action('admin_post_scfs_help_desk_portal_reply', array($this, 'handle_portal_reply'));
        add_action('admin_post_nopriv_scfs_help_desk_portal_reply', array($this, 'handle_portal_reply'));
        add_action('admin_post_scfs_help_desk_portal_transition', array($this, 'handle_portal_transition'));
        add_action('admin_post_nopriv_scfs_help_desk_portal_transition', array($this, 'handle_portal_transition'));
        add_action('admin_post_scfs_help_desk_portal_satisfaction', array($this, 'handle_portal_satisfaction'));
        add_action('admin_post_nopriv_scfs_help_desk_portal_satisfaction', array($this, 'handle_portal_satisfaction'));
        add_action('admin_post_scfs_help_desk_portal_logout', array($this, 'handle_portal_logout'));
        add_action('admin_post_nopriv_scfs_help_desk_portal_logout', array($this, 'handle_portal_logout'));
        add_action(self::CRON_HOOK, array($this, 'daily_maintenance'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk portal issue-link', array($this, 'cli_issue_link'));
            WP_CLI::add_command('scfs help-desk portal revoke-case', array($this, 'cli_revoke_case'));
            WP_CLI::add_command('scfs help-desk portal health', array($this, 'cli_health'));
        }
    }

    public static function activate() {
        $instance = self::instance();
        $instance->install_schema();
        $instance->install_capabilities();
        $instance->ensure_portal_page();
        if (!get_option(self::SETTINGS_OPTION)) {
            add_option(self::SETTINGS_OPTION, $instance->default_settings(), '', false);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 2400, 'daily', self::CRON_HOOK);
        }
    }

    public function ensure_portal_page() {
        $existing = get_page_by_path('support/cases', OBJECT, 'page');
        if ($existing instanceof WP_Post) {
            update_option(self::PORTAL_PAGE_OPTION, (int) $existing->ID, false);
            return (int) $existing->ID;
        }
        $support = get_page_by_path('support', OBJECT, 'page');
        $page_id = wp_insert_post(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => __('My Support Cases', 'sustainable-catalyst-feature-suggestions'),
            'post_name' => 'cases',
            'post_parent' => $support instanceof WP_Post ? (int) $support->ID : 0,
            'post_content' => '[' . self::SHORTCODE . ']',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ), true);
        if (!is_wp_error($page_id)) {
            update_option(self::PORTAL_PAGE_OPTION, (int) $page_id, false);
            return (int) $page_id;
        }
        return 0;
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
            'portal_path' => '/support/cases/',
            'access_link_hours' => 168,
            'session_hours' => 24,
            'reopen_window_days' => 30,
            'max_link_uses' => 5,
            'notification_authority' => 'contact-engagement',
            'identity_authority' => 'contact-engagement',
            'attachment_authority' => 'contact-engagement',
            'send_email_directly' => '0',
            'allow_requester_close' => '1',
            'allow_requester_reopen' => '1',
            'allow_satisfaction_feedback' => '1',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'tokens' => $wpdb->prefix . 'scfs_help_desk_portal_tokens',
            'sessions' => $wpdb->prefix . 'scfs_help_desk_portal_sessions',
            'events' => $wpdb->prefix . 'scfs_help_desk_portal_events',
            'satisfaction' => $wpdb->prefix . 'scfs_help_desk_portal_satisfaction',
            'notifications' => $wpdb->prefix . 'scfs_help_desk_portal_notifications',
        );
    }

    public function install_schema() {
        global $wpdb;
        $tables = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tokens = "CREATE TABLE {$tables['tokens']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            requester_ref_hash char(64) NOT NULL DEFAULT '',
            scope_json longtext NOT NULL,
            expires_at datetime NOT NULL,
            max_uses int(10) unsigned NOT NULL DEFAULT 5,
            use_count int(10) unsigned NOT NULL DEFAULT 0,
            last_used_at datetime NULL,
            revoked_at datetime NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY case_id (case_id),
            KEY expires_at (expires_at),
            KEY revoked_at (revoked_at)
        ) $charset;";

        $sessions = "CREATE TABLE {$tables['sessions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_id bigint(20) unsigned NOT NULL,
            case_id bigint(20) unsigned NOT NULL,
            session_hash char(64) NOT NULL,
            expires_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            revoked_at datetime NULL,
            created_at datetime NOT NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_hash (session_hash),
            KEY token_id (token_id),
            KEY case_id (case_id),
            KEY expires_at (expires_at)
        ) $charset;";

        $events = "CREATE TABLE {$tables['events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            token_id bigint(20) unsigned NOT NULL DEFAULT 0,
            session_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(80) NOT NULL,
            request_fingerprint_hash char(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY case_id_created (case_id,created_at),
            KEY event_type (event_type),
            KEY token_id (token_id)
        ) $charset;";

        $satisfaction = "CREATE TABLE {$tables['satisfaction']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            token_id bigint(20) unsigned NOT NULL DEFAULT 0,
            rating tinyint(3) unsigned NOT NULL,
            resolved tinyint(1) unsigned NOT NULL DEFAULT 0,
            feedback_reason varchar(80) NOT NULL DEFAULT '',
            feedback_text text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY case_token (case_id,token_id),
            KEY rating (rating),
            KEY updated_at (updated_at)
        ) $charset;";

        $notifications = "CREATE TABLE {$tables['notifications']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            notification_type varchar(80) NOT NULL,
            recipient_ref varchar(191) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT 'queued',
            subject text NOT NULL,
            body_template_key varchar(120) NOT NULL DEFAULT '',
            delivery_authority varchar(80) NOT NULL DEFAULT 'contact-engagement',
            external_message_ref varchar(191) NOT NULL DEFAULT '',
            attempt_count int(10) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NULL,
            sent_at datetime NULL,
            created_at datetime NOT NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY case_id (case_id),
            KEY status_next (status,next_attempt_at),
            KEY recipient_ref (recipient_ref)
        ) $charset;";

        dbDelta($tokens);
        dbDelta($sessions);
        dbDelta($events);
        dbDelta($satisfaction);
        dbDelta($notifications);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public function maybe_upgrade_schema() {
        if ((string) get_option(self::DB_VERSION_OPTION, '') !== self::DB_VERSION) {
            $this->install_schema();
            $this->install_capabilities();
        }
    }

    public function install_capabilities() {
        foreach (array('administrator', 'editor') as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            $role->add_cap('scfs_manage_help_desk_portal');
            $role->add_cap('scfs_issue_help_desk_portal_links');
            $role->add_cap('scfs_view_help_desk_portal_audit');
        }
    }

    public function register_shortcodes() {
        add_shortcode(self::SHORTCODE, array($this, 'render_portal_shortcode'));
        add_shortcode(self::SHORTCODE_ALIAS, array($this, 'render_portal_shortcode'));
    }

    public function register_assets() {
        wp_register_style(
            'scfs-help-desk-customer-portal',
            plugins_url('../assets/help-desk-customer-portal.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . '../assets/help-desk-customer-portal.css')
        );
        wp_register_script(
            'scfs-help-desk-customer-portal',
            plugins_url('../assets/help-desk-customer-portal.js', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . '../assets/help-desk-customer-portal.js'),
            true
        );
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false && strpos((string) $hook, 'scfs-help-desk-agent-workspace') === false) {
            return;
        }
        wp_enqueue_style('scfs-help-desk-customer-portal');
    }

    private function hash_secret($value) {
        return hash('sha256', (string) $value);
    }

    private function random_secret() {
        try {
            return bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            return wp_generate_password(64, false, false);
        }
    }

    private function utc_timestamp($hours) {
        return gmdate('Y-m-d H:i:s', time() + (absint($hours) * HOUR_IN_SECONDS));
    }

    private function portal_url($token = '') {
        $path = '/' . trim((string) $this->settings()['portal_path'], '/') . '/';
        $url = home_url($path);
        if ($token !== '') {
            $url = add_query_arg(self::LINK_QUERY_ARG, $token, $url);
        }
        return $url;
    }

    public function issue_access_link($case_id, $requester_ref = '', $args = array(), $actor_user_id = 0) {
        global $wpdb;
        $foundation = $this->foundation();
        $case = $foundation ? $foundation->get_case($case_id) : null;
        if (!$case) {
            return new WP_Error('scfs_portal_case_not_found', 'Case not found.');
        }
        $requester_ref = sanitize_text_field($requester_ref ?: $case['requester_ref']);
        if ($requester_ref === '') {
            return new WP_Error('scfs_portal_requester_ref_required', 'A requester reference is required before portal access can be issued.');
        }
        if ($case['consent_state'] === 'withdrawn') {
            return new WP_Error('scfs_portal_consent_withdrawn', 'Portal access cannot be issued after consent is withdrawn.');
        }
        $args = wp_parse_args($args, array(
            'hours' => absint($this->settings()['access_link_hours']),
            'max_uses' => absint($this->settings()['max_link_uses']),
            'scope' => array('view', 'reply', 'transition', 'satisfaction'),
            'metadata' => array(),
        ));
        $scope = array_values(array_intersect(array('view', 'reply', 'transition', 'satisfaction'), array_map('sanitize_key', (array) $args['scope'])));
        if (!in_array('view', $scope, true)) {
            $scope[] = 'view';
        }
        $secret = $this->random_secret();
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($tables['tokens'], array(
            'case_id' => absint($case_id),
            'token_hash' => $this->hash_secret($secret),
            'requester_ref_hash' => $this->hash_secret($requester_ref),
            'scope_json' => wp_json_encode($scope),
            'expires_at' => $this->utc_timestamp(max(1, absint($args['hours']))),
            'max_uses' => max(1, absint($args['max_uses'])),
            'use_count' => 0,
            'created_by' => absint($actor_user_id),
            'created_at' => $now,
            'metadata_json' => wp_json_encode(is_array($args['metadata']) ? $args['metadata'] : array()),
        ), array('%d','%s','%s','%s','%s','%d','%d','%d','%s','%s'));
        if (!$inserted) {
            return new WP_Error('scfs_portal_token_insert_failed', 'Unable to issue the secure portal link.');
        }
        $token_id = (int) $wpdb->insert_id;
        $foundation->add_event($case_id, 'portal_access_issued', array(
            'portal_token_id' => $token_id,
            'expires_at' => $this->utc_timestamp(max(1, absint($args['hours']))),
            'scope' => $scope,
            'raw_token_stored' => false,
        ), $actor_user_id);
        $this->record_portal_event($case_id, $token_id, 0, 'access_link_issued', array('scope' => $scope));
        $this->queue_notification($case_id, 'portal_access_link', $requester_ref, 'Your Sustainable Catalyst support case', 'portal-access-link', array(
            'portal_token_id' => $token_id,
            'case_number' => $case['case_number'],
            'raw_token_stored' => false,
        ));
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'token_id' => $token_id,
            'case_id' => absint($case_id),
            'case_number' => $case['case_number'],
            'portal_url' => $this->portal_url($secret),
            'expires_at' => $this->utc_timestamp(max(1, absint($args['hours']))),
            'scope' => $scope,
            'raw_token_stored' => false,
            'notification_authority' => 'contact-engagement',
        );
    }

    private function token_record($secret) {
        global $wpdb;
        $tables = $this->table_names();
        if (!is_string($secret) || strlen($secret) < 32) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['tokens']} WHERE token_hash=%s LIMIT 1",
            $this->hash_secret($secret)
        ), ARRAY_A);
    }

    public function validate_access_token($secret, $required_scope = 'view') {
        $record = $this->token_record($secret);
        if (!$record) {
            return new WP_Error('scfs_portal_access_invalid', 'This secure support link is invalid or no longer available.');
        }
        $now = current_time('mysql', true);
        if (!empty($record['revoked_at']) || $record['expires_at'] <= $now || (int) $record['use_count'] >= (int) $record['max_uses']) {
            return new WP_Error('scfs_portal_access_expired', 'This secure support link is invalid or no longer available.');
        }
        $scope = json_decode((string) $record['scope_json'], true);
        $scope = is_array($scope) ? $scope : array();
        if ($required_scope && !in_array($required_scope, $scope, true)) {
            return new WP_Error('scfs_portal_scope_denied', 'This secure support link does not permit that action.');
        }
        $record['scope'] = $scope;
        return $record;
    }

    private function create_session($token) {
        global $wpdb;
        $tables = $this->table_names();
        $secret = $this->random_secret();
        $now = current_time('mysql', true);
        $expires_at = $this->utc_timestamp(max(1, absint($this->settings()['session_hours'])));
        $wpdb->insert($tables['sessions'], array(
            'token_id' => absint($token['id']),
            'case_id' => absint($token['case_id']),
            'session_hash' => $this->hash_secret($secret),
            'expires_at' => $expires_at,
            'last_seen_at' => $now,
            'created_at' => $now,
            'metadata_json' => wp_json_encode(array('cookie_only' => true)),
        ), array('%d','%d','%s','%s','%s','%s','%s'));
        $session_id = (int) $wpdb->insert_id;
        if (!$session_id) {
            return new WP_Error('scfs_portal_session_failed', 'Unable to establish a secure portal session.');
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$tables['tokens']} SET use_count=use_count+1,last_used_at=%s WHERE id=%d",
            $now,
            absint($token['id'])
        ));
        $this->record_portal_event($token['case_id'], $token['id'], $session_id, 'session_created');
        return array('secret' => $secret, 'id' => $session_id, 'expires_at' => $expires_at);
    }

    private function set_session_cookie($secret, $expires_at) {
        $expires = strtotime($expires_at . ' UTC');
        $secure = is_ssl();
        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::SESSION_COOKIE, $secret, array(
                'expires' => $expires,
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        } else {
            setcookie(self::SESSION_COOKIE, $secret, $expires, (COOKIEPATH ?: '/') . '; samesite=Lax', COOKIE_DOMAIN, $secure, true);
        }
        $_COOKIE[self::SESSION_COOKIE] = $secret;
    }

    private function clear_session_cookie() {
        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::SESSION_COOKIE, '', array(
                'expires' => time() - HOUR_IN_SECONDS,
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        } else {
            setcookie(self::SESSION_COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        unset($_COOKIE[self::SESSION_COOKIE]);
    }

    public function bootstrap_session_from_link() {
        if (empty($_GET[self::LINK_QUERY_ARG])) {
            return;
        }
        $secret = sanitize_text_field(wp_unslash($_GET[self::LINK_QUERY_ARG]));
        $token = $this->validate_access_token($secret, 'view');
        if (is_wp_error($token)) {
            $this->clear_session_cookie();
            wp_safe_redirect(add_query_arg('portal_error', 'invalid', $this->portal_url()));
            exit;
        }
        $session = $this->create_session($token);
        if (is_wp_error($session)) {
            wp_safe_redirect(add_query_arg('portal_error', 'session', $this->portal_url()));
            exit;
        }
        $this->set_session_cookie($session['secret'], $session['expires_at']);
        wp_safe_redirect($this->portal_url());
        exit;
    }

    private function session_record($secret = '') {
        global $wpdb;
        $tables = $this->table_names();
        $secret = $secret ?: (isset($_COOKIE[self::SESSION_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE])) : '');
        if (strlen($secret) < 32) {
            return null;
        }
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*,t.scope_json,t.revoked_at AS token_revoked_at,t.expires_at AS token_expires_at,t.requester_ref_hash
             FROM {$tables['sessions']} s INNER JOIN {$tables['tokens']} t ON t.id=s.token_id
             WHERE s.session_hash=%s LIMIT 1",
            $this->hash_secret($secret)
        ), ARRAY_A);
        if (!$record) {
            return null;
        }
        $now = current_time('mysql', true);
        if (!empty($record['revoked_at']) || !empty($record['token_revoked_at']) || $record['expires_at'] <= $now || $record['token_expires_at'] <= $now) {
            return null;
        }
        $record['scope'] = json_decode((string) $record['scope_json'], true);
        $record['scope'] = is_array($record['scope']) ? $record['scope'] : array();
        $wpdb->update($tables['sessions'], array('last_seen_at' => $now), array('id' => absint($record['id'])), array('%s'), array('%d'));
        return $record;
    }

    private function require_session($scope = 'view') {
        $session = $this->session_record();
        if (!$session || !in_array($scope, $session['scope'], true)) {
            return new WP_Error('scfs_portal_session_denied', 'Your secure portal session has expired or does not permit that action.');
        }
        return $session;
    }

    public function send_private_response_headers() {
        if (!$this->session_record() && empty($_GET[self::LINK_QUERY_ARG])) {
            return;
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
    }

    private function request_fingerprint_hash() {
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])) : '';
        return hash('sha256', wp_salt('auth') . '|' . $agent . '|' . $language);
    }

    private function record_portal_event($case_id, $token_id, $session_id, $event_type, $metadata = array()) {
        global $wpdb;
        $tables = $this->table_names();
        $wpdb->insert($tables['events'], array(
            'case_id' => absint($case_id),
            'token_id' => absint($token_id),
            'session_id' => absint($session_id),
            'event_type' => sanitize_key($event_type),
            'request_fingerprint_hash' => $this->request_fingerprint_hash(),
            'created_at' => current_time('mysql', true),
            'metadata_json' => wp_json_encode(is_array($metadata) ? $metadata : array()),
        ), array('%d','%d','%d','%s','%s','%s','%s'));
        return (int) $wpdb->insert_id;
    }

    public function queue_notification($case_id, $type, $recipient_ref, $subject, $template_key, $metadata = array()) {
        global $wpdb;
        $tables = $this->table_names();
        $authority = sanitize_key($this->settings()['notification_authority']);
        if ($authority !== 'contact-engagement') {
            $authority = 'contact-engagement';
        }
        $wpdb->insert($tables['notifications'], array(
            'case_id' => absint($case_id),
            'notification_type' => sanitize_key($type),
            'recipient_ref' => sanitize_text_field($recipient_ref),
            'status' => 'queued',
            'subject' => sanitize_text_field($subject),
            'body_template_key' => sanitize_key($template_key),
            'delivery_authority' => $authority,
            'attempt_count' => 0,
            'created_at' => current_time('mysql', true),
            'metadata_json' => wp_json_encode(is_array($metadata) ? $metadata : array()),
        ), array('%d','%s','%s','%s','%s','%s','%s','%d','%s','%s'));
        return (int) $wpdb->insert_id;
    }

    public function participant_messages($case_id) {
        global $wpdb;
        $foundation = $this->foundation();
        if (!$foundation) {
            return array();
        }
        $table = $foundation->table_names()['messages'];
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id,message_type,body,body_format,created_at,edited_at,redacted_at FROM {$table}
             WHERE case_id=%d AND visibility='participants' AND redacted_at IS NULL ORDER BY created_at ASC,id ASC",
            absint($case_id)
        ), ARRAY_A);
    }

    private function public_relationships($case_id) {
        global $wpdb;
        $foundation = $this->foundation();
        if (!$foundation) {
            return array();
        }
        $table = $foundation->table_names()['relationships'];
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT relationship_type,related_record_type,related_record_id,related_record_key FROM {$table}
             WHERE case_id=%d AND public_context_only=1 ORDER BY created_at DESC,id DESC",
            absint($case_id)
        ), ARRAY_A);
    }

    public function portal_case_payload($session = null) {
        $session = $session ?: $this->session_record();
        $foundation = $this->foundation();
        $case = $session && $foundation ? $foundation->get_case($session['case_id']) : null;
        if (!$case) {
            return null;
        }
        $payload = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'case' => array(
                'case_number' => $case['case_number'],
                'subject' => $case['subject'],
                'product' => $case['product'],
                'product_version' => $case['product_version'],
                'component' => $case['component'],
                'case_type' => $case['case_type'],
                'status' => $case['status'],
                'priority' => $case['priority'],
                'created_at' => $case['created_at'],
                'updated_at' => $case['updated_at'],
                'resolved_at' => $case['resolved_at'],
                'closed_at' => $case['closed_at'],
            ),
            'messages' => $this->participant_messages($case['id']),
            'related_public_records' => $this->public_relationships($case['id']),
            'permissions' => array(
                'reply' => in_array('reply', $session['scope'], true),
                'transition' => in_array('transition', $session['scope'], true),
                'satisfaction' => in_array('satisfaction', $session['scope'], true),
            ),
            'privacy' => array(
                'internal_notes_exposed' => false,
                'requester_identity_exposed' => false,
                'organization_identity_exposed' => false,
                'private_attachment_bytes_exposed' => false,
                'raw_access_token_stored' => false,
            ),
        );
        return apply_filters('scfs_help_desk_portal_case_payload', $payload, $session);
    }

    public function add_requester_reply($session, $body) {
        $foundation = $this->foundation();
        if (!$foundation || !$session || !in_array('reply', $session['scope'], true)) {
            return new WP_Error('scfs_portal_reply_denied', 'Reply access is not available.');
        }
        $case = $foundation->get_case($session['case_id']);
        if (!$case) {
            return new WP_Error('scfs_portal_case_not_found', 'Case not found.');
        }
        if (in_array($case['status'], array('closed', 'cancelled', 'duplicate'), true)) {
            return new WP_Error('scfs_portal_case_not_replyable', 'This case is not currently open for replies.');
        }
        $message_id = $foundation->add_message($case['id'], array(
            'author_ref' => $case['requester_ref'],
            'message_type' => 'requester_message',
            'visibility' => 'participants',
            'body' => wp_kses_post($body),
            'body_format' => 'html',
            'metadata' => array('portal_session_id' => absint($session['id'])),
        ), 0);
        if (is_wp_error($message_id)) {
            return $message_id;
        }
        if ($case['status'] === 'waiting_requester') {
            $foundation->transition_case($case['id'], 'open', 'Requester replied through the secure customer portal.', 0);
        }
        $foundation->add_event($case['id'], 'portal_requester_reply', array('message_id' => $message_id), 0, 'portal-requester');
        $this->record_portal_event($case['id'], $session['token_id'], $session['id'], 'requester_reply_added', array('message_id' => $message_id));
        $this->queue_notification($case['id'], 'requester_reply', $case['assigned_team'], 'Requester replied to ' . $case['case_number'], 'requester-reply', array('message_id' => $message_id));
        return $message_id;
    }

    private function can_reopen_case($case) {
        if (!in_array($case['status'], array('resolved', 'closed'), true)) {
            return false;
        }
        $reference = $case['closed_at'] ?: $case['resolved_at'];
        if (!$reference) {
            return true;
        }
        $window = max(1, absint($this->settings()['reopen_window_days'])) * DAY_IN_SECONDS;
        return strtotime($reference . ' UTC') >= (time() - $window);
    }

    public function requester_transition($session, $operation, $reason = '') {
        $foundation = $this->foundation();
        if (!$foundation || !$session || !in_array('transition', $session['scope'], true)) {
            return new WP_Error('scfs_portal_transition_denied', 'Status access is not available.');
        }
        $case = $foundation->get_case($session['case_id']);
        if (!$case) {
            return new WP_Error('scfs_portal_case_not_found', 'Case not found.');
        }
        $operation = sanitize_key($operation);
        $target = '';
        if ($operation === 'confirm_resolved' && $this->settings()['allow_requester_close'] === '1') {
            $target = $case['status'] === 'resolved' ? 'closed' : 'resolved';
        } elseif ($operation === 'reopen' && $this->settings()['allow_requester_reopen'] === '1' && $this->can_reopen_case($case)) {
            $target = 'open';
        } else {
            return new WP_Error('scfs_portal_transition_not_allowed', 'That requester status action is not available.');
        }
        $result = $foundation->transition_case($case['id'], $target, sanitize_textarea_field($reason ?: 'Requester action through secure portal.'), 0);
        if (is_wp_error($result)) {
            return $result;
        }
        $foundation->add_event($case['id'], 'portal_requester_transition', array(
            'operation' => $operation,
            'from' => $case['status'],
            'to' => $target,
        ), 0, 'portal-requester');
        $this->record_portal_event($case['id'], $session['token_id'], $session['id'], 'requester_transition', array('operation' => $operation, 'to' => $target));
        return $result;
    }

    public function submit_satisfaction($session, $input) {
        global $wpdb;
        if (!$session || !in_array('satisfaction', $session['scope'], true) || $this->settings()['allow_satisfaction_feedback'] !== '1') {
            return new WP_Error('scfs_portal_satisfaction_denied', 'Satisfaction feedback is not available.');
        }
        $input = is_array($input) ? $input : array();
        $rating = min(5, max(1, absint($input['rating'] ?? 0)));
        $reason = sanitize_key($input['feedback_reason'] ?? '');
        $allowed_reasons = array('', 'resolved', 'partially_resolved', 'not_resolved', 'communication', 'documentation', 'other');
        if (!in_array($reason, $allowed_reasons, true)) {
            $reason = 'other';
        }
        $resolved = !empty($input['resolved']) ? 1 : 0;
        $text = sanitize_textarea_field($input['feedback_text'] ?? '');
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $wpdb->replace($tables['satisfaction'], array(
            'case_id' => absint($session['case_id']),
            'token_id' => absint($session['token_id']),
            'rating' => $rating,
            'resolved' => $resolved,
            'feedback_reason' => $reason,
            'feedback_text' => $text,
            'created_at' => $now,
            'updated_at' => $now,
        ), array('%d','%d','%d','%d','%s','%s','%s','%s'));
        $id = (int) $wpdb->insert_id;
        $foundation = $this->foundation();
        if ($foundation) {
            $foundation->add_event($session['case_id'], 'portal_satisfaction_submitted', array(
                'rating' => $rating,
                'resolved' => (bool) $resolved,
                'feedback_reason' => $reason,
                'feedback_text_public' => false,
            ), 0, 'portal-requester');
        }
        $this->record_portal_event($session['case_id'], $session['token_id'], $session['id'], 'satisfaction_submitted', array('rating' => $rating, 'resolved' => (bool) $resolved));
        return array('id' => $id, 'rating' => $rating, 'resolved' => (bool) $resolved);
    }

    public function revoke_case_access($case_id, $actor_user_id = 0, $reason = '') {
        global $wpdb;
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $token_ids = (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['tokens']} WHERE case_id=%d AND revoked_at IS NULL", absint($case_id)));
        if ($token_ids) {
            $placeholders = implode(',', array_fill(0, count($token_ids), '%d'));
            $query = $wpdb->prepare("UPDATE {$tables['sessions']} SET revoked_at=%s WHERE token_id IN ($placeholders) AND revoked_at IS NULL", array_merge(array($now), array_map('absint', $token_ids)));
            $wpdb->query($query);
        }
        $wpdb->update($tables['tokens'], array('revoked_at' => $now), array('case_id' => absint($case_id)), array('%s'), array('%d'));
        $foundation = $this->foundation();
        if ($foundation) {
            $foundation->add_event($case_id, 'portal_access_revoked', array('reason' => sanitize_textarea_field($reason)), $actor_user_id);
        }
        return count($token_ids);
    }

    private function portal_nonce_field($session) {
        wp_nonce_field(self::NONCE_ACTION . ':' . absint($session['id']), 'scfs_portal_nonce');
    }

    private function verify_portal_request($scope) {
        $session = $this->require_session($scope);
        if (is_wp_error($session)) {
            return $session;
        }
        $nonce = isset($_POST['scfs_portal_nonce']) ? sanitize_text_field(wp_unslash($_POST['scfs_portal_nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION . ':' . absint($session['id']))) {
            return new WP_Error('scfs_portal_nonce_failed', 'The secure portal request could not be verified.');
        }
        return $session;
    }

    private function portal_redirect($notice = '') {
        $url = $this->portal_url();
        if ($notice) {
            $url = add_query_arg('portal_notice', sanitize_key($notice), $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public function handle_portal_reply() {
        $session = $this->verify_portal_request('reply');
        if (is_wp_error($session)) {
            $this->portal_redirect('denied');
        }
        $result = $this->add_requester_reply($session, wp_unslash($_POST['body'] ?? ''));
        $this->portal_redirect(is_wp_error($result) ? 'reply-error' : 'reply-added');
    }

    public function handle_portal_transition() {
        $session = $this->verify_portal_request('transition');
        if (is_wp_error($session)) {
            $this->portal_redirect('denied');
        }
        $result = $this->requester_transition($session, wp_unslash($_POST['operation'] ?? ''), wp_unslash($_POST['reason'] ?? ''));
        $this->portal_redirect(is_wp_error($result) ? 'transition-error' : 'status-updated');
    }

    public function handle_portal_satisfaction() {
        $session = $this->verify_portal_request('satisfaction');
        if (is_wp_error($session)) {
            $this->portal_redirect('denied');
        }
        $result = $this->submit_satisfaction($session, wp_unslash($_POST));
        $this->portal_redirect(is_wp_error($result) ? 'feedback-error' : 'feedback-recorded');
    }

    public function handle_portal_logout() {
        $session = $this->session_record();
        if ($session) {
            global $wpdb;
            $wpdb->update($this->table_names()['sessions'], array('revoked_at' => current_time('mysql', true)), array('id' => absint($session['id'])), array('%s'), array('%d'));
            $this->record_portal_event($session['case_id'], $session['token_id'], $session['id'], 'session_logged_out');
        }
        $this->clear_session_cookie();
        wp_safe_redirect($this->portal_url());
        exit;
    }

    public function render_portal_shortcode() {
        wp_enqueue_style('scfs-help-desk-customer-portal');
        wp_enqueue_script('scfs-help-desk-customer-portal');
        $session = $this->session_record();
        ob_start();
        echo '<section class="scfs-customer-portal" data-scfs-customer-portal>';
        echo '<header class="scfs-customer-portal__masthead"><p class="scfs-customer-portal__eyebrow">' . esc_html__('Sustainable Catalyst Support', 'sustainable-catalyst-feature-suggestions') . '</p><h1>' . esc_html__('Your support case', 'sustainable-catalyst-feature-suggestions') . '</h1><p>' . esc_html__('Review the participant-visible conversation, reply securely, and confirm whether the issue has been resolved.', 'sustainable-catalyst-feature-suggestions') . '</p></header>';
        if (!$session) {
            echo '<div class="scfs-customer-portal__notice"><h2>' . esc_html__('Secure access required', 'sustainable-catalyst-feature-suggestions') . '</h2><p>' . esc_html__('Open the secure link provided by Sustainable Catalyst Support. For privacy, this page does not reveal whether a case exists without a valid access session.', 'sustainable-catalyst-feature-suggestions') . '</p><p><a href="' . esc_url(home_url('/support/')) . '">' . esc_html__('Return to the Support Center', 'sustainable-catalyst-feature-suggestions') . ' →</a></p></div>';
            echo '</section>';
            return ob_get_clean();
        }
        $payload = $this->portal_case_payload($session);
        if (!$payload) {
            echo '<div class="scfs-customer-portal__notice"><p>' . esc_html__('This case is no longer available through the portal.', 'sustainable-catalyst-feature-suggestions') . '</p></div></section>';
            return ob_get_clean();
        }
        $case = $payload['case'];
        $notice = isset($_GET['portal_notice']) ? sanitize_key(wp_unslash($_GET['portal_notice'])) : '';
        if ($notice) {
            echo '<p class="scfs-customer-portal__flash" role="status">' . esc_html($this->notice_text($notice)) . '</p>';
        }
        echo '<div class="scfs-customer-portal__layout"><main>';
        echo '<article class="scfs-customer-portal__case"><header><p class="scfs-customer-portal__case-number">' . esc_html($case['case_number']) . '</p><h2>' . esc_html($case['subject']) . '</h2><div class="scfs-customer-portal__meta"><span>' . esc_html(ucwords(str_replace('_', ' ', $case['status']))) . '</span><span>' . esc_html($case['product']) . '</span><span>' . esc_html($case['product_version']) . '</span><span>' . esc_html($case['component']) . '</span><span>' . esc_html__('Updated', 'sustainable-catalyst-feature-suggestions') . ' ' . esc_html($case['updated_at']) . '</span></div></header>';
        echo '<section class="scfs-customer-portal__conversation" aria-labelledby="scfs-portal-conversation"><h3 id="scfs-portal-conversation">' . esc_html__('Conversation', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        if (!$payload['messages']) {
            echo '<p>' . esc_html__('No participant-visible messages have been recorded.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        }
        foreach ($payload['messages'] as $message) {
            $is_requester = $message['message_type'] === 'requester_message';
            echo '<article class="scfs-customer-portal__message' . ($is_requester ? ' is-requester' : ' is-support') . '"><header><strong>' . esc_html($is_requester ? __('You', 'sustainable-catalyst-feature-suggestions') : __('Sustainable Catalyst Support', 'sustainable-catalyst-feature-suggestions')) . '</strong><time>' . esc_html($message['created_at']) . '</time></header><div>' . wp_kses_post(wpautop($message['body'])) . '</div></article>';
        }
        echo '</section>';
        if ($payload['permissions']['reply'] && !in_array($case['status'], array('closed', 'cancelled', 'duplicate'), true)) {
            echo '<form class="scfs-customer-portal__reply" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_help_desk_portal_reply">';
            $this->portal_nonce_field($session);
            echo '<label><span>' . esc_html__('Reply', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="body" rows="7" maxlength="20000" required></textarea></label><p class="scfs-customer-portal__privacy-note">' . esc_html__('Do not include passwords, API keys, or unnecessary personal information.', 'sustainable-catalyst-feature-suggestions') . '</p><button type="submit">' . esc_html__('Send reply', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
        }
        echo '</article></main><aside>';
        echo '<section class="scfs-customer-portal__panel"><h3>' . esc_html__('Case status', 'sustainable-catalyst-feature-suggestions') . '</h3><dl><div><dt>' . esc_html__('Status', 'sustainable-catalyst-feature-suggestions') . '</dt><dd>' . esc_html(ucwords(str_replace('_', ' ', $case['status']))) . '</dd></div><div><dt>' . esc_html__('Opened', 'sustainable-catalyst-feature-suggestions') . '</dt><dd>' . esc_html($case['created_at']) . '</dd></div><div><dt>' . esc_html__('Last updated', 'sustainable-catalyst-feature-suggestions') . '</dt><dd>' . esc_html($case['updated_at']) . '</dd></div></dl></section>';
        do_action('scfs_help_desk_customer_portal_status_after', $case, $payload);
        if ($payload['permissions']['transition']) {
            echo '<section class="scfs-customer-portal__panel"><h3>' . esc_html__('Resolution', 'sustainable-catalyst-feature-suggestions') . '</h3>';
            if (!in_array($case['status'], array('resolved', 'closed'), true)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_help_desk_portal_transition"><input type="hidden" name="operation" value="confirm_resolved">';
                $this->portal_nonce_field($session);
                echo '<label><span>' . esc_html__('Optional note', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="reason" rows="3"></textarea></label><button type="submit" class="is-secondary">' . esc_html__('Mark resolved', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
            } elseif ($this->can_reopen_case(array_merge($case, array('id' => $session['case_id'])))) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_help_desk_portal_transition"><input type="hidden" name="operation" value="reopen">';
                $this->portal_nonce_field($session);
                echo '<label><span>' . esc_html__('What still needs attention?', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="reason" rows="3" required></textarea></label><button type="submit" class="is-secondary">' . esc_html__('Reopen case', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
            }
            echo '</section>';
        }
        if ($payload['permissions']['satisfaction'] && $this->settings()['allow_satisfaction_feedback'] === '1') {
            echo '<section class="scfs-customer-portal__panel"><h3>' . esc_html__('Support feedback', 'sustainable-catalyst-feature-suggestions') . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_help_desk_portal_satisfaction">';
            $this->portal_nonce_field($session);
            echo '<label><span>' . esc_html__('Rating', 'sustainable-catalyst-feature-suggestions') . '</span><select name="rating" required><option value="5">5 — Excellent</option><option value="4">4 — Good</option><option value="3">3 — Adequate</option><option value="2">2 — Needs improvement</option><option value="1">1 — Poor</option></select></label><label class="scfs-customer-portal__checkbox"><input type="checkbox" name="resolved" value="1"><span>' . esc_html__('My question or issue was resolved', 'sustainable-catalyst-feature-suggestions') . '</span></label><label><span>' . esc_html__('Feedback category', 'sustainable-catalyst-feature-suggestions') . '</span><select name="feedback_reason"><option value="resolved">Resolved</option><option value="partially_resolved">Partially resolved</option><option value="not_resolved">Not resolved</option><option value="communication">Communication</option><option value="documentation">Documentation</option><option value="other">Other</option></select></label><label><span>' . esc_html__('Optional feedback', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="feedback_text" rows="4" maxlength="4000"></textarea></label><button type="submit" class="is-secondary">' . esc_html__('Submit feedback', 'sustainable-catalyst-feature-suggestions') . '</button></form></section>';
        }
        echo '<section class="scfs-customer-portal__panel"><h3>' . esc_html__('Privacy', 'sustainable-catalyst-feature-suggestions') . '</h3><p>' . esc_html__('Only participant-visible messages appear here. Internal notes, private administration records, and secure attachment storage remain inaccessible from the public site.', 'sustainable-catalyst-feature-suggestions') . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_help_desk_portal_logout"><button type="submit" class="is-link">' . esc_html__('End secure session', 'sustainable-catalyst-feature-suggestions') . '</button></form></section>';
        echo '</aside></div></section>';
        return ob_get_clean();
    }

    private function notice_text($notice) {
        $messages = array(
            'reply-added' => __('Your reply was added to the case.', 'sustainable-catalyst-feature-suggestions'),
            'status-updated' => __('The case status was updated.', 'sustainable-catalyst-feature-suggestions'),
            'feedback-recorded' => __('Thank you. Your feedback was recorded privately.', 'sustainable-catalyst-feature-suggestions'),
            'denied' => __('The secure portal action could not be verified.', 'sustainable-catalyst-feature-suggestions'),
            'reply-error' => __('The reply could not be added.', 'sustainable-catalyst-feature-suggestions'),
            'transition-error' => __('The case status could not be updated.', 'sustainable-catalyst-feature-suggestions'),
            'feedback-error' => __('The feedback could not be recorded.', 'sustainable-catalyst-feature-suggestions'),
        );
        return $messages[$notice] ?? __('The portal request was processed.', 'sustainable-catalyst-feature-suggestions');
    }

    private function issued_link_transient_key() {
        return 'scfs_portal_issued_link_' . get_current_user_id();
    }

    private function stash_issued_link($result) {
        if (!is_array($result) || empty($result['portal_url'])) {
            return;
        }
        set_transient($this->issued_link_transient_key(), array(
            'portal_url' => esc_url_raw($result['portal_url']),
            'case_number' => sanitize_text_field($result['case_number'] ?? ''),
            'expires_at' => sanitize_text_field($result['expires_at'] ?? ''),
        ), 5 * MINUTE_IN_SECONDS);
    }

    private function consume_issued_link() {
        $key = $this->issued_link_transient_key();
        $value = get_transient($key);
        if ($value !== false) {
            delete_transient($key);
        }
        return is_array($value) ? $value : array();
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Customer Portal', 'sustainable-catalyst-feature-suggestions'),
            __('Customer Portal', 'sustainable-catalyst-feature-suggestions'),
            'scfs_manage_help_desk_portal',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!current_user_can('scfs_manage_help_desk_portal') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        $issued_link = $this->consume_issued_link();
        global $wpdb;
        $tables = $this->table_names();
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['tokens']} WHERE revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() AND use_count < max_uses");
        $sessions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['sessions']} WHERE revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()");
        $queued = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['notifications']} WHERE status='queued'");
        $feedback = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['satisfaction']}");
        echo '<div class="wrap scfs-customer-portal-admin"><h1>' . esc_html__('Customer Support Portal', 'sustainable-catalyst-feature-suggestions') . '</h1><p>' . esc_html__('Issue secure access links, review portal activity, and preserve the privacy boundary between public support and private case conversations.', 'sustainable-catalyst-feature-suggestions') . '</p><div class="scfs-customer-portal-admin__stats"><div><strong>' . esc_html($active) . '</strong><span>' . esc_html__('Active links', 'sustainable-catalyst-feature-suggestions') . '</span></div><div><strong>' . esc_html($sessions) . '</strong><span>' . esc_html__('Active sessions', 'sustainable-catalyst-feature-suggestions') . '</span></div><div><strong>' . esc_html($queued) . '</strong><span>' . esc_html__('Queued notifications', 'sustainable-catalyst-feature-suggestions') . '</span></div><div><strong>' . esc_html($feedback) . '</strong><span>' . esc_html__('Feedback records', 'sustainable-catalyst-feature-suggestions') . '</span></div></div>';
        echo '<section class="scfs-customer-portal-admin__panel"><h2>' . esc_html__('Issue secure access', 'sustainable-catalyst-feature-suggestions') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="scfs_help_desk_portal_issue_link"><label><span>' . esc_html__('Case ID', 'sustainable-catalyst-feature-suggestions') . '</span><input type="number" min="1" name="case_id" required></label><label><span>' . esc_html__('Requester reference', 'sustainable-catalyst-feature-suggestions') . '</span><input type="text" name="requester_ref" maxlength="191"></label><label><span>' . esc_html__('Link lifetime (hours)', 'sustainable-catalyst-feature-suggestions') . '</span><input type="number" min="1" max="720" name="hours" value="' . esc_attr($this->settings()['access_link_hours']) . '"></label><button class="button button-primary">' . esc_html__('Issue secure link', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
        if (!empty($issued_link['portal_url'])) {
            echo '<div class="notice notice-success inline"><p><strong>' . esc_html__('Copy this link now. The raw access token is not stored.', 'sustainable-catalyst-feature-suggestions') . '</strong></p><p><input class="large-text code" readonly value="' . esc_attr($issued_link['portal_url']) . '"></p></div>';
        }
        echo '</section><section class="scfs-customer-portal-admin__panel"><h2>' . esc_html__('Governance boundary', 'sustainable-catalyst-feature-suggestions') . '</h2><ul><li>' . esc_html__('Raw access tokens and session secrets are stored only as SHA-256 hashes.', 'sustainable-catalyst-feature-suggestions') . '</li><li>' . esc_html__('Internal notes never appear in the customer portal.', 'sustainable-catalyst-feature-suggestions') . '</li><li>' . esc_html__('Requester identity and attachment authority remain with Contact and Engagement.', 'sustainable-catalyst-feature-suggestions') . '</li><li>' . esc_html__('Notifications are queued for the Contact and Engagement delivery authority.', 'sustainable-catalyst-feature-suggestions') . '</li></ul></section></div>';
    }

    public function render_agent_portal_panel($case) {
        if (!current_user_can('scfs_issue_help_desk_portal_links') && !current_user_can('manage_options')) {
            return;
        }
        $issued_link = $this->consume_issued_link();
        echo '<section class="scfs-agent-workspace__panel scfs-customer-portal-agent-panel"><h3>' . esc_html__('Customer portal', 'sustainable-catalyst-feature-suggestions') . '</h3><p>' . esc_html__('Issue an expiring secure link for the requester. The raw token is displayed once and is not stored.', 'sustainable-catalyst-feature-suggestions') . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="scfs_help_desk_portal_issue_link"><input type="hidden" name="case_id" value="' . esc_attr($case['id']) . '"><input type="hidden" name="return_workspace" value="1"><label><span>' . esc_html__('Requester reference', 'sustainable-catalyst-feature-suggestions') . '</span><input type="text" name="requester_ref" value="' . esc_attr($case['requester_ref']) . '" required></label><label><span>' . esc_html__('Link lifetime (hours)', 'sustainable-catalyst-feature-suggestions') . '</span><input type="number" min="1" max="720" name="hours" value="' . esc_attr($this->settings()['access_link_hours']) . '"></label><button class="button">' . esc_html__('Issue portal link', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
        if (!empty($issued_link['portal_url'])) {
            echo '<div class="notice notice-success inline"><p><strong>' . esc_html__('Copy this link now. The raw access token is not stored.', 'sustainable-catalyst-feature-suggestions') . '</strong></p><p><input class="large-text code" readonly value="' . esc_attr($issued_link['portal_url']) . '"></p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-customer-portal-agent-panel__revoke">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="scfs_help_desk_portal_revoke_link"><input type="hidden" name="case_id" value="' . esc_attr($case['id']) . '"><input type="hidden" name="return_workspace" value="1"><button class="button button-link-delete">' . esc_html__('Revoke all portal access', 'sustainable-catalyst-feature-suggestions') . '</button></form></section>';
    }

    private function verify_admin_action() {
        if (!current_user_can('scfs_issue_help_desk_portal_links') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
    }

    public function admin_issue_link() {
        $this->verify_admin_action();
        $case_id = absint($_POST['case_id'] ?? 0);
        $result = $this->issue_access_link($case_id, wp_unslash($_POST['requester_ref'] ?? ''), array('hours' => absint($_POST['hours'] ?? 0)), get_current_user_id());
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        $return = admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_PAGE);
        if (!empty($_POST['return_workspace'])) {
            $return = admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=scfs-help-desk-agent-workspace&case_id=' . $case_id);
        }
        $this->stash_issued_link($result);
        wp_safe_redirect(add_query_arg('portal_link_issued', '1', $return));
        exit;
    }

    public function admin_revoke_link() {
        $this->verify_admin_action();
        $case_id = absint($_POST['case_id'] ?? 0);
        $this->revoke_case_access($case_id, get_current_user_id(), 'Administrator revoked portal access.');
        $return = admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_PAGE);
        if (!empty($_POST['return_workspace'])) {
            $return = admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=scfs-help-desk-agent-workspace&case_id=' . $case_id);
        }
        wp_safe_redirect(add_query_arg('portal_access_revoked', '1', $return));
        exit;
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/help-desk/portal/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => '__return_true'));
        register_rest_route($namespace, '/help-desk/portal/case', array('methods' => 'GET', 'callback' => array($this, 'rest_case'), 'permission_callback' => array($this, 'rest_portal_permission')));
        register_rest_route($namespace, '/help-desk/portal/conversation', array('methods' => 'GET', 'callback' => array($this, 'rest_conversation'), 'permission_callback' => array($this, 'rest_portal_permission')));
        register_rest_route($namespace, '/help-desk/portal/reply', array('methods' => 'POST', 'callback' => array($this, 'rest_reply'), 'permission_callback' => array($this, 'rest_portal_reply_permission')));
        register_rest_route($namespace, '/help-desk/portal/transition', array('methods' => 'POST', 'callback' => array($this, 'rest_transition'), 'permission_callback' => array($this, 'rest_portal_transition_permission')));
        register_rest_route($namespace, '/help-desk/portal/satisfaction', array('methods' => 'POST', 'callback' => array($this, 'rest_satisfaction'), 'permission_callback' => array($this, 'rest_portal_satisfaction_permission')));
        register_rest_route($namespace, '/help-desk/portal/logout', array('methods' => 'POST', 'callback' => array($this, 'rest_logout'), 'permission_callback' => array($this, 'rest_portal_permission')));
        register_rest_route($namespace, '/help-desk/portal/admin/issue-link', array('methods' => 'POST', 'callback' => array($this, 'rest_admin_issue_link'), 'permission_callback' => array($this, 'can_manage_portal')));
        register_rest_route($namespace, '/help-desk/portal/admin/health', array('methods' => 'GET', 'callback' => array($this, 'rest_admin_health'), 'permission_callback' => array($this, 'can_manage_portal')));
    }

    private function rest_session_secret(WP_REST_Request $request) {
        $header = sanitize_text_field($request->get_header('X-SCFS-Portal-Session'));
        return $header ?: (isset($_COOKIE[self::SESSION_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE])) : '');
    }

    private function rest_permission_for(WP_REST_Request $request, $scope) {
        $session = $this->session_record($this->rest_session_secret($request));
        if (!$session || !in_array($scope, $session['scope'], true)) {
            return new WP_Error('scfs_portal_rest_denied', 'Secure portal session required.', array('status' => 401));
        }
        $this->rest_session = $session;
        return true;
    }

    public function rest_portal_permission(WP_REST_Request $request) {
        return $this->rest_permission_for($request, 'view');
    }

    public function rest_portal_reply_permission(WP_REST_Request $request) {
        return $this->rest_permission_for($request, 'reply');
    }

    public function rest_portal_transition_permission(WP_REST_Request $request) {
        return $this->rest_permission_for($request, 'transition');
    }

    public function rest_portal_satisfaction_permission(WP_REST_Request $request) {
        return $this->rest_permission_for($request, 'satisfaction');
    }

    public function can_manage_portal() {
        return current_user_can('scfs_manage_help_desk_portal') || current_user_can('manage_options');
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'shortcode' => '[' . self::SHORTCODE . ']',
            'portal_path' => $this->settings()['portal_path'],
            'additive_tables' => array_values($this->table_names()),
            'token_to_session_exchange' => true,
            'raw_access_tokens_stored' => false,
            'raw_session_secrets_stored' => false,
            'participant_visible_messages_only' => true,
            'internal_notes_exposed' => false,
            'requester_identity_authority' => 'contact-engagement',
            'attachment_authority' => 'contact-engagement',
            'notification_authority' => 'contact-engagement',
            'public_case_list_api' => false,
            'case_existence_disclosure_without_session' => false,
            'automatic_case_creation' => false,
            'automatic_case_resolution' => false,
            'human_review_required' => true,
        );
    }

    public function rest_schema() {
        return rest_ensure_response($this->schema_record());
    }

    public function rest_case() {
        return rest_ensure_response($this->portal_case_payload($this->rest_session));
    }

    public function rest_conversation() {
        $payload = $this->portal_case_payload($this->rest_session);
        return rest_ensure_response(array('schema' => self::SCHEMA, 'version' => self::VERSION, 'messages' => $payload ? $payload['messages'] : array()));
    }

    public function rest_reply(WP_REST_Request $request) {
        $result = $this->add_requester_reply($this->rest_session, $request->get_param('body'));
        return is_wp_error($result) ? $result : rest_ensure_response(array('message_id' => $result));
    }

    public function rest_transition(WP_REST_Request $request) {
        $result = $this->requester_transition($this->rest_session, $request->get_param('operation'), $request->get_param('reason'));
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_satisfaction(WP_REST_Request $request) {
        $result = $this->submit_satisfaction($this->rest_session, $request->get_json_params());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_logout() {
        global $wpdb;
        if ($this->rest_session) {
            $wpdb->update($this->table_names()['sessions'], array('revoked_at' => current_time('mysql', true)), array('id' => absint($this->rest_session['id'])), array('%s'), array('%d'));
        }
        $this->clear_session_cookie();
        return rest_ensure_response(array('logged_out' => true));
    }

    public function rest_admin_issue_link(WP_REST_Request $request) {
        return $this->issue_access_link(absint($request->get_param('case_id')), $request->get_param('requester_ref'), array(
            'hours' => absint($request->get_param('hours')),
            'scope' => (array) $request->get_param('scope'),
        ), get_current_user_id());
    }

    public function rest_admin_health() {
        return rest_ensure_response($this->health_report());
    }

    public function health_report() {
        global $wpdb;
        $tables = $this->table_names();
        $missing = array();
        foreach ($tables as $key => $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                $missing[] = $key;
            }
        }
        $report = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'missing_tables' => $missing,
            'active_tokens' => $missing ? 0 : (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['tokens']} WHERE revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() AND use_count < max_uses"),
            'active_sessions' => $missing ? 0 : (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['sessions']} WHERE revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()"),
            'queued_notifications' => $missing ? 0 : (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['notifications']} WHERE status='queued'"),
            'raw_access_tokens_stored' => false,
            'raw_session_secrets_stored' => false,
            'internal_notes_exposed' => false,
            'valid' => empty($missing),
        );
        $report['checksum'] = hash('sha256', wp_json_encode($report));
        return $report;
    }

    public function daily_maintenance() {
        global $wpdb;
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare("UPDATE {$tables['tokens']} SET revoked_at=%s WHERE revoked_at IS NULL AND expires_at <= %s", $now, $now));
        $wpdb->query($wpdb->prepare("UPDATE {$tables['sessions']} SET revoked_at=%s WHERE revoked_at IS NULL AND expires_at <= %s", $now, $now));
        $wpdb->query($wpdb->prepare("DELETE FROM {$tables['sessions']} WHERE revoked_at IS NOT NULL AND revoked_at < %s", gmdate('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS))));
    }

    public function cli_issue_link($args, $assoc_args) {
        $case_id = absint($args[0] ?? 0);
        $result = $this->issue_access_link($case_id, $assoc_args['requester-ref'] ?? '', array('hours' => absint($assoc_args['hours'] ?? 0)), get_current_user_id());
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT));
    }

    public function cli_revoke_case($args) {
        $count = $this->revoke_case_access(absint($args[0] ?? 0), get_current_user_id(), 'WP-CLI revocation.');
        WP_CLI::success(sprintf('Revoked %d portal access records.', $count));
    }

    public function cli_health() {
        WP_CLI::line(wp_json_encode($this->health_report(), JSON_PRETTY_PRINT));
    }
}
