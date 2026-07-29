<?php
$root = dirname(__DIR__);
$required = array(
    'RELEASE_NOTES_5.6.0.md',
    'SUSTAINABLE_CATALYST_PRODUCT_SUPPORT_AND_FEEDBACK_PLATFORM_V5.6.0_RELEASE_NOTES.md',
    'release-manifest-v5.6.0.json',
    'feature_suggestions_manifest.json',
    'docs/feedback-intelligence-product-signals-v5.6.0.md',
    'schemas/scfs-feedback-product-signals-v1.schema.json',
    'examples/feedback-product-signals-v5.6.0.json',
    'backend/app/feedback_product_signals.py',
    'backend/tests/test_feedback_product_signals.py',
    'wordpress/sustainable-catalyst-feature-suggestions/assets/feedback-product-signals.css',
    'wordpress/sustainable-catalyst-feature-suggestions/assets/feedback-product-signals.js',
);
$failed = array();
foreach ($required as $file) {
    $ok = is_file($root . '/' . $file) && filesize($root . '/' . $file) > 0;
    echo ($ok ? 'PASS' : 'FAIL') . " - {$file}\n";
    if (!$ok) $failed[] = $file;
}
$manifest = json_decode(file_get_contents($root . '/release-manifest-v5.6.0.json'), true);
$feature = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$schema = json_decode(file_get_contents($root . '/schemas/scfs-feedback-product-signals-v1.schema.json'), true);
$example = json_decode(file_get_contents($root . '/examples/feedback-product-signals-v5.6.0.json'), true);
$checks = array(
    'release manifest version' => ($manifest['version'] ?? '') === '5.6.0',
    'release manifest name' => ($manifest['release_name'] ?? '') === 'Feedback Intelligence and Product Signals',
    'feature manifest current version' => ($feature['version'] ?? '') === '7.8.1',
    'feature manifest signal schema' => ($feature['feedback_product_signals']['schema'] ?? '') === 'scfs-feedback-product-signals/1.0',
    'no database migration' => ($manifest['compatibility']['database_migration_required'] ?? null) === false,
    'schema version contract' => ($schema['properties']['version']['const'] ?? '') === '5.6.0',
    'example schema contract' => ($example['schema'] ?? '') === 'scfs-feedback-product-signals/1.0',
    'human review boundary' => ($example['governance']['human_review_required'] ?? false) === true,
    'raw search text boundary' => ($example['privacy']['raw_search_text_exposed'] ?? true) === false,
);
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$ok) $failed[] = $label;
}
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.6.0 release artifact contract passed (' . (count($required) + count($checks)) . " checks).\n";
