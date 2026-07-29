<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-support-discovery.php');
$checks = array(
    'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'discovery bootstrap' => strpos($main, "class-scfs-support-discovery.php") !== false,
    'discovery instance' => strpos($main, 'SCFS_Support_Discovery::instance();') !== false,
    'discovery class' => strpos($class, 'final class SCFS_Support_Discovery') !== false,
    'discovery schema' => strpos($class, "const SCHEMA = 'scfs-support-discovery/1.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.2.9 discovery bootstrap contract passed (' . count($checks) . " checks).\n";
