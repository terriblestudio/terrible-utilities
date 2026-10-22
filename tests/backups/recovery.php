<?php
use TerribleBackups\{Engine,Support};
function ensure($ok,$label) { if (!$ok) throw new RuntimeException('FAIL: '.$label); echo 'PASS: '.$label."\n"; }
function finish($j) { for($i=0;$i<2000;$i++) { if(in_array($j['phase'],['done','ready']))return $j; if(in_array($j['phase'],['failed','recovery']))throw new RuntimeException($j['error']); $j=Engine::step($j['id']); } throw new RuntimeException('timeout'); }
wp_set_current_user(1); global $wpdb;
$dir=wp_upload_dir()['basedir']; file_put_contents($dir.'/tb-fixture.txt','snapshot');
$b=finish(Engine::backup(['uploads'])); file_put_contents($dir.'/tb-fixture.txt','destination');
$j=Engine::restore($b['id'],['uploads'],'');
while($j['phase']!=='commit_files')$j=Engine::step($j['id']);
$j=Engine::step($j['id']);
// Replay the last file batch after simulating a lost state acknowledgement.
$j['index']=0; $j['phase']='commit_files'; Support::json(Engine::dir($j['id']).'/job.json',$j);
$j=Engine::step($j['id']); Engine::cancel($j['id']);
ensure(file_get_contents($dir.'/tb-fixture.txt')==='destination','replayed file commit retains the original rollback copy');
$u=Engine::upload(3); Engine::chunk($u['id'],0,'abc'); $u=Engine::chunk($u['id'],0,'abc'); ensure($u['phase']==='ready','last upload chunk replay succeeds after lost final response'); Engine::delete($u['id']);
// Build a different-prefix database archive from a real WordPress snapshot.
$b=finish(Engine::backup(['database'])); $path=Engine::dir($b['id']).'/archive.zip'; $zip=new ZipArchive(); $zip->open($path); $m=json_decode($zip->getFromName('manifest.json'),true); $m['prefix']='origin_';
foreach($m['tables'] as &$table) {
    $original=$table['name']; $renamed='origin_'.substr($original,strlen($wpdb->prefix));
    $raw=$zip->getFromName($table['file']); $lines=explode("\n",rtrim($raw,"\n")); $head=json_decode(array_shift($lines),true);
    $head['schema']=preg_replace('/^CREATE TABLE `'.preg_quote($original,'/').'`/','CREATE TABLE `'.$renamed.'`',$head['schema']);
    foreach($lines as &$line) { $row=json_decode($line,true); foreach($row as &$v) { if($v!==null) { $decoded=base64_decode($v); if(str_starts_with($decoded,$wpdb->prefix))$v=base64_encode('origin_'.substr($decoded,strlen($wpdb->prefix))); } } unset($v); $line=json_encode($row); } unset($line);
    $newRaw=json_encode($head)."\n".implode("\n",$lines).($lines?"\n":''); $newFile='database/'.$renamed.'.ndjson'; $zip->deleteName($table['file']); $zip->addFromString($newFile,$newRaw);
    foreach($m['files'] as &$f) { if($f['name']===$table['file']) { $f=['name'=>$newFile,'size'=>strlen($newRaw),'sha256'=>hash('sha256',$newRaw)]; } } unset($f);
    $table['name']=$renamed; $table['file']=$newFile;
} unset($table);
$zip->addFromString('manifest.json',json_encode($m)); $zip->close();
$j=finish(Engine::restore($b['id'],['database'],'')); wp_cache_flush();
$user=new WP_User(1); ensure($user->has_cap('manage_options'),'migration remaps serialized role options and user capability keys for a different table prefix');
// Interrupt exactly after atomic RENAME but before the acknowledgement is saved.
update_option('tb_recovery_probe','snapshot'); $b=finish(Engine::backup(['database'])); update_option('tb_recovery_probe','destination');
$j=Engine::restore($b['id'],['database'],''); while($j['phase']!=='commit_database')$j=Engine::step($j['id']);
$renames=[]; $j['old_tables']=[];
foreach($j['staged_tables'] as $target=>$stage) { $old='tb_old_'.substr($j['id'],0,12).'_'.count($j['old_tables']); $j['old_tables'][$target]=$old; $renames[]=Support::quote($target).' TO '.Support::quote($old); $renames[]=Support::quote($stage).' TO '.Support::quote($target); }
$j['phase']='swapping'; Support::json(Engine::dir($j['id']).'/job.json',$j); Support::query('RENAME TABLE '.implode(', ',$renames));
Engine::cancel($j['id']); wp_cache_flush(); ensure(get_option('tb_recovery_probe')==='destination','interruption after atomic table swap recovers the original database');
ensure(!file_exists(ABSPATH.'.maintenance'),'crash recovery removes maintenance');
echo "ALL RECOVERY CHECKS PASSED\n";
