<?php
namespace TerribleHistory;
defined('ABSPATH') || exit;
final class Plugin {
    public static function table(): string { global $wpdb; return $wpdb->prefix.'terrible_history'; }
    public static function install(): void {
        if (get_option('terrible_history_schema')==='1') return;
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $table=self::table();
        dbDelta("CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            happened bigint NOT NULL,
            actor bigint unsigned NOT NULL,
            event varchar(100) NOT NULL,
            object_id bigint unsigned NOT NULL DEFAULT 0,
            detail varchar(500) NOT NULL,
            PRIMARY KEY  (id),
            KEY happened (happened)
        ) ".$wpdb->get_charset_collate().';'); update_option('terrible_history_schema','1',false);
    }
    public static function boot(): void {
        self::install(); UI::boot('terrible-history','Terrible History',[self::class,'page'],[self::class,'action']);
        add_action('wp_login',static fn($login,$user)=>self::record('user.login',$user->ID,'Signed in.'),10,2);
        add_action('user_register',static fn($id)=>self::record('user.created',$id,'User created.'));
        add_action('deleted_user',static fn($id)=>self::record('user.deleted',$id,'User deleted.'));
        add_action('set_user_role',static fn($id,$role)=>self::record('user.role',$id,'Role changed to '.$role.'.'),10,2);
        add_action('save_post',static function($id,$post,$update) { if (wp_is_post_revision($id) || wp_is_post_autosave($id)) return; self::record($update?'content.updated':'content.created',$id,'Post type '.$post->post_type.'; status '.$post->post_status.'.'); },10,3);
        add_action('before_delete_post',static fn($id)=>self::record('content.deleted',$id,'Content deleted.'));
        add_action('activated_plugin',static fn($plugin)=>self::record('plugin.activated',0,$plugin));
        add_action('deactivated_plugin',static fn($plugin)=>self::record('plugin.deactivated',0,$plugin));
        add_action('upgrader_process_complete',static fn($upgrader,$details)=>self::record('software.updated',0,sanitize_key($details['type']??'software')),10,2);
        add_action('switch_theme',static fn($name)=>self::record('theme.switched',0,$name));
        add_action('updated_option',static function($name) { if (!get_current_user_id() || str_starts_with($name,'_') || str_starts_with($name,'terrible_history') || in_array($name,['cron','rewrite_rules','recently_activated'],true)) return; self::record('setting.updated',0,'Setting: '.substr($name,0,180)); });
        add_action('terrible_history_event',[self::class,'record'],10,3);
    }
    public static function record(string $event,int $object=0,string $detail=''): void {
        global $wpdb; $table=self::table(); $wpdb->insert($table,['happened'=>time(),'actor'=>get_current_user_id(),'event'=>substr(preg_replace('/[^a-z0-9_.-]/','',strtolower($event)),0,100),'object_id'=>max(0,$object),'detail'=>mb_substr(sanitize_text_field($detail),0,500)]);
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE happened<%d",time()-30*DAY_IN_SECONDS)); $cut=$wpdb->get_var("SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET 999"); if ($cut) $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id<%d",$cut));
    }
    public static function rows(string $query=''): array { global $wpdb; $sql='SELECT * FROM '.self::table(); if ($query!=='') $sql.=$wpdb->prepare(' WHERE event LIKE %s OR detail LIKE %s','%'.$wpdb->esc_like($query).'%','%'.$wpdb->esc_like($query).'%'); return $wpdb->get_results($sql.' ORDER BY id DESC LIMIT 1000',ARRAY_A); }
    public static function action(string $op,array $d): string { if ($op==='export') UI::export('terrible-history',self::rows()); if ($op==='clear') { global $wpdb; $wpdb->query('DELETE FROM '.self::table()); self::record('history.cleared',0,'Activity history cleared by administrator.'); return 'History cleared.'; } throw new \RuntimeException('Unknown operation.'); }
    public static function page(): void {
        UI::header('terrible-history','Terrible History','Who changed what, and when. Keeps 30 days or 1,000 events. Setting values, passwords, content bodies, and visitor IPs are not recorded.');
        echo '<form method="get"><input type="hidden" name="page" value="terrible-history"><label>Search events <input name="q" value="'.esc_attr(wp_unslash($_GET['q']??'')).'"></label> <button class="button">Search</button></form><p></p><table class="widefat striped"><tr><th>When</th><th>Actor</th><th>Event</th><th>Object</th><th>Detail</th></tr>';
        foreach (self::rows(sanitize_text_field(wp_unslash($_GET['q']??''))) as $r) echo '<tr><td>'.esc_html(wp_date('Y-m-d H:i:s',(int)$r['happened'])).'</td><td>'.($r['actor']?'User '.(int)$r['actor']:'System').'</td><td>'.esc_html($r['event']).'</td><td>'.(int)$r['object_id'].'</td><td>'.esc_html($r['detail']).'</td></tr>'; echo '</table>'; UI::form('terrible-history','export'); UI::submit('Export history'); UI::form('terrible-history','clear'); UI::submit('Clear history'); echo '</div>';
    }
}
