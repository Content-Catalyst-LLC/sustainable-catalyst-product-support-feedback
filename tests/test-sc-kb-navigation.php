<?php
// Existing importer harness supplies isolated in-memory WordPress functions.
require __DIR__ . '/test-v510-import.php';
$kb = SCFS_Integrated_Knowledge_Base::instance();
function kb_assert($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS - $message\n"; }
$nav = $kb->research_navigation();
kb_assert(count($nav['areas']) === 8, 'eight research areas loaded from versioned contract');
kb_assert(count($nav['product_areas']) === 16, 'all sixteen application identities mapped');
$post = (object) array('ID' => 9000, 'post_status' => 'publish', 'post_password' => '');
$GLOBALS['scfs_meta'][9000]['_scfs_kb_product_slug'] = 'workbench';
kb_assert($kb->article_research_areas($post) === array('scientific-computing'), 'legacy product maps without mutating its post');
$GLOBALS['scfs_meta'][9000]['_scfs_kb_research_areas'] = array('visualization-graphing', 'scientific-computing', 'invalid');
kb_assert($kb->article_research_areas($post) === array('scientific-computing', 'visualization-graphing'), 'explicit multiple areas accepted and unknown areas excluded');
$GLOBALS['scfs_meta'][9000]['_scfs_kb_research_areas'] = array();
kb_assert($kb->article_research_areas($post) === array(), 'explicit empty mapping preserved for review queue');
for ($i = 10000; $i < 10600; $i++) {
    $GLOBALS['scfs_posts'][$i] = (object) array('ID' => $i, 'post_type' => 'sc_support_article', 'post_status' => 'publish', 'post_password' => '');
}
$GLOBALS['scfs_posts'][10600] = (object) array('ID' => 10600, 'post_type' => 'sc_support_article', 'post_status' => 'publish', 'post_password' => 'restricted');
$GLOBALS['scfs_posts'][10601] = (object) array('ID' => 10601, 'post_type' => 'sc_support_article', 'post_status' => 'draft', 'post_password' => '');
$method = new ReflectionMethod($kb, 'all_published_articles');
$posts = $method->invoke($kb);
kb_assert(count($posts) === 696, 'counts include more than 500 articles and exclude draft/password content');
echo "SC-KB navigation behavior passed; in-memory harness, not WordPress/browser acceptance.\n";
