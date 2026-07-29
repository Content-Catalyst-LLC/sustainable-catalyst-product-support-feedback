<?php
$root = dirname(__DIR__);
$css = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/unified-support-search.css');
$js = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/unified-support-search.js');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v5.4.0.json'), true);
$checks = array(
    'unified form CSS' => strpos($css, '.scfs-unified-search__form') !== false,
    'summary CSS' => strpos($css, '.scfs-unified-search__summary') !== false,
    'journey CSS' => strpos($css, '.scfs-unified-search__journey-step') !== false,
    'result cards CSS' => strpos($css, '.scfs-unified-search__result') !== false,
    'handoff CSS' => strpos($css, '.scfs-unified-search__handoff') !== false,
    'responsive CSS' => strpos($css, '@media (max-width: 820px)') !== false,
    'print CSS' => strpos($css, '@media print') !== false,
    'dynamic initialization' => strpos($js, 'scfs:support-view-loaded') !== false,
    'keyboard search shortcut' => strpos($js, "event.key === '/'") !== false,
    'manifest current version' => ($manifest['version'] ?? '') === '7.8.1',
    'manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'release manifest current version' => ($release['version'] ?? '') === '5.4.0',
    'release notes' => file_exists($root . '/RELEASE_NOTES_5.4.0.md'),
    'documentation' => file_exists($root . '/docs/unified-search-guided-resolution-v5.3.0.md'),
    'example' => file_exists($root . '/examples/unified-support-search.json'),
    'schema' => file_exists($root . '/schemas/scfs-unified-support-search-v1.schema.json'),
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.4.0 interface and release artifact contract passed (' . count($checks) . " checks).\n";
