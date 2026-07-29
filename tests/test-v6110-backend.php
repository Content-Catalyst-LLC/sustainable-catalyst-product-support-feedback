<?php
$root=dirname(__DIR__);$main=file_get_contents($root.'/backend/app/main.py');$module=file_get_contents($root.'/backend/app/help_desk_api_integrations.py');$test=file_get_contents($root.'/backend/tests/test_help_desk_api_integrations.py');foreach(array('/v1/help-desk/integrations/capabilities','/scopes/evaluate','/subscriptions/evaluate','/deliveries/sign','/deliveries/retry/evaluate','/external-links/evaluate','/privacy/evaluate','/reports/verify') as $route){if(strpos($main,$route)===false){fwrite(STDERR,"FAIL backend route: $route
");exit(1);}}foreach(array('VERSION = "7.8.1"','evaluate_api_scope','evaluate_webhook_subscription','sign_webhook_delivery','evaluate_delivery_retry','evaluate_external_link','evaluate_integration_privacy','verify_integration_report') as $needle){if(strpos($module,$needle)===false){fwrite(STDERR,"FAIL backend: $needle
");exit(1);}}if(strpos($test,'test_capabilities_endpoint')===false){fwrite(STDERR,"FAIL backend test
");exit(1);}echo "v6.12.0 integration backend contract passed.
";
