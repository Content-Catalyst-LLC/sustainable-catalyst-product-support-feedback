<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$support = file_get_contents($plugin . '/includes/class-scfs-product-support-platform.php');
$css = file_get_contents($plugin . '/assets/product-support-platform.css');
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'support platform version' => strpos($support, "const VERSION = '5.4.0';") !== false,
    'embedded browser renderer' => strpos($support, 'private function render_embedded_knowledge_base') !== false,
    'resolve view includes browser' => strpos($support, "case 'resolve':") !== false && strpos($support, '$this->render_embedded_knowledge_base($context[\'product\']);') !== false,
    'overview includes browser' => preg_match('/private function render_overview\(.*?render_embedded_knowledge_base\(\$product\)/s', $support) === 1,
    'documentation view uses same renderer' => strpos($support, '$this->render_embedded_knowledge_base($context[\'product\'], true);') !== false,
    'support articles navigation label' => strpos($support, "'documentation' => array('label' => __('Support Articles'") !== false,
    'browser anchor' => strpos($support, 'id="knowledge-base"') !== false,
    'publication browser title' => strpos($support, "'title' => __('Browse Support Articles'") !== false,
    'old duplicate documentation pathway removed' => strpos($support, "'documentation' => array(__('Browse documentation'") === false,
    'embedded browser CSS' => strpos($css, 'v5.2.7 Unified Support Center and embedded Knowledge Base browser') !== false,
    'responsive embedded browser CSS' => strpos($css, '.scfs-support-platform__knowledge-base--dedicated') !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.2.7 unified Support Center contract passed (' . count($checks) . " checks).\n";
