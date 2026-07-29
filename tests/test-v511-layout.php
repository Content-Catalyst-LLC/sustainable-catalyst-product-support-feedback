<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$knowledge = file_get_contents($plugin . '/includes/class-scfs-knowledge-base.php');
$integrated = file_get_contents($plugin . '/includes/class-scfs-integrated-knowledge-base.php');
$css = file_get_contents($plugin . '/assets/knowledge-base.css');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'main version constant' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'knowledge base version' => strpos($knowledge, "const VERSION = '5.4.0';") !== false,
    'integrated knowledge base version' => strpos($integrated, "const VERSION = '5.4.0';") !== false,
    'body class filter' => strpos($knowledge, "add_filter('body_class'") !== false,
    'astra page layout filter' => strpos($knowledge, "add_filter('astra_page_layout'") !== false,
    'astra content layout filter' => strpos($knowledge, "add_filter('astra_get_content_layout'") !== false,
    'no-sidebar return' => strpos($knowledge, "? 'no-sidebar' : \$layout") !== false,
    'knowledge base view scope' => strpos($knowledge, 'is_public_knowledge_base_view') !== false,
    'sidebar suppression CSS' => strpos($css, 'body.scfs-kb-full-width #secondary') !== false,
    'publications navigation suppression' => strpos($css, '.publications-navigation') !== false,
    'full width primary content' => strpos($css, 'body.scfs-kb-full-width #primary') !== false,
    'expanded article shell' => strpos($css, 'max-width:1180px') !== false,
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'release notes exist' => file_exists($root . '/RELEASE_NOTES_5.2.9.md'),
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
