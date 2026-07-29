<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-support-content-governance.php');
$editorial = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-editorial-governance.php');
$content = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-support-content-operations.php');
$checks = array(
    'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'governance class required' => strpos($main, 'class-scfs-support-content-governance.php') !== false,
    'governance class initialized' => strpos($main, 'SCFS_Support_Content_Governance::instance();') !== false,
    'governance activation retained' => strpos($main, 'SCFS_Support_Content_Governance::activate();') !== false,
    'governance deactivation retained' => strpos($main, 'SCFS_Support_Content_Governance::deactivate();') !== false,
    'class declaration' => strpos($class, 'final class SCFS_Support_Content_Governance') !== false,
    'class version' => strpos($class, "const VERSION = '5.5.0';") !== false,
    'schema contract' => strpos($class, "const SCHEMA = 'scfs-support-content-governance/1.0';") !== false,
    'editorial governance coordinated version' => strpos($editorial, "const VERSION = '5.5.0';") !== false,
    'content operations coordinated version' => strpos($content, "const VERSION = '5.5.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.5.0 content governance bootstrap contract passed (' . count($checks) . " checks).\n";
