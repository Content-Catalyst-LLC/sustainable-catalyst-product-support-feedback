<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-unified-support-search.php');
$checks = array(
    'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'unified class file required' => strpos($main, 'class-scfs-unified-support-search.php') !== false,
    'unified class initialized' => strpos($main, 'SCFS_Unified_Support_Search::instance();') !== false,
    'unified class declaration' => strpos($class, 'final class SCFS_Unified_Support_Search') !== false,
    'unified class version' => strpos($class, "const VERSION = '5.4.0';") !== false,
    'unified schema' => strpos($class, "const SCHEMA = 'scfs-unified-support-search/1.0';") !== false,
    'journey schema' => strpos($class, "const JOURNEY_SCHEMA = 'scfs-support-resolution-journey/1.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.4.0 unified support bootstrap contract passed (' . count($checks) . " checks).\n";
