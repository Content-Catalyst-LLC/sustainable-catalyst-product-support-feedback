<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($plugin . '/includes/class-scfs-connected-support-operations.php');
$governance = file_get_contents($plugin . '/includes/class-scfs-platform-governance.php');
$backend = file_get_contents($root . '/backend/app/connected_support_operations.py');
$backend_main = file_get_contents($root . '/backend/app/main.py');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'main version constant' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'connected operations include' => strpos($main, 'class-scfs-connected-support-operations.php') !== false,
    'connected operations instance' => strpos($main, 'SCFS_Connected_Support_Operations::instance();') !== false,
    'connected operations activation' => strpos($main, 'SCFS_Connected_Support_Operations::activate();') !== false,
    'connected operations deactivation' => strpos($main, 'SCFS_Connected_Support_Operations::deactivate();') !== false,
    'connected operations class version' => strpos($class, "const VERSION = '5.1.0';") !== false,
    'unified dashboard' => strpos($class, 'function dashboard_record') !== false,
    'product dossiers' => strpos($class, 'function product_record') !== false,
    'governed action queue' => strpos($class, 'function enqueue_action') !== false,
    'action execution' => strpos($class, 'function execute_action') !== false,
    'daily snapshots' => strpos($class, 'function run_daily_snapshot') !== false,
    'report integrity' => strpos($class, "'algorithm' => 'sha256'") !== false,
    'platform governance module' => strpos($governance, "'connected_support_operations' =>") !== false,
    'platform snapshot includes connected operations' => strpos($governance, "'connected_operations_dashboard'=>") !== false,
    'connected operations CSS exists' => file_exists($plugin . '/assets/connected-support-operations.css'),
    'connected operations JS exists' => file_exists($plugin . '/assets/connected-support-operations.js'),
    'backend readiness scoring' => strpos($backend, 'def score_connected_operations') !== false,
    'backend action planning' => strpos($backend, 'def plan_connected_action') !== false,
    'backend report verification' => strpos($backend, 'def verify_connected_operations_report') !== false,
    'backend capabilities endpoint' => strpos($backend_main, "/v1/connected-operations/capabilities") !== false,
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'connected operations documentation' => file_exists($root . '/docs/connected-product-support-operations-platform.md'),
    'connected dashboard example' => file_exists($root . '/examples/connected-operations-dashboard.json'),
    'connected action example' => file_exists($root . '/examples/connected-operations-action-plan.json'),
    'connected report example' => file_exists($root . '/examples/connected-operations-report-integrity.json'),
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
