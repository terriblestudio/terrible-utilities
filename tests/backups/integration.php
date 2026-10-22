<?php
use TerribleBackups\{Engine, Support, Transport};
function check($condition, $label) { if (!$condition) { throw new RuntimeException('FAIL: ' . $label); } echo 'PASS: ' . $label . "\n"; }
function job($j) { $steps = 0; while (!in_array($j['phase'], ['done', 'ready', 'failed', 'recovery', 'cancelled'], true)) { $j = Engine::step($j['id']); if (++$steps > 2000) throw new RuntimeException('Too many steps'); } if (in_array($j['phase'], ['failed', 'recovery'], true)) throw new RuntimeException(json_encode($j)); return $j; }
wp_set_current_user(1);
$old = 'https://source.example'; $new = 'http://localhost:18765';
$object = new stdClass(); $object->url = $old . '/nested';
$serialized = serialize(['utf8' => 'café', 'nested' => [$old . '/page', $object]]);
$changed = unserialize(Support::replace($serialized, $old, $new));
check($changed['nested'][1]->url === $new . '/nested', 'serialized arrays and objects retain valid lengths');
check(Support::replace('{"url":"https:\\/\\/source.example\\/a"}', $old, $new) === '{"url":"http:\\/\\/localhost:18765\\/a"}', 'escaped JSON URL replacement');
foreach (['../escape', '/absolute', 'a/../../b', 'a\\b', 'C:/x'] as $unsafe) { try { Support::path($unsafe); throw new RuntimeException('accepted unsafe path'); } catch (RuntimeException $e) { check($e->getMessage() === 'Unsafe archive path.', 'reject ' . $unsafe); } }
$upload = wp_upload_dir()['basedir']; wp_mkdir_p($upload);
file_put_contents($upload . '/tb-fixture.txt', 'original-file');
$f = fopen($upload . '/tb-large.bin', 'wb'); for ($i=0;$i<20;$i++) fwrite($f, random_bytes(1048576)); fclose($f);
update_option('tb_fixture', ['url' => $old . '/page', 'nested' => [$object], 'blob' => "binary\0value"]);
$post = wp_insert_post(['post_title'=>'Backup fixture', 'post_content'=>$old.'/body', 'post_status'=>'publish', 'guid'=>$old.'/stable-guid']);
$backup = job(Engine::backup(['database', 'uploads', 'plugins', 'themes', 'mu-plugins', 'content', 'core', 'root']));
$m = Engine::inspect($backup['id']); check(count($m['files']) > 1000 && count($m['tables']) >= 12, 'full-site archive includes core files and database');
check(in_array('root/wp-config.php', array_column($m['files'],'name')), 'full backup includes root configuration');
$source = Engine::dir($backup['id']).'/archive.zip';
check(hash_file('sha256',$source) === $backup['sha256'], 'archive checksum');
$transferBackup = job(Engine::backup(['uploads']));
Transport::save(['s3'=>['endpoint'=>'http://s3:9000','region'=>'us-east-1','bucket'=>'backups','access'=>'terrible-test-access','secret'=>'terrible-test-secret','prefix'=>'nested/backups'], 'ftp'=>['host'=>'ftp','port'=>21,'user'=>'terrible-test','password'=>'terrible-test-password','prefix'=>'nested/backups','tls'=>false]]);
try { Transport::s3(Transport::settings()['s3'], 'PUT', ''); } catch (Throwable $e) { /* Bucket may already exist. */ }
foreach (['s3','ftp'] as $kind) {
    $sent = job(Engine::remote($kind, 'roundtrip.zip', $transferBackup['id']));
    $received = job(Engine::remote($kind, 'roundtrip.zip'));
    file_put_contents($upload.'/tb-fixture.txt','remote-restore-test');
    job(Engine::restore($received['id'], ['uploads'], ''));
    check(file_get_contents($upload.'/tb-fixture.txt') === 'original-file', strtoupper($kind).' fetched archive restores successfully');
    check(hash_file('sha256',Engine::dir($received['id']).'/archive.zip') === hash_file('sha256',Engine::dir($transferBackup['id']).'/archive.zip'), strtoupper($kind).' multipart/ranged 20 MiB archive round trip');
}
// Direct chunk retries and ordering.
$u = Engine::upload(6); $u = Engine::chunk($u['id'],0,'abc'); $again = Engine::chunk($u['id'],0,'abc'); check($again['offset'] === 3,'upload retry is idempotent');
try { Engine::chunk($u['id'],4,'d'); throw new RuntimeException('accepted invalid offset'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'offset'),'out-of-order chunks rejected'); }
Engine::cancel($u['id']);
file_put_contents($upload.'/tb-fixture.txt','changed-file'); update_option('tb_fixture', ['wrong'=>true]);
// Replace manifest URLs to model a migration without routing the actual test host away.
$zip = new ZipArchive(); $zip->open($source); $manifest = json_decode($zip->getFromName('manifest.json'),true); $manifest['home']=$old; $manifest['siteurl']=$old; $zip->addFromString('manifest.json',json_encode($manifest)); $zip->close();
$restore = job(Engine::restore($backup['id'], ['database','uploads'], $new));
check(file_get_contents($upload.'/tb-fixture.txt') === 'original-file', 'selected upload files restored');
wp_cache_flush(); $fixture = get_option('tb_fixture');
check($fixture['url'] === $new.'/page' && $fixture['nested'][0]->url === $new.'/nested' && $fixture['blob'] === "binary\0value", 'database restore with serialization-safe URL replacement and binary preservation');
check(get_post($post)->guid === $old.'/stable-guid', 'post GUID unchanged');
check(!file_exists(ABSPATH.'.maintenance'), 'maintenance removed after successful restore');
// Cancel after the first applied file and prove original files return.
file_put_contents($upload.'/tb-fixture.txt','before-cancel');
$j=Engine::restore($transferBackup['id'],['uploads'],'');
while($j['phase']!=='commit_files') { $j=Engine::step($j['id']); if ($j['phase']==='failed') throw new RuntimeException($j['error']); }
$j=Engine::step($j['id']); Engine::cancel($j['id']);
check(file_get_contents($upload.'/tb-fixture.txt') === 'before-cancel','cancelling a partially applied restore rolls files back');
check(!file_exists(ABSPATH.'.maintenance'), 'maintenance removed after cancellation');
// Corruption must be caught before touching the destination.
$zip=new ZipArchive(); $path=Engine::dir($transferBackup['id']).'/archive.zip'; $zip->open($path); $zip->addFromString('uploads/tb-fixture.txt','tampered-file'); $zip->close();
try { $j=Engine::restore($transferBackup['id'],['uploads'],''); $j=job($j); throw new RuntimeException('accepted corrupt archive'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(),'checksum')||str_contains($e->getMessage(),'size'), 'corrupt archive rejected before activation'); }
check(file_get_contents($upload.'/tb-fixture.txt') === 'before-cancel', 'failed validation leaves destination unchanged');
echo "ALL INTEGRATION CHECKS PASSED\n";
