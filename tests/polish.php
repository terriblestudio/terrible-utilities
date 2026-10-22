<?php
use TerribleBackups\Transport as T;
function check($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
wp_set_current_user(1);
check(TerribleTools\Admin::grouped(),'multiple core products share one menu');
$original=T::settings();
try {
 $empty=$original; foreach(TerribleBackups\Providers::all() as$id=>$p)$empty[$id]=[]; $empty['_connections']=[];
 // save intentionally preserves secrets; readiness still requires non-secret configuration.
 T::save($empty); check(!T::destinations(),'unconfigured providers are absent from actions');
 T::save(['s3'=>['bucket'=>'test','access'=>'access','secret'=>'secret']]);
 check(array_keys(T::destinations())===['s3'],'only configured providers appear');
 T::remember('s3','Regression archive'); $id=array_key_last(T::settings()['_connections']); T::remember('s3','regression archive');
 check(count(T::settings()['_connections'])===1,'repeated save is idempotent and case insensitive');
 check(count(T::destinations())===1 && isset(T::destinations()[$id]),'named destination replaces identical default in actions');
 T::edit($id,['bucket'=>'edited','secret'=>'']);check(T::connection($id)['config']['bucket']==='edited' && T::connection($id)['config']['secret']==='secret' && T::settings()['s3']['bucket']==='test','editing a saved connection preserves its secret and leaves defaults unchanged');
 T::manage($id,'rename','Renamed archive');check(T::destinations()[$id]==='Renamed archive','destination rename preserves identity');
 T::save(['s3'=>['bucket'=>'different','access'=>'access','secret'=>'secret']]); T::manage($id,'update','Renamed archive');
 check(T::connection($id)['config']['bucket']==='different','destination can adopt edited provider settings');
 $hide=static function($all){unset($all['s3']);return $all;};add_filter('terrible_backups_storage_providers',$hide);
 check(!isset(T::destinations()[$id]) && isset(T::settings()['_connections'][$id]),'missing module hides unusable action while retaining configuration');remove_filter('terrible_backups_storage_providers',$hide);
 T::manage($id,'remove');check(!isset(T::settings()['_connections'][$id]),'destination can be removed');
} finally { T::save($original); }
// Stream logging must happen once, without arbitrary detail payloads.
if(class_exists('WP_Stream\\Connector')){
 global $wpdb; $table=$wpdb->prefix.'stream';
 $before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE connector='terrible-tools'");
 do_action('terrible_tools_event','media.replaced',123,'never-log-secret-123');
 $after=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE connector='terrible-tools'");
 check($after===$before+1,'Stream receives exactly one semantic event across bundled copies');
 $row=$wpdb->get_row("SELECT * FROM $table WHERE connector='terrible-tools' ORDER BY ID DESC LIMIT 1",ARRAY_A);
 check(!str_contains(wp_json_encode($row),'never-log-secret-123'),'Stream messages exclude arbitrary details');
}
check(!is_plugin_active('terrible-history/terrible-history.php') && !is_plugin_active('terrible-forms/terrible-forms.php'),'retired and paused plugins remain inactive');
echo "ALL POLISH CHECKS PASSED\n";
