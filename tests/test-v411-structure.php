<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-support-content-operations.php');
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$css = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/support-content-operations.css');
$js = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/support-content-operations.js');
$backend = file_get_contents($root . '/backend/app/support_content_operations.py');
$backend_main = file_get_contents($root . '/backend/app/main.py');
$checks = array(
    'plugin patch version' => strpos($main, 'Version: 7.8.1') !== false,
    'operations patch version' => strpos($class, "const VERSION = '5.5.0'") !== false,
    'operations schema 1.1' => strpos($class, "const SCHEMA_VERSION = '1.1'") !== false,
    'rollback batch registry' => strpos($class, 'ROLLBACK_OPTION') !== false,
    'manual rollback action' => strpos($class, 'rollback_support_import_action') !== false,
    'automatic rollback policy' => strpos($class, 'automatic_import_failure') !== false,
    'trash based rollback' => strpos($class, 'wp_trash_post') !== false,
    'malformed JSON detail' => strpos($class, 'json_last_error_msg') !== false,
    'UTF-8 and null validation' => strpos($class, "preg_match('//u'") !== false,
    'source checksum' => strpos($class, 'hash(\'sha256\', $content)') !== false,
    'normalized title duplicates' => strpos($class, 'normalize_title') !== false && strpos($class, 'same_source_and_title') !== false,
    'starter trash recovery' => strpos($class, 'wp_untrash_post') !== false,
    'product context mismatch validation' => strpos($class, 'context_product_mismatch') !== false,
    'operation jobs' => strpos($class, 'start_operation_job') !== false && strpos($class, 'finish_operation_job') !== false,
    'scheduled reliability sweep' => strpos($class, 'run_reliability_sweep') !== false,
    'cron deactivation' => strpos($main, 'SCFS_Support_Content_Operations::deactivate') !== false,
    'stable export ordering' => strpos($class, 'stable_ordering') !== false,
    'export checksum validation' => strpos($class, 'validate_export_payload') !== false,
    'manage options boundary' => strpos($class, "'manage_options'") !== false,
    'multisite boundary' => strpos($class, "'manage_network_options'") !== false,
    'accessible operation status' => strpos($class, 'aria-live="polite"') !== false,
    'accessible table region' => strpos($class, 'scfs-table-scroll') !== false,
    'focus visible CSS' => strpos($css, ':focus-visible') !== false,
    'reduced motion CSS' => strpos($css, 'prefers-reduced-motion') !== false,
    'rollback confirmation JS' => strpos($js, 'data-scfs-confirm') !== false,
    'backend source inspection' => strpos($backend, 'inspect_source_document') !== false,
    'backend recovery planning' => strpos($backend, 'plan_import_recovery') !== false,
    'backend export verification' => strpos($backend, 'verify_export_integrity') !== false,
    'backend inspection endpoint' => strpos($backend_main, '/v1/support-content/import/inspect') !== false,
    'backend recovery endpoint' => strpos($backend_main, '/v1/support-content/import/recovery') !== false,
    'backend integrity endpoint' => strpos($backend_main, '/v1/support-content/export/verify') !== false,
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
