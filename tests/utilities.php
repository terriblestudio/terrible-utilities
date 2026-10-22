<?php
function check($ok,$label) { if (!$ok) throw new RuntimeException('FAIL: '.$label); echo 'PASS: '.$label."\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch (Throwable $e) { echo 'PASS: '.$label."\n"; return; } throw new RuntimeException('FAIL: '.$label); }
wp_set_current_user(1);
foreach (['192.0.2.10','192.0.2.11','192.0.2.12','192.0.2.100'] as $ip) delete_transient('tf_rate_'.hash_hmac('sha256',$ip,wp_salt('nonce')));
use TerribleRedirects\Plugin as Redirects;
use TerribleHistory\Plugin as History;
use TerribleMedia\Plugin as Media;
use TerribleForms\Plugin as Forms;
use TerribleLogin\Plugin as Login;
use TerribleMetadata\Plugin as Metadata;
use TerribleLinks\Plugin as Links;
use TerribleRevisions\Plugin as Revisions;
Redirects::save('/old-fixture/','/new-fixture/',301); check(Redirects::rules()['/old-fixture/']['target']==='/new-fixture/','redirect stored');
rejects(static fn()=>Redirects::save('/new-fixture/','/old-fixture/',301),'redirect cycle rejected');
foreach (['//evil.example','/wp-admin/','/bad%0dpath','/bad\\path'] as $path) rejects(static fn()=>Redirects::save($path,'/safe/',301),'unsafe redirect source rejected');
rejects(static fn()=>Redirects::save('/x/','javascript:alert(1)',302),'non-HTTP redirect target rejected');
for($i=0;$i<205;$i++) Redirects::missing('/bounded-'.$i); check(count(get_option('terrible_redirects_404'))===200,'404 report bounded at 200 paths');
$upload=wp_upload_dir(); wp_mkdir_p($upload['path']); $path=$upload['path'].'/replace-fixture.png'; $image=imagecreatetruecolor(80,60); $red=imagecolorallocate($image,255,0,0); imagefill($image,0,0,$red); imagepng($image,$path); imagedestroy($image);
$attachment=wp_insert_attachment(['post_title'=>'Replace fixture','post_mime_type'=>'image/png','post_status'=>'inherit'],$path); require_once ABSPATH.'wp-admin/includes/image.php'; wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$path)); $oldHash=hash_file('sha256',$path); $url=wp_get_attachment_url($attachment);
$new=wp_tempnam('replacement.png'); $image=imagecreatetruecolor(100,70); imagepng($image,$new); imagedestroy($image);
$reference=wp_insert_post(['post_title'=>'Media reference','post_status'=>'publish','post_content'=>'<img class="wp-image-'.$attachment.'" src="'.$url.'">']); set_post_thumbnail($reference,$attachment);
check(in_array($reference,Media::usage($attachment),true),'media usage finds content and featured-image references');
Media::replace($attachment,$new,'replace-fixture.png'); check(hash_file('sha256',$path)!==$oldHash && wp_get_attachment_url($attachment)===$url,'media replacement keeps URL and attachment'); check(wp_get_attachment_metadata($attachment)['width']===100,'media metadata regenerated');
Media::undo($attachment); check(hash_file('sha256',$path)===$oldHash && wp_get_attachment_metadata($attachment)['width']===80,'media undo restores original bytes and metadata');
file_put_contents($new,'<?php evil();'); rejects(static fn()=>Media::replace($attachment,$new,'replace-fixture.png'),'disguised executable upload rejected'); unlink($new);

check(Login::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',1)==='287082','TOTP matches RFC 6238 SHA1 test vector (six digits)');
$user=wp_create_user('twofactor-'.wp_generate_password(6,false),'fixture-password','factor-'.wp_generate_password(6,false).'@example.test'); $secret=Login::secret(); $codes=Login::enroll($user,$secret,Login::code($secret,intdiv(time(),30)));
check(Login::enabled($user) && count($codes)===10,'authenticator enrollment creates ten recovery codes'); check(!str_contains(get_user_meta($user,'terrible_login_secret',true),$secret),'authenticator secret encrypted');
check(!Login::verify($user,Login::code($secret,intdiv(time(),30))),'enrollment code cannot be replayed'); check(Login::verify($user,$codes[0]) && !Login::verify($user,$codes[0]),'recovery code works exactly once');
$_POST['terrible_code']='bad'; $result=wp_authenticate(get_userdata($user)->user_login,'fixture-password'); check(is_wp_error($result),'password alone cannot authenticate enrolled user');
$_POST['terrible_code']=$codes[1]; $result=wp_authenticate(get_userdata($user)->user_login,'fixture-password'); check($result instanceof WP_User,'password and recovery code authenticate'); unset($_POST['terrible_code']);
check(!wp_is_application_passwords_available_for_user(get_userdata($user)),'application passwords cannot bypass protected account policy');
for($i=0;$i<5;$i++) Login::authenticate(get_userdata($user),'',''); $_POST['terrible_code']=$codes[2]; check(Login::authenticate(get_userdata($user),'','')->get_error_code()==='terrible_login_limited','two-factor attempts are bounded'); unset($_POST['terrible_code']);

$post=wp_insert_post(['post_title'=>'Original live title','post_content'=>'<!-- wp:paragraph --><p>Original body <a href="https://example.test/gone">old link</a></p><!-- /wp:paragraph -->','post_excerpt'=>'Useful excerpt','post_status'=>'publish']);
update_post_meta($post,'_terrible_meta_title','A social title'); update_post_meta($post,'_terrible_meta_description','A "quoted" <script> text'); update_post_meta($post,'_terrible_meta_image',$attachment);
$v=Metadata::values($post); check($v['title']==='A social title' && $v['image']===$attachment,'metadata overrides and image selection');
$draft=Revisions::create($post); wp_update_post(['ID'=>$draft,'post_title'=>'Replacement title','post_content'=>'<p>Replacement body</p>']); check(get_post($post)->post_title==='Original live title','preparing a revision leaves published content unchanged');
wp_update_post(['ID'=>$draft,'post_status'=>'publish']); check(get_post_status($draft)==='draft','draft replacement cannot be accidentally published as a separate post');
$permalink=get_permalink($post); Revisions::apply($draft); check(get_post($post)->post_title==='Replacement title' && get_permalink($post)===$permalink,'applying replacement retains live URL');
$conflict=Revisions::create($post); wp_update_post(['ID'=>$post,'post_content'=>'Concurrent live edit']); rejects(static fn()=>Revisions::apply($conflict),'live content conflict prevents overwritten edits');
$schedule=Revisions::create($post); wp_update_post(['ID'=>$schedule,'post_title'=>'Scheduled title']); Revisions::schedule($schedule,time()+120); check((bool)wp_next_scheduled('terrible_revisions_publish',[$schedule]),'revision scheduled through WordPress cron'); Revisions::scheduled($schedule); check(get_post($post)->post_title==='Scheduled title','scheduled replacement publishes as authorized user');

$linkpost=wp_insert_post(['post_title'=>'Link fixture','post_status'=>'publish','post_content'=>'<!-- wp:paragraph --><p><a href="https://example.test/gone">Old</a><a href="mailto:hello@example.test">Email</a><a href="#section">Anchor</a></p><!-- /wp:paragraph -->']);
$urls=Links::urls(get_post($linkpost)->post_content,$linkpost); check($urls===['https://example.test/gone'],'link extraction skips mail and fragment-only links'); update_option('terrible_links_cursor',0); do {$count=Links::scan();} while ($count===20);
global $wpdb; $ref=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Links::table().' WHERE post_id=%d',$linkpost)); check($ref>0,'link inventory indexes published content');
Links::replace($ref,'https://example.test/new'); check(str_contains(get_post($linkpost)->post_content,'https://example.test/new') && count(wp_get_post_revisions($linkpost))>=1,'link replacement preserves a WordPress revision');
check(Links::check('http://169.254.169.254/latest/meta-data/')['status']==='unverified','link checker refuses private metadata endpoint');
check(Links::check('file:///etc/passwd')['status']==='unverified','link checker refuses non-HTTP schemes');
// HTTP protocol behavior is isolated without making a public network request.
$http=static fn($pre,$args,$url)=>['response'=>['code'=>str_contains($url,'gone')?410:(str_contains($url,'blocked')?403:200)],'body'=>'','headers'=>[]]; add_filter('pre_http_request',$http,10,3);
check(Links::check('https://example.test/gone')['status']==='broken','confirmed 410 is marked broken'); check(Links::check('https://example.test/blocked')['status']==='unverified','403 is not mislabeled as a dead link'); remove_filter('pre_http_request',$http,10);
wp_set_current_user($user); rejects(static fn()=>Media::replace($attachment,'/does/not/exist','x.png'),'non-editor cannot replace media'); rejects(static fn()=>Revisions::create($post),'non-editor cannot create replacement drafts'); wp_set_current_user(1);
$allow=static fn($external,$host)=>$host==='mock'?true:$external; $ports=static fn($ports)=>array_merge($ports,[8080]); add_filter('http_request_host_is_external',$allow,10,2); add_filter('http_allowed_safe_ports',$ports);
check(Links::check('http://mock:8080/links/gone')['status']==='broken','Docker HTTP fixture confirms dead-link response');
check(Links::check('http://mock:8080/links/blocked')['status']==='unverified','Docker HTTP fixture preserves blocked-link uncertainty');
check(Links::check('http://mock:8080/links/head-not-supported')['status']==='working','link checker falls back from HEAD 405 to bounded GET');
check(Links::check('http://mock:8080/links/redirect-private')['status']==='unverified','redirect into private metadata endpoint is blocked');
remove_filter('http_request_host_is_external',$allow,10); remove_filter('http_allowed_safe_ports',$ports);
echo "ALL UTILITY CHECKS PASSED\n";
