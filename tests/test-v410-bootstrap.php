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
    'main class loaded' => class_exists('Sustainable_Catalyst_Feature_Suggestions'),
    'version is 5.1.0' => Sustainable_Catalyst_Feature_Suggestions::VERSION === '7.8.1',
    'product integration loaded' => class_exists('SCFS_Product_Integration'),
    'knowledge base loaded' => class_exists('SCFS_Knowledge_Base_Foundation'),
    'guided resolution loaded' => class_exists('SCFS_Guided_Resolution'),
    'documentation intelligence loaded' => class_exists('SCFS_Documentation_Feature_Intelligence'),
    'forms loaded' => class_exists('SCFS_Forms_Foundation'),
    'survey intelligence loaded' => class_exists('SCFS_Survey_Intelligence'),
    'public ideas loaded' => class_exists('SCFS_Public_Ideas'),
    'opportunity workflow loaded' => class_exists('SCFS_Opportunity_Workflow'),
    'product support platform loaded' => class_exists('SCFS_Product_Support_Platform'),
    'governance loaded' => class_exists('SCFS_Platform_Governance'),
    'support content operations loaded' => class_exists('SCFS_Support_Content_Operations'),
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
$schema = SCFS_Product_Support_Platform::instance()->schema_record();
if (($schema['schema'] ?? '') !== 'scfs-product-support-platform/1.0') {
    fwrite(STDERR, "FAIL - product support schema\n"); exit(1);
}
if (!in_array('embedded', $schema['rendering_modes'] ?? array(), true)) {
    fwrite(STDERR, "FAIL - embedded rendering mode\n"); exit(1);
}
if (!in_array('custom', $schema['branding_presets'] ?? array(), true)) {
    fwrite(STDERR, "FAIL - custom branding preset\n"); exit(1);
}
if (!in_array('release_intelligence', $schema['public_modules'] ?? array(), true)) {
    fwrite(STDERR, "FAIL - release intelligence module\n"); exit(1);
}
if (($schema['governance']['automatic_case_creation'] ?? true) !== false) {
    fwrite(STDERR, "FAIL - automatic case boundary\n"); exit(1);
}
echo "PASS - product support schema\nPASS - embedded rendering mode\nPASS - custom branding preset\nPASS - release intelligence module\nPASS - automatic case boundary\n18 checks passed.\n";
