<?php
/**
 * Canonical Product Registry Administration.
 *
 * Provides governed catalog operations without changing the canonical option
 * key or exposing private credentials. All consequential mutations create a
 * reversible backup and append an administrator-attributed history record.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Canonical_Product_Registry_Admin {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-canonical-product-registry-admin/1.0';
    const ADMIN_SLUG = 'scfs-product-registry';
    const HISTORY_OPTION = 'scfs_canonical_product_registry_admin_history';
    const BACKUP_OPTION = 'scfs_canonical_product_registry_backups';
    const IMPORT_TRANSIENT_PREFIX = 'scfs_registry_import_';
    const MAX_HISTORY = 300;
    const MAX_BACKUPS = 20;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_scfs_registry_create_product', array($this, 'create_product_action'));
        add_action('admin_post_scfs_registry_save_order', array($this, 'save_order_action'));
        add_action('admin_post_scfs_registry_archive_product', array($this, 'archive_product_action'));
        add_action('admin_post_scfs_registry_restore_product', array($this, 'restore_product_action'));
        add_action('admin_post_scfs_registry_merge_products', array($this, 'merge_products_action'));
        add_action('admin_post_scfs_registry_import_dry_run', array($this, 'import_dry_run_action'));
        add_action('admin_post_scfs_registry_import_apply', array($this, 'import_apply_action'));
        add_action('admin_post_scfs_registry_restore_backup', array($this, 'restore_backup_action'));
        add_action('admin_post_scfs_registry_export_governed', array($this, 'export_action'));

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('scfs products history', array($this, 'cli_history'));
            WP_CLI::add_command('scfs products backups', array($this, 'cli_backups'));
        }
    }

    public static function activate() {
        if (!get_option(self::HISTORY_OPTION)) {
            add_option(self::HISTORY_OPTION, array(), '', false);
        }
        if (!get_option(self::BACKUP_OPTION)) {
            add_option(self::BACKUP_OPTION, array(), '', false);
        }
    }

    public function schema_record() {
        $features = array(
            'searchable_registry' => true,
            'family_and_lifecycle_filters' => true,
            'drag_drop_console_ordering' => true,
            'create_product' => true,
            'merge_duplicates' => true,
            'alias_collision_review' => true,
            'archive_restore' => true,
            'administrator_history' => true,
            'governed_export' => true,
            'dry_run_import' => true,
            'automatic_backups' => true,
            'backup_restore' => true,
        );
        return array_merge(array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'features' => $features,
        ), $features);
    }

    private function can_manage() {
        return class_exists('SCFS_Canonical_Product_Registry')
            && SCFS_Canonical_Product_Registry::instance()->can_manage();
    }

    private function registry_service() {
        return SCFS_Canonical_Product_Registry::instance();
    }

    private function registry() {
        return $this->registry_service()->registry();
    }

    public function enqueue_assets($hook_suffix) {
        if (strpos((string) $hook_suffix, self::ADMIN_SLUG) === false) {
            return;
        }
        $base = plugin_dir_url(dirname(__FILE__)) . 'assets/';
        wp_enqueue_style('scfs-canonical-product-registry-admin-v770', $base . 'canonical-product-registry-admin-v7.8.1.css', array(), self::VERSION);
        wp_enqueue_script('scfs-canonical-product-registry-admin-v770', $base . 'canonical-product-registry-admin-v7.8.1.js', array(), self::VERSION, true);
    }

    private function user_context() {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        return array(
            'user_id' => $user && isset($user->ID) ? absint($user->ID) : 0,
            'user_login' => $user && isset($user->user_login) ? sanitize_text_field($user->user_login) : '',
            'display_name' => $user && isset($user->display_name) ? sanitize_text_field($user->display_name) : '',
        );
    }

    private function record_history($action, $context = array()) {
        $history = (array) get_option(self::HISTORY_OPTION, array());
        $history[] = array_merge(array(
            'event_id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('scfs_', true),
            'action' => sanitize_key($action),
            'recorded_at' => gmdate('c'),
            'administrator' => $this->user_context(),
        ), is_array($context) ? $context : array());
        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }
        update_option(self::HISTORY_OPTION, $history, false);
    }

    public function history($limit = 50) {
        $history = array_reverse((array) get_option(self::HISTORY_OPTION, array()));
        return array_slice($history, 0, max(1, absint($limit)));
    }

    private function create_backup($reason, $registry = null) {
        $registry = $registry === null ? $this->registry() : $this->registry_service()->normalize_registry($registry);
        $backups = (array) get_option(self::BACKUP_OPTION, array());
        $backup = array(
            'backup_id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('backup_', true),
            'created_at' => gmdate('c'),
            'reason' => sanitize_key($reason),
            'administrator' => $this->user_context(),
            'schema' => SCFS_Canonical_Product_Registry::SCHEMA,
            'fingerprint' => $this->registry_service()->registry_fingerprint($registry),
            'product_count' => count($registry),
            'products' => $registry,
        );
        $backups[] = $backup;
        if (count($backups) > self::MAX_BACKUPS) {
            $backups = array_slice($backups, -self::MAX_BACKUPS);
        }
        update_option(self::BACKUP_OPTION, $backups, false);
        return $backup;
    }

    public function backups($limit = 10) {
        $backups = array_reverse((array) get_option(self::BACKUP_OPTION, array()));
        return array_slice($backups, 0, max(1, absint($limit)));
    }

    private function commit_registry($records, $action, $context = array()) {
        $normalized = $this->registry_service()->normalize_registry($records);
        if (!$normalized) {
            return new WP_Error('registry_empty', __('The canonical product registry cannot be empty.', 'sustainable-catalyst-feature-suggestions'));
        }
        $integrity = $this->registry_service()->integrity_report($normalized);
        if (!$integrity['valid']) {
            return new WP_Error('registry_integrity', __('The proposed registry contains integrity errors.', 'sustainable-catalyst-feature-suggestions'), $integrity);
        }
        update_option(SCFS_Canonical_Product_Registry::OPTION_KEY, $normalized, false);
        update_option(SCFS_Canonical_Product_Registry::SCHEMA_OPTION, SCFS_Canonical_Product_Registry::SCHEMA, false);
        $this->record_history($action, array_merge(array(
            'fingerprint' => $integrity['fingerprint'],
            'product_count' => count($normalized),
            'warning_count' => $integrity['warning_count'],
        ), $context));
        do_action('scfs_product_registry_updated', $normalized);
        do_action('scfs_release_console_cache_invalidate', 'canonical_product_registry_admin');
        return $normalized;
    }

    private function redirect($args = array()) {
        $url = admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG);
        wp_safe_redirect(add_query_arg($args, $url));
        exit;
    }

    private function require_action($nonce_action) {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        check_admin_referer($nonce_action);
    }

    private function csv_list($value, $sanitize = 'sanitize_text_field') {
        if (is_array($value)) {
            $values = $value;
        } else {
            $values = preg_split('/[\r\n,]+/', (string) $value);
        }
        return array_values(array_unique(array_filter(array_map($sanitize, (array) $values))));
    }

    public function create_product_action() {
        $this->require_action('scfs_registry_create_product');
        $id = sanitize_key(isset($_POST['canonical_id']) ? wp_unslash($_POST['canonical_id']) : '');
        $name = sanitize_text_field(isset($_POST['name']) ? wp_unslash($_POST['name']) : '');
        $family = sanitize_key(isset($_POST['family']) ? wp_unslash($_POST['family']) : 'foundation');
        $product_type = sanitize_key(isset($_POST['product_type']) ? wp_unslash($_POST['product_type']) : 'platform_module');
        $registry = $this->registry();
        if ($id === '' || $name === '') {
            $this->redirect(array('registry_error' => 'create_missing_fields'));
        }
        if (isset($registry[$id])) {
            $this->redirect(array('registry_error' => 'canonical_id_exists'));
        }
        $this->create_backup('before_create_product', $registry);
        $record = $this->registry_service()->new_product_record($id, $name, $name, $family, (count($registry) + 1) * 10, array(
            'product_type' => $product_type,
            'status' => 'unverified',
            'lifecycle_state' => 'planned',
            'public_visible' => '',
            'homepage_visible' => '',
            'record_updated_at' => gmdate('c'),
            'verification_source' => 'administrator',
        ));
        $registry[$id] = $record;
        $result = $this->commit_registry($registry, 'product_created', array('product_id' => $id));
        if (is_wp_error($result)) {
            $this->redirect(array('registry_error' => $result->get_error_code()));
        }
        $this->redirect(array('registry_updated' => 'product_created', 'product' => $id));
    }

    public function save_order_action() {
        $this->require_action('scfs_registry_save_order');
        $registry = $this->registry();
        $orders = isset($_POST['order']) && is_array($_POST['order']) ? wp_unslash($_POST['order']) : array();
        if (!$orders) {
            $this->redirect(array('registry_error' => 'order_missing'));
        }
        $this->create_backup('before_reorder', $registry);
        foreach ($orders as $family => $product_ids) {
            $family = sanitize_key($family);
            $position = 10;
            foreach ((array) $product_ids as $product_id) {
                $product_id = sanitize_key($product_id);
                if (!isset($registry[$product_id])) {
                    continue;
                }
                $registry[$product_id]['family'] = $family;
                $registry[$product_id]['console_screen'] = $family;
                $registry[$product_id]['display_order'] = $position;
                $registry[$product_id]['record_updated_at'] = gmdate('c');
                $position += 10;
            }
        }
        $result = $this->commit_registry($registry, 'console_order_saved');
        if (is_wp_error($result)) {
            $this->redirect(array('registry_error' => $result->get_error_code()));
        }
        $this->redirect(array('registry_updated' => 'order_saved'));
    }

    public function archive_product_action() {
        $this->require_action('scfs_registry_archive_product');
        $id = sanitize_key(isset($_POST['product_id']) ? wp_unslash($_POST['product_id']) : '');
        $registry = $this->registry();
        if (!isset($registry[$id]) || $id === 'product-support-feedback') {
            $this->redirect(array('registry_error' => 'archive_not_allowed'));
        }
        $this->create_backup('before_archive', $registry);
        $registry[$id]['archived'] = '1';
        $registry[$id]['archived_at'] = gmdate('c');
        $registry[$id]['archived_by'] = (string) $this->user_context()['user_id'];
        $registry[$id]['lifecycle_state'] = 'retired';
        $registry[$id]['public_visible'] = '';
        $registry[$id]['homepage_visible'] = '';
        $registry[$id]['record_updated_at'] = gmdate('c');
        $result = $this->commit_registry($registry, 'product_archived', array('product_id' => $id));
        if (is_wp_error($result)) {
            $this->redirect(array('registry_error' => $result->get_error_code()));
        }
        $this->redirect(array('registry_updated' => 'product_archived', 'product' => $id));
    }

    public function restore_product_action() {
        $this->require_action('scfs_registry_restore_product');
        $id = sanitize_key(isset($_POST['product_id']) ? wp_unslash($_POST['product_id']) : '');
        $registry = $this->registry();
        if (!isset($registry[$id])) {
            $this->redirect(array('registry_error' => 'product_not_found'));
        }
        $this->create_backup('before_restore_product', $registry);
        $registry[$id]['archived'] = '';
        $registry[$id]['archived_at'] = '';
        $registry[$id]['archived_by'] = '';
        $registry[$id]['lifecycle_state'] = 'maintenance';
        $registry[$id]['record_updated_at'] = gmdate('c');
        $result = $this->commit_registry($registry, 'product_restored', array('product_id' => $id));
        if (is_wp_error($result)) {
            $this->redirect(array('registry_error' => $result->get_error_code()));
        }
        $this->redirect(array('registry_updated' => 'product_restored', 'product' => $id));
    }

    private function merge_identifier_lists($target, $source, $field, $extra = array()) {
        return array_values(array_unique(array_filter(array_merge(
            (array) ($target[$field] ?? array()),
            (array) ($source[$field] ?? array()),
            (array) $extra
        ))));
    }

    public function merge_products_action() {
        $this->require_action('scfs_registry_merge_products');
        $source_id = sanitize_key(isset($_POST['source_product']) ? wp_unslash($_POST['source_product']) : '');
        $target_id = sanitize_key(isset($_POST['target_product']) ? wp_unslash($_POST['target_product']) : '');
        $registry = $this->registry();
        if ($source_id === '' || $target_id === '' || $source_id === $target_id || !isset($registry[$source_id], $registry[$target_id])) {
            $this->redirect(array('registry_error' => 'invalid_merge'));
        }
        $this->create_backup('before_merge', $registry);
        $source = $registry[$source_id];
        $target = $registry[$target_id];
        $target['legacy_names'] = $this->merge_identifier_lists($target, $source, 'legacy_names', array($source['name'], $source['short_name'], $source_id));
        $target['legacy_plugin_files'] = $this->merge_identifier_lists($target, $source, 'legacy_plugin_files', array($source['plugin_file']));
        $target['legacy_plugin_slugs'] = $this->merge_identifier_lists($target, $source, 'legacy_plugin_slugs', array($source['plugin_slug']));
        $target['legacy_text_domains'] = $this->merge_identifier_lists($target, $source, 'legacy_text_domains', array($source['plugin_text_domain']));
        foreach (array('plugin_file', 'plugin_slug', 'plugin_text_domain', 'github_repository_url', 'repository_slug', 'documentation_url', 'support_url', 'product_url', 'release_notes_url') as $field) {
            if (empty($target[$field]) && !empty($source[$field])) {
                $target[$field] = $source[$field];
            }
        }
        $target['record_updated_at'] = gmdate('c');
        $source['archived'] = '1';
        $source['archived_at'] = gmdate('c');
        $source['archived_by'] = (string) $this->user_context()['user_id'];
        $source['lifecycle_state'] = 'superseded';
        $source['superseded_by'] = $target_id;
        $source['public_visible'] = '';
        $source['homepage_visible'] = '';
        $source['record_updated_at'] = gmdate('c');
        $registry[$target_id] = $target;
        $registry[$source_id] = $source;
        $result = $this->commit_registry($registry, 'products_merged', array('source_product' => $source_id, 'target_product' => $target_id));
        if (is_wp_error($result)) {
            $this->redirect(array('registry_error' => $result->get_error_code()));
        }
        $this->redirect(array('registry_updated' => 'products_merged', 'product' => $target_id));
    }

    private function import_transient_key() {
        $user_id = function_exists('get_current_user_id') ? absint(get_current_user_id()) : 0;
        return self::IMPORT_TRANSIENT_PREFIX . $user_id;
    }

    private function decode_import_payload($raw) {
        $payload = json_decode((string) $raw, true);
        if (!is_array($payload)) {
            return new WP_Error('invalid_json', __('The import file is not valid JSON.', 'sustainable-catalyst-feature-suggestions'));
        }
        $products = isset($payload['products']) ? $payload['products'] : $payload;
        if (!is_array($products)) {
            return new WP_Error('products_missing', __('The import file does not contain product records.', 'sustainable-catalyst-feature-suggestions'));
        }
        $indexed = array();
        foreach ($products as $key => $record) {
            if (!is_array($record)) {
                continue;
            }
            $id = sanitize_key($record['canonical_id'] ?? (is_string($key) ? $key : ''));
            if ($id !== '') {
                $indexed[$id] = $record;
            }
        }
        if (!$indexed) {
            return new WP_Error('products_empty', __('The import contains no usable canonical products.', 'sustainable-catalyst-feature-suggestions'));
        }
        return $indexed;
    }

    public function import_dry_run_action() {
        $this->require_action('scfs_registry_import_dry_run');
        $raw = '';
        if (!empty($_FILES['registry_file']['tmp_name']) && is_uploaded_file($_FILES['registry_file']['tmp_name'])) {
            $raw = file_get_contents($_FILES['registry_file']['tmp_name']);
        } elseif (isset($_POST['registry_json'])) {
            $raw = wp_unslash($_POST['registry_json']);
        }
        $records = $this->decode_import_payload($raw);
        if (is_wp_error($records)) {
            $this->redirect(array('registry_error' => $records->get_error_code()));
        }
        $normalized = $this->registry_service()->normalize_registry($records);
        $integrity = $this->registry_service()->integrity_report($normalized);
        $current = $this->registry();
        $report = array(
            'created_at' => gmdate('c'),
            'valid' => $integrity['valid'],
            'error_count' => $integrity['error_count'],
            'warning_count' => $integrity['warning_count'],
            'current_count' => count($current),
            'import_count' => count($normalized),
            'added' => array_values(array_diff(array_keys($normalized), array_keys($current))),
            'removed' => array_values(array_diff(array_keys($current), array_keys($normalized))),
            'changed' => array_values(array_filter(array_keys($normalized), function ($id) use ($normalized, $current) {
                return isset($current[$id]) && wp_json_encode($normalized[$id]) !== wp_json_encode($current[$id]);
            })),
            'integrity' => $integrity,
            'products' => $normalized,
        );
        set_transient($this->import_transient_key(), $report, 30 * MINUTE_IN_SECONDS);
        $this->record_history('registry_import_dry_run', array(
            'valid' => $report['valid'],
            'import_count' => $report['import_count'],
            'error_count' => $report['error_count'],
            'warning_count' => $report['warning_count'],
        ));
        $this->redirect(array('registry_import' => 'dry_run'));
    }

    public function import_apply_action() {
        $this->require_action('scfs_registry_import_apply');
        $report = get_transient($this->import_transient_key());
        if (!is_array($report) || empty($report['valid']) || empty($report['products'])) {
            $this->redirect(array('registry_error' => 'valid_dry_run_required'));
        }
        $current = $this->registry();
        $backup = $this->create_backup('before_import', $current);
        $result = $this->commit_registry($report['products'], 'registry_import_applied', array(
            'backup_id' => $backup['backup_id'],
            'added' => $report['added'],
            'removed' => $report['removed'],
            'changed' => $report['changed'],
        ));
        if (is_wp_error($result)) {
            $this->redirect(array('registry_error' => $result->get_error_code()));
        }
        delete_transient($this->import_transient_key());
        $this->redirect(array('registry_updated' => 'import_applied'));
    }

    public function restore_backup_action() {
        $this->require_action('scfs_registry_restore_backup');
        $backup_id = sanitize_text_field(isset($_POST['backup_id']) ? wp_unslash($_POST['backup_id']) : '');
        $backups = (array) get_option(self::BACKUP_OPTION, array());
        $selected = null;
        foreach ($backups as $backup) {
            if (isset($backup['backup_id']) && hash_equals((string) $backup['backup_id'], $backup_id)) {
                $selected = $backup;
                break;
            }
        }
        if (!$selected || empty($selected['products'])) {
            $this->redirect(array('registry_error' => 'backup_not_found'));
        }
        $this->create_backup('before_backup_restore', $this->registry());
        $result = $this->commit_registry($selected['products'], 'registry_backup_restored', array('backup_id' => $backup_id));
        if (is_wp_error($result)) {
            $this->redirect(array('registry_error' => $result->get_error_code()));
        }
        $this->redirect(array('registry_updated' => 'backup_restored'));
    }

    public function export_action() {
        $this->require_action('scfs_registry_export_governed');
        $registry = $this->registry();
        $payload = array(
            'schema' => SCFS_Canonical_Product_Registry::SCHEMA,
            'administration_schema' => self::SCHEMA,
            'version' => self::VERSION,
            'exported_at' => gmdate('c'),
            'fingerprint' => $this->registry_service()->registry_fingerprint($registry),
            'integrity' => $this->registry_service()->integrity_report($registry),
            'products' => array_values($registry),
        );
        $this->record_history('registry_exported', array('product_count' => count($registry), 'fingerprint' => $payload['fingerprint']));
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=scfs-canonical-product-registry-' . gmdate('Y-m-d') . '.json');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function filtered_registry($registry) {
        $query = isset($_GET['registry_search']) ? strtolower(trim(sanitize_text_field(wp_unslash($_GET['registry_search'])))) : '';
        $family = isset($_GET['registry_family']) ? sanitize_key(wp_unslash($_GET['registry_family'])) : '';
        $lifecycle = isset($_GET['registry_lifecycle']) ? sanitize_key(wp_unslash($_GET['registry_lifecycle'])) : '';
        $visibility = isset($_GET['registry_visibility']) ? sanitize_key(wp_unslash($_GET['registry_visibility'])) : '';
        return array_filter($registry, function ($record) use ($query, $family, $lifecycle, $visibility) {
            if ($query !== '') {
                $haystack = strtolower(implode(' ', array_merge(array(
                    $record['canonical_id'],
                    $record['name'],
                    $record['short_name'],
                    $record['repository_slug'],
                    $record['plugin_slug'],
                ), (array) $record['legacy_names'])));
                if (strpos($haystack, $query) === false) {
                    return false;
                }
            }
            if ($family !== '' && $record['family'] !== $family) {
                return false;
            }
            if ($lifecycle !== '' && $record['lifecycle_state'] !== $lifecycle) {
                return false;
            }
            if ($visibility === 'console' && empty($record['homepage_visible'])) {
                return false;
            }
            if ($visibility === 'public' && empty($record['public_visible'])) {
                return false;
            }
            if ($visibility === 'archived' && empty($record['archived'])) {
                return false;
            }
            if ($visibility === 'active' && !empty($record['archived'])) {
                return false;
            }
            return true;
        });
    }

    private function notice() {
        if (!empty($_GET['registry_updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Canonical Product Registry updated successfully.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        }
        if (!empty($_GET['registry_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('Registry operation failed: %s', 'sustainable-catalyst-feature-suggestions'), sanitize_text_field(wp_unslash($_GET['registry_error'])))) . '</p></div>';
        }
    }

    private function select_options($options, $selected, $blank_label = '') {
        $html = $blank_label !== '' ? '<option value="">' . esc_html($blank_label) . '</option>' : '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
        return $html;
    }

    private function action_form($action, $nonce, $product_id, $label, $class = 'button', $confirm = '') {
        $confirm_attribute = $confirm !== '' ? ' data-confirm="' . esc_attr($confirm) . '"' : '';
        return '<form class="scfs-registry-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' .
            '<input type="hidden" name="action" value="' . esc_attr($action) . '">' .
            '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">' .
            wp_nonce_field($nonce, '_wpnonce', true, false) .
            '<button class="' . esc_attr($class) . '" type="submit"' . $confirm_attribute . '>' . esc_html($label) . '</button></form>';
    }

    private function render_import_report() {
        $report = get_transient($this->import_transient_key());
        if (!is_array($report)) {
            return;
        }
        echo '<section class="scfs-registry-card" aria-labelledby="scfs-import-report-title">';
        echo '<h2 id="scfs-import-report-title">' . esc_html__('Import dry-run report', 'sustainable-catalyst-feature-suggestions') . '</h2>';
        echo '<p><strong>' . esc_html($report['valid'] ? __('Ready to apply', 'sustainable-catalyst-feature-suggestions') : __('Not safe to apply', 'sustainable-catalyst-feature-suggestions')) . '</strong> · ';
        echo esc_html(sprintf(__('%1$d products, %2$d errors, %3$d warnings', 'sustainable-catalyst-feature-suggestions'), $report['import_count'], $report['error_count'], $report['warning_count'])) . '</p>';
        echo '<dl class="scfs-registry-diff"><div><dt>' . esc_html__('Added', 'sustainable-catalyst-feature-suggestions') . '</dt><dd>' . esc_html(implode(', ', $report['added']) ?: '—') . '</dd></div>';
        echo '<div><dt>' . esc_html__('Changed', 'sustainable-catalyst-feature-suggestions') . '</dt><dd>' . esc_html(implode(', ', $report['changed']) ?: '—') . '</dd></div>';
        echo '<div><dt>' . esc_html__('Removed', 'sustainable-catalyst-feature-suggestions') . '</dt><dd>' . esc_html(implode(', ', $report['removed']) ?: '—') . '</dd></div></dl>';
        if (!empty($report['valid'])) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_registry_import_apply">';
            wp_nonce_field('scfs_registry_import_apply');
            submit_button(__('Apply validated import', 'sustainable-catalyst-feature-suggestions'), 'primary', 'submit', false);
            echo '</form>';
        }
        echo '</section>';
    }

    public function render_admin_page() {
        if (!$this->can_manage()) {
            wp_die('Forbidden');
        }
        $registry = $this->registry();
        $filtered = $this->filtered_registry($registry);
        $families = $this->registry_service()->families();
        $lifecycles = $this->registry_service()->lifecycle_states();
        $types = $this->registry_service()->product_types();
        $integrity = $this->registry_service()->integrity_report($registry);
        $alias_issues = array_values(array_filter($integrity['issues'], function ($issue) {
            return $issue['code'] === 'duplicate_public_alias';
        }));

        echo '<div class="wrap scfs-registry-admin">';
        echo '<h1>' . esc_html__('Canonical Product Registry Administration', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p class="description">' . esc_html__('Search, govern, order, merge, archive, import, export, and audit the authoritative Sustainable Catalyst product catalog.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        $this->notice();

        echo '<div class="scfs-registry-summary" role="status"><span><strong>' . esc_html(count($registry)) . '</strong> ' . esc_html__('products', 'sustainable-catalyst-feature-suggestions') . '</span>';
        echo '<span><strong>' . esc_html($integrity['error_count']) . '</strong> ' . esc_html__('errors', 'sustainable-catalyst-feature-suggestions') . '</span>';
        echo '<span><strong>' . esc_html($integrity['warning_count']) . '</strong> ' . esc_html__('warnings', 'sustainable-catalyst-feature-suggestions') . '</span>';
        echo '<span><strong>' . esc_html(count($alias_issues)) . '</strong> ' . esc_html__('alias collisions', 'sustainable-catalyst-feature-suggestions') . '</span></div>';

        echo '<form class="scfs-registry-filters" method="get">';
        echo '<input type="hidden" name="post_type" value="' . esc_attr(Sustainable_Catalyst_Feature_Suggestions::POST_TYPE) . '"><input type="hidden" name="page" value="' . esc_attr(self::ADMIN_SLUG) . '">';
        echo '<label><span>' . esc_html__('Search products', 'sustainable-catalyst-feature-suggestions') . '</span><input type="search" name="registry_search" value="' . esc_attr(isset($_GET['registry_search']) ? wp_unslash($_GET['registry_search']) : '') . '" placeholder="' . esc_attr__('Name, ID, plugin, repository…', 'sustainable-catalyst-feature-suggestions') . '"></label>';
        echo '<label><span>' . esc_html__('Family', 'sustainable-catalyst-feature-suggestions') . '</span><select name="registry_family">' . $this->select_options($families, isset($_GET['registry_family']) ? sanitize_key(wp_unslash($_GET['registry_family'])) : '', __('All families', 'sustainable-catalyst-feature-suggestions')) . '</select></label>';
        echo '<label><span>' . esc_html__('Lifecycle', 'sustainable-catalyst-feature-suggestions') . '</span><select name="registry_lifecycle">' . $this->select_options($lifecycles, isset($_GET['registry_lifecycle']) ? sanitize_key(wp_unslash($_GET['registry_lifecycle'])) : '', __('All lifecycle states', 'sustainable-catalyst-feature-suggestions')) . '</select></label>';
        echo '<label><span>' . esc_html__('Visibility', 'sustainable-catalyst-feature-suggestions') . '</span><select name="registry_visibility">' . $this->select_options(array('active' => __('Not archived', 'sustainable-catalyst-feature-suggestions'), 'console' => __('Release Console', 'sustainable-catalyst-feature-suggestions'), 'public' => __('Public registry', 'sustainable-catalyst-feature-suggestions'), 'archived' => __('Archived', 'sustainable-catalyst-feature-suggestions')), isset($_GET['registry_visibility']) ? sanitize_key(wp_unslash($_GET['registry_visibility'])) : '', __('All records', 'sustainable-catalyst-feature-suggestions')) . '</select></label>';
        submit_button(__('Filter registry', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false);
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_SLUG)) . '">' . esc_html__('Clear filters', 'sustainable-catalyst-feature-suggestions') . '</a></form>';

        echo '<div class="scfs-registry-layout"><main>';
        echo '<section class="scfs-registry-card"><div class="scfs-registry-card-header"><h2>' . esc_html__('Product catalog', 'sustainable-catalyst-feature-suggestions') . '</h2><span>' . esc_html(sprintf(__('%d shown', 'sustainable-catalyst-feature-suggestions'), count($filtered))) . '</span></div>';
        echo '<div class="scfs-registry-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Product', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Family', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Lifecycle', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Console', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Connections', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Actions', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        foreach ($filtered as $id => $record) {
            $edit_url = admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . SCFS_Product_Connection_Editor::ADMIN_SLUG . '&product=' . rawurlencode($id));
            echo '<tr><td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($record['name']) . '</a></strong><code>' . esc_html($id) . '</code>';
            if (!empty($record['archived'])) {
                echo '<span class="scfs-registry-badge is-archived">' . esc_html__('Archived', 'sustainable-catalyst-feature-suggestions') . '</span>';
            }
            echo '</td><td>' . esc_html($families[$record['family']] ?? $record['family']) . '</td><td>' . esc_html($lifecycles[$record['lifecycle_state']] ?? $record['lifecycle_state']) . '</td>';
            echo '<td>' . (!empty($record['homepage_visible']) ? esc_html($families[$record['console_screen']] ?? $record['console_screen']) . ' · ' . esc_html(absint($record['display_order'])) : '—') . '</td>';
            echo '<td><span>' . (!empty($record['plugin_file']) ? esc_html__('Plugin', 'sustainable-catalyst-feature-suggestions') : '—') . '</span> <span>' . (!empty($record['github_repository_url']) ? esc_html__('GitHub', 'sustainable-catalyst-feature-suggestions') : '—') . '</span></td><td><div class="scfs-registry-actions"><a class="button button-small" href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'sustainable-catalyst-feature-suggestions') . '</a>';
            if (!empty($record['archived'])) {
                echo $this->action_form('scfs_registry_restore_product', 'scfs_registry_restore_product', $id, __('Restore', 'sustainable-catalyst-feature-suggestions'), 'button button-small');
            } elseif ($id !== 'product-support-feedback') {
                echo $this->action_form('scfs_registry_archive_product', 'scfs_registry_archive_product', $id, __('Archive', 'sustainable-catalyst-feature-suggestions'), 'button button-small', __('Archive this product and remove it from public surfaces?', 'sustainable-catalyst-feature-suggestions'));
            }
            echo '</div></td></tr>';
        }
        if (!$filtered) {
            echo '<tr><td colspan="6">' . esc_html__('No products match the current filters.', 'sustainable-catalyst-feature-suggestions') . '</td></tr>';
        }
        echo '</tbody></table></div></section>';

        echo '<section class="scfs-registry-card" aria-labelledby="scfs-console-order-title"><h2 id="scfs-console-order-title">' . esc_html__('Release Console order', 'sustainable-catalyst-feature-suggestions') . '</h2><p>' . esc_html__('Drag products within a family. Saving assigns stable ten-point order increments and updates the console screen.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-scfs-registry-order-form><input type="hidden" name="action" value="scfs_registry_save_order">';
        wp_nonce_field('scfs_registry_save_order');
        echo '<div class="scfs-registry-order-grid">';
        foreach ($families as $family_id => $family_label) {
            echo '<section><h3>' . esc_html($family_label) . '</h3><ol class="scfs-registry-sortable" data-family="' . esc_attr($family_id) . '">';
            foreach ($registry as $id => $record) {
                if ($record['console_screen'] !== $family_id || empty($record['homepage_visible']) || !empty($record['archived'])) {
                    continue;
                }
                echo '<li draggable="true" data-product="' . esc_attr($id) . '"><span class="dashicons dashicons-menu" aria-hidden="true"></span><span>' . esc_html($record['short_name'] ?: $record['name']) . '</span><input type="hidden" name="order[' . esc_attr($family_id) . '][]" value="' . esc_attr($id) . '"></li>';
            }
            echo '</ol></section>';
        }
        echo '</div>';
        submit_button(__('Save console order', 'sustainable-catalyst-feature-suggestions'), 'primary', 'submit', false);
        echo '</form></section>';

        if ($alias_issues) {
            echo '<section class="scfs-registry-card is-warning" aria-labelledby="scfs-alias-review-title"><h2 id="scfs-alias-review-title">' . esc_html__('Alias collision review', 'sustainable-catalyst-feature-suggestions') . '</h2><p>' . esc_html__('These labels resolve to more than one canonical product and require administrator review.', 'sustainable-catalyst-feature-suggestions') . '</p><ul>';
            foreach ($alias_issues as $issue) {
                echo '<li><code>' . esc_html($issue['context']['alias'] ?? '') . '</code> — ' . esc_html(implode(', ', $issue['context']['products'] ?? array())) . '</li>';
            }
            echo '</ul></section>';
        }

        $this->render_import_report();
        echo '</main><aside>';

        echo '<section class="scfs-registry-card"><h2>' . esc_html__('Create canonical product', 'sustainable-catalyst-feature-suggestions') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_registry_create_product">';
        wp_nonce_field('scfs_registry_create_product');
        echo '<label>' . esc_html__('Canonical ID', 'sustainable-catalyst-feature-suggestions') . '<input required pattern="[a-z0-9-]+" name="canonical_id" placeholder="product-id"></label>';
        echo '<label>' . esc_html__('Product name', 'sustainable-catalyst-feature-suggestions') . '<input required name="name"></label>';
        echo '<label>' . esc_html__('Family', 'sustainable-catalyst-feature-suggestions') . '<select name="family">' . $this->select_options($families, 'foundation') . '</select></label>';
        echo '<label>' . esc_html__('Product type', 'sustainable-catalyst-feature-suggestions') . '<select name="product_type">' . $this->select_options($types, 'platform_module') . '</select></label>';
        submit_button(__('Create product', 'sustainable-catalyst-feature-suggestions'), 'primary', 'submit', false);
        echo '</form></section>';

        echo '<section class="scfs-registry-card"><h2>' . esc_html__('Merge duplicate products', 'sustainable-catalyst-feature-suggestions') . '</h2><p>' . esc_html__('The source becomes an archived superseded record. Its names and plugin identifiers become aliases on the target.', 'sustainable-catalyst-feature-suggestions') . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-confirm="' . esc_attr__('Merge these products? A backup will be created first.', 'sustainable-catalyst-feature-suggestions') . '"><input type="hidden" name="action" value="scfs_registry_merge_products">';
        wp_nonce_field('scfs_registry_merge_products');
        $product_options = array();
        foreach ($registry as $id => $record) {
            if (empty($record['archived'])) {
                $product_options[$id] = $record['name'] . ' (' . $id . ')';
            }
        }
        echo '<label>' . esc_html__('Source duplicate', 'sustainable-catalyst-feature-suggestions') . '<select required name="source_product">' . $this->select_options($product_options, '', __('Select source', 'sustainable-catalyst-feature-suggestions')) . '</select></label>';
        echo '<label>' . esc_html__('Authoritative target', 'sustainable-catalyst-feature-suggestions') . '<select required name="target_product">' . $this->select_options($product_options, '', __('Select target', 'sustainable-catalyst-feature-suggestions')) . '</select></label>';
        submit_button(__('Merge products', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false);
        echo '</form></section>';

        echo '<section class="scfs-registry-card"><h2>' . esc_html__('Import and export', 'sustainable-catalyst-feature-suggestions') . '</h2><p>' . esc_html__('Imports always require a dry run. Applying a valid import creates an automatic rollback backup.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_registry_import_dry_run">';
        wp_nonce_field('scfs_registry_import_dry_run');
        echo '<label>' . esc_html__('Registry JSON file', 'sustainable-catalyst-feature-suggestions') . '<input type="file" name="registry_file" accept="application/json,.json"></label>';
        echo '<label>' . esc_html__('Or paste JSON', 'sustainable-catalyst-feature-suggestions') . '<textarea name="registry_json" rows="5"></textarea></label>';
        submit_button(__('Run import dry run', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false);
        echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_registry_export_governed">';
        wp_nonce_field('scfs_registry_export_governed');
        submit_button(__('Export governed registry', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false);
        echo '</form></section>';

        echo '<section class="scfs-registry-card"><h2>' . esc_html__('Recent backups', 'sustainable-catalyst-feature-suggestions') . '</h2><ul class="scfs-registry-history">';
        foreach ($this->backups(5) as $backup) {
            echo '<li><strong>' . esc_html($backup['reason']) . '</strong><span>' . esc_html($backup['created_at']) . ' · ' . esc_html($backup['product_count']) . ' products</span><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-confirm="' . esc_attr__('Restore this registry backup?', 'sustainable-catalyst-feature-suggestions') . '"><input type="hidden" name="action" value="scfs_registry_restore_backup"><input type="hidden" name="backup_id" value="' . esc_attr($backup['backup_id']) . '">';
            wp_nonce_field('scfs_registry_restore_backup');
            echo '<button class="button button-small" type="submit">' . esc_html__('Restore', 'sustainable-catalyst-feature-suggestions') . '</button></form></li>';
        }
        echo '</ul></section>';

        echo '<section class="scfs-registry-card"><h2>' . esc_html__('Registry change history', 'sustainable-catalyst-feature-suggestions') . '</h2><ul class="scfs-registry-history">';
        foreach ($this->history(8) as $event) {
            $actor = $event['administrator']['display_name'] ?? $event['administrator']['user_login'] ?? __('Administrator', 'sustainable-catalyst-feature-suggestions');
            echo '<li><strong>' . esc_html($event['action']) . '</strong><span>' . esc_html($event['recorded_at']) . ' · ' . esc_html($actor) . '</span></li>';
        }
        echo '</ul></section>';
        echo '</aside></div></div>';
    }

    public function cli_history($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 25;
        WP_CLI::line(wp_json_encode($this->history($limit), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function cli_backups($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 10;
        $safe = array_map(function ($backup) {
            unset($backup['products']);
            return $backup;
        }, $this->backups($limit));
        WP_CLI::line(wp_json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
