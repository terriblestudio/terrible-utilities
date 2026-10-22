<?php
use TerribleBackups\{Engine, Support};
function demand($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label."\n";}
function complete($j){for($i=0;$i<3000;$i++){if($j['phase']==='done')return $j;if(in_array($j['phase'],['failed','recovery']))throw new RuntimeException($j['error']);$j=Engine::step($j['id']);}throw new RuntimeException('timeout');}
wp_set_current_user(1);
$paths=[
'core'=>ABSPATH.'wp-includes/tb-full-test.txt',
'plugins'=>WP_CONTENT_DIR.'/plugins/tb-fixture/readme.txt',
'mu-plugins'=>WP_CONTENT_DIR.'/mu-plugins/tb-fixture.txt',
'themes'=>WP_CONTENT_DIR.'/themes/tb-fixture/readme.txt',
'content'=>WP_CONTENT_DIR.'/tb-content-test.txt',
'uploads'=>wp_upload_dir()['basedir'].'/tb-full-test.txt',
'root'=>ABSPATH.'tb-root-test.txt'];
foreach($paths as $part=>$path){wp_mkdir_p(dirname($path));file_put_contents($path,'original-'.$part);}
update_option('tb_full_test','original');$config=hash_file('sha256',ABSPATH.'wp-config.php');$plugin=hash_file('sha256',TERRIBLE_BACKUPS_FILE);
$b=complete(Engine::backup(array_keys(Support::categories())));
foreach($paths as $part=>$path)file_put_contents($path,'changed-'.$part);
update_option('tb_full_test','changed');
$j=complete(Engine::restore($b['id'],array_keys(Support::categories()),''));
foreach($paths as $part=>$path)demand(file_get_contents($path)===($part==='root'?'changed-':'original-').$part,'full restore component: '.$part);
wp_cache_flush();demand(get_option('tb_full_test')==='original','full restore database');
demand(hash_file('sha256',ABSPATH.'wp-config.php')===$config,'destination wp-config retained');
demand(hash_file('sha256',TERRIBLE_BACKUPS_FILE)===$plugin,'running recovery plugin retained');
demand(!file_exists(ABSPATH.'.maintenance'),'full restore clears maintenance');
echo "ALL FULL RESTORE CHECKS PASSED\n";
