<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($plugin . '/includes/class-scfs-support-article-integrity.php');
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'integrity class exists' => strpos($class, 'final class SCFS_Support_Article_Integrity') !== false,
    'integrity version' => strpos($class, "const VERSION = '5.2.8';") !== false,
    'integrity class required' => strpos($main, "includes/class-scfs-support-article-integrity.php") !== false,
    'integrity instance bootstrapped' => strpos($main, 'SCFS_Support_Article_Integrity::instance();') !== false,
    'integrity activation hook' => strpos($main, 'SCFS_Support_Article_Integrity::activate();') !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.2.8 Support Article integrity bootstrap contract passed (' . count($checks) . " checks).\n";
