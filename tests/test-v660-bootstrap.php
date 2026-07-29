<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-help-desk-knowledge-assisted-resolution.php');
$checks = array(
    'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'class loaded' => strpos($main, "class-scfs-help-desk-knowledge-assisted-resolution.php") !== false,
    'class initialized' => strpos($main, 'SCFS_Help_Desk_Knowledge_Assisted_Resolution::instance();') !== false,
    'class version' => strpos($class, "const VERSION = '7.8.1';") !== false,
    'schema version' => strpos($class, "scfs-help-desk-knowledge-resolution/1.0") !== false,
);
foreach ($checks as $label => $ok) { if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } }
echo "v6.12.0 bootstrap contract passed.\n";
