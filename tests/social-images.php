<?php
use TerribleSocialImages\Plugin;
function tsi_check($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS $message\n"; }
$post=wp_insert_post(['post_title'=>'A test title','post_status'=>'publish','post_type'=>'post']);
tsi_check(!is_wp_error($post) && $post>0,'create post');
update_option(Plugin::OPTION,['backgrounds'=>[],'color'=>'#202838','text'=>'#ffffff','overlay'=>45,'enabled'=>1,'font'=>'bundled']);
$draft=wp_insert_post(['post_title'=>'Draft private title','post_status'=>'draft','post_type'=>'post']);
$before=count(get_children(['post_parent'=>$draft,'post_type'=>'attachment','post_status'=>'inherit']));
$preview=Plugin::generate($draft,'Unsaved preview title');
tsi_check(is_string($preview) && str_starts_with($preview,"\xff\xd8") && !get_post_meta($draft,Plugin::META,true) && $before===count(get_children(['post_parent'=>$draft,'post_type'=>'attachment','post_status'=>'inherit'])),'draft preview returns JPEG without publishing or creating an attachment');
$original_theme=get_stylesheet(); if (wp_get_theme('twentytwentythree')->exists()) { switch_theme('twentytwentythree'); tsi_check(count(Plugin::fonts())>1,'discovers local theme font'); switch_theme($original_theme); }
if (wp_get_theme('twentytwentyfive')->exists()) { switch_theme('twentytwentyfive'); $fonts=Plugin::fonts(); $site=Plugin::site_font($fonts); tsi_check($site==='font-manrope' && !empty($fonts[$site]['path']) && str_ends_with($fonts[$site]['path'],'.woff2'),'resolves the site-selected WOFF2 title font'); update_option(Plugin::OPTION,['backgrounds'=>[],'color'=>'#202838','text'=>'#ffffff','overlay'=>45,'enabled'=>1,'font'=>'site']); Plugin::generate($post); $site_image=(int)get_post_meta($post,Plugin::META,true); update_option(Plugin::OPTION,['backgrounds'=>[],'color'=>'#202838','text'=>'#ffffff','overlay'=>45,'enabled'=>1,'font'=>'bundled']); Plugin::generate($post); $bundled=(int)get_post_meta($post,Plugin::META,true); tsi_check($site_image!==$bundled && hash_file('sha256',get_attached_file($site_image))!==hash_file('sha256',get_attached_file($bundled)),'site font produces a visibly distinct render'); switch_theme($original_theme); }
if (wp_get_theme('twentytwentyfive')->exists()) {
    switch_theme('twentytwentyfive'); $fontdir=wp_font_dir(); tsi_check(empty($fontdir['error']) && copy(get_theme_file_path('assets/fonts/manrope/Manrope-VariableFont_wght.woff2'),$fontdir['basedir'].'/tsi-test.woff2'),'prepare Font Library fixture');
    $fixture=static function($data) use ($fontdir) { return $data->update_with(['version'=>3,'settings'=>['typography'=>['fontFamilies'=>[['name'=>'Custom Manrope','slug'=>'custom-manrope','fontFamily'=>'Custom Manrope, sans-serif','fontFace'=>[['fontFamily'=>'Custom Manrope','fontStyle'=>'normal','fontWeight'=>'400','src'=>[$fontdir['baseurl'].'/tsi-test.woff2']]]]]]], 'styles'=>['elements'=>['heading'=>['typography'=>['fontFamily'=>'var:preset|font-family|custom-manrope']]]]]); };
    add_filter('wp_theme_json_data_user',$fixture); wp_clean_theme_json_cache();
    $fonts=Plugin::fonts(); tsi_check(Plugin::site_font($fonts)==='font-custom-manrope' && !empty($fonts['font-custom-manrope']['path']),'user global styles select a local Font Library face');
    update_option(Plugin::OPTION,['backgrounds'=>[],'color'=>'#202838','text'=>'#ffffff','overlay'=>45,'enabled'=>1,'font'=>'site']); Plugin::generate($post); $library_image=(int)get_post_meta($post,Plugin::META,true); tsi_check($library_image>0,'renders the Font Library face');
    wp_delete_file($fontdir['basedir'].'/tsi-test.woff2'); Plugin::generate_scheduled($post); tsi_check(str_contains((string)get_post_meta($post,'_terrible_social_error',true),'not available') && (int)get_post_meta($post,Plugin::META,true)===$library_image,'missing site font reports a failure and keeps the card');
    remove_filter('wp_theme_json_data_user',$fixture); wp_clean_theme_json_cache(); switch_theme($original_theme);
}
update_option(Plugin::OPTION,['backgrounds'=>[],'color'=>'#202838','text'=>'#ffffff','overlay'=>45,'enabled'=>1,'font'=>'bundled']);
Plugin::generate($post); $first=(int)get_post_meta($post,Plugin::META,true);
tsi_check($first>0 && wp_attachment_is_image($first),'generated a media attachment');
tsi_check(\TerribleMetadata\Plugin::values($post)['image']===$first,'metadata selects generated attachment');
Plugin::generate($post); tsi_check((int)get_post_meta($post,Plugin::META,true)===$first,'unchanged input reuses attachment');
$lockdb=mysqli_connect(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME); tsi_check((bool)$lockdb,'open independent database session');
$lockname='tsi:'.get_current_blog_id().':'.$post; $lockresult=mysqli_query($lockdb,"SELECT GET_LOCK('".mysqli_real_escape_string($lockdb,$lockname)."',0)"); tsi_check((int)mysqli_fetch_row($lockresult)[0]===1,'hold generation lock in second session');
$blocked=false; try { Plugin::generate($post); } catch (RuntimeException $e) { $blocked=str_contains($e->getMessage(),'already running'); }
tsi_check($blocked && (int)get_post_meta($post,Plugin::META,true)===$first,'concurrent generation fails safely without replacing the card');
mysqli_query($lockdb,"SELECT RELEASE_LOCK('".mysqli_real_escape_string($lockdb,$lockname)."')"); mysqli_close($lockdb);
update_post_meta($post,'_terrible_social_title','Input before render');
$change=static function($id) use ($post) { if ($id===$post) update_post_meta($id,'_terrible_social_title','Changed during render'); };
add_action('terrible_social_images_before_commit',$change); $stale=false; try { Plugin::generate($post); } catch (RuntimeException $e) { $stale=str_contains($e->getMessage(),'inputs changed'); } remove_action('terrible_social_images_before_commit',$change);
tsi_check($stale && (int)get_post_meta($post,Plugin::META,true)===$first,'stale render cannot replace the existing card');
$provisional=0; $change_late=static function($id,$attachment) use ($post,&$provisional) { if ($id===$post) { $provisional=$attachment; update_post_meta($id,'_terrible_social_title','Changed after attachment'); } };
add_action('terrible_social_images_after_attachment',$change_late,10,2); $stale=false; try { Plugin::generate($post); } catch (RuntimeException $e) { $stale=str_contains($e->getMessage(),'inputs changed'); } remove_action('terrible_social_images_after_attachment',$change_late);
tsi_check($stale && $provisional && !get_post($provisional) && (int)get_post_meta($post,Plugin::META,true)===$first,'late stale render deletes its provisional attachment and preserves the current card');
update_post_meta($post,'_terrible_meta_image',$first); update_post_meta($post,'_terrible_social_title','Changed title'); Plugin::generate($post);
$second=(int)get_post_meta($post,Plugin::META,true);
tsi_check($second!==$first && \TerribleMetadata\Plugin::values($post)['image']===$first,'explicit metadata image wins');
update_option(Plugin::OPTION,['backgrounds'=>[$first],'color'=>'#202838','text'=>'#ffffff','overlay'=>45,'enabled'=>1,'font'=>'bundled']);
Plugin::generate($post); $third=(int)get_post_meta($post,Plugin::META,true); tsi_check($third!==$second,'background is rendered');
$path=get_attached_file($first); $url='https://example.com/tsi-background.jpg';
add_filter('get_attached_file',function($file,$id) use ($first){return $id===$first?'/missing/offloaded.jpg':$file;},10,2);
add_filter('wp_get_attachment_url',function($value,$id) use ($first,$url){return $id===$first?$url:$value;},10,2);
add_filter('pre_http_request',function($pre,$args,$request) use ($url,$path){if ($request!==$url) return $pre; copy($path,$args['filename']); return ['response'=>['code'=>200,'message'=>'OK'],'headers'=>[],'body'=>'','cookies'=>[]];},10,3);
update_post_meta($post,'_terrible_social_title','Offloaded background'); Plugin::generate($post);
tsi_check((int)get_post_meta($post,Plugin::META,true)!==$third,'offloaded public background is retrieved');
$previous=(int)get_post_meta($post,Plugin::META,true); update_post_meta($post,'_terrible_social_title',str_repeat('W',1000)); Plugin::generate_scheduled($post);
tsi_check((int)get_post_meta($post,Plugin::META,true)===$previous && get_post_meta($post,'_terrible_social_error',true)!=='','render failure preserves previous card');
echo "SOCIAL IMAGE TESTS PASSED\n";
