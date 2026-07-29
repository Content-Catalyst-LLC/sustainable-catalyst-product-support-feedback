<?php
/**
 * Plugin Name: Sustainable Catalyst Product Support and Feedback Platform
 * Description: Product support, publication-grade documentation, known issues, release intelligence, feature feedback, product-signal intelligence, documentation-effectiveness analytics, cross-product support graphs, governed platform handoffs, controlled public support APIs, product embeds, institutional support integration, private help-desk case foundations, agent workspaces, team queues, assignment operations, secure customer portals, participant conversations, satisfaction feedback, service-level policies, support calendars, governed response clocks, escalation review, secure evidence intake, controlled attachment metadata, diagnostic bundles, retention and redaction governance, knowledge-assisted case resolution, agent-reviewed support recommendations, duplicate-case review, documentation promotion, governed workflow automation, operational rules, agent macros, approval queues, follow-up scheduling, email intake, case-thread matching, governed outbound email drafts, delivery and bounce tracking, Microsoft Teams handoffs, help-desk quality assurance, operational analytics, privacy-safe support intelligence, governed quality reviews, institutional workspaces, support entitlements, private knowledge collections, explicit case access, institutional reporting, scoped help-desk APIs, signed outbound webhooks, delivery retries, dead-letter review, external system links, integration audit evidence, rate limits, abuse controls, privacy operations, backup and recovery evidence, production release gates, security health monitoring, accessibility and performance hardening, connected help-desk orchestration, end-to-end support journeys, operating dossiers, cross-module command planning, platform health snapshots, connected platform governance, surveys, editorial governance, reliability analytics, and privacy-safe support handoffs for Sustainable Catalyst.
 * Version: 7.8.1
 * Author: Content Catalyst LLC
 * License: GPL-2.0-or-later
 * Text Domain: sustainable-catalyst-feature-suggestions
 * SC Product ID: product-support-feedback
 * SC Product: true
 * SC Public Release: true
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Sustainable_Catalyst_Feature_Suggestions {
    const VERSION = '7.8.1';
    const POST_TYPE = 'sc_feature_suggest';
    const NONCE_ACTION = 'scfs_submit_suggestion';
    const NONCE_NAME = 'scfs_nonce';
    const ADMIN_NONCE_ACTION = 'scfs_save_review';
    const ADMIN_NONCE_NAME = 'scfs_review_nonce';
    const SETTINGS_NONCE_ACTION = 'scfs_save_settings';
    const OPTION_KEY = 'scfs_settings';
    const SHORTCODE = 'sustainable_catalyst_feature_suggestions';
    const REST_NAMESPACE = 'scfs/v1';
    const EVENT_SCHEMA_VERSION = '1.0';
    const WEBHOOK_QUEUE_KEY = 'scfs_webhook_queue';
    const CRON_HOOK = 'scfs_process_webhook_queue';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('admin_enqueue_scripts', array($this, 'register_admin_assets'));
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));

        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'admin_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'admin_column_content'), 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', array($this, 'sortable_columns'));
        add_action('restrict_manage_posts', array($this, 'render_admin_filters'));
        add_action('pre_get_posts', array($this, 'apply_admin_filters'));

        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save_admin_review'), 10, 2);

        add_action('admin_menu', array($this, 'add_admin_pages'));
        add_action('admin_init', array($this, 'migrate_legacy_post_type'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        add_action('admin_post_scfs_export_csv', array($this, 'export_csv'));
        add_action('admin_post_scfs_save_settings', array($this, 'save_settings'));
        add_action('admin_post_scfs_send_test_email', array($this, 'send_test_email'));
        add_action('admin_post_scfs_analyze_suggestion', array($this, 'analyze_suggestion_action'));
        add_action('admin_post_scfs_analyze_batch', array($this, 'analyze_batch_action'));
        add_action('admin_post_scfs_export_intelligence_csv', array($this, 'export_intelligence_csv'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action(self::CRON_HOOK, array($this, 'process_webhook_queue'));
        add_action('scfs_dispatch_event', array($this, 'dispatch_event_webhook'), 10, 1);
    }

    public static function activate() {
        $instance = self::instance();
        $instance->register_post_type();
        if (class_exists('SCFS_Canonical_Product_Registry')) {
            SCFS_Canonical_Product_Registry::activate();
        }
        if (class_exists('SCFS_Canonical_Product_Registry_Admin')) {
            SCFS_Canonical_Product_Registry_Admin::activate();
        }
        if (class_exists('SCFS_Installed_Plugin_Discovery')) {
            SCFS_Installed_Plugin_Discovery::activate();
        }
        if (class_exists('SCFS_GitHub_Connection_Settings')) {
            SCFS_GitHub_Connection_Settings::activate();
        }
        if (class_exists('SCFS_Canonical_Product_GitHub_Sync')) {
            SCFS_Canonical_Product_GitHub_Sync::activate();
        }
        if (class_exists('SCFS_GitHub_Release_Intelligence')) {
            SCFS_GitHub_Release_Intelligence::activate();
        }
        if (class_exists('SCFS_Release_Operations_Admin')) {
            SCFS_Release_Operations_Admin::activate();
        }
        if (class_exists('SCFS_Product_Connection_Editor')) {
            SCFS_Product_Connection_Editor::activate();
        }
        if (class_exists('SCFS_Release_Console_Copy')) {
            SCFS_Release_Console_Copy::activate();
        }
        if (class_exists('SCFS_Release_Board')) {
            SCFS_Release_Board::activate();
        }
        if (class_exists('SCFS_Product_Integration')) {
            SCFS_Product_Integration::activate();
        }
        if (class_exists('SCFS_Knowledge_Base_Foundation')) {
            SCFS_Knowledge_Base_Foundation::activate();
        }
        if (class_exists('SCFS_Integrated_Knowledge_Base')) {
            SCFS_Integrated_Knowledge_Base::activate();
        }
        if (class_exists('SCFS_Guided_Resolution')) {
            SCFS_Guided_Resolution::activate();
        }
        if (class_exists('SCFS_Documentation_Feature_Intelligence')) {
            SCFS_Documentation_Feature_Intelligence::activate();
        }
        if (class_exists('SCFS_Product_Support_Platform')) {
            SCFS_Product_Support_Platform::activate();
        }
        if (class_exists('SCFS_Support_Content_Operations')) {
            SCFS_Support_Content_Operations::activate();
        }
        if (class_exists('SCFS_Editorial_Governance')) {
            SCFS_Editorial_Governance::activate();
        }
        if (class_exists('SCFS_Support_Article_Integrity')) {
            SCFS_Support_Article_Integrity::activate();
        }
        if (class_exists('SCFS_Support_Content_Governance')) {
            SCFS_Support_Content_Governance::activate();
        }
        if (class_exists('SCFS_Feedback_Product_Signals')) {
            SCFS_Feedback_Product_Signals::activate();
        }
        if (class_exists('SCFS_Support_Analytics_Documentation_Effectiveness')) {
            SCFS_Support_Analytics_Documentation_Effectiveness::activate();
        }
        if (class_exists('SCFS_Cross_Product_Support_Graph')) {
            SCFS_Cross_Product_Support_Graph::activate();
        }
        if (class_exists('SCFS_Public_Support_Integrations')) {
            SCFS_Public_Support_Integrations::activate();
        }
        if (class_exists('SCFS_Connected_Product_Support_Platform')) {
            SCFS_Connected_Product_Support_Platform::activate();
        }
        if (class_exists('SCFS_Help_Desk_Case_Foundation')) {
            SCFS_Help_Desk_Case_Foundation::activate();
        }
        if (class_exists('SCFS_Help_Desk_Agent_Workspace')) {
            SCFS_Help_Desk_Agent_Workspace::activate();
        }
        if (class_exists('SCFS_Help_Desk_Customer_Portal')) {
            SCFS_Help_Desk_Customer_Portal::activate();
        }
        if (class_exists('SCFS_Help_Desk_Service_Levels')) {
            SCFS_Help_Desk_Service_Levels::activate();
        }
        if (class_exists('SCFS_Help_Desk_Secure_Evidence')) {
            SCFS_Help_Desk_Secure_Evidence::activate();
        }
        if (class_exists('SCFS_Help_Desk_Knowledge_Assisted_Resolution')) {
            SCFS_Help_Desk_Knowledge_Assisted_Resolution::activate();
        }
        if (class_exists('SCFS_Help_Desk_Workflow_Automation')) {
            SCFS_Help_Desk_Workflow_Automation::activate();
        }
        if (class_exists('SCFS_Help_Desk_Email_Channel_Operations')) {
            SCFS_Help_Desk_Email_Channel_Operations::activate();
        }
        if (class_exists('SCFS_Help_Desk_Quality_Analytics')) {
            SCFS_Help_Desk_Quality_Analytics::activate();
        }
        if (class_exists('SCFS_Help_Desk_Institutional_Workspaces')) {
            SCFS_Help_Desk_Institutional_Workspaces::activate();
        }
        if (class_exists('SCFS_Help_Desk_API_Integrations')) {
            SCFS_Help_Desk_API_Integrations::activate();
        }
        if (class_exists('SCFS_Help_Desk_Production_Hardening')) {
            SCFS_Help_Desk_Production_Hardening::activate();
        }
        if (class_exists('SCFS_Connected_Help_Desk_Platform')) {
            SCFS_Connected_Help_Desk_Platform::activate();
        }
        if (class_exists('SCFS_Repository_Release_Synchronization')) {
            SCFS_Repository_Release_Synchronization::activate();
        }
        if (class_exists('SCFS_Known_Issue_Release_Intelligence')) {
            SCFS_Known_Issue_Release_Intelligence::activate();
        }
        if (class_exists('SCFS_Support_Reliability_Center')) {
            SCFS_Support_Reliability_Center::activate();
        }
        if (class_exists('SCFS_Cross_Product_Support_Orchestration')) {
            SCFS_Cross_Product_Support_Orchestration::activate();
        }
        if (class_exists('SCFS_Connected_Support_Operations')) {
            SCFS_Connected_Support_Operations::activate();
        }
        $instance->migrate_legacy_post_type();
        flush_rewrite_rules();
        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, $instance->default_settings(), '', false);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'scfs_five_minutes', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        if (class_exists('SCFS_Connected_Support_Operations')) {
            SCFS_Connected_Support_Operations::deactivate();
        }
        if (class_exists('SCFS_Cross_Product_Support_Orchestration')) {
            SCFS_Cross_Product_Support_Orchestration::deactivate();
        }
        if (class_exists('SCFS_Support_Reliability_Center')) {
            SCFS_Support_Reliability_Center::deactivate();
        }
        if (class_exists('SCFS_Connected_Help_Desk_Platform')) {
            SCFS_Connected_Help_Desk_Platform::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Production_Hardening')) {
            SCFS_Help_Desk_Production_Hardening::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_API_Integrations')) {
            SCFS_Help_Desk_API_Integrations::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Institutional_Workspaces')) {
            SCFS_Help_Desk_Institutional_Workspaces::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Quality_Analytics')) {
            SCFS_Help_Desk_Quality_Analytics::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Email_Channel_Operations')) {
            SCFS_Help_Desk_Email_Channel_Operations::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Workflow_Automation')) {
            SCFS_Help_Desk_Workflow_Automation::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Knowledge_Assisted_Resolution')) {
            SCFS_Help_Desk_Knowledge_Assisted_Resolution::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Secure_Evidence')) {
            SCFS_Help_Desk_Secure_Evidence::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Service_Levels')) {
            SCFS_Help_Desk_Service_Levels::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Customer_Portal')) {
            SCFS_Help_Desk_Customer_Portal::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Agent_Workspace')) {
            SCFS_Help_Desk_Agent_Workspace::deactivate();
        }
        if (class_exists('SCFS_Help_Desk_Case_Foundation')) {
            SCFS_Help_Desk_Case_Foundation::deactivate();
        }
        if (class_exists('SCFS_Connected_Product_Support_Platform')) {
            SCFS_Connected_Product_Support_Platform::deactivate();
        }
        if (class_exists('SCFS_Canonical_Product_GitHub_Sync')) {
            SCFS_Canonical_Product_GitHub_Sync::deactivate();
        }
        if (class_exists('SCFS_Repository_Release_Synchronization')) {
            SCFS_Repository_Release_Synchronization::deactivate();
        }
        if (class_exists('SCFS_Public_Support_Integrations')) {
            SCFS_Public_Support_Integrations::deactivate();
        }
        if (class_exists('SCFS_Cross_Product_Support_Graph')) {
            SCFS_Cross_Product_Support_Graph::deactivate();
        }
        if (class_exists('SCFS_Support_Analytics_Documentation_Effectiveness')) {
            SCFS_Support_Analytics_Documentation_Effectiveness::deactivate();
        }
        if (class_exists('SCFS_Feedback_Product_Signals')) {
            SCFS_Feedback_Product_Signals::deactivate();
        }
        if (class_exists('SCFS_Support_Content_Governance')) {
            SCFS_Support_Content_Governance::deactivate();
        }
        if (class_exists('SCFS_Editorial_Governance')) {
            SCFS_Editorial_Governance::deactivate();
        }
        if (class_exists('SCFS_Support_Content_Operations')) {
            SCFS_Support_Content_Operations::deactivate();
        }
        if (class_exists('SCFS_Documentation_Feature_Intelligence')) {
            $gap_timestamp = wp_next_scheduled(SCFS_Documentation_Feature_Intelligence::GAP_REFRESH_HOOK);
            if ($gap_timestamp) {
                wp_unschedule_event($gap_timestamp, SCFS_Documentation_Feature_Intelligence::GAP_REFRESH_HOOK);
            }
        }
        flush_rewrite_rules();
    }

    public function migrate_legacy_post_type() {
        global $wpdb;
        if (!isset($wpdb->posts)) {
            return;
        }
        $legacy_types = array(
            'sc_feature_suggestion',
            'sc_feature_suggesti'
        );
        foreach ($legacy_types as $legacy_type) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->posts,
                array('post_type' => self::POST_TYPE),
                array('post_type' => $legacy_type),
                array('%s'),
                array('%s')
            );
        }
    }

    public function register_post_type() {
        $labels = array(
            'name' => __('Feature Suggestions', 'sustainable-catalyst-feature-suggestions'),
            'singular_name' => __('Feature Suggestion', 'sustainable-catalyst-feature-suggestions'),
            'menu_name' => __('Support & Feedback', 'sustainable-catalyst-feature-suggestions'),
            'add_new_item' => __('Add Feature Suggestion', 'sustainable-catalyst-feature-suggestions'),
            'edit_item' => __('Review Feature Suggestion', 'sustainable-catalyst-feature-suggestions'),
            'new_item' => __('New Feature Suggestion', 'sustainable-catalyst-feature-suggestions'),
            'view_item' => __('View Feature Suggestion', 'sustainable-catalyst-feature-suggestions'),
            'search_items' => __('Search Feature Suggestions', 'sustainable-catalyst-feature-suggestions'),
            'not_found' => __('No feature suggestions found.', 'sustainable-catalyst-feature-suggestions'),
            'not_found_in_trash' => __('No feature suggestions found in Trash.', 'sustainable-catalyst-feature-suggestions'),
        );

        register_post_type(self::POST_TYPE, array(
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-lightbulb',
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports' => array('title', 'editor'),
            'has_archive' => false,
            'rewrite' => false,
            'show_in_rest' => false,
        ));
    }

    public function register_assets() {
        wp_register_style(
            'scfs-feature-suggestions',
            plugins_url('assets/feature-suggestions.css', __FILE__),
            array(),
            self::VERSION
        );
    }

    public function register_admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && ($screen->post_type === self::POST_TYPE || strpos((string) $hook, 'scfs-') !== false)) {
            wp_enqueue_style(
                'scfs-admin',
                plugins_url('assets/feature-suggestions-admin.css', __FILE__),
                array(),
                self::VERSION
            );
        }
    }

    private function default_settings() {
        return array(
            'form_title' => 'Suggest a Sustainable Catalyst Feature',
            'form_intro' => 'Use this form to suggest platform modules, online demo improvements, repository features, data/export workflows, article map ideas, accessibility fixes, documentation improvements, or Research Librarian capabilities.',
            'submit_button_label' => 'Submit Feature Suggestion',
            'success_message' => 'Thank you. Your feature suggestion has been received.',
            'error_message' => 'Could not save the suggestion. Please check the form and try again.',
            'consent_text' => 'I understand this form is for non-confidential suggestions and does not create an obligation to implement, attribute, compensate, or respond. Do not submit confidential, proprietary, sensitive personal, medical, legal, financial, or regulated information.',
            'notification_email' => get_option('admin_email'),
            'enable_email_notifications' => '1',
            'submission_status' => 'pending',
            'require_nonce' => '',
            'show_priority' => '1',
            'show_product_context' => '1',
            'show_beneficiaries' => '1',
            'show_success_criteria' => '1',
            'show_implementation_notes' => '1',
            'show_relevant_url' => '1',
            'show_contact' => '1',
            'show_follow_up' => '1',
            'collect_user_agent' => '',
            'collect_referrer' => '1',
            'max_submissions_per_hour' => '5',
            'max_submissions_per_day' => '20',
            'min_seconds_before_submit' => '2',
            'duplicate_window_hours' => '24',
            'max_links' => '4',
            'min_problem_length' => '12',
            'min_suggestion_length' => '12',
            'categories' => implode("\n", array(
                'New platform module',
                'Online demo improvement',
                'Research Librarian feature',
                'GitHub repository improvement',
                'Data or export feature',
                'Article map or library suggestion',
                'Accessibility or usability issue',
                'Bug report',
                'Documentation improvement',
                'Consulting or workflow idea',
                'Other',
            )),
            'priorities' => "Low\nMedium\nHigh\nUrgent",
            'blocked_terms' => '',
            'enable_rest_submissions' => '1',
            'rest_require_api_key' => '',
            'rest_api_key' => '',
            'enable_shared_events' => '1',
            'enable_webhooks' => '',
            'webhook_url' => '',
            'webhook_secret' => '',
            'webhook_timeout' => '8',
            'webhook_max_attempts' => '5',
            'ai_backend_url' => '',
            'ai_backend_api_key' => '',
            'ai_auto_analyze' => '',
            'ai_timeout' => '20',
        );
    }

    private function settings() {
        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return wp_parse_args($saved, $this->default_settings());
    }

    private function setting($key) {
        $settings = $this->settings();
        return isset($settings[$key]) ? $settings[$key] : '';
    }

    private function line_list($key) {
        $settings = $this->settings();
        $raw = isset($settings[$key]) ? (string) $settings[$key] : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $out = array();
        foreach ($lines as $line) {
            $line = trim(wp_strip_all_tags($line));
            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }
        return $out;
    }

    private function categories() {
        $categories = $this->line_list('categories');
        return !empty($categories) ? $categories : $this->line_list_from_default('categories');
    }

    private function priorities() {
        $priorities = $this->line_list('priorities');
        return !empty($priorities) ? $priorities : array('Low', 'Medium', 'High');
    }

    private function line_list_from_default($key) {
        $defaults = $this->default_settings();
        $raw = isset($defaults[$key]) ? (string) $defaults[$key] : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        return array_values(array_filter(array_map('trim', $lines)));
    }

    private function review_statuses() {
        return array(
            'new' => __('New', 'sustainable-catalyst-feature-suggestions'),
            'reviewed' => __('Reviewed', 'sustainable-catalyst-feature-suggestions'),
            'planned' => __('Planned', 'sustainable-catalyst-feature-suggestions'),
            'in_progress' => __('In progress', 'sustainable-catalyst-feature-suggestions'),
            'implemented' => __('Implemented', 'sustainable-catalyst-feature-suggestions'),
            'declined' => __('Declined', 'sustainable-catalyst-feature-suggestions'),
            'duplicate' => __('Duplicate', 'sustainable-catalyst-feature-suggestions'),
            'archived' => __('Archived', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function render_shortcode($atts = array()) {
        wp_enqueue_style('scfs-feature-suggestions');

        $settings = $this->settings();
        $atts = shortcode_atts(array(
            'title' => $settings['form_title'],
            'intro' => $settings['form_intro'],
            'category' => '',
        ), $atts, self::SHORTCODE);

        $message = '';
        $message_type = '';
        $values = $this->empty_values();
        if ($atts['category'] !== '' && in_array($atts['category'], $this->categories(), true)) {
            $values['category'] = $atts['category'];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scfs_form_submitted'])) {
            $result = $this->handle_submission();
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'error';
            if (!$result['success'] && isset($result['values'])) {
                $values = $result['values'];
            }
        }

        ob_start();
        ?>
        <div class="scfs-wrap" id="feature-suggestion-form">
            <div class="scfs-intro">
                <p class="scfs-eyebrow"><?php esc_html_e('Platform Feedback', 'sustainable-catalyst-feature-suggestions'); ?></p>
                <h3><?php echo esc_html($atts['title']); ?></h3>
                <?php if (!empty($atts['intro'])) : ?>
                    <p><?php echo esc_html($atts['intro']); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($message)) : ?>
                <div class="scfs-alert scfs-alert-<?php echo esc_attr($message_type); ?>" role="status">
                    <?php echo wp_kses_post($message); ?>
                </div>
            <?php endif; ?>

            <form class="scfs-form" method="post" action="#feature-suggestion-form" novalidate>
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="scfs_form_submitted" value="1">
                <input type="hidden" name="scfs_started_at" value="<?php echo esc_attr((string) time()); ?>">

                <div class="scfs-honeypot" aria-hidden="true">
                    <label for="scfs_website">Website</label>
                    <input id="scfs_website" type="text" name="scfs_website" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="scfs-grid scfs-grid-two">
                    <p class="scfs-field">
                        <label for="scfs_title"><?php esc_html_e('Suggestion title', 'sustainable-catalyst-feature-suggestions'); ?> <span aria-hidden="true">*</span></label>
                        <input id="scfs_title" name="scfs_title" type="text" required maxlength="160" value="<?php echo esc_attr($values['title']); ?>" placeholder="<?php esc_attr_e('Example: Add CSV export to Catalyst Data demo', 'sustainable-catalyst-feature-suggestions'); ?>">
                    </p>

                    <p class="scfs-field">
                        <label for="scfs_category"><?php esc_html_e('Suggestion category', 'sustainable-catalyst-feature-suggestions'); ?> <span aria-hidden="true">*</span></label>
                        <select id="scfs_category" name="scfs_category" required>
                            <option value=""><?php esc_html_e('Choose a category', 'sustainable-catalyst-feature-suggestions'); ?></option>
                            <?php foreach ($this->categories() as $category) : ?>
                                <option value="<?php echo esc_attr($category); ?>" <?php selected($values['category'], $category); ?>><?php echo esc_html($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </div>

                <?php if ($settings['show_product_context'] === '1' && class_exists('SCFS_Product_Integration')) : ?>
                    <?php SCFS_Product_Integration::instance()->render_public_context_fields($values); ?>
                <?php endif; ?>

                <p class="scfs-field">
                    <label for="scfs_problem"><?php esc_html_e('What problem would this solve?', 'sustainable-catalyst-feature-suggestions'); ?> <span aria-hidden="true">*</span></label>
                    <textarea id="scfs_problem" name="scfs_problem" required rows="4" maxlength="2400" placeholder="<?php esc_attr_e('Describe the friction, missing capability, or user need.', 'sustainable-catalyst-feature-suggestions'); ?>"><?php echo esc_textarea($values['problem']); ?></textarea>
                </p>

                <p class="scfs-field">
                    <label for="scfs_suggestion"><?php esc_html_e('Suggested feature or improvement', 'sustainable-catalyst-feature-suggestions'); ?> <span aria-hidden="true">*</span></label>
                    <textarea id="scfs_suggestion" name="scfs_suggestion" required rows="5" maxlength="3600" placeholder="<?php esc_attr_e('Describe what you would like the platform, demo, repository, or Research Librarian to do.', 'sustainable-catalyst-feature-suggestions'); ?>"><?php echo esc_textarea($values['suggestion']); ?></textarea>
                </p>

                <?php if ($settings['show_success_criteria'] === '1') : ?>
                    <p class="scfs-field">
                        <label for="scfs_success_criteria"><?php esc_html_e('What would make this successful?', 'sustainable-catalyst-feature-suggestions'); ?></label>
                        <textarea id="scfs_success_criteria" name="scfs_success_criteria" rows="3" maxlength="1600" placeholder="<?php esc_attr_e('Optional: describe the output, workflow, or result that would make the feature useful.', 'sustainable-catalyst-feature-suggestions'); ?>"><?php echo esc_textarea($values['success_criteria']); ?></textarea>
                    </p>
                <?php endif; ?>

                <?php if ($settings['show_beneficiaries'] === '1') : ?>
                    <p class="scfs-field">
                        <label for="scfs_beneficiaries"><?php esc_html_e('Who would benefit?', 'sustainable-catalyst-feature-suggestions'); ?></label>
                        <textarea id="scfs_beneficiaries" name="scfs_beneficiaries" rows="3" maxlength="1600" placeholder="<?php esc_attr_e('Examples: researchers, students, NGOs, sustainability teams, content teams, public-interest builders.', 'sustainable-catalyst-feature-suggestions'); ?>"><?php echo esc_textarea($values['beneficiaries']); ?></textarea>
                    </p>
                <?php endif; ?>

                <?php if ($settings['show_implementation_notes'] === '1') : ?>
                    <p class="scfs-field">
                        <label for="scfs_implementation_notes"><?php esc_html_e('Implementation notes or examples', 'sustainable-catalyst-feature-suggestions'); ?></label>
                        <textarea id="scfs_implementation_notes" name="scfs_implementation_notes" rows="3" maxlength="2000" placeholder="<?php esc_attr_e('Optional: add examples, references, similar tools, or technical details.', 'sustainable-catalyst-feature-suggestions'); ?>"><?php echo esc_textarea($values['implementation_notes']); ?></textarea>
                    </p>
                <?php endif; ?>

                <div class="scfs-grid scfs-grid-two">
                    <?php if ($settings['show_relevant_url'] === '1') : ?>
                        <p class="scfs-field">
                            <label for="scfs_relevant_url"><?php esc_html_e('Relevant page, demo, or repository', 'sustainable-catalyst-feature-suggestions'); ?></label>
                            <input id="scfs_relevant_url" name="scfs_relevant_url" type="text" maxlength="400" value="<?php echo esc_attr($values['relevant_url']); ?>" placeholder="<?php esc_attr_e('Paste a URL or page name if relevant', 'sustainable-catalyst-feature-suggestions'); ?>">
                        </p>
                    <?php endif; ?>

                    <?php if ($settings['show_priority'] === '1') : ?>
                        <p class="scfs-field">
                            <label for="scfs_priority"><?php esc_html_e('Priority', 'sustainable-catalyst-feature-suggestions'); ?></label>
                            <select id="scfs_priority" name="scfs_priority">
                                <?php foreach ($this->priorities() as $priority) : ?>
                                    <option value="<?php echo esc_attr($priority); ?>" <?php selected($values['priority'], $priority); ?>><?php echo esc_html($priority); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($settings['show_contact'] === '1') : ?>
                    <div class="scfs-grid scfs-grid-two">
                        <p class="scfs-field">
                            <label for="scfs_name"><?php esc_html_e('Name', 'sustainable-catalyst-feature-suggestions'); ?></label>
                            <input id="scfs_name" name="scfs_name" type="text" maxlength="120" value="<?php echo esc_attr($values['name']); ?>" placeholder="<?php esc_attr_e('Optional', 'sustainable-catalyst-feature-suggestions'); ?>">
                        </p>

                        <p class="scfs-field">
                            <label for="scfs_email"><?php esc_html_e('Email', 'sustainable-catalyst-feature-suggestions'); ?></label>
                            <input id="scfs_email" name="scfs_email" type="email" maxlength="160" value="<?php echo esc_attr($values['email']); ?>" placeholder="<?php esc_attr_e('Optional, for follow-up only', 'sustainable-catalyst-feature-suggestions'); ?>">
                        </p>
                    </div>
                <?php endif; ?>

                <div class="scfs-checks">
                    <?php if ($settings['show_follow_up'] === '1') : ?>
                        <label class="scfs-check">
                            <input type="checkbox" name="scfs_follow_up" value="1" <?php checked($values['follow_up'], '1'); ?>>
                            <span><?php esc_html_e('You may follow up with me about this suggestion.', 'sustainable-catalyst-feature-suggestions'); ?></span>
                        </label>
                    <?php endif; ?>

                    <label class="scfs-check">
                        <input type="checkbox" name="scfs_consent" value="1" required <?php checked($values['consent'], '1'); ?>>
                        <span><?php echo wp_kses_post($settings['consent_text']); ?></span>
                    </label>
                </div>

                <button class="scfs-submit" type="submit"><?php echo esc_html($settings['submit_button_label']); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function empty_values() {
        $priorities = $this->priorities();
        return array(
            'title' => '',
            'category' => '',
            'product' => '',
            'product_version' => '',
            'component' => '',
            'issue_type' => '',
            'problem' => '',
            'beneficiaries' => '',
            'suggestion' => '',
            'success_criteria' => '',
            'implementation_notes' => '',
            'relevant_url' => '',
            'priority' => isset($priorities[1]) ? $priorities[1] : (isset($priorities[0]) ? $priorities[0] : 'Medium'),
            'name' => '',
            'email' => '',
            'follow_up' => '',
            'consent' => '',
        );
    }

    private function collect_values() {
        $values = $this->empty_values();
        $values['title'] = isset($_POST['scfs_title']) ? sanitize_text_field(wp_unslash($_POST['scfs_title'])) : '';
        $values['category'] = isset($_POST['scfs_category']) ? sanitize_text_field(wp_unslash($_POST['scfs_category'])) : '';
        $values['product'] = isset($_POST['scfs_product']) ? sanitize_title(wp_unslash($_POST['scfs_product'])) : '';
        $values['product_version'] = isset($_POST['scfs_product_version']) ? sanitize_title(wp_unslash($_POST['scfs_product_version'])) : '';
        $values['component'] = isset($_POST['scfs_component']) ? sanitize_title(wp_unslash($_POST['scfs_component'])) : '';
        $values['issue_type'] = isset($_POST['scfs_issue_type']) ? sanitize_title(wp_unslash($_POST['scfs_issue_type'])) : '';
        $values['problem'] = isset($_POST['scfs_problem']) ? sanitize_textarea_field(wp_unslash($_POST['scfs_problem'])) : '';
        $values['beneficiaries'] = isset($_POST['scfs_beneficiaries']) ? sanitize_textarea_field(wp_unslash($_POST['scfs_beneficiaries'])) : '';
        $values['suggestion'] = isset($_POST['scfs_suggestion']) ? sanitize_textarea_field(wp_unslash($_POST['scfs_suggestion'])) : '';
        $values['success_criteria'] = isset($_POST['scfs_success_criteria']) ? sanitize_textarea_field(wp_unslash($_POST['scfs_success_criteria'])) : '';
        $values['implementation_notes'] = isset($_POST['scfs_implementation_notes']) ? sanitize_textarea_field(wp_unslash($_POST['scfs_implementation_notes'])) : '';
        $values['relevant_url'] = isset($_POST['scfs_relevant_url']) ? sanitize_text_field(wp_unslash($_POST['scfs_relevant_url'])) : '';
        $values['priority'] = isset($_POST['scfs_priority']) ? sanitize_text_field(wp_unslash($_POST['scfs_priority'])) : $values['priority'];
        $values['name'] = isset($_POST['scfs_name']) ? sanitize_text_field(wp_unslash($_POST['scfs_name'])) : '';
        $values['email'] = isset($_POST['scfs_email']) ? sanitize_email(wp_unslash($_POST['scfs_email'])) : '';
        $values['follow_up'] = isset($_POST['scfs_follow_up']) ? '1' : '';
        $values['consent'] = isset($_POST['scfs_consent']) ? '1' : '';
        return $values;
    }

    private function handle_submission() {
        $settings = $this->settings();
        $values = $this->collect_values();

        if (!empty($_POST['scfs_website'])) {
            return array(
                'success' => true,
                'message' => esc_html($settings['success_message']),
            );
        }

        if ($settings['require_nonce'] === '1') {
            if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
                return array(
                    'success' => false,
                    'message' => __('Security check failed. Please refresh the page and try again. If this continues, disable strict nonce validation in the plugin settings when using page caching.', 'sustainable-catalyst-feature-suggestions'),
                    'values' => $values,
                );
            }
        }

        $spam_result = $this->passes_spam_controls($settings, $values);
        if (is_wp_error($spam_result)) {
            return array(
                'success' => false,
                'message' => esc_html($spam_result->get_error_message()),
                'values' => $values,
            );
        }

        $errors = $this->validate_values($values, $settings);
        if (!empty($errors)) {
            return array(
                'success' => false,
                'message' => '<strong>' . esc_html__('Please fix the following:', 'sustainable-catalyst-feature-suggestions') . '</strong><br>' . esc_html(implode(' ', $errors)),
                'values' => $values,
            );
        }
        if (!in_array($values['priority'], $this->priorities(), true)) {
            $priorities = $this->priorities();
            $values['priority'] = isset($priorities[0]) ? $priorities[0] : 'Medium';
        }

        $fingerprint = $this->submission_fingerprint($values);
        $duplicate_key = 'scfs_dup_' . substr($fingerprint, 0, 32);
        if ($this->int_setting($settings, 'duplicate_window_hours', 24, 0, 168) > 0 && get_transient($duplicate_key)) {
            return array(
                'success' => false,
                'message' => __('This looks like a duplicate submission. Please edit the suggestion if you meant to add new information.', 'sustainable-catalyst-feature-suggestions'),
                'values' => $values,
            );
        }

        $post_content = $this->build_post_content($values);
        $post_status = $this->allowed_post_status($settings['submission_status']);

        $post_id = wp_insert_post(wp_slash(array(
            'post_type' => self::POST_TYPE,
            'post_status' => $post_status,
            'post_title' => $values['title'],
            'post_content' => $post_content,
            'post_author' => $this->default_author_id(),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        )), true);

        if (is_wp_error($post_id) || !absint($post_id)) {
            $debug_message = is_wp_error($post_id) ? $post_id->get_error_message() : __('WordPress returned an empty post ID.', 'sustainable-catalyst-feature-suggestions');
            $message = $settings['error_message'];
            if (current_user_can('manage_options')) {
                $message .= ' ' . sprintf(__('Debug: %s', 'sustainable-catalyst-feature-suggestions'), $debug_message);
            }
            return array(
                'success' => false,
                'message' => esc_html($message),
                'values' => $values,
            );
        }

        foreach ($values as $key => $value) {
            update_post_meta($post_id, '_scfs_' . $key, $value);
        }
        update_post_meta($post_id, '_scfs_fingerprint', $fingerprint);
        update_post_meta($post_id, '_scfs_review_status', 'new');
        update_post_meta($post_id, '_scfs_impact_score', '0');
        update_post_meta($post_id, '_scfs_effort_score', '0');
        update_post_meta($post_id, '_scfs_ip_hash', $this->ip_hash());
        $submission_uuid = $this->ensure_submission_uuid($post_id);
        update_post_meta($post_id, '_scfs_source', 'shortcode_form');
        update_post_meta($post_id, '_scfs_schema_version', self::EVENT_SCHEMA_VERSION);
        if (class_exists('SCFS_Product_Integration')) {
            SCFS_Product_Integration::instance()->assign_context($post_id, $values);
        }

        if ($settings['collect_user_agent'] === '1') {
            update_post_meta($post_id, '_scfs_user_agent', isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '');
        }
        if ($settings['collect_referrer'] === '1') {
            update_post_meta($post_id, '_scfs_referrer', isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '');
        }

        if ($this->int_setting($settings, 'duplicate_window_hours', 24, 0, 168) > 0) {
            set_transient($duplicate_key, '1', HOUR_IN_SECONDS * $this->int_setting($settings, 'duplicate_window_hours', 24, 0, 168));
        }
        $this->increment_rate_limits($settings);
        $notification_result = $this->send_notification($post_id, $values, $settings);
        if ($notification_result === true) {
            update_post_meta($post_id, '_scfs_notification_status', 'sent');
        } elseif ($notification_result === false) {
            update_post_meta($post_id, '_scfs_notification_status', 'failed');
        } else {
            update_post_meta($post_id, '_scfs_notification_status', 'disabled_or_unavailable');
        }
        update_post_meta($post_id, '_scfs_notification_email', sanitize_email(isset($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email')));

        $this->publish_event('feedback.submitted', $post_id, array(
            'source' => 'shortcode_form',
            'submission_uuid' => $submission_uuid,
        ));

        return array(
            'success' => true,
            'message' => esc_html($settings['success_message']),
        );
    }

    private function validate_values($values, $settings) {
        $errors = array();
        if ($values['title'] === '') {
            $errors[] = __('Suggestion title is required.', 'sustainable-catalyst-feature-suggestions');
        }
        if ($values['category'] === '' || !in_array($values['category'], $this->categories(), true)) {
            $errors[] = __('Please choose a valid suggestion category.', 'sustainable-catalyst-feature-suggestions');
        }
        if (strlen($values['problem']) < $this->int_setting($settings, 'min_problem_length', 12, 0, 1000)) {
            $errors[] = __('Please describe the problem this would solve in a little more detail.', 'sustainable-catalyst-feature-suggestions');
        }
        if (strlen($values['suggestion']) < $this->int_setting($settings, 'min_suggestion_length', 12, 0, 1000)) {
            $errors[] = __('Please describe the suggested feature or improvement in a little more detail.', 'sustainable-catalyst-feature-suggestions');
        }
        if (!in_array($values['priority'], $this->priorities(), true)) {
            $values['priority'] = isset($this->priorities()[0]) ? $this->priorities()[0] : 'Medium';
        }
        if ($values['email'] !== '' && !is_email($values['email'])) {
            $errors[] = __('Please enter a valid email address or leave it blank.', 'sustainable-catalyst-feature-suggestions');
        }
        if ($values['consent'] !== '1') {
            $errors[] = __('Please confirm the non-confidential submission notice.', 'sustainable-catalyst-feature-suggestions');
        }
        return $errors;
    }

    private function passes_spam_controls($settings, $values) {
        $started = isset($_POST['scfs_started_at']) ? absint($_POST['scfs_started_at']) : 0;
        $min_seconds = $this->int_setting($settings, 'min_seconds_before_submit', 2, 0, 30);
        if ($min_seconds > 0 && $started > 0 && (time() - $started) < $min_seconds) {
            return new WP_Error('scfs_too_fast', __('Please take a moment to complete the form before submitting.', 'sustainable-catalyst-feature-suggestions'));
        }

        $hour_limit = $this->int_setting($settings, 'max_submissions_per_hour', 5, 0, 1000);
        $day_limit = $this->int_setting($settings, 'max_submissions_per_day', 20, 0, 5000);
        $hash = $this->ip_hash();
        if ($hash !== '') {
            if ($hour_limit > 0 && absint(get_transient('scfs_hour_' . $hash)) >= $hour_limit) {
                return new WP_Error('scfs_rate_hour', __('Too many feature suggestions were submitted recently. Please try again later.', 'sustainable-catalyst-feature-suggestions'));
            }
            if ($day_limit > 0 && absint(get_transient('scfs_day_' . $hash)) >= $day_limit) {
                return new WP_Error('scfs_rate_day', __('Too many feature suggestions were submitted today. Please try again later.', 'sustainable-catalyst-feature-suggestions'));
            }
        }

        $combined = strtolower(implode(' ', $values));
        $blocked_terms = $this->line_list('blocked_terms');
        foreach ($blocked_terms as $term) {
            if ($term !== '' && strpos($combined, strtolower($term)) !== false) {
                return new WP_Error('scfs_blocked_term', __('This suggestion could not be accepted. Please remove prohibited or spam-like content and try again.', 'sustainable-catalyst-feature-suggestions'));
            }
        }

        $max_links = $this->int_setting($settings, 'max_links', 4, 0, 100);
        if ($max_links > 0) {
            preg_match_all('/https?:\/\/|www\./i', $combined, $matches);
            if (count($matches[0]) > $max_links) {
                return new WP_Error('scfs_too_many_links', __('Please reduce the number of links in the suggestion.', 'sustainable-catalyst-feature-suggestions'));
            }
        }

        return true;
    }

    private function increment_rate_limits($settings) {
        $hash = $this->ip_hash();
        if ($hash === '') {
            return;
        }
        $hour_limit = $this->int_setting($settings, 'max_submissions_per_hour', 5, 0, 1000);
        $day_limit = $this->int_setting($settings, 'max_submissions_per_day', 20, 0, 5000);
        if ($hour_limit > 0) {
            $key = 'scfs_hour_' . $hash;
            $count = absint(get_transient($key));
            set_transient($key, $count + 1, HOUR_IN_SECONDS);
        }
        if ($day_limit > 0) {
            $key = 'scfs_day_' . $hash;
            $count = absint(get_transient($key));
            set_transient($key, $count + 1, DAY_IN_SECONDS);
        }
    }

    private function submission_fingerprint($values) {
        $basis = strtolower(trim($values['title'] . '|' . $values['email'] . '|' . $values['suggestion']));
        return hash('sha256', $basis . '|' . wp_salt('auth'));
    }

    private function build_post_content($values) {
        $sections = array(
            'Problem' => $values['problem'],
            'Suggested feature' => $values['suggestion'],
            'Success criteria' => $values['success_criteria'],
            'Who benefits' => $values['beneficiaries'],
            'Implementation notes' => $values['implementation_notes'],
            'Relevant page/demo/repository' => $values['relevant_url'],
        );
        $content = '';
        foreach ($sections as $label => $value) {
            if ($value !== '') {
                $content .= $label . ":\n" . $value . "\n\n";
            }
        }
        return trim($content);
    }

    private function default_author_id() {
        $current = get_current_user_id();
        if ($current) {
            return $current;
        }
        $admin_email = get_option('admin_email');
        if ($admin_email) {
            $user = get_user_by('email', $admin_email);
            if ($user && !empty($user->ID)) {
                return absint($user->ID);
            }
        }
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ids'));
        if (!empty($admins[0])) {
            return absint($admins[0]);
        }
        return 0;
    }

    private function ip_hash() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ($ip === '') {
            return '';
        }
        return hash('sha256', $ip . wp_salt('nonce'));
    }

    private function send_notification($post_id, $values, $settings) {
        if ($settings['enable_email_notifications'] !== '1') {
            return null;
        }
        $to = sanitize_email($settings['notification_email']);
        if (!$to || !is_email($to)) {
            $to = get_option('admin_email');
        }
        if (!$to || !is_email($to)) {
            return null;
        }

        $subject = sprintf('[Sustainable Catalyst] Feature suggestion: %s', $values['title']);
        $edit_link = admin_url('post.php?post=' . absint($post_id) . '&action=edit');
        $body = "A new feature suggestion was submitted.\n\n";
        $body .= "Title: " . $values['title'] . "\n";
        $body .= "Category: " . $values['category'] . "\n";
        $body .= "Priority: " . $values['priority'] . "\n";
        $body .= "Relevant page/repo: " . $values['relevant_url'] . "\n\n";
        $body .= "Problem it solves:\n" . $values['problem'] . "\n\n";
        $body .= "Suggested feature:\n" . $values['suggestion'] . "\n\n";
        if ($values['success_criteria'] !== '') {
            $body .= "Success criteria:\n" . $values['success_criteria'] . "\n\n";
        }
        if ($values['beneficiaries'] !== '') {
            $body .= "Who benefits:\n" . $values['beneficiaries'] . "\n\n";
        }
        if ($values['implementation_notes'] !== '') {
            $body .= "Implementation notes:\n" . $values['implementation_notes'] . "\n\n";
        }
        $body .= "Name: " . $values['name'] . "\n";
        $body .= "Email: " . $values['email'] . "\n";
        $body .= "Follow-up allowed: " . ($values['follow_up'] === '1' ? 'Yes' : 'No') . "\n\n";
        $body .= "Review in WordPress: " . $edit_link . "\n";

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        return wp_mail($to, $subject, $body, $headers);
    }

    public function admin_columns($columns) {
        $new = array();
        $new['cb'] = isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />';
        $new['title'] = __('Suggestion', 'sustainable-catalyst-feature-suggestions');
        $new['scfs_product'] = __('Product', 'sustainable-catalyst-feature-suggestions');
        $new['scfs_category'] = __('Category', 'sustainable-catalyst-feature-suggestions');
        $new['scfs_priority'] = __('Priority', 'sustainable-catalyst-feature-suggestions');
        $new['scfs_workflow'] = __('Workflow', 'sustainable-catalyst-feature-suggestions');
        $new['scfs_contact'] = __('Contact', 'sustainable-catalyst-feature-suggestions');
        $new['date'] = __('Date', 'sustainable-catalyst-feature-suggestions');
        return $new;
    }

    public function admin_column_content($column, $post_id) {
        if ($column === 'scfs_product') {
            $terms = get_the_terms($post_id, 'scfs_product');
            echo esc_html(($terms && !is_wp_error($terms)) ? implode(', ', wp_list_pluck($terms, 'name')) : '—');
        } elseif ($column === 'scfs_category') {
            echo esc_html(get_post_meta($post_id, '_scfs_category', true));
        } elseif ($column === 'scfs_priority') {
            echo esc_html(get_post_meta($post_id, '_scfs_priority', true));
        } elseif ($column === 'scfs_workflow') {
            $status = get_post_meta($post_id, '_scfs_review_status', true);
            $statuses = $this->review_statuses();
            $impact = get_post_meta($post_id, '_scfs_impact_score', true);
            $effort = get_post_meta($post_id, '_scfs_effort_score', true);
            echo '<strong>' . esc_html(isset($statuses[$status]) ? $statuses[$status] : __('New', 'sustainable-catalyst-feature-suggestions')) . '</strong>';
            echo '<br><small>' . esc_html(sprintf(__('Impact %s / Effort %s', 'sustainable-catalyst-feature-suggestions'), $impact === '' ? '0' : $impact, $effort === '' ? '0' : $effort)) . '</small>';
        } elseif ($column === 'scfs_contact') {
            $name = get_post_meta($post_id, '_scfs_name', true);
            $email = get_post_meta($post_id, '_scfs_email', true);
            $follow = get_post_meta($post_id, '_scfs_follow_up', true) === '1' ? __('Follow-up OK', 'sustainable-catalyst-feature-suggestions') : __('No follow-up', 'sustainable-catalyst-feature-suggestions');
            echo esc_html(trim($name . ' ' . $email));
            echo '<br><small>' . esc_html($follow) . '</small>';
        }
    }

    public function sortable_columns($columns) {
        $columns['scfs_priority'] = 'scfs_priority';
        $columns['scfs_category'] = 'scfs_category';
        $columns['scfs_workflow'] = 'scfs_workflow';
        return $columns;
    }

    public function render_admin_filters() {
        global $typenow;
        if ($typenow !== self::POST_TYPE) {
            return;
        }
        $this->render_filter_select('scfs_filter_category', __('All categories', 'sustainable-catalyst-feature-suggestions'), $this->categories(), isset($_GET['scfs_filter_category']) ? sanitize_text_field(wp_unslash($_GET['scfs_filter_category'])) : '');
        $this->render_filter_select('scfs_filter_priority', __('All priorities', 'sustainable-catalyst-feature-suggestions'), $this->priorities(), isset($_GET['scfs_filter_priority']) ? sanitize_text_field(wp_unslash($_GET['scfs_filter_priority'])) : '');
        $this->render_filter_select('scfs_filter_review_status', __('All workflow statuses', 'sustainable-catalyst-feature-suggestions'), $this->review_statuses(), isset($_GET['scfs_filter_review_status']) ? sanitize_text_field(wp_unslash($_GET['scfs_filter_review_status'])) : '');
        foreach (array('scfs_product' => __('All products', 'sustainable-catalyst-feature-suggestions'), 'scfs_component' => __('All components', 'sustainable-catalyst-feature-suggestions'), 'scfs_issue_type' => __('All issue types', 'sustainable-catalyst-feature-suggestions'), 'scfs_release' => __('All releases', 'sustainable-catalyst-feature-suggestions')) as $taxonomy => $label) {
            $selected = isset($_GET[$taxonomy]) ? sanitize_title(wp_unslash($_GET[$taxonomy])) : '';
            wp_dropdown_categories(array('show_option_all' => $label, 'taxonomy' => $taxonomy, 'name' => $taxonomy, 'orderby' => 'name', 'selected' => $selected, 'hierarchical' => true, 'hide_empty' => false, 'value_field' => 'slug'));
        }
    }

    private function render_filter_select($name, $all_label, $options, $selected) {
        echo '<select name="' . esc_attr($name) . '">';
        echo '<option value="">' . esc_html($all_label) . '</option>';
        foreach ($options as $key => $label) {
            if (is_int($key)) {
                $key = $label;
            }
            echo '<option value="' . esc_attr($key) . '" ' . selected($selected, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function apply_admin_filters($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        $meta_query = array();
        $filters = array(
            'scfs_filter_category' => '_scfs_category',
            'scfs_filter_priority' => '_scfs_priority',
            'scfs_filter_review_status' => '_scfs_review_status',
        );
        foreach ($filters as $get_key => $meta_key) {
            if (!empty($_GET[$get_key])) {
                $meta_query[] = array(
                    'key' => $meta_key,
                    'value' => sanitize_text_field(wp_unslash($_GET[$get_key])),
                    'compare' => '=',
                );
            }
        }
        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
        $tax_query = array();
        foreach (array('scfs_product', 'scfs_component', 'scfs_issue_type', 'scfs_release') as $taxonomy) {
            if (!empty($_GET[$taxonomy])) {
                $tax_query[] = array('taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => sanitize_title(wp_unslash($_GET[$taxonomy])));
            }
        }
        if ($tax_query) {
            $query->set('tax_query', $tax_query);
        }

        $orderby = $query->get('orderby');
        if ($orderby === 'scfs_priority') {
            $query->set('meta_key', '_scfs_priority');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'scfs_category') {
            $query->set('meta_key', '_scfs_category');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'scfs_workflow') {
            $query->set('meta_key', '_scfs_review_status');
            $query->set('orderby', 'meta_value');
        }
    }

    public function add_meta_boxes() {
        add_meta_box(
            'scfs_details',
            __('Suggestion Details', 'sustainable-catalyst-feature-suggestions'),
            array($this, 'render_details_metabox'),
            self::POST_TYPE,
            'normal',
            'high'
        );
        add_meta_box(
            'scfs_workflow',
            __('Review Workflow', 'sustainable-catalyst-feature-suggestions'),
            array($this, 'render_workflow_metabox'),
            self::POST_TYPE,
            'side',
            'high'
        );
        add_meta_box(
            'scfs_ai_triage',
            __('AI Triage', 'sustainable-catalyst-feature-suggestions'),
            array($this, 'render_ai_metabox'),
            self::POST_TYPE,
            'normal',
            'default'
        );
        add_meta_box(
            'scfs_submission_meta',
            __('Submission Metadata', 'sustainable-catalyst-feature-suggestions'),
            array($this, 'render_submission_meta_metabox'),
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render_details_metabox($post) {
        $fields = array(
            'category' => 'Category',
            'priority' => 'Priority',
            'problem' => 'Problem it solves',
            'suggestion' => 'Suggested feature',
            'success_criteria' => 'Success criteria',
            'beneficiaries' => 'Who benefits',
            'implementation_notes' => 'Implementation notes',
            'relevant_url' => 'Relevant page/demo/repository',
            'name' => 'Name',
            'email' => 'Email',
            'follow_up' => 'Follow-up allowed',
            'consent' => 'Consent confirmed',
        );

        echo '<div class="scfs-admin-details">';
        foreach ($fields as $key => $label) {
            $value = get_post_meta($post->ID, '_scfs_' . $key, true);
            if ($key === 'follow_up' || $key === 'consent') {
                $value = $value === '1' ? 'Yes' : 'No';
            }
            echo '<div class="scfs-admin-row">';
            echo '<strong>' . esc_html($label) . '</strong>';
            echo '<p>' . nl2br(esc_html($value)) . '</p>';
            echo '</div>';
        }
        echo '</div>';
    }

    public function render_workflow_metabox($post) {
        wp_nonce_field(self::ADMIN_NONCE_ACTION, self::ADMIN_NONCE_NAME);
        $review_status = get_post_meta($post->ID, '_scfs_review_status', true);
        $impact = get_post_meta($post->ID, '_scfs_impact_score', true);
        $effort = get_post_meta($post->ID, '_scfs_effort_score', true);
        $roadmap_area = get_post_meta($post->ID, '_scfs_roadmap_area', true);
        $github_issue_url = get_post_meta($post->ID, '_scfs_github_issue_url', true);
        $admin_notes = get_post_meta($post->ID, '_scfs_admin_notes', true);
        ?>
        <p>
            <label for="scfs_review_status"><strong><?php esc_html_e('Workflow status', 'sustainable-catalyst-feature-suggestions'); ?></strong></label><br>
            <select id="scfs_review_status" name="scfs_review_status" class="widefat">
                <?php foreach ($this->review_statuses() as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($review_status ? $review_status : 'new', $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="scfs_impact_score"><strong><?php esc_html_e('Impact score', 'sustainable-catalyst-feature-suggestions'); ?></strong></label><br>
            <select id="scfs_impact_score" name="scfs_impact_score" class="widefat">
                <?php for ($i = 0; $i <= 5; $i++) : ?>
                    <option value="<?php echo esc_attr((string) $i); ?>" <?php selected((string) $impact, (string) $i); ?>><?php echo esc_html((string) $i); ?></option>
                <?php endfor; ?>
            </select>
        </p>
        <p>
            <label for="scfs_effort_score"><strong><?php esc_html_e('Effort score', 'sustainable-catalyst-feature-suggestions'); ?></strong></label><br>
            <select id="scfs_effort_score" name="scfs_effort_score" class="widefat">
                <?php for ($i = 0; $i <= 5; $i++) : ?>
                    <option value="<?php echo esc_attr((string) $i); ?>" <?php selected((string) $effort, (string) $i); ?>><?php echo esc_html((string) $i); ?></option>
                <?php endfor; ?>
            </select>
        </p>
        <p>
            <label for="scfs_roadmap_area"><strong><?php esc_html_e('Roadmap area', 'sustainable-catalyst-feature-suggestions'); ?></strong></label>
            <input id="scfs_roadmap_area" name="scfs_roadmap_area" type="text" class="widefat" value="<?php echo esc_attr($roadmap_area); ?>" placeholder="Research Library, GitHub, Demo, etc.">
        </p>
        <p>
            <label for="scfs_github_issue_url"><strong><?php esc_html_e('GitHub issue / task URL', 'sustainable-catalyst-feature-suggestions'); ?></strong></label>
            <input id="scfs_github_issue_url" name="scfs_github_issue_url" type="url" class="widefat" value="<?php echo esc_attr($github_issue_url); ?>">
        </p>
        <p>
            <label for="scfs_admin_notes"><strong><?php esc_html_e('Internal notes', 'sustainable-catalyst-feature-suggestions'); ?></strong></label>
            <textarea id="scfs_admin_notes" name="scfs_admin_notes" rows="5" class="widefat"><?php echo esc_textarea($admin_notes); ?></textarea>
        </p>
        <?php
    }


    public function render_ai_metabox($post) {
        $status = get_post_meta($post->ID, '_scfs_ai_analysis_status', true);
        $analysis = get_post_meta($post->ID, '_scfs_ai_analysis', true);
        $error = get_post_meta($post->ID, '_scfs_ai_analysis_error', true);
        $url = wp_nonce_url(admin_url('admin-post.php?action=scfs_analyze_suggestion&post_id=' . $post->ID), 'scfs_analyze_' . $post->ID);
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Analyze or reanalyze submission', 'sustainable-catalyst-feature-suggestions') . '</a></p>';
        echo '<p><strong>' . esc_html__('Status:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html($status ? $status : 'not analyzed') . '</p>';
        if ($error) echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
        if (is_array($analysis)) {
            $labels = array('summary'=>'Summary','feature_type'=>'Feature type','platform_area'=>'Platform area','suggested_action'=>'Suggested action','suggested_roadmap_destination'=>'Roadmap destination','confidence'=>'Confidence','provider'=>'Provider','model'=>'Model');
            echo '<table class="widefat striped"><tbody>';
            foreach ($labels as $key=>$label) if (isset($analysis[$key])) echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html(is_scalar($analysis[$key]) ? (string)$analysis[$key] : wp_json_encode($analysis[$key])) . '</td></tr>';
            if (!empty($analysis['topics'])) echo '<tr><th>Topics</th><td>' . esc_html(implode(', ', (array)$analysis['topics'])) . '</td></tr>';
            if (!empty($analysis['scores'])) echo '<tr><th>Scores</th><td><code>' . esc_html(wp_json_encode($analysis['scores'])) . '</code></td></tr>';
            if (!empty($analysis['safety_flags'])) echo '<tr><th>Safety flags</th><td>' . esc_html(implode(', ', (array)$analysis['safety_flags'])) . '</td></tr>';
            echo '</tbody></table><p class="description">AI output is advisory. Review the original submission before changing workflow status or scores.</p>';
        }
    }

    public function render_submission_meta_metabox($post) {
        $fields = array(
            'ip_hash' => __('IP hash', 'sustainable-catalyst-feature-suggestions'),
            'referrer' => __('Referrer', 'sustainable-catalyst-feature-suggestions'),
            'user_agent' => __('User agent', 'sustainable-catalyst-feature-suggestions'),
            'fingerprint' => __('Duplicate fingerprint', 'sustainable-catalyst-feature-suggestions'),
        );
        echo '<div class="scfs-admin-meta">';
        foreach ($fields as $key => $label) {
            $value = get_post_meta($post->ID, '_scfs_' . $key, true);
            if ($value === '') {
                continue;
            }
            echo '<p><strong>' . esc_html($label) . '</strong><br><code>' . esc_html($value) . '</code></p>';
        }
        echo '</div>';
    }

    public function save_admin_review($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST[self::ADMIN_NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::ADMIN_NONCE_NAME])), self::ADMIN_NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $old_review_status = get_post_meta($post_id, '_scfs_review_status', true);
        $statuses = $this->review_statuses();
        $review_status = isset($_POST['scfs_review_status']) ? sanitize_text_field(wp_unslash($_POST['scfs_review_status'])) : 'new';
        if (!isset($statuses[$review_status])) {
            $review_status = 'new';
        }
        update_post_meta($post_id, '_scfs_review_status', $review_status);
        update_post_meta($post_id, '_scfs_impact_score', isset($_POST['scfs_impact_score']) ? min(5, max(0, absint($_POST['scfs_impact_score']))) : 0);
        update_post_meta($post_id, '_scfs_effort_score', isset($_POST['scfs_effort_score']) ? min(5, max(0, absint($_POST['scfs_effort_score']))) : 0);
        update_post_meta($post_id, '_scfs_roadmap_area', isset($_POST['scfs_roadmap_area']) ? sanitize_text_field(wp_unslash($_POST['scfs_roadmap_area'])) : '');
        update_post_meta($post_id, '_scfs_github_issue_url', isset($_POST['scfs_github_issue_url']) ? esc_url_raw(wp_unslash($_POST['scfs_github_issue_url'])) : '');
        update_post_meta($post_id, '_scfs_admin_notes', isset($_POST['scfs_admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['scfs_admin_notes'])) : '');
        $this->ensure_submission_uuid($post_id);
        $this->publish_event('feedback.reviewed', $post_id, array('previous_review_status' => $old_review_status));
        if ($old_review_status !== $review_status) {
            $this->publish_event('feedback.status_changed', $post_id, array(
                'previous_review_status' => $old_review_status,
                'review_status' => $review_status,
            ));
        }
    }

    private function settings_capability() {
        /**
         * Capability required to manage Feature Suggestion settings.
         *
         * Defaulting to edit_posts keeps the settings page reachable on hosts or
         * multisite configurations where a site owner can manage the plugin UI but
         * WordPress does not grant manage_options in the expected way.
         */
        return apply_filters('scfs_settings_capability', 'edit_posts');
    }

    private function can_manage_settings() {
        return current_user_can($this->settings_capability()) || current_user_can('manage_options') || current_user_can('activate_plugins');
    }

    public function add_admin_pages() {
        $settings_capability = $this->settings_capability();

        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __('Product Support and Feedback Settings', 'sustainable-catalyst-feature-suggestions'),
            __('Settings', 'sustainable-catalyst-feature-suggestions'),
            $settings_capability,
            'scfs-settings',
            array($this, 'render_settings_page')
        );
        add_options_page(
            __('Product Support and Feedback Settings', 'sustainable-catalyst-feature-suggestions'),
            __('Support & Feedback', 'sustainable-catalyst-feature-suggestions'),
            $settings_capability,
            'scfs-settings',
            array($this, 'render_settings_page')
        );
        add_submenu_page(
            null,
            __('Product Support and Feedback Settings', 'sustainable-catalyst-feature-suggestions'),
            __('Product Support and Feedback Settings', 'sustainable-catalyst-feature-suggestions'),
            $settings_capability,
            'scfs-settings-standalone',
            array($this, 'render_settings_page')
        );
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __('Feedback Intelligence Dashboard', 'sustainable-catalyst-feature-suggestions'),
            __('Intelligence', 'sustainable-catalyst-feature-suggestions'),
            'edit_posts',
            'scfs-intelligence',
            array($this, 'render_intelligence_page')
        );
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __('Integration Status', 'sustainable-catalyst-feature-suggestions'),
            __('Integration', 'sustainable-catalyst-feature-suggestions'),
            $settings_capability,
            'scfs-integration',
            array($this, 'render_integration_page')
        );
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __('Export Suggestions', 'sustainable-catalyst-feature-suggestions'),
            __('Export CSV', 'sustainable-catalyst-feature-suggestions'),
            'edit_posts',
            'scfs-export',
            array($this, 'render_export_page')
        );
    }

    public function plugin_action_links($links) {
        $settings_url = admin_url('admin.php?page=scfs-settings-standalone');
        $submissions_url = admin_url('edit.php?post_type=' . self::POST_TYPE);
        $export_url = admin_url('edit.php?post_type=' . self::POST_TYPE . '&page=scfs-export');

        $custom_links = array(
            'scfs_submissions' => '<a href="' . esc_url($submissions_url) . '">' . esc_html__('Submissions', 'sustainable-catalyst-feature-suggestions') . '</a>',
        );

        if ($this->can_manage_settings()) {
            $custom_links['scfs_settings'] = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'sustainable-catalyst-feature-suggestions') . '</a>';
        }

        if (current_user_can('edit_posts')) {
            $custom_links['scfs_export'] = '<a href="' . esc_url($export_url) . '">' . esc_html__('Export CSV', 'sustainable-catalyst-feature-suggestions') . '</a>';
        }

        return array_merge($custom_links, $links);
    }

    public function render_settings_page() {
        if (!$this->can_manage_settings()) {
            wp_die(__('You do not have permission to manage these settings.', 'sustainable-catalyst-feature-suggestions'));
        }
        $settings = $this->settings();
        ?>
        <div class="wrap scfs-settings-page">
            <h1><?php esc_html_e('Product Support and Feedback Settings', 'sustainable-catalyst-feature-suggestions'); ?></h1>
            <?php if (!empty($_GET['settings-updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'sustainable-catalyst-feature-suggestions'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['test-email'])) : ?>
                <?php if ($_GET['test-email'] === 'sent') : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test email sent according to WordPress. If you do not receive it, check WP Mail SMTP logs, spam, or your SMTP provider.', 'sustainable-catalyst-feature-suggestions'); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e('WordPress could not send the test email. Check WP Mail SMTP configuration and mail logs.', 'sustainable-catalyst-feature-suggestions'); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="scfs_save_settings">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION, 'scfs_settings_nonce'); ?>

                <div class="scfs-settings-grid">
                    <section class="scfs-settings-card">
                        <h2><?php esc_html_e('Form Content', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                        <?php $this->text_input('form_title', __('Form title', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->textarea_input('form_intro', __('Intro text', 'sustainable-catalyst-feature-suggestions'), $settings, 3); ?>
                        <?php $this->text_input('submit_button_label', __('Submit button label', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->textarea_input('success_message', __('Success message', 'sustainable-catalyst-feature-suggestions'), $settings, 2); ?>
                        <?php $this->textarea_input('error_message', __('Generic save error message', 'sustainable-catalyst-feature-suggestions'), $settings, 2); ?>
                        <?php $this->textarea_input('consent_text', __('Consent text', 'sustainable-catalyst-feature-suggestions'), $settings, 4); ?>
                    </section>

                    <section class="scfs-settings-card">
                        <h2><?php esc_html_e('Fields and Taxonomy', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                        <?php $this->checkbox_input('show_priority', __('Show priority field', 'sustainable-catalyst-feature-suggestions'), $settings);
                    $this->checkbox_input('show_product_context', __('Show product context fields', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->checkbox_input('show_beneficiaries', __('Show beneficiaries field', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->checkbox_input('show_success_criteria', __('Show success criteria field', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->checkbox_input('show_implementation_notes', __('Show implementation notes field', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->checkbox_input('show_relevant_url', __('Show relevant URL field', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->checkbox_input('show_contact', __('Show name and email fields', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->checkbox_input('show_follow_up', __('Show follow-up permission checkbox', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->textarea_input('categories', __('Categories, one per line', 'sustainable-catalyst-feature-suggestions'), $settings, 8); ?>
                        <?php $this->textarea_input('priorities', __('Priorities, one per line', 'sustainable-catalyst-feature-suggestions'), $settings, 4); ?>
                    </section>

                    <section class="scfs-settings-card">
                        <h2><?php esc_html_e('Submission Handling', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                        <p class="scfs-setting-field">
                            <label for="scfs_submission_status"><strong><?php esc_html_e('Default saved status', 'sustainable-catalyst-feature-suggestions'); ?></strong></label>
                            <select id="scfs_submission_status" name="scfs_settings[submission_status]">
                                <?php foreach (array('pending' => 'Pending Review', 'private' => 'Private', 'draft' => 'Draft', 'publish' => 'Published internally') as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['submission_status'], $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <?php $this->checkbox_input('enable_email_notifications', __('Send email notifications', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->text_input('notification_email', __('Notification email', 'sustainable-catalyst-feature-suggestions'), $settings, 'email'); ?>
                        <p class="scfs-setting-field">
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_send_test_email'), 'scfs_send_test_email')); ?>"><?php esc_html_e('Send test notification email', 'sustainable-catalyst-feature-suggestions'); ?></a>
                            <span class="description"><?php esc_html_e('Use this to verify WP Mail SMTP or your host mail configuration.', 'sustainable-catalyst-feature-suggestions'); ?></span>
                        </p>
                        <?php $this->checkbox_input('collect_referrer', __('Store referrer URL', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->checkbox_input('collect_user_agent', __('Store user agent', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                    </section>

                    <section class="scfs-settings-card">
                        <h2><?php esc_html_e('Spam and Abuse Controls', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                        <p class="description"><?php esc_html_e('Strict nonce validation is off by default because cached WordPress pages often break public form nonces. Honeypot, duplicate detection, link limits, blocked terms, and rate limiting remain active.', 'sustainable-catalyst-feature-suggestions'); ?></p>
                        <?php $this->checkbox_input('require_nonce', __('Require strict WordPress nonce validation', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->number_input('max_submissions_per_hour', __('Max submissions per IP hash per hour', 'sustainable-catalyst-feature-suggestions'), $settings, 0, 1000); ?>
                        <?php $this->number_input('max_submissions_per_day', __('Max submissions per IP hash per day', 'sustainable-catalyst-feature-suggestions'), $settings, 0, 5000); ?>
                        <?php $this->number_input('min_seconds_before_submit', __('Minimum seconds before submit', 'sustainable-catalyst-feature-suggestions'), $settings, 0, 30); ?>
                        <?php $this->number_input('duplicate_window_hours', __('Duplicate detection window, hours', 'sustainable-catalyst-feature-suggestions'), $settings, 0, 168); ?>
                        <?php $this->number_input('max_links', __('Maximum links per suggestion', 'sustainable-catalyst-feature-suggestions'), $settings, 0, 100); ?>
                        <?php $this->number_input('min_problem_length', __('Minimum problem length', 'sustainable-catalyst-feature-suggestions'), $settings, 0, 1000); ?>
                        <?php $this->number_input('min_suggestion_length', __('Minimum suggestion length', 'sustainable-catalyst-feature-suggestions'), $settings, 0, 1000); ?>
                        <?php $this->textarea_input('blocked_terms', __('Blocked terms, one per line', 'sustainable-catalyst-feature-suggestions'), $settings, 6); ?>
                    </section>

                    <section class="scfs-settings-card">
                        <h2><?php esc_html_e('REST API and Shared Events', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                        <?php $this->checkbox_input('enable_rest_submissions', __('Enable public REST submissions', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->checkbox_input('rest_require_api_key', __('Require API key for REST submissions', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->text_input('rest_api_key', __('REST submission API key', 'sustainable-catalyst-feature-suggestions'), $settings, 'password'); ?>
                        <?php $this->checkbox_input('enable_shared_events', __('Publish WordPress shared events', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->checkbox_input('enable_webhooks', __('Send signed event webhooks', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->text_input('webhook_url', __('Webhook URL', 'sustainable-catalyst-feature-suggestions'), $settings, 'url'); ?>
                        <?php $this->text_input('webhook_secret', __('Webhook signing secret', 'sustainable-catalyst-feature-suggestions'), $settings, 'password'); ?>
                        <?php $this->number_input('webhook_timeout', __('Webhook timeout, seconds', 'sustainable-catalyst-feature-suggestions'), $settings, 2, 30); ?>
                        <?php $this->number_input('webhook_max_attempts', __('Webhook maximum attempts', 'sustainable-catalyst-feature-suggestions'), $settings, 1, 10); ?>
                        <p class="description"><?php esc_html_e('Webhooks contain privacy-minimized event records. Names, email addresses, IP hashes, and free-text submission bodies are excluded.', 'sustainable-catalyst-feature-suggestions'); ?></p>
                        <hr>
                        <h3><?php esc_html_e('Python AI Triage', 'sustainable-catalyst-feature-suggestions'); ?></h3>
                        <?php $this->text_input('ai_backend_url', __('AI backend URL', 'sustainable-catalyst-feature-suggestions'), $settings, 'url'); ?>
                        <?php $this->text_input('ai_backend_api_key', __('AI backend API key', 'sustainable-catalyst-feature-suggestions'), $settings, 'password'); ?>
                        <?php $this->checkbox_input('ai_auto_analyze', __('Automatically analyze new submissions', 'sustainable-catalyst-feature-suggestions'), $settings); ?>
                        <?php $this->number_input('ai_timeout', __('AI request timeout, seconds', 'sustainable-catalyst-feature-suggestions'), $settings, 5, 60); ?>
                        <p class="description"><?php esc_html_e('AI output is advisory and requires human review. Original submissions are never overwritten.', 'sustainable-catalyst-feature-suggestions'); ?></p>

                    </section>
                </div>

                <?php submit_button(__('Save Product Support and Feedback Settings', 'sustainable-catalyst-feature-suggestions')); ?>
            </form>
        </div>
        <?php
    }

    private function text_input($key, $label, $settings, $type = 'text') {
        echo '<p class="scfs-setting-field"><label for="scfs_' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label>';
        echo '<input id="scfs_' . esc_attr($key) . '" type="' . esc_attr($type) . '" name="scfs_settings[' . esc_attr($key) . ']" value="' . esc_attr($settings[$key]) . '" class="regular-text"></p>';
    }

    private function textarea_input($key, $label, $settings, $rows = 4) {
        echo '<p class="scfs-setting-field"><label for="scfs_' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label>';
        echo '<textarea id="scfs_' . esc_attr($key) . '" name="scfs_settings[' . esc_attr($key) . ']" rows="' . esc_attr((string) $rows) . '" class="large-text">' . esc_textarea($settings[$key]) . '</textarea></p>';
    }

    private function checkbox_input($key, $label, $settings) {
        echo '<p class="scfs-setting-field scfs-setting-check"><label><input type="checkbox" name="scfs_settings[' . esc_attr($key) . ']" value="1" ' . checked(isset($settings[$key]) ? $settings[$key] : '', '1', false) . '> ' . esc_html($label) . '</label></p>';
    }

    private function number_input($key, $label, $settings, $min, $max) {
        echo '<p class="scfs-setting-field"><label for="scfs_' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label>';
        echo '<input id="scfs_' . esc_attr($key) . '" type="number" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '" name="scfs_settings[' . esc_attr($key) . ']" value="' . esc_attr($settings[$key]) . '" class="small-text"></p>';
    }

    public function send_test_email() {
        if (!$this->can_manage_settings()) {
            wp_die(__('You do not have permission to send a test email.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer('scfs_send_test_email');
        $settings = $this->settings();
        $to = sanitize_email(isset($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email'));
        if (!$to || !is_email($to)) {
            $to = get_option('admin_email');
        }
        $sent = false;
        if ($to && is_email($to)) {
            $subject = '[Sustainable Catalyst] Feature suggestions test email';
            $body = "This is a test email from the Sustainable Catalyst Product Support and Feedback Platform.\n\nIf you received this, WordPress mail delivery is working for plugin notifications.";
            $headers = array('Content-Type: text/plain; charset=UTF-8');
            $sent = wp_mail($to, $subject, $body, $headers);
        }
        wp_safe_redirect(add_query_arg(array('page' => 'scfs-settings-standalone', 'test-email' => $sent ? 'sent' : 'failed'), admin_url('admin.php')));
        exit;
    }

    public function save_settings() {
        if (!$this->can_manage_settings()) {
            wp_die(__('You do not have permission to manage these settings.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::SETTINGS_NONCE_ACTION, 'scfs_settings_nonce');
        $defaults = $this->default_settings();
        $incoming = isset($_POST['scfs_settings']) && is_array($_POST['scfs_settings']) ? wp_unslash($_POST['scfs_settings']) : array();
        $settings = array();

        foreach ($defaults as $key => $default) {
            if (in_array($key, array('show_priority', 'show_product_context', 'show_beneficiaries', 'show_success_criteria', 'show_implementation_notes', 'show_relevant_url', 'show_contact', 'show_follow_up', 'enable_email_notifications', 'collect_user_agent', 'collect_referrer', 'require_nonce', 'enable_rest_submissions', 'rest_require_api_key', 'enable_shared_events', 'enable_webhooks', 'ai_auto_analyze'), true)) {
                $settings[$key] = isset($incoming[$key]) ? '1' : '';
            } elseif (in_array($key, array('max_submissions_per_hour', 'max_submissions_per_day', 'min_seconds_before_submit', 'duplicate_window_hours', 'max_links', 'min_problem_length', 'min_suggestion_length', 'webhook_timeout', 'webhook_max_attempts', 'ai_timeout'), true)) {
                $settings[$key] = (string) max(0, absint(isset($incoming[$key]) ? $incoming[$key] : $default));
            } elseif (in_array($key, array('webhook_url', 'ai_backend_url'), true)) {
                $settings[$key] = esc_url_raw(isset($incoming[$key]) ? $incoming[$key] : $default);
            } elseif (in_array($key, array('rest_api_key', 'webhook_secret', 'ai_backend_api_key'), true)) {
                $settings[$key] = sanitize_text_field(isset($incoming[$key]) ? $incoming[$key] : $default);
            } elseif ($key === 'notification_email') {
                $email = sanitize_email(isset($incoming[$key]) ? $incoming[$key] : $default);
                $settings[$key] = is_email($email) ? $email : get_option('admin_email');
            } elseif ($key === 'submission_status') {
                $settings[$key] = $this->allowed_post_status(isset($incoming[$key]) ? sanitize_text_field($incoming[$key]) : $default);
            } elseif (in_array($key, array('form_intro', 'success_message', 'error_message', 'consent_text', 'categories', 'priorities', 'blocked_terms'), true)) {
                $settings[$key] = sanitize_textarea_field(isset($incoming[$key]) ? $incoming[$key] : $default);
            } else {
                $settings[$key] = sanitize_text_field(isset($incoming[$key]) ? $incoming[$key] : $default);
            }
        }

        update_option(self::OPTION_KEY, $settings, false);
        wp_safe_redirect(add_query_arg(array('page' => 'scfs-settings-standalone', 'settings-updated' => '1'), admin_url('admin.php')));
        exit;
    }

    private function allowed_post_status($status) {
        $allowed = array('pending', 'private', 'draft', 'publish');
        return in_array($status, $allowed, true) ? $status : 'pending';
    }

    private function int_setting($settings, $key, $default, $min = 0, $max = PHP_INT_MAX) {
        $value = isset($settings[$key]) ? absint($settings[$key]) : absint($default);
        return min($max, max($min, $value));
    }

    public function cron_schedules($schedules) {
        if (!isset($schedules['scfs_five_minutes'])) {
            $schedules['scfs_five_minutes'] = array(
                'interval' => 300,
                'display' => __('Every five minutes', 'sustainable-catalyst-feature-suggestions'),
            );
        }
        return $schedules;
    }

    public function register_rest_routes() {
        register_rest_route(self::REST_NAMESPACE, '/health', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_health'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/schema', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_schema'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/suggestions', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'rest_create_suggestion'),
                'permission_callback' => array($this, 'rest_submission_permission'),
            ),
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'rest_list_suggestions'),
                'permission_callback' => array($this, 'rest_admin_permission'),
            ),
        ));
        register_rest_route(self::REST_NAMESPACE, '/suggestions/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'rest_get_suggestion'),
                'permission_callback' => array($this, 'rest_admin_permission'),
            ),
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'rest_update_suggestion'),
                'permission_callback' => array($this, 'rest_admin_permission'),
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/suggestions/(?P<id>\d+)/analysis', array(
            array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'rest_analyze_suggestion'), 'permission_callback' => array($this, 'rest_admin_permission')),
            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'rest_get_analysis'), 'permission_callback' => array($this, 'rest_admin_permission')),
        ));
        register_rest_route(self::REST_NAMESPACE, '/ai/status', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'rest_ai_status'), 'permission_callback' => array($this, 'rest_admin_permission'),
        ));

    }

    public function rest_submission_permission($request) {
        $settings = $this->settings();
        if ($settings['enable_rest_submissions'] !== '1') {
            return new WP_Error('scfs_rest_disabled', __('REST submissions are disabled.', 'sustainable-catalyst-feature-suggestions'), array('status' => 403));
        }
        if ($settings['rest_require_api_key'] !== '1') {
            return true;
        }
        $provided = (string) $request->get_header('x-scfs-api-key');
        $expected = (string) $settings['rest_api_key'];
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return new WP_Error('scfs_invalid_api_key', __('A valid feature suggestion API key is required.', 'sustainable-catalyst-feature-suggestions'), array('status' => 401));
        }
        return true;
    }

    public function rest_admin_permission() {
        return current_user_can('edit_posts');
    }

    public function rest_health() {
        $settings = $this->settings();
        $queue = get_option(self::WEBHOOK_QUEUE_KEY, array());
        return rest_ensure_response(array(
            'ok' => true,
            'plugin' => 'sustainable-catalyst-feature-suggestions',
            'version' => self::VERSION,
            'schema_version' => self::EVENT_SCHEMA_VERSION,
            'rest_submissions_enabled' => $settings['enable_rest_submissions'] === '1',
            'shared_events_enabled' => $settings['enable_shared_events'] === '1',
            'webhooks_enabled' => $settings['enable_webhooks'] === '1',
            'queued_webhooks' => is_array($queue) ? count($queue) : 0,
            'timestamp' => gmdate('c'),
        ));
    }

    public function rest_schema() {
        return rest_ensure_response(array(
            'schema_version' => self::EVENT_SCHEMA_VERSION,
            'namespace' => self::REST_NAMESPACE,
            'categories' => $this->categories(),
            'priorities' => $this->priorities(),
            'review_statuses' => array_keys($this->review_statuses()),
            'required_submission_fields' => array('title', 'category', 'problem', 'suggestion', 'consent'),
            'event_types' => array('feedback.submitted', 'feedback.reviewed', 'feedback.status_changed', 'feedback.classified', 'librarian.feedback_submitted'),
            'canonical_product_registry' => class_exists('SCFS_Canonical_Product_Registry') ? SCFS_Canonical_Product_Registry::instance()->schema_record() : array(),
            'canonical_product_registry_summary' => class_exists('SCFS_Canonical_Product_Registry') ? SCFS_Canonical_Product_Registry::instance()->summary_record() : array(),
            'installed_plugin_discovery' => class_exists('SCFS_Installed_Plugin_Discovery') ? SCFS_Installed_Plugin_Discovery::instance()->schema_record() : array(),
            'installed_plugin_discovery_summary' => class_exists('SCFS_Installed_Plugin_Discovery') ? SCFS_Installed_Plugin_Discovery::instance()->summary_record() : array(),
            'product_connection_editor' => class_exists('SCFS_Product_Connection_Editor') ? SCFS_Product_Connection_Editor::instance()->schema_record() : array(),
            'release_board' => class_exists('SCFS_Release_Board') ? SCFS_Release_Board::instance()->schema_record() : array(),
            'product_taxonomy' => class_exists('SCFS_Product_Integration') ? SCFS_Product_Integration::instance()->taxonomy_schema() : array(),
            'contact_engagement_handoff' => class_exists('SCFS_Product_Integration') ? SCFS_Product_Integration::instance()->handoff_schema() : array(),
        ));
    }

    private function rest_values($request) {
        $values = $this->empty_values();
        $text_fields = array('title', 'category', 'relevant_url', 'priority', 'name');
        $taxonomy_fields = array('product', 'product_version', 'component', 'issue_type', 'release');
        $textarea_fields = array('problem', 'beneficiaries', 'suggestion', 'success_criteria', 'implementation_notes');
        foreach ($text_fields as $field) {
            if ($request->has_param($field)) {
                $values[$field] = sanitize_text_field($request->get_param($field));
            }
        }
        foreach ($taxonomy_fields as $field) {
            if ($request->has_param($field)) {
                $raw = $request->get_param($field);
                $values[$field] = is_array($raw) ? array_values(array_filter(array_map('sanitize_title', $raw))) : sanitize_title($raw);
            }
        }
        foreach ($textarea_fields as $field) {
            if ($request->has_param($field)) {
                $values[$field] = sanitize_textarea_field($request->get_param($field));
            }
        }
        $values['email'] = sanitize_email($request->get_param('email'));
        $values['follow_up'] = rest_sanitize_boolean($request->get_param('follow_up')) ? '1' : '';
        $values['consent'] = rest_sanitize_boolean($request->get_param('consent')) ? '1' : '';
        return $values;
    }

    public function rest_create_suggestion($request) {
        $settings = $this->settings();
        $values = $this->rest_values($request);
        $errors = $this->validate_values($values, $settings);
        if (!empty($errors)) {
            return new WP_Error('scfs_validation_failed', implode(' ', $errors), array('status' => 400, 'errors' => $errors));
        }
        $spam = $this->passes_spam_controls($settings, $values);
        if (is_wp_error($spam)) {
            return $spam;
        }
        if (!in_array($values['priority'], $this->priorities(), true)) {
            $priorities = $this->priorities();
            $values['priority'] = isset($priorities[0]) ? $priorities[0] : 'Medium';
        }
        $fingerprint = $this->submission_fingerprint($values);
        $duplicate_key = 'scfs_dup_' . substr($fingerprint, 0, 32);
        if ($this->int_setting($settings, 'duplicate_window_hours', 24, 0, 168) > 0 && get_transient($duplicate_key)) {
            return new WP_Error('scfs_duplicate', __('This looks like a duplicate submission.', 'sustainable-catalyst-feature-suggestions'), array('status' => 409));
        }
        $post_id = wp_insert_post(wp_slash(array(
            'post_type' => self::POST_TYPE,
            'post_status' => $this->allowed_post_status($settings['submission_status']),
            'post_title' => $values['title'],
            'post_content' => $this->build_post_content($values),
            'post_author' => $this->default_author_id(),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        )), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        foreach ($values as $key => $value) {
            update_post_meta($post_id, '_scfs_' . $key, $value);
        }
        $source = sanitize_key($request->get_param('source'));
        if ($source === '') {
            $source = 'rest_api';
        }
        $correlation_id = sanitize_text_field($request->get_param('correlation_id'));
        update_post_meta($post_id, '_scfs_fingerprint', $fingerprint);
        update_post_meta($post_id, '_scfs_review_status', 'new');
        update_post_meta($post_id, '_scfs_impact_score', '0');
        update_post_meta($post_id, '_scfs_effort_score', '0');
        update_post_meta($post_id, '_scfs_ip_hash', $this->ip_hash());
        update_post_meta($post_id, '_scfs_source', $source);
        update_post_meta($post_id, '_scfs_schema_version', self::EVENT_SCHEMA_VERSION);
        update_post_meta($post_id, '_scfs_correlation_id', $correlation_id);
        if (class_exists('SCFS_Product_Integration')) {
            SCFS_Product_Integration::instance()->assign_context($post_id, $values, current_user_can('edit_posts'));
        }
        $uuid = $this->ensure_submission_uuid($post_id);
        if ($this->int_setting($settings, 'duplicate_window_hours', 24, 0, 168) > 0) {
            set_transient($duplicate_key, '1', HOUR_IN_SECONDS * $this->int_setting($settings, 'duplicate_window_hours', 24, 0, 168));
        }
        $this->increment_rate_limits($settings);
        $this->publish_event('feedback.submitted', $post_id, array('source' => $source, 'correlation_id' => $correlation_id));
        if ($settings['ai_auto_analyze'] === '1') {
            $this->request_ai_analysis($post_id);
        }
        return new WP_REST_Response(array(
            'ok' => true,
            'id' => $post_id,
            'submission_uuid' => $uuid,
            'review_status' => 'new',
            'message' => $settings['success_message'],
        ), 201);
    }

    public function rest_list_suggestions($request) {
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 20)));
        $page = max(1, absint($request->get_param('page') ?: 1));
        $args = array(
            'post_type' => self::POST_TYPE,
            'post_status' => array('private', 'publish', 'draft', 'pending'),
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        );
        $review_status = sanitize_key($request->get_param('review_status'));
        if ($review_status !== '' && isset($this->review_statuses()[$review_status])) {
            $args['meta_query'] = array(array('key' => '_scfs_review_status', 'value' => $review_status));
        }
        foreach (array('product' => 'scfs_product', 'product_version' => 'scfs_product_version', 'component' => 'scfs_component', 'issue_type' => 'scfs_issue_type', 'release' => 'scfs_release') as $parameter => $taxonomy) {
            $value = sanitize_title($request->get_param($parameter));
            if ($value !== '') {
                $args['tax_query'][] = array('taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $value);
            }
        }
        $query = new WP_Query($args);
        $items = array_map(array($this, 'suggestion_record'), $query->posts);
        $response = rest_ensure_response(array('items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => (int) $query->found_posts));
        return $response;
    }

    public function rest_get_suggestion($request) {
        $post = get_post(absint($request['id']));
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return new WP_Error('scfs_not_found', __('Feature suggestion not found.', 'sustainable-catalyst-feature-suggestions'), array('status' => 404));
        }
        return rest_ensure_response($this->suggestion_record($post, true));
    }

    public function rest_update_suggestion($request) {
        $post_id = absint($request['id']);
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return new WP_Error('scfs_not_found', __('Feature suggestion not found.', 'sustainable-catalyst-feature-suggestions'), array('status' => 404));
        }
        $old = get_post_meta($post_id, '_scfs_review_status', true);
        $status = sanitize_key($request->get_param('review_status'));
        if ($status !== '') {
            if (!isset($this->review_statuses()[$status])) {
                return new WP_Error('scfs_invalid_status', __('Invalid review status.', 'sustainable-catalyst-feature-suggestions'), array('status' => 400));
            }
            update_post_meta($post_id, '_scfs_review_status', $status);
        } else {
            $status = $old;
        }
        foreach (array('roadmap_area', 'admin_notes') as $field) {
            if ($request->has_param($field)) {
                $value = $field === 'admin_notes' ? sanitize_textarea_field($request->get_param($field)) : sanitize_text_field($request->get_param($field));
                update_post_meta($post_id, '_scfs_' . $field, $value);
            }
        }
        if ($request->has_param('github_issue_url')) {
            update_post_meta($post_id, '_scfs_github_issue_url', esc_url_raw($request->get_param('github_issue_url')));
        }
        foreach (array('impact_score', 'effort_score') as $field) {
            if ($request->has_param($field)) {
                update_post_meta($post_id, '_scfs_' . $field, min(5, max(0, absint($request->get_param($field)))));
            }
        }
        if (class_exists('SCFS_Product_Integration')) {
            $context = $request->get_param('product_context');
            if (is_array($context)) {
                SCFS_Product_Integration::instance()->assign_context($post_id, $context, true);
            }
        }
        $this->publish_event('feedback.reviewed', $post_id, array('previous_review_status' => $old));
        if ($old !== $status) {
            $this->publish_event('feedback.status_changed', $post_id, array('previous_review_status' => $old, 'review_status' => $status));
        }
        return rest_ensure_response($this->suggestion_record(get_post($post_id), true));
    }

    private function suggestion_record($post, $include_private = false) {
        $record = array(
            'id' => (int) $post->ID,
            'submission_uuid' => $this->ensure_submission_uuid($post->ID),
            'created_at' => get_post_time('c', true, $post),
            'title' => $post->post_title,
            'category' => get_post_meta($post->ID, '_scfs_category', true),
            'priority' => get_post_meta($post->ID, '_scfs_priority', true),
            'review_status' => get_post_meta($post->ID, '_scfs_review_status', true),
            'source' => get_post_meta($post->ID, '_scfs_source', true),
            'correlation_id' => get_post_meta($post->ID, '_scfs_correlation_id', true),
            'impact_score' => (int) get_post_meta($post->ID, '_scfs_impact_score', true),
            'effort_score' => (int) get_post_meta($post->ID, '_scfs_effort_score', true),
            'roadmap_area' => get_post_meta($post->ID, '_scfs_roadmap_area', true),
            'github_issue_url' => get_post_meta($post->ID, '_scfs_github_issue_url', true),
            'ai_analysis_status' => get_post_meta($post->ID, '_scfs_ai_analysis_status', true),
            'ai_analysis' => get_post_meta($post->ID, '_scfs_ai_analysis', true),
            'product_context' => class_exists('SCFS_Product_Integration') ? SCFS_Product_Integration::instance()->product_context($post->ID) : array(),
        );
        if ($include_private) {
            foreach (array('problem', 'suggestion', 'success_criteria', 'beneficiaries', 'implementation_notes', 'relevant_url', 'name', 'email', 'follow_up', 'admin_notes') as $field) {
                $record[$field] = get_post_meta($post->ID, '_scfs_' . $field, true);
            }
        }
        return $record;
    }

    private function ensure_submission_uuid($post_id) {
        $uuid = get_post_meta($post_id, '_scfs_submission_uuid', true);
        if ($uuid === '') {
            $uuid = wp_generate_uuid4();
            update_post_meta($post_id, '_scfs_submission_uuid', $uuid);
        }
        return $uuid;
    }

    private function event_payload($event_type, $post_id, $context = array()) {
        $post = get_post($post_id);
        return array(
            'event_id' => wp_generate_uuid4(),
            'event_type' => sanitize_key(str_replace('.', '_', $event_type)) === '' ? $event_type : $event_type,
            'schema_version' => self::EVENT_SCHEMA_VERSION,
            'source_plugin' => 'feature_suggestions',
            'source_version' => self::VERSION,
            'occurred_at' => gmdate('c'),
            'site_url' => home_url('/'),
            'submission' => array(
                'id' => (int) $post_id,
                'uuid' => $this->ensure_submission_uuid($post_id),
                'title' => $post ? $post->post_title : '',
                'category' => get_post_meta($post_id, '_scfs_category', true),
                'priority' => get_post_meta($post_id, '_scfs_priority', true),
                'review_status' => get_post_meta($post_id, '_scfs_review_status', true),
                'source' => get_post_meta($post_id, '_scfs_source', true),
                'correlation_id' => get_post_meta($post_id, '_scfs_correlation_id', true),
                'roadmap_area' => get_post_meta($post_id, '_scfs_roadmap_area', true),
                'impact_score' => (int) get_post_meta($post_id, '_scfs_impact_score', true),
                'effort_score' => (int) get_post_meta($post_id, '_scfs_effort_score', true),
                'product_context' => class_exists('SCFS_Product_Integration') ? SCFS_Product_Integration::instance()->product_context($post_id) : array(),
            ),
            'context' => is_array($context) ? $context : array(),
        );
    }

    private function publish_event($event_type, $post_id, $context = array()) {
        $settings = $this->settings();
        if ($settings['enable_shared_events'] !== '1' && $settings['enable_webhooks'] !== '1') {
            return;
        }
        $event = $this->event_payload($event_type, $post_id, $context);
        if ($settings['enable_shared_events'] === '1') {
            do_action('scfs_event', $event_type, $event);
            do_action('sc_platform_event', $event);
        }
        if ($settings['enable_webhooks'] === '1') {
            do_action('scfs_dispatch_event', $event);
        }
    }

    public function dispatch_event_webhook($event) {
        $settings = $this->settings();
        if ($settings['enable_webhooks'] !== '1' || empty($settings['webhook_url'])) {
            return false;
        }
        $result = $this->send_webhook($event, $settings);
        if (is_wp_error($result)) {
            $this->queue_webhook($event, 1, $result->get_error_message());
            return false;
        }
        return true;
    }

    private function send_webhook($event, $settings) {
        $body = wp_json_encode($event);
        $headers = array(
            'Content-Type' => 'application/json',
            'X-SCFS-Event' => isset($event['event_type']) ? $event['event_type'] : '',
            'X-SCFS-Delivery' => isset($event['event_id']) ? $event['event_id'] : wp_generate_uuid4(),
            'X-SCFS-Schema' => self::EVENT_SCHEMA_VERSION,
        );
        if (!empty($settings['webhook_secret'])) {
            $headers['X-SCFS-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $settings['webhook_secret']);
        }
        $response = wp_remote_post($settings['webhook_url'], array(
            'timeout' => $this->int_setting($settings, 'webhook_timeout', 8, 2, 30),
            'headers' => $headers,
            'body' => $body,
            'data_format' => 'body',
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('scfs_webhook_http_error', sprintf(__('Webhook returned HTTP %d.', 'sustainable-catalyst-feature-suggestions'), $code));
        }
        return true;
    }

    private function queue_webhook($event, $attempts = 1, $last_error = '') {
        $queue = get_option(self::WEBHOOK_QUEUE_KEY, array());
        if (!is_array($queue)) {
            $queue = array();
        }
        $queue[] = array(
            'event' => $event,
            'attempts' => absint($attempts),
            'last_error' => sanitize_text_field($last_error),
            'next_attempt_at' => time() + min(HOUR_IN_SECONDS, 60 * max(1, absint($attempts))),
        );
        if (count($queue) > 500) {
            $queue = array_slice($queue, -500);
        }
        update_option(self::WEBHOOK_QUEUE_KEY, $queue, false);
    }

    public function process_webhook_queue() {
        $queue = get_option(self::WEBHOOK_QUEUE_KEY, array());
        if (!is_array($queue) || empty($queue)) {
            return;
        }
        $settings = $this->settings();
        $remaining = array();
        $max_attempts = $this->int_setting($settings, 'webhook_max_attempts', 5, 1, 10);
        foreach ($queue as $item) {
            if (empty($item['event']) || !is_array($item['event'])) {
                continue;
            }
            if (!empty($item['next_attempt_at']) && absint($item['next_attempt_at']) > time()) {
                $remaining[] = $item;
                continue;
            }
            $result = $this->send_webhook($item['event'], $settings);
            if (is_wp_error($result)) {
                $attempts = absint(isset($item['attempts']) ? $item['attempts'] : 0) + 1;
                if ($attempts < $max_attempts) {
                    $item['attempts'] = $attempts;
                    $item['last_error'] = $result->get_error_message();
                    $item['next_attempt_at'] = time() + min(DAY_IN_SECONDS, 300 * (2 ** min(6, $attempts - 1)));
                    $remaining[] = $item;
                }
            }
        }
        update_option(self::WEBHOOK_QUEUE_KEY, $remaining, false);
    }

    public function render_integration_page() {
        if (!$this->can_manage_settings()) {
            wp_die(__('You do not have permission to view integration status.', 'sustainable-catalyst-feature-suggestions'));
        }
        $settings = $this->settings();
        $queue = get_option(self::WEBHOOK_QUEUE_KEY, array());
        ?>
        <div class="wrap scfs-settings-page">
            <h1><?php esc_html_e('Product Support and Feedback Integration', 'sustainable-catalyst-feature-suggestions'); ?></h1>
            <div class="scfs-settings-grid">
                <section class="scfs-settings-card">
                    <h2><?php esc_html_e('REST API', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                    <p><strong><?php esc_html_e('Health:', 'sustainable-catalyst-feature-suggestions'); ?></strong> <code><?php echo esc_html(rest_url(self::REST_NAMESPACE . '/health')); ?></code></p>
                    <p><strong><?php esc_html_e('Schema:', 'sustainable-catalyst-feature-suggestions'); ?></strong> <code><?php echo esc_html(rest_url(self::REST_NAMESPACE . '/schema')); ?></code></p>
                    <p><strong><?php esc_html_e('Suggestions:', 'sustainable-catalyst-feature-suggestions'); ?></strong> <code><?php echo esc_html(rest_url(self::REST_NAMESPACE . '/suggestions')); ?></code></p>
                    <p><strong><?php esc_html_e('Product taxonomy:', 'sustainable-catalyst-feature-suggestions'); ?></strong> <code><?php echo esc_html(rest_url(self::REST_NAMESPACE . '/taxonomy/schema')); ?></code></p>
                    <p><strong><?php esc_html_e('Handoff contract:', 'sustainable-catalyst-feature-suggestions'); ?></strong> <code><?php echo esc_html(rest_url(self::REST_NAMESPACE . '/contact-engagement/handoff-schema')); ?></code></p>
                    <p><?php echo $settings['enable_rest_submissions'] === '1' ? esc_html__('Public submission endpoint enabled.', 'sustainable-catalyst-feature-suggestions') : esc_html__('Public submission endpoint disabled.', 'sustainable-catalyst-feature-suggestions'); ?></p>
                </section>
                <section class="scfs-settings-card">
                    <h2><?php esc_html_e('Shared Events', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                    <p><code>scfs_event</code></p>
                    <p><code>sc_platform_event</code></p>
                    <p><?php echo $settings['enable_shared_events'] === '1' ? esc_html__('WordPress shared events enabled.', 'sustainable-catalyst-feature-suggestions') : esc_html__('WordPress shared events disabled.', 'sustainable-catalyst-feature-suggestions'); ?></p>
                </section>
                <section class="scfs-settings-card">
                    <h2><?php esc_html_e('Webhook Delivery', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                    <p><strong><?php esc_html_e('Destination:', 'sustainable-catalyst-feature-suggestions'); ?></strong> <?php echo $settings['webhook_url'] ? '<code>' . esc_html($settings['webhook_url']) . '</code>' : esc_html__('Not configured', 'sustainable-catalyst-feature-suggestions'); ?></p>
                    <p><strong><?php esc_html_e('Queued deliveries:', 'sustainable-catalyst-feature-suggestions'); ?></strong> <?php echo esc_html((string) (is_array($queue) ? count($queue) : 0)); ?></p>
                    <p><?php echo $settings['enable_webhooks'] === '1' ? esc_html__('Signed webhook delivery enabled.', 'sustainable-catalyst-feature-suggestions') : esc_html__('Webhook delivery disabled.', 'sustainable-catalyst-feature-suggestions'); ?></p>
                </section>
            </div>
        </div>
        <?php
    }


    private function ai_payload($post_id) {
        $post = get_post($post_id);
        return array(
            'submission_id' => $this->ensure_submission_uuid($post_id),
            'wordpress_id' => (int) $post_id,
            'title' => $post ? $post->post_title : '',
            'category' => get_post_meta($post_id, '_scfs_category', true),
            'priority' => get_post_meta($post_id, '_scfs_priority', true),
            'problem' => get_post_meta($post_id, '_scfs_problem', true),
            'suggestion' => get_post_meta($post_id, '_scfs_suggestion', true),
            'success_criteria' => get_post_meta($post_id, '_scfs_success_criteria', true),
            'beneficiaries' => get_post_meta($post_id, '_scfs_beneficiaries', true),
            'implementation_notes' => get_post_meta($post_id, '_scfs_implementation_notes', true),
            'product' => class_exists('SCFS_Product_Integration') ? SCFS_Product_Integration::instance()->flat_term_names($post_id, 'scfs_product') : array(),
            'product_version' => class_exists('SCFS_Product_Integration') ? SCFS_Product_Integration::instance()->flat_term_names($post_id, 'scfs_product_version') : array(),
            'component' => class_exists('SCFS_Product_Integration') ? SCFS_Product_Integration::instance()->flat_term_names($post_id, 'scfs_component') : array(),
            'issue_type' => class_exists('SCFS_Product_Integration') ? SCFS_Product_Integration::instance()->flat_term_names($post_id, 'scfs_issue_type') : array(),
            'release' => class_exists('SCFS_Product_Integration') ? SCFS_Product_Integration::instance()->flat_term_names($post_id, 'scfs_release') : array(),
            'source' => get_post_meta($post_id, '_scfs_source', true),
        );
    }

    private function request_ai_analysis($post_id) {
        $settings = $this->settings();
        $base = untrailingslashit((string) $settings['ai_backend_url']);
        if ($base === '') return new WP_Error('scfs_ai_not_configured', __('AI backend URL is not configured.', 'sustainable-catalyst-feature-suggestions'));
        update_post_meta($post_id, '_scfs_ai_analysis_status', 'processing');
        $headers = array('Content-Type' => 'application/json');
        if (!empty($settings['ai_backend_api_key'])) $headers['X-SCFS-AI-Key'] = $settings['ai_backend_api_key'];
        $response = wp_remote_post($base . '/v1/analyze', array(
            'timeout' => max(5, min(60, absint($settings['ai_timeout']))),
            'headers' => $headers,
            'body' => wp_json_encode($this->ai_payload($post_id)),
            'data_format' => 'body',
        ));
        if (is_wp_error($response)) { update_post_meta($post_id, '_scfs_ai_analysis_status', 'failed'); update_post_meta($post_id, '_scfs_ai_analysis_error', $response->get_error_message()); return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) { $err = new WP_Error('scfs_ai_http_error', sprintf(__('AI backend returned HTTP %d.', 'sustainable-catalyst-feature-suggestions'), $code)); update_post_meta($post_id, '_scfs_ai_analysis_status', 'failed'); update_post_meta($post_id, '_scfs_ai_analysis_error', $err->get_error_message()); return $err; }
        update_post_meta($post_id, '_scfs_ai_analysis', $data);
        update_post_meta($post_id, '_scfs_ai_analysis_status', 'complete');
        update_post_meta($post_id, '_scfs_ai_analysis_version', isset($data['analysis_version']) ? sanitize_text_field($data['analysis_version']) : '');
        update_post_meta($post_id, '_scfs_ai_analyzed_at', gmdate('c'));
        delete_post_meta($post_id, '_scfs_ai_analysis_error');
        $this->publish_event('feedback.classified', $post_id, array('analysis_version' => isset($data['analysis_version']) ? $data['analysis_version'] : '', 'confidence' => isset($data['confidence']) ? $data['confidence'] : null));
        return $data;
    }

    public function rest_analyze_suggestion($request) {
        $id = absint($request['id']); $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE) return new WP_Error('scfs_not_found', __('Feature suggestion not found.', 'sustainable-catalyst-feature-suggestions'), array('status'=>404));
        $result = $this->request_ai_analysis($id); if (is_wp_error($result)) return $result; return rest_ensure_response($result);
    }
    public function rest_get_analysis($request) {
        $id=absint($request['id']); return rest_ensure_response(array('status'=>get_post_meta($id,'_scfs_ai_analysis_status',true),'analysis'=>get_post_meta($id,'_scfs_ai_analysis',true),'error'=>get_post_meta($id,'_scfs_ai_analysis_error',true),'analyzed_at'=>get_post_meta($id,'_scfs_ai_analyzed_at',true)));
    }
    public function rest_ai_status() {
        $settings=$this->settings(); $base=untrailingslashit((string)$settings['ai_backend_url']);
        if ($base==='') return rest_ensure_response(array('ok'=>false,'configured'=>false));
        $headers=array(); if(!empty($settings['ai_backend_api_key'])) $headers['X-SCFS-AI-Key']=$settings['ai_backend_api_key'];
        $r=wp_remote_get($base.'/health',array('timeout'=>8,'headers'=>$headers));
        if(is_wp_error($r)) return rest_ensure_response(array('ok'=>false,'configured'=>true,'error'=>$r->get_error_message()));
        return rest_ensure_response(array('ok'=>wp_remote_retrieve_response_code($r)===200,'configured'=>true,'backend'=>json_decode(wp_remote_retrieve_body($r),true)));
    }
    public function analyze_suggestion_action() {
        if(!current_user_can('edit_posts')) wp_die(__('Permission denied.','sustainable-catalyst-feature-suggestions'));
        $id=isset($_GET['post_id'])?absint($_GET['post_id']):0; check_admin_referer('scfs_analyze_'.$id); $this->request_ai_analysis($id); wp_safe_redirect(get_edit_post_link($id,'url')); exit;
    }
    public function analyze_batch_action() {
        if(!current_user_can('edit_posts')) wp_die(__('Permission denied.','sustainable-catalyst-feature-suggestions'));
        check_admin_referer('scfs_analyze_batch'); $q=new WP_Query(array('post_type'=>self::POST_TYPE,'post_status'=>array('pending','private','publish','draft'),'posts_per_page'=>25,'meta_query'=>array(array('key'=>'_scfs_ai_analysis_status','compare'=>'NOT EXISTS'))));
        foreach($q->posts as $p){$this->request_ai_analysis($p->ID);} wp_safe_redirect(admin_url('edit.php?post_type='.self::POST_TYPE)); exit;
    }

    private function intelligence_filters() {
        $allowed_statuses = array_keys($this->review_statuses());
        $status = isset($_GET['review_status']) ? sanitize_key(wp_unslash($_GET['review_status'])) : '';
        if ($status && !in_array($status, $allowed_statuses, true)) $status = '';
        return array(
            'days' => isset($_GET['days']) ? min(3650, max(0, absint($_GET['days']))) : 90,
            'review_status' => $status,
            'category' => isset($_GET['category']) ? sanitize_text_field(wp_unslash($_GET['category'])) : '',
            'platform_area' => isset($_GET['platform_area']) ? sanitize_key(wp_unslash($_GET['platform_area'])) : '',
            'feature_type' => isset($_GET['feature_type']) ? sanitize_key(wp_unslash($_GET['feature_type'])) : '',
            'product' => isset($_GET['product']) ? sanitize_title(wp_unslash($_GET['product'])) : '',
            'component' => isset($_GET['component']) ? sanitize_title(wp_unslash($_GET['component'])) : '',
            'issue_type' => isset($_GET['issue_type']) ? sanitize_title(wp_unslash($_GET['issue_type'])) : '',
            'release' => isset($_GET['release']) ? sanitize_title(wp_unslash($_GET['release'])) : '',
        );
    }

    private function intelligence_data($filters = array()) {
        $filters = wp_parse_args($filters, array('days'=>90,'review_status'=>'','category'=>'','platform_area'=>'','feature_type'=>'','product'=>'','component'=>'','issue_type'=>'','release'=>''));
        $args = array('post_type'=>self::POST_TYPE,'post_status'=>array('publish','pending','draft','private'),'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'date','order'=>'DESC');
        if (!empty($filters['days'])) $args['date_query'] = array(array('after'=>gmdate('Y-m-d', time() - DAY_IN_SECONDS * absint($filters['days'])),'inclusive'=>true));
        if (!empty($filters['review_status'])) $args['meta_query'][] = array('key'=>'_scfs_review_status','value'=>$filters['review_status']);
        if (!empty($filters['category'])) $args['meta_query'][] = array('key'=>'_scfs_category','value'=>$filters['category']);
        foreach (array('product'=>'scfs_product','component'=>'scfs_component','issue_type'=>'scfs_issue_type','release'=>'scfs_release') as $filter_key=>$taxonomy) {
            if (!empty($filters[$filter_key])) $args['tax_query'][] = array('taxonomy'=>$taxonomy,'field'=>'slug','terms'=>$filters[$filter_key]);
        }
        $ids = get_posts($args);
        $data = array('total'=>0,'analyzed'=>0,'unanalyzed'=>0,'average_confidence'=>0,'average_impact'=>0,'average_effort'=>0,'roadmap_ready'=>0,'safety_flagged'=>0,'statuses'=>array(),'categories'=>array(),'products'=>array(),'versions'=>array(),'components'=>array(),'issue_types'=>array(),'releases'=>array(),'platform_areas'=>array(),'feature_types'=>array(),'topics'=>array(),'sentiment'=>array(),'actions'=>array(),'monthly'=>array(),'opportunities'=>array());
        $confidence_total=0; $impact_total=0; $effort_total=0; $score_count=0;
        foreach ($ids as $id) {
            $analysis = get_post_meta($id, '_scfs_ai_analysis', true);
            $area = is_array($analysis) ? sanitize_key($analysis['platform_area'] ?? '') : '';
            $type = is_array($analysis) ? sanitize_key($analysis['feature_type'] ?? '') : '';
            if ($filters['platform_area'] && $area !== $filters['platform_area']) continue;
            if ($filters['feature_type'] && $type !== $filters['feature_type']) continue;
            $data['total']++;
            $status=get_post_meta($id,'_scfs_review_status',true) ?: 'new';
            $category=get_post_meta($id,'_scfs_category',true) ?: 'Uncategorized';
            $this->intelligence_increment($data['statuses'],$status);
            $this->intelligence_increment($data['categories'],$category);
            if (class_exists('SCFS_Product_Integration')) {
                $integration = SCFS_Product_Integration::instance();
                foreach (array('products'=>'scfs_product','versions'=>'scfs_product_version','components'=>'scfs_component','issue_types'=>'scfs_issue_type','releases'=>'scfs_release') as $bucket=>$taxonomy) {
                    foreach ($integration->flat_term_names($id, $taxonomy) as $term_name) $this->intelligence_increment($data[$bucket], $term_name);
                }
            }
            $month=get_the_date('Y-m',$id); $this->intelligence_increment($data['monthly'],$month);
            $impact=(int)get_post_meta($id,'_scfs_impact_score',true); $effort=(int)get_post_meta($id,'_scfs_effort_score',true);
            if ($impact || $effort) { $impact_total += $impact; $effort_total += $effort; $score_count++; }
            if (is_array($analysis)) {
                $data['analyzed']++; $confidence=(float)($analysis['confidence'] ?? 0); $confidence_total += $confidence;
                $this->intelligence_increment($data['platform_areas'],$area ?: 'unclassified');
                $this->intelligence_increment($data['feature_types'],$type ?: 'unclassified');
                $this->intelligence_increment($data['sentiment'],sanitize_key($analysis['sentiment'] ?? 'unknown'));
                $this->intelligence_increment($data['actions'],sanitize_key($analysis['suggested_action'] ?? 'review'));
                foreach ((array)($analysis['topics'] ?? array()) as $topic) $this->intelligence_increment($data['topics'],sanitize_text_field($topic));
                if (!empty($analysis['safety_flags'])) $data['safety_flagged']++;
                if (($analysis['suggested_action'] ?? '') === 'evaluate_for_roadmap') $data['roadmap_ready']++;
                $scores=(array)($analysis['scores'] ?? array());
                $priority=((float)($scores['impact'] ?? 0) * 2 + (float)($scores['urgency'] ?? 0) + (float)($scores['strategic_alignment'] ?? 0) * 5 - (float)($scores['effort'] ?? 0)) * max(.25,$confidence);
                $data['opportunities'][]=array('id'=>$id,'title'=>get_the_title($id),'summary'=>sanitize_text_field($analysis['summary'] ?? ''),'platform_area'=>$area,'feature_type'=>$type,'confidence'=>$confidence,'priority_score'=>round($priority,2),'edit_url'=>get_edit_post_link($id,'raw'));
            } else $data['unanalyzed']++;
        }
        $data['average_confidence']=$data['analyzed'] ? round($confidence_total/$data['analyzed'],2) : 0;
        $data['average_impact']=$score_count ? round($impact_total/$score_count,1) : 0;
        $data['average_effort']=$score_count ? round($effort_total/$score_count,1) : 0;
        foreach (array('statuses','categories','products','versions','components','issue_types','releases','platform_areas','feature_types','topics','sentiment','actions') as $key) arsort($data[$key]);
        ksort($data['monthly']); usort($data['opportunities'],fn($a,$b)=>$b['priority_score']<=>$a['priority_score']); $data['opportunities']=array_slice($data['opportunities'],0,12);
        return $data;
    }

    private function intelligence_increment(&$bucket,$key) { $key=$key ?: 'unknown'; $bucket[$key]=isset($bucket[$key]) ? $bucket[$key]+1 : 1; }
    private function render_intelligence_bars($items,$total,$limit=8) {
        if (!$items) { echo '<p class="description">No data in this view.</p>'; return; }
        $i=0; echo '<div class="scfs-intel-bars">'; foreach ($items as $label=>$count) { if ($i++ >= $limit) break; $pct=$total ? min(100,round($count/$total*100)) : 0; echo '<div class="scfs-intel-bar"><div><span>'.esc_html(ucwords(str_replace('_',' ',$label))).'</span><strong>'.esc_html((string)$count).'</strong></div><span class="scfs-intel-track"><span style="width:'.esc_attr((string)$pct).'%"></span></span></div>'; } echo '</div>';
    }

    public function render_intelligence_page() {
        if (!current_user_can('edit_posts')) wp_die(__('You do not have permission to view intelligence.','sustainable-catalyst-feature-suggestions'));
        $f=$this->intelligence_filters(); $d=$this->intelligence_data($f); $export=wp_nonce_url(admin_url('admin-post.php?action=scfs_export_intelligence_csv&'.http_build_query($f)),'scfs_export_intelligence_csv');
        ?>
        <div class="wrap scfs-intelligence"><h1><?php esc_html_e('Feedback Intelligence Dashboard','sustainable-catalyst-feature-suggestions'); ?></h1>
        <p><?php esc_html_e('Aggregate, privacy-conscious signals from submissions, workflow records, and advisory AI triage. AI classifications remain subject to human review.','sustainable-catalyst-feature-suggestions'); ?></p>
        <form method="get" class="scfs-intel-filters"><input type="hidden" name="post_type" value="<?php echo esc_attr(self::POST_TYPE); ?>"><input type="hidden" name="page" value="scfs-intelligence">
        <label>Period <select name="days"><?php foreach(array(30=>'30 days',90=>'90 days',180=>'180 days',365=>'1 year',0=>'All time') as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($f['days'],$v,false).'>'.esc_html($l).'</option>'; ?></select></label>
        <label>Status <select name="review_status"><option value="">All</option><?php foreach($this->review_statuses() as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($f['review_status'],$v,false).'>'.esc_html($l).'</option>'; ?></select></label>
        <label>Category <select name="category"><option value="">All</option><?php foreach($this->categories() as $v) echo '<option value="'.esc_attr($v).'" '.selected($f['category'],$v,false).'>'.esc_html($v).'</option>'; ?></select></label>
        <label>Product <input name="product" value="<?php echo esc_attr($f['product']); ?>" placeholder="workbench"></label><label>Component <input name="component" value="<?php echo esc_attr($f['component']); ?>" placeholder="rest-api"></label><label>Issue <input name="issue_type" value="<?php echo esc_attr($f['issue_type']); ?>" placeholder="bug-defect"></label><label>Release <input name="release" value="<?php echo esc_attr($f['release']); ?>" placeholder="v3-1-0"></label><label>Platform <input name="platform_area" value="<?php echo esc_attr($f['platform_area']); ?>" placeholder="workbench"></label><label>Type <input name="feature_type" value="<?php echo esc_attr($f['feature_type']); ?>" placeholder="feature_request"></label><button class="button button-primary">Apply</button><a class="button" href="<?php echo esc_url($export); ?>">Export intelligence CSV</a></form>
        <div class="scfs-intel-cards"><?php foreach(array('total'=>'Suggestions','analyzed'=>'AI analyzed','unanalyzed'=>'Awaiting analysis','roadmap_ready'=>'Roadmap candidates','safety_flagged'=>'Safety flagged','average_confidence'=>'Avg. confidence','average_impact'=>'Avg. impact','average_effort'=>'Avg. effort') as $k=>$l) echo '<div class="scfs-intel-card"><span>'.esc_html($l).'</span><strong>'.esc_html((string)$d[$k]).'</strong></div>'; ?></div>
        <div class="scfs-intel-grid"><section><h2>Workflow status</h2><?php $this->render_intelligence_bars($d['statuses'],$d['total']); ?></section><section><h2>Products</h2><?php $this->render_intelligence_bars($d['products'],max(1,$d['total'])); ?></section><section><h2>Components</h2><?php $this->render_intelligence_bars($d['components'],max(1,$d['total'])); ?></section><section><h2>Issue types</h2><?php $this->render_intelligence_bars($d['issue_types'],max(1,$d['total'])); ?></section><section><h2>Releases</h2><?php $this->render_intelligence_bars($d['releases'],max(1,$d['total'])); ?></section><section><h2>Platform areas</h2><?php $this->render_intelligence_bars($d['platform_areas'],max(1,$d['analyzed'])); ?></section><section><h2>Feature types</h2><?php $this->render_intelligence_bars($d['feature_types'],max(1,$d['analyzed'])); ?></section><section><h2>Top topics</h2><?php $this->render_intelligence_bars($d['topics'],max(1,array_sum($d['topics']))); ?></section><section><h2>Categories</h2><?php $this->render_intelligence_bars($d['categories'],$d['total']); ?></section><section><h2>Suggested actions</h2><?php $this->render_intelligence_bars($d['actions'],max(1,$d['analyzed'])); ?></section></div>
        <section class="scfs-intel-opportunities"><h2>Highest-priority opportunities</h2><table class="widefat striped"><thead><tr><th>Suggestion</th><th>Platform</th><th>Type</th><th>Confidence</th><th>Advisory score</th></tr></thead><tbody><?php if(!$d['opportunities']) echo '<tr><td colspan="5">No analyzed opportunities in this view.</td></tr>'; foreach($d['opportunities'] as $o) echo '<tr><td><a href="'.esc_url($o['edit_url']).'"><strong>'.esc_html($o['title']).'</strong></a><br><span>'.esc_html($o['summary']).'</span></td><td>'.esc_html($o['platform_area']).'</td><td>'.esc_html($o['feature_type']).'</td><td>'.esc_html((string)$o['confidence']).'</td><td>'.esc_html((string)$o['priority_score']).'</td></tr>'; ?></tbody></table><p class="description">The advisory score combines AI impact, urgency, strategic alignment, effort, and confidence. It is not an automatic roadmap decision.</p></section></div><?php
    }

    public function rest_intelligence($request) { return rest_ensure_response(array('ok'=>true,'version'=>self::VERSION,'filters'=>$this->intelligence_filters(),'data'=>$this->intelligence_data($request->get_params()))); }

    public function export_intelligence_csv() {
        if (!current_user_can('edit_posts')) wp_die(__('You do not have permission to export intelligence.','sustainable-catalyst-feature-suggestions'));
        check_admin_referer('scfs_export_intelligence_csv'); $d=$this->intelligence_data($this->intelligence_filters());
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=scfs-feedback-intelligence-'.gmdate('Y-m-d').'.csv'); $out=fopen('php://output','w');
        fputcsv($out,array('Metric','Key','Value')); foreach(array('total','analyzed','unanalyzed','roadmap_ready','safety_flagged','average_confidence','average_impact','average_effort') as $k) fputcsv($out,array('summary',$k,$d[$k]));
        foreach(array('statuses','categories','products','versions','components','issue_types','releases','platform_areas','feature_types','topics','sentiment','actions','monthly') as $group) foreach($d[$group] as $key=>$value) fputcsv($out,array($group,$key,$value)); fclose($out); exit;
    }

    public function render_export_page() {
        $url = wp_nonce_url(admin_url('admin-post.php?action=scfs_export_csv'), 'scfs_export_csv');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Export Product Feedback', 'sustainable-catalyst-feature-suggestions'); ?></h1>
            <p><?php esc_html_e('Download all product feedback records as a CSV file for review, backlog planning, GitHub issue creation, or archival.', 'sustainable-catalyst-feature-suggestions'); ?></p>
            <p><a class="button button-primary" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Download CSV', 'sustainable-catalyst-feature-suggestions'); ?></a></p>
        </div>
        <?php
    }

    public function export_csv() {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to export suggestions.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer('scfs_export_csv');

        $filename = 'sustainable-catalyst-feature-suggestions-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fputcsv($out, array(
            'ID', 'Submission UUID', 'Source', 'Schema Version', 'Date', 'Post Status', 'Title', 'Products', 'Product Versions', 'Components', 'Issue Types', 'Releases', 'Category', 'Priority', 'Review Status', 'Impact Score', 'Effort Score', 'Roadmap Area', 'GitHub Issue URL', 'Problem', 'Suggestion', 'Success Criteria', 'Beneficiaries', 'Implementation Notes', 'Relevant URL', 'Name', 'Email', 'Follow Up Allowed', 'Consent Confirmed', 'Referrer', 'IP Hash', 'Admin Notes'
        ));

        $query = new WP_Query(array(
            'post_type' => self::POST_TYPE,
            'post_status' => array('private', 'publish', 'draft', 'pending'),
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ));

        foreach ($query->posts as $post) {
            fputcsv($out, array(
                $post->ID,
                $this->ensure_submission_uuid($post->ID),
                get_post_meta($post->ID, '_scfs_source', true),
                get_post_meta($post->ID, '_scfs_schema_version', true),
                $post->post_date,
                $post->post_status,
                $post->post_title,
                class_exists('SCFS_Product_Integration') ? implode(' | ', SCFS_Product_Integration::instance()->flat_term_names($post->ID, 'scfs_product')) : '',
                class_exists('SCFS_Product_Integration') ? implode(' | ', SCFS_Product_Integration::instance()->flat_term_names($post->ID, 'scfs_product_version')) : '',
                class_exists('SCFS_Product_Integration') ? implode(' | ', SCFS_Product_Integration::instance()->flat_term_names($post->ID, 'scfs_component')) : '',
                class_exists('SCFS_Product_Integration') ? implode(' | ', SCFS_Product_Integration::instance()->flat_term_names($post->ID, 'scfs_issue_type')) : '',
                class_exists('SCFS_Product_Integration') ? implode(' | ', SCFS_Product_Integration::instance()->flat_term_names($post->ID, 'scfs_release')) : '',
                get_post_meta($post->ID, '_scfs_category', true),
                get_post_meta($post->ID, '_scfs_priority', true),
                get_post_meta($post->ID, '_scfs_review_status', true),
                get_post_meta($post->ID, '_scfs_impact_score', true),
                get_post_meta($post->ID, '_scfs_effort_score', true),
                get_post_meta($post->ID, '_scfs_roadmap_area', true),
                get_post_meta($post->ID, '_scfs_github_issue_url', true),
                get_post_meta($post->ID, '_scfs_problem', true),
                get_post_meta($post->ID, '_scfs_suggestion', true),
                get_post_meta($post->ID, '_scfs_success_criteria', true),
                get_post_meta($post->ID, '_scfs_beneficiaries', true),
                get_post_meta($post->ID, '_scfs_implementation_notes', true),
                get_post_meta($post->ID, '_scfs_relevant_url', true),
                get_post_meta($post->ID, '_scfs_name', true),
                get_post_meta($post->ID, '_scfs_email', true),
                get_post_meta($post->ID, '_scfs_follow_up', true) === '1' ? 'Yes' : 'No',
                get_post_meta($post->ID, '_scfs_consent', true) === '1' ? 'Yes' : 'No',
                get_post_meta($post->ID, '_scfs_referrer', true),
                get_post_meta($post->ID, '_scfs_ip_hash', true),
                get_post_meta($post->ID, '_scfs_admin_notes', true),
            ));
        }
        fclose($out);
        exit;
    }
}

require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-canonical-product-registry.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-canonical-product-registry-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-installed-plugin-discovery.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-github-connection-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-canonical-product-github-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-github-release-intelligence.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-release-operations-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-product-connection-editor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-release-console-copy.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-release-board.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-product-integration.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-knowledge-base.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-guided-resolution.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-documentation-intelligence.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-integrated-knowledge-base.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-forms.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-survey-intelligence.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-research-librarian-feedback.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-public-ideas.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-opportunity-workflow.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-product-support-platform.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-platform-governance.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-support-content-operations.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-editorial-governance.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-support-article-integrity.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-support-content-governance.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-feedback-product-signals.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-support-analytics-documentation-effectiveness.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-cross-product-support-graph.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-public-support-integrations.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-connected-product-support-platform.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-case-foundation.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-agent-workspace.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-customer-portal.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-service-levels.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-secure-evidence.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-knowledge-assisted-resolution.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-workflow-automation.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-email-channel-operations.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-quality-analytics.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-institutional-workspaces.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-api-integrations.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-help-desk-production-hardening.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-connected-help-desk-platform.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-support-discovery.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-unified-support-search.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-known-issue-release-intelligence.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-repository-release-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-support-reliability-center.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-cross-product-orchestration.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-scfs-connected-support-operations.php';
SCFS_Canonical_Product_Registry::instance();
SCFS_Canonical_Product_Registry_Admin::instance();
SCFS_Installed_Plugin_Discovery::instance();
SCFS_GitHub_Connection_Settings::instance();
SCFS_Canonical_Product_GitHub_Sync::instance();
SCFS_GitHub_Release_Intelligence::instance();
SCFS_Release_Operations_Admin::instance();
SCFS_Product_Connection_Editor::instance();
SCFS_Release_Console_Copy::instance();
SCFS_Release_Board::instance();
SCFS_Product_Integration::instance();
SCFS_Knowledge_Base_Foundation::instance();
SCFS_Guided_Resolution::instance();
SCFS_Documentation_Feature_Intelligence::instance();
SCFS_Integrated_Knowledge_Base::instance();
SCFS_Forms_Foundation::instance();
SCFS_Survey_Intelligence::instance();
SCFS_Research_Librarian_Feedback::instance();
SCFS_Public_Ideas::instance();
SCFS_Opportunity_Workflow::instance();
SCFS_Product_Support_Platform::instance();
SCFS_Platform_Governance::instance();
SCFS_Support_Content_Operations::instance();
SCFS_Editorial_Governance::instance();
SCFS_Support_Article_Integrity::instance();
SCFS_Support_Content_Governance::instance();
SCFS_Feedback_Product_Signals::instance();
SCFS_Support_Analytics_Documentation_Effectiveness::instance();
SCFS_Cross_Product_Support_Graph::instance();
SCFS_Public_Support_Integrations::instance();
SCFS_Connected_Product_Support_Platform::instance();
SCFS_Help_Desk_Case_Foundation::instance();
SCFS_Help_Desk_Agent_Workspace::instance();
SCFS_Help_Desk_Customer_Portal::instance();
SCFS_Help_Desk_Service_Levels::instance();
SCFS_Help_Desk_Secure_Evidence::instance();
SCFS_Help_Desk_Knowledge_Assisted_Resolution::instance();
SCFS_Help_Desk_Workflow_Automation::instance();
SCFS_Help_Desk_Email_Channel_Operations::instance();
SCFS_Help_Desk_Quality_Analytics::instance();
SCFS_Help_Desk_Institutional_Workspaces::instance();
SCFS_Help_Desk_API_Integrations::instance();
SCFS_Help_Desk_Production_Hardening::instance();
SCFS_Connected_Help_Desk_Platform::instance();
SCFS_Support_Discovery::instance();
SCFS_Unified_Support_Search::instance();
SCFS_Known_Issue_Release_Intelligence::instance();
SCFS_Repository_Release_Synchronization::instance();
SCFS_Support_Reliability_Center::instance();
SCFS_Cross_Product_Support_Orchestration::instance();
SCFS_Connected_Support_Operations::instance();

Sustainable_Catalyst_Feature_Suggestions::instance();
register_activation_hook(__FILE__, array('Sustainable_Catalyst_Feature_Suggestions', 'activate'));
register_deactivation_hook(__FILE__, array('Sustainable_Catalyst_Feature_Suggestions', 'deactivate'));
