<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-help-desk-secure-evidence.php');
$checks = array(
 'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
 'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
 'class required' => strpos($main, 'class-scfs-help-desk-secure-evidence.php') !== false,
 'class initialized' => strpos($main, 'SCFS_Help_Desk_Secure_Evidence::instance();') !== false,
 'activation hook' => strpos($main, 'SCFS_Help_Desk_Secure_Evidence::activate();') !== false,
 'deactivation hook' => strpos($main, 'SCFS_Help_Desk_Secure_Evidence::deactivate();') !== false,
 'class declaration' => strpos($class, 'final class SCFS_Help_Desk_Secure_Evidence') !== false,
 'schema constant' => strpos($class, "const SCHEMA = 'scfs-help-desk-secure-evidence/1.0';") !== false,
 'db version' => strpos($class, "const DB_VERSION = '1.4.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v6.12.0 Secure Evidence bootstrap contract passed (' . count($checks) . " checks).\n";
