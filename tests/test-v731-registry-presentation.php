<?php
$root = dirname(__DIR__);
$registry = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
$checks = array(
    'registry version' => strpos($registry, "const VERSION = '7.8.1';") !== false,
    'registry schema' => strpos($registry, "scfs-canonical-product-registry/2.1") !== false,
    'frontend upgrade hook' => strpos($registry, "add_action('init', array(\$this, 'maybe_upgrade'), 3)") !== false,
    'presentation migration' => strpos($registry, 'apply_v731_presentation_migrations') !== false,
    'knowledge library public visibility' => strpos($registry, "\$records['knowledge-library']['public_visible'] = '1';") !== false,
    'knowledge library homepage visibility' => strpos($registry, "\$records['knowledge-library']['homepage_visible'] = '1';") !== false,
    'knowledge library label' => strpos($registry, "'Knowledge Library'") !== false,
    'analytics full name' => strpos($registry, "'Catalyst Analytics R'") !== false,
    'analytics public label' => strpos($registry, "'Analytics R'") !== false,
    'analytics legacy alias' => strpos($registry, "\$legacy_names[] = 'Catalyst AnalyticsR';") !== false,
    'canonical id preserved' => strpos($registry, "'catalyst-analytics-r'") !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 registry presentation migration contract passed.\n";
