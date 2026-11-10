<?php
namespace TerribleSocialImages;
defined('ABSPATH') || exit;

final class Plugin {
    const OPTION = 'terrible_social_images_settings';
    const META = '_terrible_social_image';

    public static function boot(): void {
        \TerribleTools\Admin::register('terrible-social-images', 'Social images', [self::class, 'page']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_terrible_social_images_settings', [self::class, 'save_settings']);
        add_action('admin_post_terrible_social_images_generate', [self::class, 'manual_generate']);
        add_action('wp_ajax_terrible_social_images_preview', [self::class, 'preview']);
        add_action('wp_ajax_terrible_social_images_bulk', [self::class, 'bulk']);
        add_action('add_meta_boxes', [self::class, 'boxes']);
        add_action('save_post', [self::class, 'save_post'], 100, 2);
        add_action('terrible_social_images_generate', [self::class, 'generate_scheduled']);
        add_filter('terrible_metadata_generated_image', [self::class, 'metadata_image'], 10, 2);
        add_action('wp_head', [self::class, 'head'], 6);
    }

    public static function settings(): array {
        return wp_parse_args(get_option(self::OPTION, []), ['backgrounds'=>[], 'color'=>'#202838', 'text'=>'#ffffff', 'overlay'=>45, 'enabled'=>0, 'font'=>'site']);
    }

    public static function fonts(): array {
        $fonts=['bundled'=>['name'=>'Inter (bundled)', 'path'=>__DIR__.'/assets/Inter.ttf']];
        $groups=wp_get_global_settings(['typography','fontFamilies']);
        if (!is_array($groups)) return $fonts;
        foreach (['default','theme','custom'] as $origin) foreach (($groups[$origin]??[]) as $family) {
            $slug=sanitize_key($family['slug']??''); if (!$slug || empty($family['fontFace']) || !is_array($family['fontFace'])) continue;
            $faces=$family['fontFace'];
            usort($faces, static function($a,$b) { $score=static function($face) { $weight=(string)($face['fontWeight']??'400'); preg_match_all('/\d+/',$weight,$matches); $range=array_map('intval',$matches[0]); $distance=count($range)>1 && $range[0]<=400 && $range[1]>=400 ? 0 : abs(($range[0]??400)-400); return ($face['fontStyle']??'normal')==='normal' ? $distance : 10000+$distance; }; return $score($a)<=>$score($b); });
            $path=''; foreach ($faces as $face) { if (($face['fontStyle']??'normal')!=='normal') continue; foreach ((array)($face['src']??[]) as $src) { $candidate=self::font_path($src); if ($candidate && function_exists('imagettfbbox') && @imagettfbbox(12,0,$candidate,'Test')!==false) { $path=$candidate; break 2; } } }
            $fonts['font-'.$slug]=['name'=>(string)($family['name']??$slug), 'path'=>$path,'family'=>(string)($family['fontFamily']??'')];
        }
        return $fonts;
    }

    private static function font_path($src): string {
        if (!is_string($src)) return '';
        $roots=[];
        if (str_starts_with($src,'file:./')) { $relative=substr($src,7); $roots[]=[get_stylesheet_directory(),$relative]; $roots[]=[get_template_directory(),$relative]; }
        else {
            $dirs=[[get_stylesheet_directory_uri(),get_stylesheet_directory()],[get_template_directory_uri(),get_template_directory()]];
            if (function_exists('wp_get_font_dir')) { $dir=wp_get_font_dir(); if (empty($dir['error'])) $dirs[]=[$dir['baseurl'],$dir['basedir']]; }
            foreach ($dirs as [$baseurl,$root]) { $prefix=rtrim($baseurl,'/').'/'; if (str_starts_with($src,$prefix)) $roots[]=[$root,rawurldecode(substr($src,strlen($prefix)))]; }
        }
        foreach ($roots as [$root,$relative]) { $base=realpath($root); $file=realpath($root.'/'.$relative); if ($base && $file && str_starts_with($file,$base.DIRECTORY_SEPARATOR) && is_file($file) && is_readable($file) && filesize($file)<=20*1024*1024) return $file; }
        return '';
    }

    public static function site_font(array $fonts): string {
        $styles=wp_get_global_styles();
        $value=''; foreach ([$styles['blocks']['core/post-title']['typography']['fontFamily']??'', $styles['elements']['h1']['typography']['fontFamily']??'', $styles['elements']['heading']['typography']['fontFamily']??'', $styles['typography']['fontFamily']??''] as $candidate) if (is_string($candidate) && trim($candidate)!=='' && !in_array(strtolower(trim($candidate)),['inherit','unset','initial'],true)) { $value=$candidate; break; }
        if (preg_match('/^(?:var\(--wp--preset--font-family--([a-z0-9-]+)\)|var:preset\|font-family\|([a-z0-9-]+))$/i',trim((string)$value),$match)) { $key='font-'.($match[1]?:$match[2]); return isset($fonts[$key])?$key:''; }
        foreach ($fonts as $key=>$font) if (!empty($font['family']) && strcasecmp(trim($font['family']),trim((string)$value))===0) return $key;
        return '';
    }

    public static function assets(): void {
        if (in_array($GLOBALS['pagenow']??'', ['post.php','post-new.php'],true)) { wp_enqueue_script('terrible-social-preview',plugins_url('preview.js',__FILE__),[], '0.1.0',true); return; }
        if (($_GET['page'] ?? '') !== 'terrible-social-images') return;
        wp_enqueue_media();
        wp_enqueue_script('terrible-social-images', plugins_url('settings.js', __FILE__), ['jquery','media-views'], '0.1.0', true);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        \TerribleTools\Admin::header('Social images', 'Create title cards for shared links.');
        if ($notice = get_transient('terrible_social_notice_'.get_current_user_id())) { delete_transient('terrible_social_notice_'.get_current_user_id()); echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>'; }
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) echo '<div class="notice notice-error"><p>PHP GD with FreeType is required to render cards.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="terrible_social_images_settings">'; wp_nonce_field('terrible_social_images_settings');
        echo '<p><label><input type="checkbox" name="enabled" value="1" '.checked($s['enabled'],1,false).'> Generate cards for published posts and pages</label></p>';
        echo '<p>Select one or more Media Library images. Each post keeps its chosen background until it is removed from the collection. Leave empty for a solid background.</p><input type="hidden" name="backgrounds" id="tsi-backgrounds" value="'.esc_attr(implode(',',array_map('absint',$s['backgrounds']))).'"><button class="button" type="button" id="tsi-pick">Choose backgrounds</button> <span id="tsi-count">'.esc_html(count($s['backgrounds'])).' selected</span>';
        echo '<p><label>Background color <input id="tsi-color" type="color" name="color" value="'.esc_attr($s['color']).'"></label> <label>Text color <input type="color" name="text" value="'.esc_attr($s['text']).'"></label></p>';
        $palette=function_exists('wp_get_global_settings') ? wp_get_global_settings(['color','palette']) : []; if (is_array($palette)) { echo '<p>Theme colors: '; foreach ($palette as $group) if (is_array($group)) foreach ($group as $entry) { $hex=sanitize_hex_color($entry['color']??''); if ($hex && strlen($hex)===7) echo '<button type="button" class="button tsi-swatch" data-color="'.esc_attr($hex).'">'.esc_html($entry['name']??$hex).'</button> '; } echo '</p>'; }
        echo '<p><label>Dark overlay over images <input type="number" min="0" max="90" name="overlay" value="'.esc_attr($s['overlay']).'">%</label></p>';
        $fonts=self::fonts(); $site=self::site_font($fonts);
        echo '<p><label>Card font <select name="font"><option value="site" '.selected($s['font'],'site',false).'>Site title font'.esc_html($site?' — '.$fonts[$site]['name'].(empty($fonts[$site]['path'])?' (unavailable)':''):' — unavailable').'</option>'; foreach ($fonts as $key=>$font) echo '<option value="'.esc_attr($key).'" '.selected($s['font'],$key,false).' '.disabled(empty($font['path']),true,false).'>'.esc_html($font['name'].(empty($font['path'])?' — unavailable on this server':'')).'</option>'; echo '</select></label></p><p>Uses fonts registered in WordPress global styles and the Font Library when a local face is supported by this server. If the site font is unavailable, choose an available font.</p>'; submit_button('Save settings'); echo '</form><p>Open a post or page to preview or regenerate its card. To apply saved settings to existing posts and pages, run generation below. Existing manual sharing images are never changed.</p><p><button type="button" class="button" id="tsi-bulk" data-url="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('terrible_social_bulk')).'">Generate / update all cards</button></p><p id="tsi-bulk-status" role="status" aria-live="polite"></p></div>';
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('terrible_social_images_settings');
        $ids = array_values(array_unique(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash($_POST['backgrounds'] ?? '')))))));
        $ids = array_values(array_filter($ids, 'wp_attachment_is_image'));
        $font=sanitize_key(wp_unslash($_POST['font']??'site')); if ($font!=='site' && (!isset(self::fonts()[$font]) || !self::fonts()[$font]['path'])) $font='bundled';
        update_option(self::OPTION, ['backgrounds'=>$ids, 'color'=>sanitize_hex_color(wp_unslash($_POST['color'] ?? '')) ?: '#202838', 'text'=>sanitize_hex_color(wp_unslash($_POST['text'] ?? '')) ?: '#ffffff', 'overlay'=>max(0,min(90,absint($_POST['overlay'] ?? 45))), 'enabled'=>empty($_POST['enabled'])?0:1,'font'=>$font], false);
        self::notice('Settings saved.');
        wp_safe_redirect(\TerribleTools\Admin::url('terrible-social-images')); exit;
    }

    public static function boxes(): void {
        foreach (['post','page'] as $type) add_meta_box('terrible-social-images', 'Social image', [self::class,'box'], $type, 'side');
    }
    public static function box($post): void {
        wp_nonce_field('terrible_social_images_post','terrible_social_images_nonce');
        $title = get_post_meta($post->ID, '_terrible_social_title', true);
        echo '<p><label>Card title (optional)<input type="text" class="widefat" name="terrible_social_title" value="'.esc_attr($title).'"></label></p>';
        $id = (int)get_post_meta($post->ID,self::META,true);
        if ($id && wp_attachment_is_image($id)) echo wp_get_attachment_image($id,'medium',false,['style'=>'max-width:100%;height:auto']);
        $error = get_post_meta($post->ID,'_terrible_social_error',true); if ($error) echo '<p>'.esc_html($error).'</p>';
        echo '<p><button type="button" class="button tsi-preview" data-post="'.esc_attr($post->ID).'" data-nonce="'.esc_attr(wp_create_nonce('terrible_social_preview_'.$post->ID)).'" data-url="'.esc_url(admin_url('admin-ajax.php')).'">Preview current title</button></p><p class="tsi-preview-status" role="status" aria-live="polite"></p><img class="tsi-preview-image" alt="Social image preview" style="max-width:100%;height:auto" hidden>';
        echo '<p>Preview uses saved site settings. Save the post to update its published card.</p>';
        if ($post->post_status === 'publish') { $url=wp_nonce_url(admin_url('admin-post.php?action=terrible_social_images_generate&post='.$post->ID),'terrible_social_images_generate_'.$post->ID); echo '<p><a class="button" href="'.esc_url($url).'">Regenerate card</a></p>'; }
    }
    public static function save_post(int $id, $post): void {
        if (wp_is_post_revision($id) || wp_is_post_autosave($id) || !in_array($post->post_type,['post','page'],true)) return;
        if (isset($_POST['terrible_social_images_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['terrible_social_images_nonce'])),'terrible_social_images_post') && current_user_can('edit_post',$id)) update_post_meta($id,'_terrible_social_title',sanitize_text_field(wp_unslash($_POST['terrible_social_title'] ?? '')));
        if ($post->post_status !== 'publish' || $post->post_password !== '') return;
        global $wpdb; $lock='tsi:'.get_current_blog_id().':'.$id;
        $generation_running=(int)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)',$lock))>0;
        if (!self::settings()['enabled'] && !$generation_running) return;
        if (!wp_next_scheduled('terrible_social_images_generate',[$id])) wp_schedule_single_event(time()+5,'terrible_social_images_generate',[$id]);
    }
    public static function manual_generate(): void {
        $id=absint($_GET['post']??0); if (!current_user_can('edit_post',$id)) wp_die('Forbidden','',['response'=>403]); check_admin_referer('terrible_social_images_generate_'.$id);
        self::generate_scheduled($id); wp_safe_redirect(get_edit_post_link($id,'url')); exit;
    }
    public static function generate_scheduled(int $id): void {
        try { self::generate($id); delete_post_meta($id,'_terrible_social_error'); }
        catch (\Throwable $e) { update_post_meta($id,'_terrible_social_error',$e->getMessage()); if (in_array($e->getMessage(),['Card generation is already running.','Card inputs changed during generation.'],true) && !wp_next_scheduled('terrible_social_images_generate',[$id])) wp_schedule_single_event(time()+30,'terrible_social_images_generate',[$id]); }
    }
    public static function bulk(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Administrator access required.'],403);
        if (!check_ajax_referer('terrible_social_bulk','nonce',false)) wp_send_json_error(['message'=>'Session expired. Reload the page.'],403);
        global $wpdb; $cursor=absint($_POST['cursor']??0);
        $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('post','page') AND post_status='publish' AND post_password='' ORDER BY ID LIMIT 2",$cursor));
        $more=count($ids)>1; $ids=array_slice($ids,0,1); $errors=[];
        foreach ($ids as $id) { $id=(int)$id; try { self::generate($id); delete_post_meta($id,'_terrible_social_error'); } catch (\Throwable $e) { update_post_meta($id,'_terrible_social_error',$e->getMessage()); if (in_array($e->getMessage(),['Card generation is already running.','Card inputs changed during generation.'],true) && !wp_next_scheduled('terrible_social_images_generate',[$id])) wp_schedule_single_event(time()+30,'terrible_social_images_generate',[$id]); $errors[]=['id'=>$id,'message'=>$e->getMessage()]; } $cursor=$id; }
        wp_send_json_success(['cursor'=>$cursor,'count'=>count($ids),'more'=>$more,'errors'=>$errors]);
    }
    public static function preview(): void {
        $id=absint($_POST['post']??0);
        if (!$id || !current_user_can('edit_post',$id)) wp_send_json_error(['message'=>'You cannot edit this post.'],403);
        if (!check_ajax_referer('terrible_social_preview_'.$id,'nonce',false)) wp_send_json_error(['message'=>'The preview session expired. Reload the editor.'],403);
        $title=sanitize_text_field(wp_unslash($_POST['title']??''));
        if (mb_strlen($title)>1000) wp_send_json_error(['message'=>'The preview title is too long.'],400);
        nocache_headers();
        try { $image=self::generate($id,$title); wp_send_json_success(['image'=>'data:image/jpeg;base64,'.base64_encode($image)]); }
        catch (\Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()],400); }
    }
    private static function notice(string $s): void { set_transient('terrible_social_notice_'.get_current_user_id(),$s,60); }

    public static function metadata_image($image, int $post_id): int {
        $id=(int)get_post_meta($post_id,self::META,true); return $id && wp_attachment_is_image($id) ? $id : (int)$image;
    }
    public static function head(): void {
        if (class_exists('TerribleMetadata\\Plugin') || defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || !is_singular() || is_preview() || post_password_required() || get_post_status() !== 'publish') return;
        $id=self::metadata_image(0,get_queried_object_id()); $img=$id ? wp_get_attachment_image_src($id,'full') : false;
        if ($img) echo '<meta property="og:image" content="'.esc_url($img[0]).'">' . "\n" . '<meta property="og:image:width" content="'.esc_attr($img[1]).'">' . "\n" . '<meta property="og:image:height" content="'.esc_attr($img[2]).'">' . "\n";
    }

    public static function generate(int $id, ?string $preview_title=null): ?string {
        $preview=$preview_title!==null;
        global $wpdb; $lock='tsi:'.get_current_blog_id().':'.$id; $locked=false;
        if (!$preview) { $locked=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))===1; if (!$locked) throw new \RuntimeException('Card generation is already running.'); }
        try {
        $post=get_post($id); if (!$post || (!$preview && ($post->post_status!=='publish' || $post->post_password!=='')) || !in_array($post->post_type,['post','page'],true)) throw new \RuntimeException('Only public, non-password-protected posts and pages can have published cards.');
        if (!function_exists('imagettftext')) throw new \RuntimeException('PHP GD with FreeType is required.');
        $s=self::settings(); $background=(int)get_post_meta($id,'_terrible_social_background',true);
        if (!in_array($background,$s['backgrounds'],true)) { $background=$s['backgrounds'] ? $s['backgrounds'][$id % count($s['backgrounds'])] : 0; if (!$preview) update_post_meta($id,'_terrible_social_background',$background); }
        $title=trim(wp_strip_all_tags($preview ? $preview_title : (get_post_meta($id,'_terrible_social_title',true) ?: $post->post_title)));
        if ($title==='') throw new \RuntimeException('The post needs a title.');
        $fonts=self::fonts(); $fontkey=$s['font']==='site' ? self::site_font($fonts) : $s['font'];
        if (str_starts_with((string)$fontkey,'theme-')) foreach ($fonts as $key=>$candidate) if (!empty($candidate['path']) && 'theme-'.substr(hash('sha256',$candidate['path']),0,16)===$fontkey) { $fontkey=$key; break; }
        if (!isset($fonts[$fontkey]) || empty($fonts[$fontkey]['path'])) throw new \RuntimeException('The selected site font is not available to the server renderer. Choose an available font in Social images settings.');
        $font=$fonts[$fontkey]['path'];
        if (!is_readable($font)) throw new \RuntimeException('The card font is unavailable.');
        $remote_temp='';
        $path=$background ? get_attached_file($background) : '';
        if ($background && (!$path || !is_readable($path))) { $remote_temp=self::fetch_background($background); $path=$remote_temp; }
        try {
        if ($path) { $dimensions=@getimagesize($path); if (!$dimensions || $dimensions[0]<1 || $dimensions[1]<1 || $dimensions[0]*$dimensions[1]>40000000) throw new \RuntimeException('Background must be an image of at most 40 megapixels.'); }
        $fingerprint=hash('sha256',wp_json_encode([$title,$s,$background,$path ? hash_file('sha256',$path) : '',hash_file('sha256',$font),'render-v1']));
        $existing=(int)get_post_meta($id,self::META,true); if (!$preview && $existing && wp_attachment_is_image($existing) && hash_equals((string)get_post_meta($id,'_terrible_social_fingerprint',true),$fingerprint)) return null;
        $canvas=imagecreatetruecolor(1200,630); if (!$canvas) throw new \RuntimeException('Could not create image.');
        try {
            [$r,$g,$b]=self::rgb($s['color']); imagefill($canvas,0,0,imagecolorallocate($canvas,$r,$g,$b));
            if ($background) {
                $editor=wp_get_image_editor($path); if (is_wp_error($editor)) throw new \RuntimeException($editor->get_error_message());
                $res=$editor->resize(1200,630,true); if (is_wp_error($res)) throw new \RuntimeException($res->get_error_message());
                $tmp=wp_tempnam('terrible-social.jpg'); if (!$tmp) throw new \RuntimeException('Could not create a temporary image.');
                $saved=$editor->save($tmp,'image/jpeg'); if (is_wp_error($saved)) { @unlink($tmp); throw new \RuntimeException($saved->get_error_message()); }
                $source=@imagecreatefromjpeg($saved['path']); @unlink($saved['path']); if (!$source) throw new \RuntimeException('Could not read the prepared background.'); imagecopy($canvas,$source,0,0,0,0,1200,630); imagedestroy($source);
                imagefilledrectangle($canvas,0,0,1200,630,imagecolorallocatealpha($canvas,0,0,0,(int)round(127*(100-$s['overlay'])/100)));
            }
            [$r,$g,$b]=self::rgb($s['text']); $color=imagecolorallocate($canvas,$r,$g,$b);
            $lines=[]; $size=64;
            for (; $size>=28; $size-=2) { $lines=self::wrap($title,$font,$size,1000); if ($lines && count($lines)*($size*1.5)<=390) break; }
            if ($size<28) throw new \RuntimeException('The card title is too long. Set a shorter card title.');
            $y=(int)((630-count($lines)*$size*1.5)/2+$size);
            foreach ($lines as $line) { imagettftext($canvas,$size,0,100,$y,$color,$font,$line); $y+=(int)($size*1.5); }
            $temp=wp_tempnam('terrible-social.jpg'); if (!$temp || !imagejpeg($canvas,$temp,88)) throw new \RuntimeException('Could not save the generated card.');
            $bits=file_get_contents($temp); @unlink($temp); if ($bits===false) throw new \RuntimeException('Could not read the generated card.');
            if ($preview) return $bits;
            do_action('terrible_social_images_before_commit',$id);
            if (!self::inputs_current($id,$title,$s)) throw new \RuntimeException('Card inputs changed during generation.');
            $upload=wp_upload_bits('terrible-social-'.$id.'-'.substr(hash('sha256',$bits),0,16).'.jpg',null,$bits); if (!empty($upload['error'])) throw new \RuntimeException($upload['error']);
            $attachment=wp_insert_attachment(['post_mime_type'=>'image/jpeg','post_title'=>'Social image: '.$post->post_title,'post_status'=>'inherit','post_parent'=>$id],$upload['file'],$id,true);
            if (is_wp_error($attachment)) throw new \RuntimeException($attachment->get_error_message());
            require_once ABSPATH.'wp-admin/includes/image.php'; wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$upload['file']));
            do_action('terrible_social_images_after_attachment',$id,$attachment);
            if (!self::inputs_current($id,$title,$s)) { wp_delete_attachment($attachment,true); throw new \RuntimeException('Card inputs changed during generation.'); }
            update_post_meta($attachment,'_wp_attachment_image_alt',$title); update_post_meta($id,self::META,$attachment); update_post_meta($id,'_terrible_social_fingerprint',$fingerprint);
        } finally { imagedestroy($canvas); }
        } finally { if ($remote_temp) wp_delete_file($remote_temp); }
        return null;
        } finally { if ($locked) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private static function inputs_current(int $id,string $title,array $settings): bool {
        clean_post_cache($id); $current=get_post($id); $now=trim(wp_strip_all_tags(get_post_meta($id,'_terrible_social_title',true) ?: ($current->post_title??'')));
        return $current && $current->post_status==='publish' && $current->post_password==='' && $now===$title && self::settings()===$settings;
    }
    private static function fetch_background(int $id): string {
        $url=wp_get_attachment_url($id);
        if (!$url || !wp_http_validate_url($url)) throw new \RuntimeException('The background is offloaded and its public URL is not available.');
        $tmp=wp_tempnam('terrible-social-source'); if (!$tmp) throw new \RuntimeException('Could not create a temporary background.');
        $response=wp_safe_remote_get($url,['timeout'=>20,'redirection'=>3,'stream'=>true,'filename'=>$tmp,'limit_response_size'=>16*1024*1024+1]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200 || !is_file($tmp) || filesize($tmp)>16*1024*1024 || !@getimagesize($tmp)) { wp_delete_file($tmp); throw new \RuntimeException('Could not retrieve the offloaded background as a public image (maximum 16 MiB).'); }
        return $tmp;
    }
    private static function rgb(string $hex): array { return array_map('hexdec',str_split(ltrim($hex,'#'),2)); }
    private static function wrap(string $text,string $font,int $size,int $width): array {
        $lines=[]; $line=''; foreach (preg_split('/\s+/u',$text) as $word) { $next=trim($line.' '.$word); $box=imagettfbbox($size,0,$font,$next); if ($box && $box[2]-$box[0]>$width) { if ($line==='') return []; $lines[]=$line; $line=$word; $box=imagettfbbox($size,0,$font,$line); if (!$box || $box[2]-$box[0]>$width) return []; } else $line=$next; } if ($line!=='') $lines[]=$line; return $lines;
    }
}
