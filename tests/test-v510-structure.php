<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$integrated = file_get_contents($plugin . '/includes/class-scfs-integrated-knowledge-base.php');
$knowledge = file_get_contents($plugin . '/includes/class-scfs-knowledge-base.php');
$platform = file_get_contents($plugin . '/includes/class-scfs-product-support-platform.php');
$feedback = file_get_contents($plugin . '/includes/class-scfs-documentation-intelligence.php');
$editorial = file_get_contents($plugin . '/includes/class-scfs-editorial-governance.php');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'main version constant' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'integrated class included' => strpos($main, 'class-scfs-integrated-knowledge-base.php') !== false,
    'integrated class instantiated' => strpos($main, 'SCFS_Integrated_Knowledge_Base::instance();') !== false,
    'integrated activation included' => strpos($main, 'SCFS_Integrated_Knowledge_Base::activate();') !== false,
    'integrated class version' => strpos($integrated, "const VERSION = '5.4.0';") !== false,
    'dynamic documentation directory' => strpos($integrated, 'filtered_articles') !== false && strpos($integrated, 'scfs-kb-library-results-list') !== false,
    'product navigator and compact category folders' => strpos($integrated, 'scfs-kb-library-navigation') !== false && strpos($integrated, 'scfs-support-library-compact__topic') !== false,
    'search and product filters' => strpos($integrated, 'scfs-kb-library-search') !== false && strpos($integrated, 'scfs_kb_product') !== false,
    'article breadcrumbs' => strpos($integrated, 'scfs-kb-publication-breadcrumbs') !== false,
    'article sequencing' => strpos($integrated, 'scfs-kb-publication-navigation') !== false,
    'related guides' => strpos($integrated, 'Related support articles') !== false,
    'print support' => strpos($integrated, 'data-scfs-kb-print') !== false,
    'sample links avoid media library' => strpos($integrated, "plugins_url('content/knowledge-base/'") !== false && strpos($integrated, 'media_handle_sideload') === false,
    'manual edit protection' => strpos($integrated, 'manual_edits_preserved') !== false && strpos($integrated, 'hash_equals') !== false,
    'knowledge shortcode delegates' => strpos($knowledge, 'SCFS_Integrated_Knowledge_Base::instance()->render_browser') !== false,
    'knowledge navigation persistent' => strpos($integrated, 'keep_knowledge_base_navigation') !== false,
    'platform does not hide documentation' => strpos($platform, "in_array(\$key, array('documentation'") === false,
    'modern visitor rating question' => strpos($feedback, 'Was this article useful?') !== false,
    'negative usefulness choice' => strpos($feedback, 'Not yet') !== false,
    'scoped editorial publication filter' => strpos($editorial, 'scfs_editorial_allow_system_publication') !== false,
    'integrated publication callback' => strpos($integrated, 'allow_integrated_publication') !== false,
    'integrated JavaScript exists' => file_exists($plugin . '/assets/integrated-knowledge-base.js'),
    'knowledge base CSS exists' => file_exists($plugin . '/assets/knowledge-base.css'),
    'documentation intelligence CSS exists' => file_exists($plugin . '/assets/documentation-intelligence.css'),
    'documentation guide exists' => file_exists($root . '/docs/integrated-knowledge-base-documentation-library.md'),
    'release notes exist' => file_exists($root . '/RELEASE_NOTES_5.1.0.md'),
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'manifest integrated corpus capability' => in_array('integrated first-party 96-article Knowledge Base corpus', $manifest['capabilities'] ?? array(), true),
    'manifest usefulness capability' => in_array('anonymous visitor usefulness ratings with duplicate protection', $manifest['capabilities'] ?? array(), true),
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
