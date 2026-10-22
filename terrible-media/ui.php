<?php
namespace TerribleMedia;
defined('ABSPATH') || exit;
require_once __DIR__.'/shared/admin.php';
require_once __DIR__.'/shared/stream.php';
final class UI {
    public static function boot(string $slug,string $title,callable $page,callable $action): void {
        \TerribleTools\Admin::register($slug,$title,$page);
        add_action('admin_post_'.$slug,static function () use ($slug,$action) {
            if (!current_user_can('manage_options')) wp_die('Administrator access required.','',['response'=>403]);
            check_admin_referer($slug);
            try { $message=$action(sanitize_key($_POST['op']??''),wp_unslash($_POST)); set_transient($slug.'_notice_'.get_current_user_id(),$message?:'Saved.',60); wp_safe_redirect(\TerribleTools\Admin::url($slug,$slug==='terrible-media' && !empty($_POST['id']) ? ['id'=>(int)$_POST['id']] : [])); exit; }
            catch (\Throwable $e) { set_transient($slug.'_error_'.get_current_user_id(),$e->getMessage(),60); wp_safe_redirect(\TerribleTools\Admin::url($slug,$slug==='terrible-media' && !empty($_POST['id']) ? ['id'=>(int)$_POST['id']] : [])); exit; }
        });
    }
    public static function header(string $slug,string $title,string $description): void {
        \TerribleTools\Admin::header($title,$description);
        $error=$slug.'_error_'.get_current_user_id(); if ($message=get_transient($error)) { delete_transient($error); echo '<div class="tt-error" role="alert">'.esc_html($message).'</div>'; }
        $key=$slug.'_notice_'.get_current_user_id(); if ($message=get_transient($key)) { delete_transient($key); echo '<div class="tt-success" role="status"><p>'.esc_html($message).'</p></div>'; }
    }
    public static function form(string $slug,string $op): void { echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr($slug).'"><input type="hidden" name="op" value="'.esc_attr($op).'">'; wp_nonce_field($slug); }
    public static function field(string $key,string $label,string $value='',string $type='text'): void { echo '<p><label>'.esc_html($label).'<br><input class="regular-text" name="'.esc_attr($key).'" type="'.esc_attr($type).'" value="'.esc_attr($value).'"></label></p>'; }
    public static function submit(string $label): void { submit_button($label); echo '</form>'; }
    public static function export(string $name,array $data): never { nocache_headers(); header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="'.$name.'.json"'); echo wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit; }
}
