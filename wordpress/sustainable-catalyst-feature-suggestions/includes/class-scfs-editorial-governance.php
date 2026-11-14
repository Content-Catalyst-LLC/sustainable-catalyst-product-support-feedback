<?php
/**
 * Documentation Workflow and Editorial Governance.
 *
 * Adds review assignments, controlled workflow transitions, documentation
 * standards, editorial notes, audit history, scheduled publication,
 * expiration, reminders, and governance reporting to public support content.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Editorial_Governance {
    const VERSION = '5.5.0';
    const SCHEMA_VERSION = '1.0';
    const OPTION_KEY = 'scfs_editorial_governance_settings';
    const AUDIT_OPTION = 'scfs_editorial_governance_audit';
    const REMINDER_OPTION = 'scfs_editorial_governance_reminders';
    const SCHEMA_OPTION = 'scfs_editorial_governance_schema_version';
    const NOTE_POST_TYPE = 'sc_editorial_note';
    const ADMIN_SLUG = 'scfs-editorial-governance';
    const NONCE_ACTION = 'scfs_save_editorial_governance';
    const NONCE_NAME = 'scfs_editorial_governance_nonce';
    const CRON_HOOK = 'scfs_editorial_governance_hourly';
    const CRON_LOCK = 'scfs_editorial_governance_lock';
    const MAX_AUDIT_ENTRIES = 2000;

    private static $instance = null;
    private $transition_in_progress = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_note_post_type'), 10);
        add_action('init', array($this, 'register_meta'), 12);
        add_action('admin_menu', array($this, 'register_admin_page'), 25);
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'), 20);
        add_action('save_post', array($this, 'save_editorial_meta'), 70, 3);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_scfs_save_editorial_settings', array($this, 'save_settings_action'));
        add_action('admin_post_scfs_editorial_transition', array($this, 'transition_action'));
        // Support Articles use the native WordPress Save Draft / Publish / Update workflow.
        add_action('transition_post_status', array($this, 'sync_support_article_native_state'), 10, 3);
        add_action('admin_post_scfs_run_editorial_governance', array($this, 'run_governance_action'));
        add_action('admin_post_scfs_export_editorial_audit', array($this, 'export_audit_action'));
        add_action(self::CRON_HOOK, array($this, 'run_scheduled_governance'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_filter('wp_insert_post_data', array($this, 'enforce_publication_gate'), 30, 2);

        foreach ($this->managed_post_types() as $post_type) {
            if ($post_type === 'sc_support_article') {
                continue;
            }
            add_filter('manage_' . $post_type . '_posts_columns', array($this, 'admin_columns'));
            add_action('manage_' . $post_type . '_posts_custom_column', array($this, 'admin_column_content'), 10, 2);
        }

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('scfs editorial-queue', array($this, 'cli_queue'));
            WP_CLI::add_command('scfs editorial-run', array($this, 'cli_run'));
            WP_CLI::add_command('scfs editorial-transition', array($this, 'cli_transition'));
        }
    }

    public static function activate() {
        $instance = self::instance();
        $instance->register_note_post_type();
        $instance->register_meta();
        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, $instance->default_settings(), '', false);
        }
        if (!get_option(self::AUDIT_OPTION)) {
            add_option(self::AUDIT_OPTION, array(), '', false);
        }
        if (!get_option(self::REMINDER_OPTION)) {
            add_option(self::REMINDER_OPTION, array(), '', false);
        }
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event') && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        if (!function_exists('wp_next_scheduled')) {
            return;
        }
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp && function_exists('wp_unschedule_event')) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        if (function_exists('delete_transient')) {
            delete_transient(self::CRON_LOCK);
        }
    }

    public function managed_post_types() {
        $types = array();
        if (class_exists('SCFS_Knowledge_Base_Foundation')) {
            $types[] = SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE;
            $types[] = SCFS_Knowledge_Base_Foundation::ISSUE_POST_TYPE;
        }
        if (class_exists('SCFS_Product_Support_Platform')) {
            $types[] = SCFS_Product_Support_Platform::RELEASE_POST_TYPE;
        }
        return array_values(array_unique(array_filter($types)));
    }

    public function register_note_post_type() {
        register_post_type(self::NOTE_POST_TYPE, array(
            'labels' => array(
                'name' => __('Editorial Notes', 'sustainable-catalyst-feature-suggestions'),
                'singular_name' => __('Editorial Note', 'sustainable-catalyst-feature-suggestions'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => array('title', 'editor', 'author'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));
    }

    public function workflow_states() {
        return array(
            'draft' => __('Draft', 'sustainable-catalyst-feature-suggestions'),
            'submitted' => __('Submitted for Review', 'sustainable-catalyst-feature-suggestions'),
            'in_review' => __('In Review', 'sustainable-catalyst-feature-suggestions'),
            'changes_requested' => __('Changes Requested', 'sustainable-catalyst-feature-suggestions'),
            'approved' => __('Approved', 'sustainable-catalyst-feature-suggestions'),
            'scheduled' => __('Scheduled', 'sustainable-catalyst-feature-suggestions'),
            'published' => __('Published', 'sustainable-catalyst-feature-suggestions'),
            'expired' => __('Expired', 'sustainable-catalyst-feature-suggestions'),
            'archived' => __('Archived', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function allowed_transitions() {
        return array(
            'draft' => array('submitted', 'archived'),
            'submitted' => array('in_review', 'changes_requested', 'draft'),
            'in_review' => array('approved', 'changes_requested', 'draft'),
            'changes_requested' => array('submitted', 'draft', 'archived'),
            'approved' => array('scheduled', 'published', 'changes_requested', 'archived'),
            'scheduled' => array('published', 'approved', 'archived'),
            'published' => array('in_review', 'expired', 'archived'),
            'expired' => array('in_review', 'archived'),
            'archived' => array('draft'),
        );
    }

    public function note_types() {
        return array(
            'general' => __('Editorial note', 'sustainable-catalyst-feature-suggestions'),
            'review' => __('Review note', 'sustainable-catalyst-feature-suggestions'),
            'changes' => __('Requested change', 'sustainable-catalyst-feature-suggestions'),
            'approval' => __('Approval note', 'sustainable-catalyst-feature-suggestions'),
            'standards' => __('Documentation standards note', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function default_settings() {
        return array(
            'require_review_before_publish' => '1',
            'require_separate_approver' => '1',
            'require_change_summary' => '1',
            'require_product_context' => '1',
            'require_version_approval' => '1',
            'default_review_interval_days' => 180,
            'reminder_lead_days' => 14,
            'email_reminders' => '1',
            'expire_public_content' => '1',
            'block_publish_on_standards_failure' => '1',
            'minimum_standards_score' => 80,
            'max_audit_entries' => self::MAX_AUDIT_ENTRIES,
            'standards' => $this->default_standards(),
        );
    }

    public function default_standards() {
        return array(
            'getting-started' => array('Overview', 'Before you begin', 'Steps', 'Next steps'),
            'how-to' => array('Goal', 'Requirements', 'Procedure', 'Troubleshooting'),
            'troubleshooting' => array('Symptoms', 'Cause', 'Resolution', 'Verification'),
            'technical-reference' => array('Scope', 'Interfaces', 'Examples', 'Limitations'),
            'known-issue' => array('Symptoms', 'Affected versions', 'Workaround', 'Resolution'),
            'release' => array('Summary', 'Highlights', 'Support notes', 'Known limitations'),
        );
    }

    public function settings() {
        $saved = function_exists('get_option') ? (array) get_option(self::OPTION_KEY, array()) : array();
        $defaults = $this->default_settings();
        if (function_exists('wp_parse_args')) {
            $settings = wp_parse_args($saved, $defaults);
        } else {
            $settings = array_merge($defaults, $saved);
        }
        $settings['standards'] = array_merge($defaults['standards'], is_array($settings['standards'] ?? null) ? $settings['standards'] : array());
        $settings['standards'] = apply_filters('scfs_editorial_standards', $settings['standards'], 0);
        return $settings;
    }

    public function admin_capability() {
        return apply_filters('scfs_editorial_governance_capability', 'edit_others_posts');
    }

    public function approve_capability() {
        return apply_filters('scfs_editorial_approval_capability', 'publish_posts');
    }

    public function can_manage() {
        return current_user_can($this->admin_capability());
    }

    public function can_approve() {
        return current_user_can($this->approve_capability());
    }

    public function sanitize_state($value) {
        $value = sanitize_key((string) $value);
        return isset($this->workflow_states()[$value]) ? $value : 'draft';
    }

    public function sanitize_date($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    public function sanitize_datetime($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }

    public function sanitize_id_array($value) {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value);
        }
        return array_values(array_unique(array_filter(array_map('absint', (array) $value))));
    }

    public function register_meta() {
        $definitions = array(
            '_scfs_editorial_state' => array('type' => 'string', 'sanitize_callback' => array($this, 'sanitize_state')),
            '_scfs_editorial_author_id' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
            '_scfs_editorial_reviewer_id' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
            '_scfs_editorial_approver_id' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
            '_scfs_editorial_submitted_at' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            '_scfs_editorial_reviewed_at' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            '_scfs_editorial_approved_at' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            '_scfs_editorial_scheduled_at' => array('type' => 'string', 'sanitize_callback' => array($this, 'sanitize_datetime')),
            '_scfs_editorial_expires_at' => array('type' => 'string', 'sanitize_callback' => array($this, 'sanitize_date')),
            '_scfs_editorial_review_due_at' => array('type' => 'string', 'sanitize_callback' => array($this, 'sanitize_date')),
            '_scfs_editorial_change_summary' => array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'),
            '_scfs_editorial_approval_note' => array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'),
            '_scfs_editorial_rejection_reason' => array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'),
            '_scfs_editorial_approved_version_ids' => array('type' => 'array', 'sanitize_callback' => array($this, 'sanitize_id_array')),
            '_scfs_editorial_schema' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            '_scfs_editorial_last_transition' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            '_scfs_editorial_standards_score' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
            '_scfs_editorial_standards_state' => array('type' => 'string', 'sanitize_callback' => 'sanitize_key'),
            '_scfs_editorial_content_hash' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
        );

        foreach ($this->managed_post_types() as $post_type) {
            foreach ($definitions as $key => $definition) {
                $show_in_rest = true;
                if ($definition['type'] === 'array') {
                    $show_in_rest = array('schema' => array('type' => 'array', 'items' => array('type' => 'integer')));
                }
                register_post_meta($post_type, $key, array(
                    'type' => $definition['type'],
                    'single' => true,
                    'show_in_rest' => $show_in_rest,
                    'sanitize_callback' => $definition['sanitize_callback'],
                    'auth_callback' => function () {
                        return $this->can_manage();
                    },
                ));
            }
        }

        register_post_meta(self::NOTE_POST_TYPE, '_scfs_editorial_record_id', array(
            'type' => 'integer', 'single' => true, 'show_in_rest' => false,
            'sanitize_callback' => 'absint', 'auth_callback' => function () { return $this->can_manage(); },
        ));
        register_post_meta(self::NOTE_POST_TYPE, '_scfs_editorial_note_type', array(
            'type' => 'string', 'single' => true, 'show_in_rest' => false,
            'sanitize_callback' => 'sanitize_key', 'auth_callback' => function () { return $this->can_manage(); },
        ));
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Editorial Governance', 'sustainable-catalyst-feature-suggestions'),
            __('Editorial Governance', 'sustainable-catalyst-feature-suggestions'),
            $this->admin_capability(),
            self::ADMIN_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_SLUG) === false && strpos((string) $hook, 'post.php') === false && strpos((string) $hook, 'post-new.php') === false) {
            return;
        }
        $css_file = dirname(__DIR__) . '/assets/editorial-governance.css';
        $js_file = dirname(__DIR__) . '/assets/editorial-governance.js';
        wp_enqueue_style('scfs-editorial-governance', plugins_url('assets/editorial-governance.css', dirname(__DIR__) . '/sustainable-catalyst-feature-suggestions.php'), array(), file_exists($css_file) ? (string) filemtime($css_file) : self::VERSION);
        wp_enqueue_script('scfs-editorial-governance', plugins_url('assets/editorial-governance.js', dirname(__DIR__) . '/sustainable-catalyst-feature-suggestions.php'), array(), file_exists($js_file) ? (string) filemtime($js_file) : self::VERSION, true);
    }

    public function add_meta_boxes() {
        foreach ($this->managed_post_types() as $post_type) {
            if ($post_type === 'sc_support_article') {
                continue;
            }
            add_meta_box(
                'scfs-editorial-governance',
                __('Editorial Workflow and Governance', 'sustainable-catalyst-feature-suggestions'),
                array($this, 'render_meta_box'),
                $post_type,
                'normal',
                'high'
            );
        }
    }

    public function state_for_post($post_id) {
        $state = get_post_meta($post_id, '_scfs_editorial_state', true);
        if ($state && isset($this->workflow_states()[$state])) {
            return $state;
        }
        $status = get_post_status($post_id);
        if ($status === 'publish') {
            return 'published';
        }
        if ($status === 'future') {
            return 'scheduled';
        }
        if ($status === 'pending') {
            return 'submitted';
        }
        return 'draft';
    }

    public function render_meta_box($post) {
        if (!$this->can_manage()) {
            echo '<p>' . esc_html__('You do not have permission to manage this editorial workflow.', 'sustainable-catalyst-feature-suggestions') . '</p>';
            return;
        }
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $state = $this->state_for_post($post->ID);
        $author_id = absint(get_post_meta($post->ID, '_scfs_editorial_author_id', true)) ?: absint($post->post_author);
        $reviewer_id = absint(get_post_meta($post->ID, '_scfs_editorial_reviewer_id', true));
        $approver_id = absint(get_post_meta($post->ID, '_scfs_editorial_approver_id', true));
        $schedule = get_post_meta($post->ID, '_scfs_editorial_scheduled_at', true);
        $expires = get_post_meta($post->ID, '_scfs_editorial_expires_at', true);
        $review_due = get_post_meta($post->ID, '_scfs_editorial_review_due_at', true);
        $change_summary = get_post_meta($post->ID, '_scfs_editorial_change_summary', true);
        $approved_versions = $this->sanitize_id_array(get_post_meta($post->ID, '_scfs_editorial_approved_version_ids', true));
        $assessment = $this->standards_assessment($post->ID);
        $users = get_users(array('who' => 'authors', 'orderby' => 'display_name', 'order' => 'ASC'));

        echo '<div class="scfs-editorial-meta" data-scfs-editorial-state="' . esc_attr($state) . '">';
        echo '<div class="scfs-editorial-status"><span>' . esc_html__('Current state', 'sustainable-catalyst-feature-suggestions') . '</span><strong>' . esc_html($this->workflow_states()[$state]) . '</strong><span class="scfs-editorial-score">' . esc_html(sprintf(__('Standards: %d/100', 'sustainable-catalyst-feature-suggestions'), $assessment['score'])) . '</span></div>';
        if ($assessment['blockers']) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Publication blockers:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html(implode('; ', array_map(array($this, 'humanize_code'), $assessment['blockers']))) . '</p></div>';
        }
        echo '<div class="scfs-editorial-grid">';
        $this->render_user_select('scfs_editorial_author_id', __('Author', 'sustainable-catalyst-feature-suggestions'), $author_id, $users);
        $this->render_user_select('scfs_editorial_reviewer_id', __('Reviewer', 'sustainable-catalyst-feature-suggestions'), $reviewer_id, $users);
        $this->render_user_select('scfs_editorial_approver_id', __('Approver', 'sustainable-catalyst-feature-suggestions'), $approver_id, $users);
        echo '<label><span>' . esc_html__('Scheduled publication', 'sustainable-catalyst-feature-suggestions') . '</span><input type="datetime-local" name="scfs_editorial_scheduled_at" value="' . esc_attr($schedule ? gmdate('Y-m-d\TH:i', strtotime($schedule)) : '') . '"></label>';
        echo '<label><span>' . esc_html__('Expiration date', 'sustainable-catalyst-feature-suggestions') . '</span><input type="date" name="scfs_editorial_expires_at" value="' . esc_attr($expires) . '"></label>';
        echo '<label><span>' . esc_html__('Next review due', 'sustainable-catalyst-feature-suggestions') . '</span><input type="date" name="scfs_editorial_review_due_at" value="' . esc_attr($review_due) . '"></label>';
        echo '</div>';

        echo '<label class="scfs-editorial-wide"><span>' . esc_html__('Change summary', 'sustainable-catalyst-feature-suggestions') . '</span><textarea name="scfs_editorial_change_summary" rows="3" placeholder="' . esc_attr__('Summarize what changed and why this revision is ready for review.', 'sustainable-catalyst-feature-suggestions') . '">' . esc_textarea($change_summary) . '</textarea></label>';

        if (class_exists('SCFS_Product_Integration')) {
            $version_terms = get_terms(array('taxonomy' => SCFS_Product_Integration::VERSION_TAXONOMY, 'hide_empty' => false));
            if (!is_wp_error($version_terms) && $version_terms) {
                echo '<fieldset class="scfs-editorial-versions"><legend>' . esc_html__('Approved product versions', 'sustainable-catalyst-feature-suggestions') . '</legend><div>';
                foreach ($version_terms as $term) {
                    echo '<label><input type="checkbox" name="scfs_editorial_approved_version_ids[]" value="' . esc_attr((string) $term->term_id) . '" ' . checked(in_array((int) $term->term_id, $approved_versions, true), true, false) . '> ' . esc_html($term->name) . '</label>';
                }
                echo '</div></fieldset>';
            }
        }

        echo '<fieldset class="scfs-editorial-transition"><legend>' . esc_html__('Workflow action', 'sustainable-catalyst-feature-suggestions') . '</legend><div class="scfs-editorial-transition-row">';
        echo '<select name="scfs_editorial_transition"><option value="">' . esc_html__('Save without changing state', 'sustainable-catalyst-feature-suggestions') . '</option>';
        $choices = $this->allowed_transitions()[$state] ?? array();
        if ($post->post_type === 'sc_support_article') {
            if (in_array($state, array('draft', 'submitted', 'in_review', 'changes_requested'), true) && $this->can_approve()) {
                $choices[] = 'approved';
            }
            if (in_array($state, array('approved', 'in_review', 'changes_requested'), true)) {
                $choices[] = 'submitted';
            }
            $choices = array_values(array_unique($choices));
        }
        foreach ($choices as $target) {
            echo '<option value="' . esc_attr($target) . '">' . esc_html($this->workflow_states()[$target]) . '</option>';
        }
        echo '</select>';
        echo '<textarea name="scfs_editorial_transition_note" rows="2" placeholder="' . esc_attr__('Optional transition note or decision rationale.', 'sustainable-catalyst-feature-suggestions') . '"></textarea>';
        echo '</div></fieldset>';

        echo '<fieldset class="scfs-editorial-note"><legend>' . esc_html__('Add internal editorial comment', 'sustainable-catalyst-feature-suggestions') . '</legend><div class="scfs-editorial-transition-row"><select name="scfs_editorial_note_type">';
        foreach ($this->note_types() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select><textarea name="scfs_editorial_note" rows="3" placeholder="' . esc_attr__('Visible only to authorized WordPress editors.', 'sustainable-catalyst-feature-suggestions') . '"></textarea></div></fieldset>';

        $notes = $this->editorial_notes($post->ID, 5);
        if ($notes) {
            echo '<div class="scfs-editorial-notes"><h4>' . esc_html__('Recent editorial comments', 'sustainable-catalyst-feature-suggestions') . '</h4><ol>';
            foreach ($notes as $note) {
                echo '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', get_post_meta($note->ID, '_scfs_editorial_note_type', true) ?: 'general'))) . '</strong> <span>' . esc_html(get_the_date('Y-m-d H:i', $note)) . '</span><p>' . esc_html(wp_strip_all_tags($note->post_content)) . '</p></li>';
            }
            echo '</ol></div>';
        }
        echo '</div>';
    }

    private function render_user_select($name, $label, $selected, $users) {
        echo '<label><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '"><option value="0">' . esc_html__('Unassigned', 'sustainable-catalyst-feature-suggestions') . '</option>';
        foreach ((array) $users as $user) {
            echo '<option value="' . esc_attr((string) $user->ID) . '" ' . selected((int) $selected, (int) $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
        }
        echo '</select></label>';
    }

    public function save_editorial_meta($post_id, $post, $update) {
        if ($post && $post->post_type === 'sc_support_article') {
            return;
        }
        if (!$post || !in_array($post->post_type, $this->managed_post_types(), true)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            $this->maintain_defaults($post_id, $post);
            return;
        }
        if (!$this->can_manage() || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $before = $this->assignment_snapshot($post_id);
        update_post_meta($post_id, '_scfs_editorial_author_id', absint($_POST['scfs_editorial_author_id'] ?? $post->post_author));
        update_post_meta($post_id, '_scfs_editorial_reviewer_id', absint($_POST['scfs_editorial_reviewer_id'] ?? 0));
        update_post_meta($post_id, '_scfs_editorial_approver_id', absint($_POST['scfs_editorial_approver_id'] ?? 0));
        update_post_meta($post_id, '_scfs_editorial_scheduled_at', $this->sanitize_datetime(wp_unslash($_POST['scfs_editorial_scheduled_at'] ?? '')));
        update_post_meta($post_id, '_scfs_editorial_expires_at', $this->sanitize_date(wp_unslash($_POST['scfs_editorial_expires_at'] ?? '')));
        update_post_meta($post_id, '_scfs_editorial_review_due_at', $this->sanitize_date(wp_unslash($_POST['scfs_editorial_review_due_at'] ?? '')));
        update_post_meta($post_id, '_scfs_editorial_change_summary', sanitize_textarea_field(wp_unslash($_POST['scfs_editorial_change_summary'] ?? '')));
        update_post_meta($post_id, '_scfs_editorial_approved_version_ids', $this->sanitize_id_array(wp_unslash($_POST['scfs_editorial_approved_version_ids'] ?? array())));
        update_post_meta($post_id, '_scfs_editorial_schema', self::SCHEMA_VERSION);

        $after = $this->assignment_snapshot($post_id);
        if ($before !== $after) {
            $this->append_audit($post_id, 'assignments_updated', array('before' => $before, 'after' => $after));
        }

        $note = sanitize_textarea_field(wp_unslash($_POST['scfs_editorial_note'] ?? ''));
        if ($note !== '') {
            $this->add_editorial_note($post_id, sanitize_key($_POST['scfs_editorial_note_type'] ?? 'general'), $note);
        }

        $target = sanitize_key($_POST['scfs_editorial_transition'] ?? '');
        if ($target !== '') {
            $transition_note = sanitize_textarea_field(wp_unslash($_POST['scfs_editorial_transition_note'] ?? ''));
            $result = $this->transition_record($post_id, $target, $transition_note, get_current_user_id(), false);
            if (is_wp_error($result)) {
                set_transient('scfs_editorial_error_' . get_current_user_id(), $result->get_error_message(), 60);
            }
        } else {
            $state = $this->state_for_post($post_id);
            $stored_hash = (string) get_post_meta($post_id, '_scfs_editorial_content_hash', true);
            $current_hash = $this->content_hash($post_id);
            if ($stored_hash !== '' && $current_hash !== '' && !hash_equals($stored_hash, $current_hash) && in_array($state, array('approved', 'scheduled', 'published'), true)) {
                update_post_meta($post_id, '_scfs_editorial_state', 'changes_requested');
                $this->sync_post_status($post_id, 'changes_requested');
                $this->append_audit($post_id, 'approved_content_changed', array('previous_state' => $state, 'new_state' => 'changes_requested'));
            }
        }

        $this->update_assessment_meta($post_id);
        $this->maintain_defaults($post_id, $post);
    }

    private function maintain_defaults($post_id, $post) {
        if (!get_post_meta($post_id, '_scfs_editorial_state', true)) {
            $default_state = $post->post_status === 'publish' ? 'published' : ($post->post_status === 'future' ? 'scheduled' : ($post->post_status === 'pending' ? 'submitted' : 'draft'));
            update_post_meta($post_id, '_scfs_editorial_state', $default_state);
        }
        if (!get_post_meta($post_id, '_scfs_editorial_author_id', true)) {
            update_post_meta($post_id, '_scfs_editorial_author_id', absint($post->post_author));
        }
        if (!get_post_meta($post_id, '_scfs_editorial_review_due_at', true)) {
            $days = max(1, absint($this->settings()['default_review_interval_days']));
            update_post_meta($post_id, '_scfs_editorial_review_due_at', gmdate('Y-m-d', time() + ($days * DAY_IN_SECONDS)));
        }
        update_post_meta($post_id, '_scfs_editorial_schema', self::SCHEMA_VERSION);
        update_post_meta($post_id, '_scfs_editorial_content_hash', $this->content_hash($post_id));
    }

    private function assignment_snapshot($post_id) {
        return array(
            'author_id' => absint(get_post_meta($post_id, '_scfs_editorial_author_id', true)),
            'reviewer_id' => absint(get_post_meta($post_id, '_scfs_editorial_reviewer_id', true)),
            'approver_id' => absint(get_post_meta($post_id, '_scfs_editorial_approver_id', true)),
            'approved_version_ids' => $this->sanitize_id_array(get_post_meta($post_id, '_scfs_editorial_approved_version_ids', true)),
        );
    }

    public function transition_record($post_id, $target_state, $note = '', $actor_id = 0, $system = false) {
        $post_id = absint($post_id);
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, $this->managed_post_types(), true)) {
            return new WP_Error('scfs_editorial_record_invalid', __('The editorial record could not be found.', 'sustainable-catalyst-feature-suggestions'));
        }
        $target_state = $this->sanitize_state($target_state);
        $current_state = $this->state_for_post($post_id);
        if ($current_state === $target_state) {
            return $this->record($post_id);
        }
        $quick_article_approval = $post->post_type === 'sc_support_article' && $target_state === 'approved'
            && in_array($current_state, array('draft', 'submitted', 'in_review', 'changes_requested'), true);
        $quick_article_pending = $post->post_type === 'sc_support_article' && $target_state === 'submitted'
            && in_array($current_state, array('approved', 'in_review', 'changes_requested'), true);
        if (!$quick_article_approval && !$quick_article_pending && !in_array($target_state, $this->allowed_transitions()[$current_state] ?? array(), true)) {
            return new WP_Error('scfs_editorial_transition_invalid', sprintf(__('The transition from %1$s to %2$s is not allowed.', 'sustainable-catalyst-feature-suggestions'), $current_state, $target_state));
        }
        if (!$system && !$this->can_manage()) {
            return new WP_Error('scfs_editorial_permission_denied', __('You do not have permission to change this editorial state.', 'sustainable-catalyst-feature-suggestions'));
        }
        if (in_array($target_state, array('approved', 'scheduled', 'published'), true) && !$system && !$this->can_approve()) {
            return new WP_Error('scfs_editorial_approval_permission', __('Approval or publication requires the configured approval capability.', 'sustainable-catalyst-feature-suggestions'));
        }

        $gate = $this->transition_gate($post_id, $target_state);
        if (is_wp_error($gate)) {
            return $gate;
        }

        $actor_id = absint($actor_id);
        if (!$actor_id && function_exists('get_current_user_id')) {
            $actor_id = get_current_user_id();
        }
        $now = current_time('mysql', true);
        update_post_meta($post_id, '_scfs_editorial_state', $target_state);
        update_post_meta($post_id, '_scfs_editorial_last_transition', $now);
        update_post_meta($post_id, '_scfs_editorial_schema', self::SCHEMA_VERSION);

        if ($target_state === 'submitted') {
            update_post_meta($post_id, '_scfs_editorial_submitted_at', $now);
        } elseif ($target_state === 'in_review') {
            update_post_meta($post_id, '_scfs_editorial_reviewed_at', $now);
        } elseif ($target_state === 'approved') {
            update_post_meta($post_id, '_scfs_editorial_approved_at', $now);
            update_post_meta($post_id, '_scfs_editorial_approver_id', absint(get_post_meta($post_id, '_scfs_editorial_approver_id', true)) ?: $actor_id);
            update_post_meta($post_id, '_scfs_editorial_content_hash', $this->content_hash($post_id));
        } elseif ($target_state === 'changes_requested') {
            update_post_meta($post_id, '_scfs_editorial_rejection_reason', $note);
        } elseif ($target_state === 'published') {
            $days = max(1, absint($this->settings()['default_review_interval_days']));
            update_post_meta($post_id, '_scfs_editorial_review_due_at', gmdate('Y-m-d', time() + ($days * DAY_IN_SECONDS)));
            update_post_meta($post_id, '_scfs_editorial_content_hash', $this->content_hash($post_id));
        }

        if ($note !== '') {
            update_post_meta($post_id, '_scfs_editorial_approval_note', sanitize_textarea_field($note));
            $this->add_editorial_note($post_id, $target_state === 'changes_requested' ? 'changes' : ($target_state === 'approved' ? 'approval' : 'review'), $note, $actor_id);
        }

        $this->sync_post_status($post_id, $target_state);
        $this->update_assessment_meta($post_id);
        $this->append_audit($post_id, 'workflow_transition', array(
            'from' => $current_state,
            'to' => $target_state,
            'note' => $note,
            'system' => (bool) $system,
        ), $actor_id);
        do_action('scfs_editorial_state_changed', $post_id, $current_state, $target_state, $actor_id);
        return $this->record($post_id);
    }

    public function transition_gate($post_id, $target_state) {
        $settings = $this->settings();
        $post = function_exists('get_post') ? get_post($post_id) : null;
        // Editorial approval of support guidance is distinct from publishing or scientific certification.
        // An authorized approver may approve a saved article revision without publication-only gates.
        if ($post && $post->post_type === 'sc_support_article' && $target_state === 'approved') {
            return true;
        }
        $reviewer_id = absint(get_post_meta($post_id, '_scfs_editorial_reviewer_id', true));
        $approver_id = absint(get_post_meta($post_id, '_scfs_editorial_approver_id', true));
        $author_id = absint(get_post_meta($post_id, '_scfs_editorial_author_id', true));

        if (in_array($target_state, array('submitted', 'in_review', 'approved', 'scheduled', 'published'), true) && !$author_id) {
            return new WP_Error('scfs_editorial_author_required', __('Assign an author before moving this record into review.', 'sustainable-catalyst-feature-suggestions'));
        }
        if (in_array($target_state, array('in_review', 'approved', 'scheduled', 'published'), true) && !$reviewer_id) {
            return new WP_Error('scfs_editorial_reviewer_required', __('Assign a reviewer before beginning editorial review.', 'sustainable-catalyst-feature-suggestions'));
        }
        if (in_array($target_state, array('approved', 'scheduled', 'published'), true) && $settings['require_separate_approver'] === '1') {
            if (!$approver_id) {
                return new WP_Error('scfs_editorial_approver_required', __('Assign an approver before approval or publication.', 'sustainable-catalyst-feature-suggestions'));
            }
            if ($approver_id === $author_id) {
                return new WP_Error('scfs_editorial_separation_required', __('The approver must be different from the author.', 'sustainable-catalyst-feature-suggestions'));
            }
        }
        if (in_array($target_state, array('approved', 'scheduled', 'published'), true) && $settings['require_change_summary'] === '1' && trim((string) get_post_meta($post_id, '_scfs_editorial_change_summary', true)) === '') {
            return new WP_Error('scfs_editorial_change_summary_required', __('Add a change summary before approval.', 'sustainable-catalyst-feature-suggestions'));
        }
        if (in_array($target_state, array('approved', 'scheduled', 'published'), true) && $settings['require_version_approval'] === '1') {
            $assigned_versions = class_exists('SCFS_Product_Integration') ? wp_get_object_terms($post_id, SCFS_Product_Integration::VERSION_TAXONOMY, array('fields' => 'ids')) : array();
            $approved_versions = $this->sanitize_id_array(get_post_meta($post_id, '_scfs_editorial_approved_version_ids', true));
            if (!is_wp_error($assigned_versions) && $assigned_versions && !array_intersect(array_map('absint', $assigned_versions), $approved_versions)) {
                return new WP_Error('scfs_editorial_version_approval_required', __('Approve at least one assigned product version before publication.', 'sustainable-catalyst-feature-suggestions'));
            }
        }
        if (in_array($target_state, array('approved', 'scheduled', 'published'), true) && $settings['block_publish_on_standards_failure'] === '1') {
            $assessment = $this->standards_assessment($post_id);
            if ($assessment['score'] < absint($settings['minimum_standards_score']) || $assessment['blockers']) {
                return new WP_Error('scfs_editorial_standards_failed', sprintf(__('Documentation standards are not satisfied (%d/100).', 'sustainable-catalyst-feature-suggestions'), $assessment['score']));
            }
        }
        if ($target_state === 'scheduled' && $this->sanitize_datetime(get_post_meta($post_id, '_scfs_editorial_scheduled_at', true)) === '') {
            return new WP_Error('scfs_editorial_schedule_required', __('Set a future publication date before scheduling.', 'sustainable-catalyst-feature-suggestions'));
        }
        return true;
    }

    private function sync_post_status($post_id, $state) {
        if ($this->transition_in_progress) {
            return;
        }
        $this->transition_in_progress = true;
        $update = array('ID' => $post_id);
        if ($state === 'published') {
            $update['post_status'] = 'publish';
        } elseif ($state === 'scheduled') {
            $scheduled = get_post_meta($post_id, '_scfs_editorial_scheduled_at', true);
            $timestamp = $scheduled ? strtotime($scheduled . ' UTC') : false;
            if ($timestamp && $timestamp > time()) {
                $update['post_status'] = 'future';
                $update['post_date_gmt'] = gmdate('Y-m-d H:i:s', $timestamp);
                $update['post_date'] = get_date_from_gmt($update['post_date_gmt']);
            } else {
                $update['post_status'] = 'pending';
            }
        } elseif (in_array($state, array('submitted', 'in_review', 'changes_requested', 'approved'), true)) {
            $update['post_status'] = 'pending';
        } elseif ($state === 'archived') {
            $update['post_status'] = 'private';
        } elseif ($state === 'expired') {
            $update['post_status'] = 'draft';
        } else {
            $update['post_status'] = 'draft';
        }
        wp_update_post($update);
        $this->transition_in_progress = false;
    }

    public function enforce_publication_gate($data, $postarr) {
        if (($data['post_type'] ?? '') === 'sc_support_article') {
            return $data;
        }
        if (apply_filters('scfs_editorial_allow_system_publication', false, $data, $postarr)) {
            return $data;
        }
        if ($this->transition_in_progress || !in_array($data['post_type'] ?? '', $this->managed_post_types(), true)) {
            return $data;
        }
        if (!in_array($data['post_status'] ?? '', array('publish', 'future'), true)) {
            return $data;
        }
        $settings = $this->settings();
        if ($settings['require_review_before_publish'] !== '1') {
            return $data;
        }
        $post_id = absint($postarr['ID'] ?? 0);
        $state = $post_id ? $this->state_for_post($post_id) : 'draft';
        if (!in_array($state, array('approved', 'scheduled', 'published'), true)) {
            $data['post_status'] = 'pending';
            if ($post_id) {
                update_post_meta($post_id, '_scfs_editorial_state', 'submitted');
                $this->append_audit($post_id, 'publication_blocked', array('requested_status' => $postarr['post_status'] ?? 'publish', 'reason' => 'approval_required'));
            }
        }
        return $data;
    }

    public function content_hash($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }
        return hash('sha256', wp_json_encode(array(
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
        )));
    }

    public function standards_assessment($post_id) {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, $this->managed_post_types(), true)) {
            return array('score' => 0, 'state' => 'invalid', 'blockers' => array('record_not_found'), 'warnings' => array(), 'checks' => array());
        }
        $settings = $this->settings();
        $checks = array();
        $blockers = array();
        $warnings = array();
        $score = 0;

        $title_ok = strlen(trim(wp_strip_all_tags($post->post_title))) >= 6;
        $checks['title'] = array('points' => 10, 'passed' => $title_ok);
        $score += $title_ok ? 10 : 0;
        if (!$title_ok) { $blockers[] = 'title_too_short'; }

        $content_parts = array($post->post_content);
        if ($post->post_type === SCFS_Knowledge_Base_Foundation::ISSUE_POST_TYPE) {
            $content_parts[] = get_post_meta($post_id, '_scfs_issue_symptom', true);
            $content_parts[] = get_post_meta($post_id, '_scfs_issue_workaround', true);
            $content_parts[] = get_post_meta($post_id, '_scfs_issue_resolution', true);
        } elseif ($post->post_type === SCFS_Product_Support_Platform::RELEASE_POST_TYPE) {
            $content_parts[] = get_post_meta($post_id, '_scfs_release_summary', true);
            $content_parts[] = get_post_meta($post_id, '_scfs_release_highlights', true);
            $content_parts[] = get_post_meta($post_id, '_scfs_release_support_note', true);
            $content_parts[] = get_post_meta($post_id, '_scfs_release_known_limitations', true);
        }
        $content_text = trim(wp_strip_all_tags(implode(' ', array_filter($content_parts))));
        $content_ok = strlen($content_text) >= 120;
        $checks['content'] = array('points' => 20, 'passed' => $content_ok);
        $score += $content_ok ? 20 : min(10, (int) floor(strlen($content_text) / 12));
        if (!$content_ok) { $blockers[] = 'content_too_short'; }

        $summary = get_post_meta($post_id, '_scfs_kb_summary', true);
        if ($post->post_type === SCFS_Product_Support_Platform::RELEASE_POST_TYPE) {
            $summary = get_post_meta($post_id, '_scfs_release_summary', true);
        } elseif ($post->post_type === SCFS_Knowledge_Base_Foundation::ISSUE_POST_TYPE) {
            $summary = get_post_meta($post_id, '_scfs_issue_symptom', true);
        }
        $summary_ok = strlen(trim(wp_strip_all_tags((string) ($summary ?: $post->post_excerpt)))) >= 20;
        $checks['summary'] = array('points' => 10, 'passed' => $summary_ok);
        $score += $summary_ok ? 10 : 0;
        if (!$summary_ok) { $warnings[] = 'summary_missing'; }

        $product_ids = class_exists('SCFS_Product_Integration') ? wp_get_object_terms($post_id, SCFS_Product_Integration::PRODUCT_TAXONOMY, array('fields' => 'ids')) : array();
        $product_ok = !is_wp_error($product_ids) && !empty($product_ids);
        $checks['product_context'] = array('points' => 15, 'passed' => $product_ok);
        $score += $product_ok ? 15 : 0;
        if (!$product_ok && $settings['require_product_context'] === '1') { $blockers[] = 'product_context_missing'; }

        $required_sections = $this->required_sections_for_post($post_id);
        $found_sections = $this->extract_headings($post->post_content);
        if ($post->post_type === SCFS_Knowledge_Base_Foundation::ISSUE_POST_TYPE) {
            if (trim((string) get_post_meta($post_id, '_scfs_issue_symptom', true)) !== '') { $found_sections[] = 'Symptoms'; }
            if (trim((string) get_post_meta($post_id, '_scfs_issue_workaround', true)) !== '') { $found_sections[] = 'Workaround'; }
            if (trim((string) get_post_meta($post_id, '_scfs_issue_resolution', true)) !== '') { $found_sections[] = 'Resolution'; }
            $versions = class_exists('SCFS_Product_Integration') ? wp_get_object_terms($post_id, SCFS_Product_Integration::VERSION_TAXONOMY, array('fields' => 'ids')) : array();
            if (!is_wp_error($versions) && $versions) { $found_sections[] = 'Affected versions'; }
        } elseif ($post->post_type === SCFS_Product_Support_Platform::RELEASE_POST_TYPE) {
            if (trim((string) get_post_meta($post_id, '_scfs_release_summary', true)) !== '') { $found_sections[] = 'Summary'; }
            if (trim((string) get_post_meta($post_id, '_scfs_release_highlights', true)) !== '') { $found_sections[] = 'Highlights'; }
            if (trim((string) get_post_meta($post_id, '_scfs_release_support_note', true)) !== '') { $found_sections[] = 'Support notes'; }
            if (trim((string) get_post_meta($post_id, '_scfs_release_known_limitations', true)) !== '') { $found_sections[] = 'Known limitations'; }
        }
        $found_sections = array_values(array_unique($found_sections));
        $matched = 0;
        foreach ($required_sections as $required) {
            foreach ($found_sections as $found) {
                if (sanitize_title($required) === sanitize_title($found) || strpos(sanitize_title($found), sanitize_title($required)) !== false) {
                    $matched++;
                    break;
                }
            }
        }
        $section_ratio = $required_sections ? ($matched / count($required_sections)) : 1;
        $section_points = (int) round($section_ratio * 25);
        $checks['required_sections'] = array('points' => 25, 'earned' => $section_points, 'matched' => $matched, 'required' => count($required_sections), 'sections' => $required_sections);
        $score += $section_points;
        if ($required_sections && $matched < max(1, (int) ceil(count($required_sections) * 0.5))) { $blockers[] = 'required_sections_missing'; }
        elseif ($matched < count($required_sections)) { $warnings[] = 'some_required_sections_missing'; }

        $source_reference = get_post_meta($post_id, '_scfs_source_reference', true);
        $verified_at = get_post_meta($post_id, '_scfs_verified_at', true);
        $provenance_ok = $source_reference !== '' || $verified_at !== '';
        $checks['provenance'] = array('points' => 10, 'passed' => $provenance_ok);
        $score += $provenance_ok ? 10 : 0;
        if (!$provenance_ok) { $warnings[] = 'provenance_unverified'; }

        $change_summary = trim((string) get_post_meta($post_id, '_scfs_editorial_change_summary', true));
        $change_ok = $change_summary !== '' || $this->state_for_post($post_id) === 'draft';
        $checks['change_summary'] = array('points' => 10, 'passed' => $change_ok);
        $score += $change_ok ? 10 : 0;
        if (!$change_ok && $settings['require_change_summary'] === '1') { $blockers[] = 'change_summary_missing'; }

        $score = min(100, max(0, $score));
        $minimum = absint($settings['minimum_standards_score']);
        $state = $blockers ? 'blocked' : ($score >= $minimum ? 'ready' : 'review');
        return array(
            'schema' => 'scfs-documentation-standards/' . self::SCHEMA_VERSION,
            'version' => self::VERSION,
            'post_id' => (int) $post_id,
            'post_type' => $post->post_type,
            'score' => $score,
            'minimum_score' => $minimum,
            'state' => $state,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'checks' => $checks,
            'human_review_required' => true,
        );
    }

    public function required_sections_for_post($post_id) {
        $post_type = get_post_type($post_id);
        $key = 'technical-reference';
        if ($post_type === SCFS_Knowledge_Base_Foundation::ISSUE_POST_TYPE) {
            $key = 'known-issue';
        } elseif ($post_type === SCFS_Product_Support_Platform::RELEASE_POST_TYPE) {
            $key = 'release';
        } elseif ($post_type === SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE && class_exists('SCFS_Knowledge_Base_Foundation')) {
            $terms = wp_get_object_terms($post_id, SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY, array('fields' => 'slugs'));
            if (!is_wp_error($terms) && $terms) {
                $key = sanitize_key(reset($terms));
            }
        }
        $standards = $this->settings()['standards'];
        $standards = apply_filters('scfs_editorial_standards', $standards, $post_id);
        return array_values(array_filter(array_map('sanitize_text_field', (array) ($standards[$key] ?? array()))));
    }

    public function extract_headings($content) {
        $headings = array();
        if (preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', (string) $content, $matches)) {
            foreach ($matches[1] as $heading) {
                $headings[] = trim(wp_strip_all_tags($heading));
            }
        }
        if (preg_match_all('/^#{1,6}\s+(.+)$/m', wp_strip_all_tags((string) $content), $matches)) {
            foreach ($matches[1] as $heading) {
                $headings[] = trim($heading);
            }
        }
        return array_values(array_unique(array_filter($headings)));
    }

    private function update_assessment_meta($post_id) {
        $assessment = $this->standards_assessment($post_id);
        update_post_meta($post_id, '_scfs_editorial_standards_score', absint($assessment['score']));
        update_post_meta($post_id, '_scfs_editorial_standards_state', sanitize_key($assessment['state']));
        return $assessment;
    }

    public function add_editorial_note($post_id, $type, $content, $author_id = 0) {
        $type = isset($this->note_types()[$type]) ? $type : 'general';
        $content = sanitize_textarea_field($content);
        if ($content === '') {
            return new WP_Error('scfs_editorial_note_empty', __('Editorial comments cannot be empty.', 'sustainable-catalyst-feature-suggestions'));
        }
        $author_id = absint($author_id) ?: (function_exists('get_current_user_id') ? get_current_user_id() : 0);
        $note_id = wp_insert_post(array(
            'post_type' => self::NOTE_POST_TYPE,
            'post_status' => 'private',
            'post_title' => sprintf('%s #%d', $this->note_types()[$type], absint($post_id)),
            'post_content' => $content,
            'post_author' => $author_id,
        ), true);
        if (is_wp_error($note_id)) {
            return $note_id;
        }
        update_post_meta($note_id, '_scfs_editorial_record_id', absint($post_id));
        update_post_meta($note_id, '_scfs_editorial_note_type', $type);
        $this->append_audit($post_id, 'editorial_comment_added', array('note_id' => (int) $note_id, 'note_type' => $type), $author_id);
        return (int) $note_id;
    }

    public function editorial_notes($post_id, $limit = 20) {
        $query = new WP_Query(array(
            'post_type' => self::NOTE_POST_TYPE,
            'post_status' => array('private', 'publish'),
            'posts_per_page' => max(1, absint($limit)),
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(array('key' => '_scfs_editorial_record_id', 'value' => absint($post_id), 'type' => 'NUMERIC')),
            'no_found_rows' => true,
        ));
        return $query->posts;
    }

    public function append_audit($post_id, $action, $context = array(), $actor_id = 0) {
        $log = (array) get_option(self::AUDIT_OPTION, array());
        $actor_id = absint($actor_id) ?: (function_exists('get_current_user_id') ? get_current_user_id() : 0);
        array_unshift($log, array(
            'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('scfs-', true),
            'at' => current_time('mysql', true),
            'post_id' => absint($post_id),
            'post_type' => get_post_type($post_id) ?: '',
            'action' => sanitize_key($action),
            'actor_id' => $actor_id,
            'context' => $this->sanitize_audit_context($context),
        ));
        $maximum = max(100, min(self::MAX_AUDIT_ENTRIES, absint($this->settings()['max_audit_entries'])));
        $log = array_slice($log, 0, $maximum);
        update_option(self::AUDIT_OPTION, $log, false);
    }

    private function sanitize_audit_context($context) {
        $clean = array();
        foreach ((array) $context as $key => $value) {
            $key = sanitize_key($key);
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$key] = $value;
            } elseif (is_array($value)) {
                $clean[$key] = array_map(function ($item) { return is_scalar($item) ? sanitize_text_field((string) $item) : ''; }, $value);
            } else {
                $clean[$key] = sanitize_textarea_field((string) $value);
            }
        }
        return $clean;
    }

    public function record($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return array();
        }
        $assessment = $this->standards_assessment($post_id);
        return array(
            'schema' => 'scfs-editorial-record/' . self::SCHEMA_VERSION,
            'version' => self::VERSION,
            'id' => (int) $post_id,
            'post_type' => $post->post_type,
            'title' => get_the_title($post_id),
            'state' => $this->state_for_post($post_id),
            'post_status' => $post->post_status,
            'assignments' => $this->assignment_snapshot($post_id),
            'submitted_at' => get_post_meta($post_id, '_scfs_editorial_submitted_at', true),
            'reviewed_at' => get_post_meta($post_id, '_scfs_editorial_reviewed_at', true),
            'approved_at' => get_post_meta($post_id, '_scfs_editorial_approved_at', true),
            'scheduled_at' => get_post_meta($post_id, '_scfs_editorial_scheduled_at', true),
            'expires_at' => get_post_meta($post_id, '_scfs_editorial_expires_at', true),
            'review_due_at' => get_post_meta($post_id, '_scfs_editorial_review_due_at', true),
            'change_summary' => get_post_meta($post_id, '_scfs_editorial_change_summary', true),
            'standards' => $assessment,
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'human_review_required' => true,
        );
    }

    public function queue_records($filters = array()) {
        $filters = is_array($filters) ? $filters : array();
        $args = array(
            'post_type' => $this->managed_post_types(),
            'post_status' => array('draft', 'pending', 'future', 'publish', 'private'),
            'posts_per_page' => max(1, min(200, absint($filters['limit'] ?? 100))),
            'orderby' => 'modified',
            'order' => 'ASC',
            'no_found_rows' => true,
        );
        $meta_query = array();
        if (!empty($filters['state'])) {
            $meta_query[] = array('key' => '_scfs_editorial_state', 'value' => $this->sanitize_state($filters['state']));
        }
        if (!empty($filters['assignee'])) {
            $user_id = absint($filters['assignee']);
            $meta_query[] = array(
                'relation' => 'OR',
                array('key' => '_scfs_editorial_author_id', 'value' => $user_id, 'type' => 'NUMERIC'),
                array('key' => '_scfs_editorial_reviewer_id', 'value' => $user_id, 'type' => 'NUMERIC'),
                array('key' => '_scfs_editorial_approver_id', 'value' => $user_id, 'type' => 'NUMERIC'),
            );
        }
        if ($meta_query) {
            if (count($meta_query) > 1) {
                $meta_query['relation'] = 'AND';
            }
            $args['meta_query'] = $meta_query;
        }
        $query = new WP_Query($args);
        $records = array_map(array($this, 'record'), $query->posts ? wp_list_pluck($query->posts, 'ID') : array());
        return array_values(array_filter($records));
    }

    public function governance_summary() {
        $states = array_fill_keys(array_keys($this->workflow_states()), 0);
        $overdue = 0;
        $expiring = 0;
        $standards_blocked = 0;
        $records = $this->queue_records(array('limit' => 200));
        $today = gmdate('Y-m-d');
        $lead = gmdate('Y-m-d', time() + (max(1, absint($this->settings()['reminder_lead_days'])) * DAY_IN_SECONDS));
        foreach ($records as $record) {
            if (isset($states[$record['state']])) {
                $states[$record['state']]++;
            }
            if ($record['review_due_at'] && $record['review_due_at'] < $today) {
                $overdue++;
            }
            if ($record['expires_at'] && $record['expires_at'] <= $lead && $record['expires_at'] >= $today) {
                $expiring++;
            }
            if (($record['standards']['state'] ?? '') === 'blocked') {
                $standards_blocked++;
            }
        }
        return array(
            'schema' => 'scfs-editorial-governance-summary/' . self::SCHEMA_VERSION,
            'version' => self::VERSION,
            'total' => count($records),
            'states' => $states,
            'overdue_reviews' => $overdue,
            'expiring_records' => $expiring,
            'standards_blocked' => $standards_blocked,
            'scheduled_task' => $this->cron_health(),
            'human_review_required' => true,
        );
    }

    public function run_scheduled_governance() {
        if (function_exists('get_transient') && get_transient(self::CRON_LOCK)) {
            return array('status' => 'locked');
        }
        if (function_exists('set_transient')) {
            set_transient(self::CRON_LOCK, '1', 10 * MINUTE_IN_SECONDS);
        }
        $result = array('published' => 0, 'expired' => 0, 'reminders' => 0, 'checked' => 0, 'errors' => array());
        $records = $this->queue_records(array('limit' => 500));
        $today = gmdate('Y-m-d');
        $now = time();
        foreach ($records as $record) {
            if (($record['post_type'] ?? '') === 'sc_support_article') {
                continue;
            }
            $result['checked']++;
            if ($record['state'] === 'scheduled' && $record['scheduled_at'] && strtotime($record['scheduled_at'] . ' UTC') <= $now) {
                $transition = $this->transition_record($record['id'], 'published', __('Scheduled publication executed automatically.', 'sustainable-catalyst-feature-suggestions'), 0, true);
                if (is_wp_error($transition)) { $result['errors'][] = $transition->get_error_code(); } else { $result['published']++; }
            }
            if ($record['state'] === 'published' && $this->settings()['expire_public_content'] === '1' && $record['expires_at'] && $record['expires_at'] < $today) {
                $transition = $this->transition_record($record['id'], 'expired', __('Content expiration date reached.', 'sustainable-catalyst-feature-suggestions'), 0, true);
                if (is_wp_error($transition)) { $result['errors'][] = $transition->get_error_code(); } else { $result['expired']++; }
            }
            if ($record['review_due_at'] && $record['review_due_at'] <= gmdate('Y-m-d', $now + (max(1, absint($this->settings()['reminder_lead_days'])) * DAY_IN_SECONDS))) {
                if ($this->send_review_reminder($record)) {
                    $result['reminders']++;
                }
            }
        }
        update_option(self::REMINDER_OPTION, array('last_run' => current_time('mysql', true), 'result' => $result), false);
        if (function_exists('delete_transient')) {
            delete_transient(self::CRON_LOCK);
        }
        do_action('scfs_editorial_governance_completed', $result);
        return $result;
    }

    private function send_review_reminder($record) {
        $last = get_post_meta($record['id'], '_scfs_editorial_last_reminder_at', true);
        if ($last && strtotime($last . ' UTC') > time() - DAY_IN_SECONDS) {
            return false;
        }
        update_post_meta($record['id'], '_scfs_editorial_last_reminder_at', current_time('mysql', true));
        $this->append_audit($record['id'], 'review_reminder_created', array('review_due_at' => $record['review_due_at']));
        if ($this->settings()['email_reminders'] !== '1' || !function_exists('wp_mail')) {
            return true;
        }
        $recipient_ids = array_filter(array_unique(array(
            absint($record['assignments']['author_id'] ?? 0),
            absint($record['assignments']['reviewer_id'] ?? 0),
        )));
        foreach ($recipient_ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user && is_email($user->user_email)) {
                wp_mail($user->user_email, sprintf(__('Editorial review due: %s', 'sustainable-catalyst-feature-suggestions'), $record['title']), sprintf(__('Review is due on %1$s. Open the record: %2$s', 'sustainable-catalyst-feature-suggestions'), $record['review_due_at'], $record['edit_url']));
            }
        }
        return true;
    }

    public function cron_health() {
        $next = function_exists('wp_next_scheduled') ? wp_next_scheduled(self::CRON_HOOK) : false;
        $state = (array) get_option(self::REMINDER_OPTION, array());
        return array(
            'hook' => self::CRON_HOOK,
            'scheduled' => (bool) $next,
            'next_run_gmt' => $next ? gmdate('c', $next) : '',
            'last_run_gmt' => $state['last_run'] ?? '',
            'last_result' => $state['result'] ?? array(),
        );
    }

    public function admin_columns($columns) {
        $columns['scfs_editorial_state'] = __('Editorial State', 'sustainable-catalyst-feature-suggestions');
        $columns['scfs_editorial_owner'] = __('Editorial Owner', 'sustainable-catalyst-feature-suggestions');
        $columns['scfs_editorial_due'] = __('Review Due', 'sustainable-catalyst-feature-suggestions');
        return $columns;
    }

    public function admin_column_content($column, $post_id) {
        if ($column === 'scfs_editorial_state') {
            $state = $this->state_for_post($post_id);
            echo esc_html($this->workflow_states()[$state] ?? $state);
            $score = absint(get_post_meta($post_id, '_scfs_editorial_standards_score', true));
            if ($score) { echo '<br><small>' . esc_html(sprintf(__('Standards %d/100', 'sustainable-catalyst-feature-suggestions'), $score)) . '</small>'; }
        } elseif ($column === 'scfs_editorial_owner') {
            $reviewer = get_userdata(absint(get_post_meta($post_id, '_scfs_editorial_reviewer_id', true)));
            echo esc_html($reviewer ? $reviewer->display_name : __('Unassigned', 'sustainable-catalyst-feature-suggestions'));
        } elseif ($column === 'scfs_editorial_due') {
            $due = get_post_meta($post_id, '_scfs_editorial_review_due_at', true);
            echo esc_html($due ?: '—');
        }
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/editorial-governance/schema', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function () { return rest_ensure_response($this->schema_record()); },
            'permission_callback' => '__return_true',
        ));
        register_rest_route($namespace, '/editorial-governance/summary', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function () { return rest_ensure_response($this->governance_summary()); },
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route($namespace, '/editorial-governance/queue', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function ($request) { return rest_ensure_response(array('records' => $this->queue_records($request->get_params()), 'version' => self::VERSION)); },
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route($namespace, '/editorial-governance/record/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function ($request) { return rest_ensure_response($this->record(absint($request['id']))); },
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route($namespace, '/editorial-governance/record/(?P<id>\d+)/standards', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function ($request) { return rest_ensure_response($this->standards_assessment(absint($request['id']))); },
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route($namespace, '/editorial-governance/record/(?P<id>\d+)/transition', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_transition'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route($namespace, '/editorial-governance/audit', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function () { return rest_ensure_response(array('version' => self::VERSION, 'entries' => array_slice((array) get_option(self::AUDIT_OPTION, array()), 0, 500))); },
            'permission_callback' => array($this, 'rest_permission'),
        ));
        register_rest_route($namespace, '/editorial-governance/run', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function () { return rest_ensure_response($this->run_scheduled_governance()); },
            'permission_callback' => array($this, 'rest_permission'),
        ));
    }

    public function rest_permission() {
        return $this->can_manage();
    }

    public function rest_transition($request) {
        $result = $this->transition_record(absint($request['id']), sanitize_key($request->get_param('state')), sanitize_textarea_field($request->get_param('note')), get_current_user_id(), false);
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function schema_record() {
        return array(
            'schema' => 'scfs-editorial-governance/' . self::SCHEMA_VERSION,
            'version' => self::VERSION,
            'managed_post_types' => $this->managed_post_types(),
            'workflow_states' => array_keys($this->workflow_states()),
            'allowed_transitions' => $this->allowed_transitions(),
            'capabilities' => array(
                'author_reviewer_approver_assignments',
                'editorial_review_queues',
                'required_review_before_publication',
                'scheduled_publication',
                'content_expiration',
                'review_due_reminders',
                'version_specific_approval',
                'change_summaries',
                'private_editorial_comments',
                'editorial_audit_history',
                'documentation_standards_scoring',
                'governance_dashboard',
                'protected_editorial_rest_api',
                'wp_cli_editorial_operations',
            ),
            'governance' => array(
                'human_approval_required' => true,
                'automatic_approval' => false,
                'private_comments_publicly_exposed' => false,
                'publication_can_be_blocked_by_standards' => true,
                'audit_history_is_internal' => true,
            ),
        );
    }

    public function render_admin_page() {
        if (!$this->can_manage()) {
            wp_die(__('You do not have permission to manage editorial governance.', 'sustainable-catalyst-feature-suggestions'));
        }
        $summary = $this->governance_summary();
        $queue = $this->queue_records(array('limit' => 100));
        $settings = $this->settings();
        $audit = array_slice((array) get_option(self::AUDIT_OPTION, array()), 0, 50);
        $error = get_transient('scfs_editorial_error_' . get_current_user_id());
        if ($error) { delete_transient('scfs_editorial_error_' . get_current_user_id()); }
        echo '<div class="wrap scfs-editorial-governance"><h1>' . esc_html__('Documentation Workflow and Editorial Governance', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p>' . esc_html__('Assign accountable editors, review documentation against product standards, require approval before publication, and maintain an auditable content lifecycle.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        if ($error) { echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>'; }
        echo '<div class="scfs-editorial-summary">';
        $cards = array('total' => __('Managed records', 'sustainable-catalyst-feature-suggestions'), 'overdue_reviews' => __('Overdue reviews', 'sustainable-catalyst-feature-suggestions'), 'expiring_records' => __('Expiring soon', 'sustainable-catalyst-feature-suggestions'), 'standards_blocked' => __('Standards blocked', 'sustainable-catalyst-feature-suggestions'));
        foreach ($cards as $key => $label) { echo '<article><span>' . esc_html($label) . '</span><strong>' . esc_html((string) ($summary[$key] ?? 0)) . '</strong></article>'; }
        echo '</div>';
        echo '<section class="scfs-editorial-panel"><h2>' . esc_html__('Workflow queue', 'sustainable-catalyst-feature-suggestions') . '</h2><div class="scfs-editorial-table" tabindex="0" role="region" aria-label="' . esc_attr__('Editorial workflow queue', 'sustainable-catalyst-feature-suggestions') . '"><table class="widefat striped"><thead><tr><th>' . esc_html__('Record', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Type', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('State', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Standards', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Review due', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Assignments', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        if (!$queue) { echo '<tr><td colspan="6">' . esc_html__('No managed support records were found.', 'sustainable-catalyst-feature-suggestions') . '</td></tr>'; }
        foreach ($queue as $record) {
            $assignments = array();
            foreach (array('author_id' => 'A', 'reviewer_id' => 'R', 'approver_id' => 'P') as $key => $prefix) {
                $user = get_userdata(absint($record['assignments'][$key] ?? 0));
                if ($user) { $assignments[] = $prefix . ': ' . $user->display_name; }
            }
            echo '<tr><td><a href="' . esc_url($record['edit_url']) . '"><strong>' . esc_html($record['title']) . '</strong></a></td><td>' . esc_html($record['post_type']) . '</td><td>' . esc_html($this->workflow_states()[$record['state']] ?? $record['state']) . '</td><td>' . esc_html((string) ($record['standards']['score'] ?? 0)) . '/100<br><small>' . esc_html($record['standards']['state'] ?? '') . '</small></td><td>' . esc_html($record['review_due_at'] ?: '—') . '</td><td>' . esc_html(implode(' · ', $assignments) ?: __('Unassigned', 'sustainable-catalyst-feature-suggestions')) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';

        echo '<section class="scfs-editorial-panel"><h2>' . esc_html__('Governance controls', 'sustainable-catalyst-feature-suggestions') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_save_editorial_settings">';
        wp_nonce_field('scfs_save_editorial_settings');
        $checkboxes = array(
            'require_review_before_publish' => __('Require editorial review before publication', 'sustainable-catalyst-feature-suggestions'),
            'require_separate_approver' => __('Require an approver who is separate from the author', 'sustainable-catalyst-feature-suggestions'),
            'require_change_summary' => __('Require a change summary before approval', 'sustainable-catalyst-feature-suggestions'),
            'require_product_context' => __('Require product context', 'sustainable-catalyst-feature-suggestions'),
            'require_version_approval' => __('Require version-specific approval when versions are assigned', 'sustainable-catalyst-feature-suggestions'),
            'email_reminders' => __('Send review reminder emails to assigned editors', 'sustainable-catalyst-feature-suggestions'),
            'expire_public_content' => __('Expire published records when their expiration date passes', 'sustainable-catalyst-feature-suggestions'),
            'block_publish_on_standards_failure' => __('Block approval and publication when documentation standards fail', 'sustainable-catalyst-feature-suggestions'),
        );
        echo '<div class="scfs-editorial-settings-grid">';
        foreach ($checkboxes as $key => $label) { echo '<label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($settings[$key], '1', false) . '> <span>' . esc_html($label) . '</span></label>'; }
        echo '<label><span>' . esc_html__('Default review interval (days)', 'sustainable-catalyst-feature-suggestions') . '</span><input type="number" min="1" max="1095" name="default_review_interval_days" value="' . esc_attr((string) $settings['default_review_interval_days']) . '"></label>';
        echo '<label><span>' . esc_html__('Reminder lead time (days)', 'sustainable-catalyst-feature-suggestions') . '</span><input type="number" min="1" max="90" name="reminder_lead_days" value="' . esc_attr((string) $settings['reminder_lead_days']) . '"></label>';
        echo '<label><span>' . esc_html__('Minimum standards score', 'sustainable-catalyst-feature-suggestions') . '</span><input type="number" min="0" max="100" name="minimum_standards_score" value="' . esc_attr((string) $settings['minimum_standards_score']) . '"></label>';
        echo '</div><p><button class="button button-primary">' . esc_html__('Save governance settings', 'sustainable-catalyst-feature-suggestions') . '</button></p></form>';
        $run_url = wp_nonce_url(admin_url('admin-post.php?action=scfs_run_editorial_governance'), 'scfs_run_editorial_governance');
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=scfs_export_editorial_audit'), 'scfs_export_editorial_audit');
        echo '<p><a class="button" href="' . esc_url($run_url) . '">' . esc_html__('Run scheduled workflow now', 'sustainable-catalyst-feature-suggestions') . '</a> <a class="button" href="' . esc_url($export_url) . '">' . esc_html__('Export audit history', 'sustainable-catalyst-feature-suggestions') . '</a></p></section>';

        echo '<section class="scfs-editorial-panel"><h2>' . esc_html__('Documentation standards', 'sustainable-catalyst-feature-suggestions') . '</h2><div class="scfs-editorial-standards">';
        foreach ($settings['standards'] as $type => $sections) {
            echo '<article><strong>' . esc_html(ucwords(str_replace('-', ' ', $type))) . '</strong><p>' . esc_html(implode(' · ', $sections)) . '</p></article>';
        }
        echo '</div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-editorial-standard-form"><input type="hidden" name="action" value="scfs_save_editorial_settings">';
        wp_nonce_field('scfs_save_editorial_settings');
        foreach ($settings['standards'] as $type => $sections) {
            echo '<label><span>' . esc_html(ucwords(str_replace('-', ' ', $type))) . '</span><input type="text" name="standards[' . esc_attr($type) . ']" value="' . esc_attr(implode(', ', $sections)) . '"></label>';
        }
        echo '<input type="hidden" name="preserve_existing_controls" value="1"><p><button class="button">' . esc_html__('Save documentation standards', 'sustainable-catalyst-feature-suggestions') . '</button></p></form><p class="description">' . esc_html__('Standards are advisory until the configured minimum score and blockers are enforced at approval. They can also be extended through the scfs_editorial_standards filter.', 'sustainable-catalyst-feature-suggestions') . '</p></section>';

        echo '<section class="scfs-editorial-panel"><h2>' . esc_html__('Recent governance history', 'sustainable-catalyst-feature-suggestions') . '</h2><div class="scfs-editorial-table" tabindex="0"><table class="widefat striped"><thead><tr><th>' . esc_html__('Time', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Record', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Action', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Actor', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        if (!$audit) { echo '<tr><td colspan="4">' . esc_html__('No editorial governance events have been recorded.', 'sustainable-catalyst-feature-suggestions') . '</td></tr>'; }
        foreach ($audit as $entry) {
            $user = get_userdata(absint($entry['actor_id'] ?? 0));
            echo '<tr><td>' . esc_html($entry['at'] ?? '') . '</td><td>' . esc_html(get_the_title(absint($entry['post_id'] ?? 0)) ?: ('#' . absint($entry['post_id'] ?? 0))) . '</td><td>' . esc_html($this->humanize_code($entry['action'] ?? '')) . '</td><td>' . esc_html($user ? $user->display_name : __('System', 'sustainable-catalyst-feature-suggestions')) . '</td></tr>';
        }
        echo '</tbody></table></div></section></div>';
    }

    public function save_settings_action() {
        if (!$this->can_manage()) { wp_die(__('Permission denied.', 'sustainable-catalyst-feature-suggestions')); }
        check_admin_referer('scfs_save_editorial_settings');
        $settings = $this->settings();
        if (empty($_POST['preserve_existing_controls'])) {
            foreach (array('require_review_before_publish', 'require_separate_approver', 'require_change_summary', 'require_product_context', 'require_version_approval', 'email_reminders', 'expire_public_content', 'block_publish_on_standards_failure') as $key) {
                $settings[$key] = !empty($_POST[$key]) ? '1' : '0';
            }
            $settings['default_review_interval_days'] = max(1, min(1095, absint($_POST['default_review_interval_days'] ?? 180)));
            $settings['reminder_lead_days'] = max(1, min(90, absint($_POST['reminder_lead_days'] ?? 14)));
            $settings['minimum_standards_score'] = max(0, min(100, absint($_POST['minimum_standards_score'] ?? 80)));
        }
        if (isset($_POST['standards']) && is_array($_POST['standards'])) {
            foreach (wp_unslash($_POST['standards']) as $type => $value) {
                $type = sanitize_key($type);
                if (!isset($settings['standards'][$type])) { continue; }
                $sections = preg_split('/[,\n]+/', (string) $value);
                $sections = array_values(array_filter(array_map('sanitize_text_field', $sections)));
                if ($sections) { $settings['standards'][$type] = array_slice($sections, 0, 12); }
            }
        }
        update_option(self::OPTION_KEY, $settings, false);
        $this->append_audit(0, 'governance_settings_updated', array('minimum_standards_score' => $settings['minimum_standards_score']));
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&updated=1'));
        exit;
    }

    /**
     * Clearly distinguish the WordPress post status from editorial approval.
     * Approved articles remain unpublished, but are not shown as awaiting review.
     */
    public function support_article_editorial_views($views) {
        if (!$this->can_manage()) {
            return $views;
        }
        $base = admin_url('edit.php?post_type=sc_support_article');
        $selected = isset($_GET['scfs_editorial_view']) ? sanitize_key(wp_unslash($_GET['scfs_editorial_view'])) : '';
        $approved = new WP_Query(array(
            'post_type' => 'sc_support_article',
            'post_status' => 'pending',
            'meta_key' => '_scfs_editorial_state',
            'meta_value' => 'approved',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
        ));
        $count = (int) $approved->found_posts;
        $views['scfs_editorial_approved'] = '<a href="' . esc_url(add_query_arg('scfs_editorial_view', 'approved', $base)) . '"' .
            ($selected === 'approved' ? ' class="current" aria-current="page"' : '') . '>' .
            esc_html__('Editorial Approved', 'sustainable-catalyst-feature-suggestions') .
            ' <span class="count">(' . number_format_i18n($count) . ')</span></a>';
        return $views;
    }

    public function filter_support_article_editorial_list($query) {
        if (!is_admin() || !$query->is_main_query() || !$this->can_manage() ||
            $query->get('post_type') !== 'sc_support_article') {
            return;
        }
        $view = isset($_GET['scfs_editorial_view']) ? sanitize_key(wp_unslash($_GET['scfs_editorial_view'])) : '';
        $post_status = $query->get('post_status');
        if ($view === 'approved') {
            $query->set('post_status', 'pending');
            $query->set('meta_query', array(array(
                'key' => '_scfs_editorial_state',
                'value' => 'approved',
                'compare' => '=',
            )));
        } elseif ($post_status === 'pending' && $view === '') {
            // Existing Pending tab means awaiting editorial review; hide approved items.
            $query->set('meta_query', array('relation' => 'OR',
                array('key' => '_scfs_editorial_state', 'compare' => 'NOT EXISTS'),
                array('key' => '_scfs_editorial_state', 'value' => 'approved', 'compare' => '!='),
            ));
        }
    }

    /**
     * Explicit editorial actions for selected Support Articles only.
     * These operations do not publish an article or attest to scientific verification.
     */
    /**
     * Keep legacy editorial metadata consistent with native WordPress status.
     * This hook observes status changes; it never changes the post status.
     */
    public function sync_support_article_native_state($new_status, $old_status, $post) {
        if (!$post || $post->post_type !== 'sc_support_article' || $new_status === $old_status) {
            return;
        }
        $map = array(
            'draft' => 'draft',
            'pending' => 'submitted',
            'publish' => 'published',
            'future' => 'scheduled',
            'private' => 'archived',
        );
        if (isset($map[$new_status])) {
            update_post_meta($post->ID, '_scfs_editorial_state', $map[$new_status]);
        }
    }

    public function support_article_bulk_actions($actions) {
        if ($this->can_manage() && $this->can_approve()) {
            $actions['scfs_editorial_bulk_approve'] = __('Approve selected articles (editorial)', 'sustainable-catalyst-feature-suggestions');
        }
        if ($this->can_manage()) {
            $actions['scfs_editorial_bulk_pending'] = __('Mark selected articles pending review', 'sustainable-catalyst-feature-suggestions');
        }
        return $actions;
    }

    public function handle_support_article_bulk_actions($redirect_to, $action, $post_ids) {
        if (!in_array($action, array('scfs_editorial_bulk_approve', 'scfs_editorial_bulk_pending'), true)) {
            return $redirect_to;
        }
        if (!$this->can_manage() || ($action === 'scfs_editorial_bulk_approve' && !$this->can_approve())) {
            return add_query_arg('scfs_editorial_bulk_denied', '1', $redirect_to);
        }
        // WordPress checks the bulk-action nonce before calling this filter.
        $target = $action === 'scfs_editorial_bulk_approve' ? 'approved' : 'submitted';
        $passed = 0;
        $failed = array();
        foreach (array_unique(array_map('absint', (array) $post_ids)) as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'sc_support_article' || !current_user_can('edit_post', $post_id)) {
                $failed[] = $post_id;
                continue;
            }
            // Do not demote publicly published or scheduled records through a bulk edit.
            if (in_array($post->post_status, array('publish', 'future', 'private'), true)) {
                $failed[] = $post_id;
                continue;
            }
            $result = $this->transition_record($post_id, $target, 'Owner-selected support article editorial action', get_current_user_id(), false);
            if (is_wp_error($result)) {
                $failed[] = $post_id;
            } else {
                $passed++;
            }
        }
        return add_query_arg(array(
            'scfs_editorial_bulk_updated' => $passed,
            'scfs_editorial_bulk_failed' => count($failed),
        ), $redirect_to);
    }

    public function transition_action() {
        if (!$this->can_manage()) { wp_die(__('Permission denied.', 'sustainable-catalyst-feature-suggestions')); }
        $post_id = absint($_REQUEST['post_id'] ?? 0);
        check_admin_referer('scfs_editorial_transition_' . $post_id);
        $result = $this->transition_record($post_id, sanitize_key($_REQUEST['state'] ?? ''), sanitize_textarea_field(wp_unslash($_REQUEST['note'] ?? '')), get_current_user_id(), false);
        $url = get_edit_post_link($post_id, 'raw') ?: admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE);
        if (is_wp_error($result)) { $url = add_query_arg('scfs_editorial_error', rawurlencode($result->get_error_message()), $url); }
        wp_safe_redirect($url); exit;
    }

    public function run_governance_action() {
        if (!$this->can_manage()) { wp_die(__('Permission denied.', 'sustainable-catalyst-feature-suggestions')); }
        check_admin_referer('scfs_run_editorial_governance');
        $this->run_scheduled_governance();
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&ran=1'));
        exit;
    }

    public function export_audit_action() {
        if (!$this->can_manage()) { wp_die(__('Permission denied.', 'sustainable-catalyst-feature-suggestions')); }
        check_admin_referer('scfs_export_editorial_audit');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=scfs-editorial-audit-' . gmdate('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('Audit ID', 'Time GMT', 'Post ID', 'Post Type', 'Action', 'Actor ID', 'Context JSON'));
        foreach ((array) get_option(self::AUDIT_OPTION, array()) as $entry) {
            fputcsv($out, array($entry['id'] ?? '', $entry['at'] ?? '', $entry['post_id'] ?? 0, $entry['post_type'] ?? '', $entry['action'] ?? '', $entry['actor_id'] ?? 0, wp_json_encode($entry['context'] ?? array())));
        }
        fclose($out); exit;
    }

    public function cli_queue($args, $assoc_args) {
        $records = $this->queue_records(array('state' => $assoc_args['state'] ?? '', 'limit' => $assoc_args['limit'] ?? 100));
        WP_CLI\Utils\format_items('table', $records, array('id', 'post_type', 'title', 'state', 'review_due_at'));
    }

    public function cli_run() {
        WP_CLI::success(wp_json_encode($this->run_scheduled_governance()));
    }

    public function cli_transition($args, $assoc_args) {
        $post_id = absint($args[0] ?? 0);
        $state = sanitize_key($assoc_args['state'] ?? '');
        $result = $this->transition_record($post_id, $state, sanitize_textarea_field($assoc_args['note'] ?? ''), 0, true);
        if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); }
        WP_CLI::success(sprintf('Record %d moved to %s.', $post_id, $state));
    }

    public function humanize_code($value) {
        return ucwords(str_replace(array('_', '-'), ' ', sanitize_text_field((string) $value)));
    }
}
