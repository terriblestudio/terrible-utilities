<?php
namespace TerribleForms;
defined('ABSPATH') || exit;
final class Plugin {
    public static function table(): string { global $wpdb; return $wpdb->prefix.'terrible_forms'; }
    public static function install(): void {
        if (get_option('terrible_forms_schema')==='1') return; global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $table=self::table();
        dbDelta("CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            created bigint NOT NULL,
            name varchar(200) NOT NULL,
            email varchar(254) NOT NULL,
            message text NOT NULL,
            notified tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token)
        ) ".$wpdb->get_charset_collate().';'); update_option('terrible_forms_schema','1',false);
    }
    public static function boot(): void { self::install(); UI::boot('terrible-forms','Terrible Forms',[self::class,'page'],[self::class,'action']); add_shortcode('terrible_form',[self::class,'form']); add_action('admin_post_nopriv_terrible_form_submit',[self::class,'submit']); add_action('admin_post_terrible_form_submit',[self::class,'submit']); add_action('terrible_forms_cleanup',[self::class,'cleanup']); if (!wp_next_scheduled('terrible_forms_cleanup')) wp_schedule_event(time()+3600,'daily','terrible_forms_cleanup'); }
    public static function form(): string {
        $token=wp_generate_uuid4(); $instance=wp_unique_id('terrible-form-'); ob_start();
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="terrible-form"><input type="hidden" name="action" value="terrible_form_submit"><input type="hidden" name="token" value="'.esc_attr($token).'">'; wp_nonce_field('terrible-form','_wpnonce',false);
        echo '<p><label for="'.$instance.'name">Name</label><br><input id="'.$instance.'name" name="name" autocomplete="name" maxlength="200" required></p><p><label for="'.$instance.'email">Email</label><br><input id="'.$instance.'email" type="email" name="email" autocomplete="email" maxlength="254" required></p><p><label for="'.$instance.'message">Message</label><br><textarea id="'.$instance.'message" name="message" rows="6" maxlength="10000" required></textarea></p><div hidden aria-hidden="true"><label>Leave this empty <input name="website" tabindex="-1" autocomplete="off"></label></div><p>Your details are used to respond to your message and retained for up to 90 days.</p><button type="submit">Send message</button></form>'; return ob_get_clean();
    }
    public static function receive(array $d,string $address): int {
        if (!empty($d['website'])) throw new \RuntimeException('Submission rejected.');
        $name=sanitize_text_field($d['name']??''); $email=sanitize_email($d['email']??''); $message=sanitize_textarea_field($d['message']??''); $token=(string)($d['token']??'');
        if ($name==='' || strlen($name)>200 || !is_email($email) || $message==='' || strlen($message)>10000 || !preg_match('/\A[a-f0-9-]{36}\z/',$token)) throw new \RuntimeException('Enter your name, valid email, and a message of at most 10,000 bytes.');
        $token=hash('sha256',$token."\0".$name."\0".$email."\0".$message);
        global $wpdb; $table=self::table(); $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE token=%s",$token)); if ($existing) return (int)$existing;
        $rate='tf_rate_'.hash_hmac('sha256',$address,wp_salt('nonce')); $count=(int)get_transient($rate); if ($count>=5) throw new \RuntimeException('Please wait 15 minutes before sending another message.');
        set_transient($rate,$count+1,15*MINUTE_IN_SECONDS);
        $ok=$wpdb->insert($table,['token'=>$token,'created'=>time(),'name'=>$name,'email'=>$email,'message'=>$message,'notified'=>0]);
        if (!$ok) { $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE token=%s",$token)); if ($existing) return (int)$existing; throw new \RuntimeException('Your message could not be saved. Please try again.'); }
        $id=(int)$wpdb->insert_id; self::notify($id); self::cleanup(); return $id;
    }
    public static function notify(int $id): bool {
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$id),ARRAY_A); if (!$row) throw new \RuntimeException('Submission not found.');
        $to=get_option('terrible_forms_recipient',get_option('admin_email')); $body="New website message #$id\n\nName: {$row['name']}\nEmail: {$row['email']}\n\n{$row['message']}\n";
        try { $sent=wp_mail($to,'New website contact message #'.$id,$body,['Reply-To: '.$row['email']]); } catch (\Throwable $e) { $sent=false; }
        $wpdb->update(self::table(),['notified'=>$sent?1:0],['id'=>$id]); return $sent;
    }
    public static function submit(): void {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !wp_verify_nonce($_POST['_wpnonce']??'','terrible-form')) wp_die('The form expired. Reload it and try again.','',['response'=>403]);
        try { self::receive(wp_unslash($_POST),(string)($_SERVER['REMOTE_ADDR']??'')); wp_die('Your message has been saved. Thank you.','Message received',['response'=>200,'back_link'=>true]); }
        catch (\Throwable $e) { wp_die(esc_html($e->getMessage()),'Unable to send',['response'=>400,'back_link'=>true]); }
    }
    public static function cleanup(): void { global $wpdb; $table=self::table(); $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created<%d",time()-90*DAY_IN_SECONDS)); $cut=$wpdb->get_var("SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET 499"); if ($cut) $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id<%d",$cut)); }
    public static function rows(): array { global $wpdb; return $wpdb->get_results('SELECT * FROM '.self::table().' ORDER BY id DESC LIMIT 500',ARRAY_A); }
    public static function action(string $op,array $d): string {
        global $wpdb;
        if ($op==='save') { $email=sanitize_email($d['recipient']??''); if (!is_email($email)) throw new \RuntimeException('Enter a valid notification address.'); update_option('terrible_forms_recipient',$email,false); }
        elseif ($op==='delete') $wpdb->delete(self::table(),['id'=>(int)($d['id']??0)]);
        elseif ($op==='notify') return self::notify((int)$d['id'])?'Notification accepted.':'Notification failed; the submission remains in the inbox.';
        elseif ($op==='export') UI::export('terrible-forms',self::rows());
        else throw new \RuntimeException('Unknown operation.'); return 'Saved.';
    }
    public static function page(): void {
        UI::header('terrible-forms','Terrible Forms','A contact form and a local inbox. Insert [terrible_form] into a page or Shortcode block. Messages are saved before email is attempted.');
        UI::form('terrible-forms','save'); UI::field('recipient','Notification recipient',get_option('terrible_forms_recipient',get_option('admin_email')),'email'); UI::submit('Save');
        echo '<h2>Inbox</h2><p>Keeps up to 500 messages for 90 days. Export messages before they expire. Notification acceptance does not establish inbox delivery.</p>';
        foreach (self::rows() as $r) { echo '<details style="padding:12px;background:white;margin:8px 0"><summary>#'.(int)$r['id'].' — '.esc_html($r['name']).' — '.esc_html(wp_date('Y-m-d H:i',(int)$r['created'])).'</summary><p>'.esc_html($r['email']).'</p><p style="white-space:pre-wrap">'.esc_html($r['message']).'</p><p>Notification: '.($r['notified']?'accepted':'not confirmed').'</p>'; if (!$r['notified']) { UI::form('terrible-forms','notify'); echo '<input type="hidden" name="id" value="'.(int)$r['id'].'">'; echo '<p>Sending again may duplicate a notification whose acknowledgement was lost.</p>'; UI::submit('Send notification again'); } UI::form('terrible-forms','delete'); echo '<input type="hidden" name="id" value="'.(int)$r['id'].'">'; UI::submit('Delete submission'); echo '</details>'; }
        UI::form('terrible-forms','export'); UI::submit('Export inbox'); echo '</div>';
    }
}
