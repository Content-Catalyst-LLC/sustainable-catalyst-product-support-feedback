<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-integrated-knowledge-base.php');
$css = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/knowledge-base.css');
$js = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/integrated-knowledge-base.js');
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version constant' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'knowledge base version' => strpos($class, "const VERSION = '5.4.0';") !== false,
    'category grouping' => strpos($class, 'browser_category_groups') !== false,
    'category navigation' => strpos($class, 'scfs-kb-library-nav-group') !== false,
    'product navigator' => strpos($class, 'scfs-kb-library-navigation') !== false,
    'generated article rows' => strpos($class, 'render_library_article_row') !== false,
    'compact category details' => strpos($class, 'scfs-support-library-compact__topic') !== false,
    'first compact product expanded' => strpos($class, "\$shown === 0 && \$atts['open_first'] === '1'") !== false,
    'inline article containers' => strpos($class, 'scfs-support-library-compact__articles') !== false,
    'server rendered filters' => strpos($class, 'scfs_kb_section') !== false && strpos($class, 'scfs_kb_product') !== false,
    'progressive script retained' => strlen($js) > 100,
    'native details behavior' => strpos($class, '<details class="scfs-support-library-compact__topic"') !== false,
    'refined library styling' => strpos($css, '.scfs-kb-refined__layout') !== false,
    'article list styling' => strpos($css, '.scfs-support-library-article') !== false,
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'release notes' => file_exists($root . '/RELEASE_NOTES_5.2.9.md'),
);
$failed = array_keys(array_filter($checks, function ($value) { return !$value; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { echo 'Failed checks: ' . implode(', ', $failed) . "\n"; exit(1); }
echo 'v5.2.7 support documentation library contract passed (' . count($checks) . " checks).\n";
