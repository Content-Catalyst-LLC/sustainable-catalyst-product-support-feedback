<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-connected-product-support-platform.php');
$checks = array(
 'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
 'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
 'class required' => strpos($main, 'class-scfs-connected-product-support-platform.php') !== false,
 'class initialized' => strpos($main, 'SCFS_Connected_Product_Support_Platform::instance();') !== false,
 'activation hook' => strpos($main, 'SCFS_Connected_Product_Support_Platform::activate();') !== false,
 'deactivation hook' => strpos($main, 'SCFS_Connected_Product_Support_Platform::deactivate();') !== false,
 'class declaration' => strpos($class, 'final class SCFS_Connected_Product_Support_Platform') !== false,
 'class version' => strpos($class, "const VERSION = '7.8.1';") !== false,
 'platform schema' => strpos($class, "const SCHEMA = 'scfs-connected-product-support-feedback-platform/1.0';") !== false,
 'journey schema' => strpos($class, "const JOURNEY_SCHEMA = 'scfs-connected-support-journey/1.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v6.3.0 bootstrap contract passed (' . count($checks) . " checks).\n";
