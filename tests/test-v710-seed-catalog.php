<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
$ids = array('sustainable-catalyst-core','product-support-feedback','contact-engagement','knowledge-library','research-librarian','site-intelligence','decision-studio','narrative-risk','catalyst-data','catalyst-analytics-r','catalyst-finance','global-impact-catalyst','catalyst-canvas','catalyst-grit','workbench','sustainable-catalyst-lab','catalyst-intelligence');
foreach ($ids as $id) {
    if (strpos($class, "'{$id}'") === false) { fwrite(STDERR, "FAIL seed product: {$id}\n"); exit(1); }
}
foreach (array('foundation','research-intelligence','data-analysis','creation-systems','commercial') as $family) {
    if (strpos($class, "'{$family}'") === false) { fwrite(STDERR, "FAIL family: {$family}\n"); exit(1); }
}
if (strpos($class, "'public_version' => '0.23.1'") === false || strpos($class, "'version_source' => 'manual'") === false) {
    fwrite(STDERR, "FAIL Catalyst Intelligence manual seed\n"); exit(1);
}
echo "v7.8.1 seeded product catalog contract passed.\n";
