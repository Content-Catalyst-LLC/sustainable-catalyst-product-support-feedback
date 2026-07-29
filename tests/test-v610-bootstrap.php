<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-help-desk-case-foundation.php');
$checks = array(
 'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
 'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
 'class required' => strpos($main, 'class-scfs-help-desk-case-foundation.php') !== false,
 'class initialized' => strpos($main, 'SCFS_Help_Desk_Case_Foundation::instance();') !== false,
 'activation hook' => strpos($main, 'SCFS_Help_Desk_Case_Foundation::activate();') !== false,
 'deactivation hook' => strpos($main, 'SCFS_Help_Desk_Case_Foundation::deactivate();') !== false,
 'class declaration' => strpos($class, 'final class SCFS_Help_Desk_Case_Foundation') !== false,
 'case schema' => strpos($class, "const SCHEMA = 'scfs-help-desk-case/1.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v6.3.0 Help Desk bootstrap contract passed (' . count($checks) . " checks).\n";
