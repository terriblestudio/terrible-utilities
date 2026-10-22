<?php
namespace TerribleMedia;
defined('ABSPATH') || exit;
final class Plugin {
    public static function install(): void {}
    public static function boot(): void {
        add_action('admin_enqueue_scripts',static function($hook){if (($_GET['page']??'')==='terrible-media') { wp_enqueue_media(); wp_enqueue_script('terrible-media',plugins_url('media.js',__FILE__),['media-views'],'0.2.0',true); }});
        UI::boot('terrible-media','Terrible Media',[self::class,'page'],[self::class,'action']);
        add_filter('attachment_fields_to_edit',static function($fields,$post){if (current_user_can('manage_options')) $fields['terrible_media']=['label'=>'Replace / usage','input'=>'html','html'=>'<a href="'.esc_url(admin_url('tools.php?page=terrible-media&id='.$post->ID)).'">Open Terrible Media</a>']; return $fields;},10,2);
        add_filter('media_row_actions',static function($actions,$post) { if (current_user_can('manage_options')) $actions['terrible_media']='<a href="'.esc_url(admin_url('tools.php?page=terrible-media&id='.$post->ID)).'">Replace / usage</a>'; return $actions; },10,2);
    }
    private static function path(int $id): string {
        if (get_post_type($id)!=='attachment' || !current_user_can('edit_post',$id)) throw new \RuntimeException('Choose an editable media attachment.');
        $path=get_attached_file($id); $base=realpath(wp_upload_dir()['basedir']); $real=realpath($path?:'');
        $cursor=$path?:''; while ($cursor && dirname($cursor)!==$cursor) { if (is_link($cursor)) throw new \RuntimeException('Symbolic links are not supported for media replacement.'); $cursor=dirname($cursor); }
        if (!$real || !$base || !str_starts_with($real,$base.DIRECTORY_SEPARATOR) || is_link($path)) throw new \RuntimeException('Replacement requires a local file inside the uploads directory.');
        return $real;
    }
    private static function storage(int $id): string {
        $root=defined('TERRIBLE_MEDIA_DIR')?TERRIBLE_MEDIA_DIR:sys_get_temp_dir().'/terrible-media-'.substr(hash('sha256',ABSPATH.wp_salt('auth')),0,24);
        if (!is_dir($root) && !mkdir($root,0700,true)) throw new \RuntimeException('Cannot create private version storage.');
        if (is_link($root) || str_starts_with(realpath($root).'/',realpath(ABSPATH).'/') || (!empty($_SERVER['DOCUMENT_ROOT']) && realpath($_SERVER['DOCUMENT_ROOT']) && str_starts_with(realpath($root).'/',realpath($_SERVER['DOCUMENT_ROOT']).'/'))) throw new \RuntimeException('Version storage must be outside the web root.');
        chmod($root,0700); $dir=$root.'/'.$id; if (!is_dir($dir)) mkdir($dir,0700); return $dir;
    }
    private static function files(string $path,array $meta): array {
        $out=[basename($path)]; foreach ($meta['sizes']??[] as $size) if (!empty($size['file'])) $out[]=$size['file']; if (!empty($meta['original_image'])) $out[]=$meta['original_image'];
        foreach ($out as $name) if ($name!==basename($name) || str_contains($name,'\\') || is_link(dirname($path).'/'.$name)) throw new \RuntimeException('Unsafe attachment metadata.'); return array_unique($out);
    }
    public static function replace(int $id,string $source,string $filename): void {
        $path=self::path($id); $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        if (!in_array($ext,['jpg','jpeg','png','gif','webp','avif','pdf'],true) || strtolower(pathinfo($filename,PATHINFO_EXTENSION))!==$ext) throw new \RuntimeException('Replacement must use the same file extension (supported images or PDF).');
        if (!is_file($source) || filesize($source)<1 || filesize($source)>50*1048576) throw new \RuntimeException('Replacement must be between 1 byte and 50 MiB.');
        $check=wp_check_filetype_and_ext($source,$filename); $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (empty($check['ext']) || $check['type']!==get_post_mime_type($id) || $mime!==get_post_mime_type($id)) throw new \RuntimeException('Replacement content does not match the original media type.');
        $dir=self::storage($id); $lock=fopen($dir.'/lock','c'); if (!flock($lock,LOCK_EX|LOCK_NB)) throw new \RuntimeException('This file is already being replaced.');
        $meta=wp_get_attachment_metadata($id)?:[]; $old=self::files($path,$meta); $stage=$dir.'/staged-'.wp_generate_uuid4(); mkdir($stage,0700);
        try {
            foreach ($old as $name) if (is_file(dirname($path).'/'.$name) && !copy(dirname($path).'/'.$name,$stage.'/'.$name)) throw new \RuntimeException('Cannot preserve the previous version.');
            file_put_contents($stage.'/version.json',wp_json_encode(['path'=>$path,'meta'=>$meta,'files'=>$old],JSON_THROW_ON_ERROR));
            $new=$path.'.terrible-new'; if (!copy($source,$new) || !rename($new,$path)) throw new \RuntimeException('Cannot replace this file.');
            require_once ABSPATH.'wp-admin/includes/image.php'; $disable=static fn()=>false; add_filter('big_image_size_threshold',$disable);
            try { $generated=wp_generate_attachment_metadata($id,$path); } finally { remove_filter('big_image_size_threshold',$disable); }
            if (!is_array($generated)) throw new \RuntimeException('Could not regenerate media metadata.');
            wp_update_attachment_metadata($id,$generated); update_attached_file($id,$path);
            foreach (array_diff($old,self::files($path,$generated)) as $name) if (is_file(dirname($path).'/'.$name)) unlink(dirname($path).'/'.$name);
            $previous=$dir.'/previous'; if (is_dir($previous)) self::remove($previous); rename($stage,$previous); clean_post_cache($id);
            do_action('terrible_tools_event','media.replaced',$id,'Media file replaced; previous version retained.');
        } catch (\Throwable $e) {
            if (is_file($stage.'/version.json')) { foreach ($old as $name) if (is_file($stage.'/'.$name)) copy($stage.'/'.$name,dirname($path).'/'.$name); wp_update_attachment_metadata($id,$meta); update_attached_file($id,$path); }
            throw $e;
        } finally { if (is_dir($stage)) self::remove($stage); flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function undo(int $id): void {
        $path=self::path($id); $dir=self::storage($id); $lock=fopen($dir.'/lock','c'); if (!flock($lock,LOCK_EX|LOCK_NB)) throw new \RuntimeException('This attachment is busy.');
        try { $previous=$dir.'/previous'; if (!is_file($previous.'/version.json')) throw new \RuntimeException('No previous version is available.'); $v=json_decode(file_get_contents($previous.'/version.json'),true,64,JSON_THROW_ON_ERROR); if ($v['path']!==$path) throw new \RuntimeException('The attachment path changed. Cannot safely restore this version.');
            foreach ($v['files'] as $name) { if ($name!==basename($name)) throw new \RuntimeException('Invalid version file.'); if (is_file($previous.'/'.$name) && !copy($previous.'/'.$name,dirname($path).'/'.$name)) throw new \RuntimeException('Cannot restore the previous version.'); }
            wp_update_attachment_metadata($id,$v['meta']); clean_post_cache($id); self::remove($previous); do_action('terrible_tools_event','media.restored',$id,'Previous media version restored.');
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private static function remove(string $dir): void { foreach (new \FilesystemIterator($dir) as $file) if ($file->isFile()) unlink($file->getPathname()); rmdir($dir); }
    public static function usage(int $id): array {
        global $wpdb; $url=wp_get_attachment_url($id); if (!$url) return [];
        $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type!='revision' AND (post_content LIKE %s OR post_content LIKE %s) LIMIT 200",'%'.$wpdb->esc_like($url).'%', '%'.$wpdb->esc_like('wp-image-'.$id).'%'));
        $featured=$wpdb->get_col($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_thumbnail_id' AND meta_value=%s LIMIT 200",(string)$id)); return array_slice(array_unique(array_map('intval',array_merge($ids,$featured))),0,200);
    }
    public static function action(string $op,array $d): string {
        $id=(int)($d['id']??0);
        if ($op==='replace') { $f=$_FILES['replacement']??[]; if (($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new \RuntimeException('Upload failed. Check the server upload limit.'); self::replace($id,$f['tmp_name'],$f['name']); return 'File replaced. Existing attachment ID and URL retained.'; }
        if ($op==='undo') { self::undo($id); return 'Previous version restored.'; } throw new \RuntimeException('Unknown operation.');
    }
    public static function page(): void { require __DIR__.'/page.php'; }
}
