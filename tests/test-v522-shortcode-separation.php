<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-integrated-knowledge-base.php');
$css = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/knowledge-base.css');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'compact shortcode constant' => strpos($class, "const COMPACT_SHORTCODE = 'scfs_support_library_compact';") !== false,
    'compact shortcode registration' => strpos($class, "add_shortcode(self::COMPACT_SHORTCODE") !== false,
    'compact renderer' => strpos($class, 'public function render_compact_library') !== false,
    'automatic injector disabled' => strpos($class, 'public function automatically_render_on_support_page($content)') !== false && strpos($class, "return '<div class=\"scfs-support-page-library-entry\"'") === false,
    'dedicated full shortcode remains' => strpos(file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-knowledge-base.php'), "const SHORTCODE = 'scfs_support_knowledge_base';") !== false,
    'library parity compact css' => strpos($css, 'v5.2.7 Dynamic records, permalink integrity, refined full browser') !== false,
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'manifest compact shortcode' => in_array('[scfs_support_library_compact]', $manifest['shortcodes'] ?? array(), true),
    'release notes' => file_exists($root . '/RELEASE_NOTES_5.2.9.md'),
);
$failed = array_keys(array_filter($checks, static function($pass){ return !$pass; }));
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.2.7 shortcode separation contract passed (' . count($checks) . " checks).\n";
