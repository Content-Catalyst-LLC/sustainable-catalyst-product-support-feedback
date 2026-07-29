<?php
$root=dirname(__DIR__);$class=file_get_contents($root.'/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-connected-help-desk-platform.php');foreach(array('scfs_connected_help_desk_platform','scfs_help_desk_platform','public_capability_overview','private case records') as $needle){if(stripos($class,$needle)===false){fwrite(STDERR,"FAIL shortcode: $needle
");exit(1);}}echo "v7.8.1 public capability overview contract passed.
";
