<?php
/**
 * Release Console copy controls.
 *
 * Keeps public presentation language editable while product identity, versions,
 * lifecycle state, and release evidence remain governed by the Product Registry.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Release_Console_Copy {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-release-console-copy/1.0';
    const OPTION_KEY = 'scfs_release_console_copy';
    const ADMIN_SLUG = 'scfs-release-console-copy';
    const NONCE_ACTION = 'scfs_save_release_console_copy';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_page'), 22);
        add_action('admin_post_scfs_save_release_console_copy', array($this, 'save_action'));
        add_action('admin_post_scfs_reset_release_console_copy', array($this, 'reset_action'));
    }

    public static function activate() {
        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, self::instance()->defaults(), '', false);
        }
    }

    public function defaults() {
        return array(
            'title' => __('Release Console', 'sustainable-catalyst-feature-suggestions'),
            'terminal_intro' => __('Five governed release screens rotating across the Sustainable Catalyst platform.', 'sustainable-catalyst-feature-suggestions'),
            'standard_intro' => __('Current releases across the Sustainable Catalyst platform.', 'sustainable-catalyst-feature-suggestions'),
            'screen_foundation' => __('Foundation', 'sustainable-catalyst-feature-suggestions'),
            'screen_research_intelligence' => __('Research and Intelligence', 'sustainable-catalyst-feature-suggestions'),
            'screen_data_analysis' => __('Data and Analysis', 'sustainable-catalyst-feature-suggestions'),
            'screen_creation_systems' => __('Creation and Systems', 'sustainable-catalyst-feature-suggestions'),
            'screen_commercial' => __('Commercial Release', 'sustainable-catalyst-feature-suggestions'),
            'column_system' => __('system', 'sustainable-catalyst-feature-suggestions'),
            'column_version' => __('version', 'sustainable-catalyst-feature-suggestions'),
            'column_state' => __('state', 'sustainable-catalyst-feature-suggestions'),
            'column_source' => __('source', 'sustainable-catalyst-feature-suggestions'),
            'summary_systems' => __('systems', 'sustainable-catalyst-feature-suggestions'),
            'summary_plugin' => __('plugin', 'sustainable-catalyst-feature-suggestions'),
            'summary_manual' => __('manual', 'sustainable-catalyst-feature-suggestions'),
            'summary_matched' => __('matched', 'sustainable-catalyst-feature-suggestions'),
            'summary_last_sync' => __('last sync', 'sustainable-catalyst-feature-suggestions'),
            'previous' => __('Previous', 'sustainable-catalyst-feature-suggestions'),
            'pause' => __('Pause', 'sustainable-catalyst-feature-suggestions'),
            'play' => __('Play', 'sustainable-catalyst-feature-suggestions'),
            'next' => __('Next', 'sustainable-catalyst-feature-suggestions'),
            'previous_aria' => __('Previous release screen', 'sustainable-catalyst-feature-suggestions'),
            'pause_aria' => __('Pause release screens', 'sustainable-catalyst-feature-suggestions'),
            'play_aria' => __('Play release screens', 'sustainable-catalyst-feature-suggestions'),
            'next_aria' => __('Next release screen', 'sustainable-catalyst-feature-suggestions'),
            'controls_aria' => __('Release Console controls', 'sustainable-catalyst-feature-suggestions'),
            'screens_aria' => __('Release Console screens. Use Left and Right Arrow keys to navigate, Home for the first screen, End for the last screen, and Space to pause or play.', 'sustainable-catalyst-feature-suggestions'),
            'noscript' => __('Rotation controls require JavaScript. All release groups are shown.', 'sustainable-catalyst-feature-suggestions'),
            'footer_releases' => __('repository', 'sustainable-catalyst-feature-suggestions'),
            'footer_repository' => __('repository', 'sustainable-catalyst-feature-suggestions'),
            'footer_repository_url' => '',
            'footer_support' => __('support', 'sustainable-catalyst-feature-suggestions'),
            'footer_support_url' => '/support/',
            'empty_message' => __('No governed releases are available for this view.', 'sustainable-catalyst-feature-suggestions'),
            'unavailable_message' => __('Release intelligence is temporarily unavailable.', 'sustainable-catalyst-feature-suggestions'),
            'label_previous_version' => __('from', 'sustainable-catalyst-feature-suggestions'),
            'label_released' => __('released', 'sustainable-catalyst-feature-suggestions'),
            'label_validated' => __('validated', 'sustainable-catalyst-feature-suggestions'),
            'label_validation_partial' => __('validation partial', 'sustainable-catalyst-feature-suggestions'),
            'label_validation_pending' => __('validation pending', 'sustainable-catalyst-feature-suggestions'),
            'label_validation_failed' => __('validation failed', 'sustainable-catalyst-feature-suggestions'),
            'label_docs_ready' => __('docs ready', 'sustainable-catalyst-feature-suggestions'),
            'label_docs_partial' => __('docs partial', 'sustainable-catalyst-feature-suggestions'),
            'label_docs_missing' => __('docs missing', 'sustainable-catalyst-feature-suggestions'),
            'label_known_issue' => __('known issue', 'sustainable-catalyst-feature-suggestions'),
            'label_known_issues' => __('known issues', 'sustainable-catalyst-feature-suggestions'),
            'label_commit' => __('commit', 'sustainable-catalyst-feature-suggestions'),
            'label_repository_updated' => __('repository updated', 'sustainable-catalyst-feature-suggestions'),
            'label_recently_updated' => __('recently updated', 'sustainable-catalyst-feature-suggestions'),
            'label_maintenance' => __('maintenance', 'sustainable-catalyst-feature-suggestions'),
            'label_superseded' => __('superseded', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    public function copy() {
        $stored = get_option(self::OPTION_KEY, array());
        $stored = is_array($stored) ? $stored : array();
        $copy = array_merge($this->defaults(), $stored);
        if (!array_key_exists('footer_repository', $stored) && !empty($stored['footer_releases'])) {
            $legacy_label = sanitize_key($stored['footer_releases']);
            $copy['footer_repository'] = in_array($legacy_label, array('release', 'releases'), true)
                ? $this->defaults()['footer_repository']
                : $stored['footer_releases'];
        }
        return apply_filters('scfs_release_console_copy', $copy);
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'option_key' => self::OPTION_KEY,
            'built_in_defaults' => true,
            'wordpress_settings' => true,
            'shortcode_overrides' => true,
            'filter' => 'scfs_release_console_copy',
            'product_facts_editable_here' => false,
            'registry_authority_preserved' => true,
            'fields' => array_keys($this->defaults()),
            'footer_destination_validation' => true,
            'cache_invalidation_action' => 'scfs_release_console_copy_updated',
        );
    }

    private function sanitize_link_target($value, $fallback = '') {
        $value = trim((string) wp_unslash($value));
        if ($value === '') {
            return $fallback;
        }
        if (strpos($value, '/') === 0) {
            return '/' . ltrim($value, '/');
        }
        return esc_url_raw($value);
    }

    private function sanitize($input) {
        $defaults = $this->defaults();
        $clean = array();
        foreach ($defaults as $key => $fallback) {
            if (in_array($key, array('footer_repository_url', 'footer_support_url'), true)) {
                $clean[$key] = $this->sanitize_link_target(isset($input[$key]) ? $input[$key] : '', $fallback);
                continue;
            }
            $value = isset($input[$key]) ? sanitize_text_field(wp_unslash($input[$key])) : $fallback;
            $clean[$key] = $value === '' ? $fallback : $value;
        }
        $clean['footer_releases'] = $clean['footer_repository'];
        return $clean;
    }

    public function footer_settings() {
        $copy = $this->copy();
        return array(
            'footer_repository' => $copy['footer_repository'],
            'footer_repository_url' => $copy['footer_repository_url'],
            'footer_support' => $copy['footer_support'],
            'footer_support_url' => $copy['footer_support_url'],
        );
    }

    public function update_footer_settings($input) {
        $stored = get_option(self::OPTION_KEY, array());
        $stored = is_array($stored) ? $stored : array();
        $defaults = $this->defaults();
        $footer = is_array($input) ? $input : array();
        $repository_label = sanitize_text_field(wp_unslash($footer['footer_repository'] ?? $defaults['footer_repository']));
        $support_label = sanitize_text_field(wp_unslash($footer['footer_support'] ?? $defaults['footer_support']));
        $stored['footer_repository'] = $repository_label !== '' ? $repository_label : $defaults['footer_repository'];
        $stored['footer_releases'] = $stored['footer_repository'];
        $stored['footer_repository_url'] = $this->sanitize_link_target($footer['footer_repository_url'] ?? '', '');
        $stored['footer_support'] = $support_label !== '' ? $support_label : $defaults['footer_support'];
        $stored['footer_support_url'] = $this->sanitize_link_target($footer['footer_support_url'] ?? '', $defaults['footer_support_url']);
        update_option(self::OPTION_KEY, array_merge($defaults, $stored), false);
        do_action('scfs_release_console_copy_updated', array_merge($defaults, $stored));
        return $this->footer_settings();
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Release Console Copy', 'sustainable-catalyst-feature-suggestions'),
            __('Release Console Copy', 'sustainable-catalyst-feature-suggestions'),
            'manage_options',
            self::ADMIN_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $copy = $this->copy();
        $sections = array(
            __('Header and screens', 'sustainable-catalyst-feature-suggestions') => array('title','terminal_intro','standard_intro','screen_foundation','screen_research_intelligence','screen_data_analysis','screen_creation_systems','screen_commercial'),
            __('Controls and navigation', 'sustainable-catalyst-feature-suggestions') => array('previous','pause','play','next','previous_aria','pause_aria','play_aria','next_aria','controls_aria','screens_aria','noscript'),
            __('Columns and summary', 'sustainable-catalyst-feature-suggestions') => array('column_system','column_version','column_state','column_source','summary_systems','summary_plugin','summary_manual','summary_matched','summary_last_sync'),
            __('Release intelligence labels', 'sustainable-catalyst-feature-suggestions') => array('label_previous_version','label_released','label_validated','label_validation_partial','label_validation_pending','label_validation_failed','label_docs_ready','label_docs_partial','label_docs_missing','label_known_issue','label_known_issues','label_commit','label_repository_updated','label_recently_updated','label_maintenance','label_superseded'),
            __('Fallback messages', 'sustainable-catalyst-feature-suggestions') => array('empty_message','unavailable_message'),
        );
        echo '<div class="wrap"><h1>' . esc_html__('Release Console Copy', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p>' . esc_html__('Edit public presentation language here. Product names, versions, lifecycle states, and release evidence remain governed by the Product Registry.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="scfs_save_release_console_copy">';
        wp_nonce_field(self::NONCE_ACTION, 'scfs_release_console_copy_nonce');
        foreach ($sections as $heading => $keys) {
            echo '<h2>' . esc_html($heading) . '</h2><table class="form-table" role="presentation"><tbody>';
            foreach ($keys as $key) {
                $label = ucwords(str_replace('_', ' ', $key));
                echo '<tr><th scope="row"><label for="scfs-copy-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="large-text" id="scfs-copy-' . esc_attr($key) . '" name="copy[' . esc_attr($key) . ']" value="' . esc_attr($copy[$key]) . '"></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h2>' . esc_html__('Footer links', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<p>' . esc_html__('Change both the visible terminal labels and their destinations. Leave the repository URL blank to use the GitHub repository mapped to Product Support and Feedback Platform.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="scfs-copy-footer-repository">' . esc_html__('Repository label', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input class="regular-text" id="scfs-copy-footer-repository" name="copy[footer_repository]" value="' . esc_attr($copy['footer_repository']) . '"><p class="description">' . esc_html__('Displayed as ./repository when the label is repository.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-copy-footer-repository-url">' . esc_html__('Repository destination', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input class="large-text code" type="url" id="scfs-copy-footer-repository-url" name="copy[footer_repository_url]" value="' . esc_attr($copy['footer_repository_url']) . '" placeholder="Automatic from Canonical Product Registry"><p class="description">' . esc_html__('Use a complete GitHub URL, or leave blank for the mapped canonical repository.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-copy-footer-support">' . esc_html__('Support label', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input class="regular-text" id="scfs-copy-footer-support" name="copy[footer_support]" value="' . esc_attr($copy['footer_support']) . '"><p class="description">' . esc_html__('Displayed as ./support when the label is support.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="scfs-copy-footer-support-url">' . esc_html__('Support destination', 'sustainable-catalyst-feature-suggestions') . '</label></th><td><input class="large-text code" id="scfs-copy-footer-support-url" name="copy[footer_support_url]" value="' . esc_attr($copy['footer_support_url']) . '" placeholder="/support/"><p class="description">' . esc_html__('Use a site-relative path such as /support/ or a complete external URL.', 'sustainable-catalyst-feature-suggestions') . '</p></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save Console Copy', 'sustainable-catalyst-feature-suggestions'));
        echo '</form><p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_reset_release_console_copy'), 'scfs_reset_release_console_copy')) . '">' . esc_html__('Reset copy defaults', 'sustainable-catalyst-feature-suggestions') . '</a></p></div>';
    }

    public function save_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer(self::NONCE_ACTION, 'scfs_release_console_copy_nonce');
        $input = isset($_POST['copy']) && is_array($_POST['copy']) ? $_POST['copy'] : array();
        $copy = $this->sanitize($input);
        update_option(self::OPTION_KEY, $copy, false);
        do_action('scfs_release_console_copy_updated', $copy);
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&updated=1'));
        exit;
    }

    public function reset_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('scfs_reset_release_console_copy');
        $copy = $this->defaults();
        update_option(self::OPTION_KEY, $copy, false);
        do_action('scfs_release_console_copy_updated', $copy);
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG . '&updated=1'));
        exit;
    }
}
