<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-public-support-integrations.php');
$checks = array(
 'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
 'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
 'class required' => strpos($main, 'class-scfs-public-support-integrations.php') !== false,
 'class initialized' => strpos($main, 'SCFS_Public_Support_Integrations::instance();') !== false,
 'activation hook' => strpos($main, 'SCFS_Public_Support_Integrations::activate();') !== false,
 'deactivation hook' => strpos($main, 'SCFS_Public_Support_Integrations::deactivate();') !== false,
 'class declaration' => strpos($class, 'final class SCFS_Public_Support_Integrations') !== false,
 'class version' => strpos($class, "const VERSION = '5.9.0';") !== false,
 'schema contract' => strpos($class, "const SCHEMA = 'scfs-public-support-integration/1.0';") !== false,
 'embed contract' => strpos($class, "const EMBED_SCHEMA = 'scfs-support-embed/1.0';") !== false,
 'institution contract' => strpos($class, "const INSTITUTION_SCHEMA = 'scfs-institutional-support-integration/1.0';") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.9.0 bootstrap contract passed (' . count($checks) . " checks).\n";
