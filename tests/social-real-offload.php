<?php
use TerribleSocialImages\Plugin;
function check_offload($ok,$what) { if (!$ok) throw new RuntimeException($what); echo "PASS $what\n"; }
update_option(Plugin::OPTION,['backgrounds'=>[],'color'=>'#202838','text'=>'#ffffff','overlay'=>45,'enabled'=>1,'font'=>'bundled']);
$image=imagecreatetruecolor(1200,630); imagefill($image,0,0,imagecolorallocate($image,120,80,50)); ob_start(); imagejpeg($image); $bytes=ob_get_clean(); imagedestroy($image);
$up=wp_upload_bits('offload-background.jpg',null,$bytes); check_offload(empty($up['error']),'upload fixture');
$bg=wp_insert_attachment(['post_mime_type'=>'image/jpeg','post_title'=>'Offload background','post_status'=>'inherit'],$up['file'],0,true);
require_once ABSPATH.'wp-admin/includes/image.php'; wp_update_attachment_metadata($bg,wp_generate_attachment_metadata($bg,$up['file']));
$url=as3cf_get_attachment_url($bg);
check_offload(is_string($url) && str_contains($url,'minio:9000'),'real plugin offloads background');
check_offload(!is_readable($up['file']),'real plugin removed local background');
update_option(Plugin::OPTION,['backgrounds'=>[$bg],'color'=>'#202838','text'=>'#ffffff','overlay'=>45,'enabled'=>1,'font'=>'bundled']);
$post=wp_insert_post(['post_title'=>'Real offload proof','post_status'=>'publish','post_type'=>'post']);
Plugin::generate($post); $card=(int)get_post_meta($post,Plugin::META,true); $card_url=as3cf_get_attachment_url($card);
check_offload($card && is_string($card_url) && str_contains($card_url,'minio:9000'),'generated attachment is offloaded by real plugin');
$local_card=trailingslashit(wp_get_upload_dir()['basedir']).get_post_meta($card,'_wp_attached_file',true);
check_offload(!is_readable($local_card),'real plugin removed local generated card');
check_offload(\TerribleMetadata\Plugin::values($post)['image']===$card,'Metadata selects offloaded card');
$rendered=wp_get_attachment_image_src($card,'full'); $response=wp_safe_remote_get($rendered[0]);
check_offload(!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200 && str_starts_with(wp_remote_retrieve_body($response),"\xff\xd8"),'offloaded card URL returns JPEG bytes');
echo "REAL OFFLOAD TESTS PASSED\n";
