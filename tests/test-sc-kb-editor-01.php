<?php
/**
 * SC-KB-EDITOR-01 focused regression checks.
 * Run: php tests/test-sc-kb-editor-01.php
 * This is a unit fixture, not an authenticated WordPress integration test.
 */
define('ABSPATH', __DIR__ . '/');

class WP_Error {
    private $code;
    public function __construct($code) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class SCFS_Knowledge_Base_Foundation {
    const ARTICLE_POST_TYPE = 'sc_support_article';
    const ISSUE_POST_TYPE = 'sc_known_issue';
}
$GLOBALS['granted_capabilities'] = array('edit_others_posts', 'publish_posts');
function add_action() { return true; }
function add_filter() { return true; }
function apply_filters($name, $value) { return $value; }
function __($text) { return $text; }
function current_user_can($cap) { return in_array($cap, $GLOBALS['granted_capabilities'], true); }
function get_post($id) { return (object) array('ID' => $id, 'post_type' => $id === 99 ? 'post' : 'sc_support_article', 'post_status' => 'pending'); }
function get_option($key, $default = array()) { return $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function absint($value) { return abs((int) $value); }
function get_post_meta($id, $key, $single = true) { return ''; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim((string) $value); }

require dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-editorial-governance.php';
$governance = SCFS_Editorial_Governance::instance();
$actions = $governance->support_article_bulk_actions(array('edit' => 'Edit'));

$checks = array(
    'authorized bulk approve visible' => isset($actions['scfs_editorial_bulk_approve']),
    'authorized bulk pending visible' => isset($actions['scfs_editorial_bulk_pending']),
    'support editorial approval does not require reviewer assignment' => $governance->transition_gate(42, 'approved') === true,
    'non-support approval still uses governance checks' => $governance->transition_gate(99, 'approved') instanceof WP_Error,
    'publication still uses governance checks' => $governance->transition_gate(42, 'published') instanceof WP_Error,
);
$GLOBALS['granted_capabilities'] = array('edit_others_posts');
$restricted = $governance->support_article_bulk_actions(array());
$checks['nonapprover cannot bulk approve'] = !isset($restricted['scfs_editorial_bulk_approve']);
$checks['editor can mark pending'] = isset($restricted['scfs_editorial_bulk_pending']);
$GLOBALS['granted_capabilities'] = array();
$restricted = $governance->support_article_bulk_actions(array());
$checks['unauthorized roles get no actions'] = !isset($restricted['scfs_editorial_bulk_pending']) && !isset($restricted['scfs_editorial_bulk_approve']);

foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
    if (!$passed) { exit(1); }
}
echo count($checks) . ' checks passed.' . PHP_EOL;
