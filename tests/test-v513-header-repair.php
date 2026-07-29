<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-knowledge-base.php');
$css = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/knowledge-base.css');
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$checks = array(
    "const VERSION = '5.4.0'" => strpos($class, "const VERSION = '5.4.0'") !== false,
    'astra title filter' => strpos($class, "astra_the_title_enabled") !== false,
    'astra meta filter' => strpos($class, "astra_single_post_meta") !== false,
    'support article title suppression' => strpos($class, 'is_singular(self::ARTICLE_POST_TYPE) ? false') !== false,
    'scoped fallback selector' => strpos($css, 'body.single-sc_support_article.scfs-kb-full-width .entry-header') !== false,
    'plugin version' => strpos($main, 'Version: 7.8.1') !== false,
);
foreach ($checks as $label => $pass) { if (!$pass) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } }
echo "v5.2.7 header repair contract passed (" . count($checks) . " checks).\n";
