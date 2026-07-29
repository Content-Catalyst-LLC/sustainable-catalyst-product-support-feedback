<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-support-analytics-documentation-effectiveness.php');
$checks = array(
    'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'analytics class required' => strpos($main, 'class-scfs-support-analytics-documentation-effectiveness.php') !== false,
    'analytics class initialized' => strpos($main, 'SCFS_Support_Analytics_Documentation_Effectiveness::instance();') !== false,
    'activation hook' => strpos($main, 'SCFS_Support_Analytics_Documentation_Effectiveness::activate();') !== false,
    'deactivation hook' => strpos($main, 'SCFS_Support_Analytics_Documentation_Effectiveness::deactivate();') !== false,
    'class declaration' => strpos($class, 'final class SCFS_Support_Analytics_Documentation_Effectiveness') !== false,
    'class version' => strpos($class, "const VERSION = '5.7.0';") !== false,
    'schema contract' => strpos($class, "const SCHEMA = 'scfs-support-analytics-documentation-effectiveness/1.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.7.0 Support Analytics bootstrap contract passed (' . count($checks) . " checks).\n";
