<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-cross-product-support-graph.php');
$checks = array(
    'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'graph class required' => strpos($main, 'class-scfs-cross-product-support-graph.php') !== false,
    'graph class initialized' => strpos($main, 'SCFS_Cross_Product_Support_Graph::instance();') !== false,
    'activation hook' => strpos($main, 'SCFS_Cross_Product_Support_Graph::activate();') !== false,
    'deactivation hook' => strpos($main, 'SCFS_Cross_Product_Support_Graph::deactivate();') !== false,
    'class declaration' => strpos($class, 'final class SCFS_Cross_Product_Support_Graph') !== false,
    'class version' => strpos($class, "const VERSION = '5.8.0';") !== false,
    'schema contract' => strpos($class, "const SCHEMA = 'scfs-cross-product-support-graph/1.0';") !== false,
    'handoff contract' => strpos($class, "const HANDOFF_SCHEMA = 'scfs-platform-support-handoff/1.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.8.0 Support Graph bootstrap contract passed (' . count($checks) . " checks).\n";
