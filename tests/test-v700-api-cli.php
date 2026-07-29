<?php
$root=dirname(__DIR__);$class=file_get_contents($root.'/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-connected-help-desk-platform.php');foreach(array('/help-desk/connected-platform/schema','/help-desk/connected-platform/overview','/help-desk/connected-platform/modules','/dossier','/journey','/commands/plan','/authorize','/snapshots/refresh','WP_CLI::add_command','status','modules','dossier','journey','snapshot') as $needle){if(strpos($class,$needle)===false){fwrite(STDERR,"FAIL API/CLI: $needle
");exit(1);}}echo "v7.8.1 connected API and CLI contract passed.
";
