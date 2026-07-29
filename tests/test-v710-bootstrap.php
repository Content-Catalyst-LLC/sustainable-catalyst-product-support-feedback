<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
$checks = array(
    'plugin version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'class loaded' => strpos($main, 'class-scfs-canonical-product-registry.php') !== false,
    'class initialized' => strpos($main, 'SCFS_Canonical_Product_Registry::instance();') !== false,
    'activation' => strpos($main, 'SCFS_Canonical_Product_Registry::activate();') !== false,
    'class version' => strpos($class, "const VERSION = '7.8.1';") !== false,
    'schema' => strpos($class, 'scfs-canonical-product-registry/2.1') !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 canonical product registry bootstrap contract passed.\n";
