<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-feedback-product-signals.php');
$checks = array(
    'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'signal class required' => strpos($main, 'class-scfs-feedback-product-signals.php') !== false,
    'signal class initialized' => strpos($main, 'SCFS_Feedback_Product_Signals::instance();') !== false,
    'activation hook retained' => strpos($main, 'SCFS_Feedback_Product_Signals::activate();') !== false,
    'deactivation hook retained' => strpos($main, 'SCFS_Feedback_Product_Signals::deactivate();') !== false,
    'class declaration' => strpos($class, 'final class SCFS_Feedback_Product_Signals') !== false,
    'class version' => strpos($class, "const VERSION = '5.6.0';") !== false,
    'schema contract' => strpos($class, "const SCHEMA = 'scfs-feedback-product-signals/1.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.6.0 feedback product signals bootstrap contract passed (' . count($checks) . " checks).\n";
