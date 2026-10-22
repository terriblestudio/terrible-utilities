<?php
namespace TerribleLogin;
defined('ABSPATH') || exit;
final class Plugin {
    public static function install(): void {}
    public static function boot(): void {
        UI::boot('terrible-login','Terrible Login',[self::class,'page'],[self::class,'action']);
        add_action('login_form',static function(){echo '<p><label for="terrible-code">Authenticator or recovery code<br><input id="terrible-code" name="terrible_code" type="text" class="input" autocomplete="one-time-code" maxlength="32"></label></p>';});
        add_filter('authenticate',[self::class,'authenticate'],99,3);
        add_action('show_user_profile',[self::class,'profile']);
        add_action('admin_post_terrible_login_account',[self::class,'account']);
        add_action('admin_init',[self::class,'gate']);
        add_filter('rest_authentication_errors',static function($error){return $error?: (is_user_logged_in() && self::needsEnrollment(wp_get_current_user())?new \WP_Error('terrible_login_enrollment','Enroll an authenticator on your profile first.',['status'=>403]):$error);});
        add_filter('wp_is_application_passwords_available_for_user',static fn($available,$user)=>($user instanceof \WP_User && (self::enabled($user->ID) || self::needsEnrollment($user)))?false:$available,10,2);
    }
    private static function seal(string $value): string { $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($value,'aes-256-gcm',hash('sha256',wp_salt('auth').'terrible-login',true),OPENSSL_RAW_DATA,$iv,$tag); if ($cipher===false) throw new \RuntimeException('Cannot encrypt the authenticator secret.'); return base64_encode($iv.$tag.$cipher); }
    private static function open(string $value): string { $raw=base64_decode($value,true); if ($raw===false || strlen($raw)<28) throw new \RuntimeException('Authenticator secret unavailable. Use a recovery code.'); $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',wp_salt('auth').'terrible-login',true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)); if ($plain===false) throw new \RuntimeException('Authenticator secret unavailable. Use a recovery code.'); return $plain; }
    public static function secret(): string { $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $out=''; foreach (str_split(random_bytes(32)) as $byte) $out.=$alphabet[ord($byte)&31]; return $out; }
    public static function code(string $secret,int $step): string {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; foreach (str_split($secret) as $c) { $n=strpos($alphabet,$c); if ($n===false) throw new \RuntimeException('Invalid authenticator secret.'); $bits.=str_pad(decbin($n),5,'0',STR_PAD_LEFT); }
        $key=''; for($i=0;$i+8<=strlen($bits);$i+=8) $key.=chr(bindec(substr($bits,$i,8)));
        $hash=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),$key,true); $offset=ord($hash[19])&15; $n=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff; return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);
    }
    public static function enabled(int $id): bool { return (bool)get_user_meta($id,'terrible_login_secret',true); }
    public static function needsEnrollment(\WP_User $user): bool { return (bool)get_option('terrible_login_require_admin',false) && user_can($user,'manage_options') && !self::enabled($user->ID); }
    public static function enroll(int $id,string $secret,string $code): array {
        $step=intdiv(time(),30); if (!preg_match('/\A\d{6}\z/',$code) || !hash_equals(self::code($secret,$step),$code)) throw new \RuntimeException('The code did not match. Check your authenticator clock and try again.');
        update_user_meta($id,'terrible_login_secret',self::seal($secret)); update_user_meta($id,'terrible_login_step',(string)$step);
        $codes=[]; for($i=0;$i<10;$i++) $codes[]=bin2hex(random_bytes(8)); update_user_meta($id,'terrible_login_recovery',array_map(static fn($c)=>hash('sha256',$c),$codes)); delete_user_meta($id,'terrible_login_pending');
        if ($id===get_current_user_id()) \WP_Session_Tokens::get_instance($id)->destroy_others(wp_get_session_token());
        do_action('terrible_tools_event','login.enrolled',$id,'Authenticator enrolled.'); return $codes;
    }
    public static function verify(int $id,string $code): bool {
        $code=trim($code); $recovery=get_user_meta($id,'terrible_login_recovery',true)?:[]; $hash=hash('sha256',$code);
        foreach ($recovery as $index=>$saved) if (hash_equals($saved,$hash)) { $remaining=$recovery; unset($remaining[$index]); return (bool)update_user_meta($id,'terrible_login_recovery',array_values($remaining),$recovery); }
        if (!preg_match('/\A\d{6}\z/',$code)) return false;
        $secret=self::open(get_user_meta($id,'terrible_login_secret',true)); $last=(string)get_user_meta($id,'terrible_login_step',true); $now=intdiv(time(),30);
        foreach ([$now-1,$now,$now+1] as $step) if ($step>(int)$last && hash_equals(self::code($secret,$step),$code)) return (bool)update_user_meta($id,'terrible_login_step',(string)$step,$last);
        return false;
    }
    public static function authenticate($user,string $username,string $password) {
        if (!$user instanceof \WP_User || !self::enabled($user->ID)) return $user;
        $key='tl_attempts_'.$user->ID; $attempts=(int)get_transient($key); if ($attempts>=5) return new \WP_Error('terrible_login_limited','Too many authenticator attempts. Wait five minutes.');
        try { $valid=self::verify($user->ID,(string)wp_unslash($_POST['terrible_code']??'')); } catch (\Throwable $e) { $valid=false; }
        if (!$valid) { set_transient($key,$attempts+1,5*MINUTE_IN_SECONDS); return new \WP_Error('terrible_login_code','Enter a valid authenticator or unused recovery code.'); }
        delete_transient($key); return $user;
    }
    public static function gate(): void {
        if (!self::needsEnrollment(wp_get_current_user())) return;
        global $pagenow;
        if ($pagenow==='profile.php' || ($pagenow==='admin-post.php' && ($_REQUEST['action']??'')==='terrible_login_account')) return;
        wp_safe_redirect(admin_url('profile.php#terrible-login')); exit;
    }
    public static function account(): void {
        if (!is_user_logged_in()) wp_die('Sign in first.','',['response'=>403]); check_admin_referer('terrible-login-account'); $id=get_current_user_id(); $user=wp_get_current_user();
        if (!wp_check_password(wp_unslash($_POST['password']??''),$user->user_pass,$id)) wp_die('Your current password did not match.');
        try {
            $op=sanitize_key($_POST['operation']??'');
            if ($op==='start') { if (self::enabled($id)) throw new \RuntimeException('An authenticator is already enrolled.'); update_user_meta($id,'terrible_login_pending',['secret'=>self::seal(self::secret()),'expires'=>time()+600]); }
            elseif ($op==='enroll') { $pending=get_user_meta($id,'terrible_login_pending',true); if (!$pending || $pending['expires']<time()) throw new \RuntimeException('Enrollment expired. Start again.'); $codes=self::enroll($id,self::open($pending['secret']),trim(wp_unslash($_POST['code']??''))); set_transient('tl_codes_'.$id,self::seal(wp_json_encode($codes)),300); }
            elseif ($op==='disable') { if (get_option('terrible_login_require_admin') && current_user_can('manage_options')) throw new \RuntimeException('Administrators are required to keep two-factor authentication enabled.'); if (!self::verify($id,trim(wp_unslash($_POST['code']??'')))) throw new \RuntimeException('Enter a valid authenticator or recovery code.'); foreach (['secret','step','recovery','pending'] as $key) delete_user_meta($id,'terrible_login_'.$key); }
            else throw new \RuntimeException('Unknown operation.');
            wp_safe_redirect(admin_url('profile.php#terrible-login')); exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()),'Terrible Login',['back_link'=>true]); }
    }
    public static function profile($user): void {
        if ($user->ID!==get_current_user_id()) return;
        echo '<h2 id="terrible-login">Terrible Login</h2><p>Protect password sign-ins with an authenticator app. Protected accounts cannot use application passwords; existing sessions are not prompted on each request.</p>';
        if ($codes=get_transient('tl_codes_'.$user->ID)) { delete_transient('tl_codes_'.$user->ID); echo '<p><strong>Save these recovery codes now. Each works once; they are shown only once.</strong></p><pre>'.esc_html(implode("\n",json_decode(self::open($codes),true))).'</pre>'; }
        $enabled=self::enabled($user->ID); $pending=get_user_meta($user->ID,'terrible_login_pending',true); $op=$enabled?'disable':($pending && $pending['expires']>=time()?'enroll':'start');
        // Profile is already a form: buttons target a separate form outside it via the form attribute.
        if ($op==='enroll') { $secret=self::open($pending['secret']); echo '<p>Add this setup key to an authenticator (time-based, SHA-1, 6 digits, 30 seconds): <code>'.esc_html($secret).'</code></p>'; }
        echo '<p>Status: '.($enabled?'enabled':'not enrolled').'.</p><p><label>Current password <input form="tl-account-form" type="password" name="password" autocomplete="current-password" required></label></p>';
        if ($op!=='start') echo '<p><label>Authenticator'.($enabled?' or recovery':'').' code <input form="tl-account-form" name="code" autocomplete="one-time-code" required></label></p>';
        echo '<p><button form="tl-account-form" class="button" type="submit">'.esc_html(['start'=>'Set up authenticator','enroll'=>'Verify and enable','disable'=>'Disable authenticator'][$op]).'</button></p>';
        add_action('admin_footer',static function() use($op){echo '<form id="tl-account-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="terrible_login_account"><input type="hidden" name="operation" value="'.esc_attr($op).'">'; wp_nonce_field('terrible-login-account'); echo '</form>';});
    }
    public static function action(string $op,array $d): string { if ($op!=='save') throw new \RuntimeException('Unknown operation.'); $require=!empty($d['require']); if ($require && !self::enabled(get_current_user_id())) throw new \RuntimeException('Enroll your own authenticator before requiring administrators to enroll.'); update_option('terrible_login_require_admin',$require,false); return 'Policy saved.'; }
    public static function page(): void { require __DIR__.'/page.php'; }
}
