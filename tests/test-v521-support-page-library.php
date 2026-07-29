<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-integrated-knowledge-base.php');
$css = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/knowledge-base.css');
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
  'plugin version' => strpos($main, 'Version: 7.8.1') !== false,
  'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
  'legacy automatic renderer retained as no-op' => strpos($class, 'public function automatically_render_on_support_page($content)') !== false,
  'automatic content filter removed' => strpos($class, "add_filter('the_content', array(\$this, 'automatically_render_on_support_page')") === false,
  'compact shortcode renderer' => strpos($class, 'render_compact_library') !== false,
  'dedicated knowledge base route' => strpos($class, 'dedicated_knowledge_base_url') !== false,
  'compact library CSS' => strpos($css, 'v5.2.7 Dynamic records, permalink integrity, refined full browser') !== false,
  'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
  'manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
  'release notes' => file_exists($root . '/RELEASE_NOTES_5.2.9.md'),
);
foreach ($checks as $label => $pass) {
  if (!$pass) { fwrite(STDERR, "FAIL - $label\n"); exit(1); }
  echo "PASS - $label\n";
}
echo 'v5.2.7 support page library contract passed (' . count($checks) . " checks).\n";
