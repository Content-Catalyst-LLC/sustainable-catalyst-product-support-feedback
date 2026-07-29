<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$discovery = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php');
$registry = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
$checks = array(
    'plugin version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'discovery version' => strpos($discovery, "const VERSION = '7.8.1';") !== false,
    'registry version' => strpos($registry, "const VERSION = '7.8.1';") !== false,
    'discovery diagnostics schema' => strpos($discovery, 'scfs-plugin-discovery-diagnostics/1.0') !== false,
    'legacy WordPress slug preserved' => strpos($main, 'Text Domain: sustainable-catalyst-feature-suggestions') !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 bootstrap contract passed.\n";
