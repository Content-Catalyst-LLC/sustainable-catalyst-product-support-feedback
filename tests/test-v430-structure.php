<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($plugin . '/includes/class-scfs-repository-release-sync.php');
$backend = file_get_contents($root . '/backend/app/repository_release_sync.py');
$backend_main = file_get_contents($root . '/backend/app/main.py');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'main version constant' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'repository synchronization include' => strpos($main, 'class-scfs-repository-release-sync.php') !== false,
    'repository synchronization instance' => strpos($main, 'SCFS_Repository_Release_Synchronization::instance();') !== false,
    'repository synchronization activation' => strpos($main, 'SCFS_Repository_Release_Synchronization::activate();') !== false,
    'repository synchronization deactivation' => strpos($main, 'SCFS_Repository_Release_Synchronization::deactivate();') !== false,
    'sync class version' => strpos($class, "const VERSION = '5.1.0';") !== false,
    'mapping support' => strpos($class, 'function save_mapping') !== false,
    'GitHub URL parser' => strpos($class, 'function parse_github_repository_url') !== false,
    'repository snapshot' => strpos($class, 'function repository_snapshot') !== false,
    'candidate collection' => strpos($class, 'function collect_candidates') !== false,
    'candidate decision' => strpos($class, 'function evaluate_candidate') !== false,
    'review copy protection' => strpos($class, 'create_review_copy') !== false,
    'drift report' => strpos($class, 'function drift_report') !== false,
    'broken link checks' => strpos($class, 'function check_product_links') !== false,
    'bounded sync log' => strpos($class, 'function append_log') !== false,
    'scheduled inspection' => strpos($class, 'function run_scheduled_sync') !== false,
    'signed webhook verification' => strpos($class, 'function verify_github_signature') !== false,
    'webhook does not publish' => strpos($class, "'automatic_publication' => false") !== false,
    'repository sync CSS exists' => file_exists($plugin . '/assets/repository-release-sync.css'),
    'repository sync JS exists' => file_exists($plugin . '/assets/repository-release-sync.js'),
    'backend evaluator' => strpos($backend, 'def evaluate_repository_candidate') !== false,
    'backend drift evaluator' => strpos($backend, 'def evaluate_repository_drift') !== false,
    'backend release planner' => strpos($backend, 'def plan_release_sync') !== false,
    'backend link summary' => strpos($backend, 'def summarize_link_health') !== false,
    'backend capabilities endpoint' => strpos($backend_main, "/v1/repository-sync/capabilities") !== false,
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'repository sync documentation' => file_exists($root . '/docs/repository-release-synchronization.md'),
    'sync decision example' => file_exists($root . '/examples/repository-sync-decision.json'),
    'drift example' => file_exists($root . '/examples/repository-drift-report.json'),
    'release plan example' => file_exists($root . '/examples/repository-release-sync-plan.json'),
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
