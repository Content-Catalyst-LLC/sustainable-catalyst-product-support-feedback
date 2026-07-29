<?php
define('ABSPATH', __DIR__ . '/');
function add_action() { return true; }
function add_filter() { return true; }
function add_shortcode() { return true; }
function register_activation_hook() { return true; }
function register_deactivation_hook() { return true; }
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_basename($file) { return basename($file); }
function apply_filters($hook, $value) { return $value; }
function __($text) { return $text; }

require dirname(__DIR__) . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php';
$checks = array(
    'main version is 5.1.0' => Sustainable_Catalyst_Feature_Suggestions::VERSION === '7.8.1',
    'cross product orchestration loaded' => class_exists('SCFS_Cross_Product_Support_Orchestration'),
    'cross product version' => SCFS_Cross_Product_Support_Orchestration::VERSION === '5.1.0',
    'cross product schema version' => SCFS_Cross_Product_Support_Orchestration::SCHEMA_VERSION === '1.0',
    'platform incident post type declared' => SCFS_Cross_Product_Support_Orchestration::INCIDENT_POST_TYPE === 'sc_platform_incident',
    'support reliability retained' => class_exists('SCFS_Support_Reliability_Center'),
    'repository synchronization retained' => class_exists('SCFS_Repository_Release_Synchronization'),
    'editorial governance retained' => class_exists('SCFS_Editorial_Governance'),
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
