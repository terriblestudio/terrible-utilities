<?php
// Disposable Docker test configuration only.
define('AS3CF_SETTINGS', serialize(['provider'=>'aws','access-key-id'=>'isolated-test-access','secret-access-key'=>'isolated-test-secret','bucket'=>'social-images-test','region'=>'us-east-1','copy-to-s3'=>true,'serve-from-s3'=>true,'remove-local-file'=>true]));
add_filter('as3cf_aws_s3_client_args',static function($args){$args['endpoint']='http://minio:9000';$args['use_path_style_endpoint']=true;return $args;});
add_filter('as3cf_aws_s3_url_domain',static function($domain,$bucket){return 'minio:9000/'.$bucket;},10,2);
add_filter('as3cf_upload_acl',static function(){return 'private';});
add_filter('as3cf_upload_acl_sizes',static function(){return 'private';});
add_filter('http_request_host_is_external',static function($external,$host){return $host==='minio' ? true : $external;},10,2);
add_filter('http_allowed_safe_ports',static function($ports){$ports[]=9000;return $ports;});
