<?php
namespace TerribleMail;
defined('ABSPATH') || exit;
require_once dirname(__DIR__).'/shared/admin.php';
require_once dirname(__DIR__).'/shared/stream.php';
final class Admin {
    public static function boot(): void {
        \TerribleTools\Admin::register('terrible-mail','Terrible Mail',[self::class,'page'],'options-general.php');
        add_action('admin_post_terrible_mail',[self::class,'handle']);
        add_filter('plugin_action_links_' . plugin_basename(TERRIBLE_MAIL_FILE),static fn($links)=>array_merge(['<a href="'.esc_url(admin_url('options-general.php?page=terrible-mail')).'">Settings</a>'],$links));
    }
    public static function handle(): void {
        if (!current_user_can('manage_options')) wp_die('Administrator access required.','',['response'=>403]);
        check_admin_referer('terrible-mail');
        try {
            $op=sanitize_key($_POST['op']??'');
            if ($op==='save') { Settings::save(wp_unslash($_POST)); $message='Settings saved.'; }
            elseif ($op==='remember') { Settings::remember((string)($_POST['label']??'')); $message='Connection saved.'; }
            elseif ($op==='forget') { Settings::forget((string)($_POST['connection']??'')); $message='Connection removed.'; }
            elseif ($op==='use') { Settings::useConnection((string)($_POST['connection']??'')); $message='Saved connection selected.'; }
            elseif ($op==='test') {
                if (!Settings::get()['enabled']) throw new \RuntimeException('Enable Terrible Mail before sending a test.');
                $to=sanitize_email(wp_unslash($_POST['to']??'')); if (!is_email($to)) throw new \RuntimeException('Enter a valid test recipient.');
                $message=wp_mail($to,'Terrible Mail test','This is a test message from '.home_url().'.')?'Test accepted by the sending service. Inbox delivery is not confirmed.':'Test failed. See the submission history below.';
            } elseif ($op==='retry') { $r=History::retry(sanitize_text_field($_POST['id']??'')); $message=$r->detail; }
            elseif ($op==='clear') { global $wpdb; $wpdb->query('DELETE FROM '.History::table()." WHERE status!='sending'"); $message='History cleared.'; }
            else throw new \RuntimeException('Unknown operation.');
            set_transient('terrible_mail_notice_'.get_current_user_id(),$message,60);
            wp_safe_redirect(admin_url('options-general.php?page=terrible-mail')); exit;
        } catch (\Throwable $e) { set_transient('terrible_mail_error_'.get_current_user_id(),$e->getMessage(),60); wp_safe_redirect(\TerribleTools\Admin::url('terrible-mail')); exit; }
    }
    private static function begin(string $op): void { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="terrible_mail"><input type="hidden" name="op" value="'.esc_attr($op).'">'; wp_nonce_field('terrible-mail'); }
    public static function page(): void { require __DIR__.'/page.php'; }
}
