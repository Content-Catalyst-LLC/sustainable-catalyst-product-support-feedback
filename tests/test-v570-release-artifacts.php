<?php
$root = dirname(__DIR__);
$required = array(
    'RELEASE_NOTES_5.7.0.md',
    'SUSTAINABLE_CATALYST_PRODUCT_SUPPORT_AND_FEEDBACK_PLATFORM_V5.7.0_RELEASE_NOTES.md',
    'release-manifest-v5.7.0.json',
    'feature_suggestions_manifest.json',
    'docs/support-analytics-documentation-effectiveness-v5.7.0.md',
    'schemas/scfs-support-analytics-documentation-effectiveness-v1.schema.json',
    'examples/support-analytics-documentation-effectiveness-v5.7.0.json',
    'backend/app/support_analytics_documentation_effectiveness.py',
    'backend/tests/test_support_analytics_documentation_effectiveness.py',
    'wordpress/sustainable-catalyst-feature-suggestions/assets/support-analytics-documentation-effectiveness.css',
    'wordpress/sustainable-catalyst-feature-suggestions/assets/support-analytics-documentation-effectiveness.js',
);
$failed = array();
foreach ($required as $file) {
    $ok = is_file($root . '/' . $file) && filesize($root . '/' . $file) > 0;
    echo ($ok ? 'PASS' : 'FAIL') . " - {$file}\n";
    if (!$ok) $failed[] = $file;
}
$manifest = json_decode(file_get_contents($root . '/release-manifest-v5.7.0.json'), true);
$feature = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$schema = json_decode(file_get_contents($root . '/schemas/scfs-support-analytics-documentation-effectiveness-v1.schema.json'), true);
$example = json_decode(file_get_contents($root . '/examples/support-analytics-documentation-effectiveness-v5.7.0.json'), true);
$checks = array(
    'release manifest version' => ($manifest['version'] ?? '') === '5.7.0',
    'release manifest name' => ($manifest['release_name'] ?? '') === 'Support Analytics and Documentation Effectiveness',
    'feature manifest version' => ($feature['version'] ?? '') === '7.8.1',
    'analytics schema' => ($feature['support_analytics']['schema'] ?? '') === 'scfs-support-analytics-documentation-effectiveness/1.0',
    'no database migration' => ($manifest['compatibility']['database_migration_required'] ?? null) === false,
    'schema version contract' => ($schema['properties']['version']['const'] ?? '') === '5.7.0',
    'example schema contract' => ($example['schema'] ?? '') === 'scfs-support-analytics-documentation-effectiveness/1.0',
    'privacy boundary' => ($example['privacy']['raw_search_text_exposed'] ?? true) === false,
    'human review boundary' => ($example['governance']['human_review_required'] ?? false) === true,
);
foreach ($checks as $label => $ok) { echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n"; if (!$ok) $failed[] = $label; }
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.7.0 release artifact contract passed (' . (count($required) + count($checks)) . " checks).\n";
