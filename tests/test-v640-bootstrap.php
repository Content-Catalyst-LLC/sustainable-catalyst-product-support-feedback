<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-help-desk-service-levels.php');
$checks = array(
 'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
 'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
 'class required' => strpos($main, 'class-scfs-help-desk-service-levels.php') !== false,
 'class initialized' => strpos($main, 'SCFS_Help_Desk_Service_Levels::instance();') !== false,
 'activation hook' => strpos($main, 'SCFS_Help_Desk_Service_Levels::activate();') !== false,
 'deactivation hook' => strpos($main, 'SCFS_Help_Desk_Service_Levels::deactivate();') !== false,
 'class declaration' => strpos($class, 'final class SCFS_Help_Desk_Service_Levels') !== false,
 'class version' => strpos($class, "const VERSION = '7.8.1';") !== false,
 'schema constant' => strpos($class, "const SCHEMA = 'scfs-help-desk-service-levels/1.0';") !== false,
 'db version' => strpos($class, "const DB_VERSION = '1.3.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v6.4.0 Service Levels bootstrap contract passed (' . count($checks) . " checks).\n";
