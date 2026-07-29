<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($plugin . '/includes/class-scfs-editorial-governance.php');
$backend = file_get_contents($root . '/backend/app/editorial_governance.py');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'main version constant' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'editorial include' => strpos($main, "class-scfs-editorial-governance.php") !== false,
    'editorial instance' => strpos($main, 'SCFS_Editorial_Governance::instance();') !== false,
    'editorial activation' => strpos($main, 'SCFS_Editorial_Governance::activate();') !== false,
    'editorial deactivation' => strpos($main, 'SCFS_Editorial_Governance::deactivate();') !== false,
    'governance class version' => strpos($class, "const VERSION = '5.5.0';") !== false,
    'workflow transition function' => strpos($class, 'function transition_record') !== false,
    'publication gate' => strpos($class, 'function enforce_publication_gate') !== false,
    'standards assessment' => strpos($class, 'function standards_assessment') !== false,
    'editorial notes' => strpos($class, 'function add_editorial_note') !== false,
    'audit history' => strpos($class, 'function append_audit') !== false,
    'governance dashboard' => strpos($class, 'function render_admin_page') !== false,
    'scheduled governance' => strpos($class, 'function run_scheduled_governance') !== false,
    'REST transition endpoint' => strpos($class, '/editorial-governance/record/(?P<id>\\d+)/transition') !== false,
    'editorial CSS exists' => file_exists($plugin . '/assets/editorial-governance.css'),
    'editorial JS exists' => file_exists($plugin . '/assets/editorial-governance.js'),
    'backend module version' => strpos($backend, 'version: str = "5.1.0"') !== false,
    'backend transition evaluator' => strpos($backend, 'def evaluate_editorial_transition') !== false,
    'backend standards scorer' => strpos($backend, 'def score_documentation_standards') !== false,
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'governance documentation' => file_exists($root . '/docs/documentation-workflow-editorial-governance.md'),
    'transition example' => file_exists($root . '/examples/editorial-transition-decision.json'),
    'standards example' => file_exists($root . '/examples/documentation-standards-score.json'),
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
