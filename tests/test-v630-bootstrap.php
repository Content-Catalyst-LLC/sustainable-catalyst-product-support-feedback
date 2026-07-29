<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-help-desk-customer-portal.php');
$checks = array(
 'plugin header version' => strpos($main, 'Version: 7.8.1') !== false,
 'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
 'class required' => strpos($main, 'class-scfs-help-desk-customer-portal.php') !== false,
 'class initialized' => strpos($main, 'SCFS_Help_Desk_Customer_Portal::instance();') !== false,
 'activation hook' => strpos($main, 'SCFS_Help_Desk_Customer_Portal::activate();') !== false,
 'deactivation hook' => strpos($main, 'SCFS_Help_Desk_Customer_Portal::deactivate();') !== false,
 'class declaration' => strpos($class, 'final class SCFS_Help_Desk_Customer_Portal') !== false,
 'portal schema' => strpos($class, "const SCHEMA = 'scfs-help-desk-customer-portal/1.0';") !== false,
 'primary shortcode' => strpos($class, "const SHORTCODE = 'scfs_help_desk_customer_portal';") !== false,
 'shortcode alias' => strpos($class, "const SHORTCODE_ALIAS = 'scfs_customer_support_portal';") !== false,
 'managed portal page' => strpos($class, 'ensure_portal_page') !== false && strpos($class, "'post_name' => 'cases'") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v6.3.0 Customer Portal bootstrap contract passed (' . count($checks) . " checks).\n";
