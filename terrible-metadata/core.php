<?php
namespace TerribleMetadata;
defined('ABSPATH') || exit;
final class Plugin {
    public static function install(): void {}
    public static function boot(): void {
        add_action('admin_enqueue_scripts',static function($hook){if (in_array($hook,['post.php','post-new.php'],true) || ($_GET['page']??'')==='terrible-metadata') { wp_enqueue_media(); wp_enqueue_style('terrible-tools',plugins_url('shared/admin.css',__FILE__),[],'0.2.0'); wp_enqueue_script('terrible-metadata',plugins_url('media.js',__FILE__),['media-views'],'0.2.0',true); }});
        UI::boot('terrible-metadata','Terrible Metadata',[self::class,'page'],[self::class,'action']);
        add_action('add_meta_boxes',static function(){foreach (get_post_types(['public'=>true]) as $type) if ($type!=='attachment') add_meta_box('terrible-metadata','Search & sharing',[self::class,'box'],$type,'normal');});
        add_action('save_post',[self::class,'save'],10,2); add_action('wp_head',[self::class,'head'],5);
        add_filter('pre_get_document_title',static function($title){ if (self::active() && is_singular()) { $custom=get_post_meta(get_queried_object_id(),'_terrible_meta_title',true); if ($custom) return $custom; } return $title; });
    }
    public static function active(): bool { return (bool)apply_filters('terrible_metadata_enabled',!defined('WPSEO_VERSION') && !defined('RANK_MATH_VERSION') && !defined('AIOSEO_VERSION')); }
    public static function values(int $id): array {
        $post=get_post($id); if (!$post) return [];
        $description=get_post_meta($id,'_terrible_meta_description',true);
        if (!$description) { $text=$post->post_excerpt?:strip_shortcodes($post->post_content); $description=mb_substr(trim(preg_replace('/\s+/u',' ',wp_strip_all_tags($text))),0,180); }
        $image=(int)get_post_meta($id,'_terrible_meta_image',true)?:get_post_thumbnail_id($id)?: (int)get_option('terrible_metadata_image',0);
        return ['title'=>get_post_meta($id,'_terrible_meta_title',true)?:get_the_title($id),'description'=>$description,'image'=>$image,'url'=>get_permalink($id)];
    }
    public static function head(): void {
        if (!self::active() || !is_singular() || is_preview() || post_password_required()) return; $id=get_queried_object_id(); if (get_post_status($id)!=='publish') return; $v=self::values($id);
        echo '<!-- Terrible Metadata -->' . "\n";
        foreach (['description'=>$v['description']] as $name=>$content) if ($content!=='') echo '<meta name="'.esc_attr($name).'" content="'.esc_attr($content).'">'."\n";
        $props=['og:title'=>$v['title'],'og:description'=>$v['description'],'og:url'=>$v['url'],'og:type'=>get_post_type($id)==='post'?'article':'website','og:site_name'=>get_bloginfo('name')];
        $img=$v['image']?wp_get_attachment_image_src($v['image'],'full'):false; if ($img) $props+=['og:image'=>$img[0],'og:image:width'=>$img[1],'og:image:height'=>$img[2],'og:image:alt'=>get_post_meta($v['image'],'_wp_attachment_image_alt',true)];
        foreach ($props as $name=>$content) if ($content!=='') echo '<meta property="'.esc_attr($name).'" content="'.esc_attr((string)$content).'">'."\n";
        echo '<meta name="twitter:card" content="'.($img?'summary_large_image':'summary').'">'."\n";
    }
    public static function save(int $id,$post): void {
        if (!isset($_POST['terrible_metadata_nonce']) || !wp_verify_nonce($_POST['terrible_metadata_nonce'],'terrible-metadata') || !current_user_can('edit_post',$id) || wp_is_post_revision($id) || wp_is_post_autosave($id)) return;
        foreach (['title','description'] as $field) { $value=sanitize_text_field(wp_unslash($_POST['terrible_meta_'.$field]??'')); update_post_meta($id,'_terrible_meta_'.$field,mb_substr($value,0,$field==='title'?300:1000)); }
        $image=absint($_POST['terrible_meta_image']??0); if (!$image || wp_attachment_is_image($image)) update_post_meta($id,'_terrible_meta_image',$image);
    }
    public static function box($post): void { require __DIR__.'/box.php'; }
    public static function imageControl(string $field,int $id): void {
        $url=$id ? wp_get_attachment_image_url($id,'medium') : '';
        $post=get_post(); $fallback=$post ? ((int)get_post_thumbnail_id($post->ID) ?: (int)get_option('terrible_metadata_image',0)) : 0;
        echo '<div class="terrible-image-control" data-fallback="'.esc_url($fallback ? wp_get_attachment_image_url($fallback,'medium') : '').'"><input type="hidden" name="'.esc_attr($field).'" value="'.$id.'"><img class="tt-media-preview" alt="Selected sharing image" src="'.esc_url($url ?: '').'" '.(!$url?'hidden':'').'><p><button type="button" class="button terrible-image-picker" data-field="'.esc_attr($field).'">Choose image</button> <button type="button" class="button terrible-image-remove" '.(!$id?'hidden':'').'>Use fallback</button></p></div>';
    }
    public static function action(string $op,array $d): string { if ($op!=='save') throw new \RuntimeException('Unknown operation.'); $id=(int)($d['image']??0); if ($id && !wp_attachment_is_image($id)) throw new \RuntimeException('Choose an image attachment.'); update_option('terrible_metadata_image',$id,false); return 'Default image saved.'; }
    public static function page(): void { require __DIR__.'/page.php'; }
}
