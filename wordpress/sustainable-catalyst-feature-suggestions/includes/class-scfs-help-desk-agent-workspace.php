<?php
/**
 * Help Desk Agent Workspace, queues, and assignment operations.
 *
 * Builds a private operating console over the v6.1.0 case foundation. The
 * workspace never exposes case records through public shortcodes or public
 * REST permissions. Contact and Engagement remains the authority for
 * requester identity and private attachment bytes.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Agent_Workspace {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-agent-workspace/1.0';
    const DB_VERSION = '1.1.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_workspace_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_workspace_settings';
    const ADMIN_PAGE = 'scfs-help-desk-agent-workspace';
    const NONCE_ACTION = 'scfs_help_desk_workspace_action';
    const AGENT_ROLE = 'scfs_help_desk_agent';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 6);
        add_action('admin_menu', array($this, 'register_admin_page'), 49);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_post_scfs_help_desk_workspace_bulk', array($this, 'admin_bulk_action'));
        add_action('admin_post_scfs_help_desk_workspace_case', array($this, 'admin_case_action'));
        add_action('admin_post_scfs_help_desk_workspace_save_view', array($this, 'admin_save_view'));
        add_action('admin_post_scfs_help_desk_workspace_delete_view', array($this, 'admin_delete_view'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk workspace queues', array($this, 'cli_queues'));
            WP_CLI::add_command('scfs help-desk workspace workload', array($this, 'cli_workload'));
            WP_CLI::add_command('scfs help-desk workspace assign', array($this, 'cli_assign'));
            WP_CLI::add_command('scfs help-desk workspace views', array($this, 'cli_views'));
        }
    }

    public static function activate() {
        $instance = self::instance();
        $instance->install_schema();
        $instance->install_capabilities();
        if (!get_option(self::SETTINGS_OPTION)) {
            add_option(self::SETTINGS_OPTION, $instance->default_settings(), '', false);
        }
        $instance->seed_default_teams();
    }

    public static function deactivate() {
        // Workspace data and capabilities are intentionally retained.
    }

    private function foundation() {
        return class_exists('SCFS_Help_Desk_Case_Foundation') ? SCFS_Help_Desk_Case_Foundation::instance() : null;
    }

    public function default_settings() {
        return array(
            'default_queue' => 'my_open',
            'page_size' => 50,
            'recent_hours' => 72,
            'resolved_days' => 14,
            'workload_warning' => 20,
            'workload_critical' => 35,
            'public_workspace_api' => '0',
            'automatic_assignment' => '0',
            'identity_authority' => 'contact-engagement',
            'attachment_authority' => 'contact-engagement',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'saved_views' => $wpdb->prefix . 'scfs_help_desk_saved_views',
            'teams' => $wpdb->prefix . 'scfs_help_desk_teams',
            'team_members' => $wpdb->prefix . 'scfs_help_desk_team_members',
        );
    }

    public function install_schema() {
        global $wpdb;
        $tables = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $saved_views = "CREATE TABLE {$tables['saved_views']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            name varchar(191) NOT NULL,
            view_key varchar(120) NOT NULL,
            visibility varchar(30) NOT NULL DEFAULT 'private',
            query_json longtext NOT NULL,
            is_default tinyint(1) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_view_key (user_id,view_key),
            KEY visibility (visibility),
            KEY updated_at (updated_at)
        ) $charset;";

        $teams = "CREATE TABLE {$tables['teams']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_key varchar(120) NOT NULL,
            name varchar(191) NOT NULL,
            description text NULL,
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            default_priority varchar(40) NOT NULL DEFAULT 'normal',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY team_key (team_key),
            KEY active (active)
        ) $charset;";

        $members = "CREATE TABLE {$tables['team_members']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            membership_role varchar(40) NOT NULL DEFAULT 'agent',
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            added_by bigint(20) unsigned NOT NULL DEFAULT 0,
            added_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY team_user (team_id,user_id),
            KEY user_id (user_id),
            KEY active (active)
        ) $charset;";

        dbDelta($saved_views);
        dbDelta($teams);
        dbDelta($members);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public function maybe_upgrade_schema() {
        if ((string) get_option(self::DB_VERSION_OPTION, '') !== self::DB_VERSION) {
            $this->install_schema();
            $this->seed_default_teams();
        }
    }

    public function install_capabilities() {
        $agent_caps = array(
            'read' => true,
            'scfs_view_help_desk' => true,
            'scfs_reply_help_desk' => true,
            'scfs_assign_help_desk' => true,
            'scfs_manage_help_desk_views' => true,
        );
        if (!get_role(self::AGENT_ROLE)) {
            add_role(self::AGENT_ROLE, __('Help Desk Agent', 'sustainable-catalyst-feature-suggestions'), $agent_caps);
        }
        foreach (array('administrator', 'editor') as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach (array_keys($agent_caps) as $capability) {
                $role->add_cap($capability);
            }
            if ($role_name === 'administrator') {
                $role->add_cap('scfs_manage_help_desk_teams');
                $role->add_cap('scfs_bulk_manage_help_desk');
            }
        }
    }

    public function seed_default_teams() {
        global $wpdb;
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $defaults = array(
            array('general-support', 'General Support', 'General product questions and routing.'),
            array('product-support', 'Product Support', 'Product, version, component, and workflow support.'),
            array('technical-review', 'Technical Review', 'Defects, integrations, data, and diagnostic review.'),
            array('security-privacy', 'Security & Privacy', 'Restricted security and privacy concerns.'),
        );
        foreach ($defaults as $team) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$tables['teams']} (team_key,name,description,active,default_priority,created_at,updated_at)
                 VALUES (%s,%s,%s,1,'normal',%s,%s)
                 ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),updated_at=VALUES(updated_at)",
                $team[0], $team[1], $team[2], $now, $now
            ));
        }
    }

    public function can_view() {
        return current_user_can('scfs_view_help_desk') || current_user_can('manage_options');
    }

    public function can_assign() {
        return current_user_can('scfs_assign_help_desk') || current_user_can('scfs_manage_help_desk') || current_user_can('manage_options');
    }

    public function can_bulk_manage() {
        return current_user_can('scfs_bulk_manage_help_desk') || current_user_can('scfs_manage_help_desk') || current_user_can('manage_options');
    }

    public function queue_definitions() {
        return array(
            'my_open' => array('label' => __('My open cases', 'sustainable-catalyst-feature-suggestions'), 'assigned_user_id' => get_current_user_id(), 'exclude_closed' => true),
            'unassigned' => array('label' => __('Unassigned', 'sustainable-catalyst-feature-suggestions'), 'unassigned' => true, 'exclude_closed' => true),
            'new' => array('label' => __('New', 'sustainable-catalyst-feature-suggestions'), 'statuses' => array('new')),
            'waiting_support' => array('label' => __('Waiting for support', 'sustainable-catalyst-feature-suggestions'), 'statuses' => array('waiting_support')),
            'waiting_requester' => array('label' => __('Waiting for requester', 'sustainable-catalyst-feature-suggestions'), 'statuses' => array('waiting_requester')),
            'escalated' => array('label' => __('Escalated', 'sustainable-catalyst-feature-suggestions'), 'statuses' => array('escalated')),
            'high_priority' => array('label' => __('P1 and P2', 'sustainable-catalyst-feature-suggestions'), 'priorities' => array('p1_critical', 'p2_high'), 'exclude_closed' => true),
            'recently_updated' => array('label' => __('Recently updated', 'sustainable-catalyst-feature-suggestions'), 'recent_hours' => absint($this->settings()['recent_hours'])),
            'resolved_recent' => array('label' => __('Recently resolved', 'sustainable-catalyst-feature-suggestions'), 'statuses' => array('resolved'), 'resolved_days' => absint($this->settings()['resolved_days'])),
            'all_open' => array('label' => __('All active cases', 'sustainable-catalyst-feature-suggestions'), 'exclude_closed' => true),
        );
    }

    public function teams($active_only = true) {
        global $wpdb;
        $tables = $this->table_names();
        $sql = "SELECT * FROM {$tables['teams']}";
        if ($active_only) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= ' ORDER BY name ASC';
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    public function user_team_keys($user_id) {
        global $wpdb;
        $tables = $this->table_names();
        $user_id = absint($user_id);
        if (!$user_id) {
            return array();
        }
        return (array) $wpdb->get_col($wpdb->prepare(
            "SELECT t.team_key FROM {$tables['team_members']} m INNER JOIN {$tables['teams']} t ON t.id=m.team_id WHERE m.user_id=%d AND m.active=1 AND t.active=1 ORDER BY t.name",
            $user_id
        ));
    }

    private function normalize_filters($args) {
        $args = is_array($args) ? $args : array();
        return array(
            'queue' => sanitize_key($args['queue'] ?? ''),
            'status' => sanitize_key($args['status'] ?? ''),
            'priority' => sanitize_key($args['priority'] ?? ''),
            'product' => sanitize_key($args['product'] ?? ''),
            'team' => sanitize_text_field($args['team'] ?? ''),
            'assigned_user_id' => absint($args['assigned_user_id'] ?? 0),
            'search' => sanitize_text_field($args['search'] ?? ''),
            'sort' => sanitize_key($args['sort'] ?? 'updated_desc'),
            'page' => max(1, absint($args['page'] ?? 1)),
            'per_page' => min(200, max(10, absint($args['per_page'] ?? $this->settings()['page_size']))),
        );
    }

    private function build_case_query($args, $count_only = false) {
        $foundation = $this->foundation();
        if (!$foundation) {
            return array('', array());
        }
        $case_table = $foundation->table_names()['cases'];
        $filters = $this->normalize_filters($args);
        $where = array('1=1');
        $values = array();

        $definitions = $this->queue_definitions();
        $definition = isset($definitions[$filters['queue']]) ? $definitions[$filters['queue']] : array();
        if (strpos($filters['queue'], 'team_') === 0) {
            $definition['team'] = str_replace('_', '-', substr($filters['queue'], 5));
            $definition['exclude_closed'] = true;
        }

        $statuses = array();
        if ($filters['status'] !== '') {
            $statuses[] = $filters['status'];
        } elseif (!empty($definition['statuses'])) {
            $statuses = array_map('sanitize_key', (array) $definition['statuses']);
        }
        if ($statuses) {
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where[] = "status IN ($placeholders)";
            $values = array_merge($values, $statuses);
        } elseif (!empty($definition['exclude_closed'])) {
            $where[] = "status NOT IN ('closed','cancelled','duplicate')";
        }

        $priorities = array();
        if ($filters['priority'] !== '') {
            $priorities[] = $filters['priority'];
        } elseif (!empty($definition['priorities'])) {
            $priorities = array_map('sanitize_key', (array) $definition['priorities']);
        }
        if ($priorities) {
            $placeholders = implode(',', array_fill(0, count($priorities), '%s'));
            $where[] = "priority IN ($placeholders)";
            $values = array_merge($values, $priorities);
        }

        if (!empty($definition['unassigned'])) {
            $where[] = "assigned_user_id=0 AND assigned_team=''";
        } elseif (!empty($definition['assigned_user_id'])) {
            $where[] = 'assigned_user_id=%d';
            $values[] = absint($definition['assigned_user_id']);
        } elseif ($filters['assigned_user_id']) {
            $where[] = 'assigned_user_id=%d';
            $values[] = $filters['assigned_user_id'];
        }

        $team = $filters['team'] !== '' ? $filters['team'] : ($definition['team'] ?? '');
        if ($team !== '') {
            $where[] = 'assigned_team=%s';
            $values[] = sanitize_text_field($team);
        }
        if ($filters['product'] !== '') {
            $where[] = 'product=%s';
            $values[] = $filters['product'];
        }
        if ($filters['search'] !== '') {
            global $wpdb;
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where[] = '(case_number LIKE %s OR subject LIKE %s OR product LIKE %s OR component LIKE %s OR requester_ref LIKE %s)';
            for ($i = 0; $i < 5; $i++) {
                $values[] = $like;
            }
        }
        if (!empty($definition['recent_hours'])) {
            $where[] = 'updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)';
            $values[] = max(1, absint($definition['recent_hours']));
        }
        if (!empty($definition['resolved_days'])) {
            $where[] = 'resolved_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)';
            $values[] = max(1, absint($definition['resolved_days']));
        }

        if ($count_only) {
            return array("SELECT COUNT(*) FROM {$case_table} WHERE " . implode(' AND ', $where), $values);
        }

        $sorts = array(
            'updated_desc' => 'updated_at DESC,id DESC',
            'updated_asc' => 'updated_at ASC,id ASC',
            'created_desc' => 'created_at DESC,id DESC',
            'priority' => "FIELD(priority,'p1_critical','p2_high','normal','low'),updated_at DESC",
            'case_number' => 'case_number ASC',
        );
        $order = $sorts[$filters['sort']] ?? $sorts['updated_desc'];
        $offset = ($filters['page'] - 1) * $filters['per_page'];
        $sql = "SELECT * FROM {$case_table} WHERE " . implode(' AND ', $where) . " ORDER BY {$order} LIMIT %d OFFSET %d";
        $values[] = $filters['per_page'];
        $values[] = $offset;
        return array($sql, $values);
    }

    public function queue_cases($args = array()) {
        global $wpdb;
        list($sql, $values) = $this->build_case_query($args, false);
        if ($sql === '') {
            return array();
        }
        return (array) $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
    }

    public function queue_count($args = array()) {
        global $wpdb;
        list($sql, $values) = $this->build_case_query($args, true);
        if ($sql === '') {
            return 0;
        }
        return (int) $wpdb->get_var($values ? $wpdb->prepare($sql, $values) : $sql);
    }

    public function queue_summary() {
        $summary = array();
        foreach ($this->queue_definitions() as $key => $definition) {
            $summary[$key] = array(
                'key' => $key,
                'label' => $definition['label'],
                'count' => $this->queue_count(array('queue' => $key)),
            );
        }
        foreach ($this->teams() as $team) {
            $key = 'team_' . str_replace('-', '_', $team['team_key']);
            $summary[$key] = array(
                'key' => $key,
                'label' => $team['name'],
                'count' => $this->queue_count(array('queue' => $key)),
                'team' => $team['team_key'],
            );
        }
        return array_values($summary);
    }

    public function workload_summary() {
        global $wpdb;
        $foundation = $this->foundation();
        if (!$foundation) {
            return array('agents' => array(), 'teams' => array(), 'unassigned' => 0, 'state' => 'unavailable');
        }
        $case_table = $foundation->table_names()['cases'];
        $active = "status NOT IN ('closed','cancelled','duplicate')";
        $agents = (array) $wpdb->get_results(
            "SELECT assigned_user_id,COUNT(*) case_count,
             SUM(CASE priority WHEN 'p1_critical' THEN 8 WHEN 'p2_high' THEN 4 WHEN 'normal' THEN 2 ELSE 1 END) weighted_load,
             SUM(status='escalated') escalated_count
             FROM {$case_table} WHERE {$active} AND assigned_user_id>0 GROUP BY assigned_user_id ORDER BY weighted_load DESC",
            ARRAY_A
        );
        foreach ($agents as &$agent) {
            $user = get_userdata((int) $agent['assigned_user_id']);
            $agent['display_name'] = $user ? $user->display_name : 'User #' . absint($agent['assigned_user_id']);
            $agent['state'] = $this->workload_state((int) $agent['weighted_load']);
        }
        unset($agent);
        $teams = (array) $wpdb->get_results(
            "SELECT assigned_team,COUNT(*) case_count,
             SUM(CASE priority WHEN 'p1_critical' THEN 8 WHEN 'p2_high' THEN 4 WHEN 'normal' THEN 2 ELSE 1 END) weighted_load,
             SUM(status='escalated') escalated_count
             FROM {$case_table} WHERE {$active} AND assigned_team<>'' GROUP BY assigned_team ORDER BY weighted_load DESC",
            ARRAY_A
        );
        foreach ($teams as &$team) {
            $team['state'] = $this->workload_state((int) $team['weighted_load']);
        }
        unset($team);
        $unassigned = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$case_table} WHERE {$active} AND assigned_user_id=0 AND assigned_team=''");
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'generated_at' => gmdate('c'),
            'agents' => $agents,
            'teams' => $teams,
            'unassigned' => $unassigned,
            'automatic_assignment' => false,
            'human_review_required' => true,
        );
    }

    private function workload_state($weighted_load) {
        $settings = $this->settings();
        if ($weighted_load >= absint($settings['workload_critical'])) {
            return 'critical_review';
        }
        if ($weighted_load >= absint($settings['workload_warning'])) {
            return 'watch';
        }
        return 'balanced';
    }

    public function save_view($user_id, $name, $query, $visibility = 'private', $is_default = false) {
        global $wpdb;
        $tables = $this->table_names();
        $name = sanitize_text_field($name);
        if ($name === '') {
            return new WP_Error('scfs_view_name_required', 'A saved-view name is required.');
        }
        $visibility = in_array($visibility, array('private', 'team', 'shared'), true) ? $visibility : 'private';
        if ($visibility !== 'private' && !current_user_can('scfs_manage_help_desk_teams') && !current_user_can('manage_options')) {
            return new WP_Error('scfs_view_visibility_forbidden', 'Only administrators may create shared views.');
        }
        $view_key = sanitize_title($name);
        $now = current_time('mysql', true);
        $query = $this->normalize_filters($query);
        $wpdb->replace($tables['saved_views'], array(
            'user_id' => absint($user_id),
            'name' => $name,
            'view_key' => $view_key,
            'visibility' => $visibility,
            'query_json' => wp_json_encode($query),
            'is_default' => $is_default ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ), array('%d','%s','%s','%s','%s','%d','%s','%s'));
        return (int) $wpdb->insert_id;
    }

    public function saved_views($user_id = 0) {
        global $wpdb;
        $tables = $this->table_names();
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['saved_views']} WHERE user_id=%d OR visibility='shared' ORDER BY is_default DESC,name ASC",
            $user_id
        ), ARRAY_A);
        foreach ($rows as &$row) {
            $row['query'] = json_decode($row['query_json'], true) ?: array();
            unset($row['query_json']);
        }
        unset($row);
        return $rows;
    }

    public function delete_view($view_id, $user_id) {
        global $wpdb;
        $tables = $this->table_names();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['saved_views']} WHERE id=%d", absint($view_id)), ARRAY_A);
        if (!$row) {
            return new WP_Error('scfs_view_not_found', 'Saved view not found.');
        }
        if ((int) $row['user_id'] !== absint($user_id) && !current_user_can('manage_options')) {
            return new WP_Error('scfs_view_delete_forbidden', 'You may delete only your own saved views.');
        }
        return (bool) $wpdb->delete($tables['saved_views'], array('id' => absint($view_id)), array('%d'));
    }

    public function bulk_plan($case_ids, $operation, $payload = array()) {
        $foundation = $this->foundation();
        $case_ids = array_values(array_unique(array_filter(array_map('absint', (array) $case_ids))));
        $allowed = array('assign', 'claim', 'unassign', 'transition', 'priority');
        $errors = array();
        if (!$foundation) {
            $errors[] = 'case_foundation_unavailable';
        }
        if (!$case_ids) {
            $errors[] = 'case_selection_required';
        }
        if (!in_array($operation, $allowed, true)) {
            $errors[] = 'unsupported_bulk_operation';
        }
        $cases = array();
        if ($foundation) {
            foreach ($case_ids as $case_id) {
                $case = $foundation->get_case($case_id);
                if ($case) {
                    $cases[] = $case;
                } else {
                    $errors[] = 'case_not_found:' . $case_id;
                }
            }
        }
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'operation' => $operation,
            'case_ids' => $case_ids,
            'case_count' => count($cases),
            'payload' => is_array($payload) ? $payload : array(),
            'ready' => empty($errors),
            'errors' => $errors,
            'automatic_assignment' => false,
            'human_confirmation_required' => true,
        );
    }

    public function execute_bulk($case_ids, $operation, $payload, $actor_user_id) {
        global $wpdb;
        $foundation = $this->foundation();
        $plan = $this->bulk_plan($case_ids, $operation, $payload);
        if (!$plan['ready']) {
            return new WP_Error('scfs_bulk_plan_invalid', implode(', ', $plan['errors']));
        }
        $results = array();
        foreach ($plan['case_ids'] as $case_id) {
            if ($operation === 'assign') {
                $result = $foundation->assign_case($case_id, absint($payload['assigned_user_id'] ?? 0), sanitize_text_field($payload['assigned_team'] ?? ''), sanitize_textarea_field($payload['reason'] ?? ''), $actor_user_id);
            } elseif ($operation === 'claim') {
                $result = $foundation->assign_case($case_id, absint($actor_user_id), sanitize_text_field($payload['assigned_team'] ?? ''), 'Claimed from Agent Workspace', $actor_user_id);
            } elseif ($operation === 'unassign') {
                $result = $foundation->assign_case($case_id, 0, '', 'Unassigned from Agent Workspace', $actor_user_id);
            } elseif ($operation === 'transition') {
                $result = $foundation->transition_case($case_id, sanitize_key($payload['status'] ?? ''), sanitize_textarea_field($payload['reason'] ?? ''), $actor_user_id);
            } else {
                $priority = sanitize_key($payload['priority'] ?? '');
                if (!isset($foundation->priorities()[$priority])) {
                    $result = new WP_Error('scfs_invalid_priority', 'Invalid priority.');
                } else {
                    $tables = $foundation->table_names();
                    $wpdb->update($tables['cases'], array('priority' => $priority, 'updated_at' => current_time('mysql', true)), array('id' => $case_id), array('%s','%s'), array('%d'));
                    $foundation->add_event($case_id, 'priority_changed', array('priority' => $priority, 'source' => 'agent_workspace_bulk'), $actor_user_id);
                    $result = true;
                }
            }
            $results[] = array('case_id' => $case_id, 'ok' => !is_wp_error($result), 'error' => is_wp_error($result) ? $result->get_error_message() : '');
        }
        return array('plan' => $plan, 'results' => $results);
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Agent Workspace', 'sustainable-catalyst-feature-suggestions'),
            __('Agent Workspace', 'sustainable-catalyst-feature-suggestions'),
            'scfs_view_help_desk',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false) {
            return;
        }
        $base = dirname(__DIR__) . '/assets/';
        wp_enqueue_style('scfs-help-desk-agent-workspace', plugins_url('../assets/help-desk-agent-workspace.css', __FILE__), array(), is_readable($base . 'help-desk-agent-workspace.css') ? (string) filemtime($base . 'help-desk-agent-workspace.css') : self::VERSION);
        wp_enqueue_script('scfs-help-desk-agent-workspace', plugins_url('../assets/help-desk-agent-workspace.js', __FILE__), array(), is_readable($base . 'help-desk-agent-workspace.js') ? (string) filemtime($base . 'help-desk-agent-workspace.js') : self::VERSION, true);
    }

    private function admin_url($args = array()) {
        return add_query_arg(array_merge(array('post_type' => Sustainable_Catalyst_Feature_Suggestions::POST_TYPE, 'page' => self::ADMIN_PAGE), $args), admin_url('edit.php'));
    }

    public function render_admin_page() {
        if (!$this->can_view()) {
            wp_die(esc_html__('You do not have permission to view the Agent Workspace.', 'sustainable-catalyst-feature-suggestions'));
        }
        $case_id = isset($_GET['case_id']) ? absint($_GET['case_id']) : 0;
        echo '<div class="wrap scfs-agent-workspace">';
        echo '<header class="scfs-agent-workspace__masthead"><div><p class="scfs-agent-workspace__eyebrow">' . esc_html__('Private support operations', 'sustainable-catalyst-feature-suggestions') . '</p><h1>' . esc_html__('Agent Workspace', 'sustainable-catalyst-feature-suggestions') . '</h1><p>' . esc_html__('Manage private cases, queues, ownership, internal notes, requester-visible replies, and governed assignments.', 'sustainable-catalyst-feature-suggestions') . '</p></div><div class="scfs-agent-workspace__privacy">' . esc_html__('Private by design · No public case API', 'sustainable-catalyst-feature-suggestions') . '</div></header>';
        if ($case_id) {
            $this->render_case_workspace($case_id);
        } else {
            $this->render_queue_workspace();
        }
        echo '</div>';
    }

    private function current_filters() {
        return $this->normalize_filters(array(
            'queue' => $_GET['queue'] ?? $this->settings()['default_queue'],
            'status' => $_GET['status'] ?? '',
            'priority' => $_GET['priority'] ?? '',
            'product' => $_GET['product'] ?? '',
            'team' => $_GET['team'] ?? '',
            'assigned_user_id' => $_GET['assigned_user_id'] ?? 0,
            'search' => $_GET['s'] ?? '',
            'sort' => $_GET['sort'] ?? 'updated_desc',
            'page' => $_GET['paged'] ?? 1,
            'per_page' => $_GET['per_page'] ?? $this->settings()['page_size'],
        ));
    }

    private function render_queue_workspace() {
        $foundation = $this->foundation();
        if (!$foundation) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Help Desk Case Foundation is unavailable.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
            return;
        }
        $filters = $this->current_filters();
        $queues = $this->queue_summary();
        $cases = $this->queue_cases($filters);
        $total = $this->queue_count($filters);
        $workload = $this->workload_summary();

        echo '<section class="scfs-agent-workspace__metrics" aria-label="' . esc_attr__('Queue summary', 'sustainable-catalyst-feature-suggestions') . '">';
        $metric_map = array(
            array(__('My open', 'sustainable-catalyst-feature-suggestions'), $this->queue_count(array('queue' => 'my_open'))),
            array(__('Unassigned', 'sustainable-catalyst-feature-suggestions'), $workload['unassigned']),
            array(__('Escalated', 'sustainable-catalyst-feature-suggestions'), $this->queue_count(array('queue' => 'escalated'))),
            array(__('P1 / P2', 'sustainable-catalyst-feature-suggestions'), $this->queue_count(array('queue' => 'high_priority'))),
        );
        foreach ($metric_map as $metric) {
            echo '<article><strong>' . esc_html(number_format_i18n($metric[1])) . '</strong><span>' . esc_html($metric[0]) . '</span></article>';
        }
        echo '</section>';

        echo '<div class="scfs-agent-workspace__layout"><aside class="scfs-agent-workspace__sidebar">';
        echo '<section class="scfs-agent-workspace__panel"><h2>' . esc_html__('Queues', 'sustainable-catalyst-feature-suggestions') . '</h2><nav class="scfs-agent-workspace__queues">';
        foreach ($queues as $queue) {
            $active = $filters['queue'] === $queue['key'] ? ' is-active' : '';
            echo '<a class="' . esc_attr(trim($active)) . '" href="' . esc_url($this->admin_url(array('queue' => $queue['key']))) . '"><span>' . esc_html($queue['label']) . '</span><strong>' . esc_html(number_format_i18n($queue['count'])) . '</strong></a>';
        }
        echo '</nav></section>';
        $this->render_saved_views($filters);
        $this->render_workload_panel($workload);
        echo '</aside><main class="scfs-agent-workspace__main">';

        echo '<section class="scfs-agent-workspace__panel scfs-agent-workspace__filter-panel"><form method="get">';
        echo '<input type="hidden" name="post_type" value="' . esc_attr(Sustainable_Catalyst_Feature_Suggestions::POST_TYPE) . '"><input type="hidden" name="page" value="' . esc_attr(self::ADMIN_PAGE) . '"><input type="hidden" name="queue" value="' . esc_attr($filters['queue']) . '">';
        echo '<label><span>' . esc_html__('Search', 'sustainable-catalyst-feature-suggestions') . '</span><input type="search" name="s" value="' . esc_attr($filters['search']) . '" placeholder="' . esc_attr__('Case number, subject, product, component', 'sustainable-catalyst-feature-suggestions') . '"></label>';
        echo '<label><span>' . esc_html__('Status', 'sustainable-catalyst-feature-suggestions') . '</span><select name="status"><option value="">' . esc_html__('Any status', 'sustainable-catalyst-feature-suggestions') . '</option>';
        foreach ($foundation->statuses() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($filters['status'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>' . esc_html__('Priority', 'sustainable-catalyst-feature-suggestions') . '</span><select name="priority"><option value="">' . esc_html__('Any priority', 'sustainable-catalyst-feature-suggestions') . '</option>';
        foreach ($foundation->priorities() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($filters['priority'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>' . esc_html__('Team', 'sustainable-catalyst-feature-suggestions') . '</span><select name="team"><option value="">' . esc_html__('Any team', 'sustainable-catalyst-feature-suggestions') . '</option>';
        foreach ($this->teams() as $team) {
            echo '<option value="' . esc_attr($team['team_key']) . '" ' . selected($filters['team'], $team['team_key'], false) . '>' . esc_html($team['name']) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>' . esc_html__('Sort', 'sustainable-catalyst-feature-suggestions') . '</span><select name="sort">';
        foreach (array('updated_desc' => __('Recently updated', 'sustainable-catalyst-feature-suggestions'), 'priority' => __('Priority', 'sustainable-catalyst-feature-suggestions'), 'created_desc' => __('Newest', 'sustainable-catalyst-feature-suggestions'), 'case_number' => __('Case number', 'sustainable-catalyst-feature-suggestions')) as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($filters['sort'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label><button class="button button-primary">' . esc_html__('Apply', 'sustainable-catalyst-feature-suggestions') . '</button></form></section>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-agent-workspace__case-form">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="scfs_help_desk_workspace_bulk"><input type="hidden" name="return_query" value="' . esc_attr(wp_json_encode($filters)) . '">';
        echo '<section class="scfs-agent-workspace__panel"><header class="scfs-agent-workspace__section-header"><div><p class="scfs-agent-workspace__eyebrow">' . esc_html__('Active queue', 'sustainable-catalyst-feature-suggestions') . '</p><h2>' . esc_html(number_format_i18n($total) . ' ' . _n('case', 'cases', $total, 'sustainable-catalyst-feature-suggestions')) . '</h2></div>';
        if ($this->can_assign()) {
            echo '<div class="scfs-agent-workspace__bulk"><select name="bulk_operation"><option value="">' . esc_html__('Bulk action', 'sustainable-catalyst-feature-suggestions') . '</option><option value="claim">' . esc_html__('Claim cases', 'sustainable-catalyst-feature-suggestions') . '</option><option value="assign">' . esc_html__('Assign', 'sustainable-catalyst-feature-suggestions') . '</option><option value="unassign">' . esc_html__('Unassign', 'sustainable-catalyst-feature-suggestions') . '</option><option value="transition">' . esc_html__('Change status', 'sustainable-catalyst-feature-suggestions') . '</option><option value="priority">' . esc_html__('Change priority', 'sustainable-catalyst-feature-suggestions') . '</option></select><input name="bulk_value" placeholder="' . esc_attr__('User ID, team, status, or priority', 'sustainable-catalyst-feature-suggestions') . '"><button class="button">' . esc_html__('Apply', 'sustainable-catalyst-feature-suggestions') . '</button></div>';
        }
        echo '</header><div class="scfs-agent-workspace__table-wrap"><table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" data-scfs-select-all></td><th>' . esc_html__('Case', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Subject', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Context', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Priority', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Status', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Owner', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Updated', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        if (!$cases) {
            echo '<tr><td colspan="8"><div class="scfs-agent-workspace__empty"><strong>' . esc_html__('No cases match this queue.', 'sustainable-catalyst-feature-suggestions') . '</strong><p>' . esc_html__('Change filters or review another queue.', 'sustainable-catalyst-feature-suggestions') . '</p></div></td></tr>';
        }
        foreach ($cases as $case) {
            $owner = $case['assigned_team'];
            if (!$owner && $case['assigned_user_id']) {
                $user = get_userdata((int) $case['assigned_user_id']);
                $owner = $user ? $user->display_name : 'User #' . absint($case['assigned_user_id']);
            }
            if (!$owner) {
                $owner = __('Unassigned', 'sustainable-catalyst-feature-suggestions');
            }
            echo '<tr><th class="check-column"><input type="checkbox" name="case_ids[]" value="' . esc_attr($case['id']) . '" data-scfs-case-checkbox></th>';
            echo '<td><a href="' . esc_url($this->admin_url(array('case_id' => $case['id']))) . '"><strong>' . esc_html($case['case_number']) . '</strong></a></td>';
            echo '<td><strong>' . esc_html($case['subject']) . '</strong><small>' . esc_html($case['requester_ref']) . '</small></td>';
            echo '<td>' . esc_html(trim($case['product'] . ' ' . $case['product_version'])) . '<small>' . esc_html($case['component']) . '</small></td>';
            echo '<td><span class="scfs-agent-workspace__badge is-' . esc_attr($case['priority']) . '">' . esc_html($foundation->priorities()[$case['priority']] ?? $case['priority']) . '</span></td>';
            echo '<td><span class="scfs-agent-workspace__badge">' . esc_html($foundation->statuses()[$case['status']] ?? $case['status']) . '</span></td>';
            echo '<td>' . esc_html($owner) . '</td><td><time>' . esc_html($case['updated_at']) . '</time></td></tr>';
        }
        echo '</tbody></table></div></section></form>';
        echo '</main></div>';
    }

    private function render_saved_views($filters) {
        echo '<section class="scfs-agent-workspace__panel"><h2>' . esc_html__('Saved views', 'sustainable-catalyst-feature-suggestions') . '</h2><div class="scfs-agent-workspace__saved-views">';
        foreach ($this->saved_views() as $view) {
            $query = $view['query'];
            unset($query['page']);
            $url_args = array('queue' => $query['queue'] ?? 'all_open', 'status' => $query['status'] ?? '', 'priority' => $query['priority'] ?? '', 'team' => $query['team'] ?? '', 's' => $query['search'] ?? '', 'sort' => $query['sort'] ?? 'updated_desc');
            echo '<div><a href="' . esc_url($this->admin_url($url_args)) . '">' . esc_html($view['name']) . '</a>';
            if ((int) $view['user_id'] === get_current_user_id() || current_user_can('manage_options')) {
                $delete_url = wp_nonce_url(add_query_arg(array('action' => 'scfs_help_desk_workspace_delete_view', 'view_id' => $view['id']), admin_url('admin-post.php')), self::NONCE_ACTION);
                echo '<a class="scfs-agent-workspace__delete" href="' . esc_url($delete_url) . '" aria-label="' . esc_attr__('Delete saved view', 'sustainable-catalyst-feature-suggestions') . '">×</a>';
            }
            echo '</div>';
        }
        echo '</div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="scfs_help_desk_workspace_save_view"><input type="hidden" name="query" value="' . esc_attr(wp_json_encode($filters)) . '"><label><span>' . esc_html__('Save current filters', 'sustainable-catalyst-feature-suggestions') . '</span><input name="name" required placeholder="' . esc_attr__('View name', 'sustainable-catalyst-feature-suggestions') . '"></label><button class="button">' . esc_html__('Save view', 'sustainable-catalyst-feature-suggestions') . '</button></form></section>';
    }

    private function render_workload_panel($workload) {
        echo '<section class="scfs-agent-workspace__panel"><h2>' . esc_html__('Workload', 'sustainable-catalyst-feature-suggestions') . '</h2><div class="scfs-agent-workspace__workload">';
        if (!$workload['agents']) {
            echo '<p>' . esc_html__('No assigned active cases.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        }
        foreach (array_slice($workload['agents'], 0, 8) as $agent) {
            echo '<div><span>' . esc_html($agent['display_name']) . '</span><strong>' . esc_html($agent['weighted_load']) . '</strong><small>' . esc_html($agent['state']) . '</small></div>';
        }
        echo '</div></section>';
    }

    private function render_case_workspace($case_id) {
        global $wpdb;
        $foundation = $this->foundation();
        $case = $foundation ? $foundation->get_case($case_id) : null;
        if (!$case) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Case not found.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
            return;
        }
        $tables = $foundation->table_names();
        $timeline = $foundation->case_timeline($case_id, true);
        $assignments = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['assignments']} WHERE case_id=%d ORDER BY assigned_at DESC,id DESC", $case_id), ARRAY_A);
        $participants = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['participants']} WHERE case_id=%d AND removed_at IS NULL ORDER BY added_at ASC", $case_id), ARRAY_A);
        $relationships = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['relationships']} WHERE case_id=%d ORDER BY created_at DESC,id DESC", $case_id), ARRAY_A);
        $owner = $case['assigned_team'];
        if (!$owner && $case['assigned_user_id']) {
            $user = get_userdata((int) $case['assigned_user_id']);
            $owner = $user ? $user->display_name : 'User #' . absint($case['assigned_user_id']);
        }
        echo '<p><a href="' . esc_url($this->admin_url()) . '">← ' . esc_html__('Back to queues', 'sustainable-catalyst-feature-suggestions') . '</a></p>';
        echo '<header class="scfs-agent-workspace__case-header"><div><p class="scfs-agent-workspace__eyebrow">' . esc_html($case['case_number']) . '</p><h2>' . esc_html($case['subject']) . '</h2><p>' . esc_html(trim($case['product'] . ' ' . $case['product_version'] . ' · ' . $case['component'], ' ·')) . '</p></div><div><span class="scfs-agent-workspace__badge is-' . esc_attr($case['priority']) . '">' . esc_html($foundation->priorities()[$case['priority']] ?? $case['priority']) . '</span> <span class="scfs-agent-workspace__badge">' . esc_html($foundation->statuses()[$case['status']] ?? $case['status']) . '</span></div></header>';
        echo '<div class="scfs-agent-workspace__case-layout"><main>';
        echo '<section class="scfs-agent-workspace__panel"><h3>' . esc_html__('Private case description', 'sustainable-catalyst-feature-suggestions') . '</h3><div class="scfs-agent-workspace__prose">' . wp_kses_post(wpautop($case['description'])) . '</div></section>';
        echo '<section class="scfs-agent-workspace__panel"><h3>' . esc_html__('Conversation and internal timeline', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        foreach ($timeline['messages'] as $message) {
            $internal = $message['visibility'] === 'internal';
            echo '<article class="scfs-agent-workspace__message' . ($internal ? ' is-internal' : '') . '"><header><strong>' . esc_html($internal ? __('Internal note', 'sustainable-catalyst-feature-suggestions') : __('Participant message', 'sustainable-catalyst-feature-suggestions')) . '</strong><time>' . esc_html($message['created_at']) . '</time></header><div>' . wp_kses_post(wpautop($message['body'])) . '</div></article>';
        }
        if (!$timeline['messages']) {
            echo '<p>' . esc_html__('No messages have been recorded.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        }
        if (current_user_can('scfs_reply_help_desk') || current_user_can('manage_options')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-agent-workspace__reply">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="scfs_help_desk_workspace_case"><input type="hidden" name="case_id" value="' . esc_attr($case_id) . '"><input type="hidden" name="operation" value="message"><label><span>' . esc_html__('Message type', 'sustainable-catalyst-feature-suggestions') . '</span><select name="message_type"><option value="support_reply">' . esc_html__('Requester-visible reply', 'sustainable-catalyst-feature-suggestions') . '</option><option value="internal_note">' . esc_html__('Internal note', 'sustainable-catalyst-feature-suggestions') . '</option></select></label><label><span>' . esc_html__('Message', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="body" rows="7" required></textarea></label><button class="button button-primary">' . esc_html__('Add message', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
        }
        echo '</section></main><aside>';
        echo '<section class="scfs-agent-workspace__panel"><h3>' . esc_html__('Case context', 'sustainable-catalyst-feature-suggestions') . '</h3><dl>';
        $details = array(__('Requester reference', 'sustainable-catalyst-feature-suggestions') => $case['requester_ref'], __('Organization', 'sustainable-catalyst-feature-suggestions') => $case['organization_ref'], __('Owner', 'sustainable-catalyst-feature-suggestions') => $owner ?: __('Unassigned', 'sustainable-catalyst-feature-suggestions'), __('Source', 'sustainable-catalyst-feature-suggestions') => $case['source'], __('Consent', 'sustainable-catalyst-feature-suggestions') => $case['consent_state'], __('Created', 'sustainable-catalyst-feature-suggestions') => $case['created_at'], __('Updated', 'sustainable-catalyst-feature-suggestions') => $case['updated_at']);
        foreach ($details as $label => $value) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
        }
        echo '</dl></section>';
        if ($this->can_assign()) {
            echo '<section class="scfs-agent-workspace__panel"><h3>' . esc_html__('Assignment', 'sustainable-catalyst-feature-suggestions') . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="scfs_help_desk_workspace_case"><input type="hidden" name="case_id" value="' . esc_attr($case_id) . '"><input type="hidden" name="operation" value="assign"><label><span>' . esc_html__('Agent user ID', 'sustainable-catalyst-feature-suggestions') . '</span><input type="number" min="0" name="assigned_user_id" value="' . esc_attr($case['assigned_user_id']) . '"></label><label><span>' . esc_html__('Team', 'sustainable-catalyst-feature-suggestions') . '</span><select name="assigned_team"><option value="">' . esc_html__('No team', 'sustainable-catalyst-feature-suggestions') . '</option>';
            foreach ($this->teams() as $team) {
                echo '<option value="' . esc_attr($team['team_key']) . '" ' . selected($case['assigned_team'], $team['team_key'], false) . '>' . esc_html($team['name']) . '</option>';
            }
            echo '</select></label><label><span>' . esc_html__('Assignment reason', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="reason" rows="3"></textarea></label><button class="button">' . esc_html__('Update assignment', 'sustainable-catalyst-feature-suggestions') . '</button></form></section>';
        }
        echo '<section class="scfs-agent-workspace__panel"><h3>' . esc_html__('Status', 'sustainable-catalyst-feature-suggestions') . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="scfs_help_desk_workspace_case"><input type="hidden" name="case_id" value="' . esc_attr($case_id) . '"><input type="hidden" name="operation" value="transition"><label><span>' . esc_html__('New status', 'sustainable-catalyst-feature-suggestions') . '</span><select name="status">';
        foreach ($foundation->statuses() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($case['status'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label><label><span>' . esc_html__('Reason', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="reason" rows="3"></textarea></label><button class="button">' . esc_html__('Update status', 'sustainable-catalyst-feature-suggestions') . '</button></form></section>';
        echo '<section class="scfs-agent-workspace__panel"><h3>' . esc_html__('Operational record', 'sustainable-catalyst-feature-suggestions') . '</h3><p>' . esc_html(sprintf(_n('%d participant', '%d participants', count($participants), 'sustainable-catalyst-feature-suggestions'), count($participants))) . '</p><p>' . esc_html(sprintf(_n('%d related record', '%d related records', count($relationships), 'sustainable-catalyst-feature-suggestions'), count($relationships))) . '</p><p>' . esc_html(sprintf(_n('%d assignment event', '%d assignment events', count($assignments), 'sustainable-catalyst-feature-suggestions'), count($assignments))) . '</p></section>';
        do_action('scfs_help_desk_case_workspace_after', $case);
        echo '</aside></div>';
    }

    private function verify_admin_action() {
        if (!$this->can_view()) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
    }

    public function admin_bulk_action() {
        $this->verify_admin_action();
        if (!$this->can_assign()) {
            wp_die(esc_html__('Assignment permission required.', 'sustainable-catalyst-feature-suggestions'));
        }
        $operation = sanitize_key($_POST['bulk_operation'] ?? '');
        $value = sanitize_text_field($_POST['bulk_value'] ?? '');
        $payload = array('reason' => 'Bulk action from Agent Workspace');
        if ($operation === 'assign') {
            if (ctype_digit($value)) {
                $payload['assigned_user_id'] = absint($value);
            } else {
                $payload['assigned_team'] = $value;
            }
        } elseif ($operation === 'transition') {
            $payload['status'] = sanitize_key($value);
        } elseif ($operation === 'priority') {
            $payload['priority'] = sanitize_key($value);
        }
        $result = $this->execute_bulk($_POST['case_ids'] ?? array(), $operation, $payload, get_current_user_id());
        $args = array('scfs_workspace_notice' => is_wp_error($result) ? 'error' : 'updated');
        wp_safe_redirect($this->admin_url($args));
        exit;
    }

    public function admin_case_action() {
        $this->verify_admin_action();
        $foundation = $this->foundation();
        $case_id = absint($_POST['case_id'] ?? 0);
        $operation = sanitize_key($_POST['operation'] ?? '');
        if ($operation === 'message') {
            if (!current_user_can('scfs_reply_help_desk') && !current_user_can('manage_options')) {
                wp_die(esc_html__('Reply permission required.', 'sustainable-catalyst-feature-suggestions'));
            }
            $type = sanitize_key($_POST['message_type'] ?? 'support_reply');
            $foundation->add_message($case_id, array('message_type' => $type, 'visibility' => $type === 'internal_note' ? 'internal' : 'participants', 'body' => wp_kses_post($_POST['body'] ?? '')), get_current_user_id());
        } elseif ($operation === 'assign' && $this->can_assign()) {
            $foundation->assign_case($case_id, absint($_POST['assigned_user_id'] ?? 0), sanitize_text_field($_POST['assigned_team'] ?? ''), sanitize_textarea_field($_POST['reason'] ?? ''), get_current_user_id());
        } elseif ($operation === 'transition') {
            $foundation->transition_case($case_id, sanitize_key($_POST['status'] ?? ''), sanitize_textarea_field($_POST['reason'] ?? ''), get_current_user_id());
        }
        wp_safe_redirect($this->admin_url(array('case_id' => $case_id, 'scfs_workspace_notice' => 'updated')));
        exit;
    }

    public function admin_save_view() {
        $this->verify_admin_action();
        $query = json_decode(wp_unslash($_POST['query'] ?? '{}'), true);
        $this->save_view(get_current_user_id(), sanitize_text_field($_POST['name'] ?? ''), is_array($query) ? $query : array());
        wp_safe_redirect($this->admin_url(array('scfs_workspace_notice' => 'view_saved')));
        exit;
    }

    public function admin_delete_view() {
        $this->verify_admin_action();
        $this->delete_view(absint($_GET['view_id'] ?? 0), get_current_user_id());
        wp_safe_redirect($this->admin_url(array('scfs_workspace_notice' => 'view_deleted')));
        exit;
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/help-desk/workspace/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/workspace/queues', array('methods' => 'GET', 'callback' => array($this, 'rest_queues'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/workspace/cases', array('methods' => 'GET', 'callback' => array($this, 'rest_cases'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/workspace/workload', array('methods' => 'GET', 'callback' => array($this, 'rest_workload'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/workspace/assign', array('methods' => 'POST', 'callback' => array($this, 'rest_assign'), 'permission_callback' => array($this, 'can_assign')));
        register_rest_route($namespace, '/help-desk/workspace/bulk', array('methods' => 'POST', 'callback' => array($this, 'rest_bulk'), 'permission_callback' => array($this, 'can_bulk_manage')));
        register_rest_route($namespace, '/help-desk/workspace/views', array(
            array('methods' => 'GET', 'callback' => array($this, 'rest_views'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => 'POST', 'callback' => array($this, 'rest_save_view'), 'permission_callback' => array($this, 'can_view')),
        ));
        register_rest_route($namespace, '/help-desk/workspace/views/(?P<id>\d+)', array('methods' => 'DELETE', 'callback' => array($this, 'rest_delete_view'), 'permission_callback' => array($this, 'can_view')));
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'private_agent_workspace' => true,
            'public_workspace_api' => false,
            'automatic_assignment' => false,
            'features' => array('queues','case_workspace','assignment_history','team_queues','saved_views','bulk_actions','workload_summary','internal_notes','requester_visible_replies'),
            'tables' => array_values($this->table_names()),
            'identity_authority' => 'contact-engagement',
            'attachment_authority' => 'contact-engagement',
            'human_review_required' => true,
        );
    }

    public function rest_schema() {
        return rest_ensure_response($this->schema_record());
    }

    public function rest_queues() {
        return rest_ensure_response(array('schema' => self::SCHEMA, 'version' => self::VERSION, 'queues' => $this->queue_summary()));
    }

    public function rest_cases(WP_REST_Request $request) {
        $args = $request->get_params();
        return rest_ensure_response(array('schema' => self::SCHEMA, 'version' => self::VERSION, 'total' => $this->queue_count($args), 'cases' => $this->queue_cases($args)));
    }

    public function rest_workload() {
        return rest_ensure_response($this->workload_summary());
    }

    public function rest_assign(WP_REST_Request $request) {
        $foundation = $this->foundation();
        $result = $foundation->assign_case(absint($request['case_id']), absint($request['assigned_user_id']), sanitize_text_field($request['assigned_team']), sanitize_textarea_field($request['reason']), get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response(array('ok' => true, 'assignment_id' => $result));
    }

    public function rest_bulk(WP_REST_Request $request) {
        $result = $this->execute_bulk((array) $request['case_ids'], sanitize_key($request['operation']), (array) $request['payload'], get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_views() {
        return rest_ensure_response(array('schema' => self::SCHEMA, 'version' => self::VERSION, 'views' => $this->saved_views()));
    }

    public function rest_save_view(WP_REST_Request $request) {
        $result = $this->save_view(get_current_user_id(), sanitize_text_field($request['name']), (array) $request['query'], sanitize_key($request['visibility']), !empty($request['is_default']));
        return is_wp_error($result) ? $result : rest_ensure_response(array('ok' => true, 'view_id' => $result));
    }

    public function rest_delete_view(WP_REST_Request $request) {
        $result = $this->delete_view(absint($request['id']), get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response(array('ok' => true));
    }

    public function cli_queues() {
        WP_CLI::line(wp_json_encode(array('schema' => self::SCHEMA, 'version' => self::VERSION, 'queues' => $this->queue_summary()), JSON_PRETTY_PRINT));
    }

    public function cli_workload() {
        WP_CLI::line(wp_json_encode($this->workload_summary(), JSON_PRETTY_PRINT));
    }

    public function cli_assign($args, $assoc_args) {
        $case_id = absint($args[0] ?? 0);
        $user_id = absint($assoc_args['user'] ?? 0);
        $team = sanitize_text_field($assoc_args['team'] ?? '');
        $result = $this->foundation()->assign_case($case_id, $user_id, $team, sanitize_textarea_field($assoc_args['reason'] ?? 'WP-CLI assignment'), get_current_user_id());
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success('Assignment recorded: ' . $result);
    }

    public function cli_views() {
        WP_CLI::line(wp_json_encode(array('schema' => self::SCHEMA, 'version' => self::VERSION, 'views' => $this->saved_views()), JSON_PRETTY_PRINT));
    }
}
