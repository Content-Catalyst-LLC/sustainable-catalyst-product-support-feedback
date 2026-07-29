<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($plugin . '/includes/class-scfs-support-reliability-center.php');
$backend = file_get_contents($root . '/backend/app/support_reliability.py');
$backend_main = file_get_contents($root . '/backend/app/main.py');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'main version constant' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'reliability class include' => strpos($main, 'class-scfs-support-reliability-center.php') !== false,
    'reliability class instance' => strpos($main, 'SCFS_Support_Reliability_Center::instance();') !== false,
    'reliability activation' => strpos($main, 'SCFS_Support_Reliability_Center::activate();') !== false,
    'reliability deactivation' => strpos($main, 'SCFS_Support_Reliability_Center::deactivate();') !== false,
    'reliability class version' => strpos($class, "const VERSION = '5.1.0';") !== false,
    'product scoring' => strpos($class, 'function calculate_reliability_score') !== false,
    'resolution metrics' => strpos($class, 'function resolution_metrics') !== false,
    'documentation metrics' => strpos($class, 'function documentation_metrics') !== false,
    'issue recurrence' => strpos($class, 'function known_issue_metrics') !== false,
    'release aggregation' => strpos($class, 'function release_metrics') !== false,
    'repository health' => strpos($class, 'function repository_metrics') !== false,
    'gap prioritization' => strpos($class, 'function gap_metrics') !== false,
    'unresolved clustering' => strpos($class, 'function unresolved_clusters') !== false,
    'scheduled snapshots' => strpos($class, 'function run_daily_snapshot') !== false,
    'trend records' => strpos($class, 'function trend_record') !== false,
    'checksum report' => strpos($class, 'records_checksum') !== false,
    'reliability CSS exists' => file_exists($plugin . '/assets/support-reliability-center.css'),
    'reliability JS exists' => file_exists($plugin . '/assets/support-reliability-center.js'),
    'platform command center module' => strpos(file_get_contents($plugin . '/includes/class-scfs-platform-governance.php'), "'support_reliability' =>") !== false,
    'platform snapshot includes reliability' => strpos(file_get_contents($plugin . '/includes/class-scfs-platform-governance.php'), "'support_reliability_dashboard'=>") !== false,
    'backend scorer' => strpos($backend, 'def score_product_reliability') !== false,
    'backend trend summarizer' => strpos($backend, 'def summarize_reliability_trend') !== false,
    'backend cluster prioritizer' => strpos($backend, 'def prioritize_unresolved_cluster') !== false,
    'backend integrity verifier' => strpos($backend, 'def verify_reliability_report') !== false,
    'backend capabilities endpoint' => strpos($backend_main, "/v1/support-reliability/capabilities") !== false,
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'reliability documentation' => file_exists($root . '/docs/support-analytics-product-reliability-center.md'),
    'score example' => file_exists($root . '/examples/product-reliability-score.json'),
    'dashboard example' => file_exists($root . '/examples/support-reliability-dashboard.json'),
    'cluster example' => file_exists($root . '/examples/unresolved-query-cluster.json'),
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
