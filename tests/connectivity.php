<?php
function check($ok,$label) { if (!$ok) throw new RuntimeException('FAIL: '.$label); echo 'PASS: '.$label."\n"; }
function control(array $m): void { $r=wp_remote_post('http://mock:8080/control',['body'=>json_encode($m)]); if (is_wp_error($r)) throw new RuntimeException('Mock unavailable'); }
function complete(array $j): array { for ($i=0;$i<1000;$i++) { if (in_array($j['phase'],['done','ready','failed'],true)) break; $j=\TerribleBackups\Engine::step($j['id']); } check(in_array($j['phase'],['done','ready'],true),'backup job '.$j['type'].' completes'.(isset($j['error'])?': '.$j['error']:'')); return $j; }
wp_set_current_user(1);
use TerribleBackups\{Engine,Transport,Providers};
use TerribleMail\{Settings,History,Transports};
$upload=wp_upload_dir()['basedir']; wp_mkdir_p($upload); $fixture=$upload.'/cloud-fixture.bin'; file_put_contents($fixture,random_bytes(20*1048576));
$hash=hash_file('sha256',$fixture); $backup=complete(Engine::backup(['uploads']));
$cloud=['client_id'=>'test-client','client_secret'=>'test-secret','refresh_token'=>'test-refresh'];
Transport::save(['dropbox'=>$cloud,'gdrive'=>$cloud]);
foreach (['dropbox','gdrive'] as $kind) {
    $name='roundtrip-'.wp_generate_uuid4().'.zip';
    $out=complete(Engine::remote($kind,$name,$backup['id'])); check($out['cloud_verified']??false,$kind.' upload content verified');
    $listing=Providers::get($kind)->catalog(Transport::settings()[$kind]); check(in_array($name,array_column($listing['files'],'key')),$kind.' archive discoverable from provider');
    $fetched=complete(Engine::remote($kind,$name)); check(hash_file('sha256',Engine::dir($fetched['id']).'/archive.zip')===hash_file('sha256',Engine::dir($backup['id']).'/archive.zip'),$kind.' multi-chunk byte-identical round trip');
    file_put_contents($fixture,'changed'); complete(Engine::restore($fetched['id'],['uploads'],'')); check(hash_file('sha256',$fixture)===$hash,$kind.' fetched archive restores real files');
    control(['corrupt_download'=>true]); $bad=Engine::remote($kind,$name); do { $bad=Engine::step($bad['id']); } while (!in_array($bad['phase'],['failed','ready'],true)); check($bad['phase']==='failed',$kind.' corrupt download rejected'); control(['corrupt_download'=>false]);
}
$before=Transport::settings()['dropbox']; Transport::save(['ftp'=>['host'=>'ftp','port'=>21]]); check(Transport::settings()['dropbox']===$before,'unrelated settings preserve cloud credentials');
$hide=static function($p) { unset($p['dropbox']); return $p; }; add_filter('terrible_backups_storage_providers',$hide,99);
try { Engine::remote('dropbox','anything.zip'); throw new RuntimeException('accepted missing provider'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'unavailable'),'missing storage provider fails without fallback'); } remove_filter('terrible_backups_storage_providers',$hide,99);
$job=Engine::remote('gdrive','changed-'.wp_generate_uuid4().'.zip',$backup['id']); $c=Transport::settings()['gdrive']; $c['prefix']='different'; Transport::save(['gdrive'=>$c]); $job=Engine::step($job['id']); check($job['phase']==='failed' && str_contains($job['error'],'settings changed'),'in-progress job cannot silently switch destination');

$smtp=['host'=>'mock','port'=>'1025','security'=>'none','username'=>'test-user','password'=>'test-password'];
function config(string $id,array $provider): void { Settings::save(['enabled'=>true,'transport'=>$id,'from'=>'sender@example.test','name'=>'Terrible Test','providers'=>[$id=>$provider]]); }
config('smtp',$smtp); $events=['success'=>0,'failure'=>0]; add_action('wp_mail_succeeded',static function() use (&$events){$events['success']++;}); add_action('wp_mail_failed',static function() use (&$events){$events['failure']++;});
$attachment=$upload.'/mail-fixture.txt'; file_put_contents($attachment,'attachment bytes');
check(wp_mail('Reader <reader@example.test>','Unicode café','<p>Hello</p>',['Content-Type: text/html; charset=UTF-8','Cc: copy@example.test','Bcc: hidden@example.test','Reply-To: replies@example.test'],[$attachment]),'authenticated SMTP accepts HTML, recipients, and attachment');
check($events['success']===1,'WordPress success hook preserved');
check(!wp_mail('reject@example.test','Rejected','body'),'SMTP rejection returns false');
$row=History::rows()[0]; check($row['status']==='rejected' && $row['retryable'],'definitive SMTP failure retained encrypted for retry');
control(['smtp_reject'=>true]); check(!wp_mail('reader@example.test','Retry me','body'),'SMTP DATA rejection recorded'); $id=History::rows()[0]['id']; control(['smtp_reject'=>false]); $r=History::retry($id); check($r->status==='accepted','manual retry submits rejected message');
try { History::retry($id); throw new RuntimeException('duplicate retry'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'Only'),'accepted mail cannot be retried'); }
control(['smtp_drop'=>true]); check(!wp_mail('reader@example.test','Unknown','body'),'lost SMTP acknowledgement returns false'); check(History::rows()[0]['status']==='unknown' && !History::rows()[0]['retryable'],'ambiguous SMTP outcome cannot be retried'); control(['smtp_drop'=>false]);
$bad=$smtp; $bad['password']='wrong'; config('smtp',$bad); check(!wp_mail('reader@example.test','Bad auth','body'),'SMTP authentication failure'); config('smtp',$smtp);
foreach (['resend'=>['api_key'=>'test-resend'],'ses'=>['region'=>'us-east-1','access_key'=>'test-access','secret_key'=>'test-secret'],'gmail'=>$cloud] as $id=>$settings) {
    config($id,$settings);
    check(wp_mail('reader@example.test','Provider '.$id,'<p>provider body</p>',['Content-Type: text/html','Bcc: hidden@example.test','Reply-To: replies@example.test'],[$attachment]),$id.' direct API submission with attachment');
    control(['mail_status'=>429]); check(!wp_mail('reader@example.test','Rate limit','body'),$id.' rejection returns false'); check(History::rows()[0]['status']==='rejected',$id.' rate limit classified rejected');
    control(['mail_status'=>503]); check(!wp_mail('reader@example.test','Unknown provider','body'),$id.' server error returns false'); check(History::rows()[0]['status']==='unknown',$id.' uncertain server error cannot duplicate-send'); control(['mail_status'=>0]);
}
config('resend',['api_key'=>'test-resend']); $hide=static function($p){unset($p['resend']); return $p;}; add_filter('terrible_mail_transports',$hide,99); check(!wp_mail('reader@example.test','Unavailable','body'),'missing mail provider blocks send'); check(History::rows()[0]['status']==='rejected','missing provider recorded without SMTP fallback'); remove_filter('terrible_mail_transports',$hide,99);
config('smtp',$smtp); check(!wp_mail('invalid-address','Invalid','body'),'invalid recipients preserve WordPress failure behavior');
$raw=get_option('terrible_mail_settings'); check(!str_contains($raw,'test-password') && !str_contains($raw,'test-secret'),'stored mail credentials encrypted');
$messages=json_decode(wp_remote_retrieve_body(wp_remote_get('http://mock:8080/messages')),true)['messages'];
$smtpMessages=array_values(array_filter($messages,static fn($m)=>$m['path']==='smtp')); $mime=base64_decode($smtpMessages[0]['raw']); check(!preg_match('/^Bcc:/m',$mime) && str_contains($mime,'mail-fixture.txt'),'SMTP MIME preserves attachments without disclosing Bcc header');
$gmail=array_values(array_filter($messages,static fn($m)=>str_contains($m['path'],'gmail'))); $gmailRaw=base64_decode(strtr($gmail[0]['data']['raw'],'-_','+/')); check(str_contains($gmailRaw,'Bcc: hidden@example.test'),'Gmail submission includes hidden recipient for provider processing');
check($events['failure']>=10,'WordPress failure hooks preserved');
echo "ALL CONNECTIVITY CHECKS PASSED\n";
// Saved destinations and interrupted transfer replay use the same provider contracts.
$c=Transport::settings()['gdrive']; $c['prefix']=''; Transport::save(['gdrive'=>$c]); Transport::remember('gdrive','Independent drive');
$ids=array_keys(array_filter(Transport::destinations(),static fn($label)=>str_contains($label,'Independent drive'))); $saved=end($ids);
Transport::save(['gdrive'=>array_merge($c,['refresh_token'=>'changed-default'])]); check(Transport::connection($saved)['config']['refresh_token']==='test-refresh','saved storage connection independent from defaults'); Transport::save(['gdrive'=>$c]);
foreach (['dropbox','gdrive'] as $kind) {
    $j=Engine::remote($kind,'replay-'.wp_generate_uuid4().'.zip',$backup['id']); $j=Engine::step($j['id']);
    $checkpoint=$j; $checkpoint['offset']=0; \TerribleBackups\Support::json(Engine::dir($j['id']).'/job.json',$checkpoint);
    $j=Engine::step($j['id']); check($j['phase']!=='failed' && $j['offset']===Transport::CHUNK,$kind.' recovers the first chunk after lost local checkpoint');
    $before=$j; $j=Engine::step($j['id']); \TerribleBackups\Support::json(Engine::dir($j['id']).'/job.json',$before);
    $j=Engine::step($j['id']); check($j['phase']!=='failed' && $j['offset']>=$before['offset'],$kind.' replays an acknowledged chunk after lost local checkpoint');
    while ($j['offset']+Transport::CHUNK<$j['size']) $j=Engine::step($j['id']); $before=$j; $j=Engine::step($j['id']);
    \TerribleBackups\Support::json(Engine::dir($j['id']).'/job.json',$before); $j=Engine::step($j['id']); check($j['phase']==='done',$kind.' recovers a lost final acknowledgement without duplicate archive');
}
Settings::remember('Local SMTP'); $saved=array_key_last(Settings::connections()); config('resend',['api_key'=>'test-resend']); Settings::useConnection($saved); check(Settings::get()['transport']==='smtp','saved mail connection restores the chosen transport');
echo "ALL REPLAY AND CONNECTION CHECKS PASSED\n";
foreach (['tls'=>1025,'ssl'=>1465] as $security=>$port) { $c=$smtp; $c['security']=$security; $c['port']=(string)$port; config('smtp',$c); check(wp_mail('reader@example.test','Encrypted SMTP','TLS fixture'),$security.' SMTP succeeds with a trusted certificate'); }
$c['host']='mock-alt'; config('smtp',$c); check(!wp_mail('reader@example.test','Wrong certificate hostname','TLS fixture'),'SMTP rejects a certificate for the wrong hostname'); config('smtp',$smtp);
echo "ALL TLS CHECKS PASSED\n";
