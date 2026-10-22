<?php
namespace TerribleMailIntegrations;
use TerribleMail\{MailTransport,Result,Settings};
defined('ABSPATH') || exit;
final class Http {
    public static function endpoint(string $id, string $default): string { return defined('TERRIBLE_MAIL_TEST_ENDPOINTS') ? (TERRIBLE_MAIL_TEST_ENDPOINTS[$id]??$default) : $default; }
    public static function request(string $url, array $headers, string $body): array|\WP_Error { return wp_remote_post($url,['headers'=>$headers,'body'=>$body,'timeout'=>30,'redirection'=>0,'limit_response_size'=>1048576]); }
}
final class GoogleOAuth {
    public static function redirect(): string { return admin_url('admin-post.php?action=terrible_mail_oauth'); }
    public static function exchange(array $args): array {
        $r=Http::request(Http::endpoint('google_token','https://oauth2.googleapis.com/token'),['Content-Type'=>'application/x-www-form-urlencoded'],http_build_query($args));
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200) throw new \RuntimeException('Google authorization failed. Reconnect the account.');
        $data=json_decode(wp_remote_retrieve_body($r),true,64,JSON_THROW_ON_ERROR); if (!is_array($data)) throw new \RuntimeException('Invalid authorization response.'); return $data;
    }
    public static function connect(): void {
        if (!current_user_can('manage_options')) wp_die('Administrator access required.','',['response'=>403]); check_admin_referer('terrible-mail-connect');
        $c=Settings::get()['providers']['gmail']??[]; if (empty($c['client_id']) || empty($c['client_secret'])) wp_die('Save your application ID and secret first.');
        $state=bin2hex(random_bytes(32)); set_transient('tm_oauth_'.hash('sha256',$state),get_current_user_id(),600);
        $args=['client_id'=>$c['client_id'],'redirect_uri'=>self::redirect(),'response_type'=>'code','scope'=>'https://www.googleapis.com/auth/gmail.send','access_type'=>'offline','prompt'=>'consent','state'=>$state];
        wp_redirect(Http::endpoint('google_auth','https://accounts.google.com/o/oauth2/v2/auth').'?'.http_build_query($args)); exit;
    }
    public static function callback(): void {
        if (!current_user_can('manage_options')) wp_die('Administrator access required.','',['response'=>403]);
        $key='tm_oauth_'.hash('sha256',(string)($_GET['state']??'')); $user=get_transient($key); delete_transient($key);
        if (!$user || (int)$user!==get_current_user_id() || empty($_GET['code'])) wp_die('Connection expired or denied. Start again.');
        try { $c=Settings::get(); $p=$c['providers']['gmail']??[]; $r=self::exchange(['grant_type'=>'authorization_code','code'=>wp_unslash($_GET['code']),'client_id'=>$p['client_id'],'client_secret'=>$p['client_secret'],'redirect_uri'=>self::redirect()]);
            if (empty($r['refresh_token'])) throw new \RuntimeException('Offline access was not granted. Reconnect with consent.');
            $c['providers']['gmail']['refresh_token']=$r['refresh_token']; $c['transport']='gmail'; Settings::save($c); wp_safe_redirect(admin_url('options-general.php?page=terrible-mail')); exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage())); }
    }
}
final class ApiTransport implements MailTransport {
    public function __construct(private string $kind) {}
    public function id(): string { return $this->kind; }
    public function label(): string { return ['resend'=>'Resend','ses'=>'Amazon SES','gmail'=>'Google Workspace / Gmail'][$this->kind]; }
    public function fields(): array {
        return match($this->kind) {
            'resend'=>['api_key'=>['API key','password','']],
            'ses'=>['region'=>['AWS region','text','us-east-1'],'access_key'=>['Access key ID','text',''],'secret_key'=>['Secret access key','password',''],'session_token'=>['Session token (optional)','password','']],
            'gmail'=>['client_id'=>['Application client ID','text',''],'client_secret'=>['Application secret','password',''],'refresh_token'=>['Refresh token (or use Connect Google account)','password','']],
        };
    }
    public function validate(array $c): void {
        foreach ($c as $value) if (!is_string($value) || strlen($value)>8192 || preg_match('/[\r\n]/',$value)) throw new \RuntimeException('Invalid provider setting.');
        if ($this->kind==='ses' && !preg_match('/\A[a-z]{2}(?:-[a-z]+)+-[0-9]+\z/',$c['region']??'')) throw new \RuntimeException('Enter an AWS region.');
    }
    private static function addresses(array $list): array { return array_map(static fn($a)=>$a[0],$list); }
    public function send(array $m, array $c): Result {
        $this->validate($c); $headers=['Content-Type'=>'application/json'];
        if ($this->kind==='resend') {
            if (empty($c['api_key'])) return new Result('rejected','Configure a Resend API key first.');
            if (!in_array($m['content_type']??'text/plain',['text/plain','text/html'],true)) return new Result('rejected','Resend supports plain-text or HTML bodies here. Select SMTP or a raw-MIME transport for this message type.');
            $url=Http::endpoint('resend','https://api.resend.com/emails'); $headers['Authorization']='Bearer '.$c['api_key']; $headers['Idempotency-Key']=$m['id'];
            $formatter=new \PHPMailer\PHPMailer\PHPMailer(); $data=['from'=>$formatter->addrFormat([$m['from'],$m['from_name']]),'to'=>self::addresses($m['to']),'subject'=>$m['subject']];
            foreach (['cc','bcc','reply_to'] as $key) if ($m[$key]) $data[$key]=self::addresses($m[$key]);
            if ($m['html']!=='') $data['html']=$m['html']; if ($m['text']!=='' || !$m['html']) $data['text']=$m['text'];
            if ($m['attachments']) { $data['attachments']=[]; foreach ($m['attachments'] as $a) { $item=['filename'=>$a['filename'],'content'=>$a['content'],'content_type'=>$a['content_type']]; if ($a['disposition']==='inline') $item['content_id']=$a['content_id']; $data['attachments'][]=$item; } }
            foreach ($m['headers'] as [$key,$value]) $data['headers'][$key]=$value;
            $body=wp_json_encode($data,JSON_THROW_ON_ERROR);
        } elseif ($this->kind==='gmail') {
            if (empty($c['refresh_token'])) return new Result('rejected','Connect the Google account first.');
            try { $token=GoogleOAuth::exchange(['grant_type'=>'refresh_token','client_id'=>$c['client_id']??'','client_secret'=>$c['client_secret']??'','refresh_token'=>$c['refresh_token']]); }
            catch (\Throwable $e) { return new Result('rejected','Google authorization failed. Reconnect the account.'); }
            if (empty($token['access_token']) || preg_match('/[\r\n]/',$token['access_token'])) return new Result('rejected','Google did not return a valid access token.');
            $headers['Authorization']='Bearer '.$token['access_token']; $url=Http::endpoint('gmail','https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
            $mail=new \PHPMailer\PHPMailer\PHPMailer(); $raw=($m['bcc']?$mail->addrAppend('Bcc',$m['bcc']):'').$m['mime'];
            $body=wp_json_encode(['raw'=>rtrim(strtr(base64_encode($raw),'+/','-_'),'=')]);
        } else {
            if (empty($c['access_key']) || empty($c['secret_key'])) return new Result('rejected','Configure SES credentials first.');
            $region=$c['region']; $url=Http::endpoint('ses','https://email.'.$region.'.amazonaws.com/v2/email/outbound-emails');
            $data=['FromEmailAddress'=>$m['from'],'Destination'=>['ToAddresses'=>self::addresses($m['to']),'CcAddresses'=>self::addresses($m['cc']),'BccAddresses'=>self::addresses($m['bcc'])],'Content'=>['Raw'=>['Data'=>base64_encode($m['mime'])]]];
            $body=wp_json_encode($data,JSON_THROW_ON_ERROR); $host=parse_url($url,PHP_URL_HOST); $port=parse_url($url,PHP_URL_PORT); if ($port) $host.=':'.$port;
            $date=gmdate('Ymd\THis\Z'); $day=substr($date,0,8); $scope="$day/$region/ses/aws4_request";
            $signed=['content-type'=>'application/json','host'=>$host,'x-amz-date'=>$date]; if (!empty($c['session_token'])) $signed['x-amz-security-token']=$c['session_token']; ksort($signed);
            $canonical=''; foreach ($signed as $key=>$value) $canonical.=$key.':'.trim($value)."\n";
            $names=implode(';',array_keys($signed)); $request="POST\n".parse_url($url,PHP_URL_PATH)."\n\n$canonical\n$names\n".hash('sha256',$body);
            $key=hash_hmac('sha256',$day,'AWS4'.$c['secret_key'],true); foreach ([$region,'ses','aws4_request'] as $part) $key=hash_hmac('sha256',$part,$key,true);
            $signature=hash_hmac('sha256',"AWS4-HMAC-SHA256\n$date\n$scope\n".hash('sha256',$request),$key);
            $headers=$signed+['Authorization'=>"AWS4-HMAC-SHA256 Credential={$c['access_key']}/$scope, SignedHeaders=$names, Signature=$signature"];
        }
        $r=Http::request($url,$headers,$body);
        if (is_wp_error($r)) return new Result('unknown','Provider acknowledgement unavailable. Check the provider before sending again.');
        $status=wp_remote_retrieve_response_code($r); $data=json_decode(wp_remote_retrieve_body($r),true);
        if ($status>=200 && $status<300) { $id=$data[$this->kind==='ses'?'MessageId':'id']??''; if (!is_string($id) || $id==='') return new Result('unknown','Provider returned an incomplete acknowledgement. Check before resending.'); return new Result('accepted','Accepted by '.$this->label().'. Inbox delivery is not confirmed.',$id); }
        if ($status>=400 && $status<500 && $status!==408) return new Result('rejected','Provider rejected the request (HTTP '.$status.'). Check credentials, sender permissions, and limits.');
        return new Result('unknown','Provider returned HTTP '.$status.'. Check its message history before sending again.');
    }
}
