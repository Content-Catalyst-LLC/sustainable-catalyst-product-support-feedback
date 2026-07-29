<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
foreach (array('canonical_id_immutable','public_visibility_governed','homepage_visibility_governed','private_repository_fields_publicly_exposed','automatic_publication','human_review_required') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL governance: {$needle}\n"); exit(1); }
}
if (strpos($class, "'private_repository_fields_publicly_exposed' => false") === false || strpos($class, "'automatic_publication' => false") === false || strpos($class, "'human_review_required' => true") === false) {
    fwrite(STDERR, "FAIL governance values\n"); exit(1);
}
echo "v7.8.1 product registry governance contract passed.\n";
