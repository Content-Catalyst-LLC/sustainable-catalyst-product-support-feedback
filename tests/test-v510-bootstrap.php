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
$integrated = SCFS_Integrated_Knowledge_Base::instance();
$reflection = new ReflectionClass($integrated);
$property = $reflection->getProperty('importing_documentation');
$property->setAccessible(true);
$property->setValue($integrated, true);
$allowed_support_article = $integrated->allow_integrated_publication(false, array('post_type' => SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE), array());
$allowed_other_record = $integrated->allow_integrated_publication(false, array('post_type' => Sustainable_Catalyst_Feature_Suggestions::POST_TYPE), array());
$property->setValue($integrated, false);
$allowed_outside_import = $integrated->allow_integrated_publication(false, array('post_type' => SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE), array());
$checks = array(
    'main version is 5.1.0' => Sustainable_Catalyst_Feature_Suggestions::VERSION === '7.8.1',
    'integrated Knowledge Base class loaded' => class_exists('SCFS_Integrated_Knowledge_Base'),
    'integrated Knowledge Base version' => SCFS_Integrated_Knowledge_Base::VERSION === '5.4.0',
    'Knowledge Base foundation retained' => class_exists('SCFS_Knowledge_Base_Foundation'),
    'documentation intelligence retained' => class_exists('SCFS_Documentation_Feature_Intelligence'),
    'editorial governance retained' => class_exists('SCFS_Editorial_Governance'),
    'system publication allowed for integrated article during import' => $allowed_support_article === true,
    'system publication denied for other post types' => $allowed_other_record === false,
    'system publication denied outside import' => $allowed_outside_import === false,
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
