<?php
wp_set_current_user(1);
$slug='http-fixtures'; $post=get_page_by_path($slug,OBJECT,'page'); $form=wp_insert_post(['ID'=>$post?$post->ID:0,'post_type'=>'page','post_name'=>$slug,'post_title'=>'HTTP Form fixture','post_status'=>'publish','post_content'=>'[contact]']);
$post=wp_insert_post(['post_type'=>'post','post_title'=>'HTTP Metadata fixture','post_status'=>'publish','post_content'=>'<p>Public metadata fixture.</p>']); update_post_meta($post,'_terrible_meta_title','Metadata "quoted" title'); update_post_meta($post,'_terrible_meta_description','A <script> label & description.');
$u=get_user_by('login','http-subscriber'); if (!$u) $id=wp_create_user('http-subscriber','http-test-password','http-subscriber@example.test'); else $id=$u->ID; $user=new WP_User($id); $user->set_role('subscriber');
$factor=get_user_by('login','http-factor'); $fid=$factor?$factor->ID:wp_create_user('http-factor','http-factor-password','http-factor@example.test');
$secret=\TerribleLogin\Plugin::secret(); $codes=\TerribleLogin\Plugin::enroll($fid,$secret,\TerribleLogin\Plugin::code($secret,intdiv(time(),30))); delete_transient('tl_attempts_'.$fid);
$uploads=wp_upload_dir(); wp_mkdir_p($uploads['path']); $path=$uploads['path'].'/http-media.png'; $image=imagecreatetruecolor(40,40); imagepng($image,$path); imagedestroy($image); $attachment=wp_insert_attachment(['post_title'=>'HTTP Media fixture','post_mime_type'=>'image/png','post_status'=>'inherit'],$path); require_once ABSPATH.'wp-admin/includes/image.php'; wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$path));
file_put_contents('/tmp/http-fixtures.json',wp_json_encode(['form'=>$form,'post'=>$post,'factor_code'=>$codes[0],'media'=>$attachment,'media_url'=>wp_get_attachment_url($attachment)]));
echo "HTTP fixtures ready.\n";
