<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$checks = array(
    'option storage' => strpos($class, "const OPTION_KEY = 'scfs_canonical_product_registry';") !== false,
    'nonautoload seed' => strpos($class, 'add_option(self::OPTION_KEY, $registry, \'\', false)') !== false,
    'legacy plugin directory' => is_dir($root . '/wordpress/sustainable-catalyst-feature-suggestions'),
    'public plugin name' => strpos($main, 'Plugin Name: Sustainable Catalyst Product Support and Feedback Platform') !== false,
    'text domain' => strpos($main, 'Text Domain: sustainable-catalyst-feature-suggestions') !== false,
    'REST namespace' => strpos($main, "const REST_NAMESPACE = 'scfs/v1';") !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 registry storage and WordPress compatibility contract passed.\n";
