<?php
$root = dirname(__DIR__);
$registry = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
$discovery = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php');
$board = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-release-board.php');
$backend = file_get_contents($root . '/backend/app/canonical_product_registry.py');
$main = file_get_contents($root . '/backend/app/main.py');
$checks = array(
    'registry version' => strpos($registry, "const VERSION = '7.8.1';") !== false,
    'registry schema 2' => strpos($registry, "scfs-canonical-product-registry/2.1") !== false,
    'migration option' => strpos($registry, "MIGRATION_OPTION") !== false,
    'stale threshold' => strpos($registry, "STALE_AFTER_DAYS = 90") !== false,
    'lifecycle states' => strpos($registry, 'function lifecycle_states') !== false && strpos($registry, "'superseded'") !== false && strpos($registry, "'retired'") !== false,
    'version precedence' => strpos($registry, 'function version_precedence_options') !== false && strpos($registry, 'resolved_version') !== false,
    'private identity' => strpos($registry, "'internal_name'") !== false && strpos($registry, "'repository_slug'") !== false,
    'console screen assignment' => strpos($registry, "'console_screen'") !== false,
    'verification provenance' => strpos($registry, "'verification_source'") !== false && strpos($registry, "'source_verified_at'") !== false,
    'integrity report' => strpos($registry, 'function integrity_report') !== false && strpos($registry, 'scfs-product-registry-integrity/1.0') !== false,
    'duplicate detection' => strpos($registry, 'duplicate_canonical_id') !== false && strpos($registry, 'duplicate_public_alias') !== false,
    'stale detection' => strpos($registry, 'verification_stale') !== false,
    'supersession validation' => strpos($registry, 'invalid_supersession_target') !== false,
    'migration tooling' => strpos($registry, 'apply_v740_governance_migrations') !== false && strpos($registry, 'apply_v750_release_intelligence_migrations') !== false && strpos($registry, 'scfs products migrate') !== false,
    'validation tooling' => strpos($registry, 'scfs products validate') !== false && strpos($registry, '/product-registry/integrity') !== false,
    'private fields excluded publicly' => strpos($registry, "'private_repository_fields_publicly_exposed' => false") !== false,
    'discovery provenance' => strpos($discovery, "'verification_source' = 'wordpress_plugin'") === false && strpos($discovery, "verification_source'] = 'wordpress_plugin'") !== false,
    'console uses governed screen' => strpos($board, "product['console_screen']") !== false,
    'console hides retired products' => strpos($board, "array('retired', 'superseded')") !== false,
    'backend schema' => strpos($backend, 'SCHEMA = "scfs-canonical-product-registry/2.1"') !== false,
    'backend migration endpoint' => strpos($main, '/v1/product-registry/migrate') !== false,
);
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL - {$label}\n");
        exit(1);
    }
}
echo "v7.8.1 Product Connection Editor contract passed.\n";
