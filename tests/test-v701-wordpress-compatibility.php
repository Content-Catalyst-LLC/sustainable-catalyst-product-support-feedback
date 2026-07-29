<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$checks = array(
    'public plugin name preserved' => strpos($main, 'Plugin Name: Sustainable Catalyst Product Support and Feedback Platform') !== false,
    'plugin version bumped' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version bumped' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'text domain preserved' => strpos($main, 'Text Domain: sustainable-catalyst-feature-suggestions') !== false,
    'legacy PHP class preserved' => strpos($main, 'class Sustainable_Catalyst_Feature_Suggestions') !== false,
    'post type preserved' => strpos($main, "const POST_TYPE = 'sc_feature_suggest';") !== false,
    'REST namespace preserved' => strpos($main, "const REST_NAMESPACE = 'scfs/v1';") !== false,
    'settings key preserved' => strpos($main, "const OPTION_KEY = 'scfs_settings';") !== false,
);
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$ok) exit(1);
}
echo "v7.8.1 WordPress compatibility contract passed.\n";
