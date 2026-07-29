<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$kb = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-knowledge-base.php');
$integrated = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-integrated-knowledge-base.php');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
 'plugin version' => strpos($main, 'Version: 7.8.1') !== false,
 'article permalinks remain under support guides' => strpos($kb, "'rewrite' => array('slug' => 'support/guides'") !== false,
 'legacy archive remains registered for redirect compatibility' => strpos($kb, "'has_archive' => 'support-documentation'") !== false,
 'legacy page provisioner retained' => strpos($integrated, 'ensure_dedicated_knowledge_base_page') !== false,
 'upgrade route repair retained' => strpos($integrated, 'maybe_repair_dedicated_page_route') !== false,
 'nested legacy path recognized' => strpos($integrated, "get_page_by_path('support/knowledge-base'") !== false,
 'legacy route redirects to support page' => strpos($integrated, "array('scfs_support_view' => 'documentation')") !== false && strpos($integrated, "'#knowledge-base'") !== false,
 'support article archive consolidated' => strpos($integrated, 'is_post_type_archive(SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE)') !== false,
 'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
 'manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
 'release notes' => file_exists($root . '/RELEASE_NOTES_5.2.9.md'),
);
$failed=[]; foreach($checks as $label=>$ok){ echo ($ok?'PASS':'FAIL')." - $label\n"; if(!$ok)$failed[]=$label; }
if($failed){fwrite(STDERR,'Failed checks: '.implode(', ',$failed)."\n");exit(1);} echo count($checks)." checks passed.\n";
