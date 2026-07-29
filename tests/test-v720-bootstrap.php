<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php');
$checks = array(
    'plugin version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'class loaded' => strpos($main, 'class-scfs-installed-plugin-discovery.php') !== false,
    'class initialized' => strpos($main, 'SCFS_Installed_Plugin_Discovery::instance();') !== false,
    'activation' => strpos($main, 'SCFS_Installed_Plugin_Discovery::activate();') !== false,
    'class version' => strpos($class, "const VERSION = '7.8.1';") !== false,
    'schema' => strpos($class, 'scfs-installed-plugin-discovery/1.0') !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}
"); exit(1); }
}
echo "v7.8.1 installed plugin discovery bootstrap contract passed.
";
