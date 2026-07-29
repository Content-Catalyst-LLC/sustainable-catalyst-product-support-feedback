<?php
$root=dirname(__DIR__);$module=file_get_contents($root.'/backend/app/connected_help_desk_platform.py');$main=file_get_contents($root.'/backend/app/main.py');foreach(array('VERSION = "7.8.1"','SCHEMA = "scfs-connected-help-desk-platform/1.0"','evaluate_connected_help_desk','plan_support_journey','plan_connected_command','evaluate_case_dossier','verify_connected_report') as $needle){if(strpos($module,$needle)===false){fwrite(STDERR,"FAIL backend: $needle
");exit(1);}}foreach(array('/v1/help-desk/connected-platform/capabilities','/v1/help-desk/connected-platform/evaluate','/v1/help-desk/connected-platform/journeys/plan','/v1/help-desk/connected-platform/commands/plan','/v1/help-desk/connected-platform/dossiers/evaluate','/v1/help-desk/connected-platform/reports/verify') as $route){if(strpos($main,$route)===false){fwrite(STDERR,"FAIL route: $route
");exit(1);}}echo "v7.8.1 connected backend contract passed.
";
