<?php
namespace TerribleMail;
defined('ABSPATH') || exit;

final class Secrets {
    public static function seal(array $value): string {
        $iv=random_bytes(12); $tag=''; $encrypted=openssl_encrypt(wp_json_encode($value, JSON_THROW_ON_ERROR),'aes-256-gcm',hash('sha256',wp_salt('auth').'terrible-mail',true),OPENSSL_RAW_DATA,$iv,$tag);
        if ($encrypted===false) throw new \RuntimeException('Cannot encrypt mail configuration.');
        return base64_encode($iv.$tag.$encrypted);
    }
    public static function open(string $value): array {
        $raw=base64_decode($value,true); if ($raw===false || strlen($raw)<28) throw new \RuntimeException('Mail settings cannot be decrypted. Save them again.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',wp_salt('auth').'terrible-mail',true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
        if ($plain===false) throw new \RuntimeException('Mail settings cannot be decrypted. Save them again.');
        return json_decode($plain,true,64,JSON_THROW_ON_ERROR);
    }
}
interface MailTransport {
    public function id(): string;
    public function label(): string;
    /** field => [label, type, default]. */
    public function fields(): array;
    public function validate(array $settings): void;
    /** Return accepted, rejected, or unknown. Never claim inbox delivery here. */
    public function send(array $message, array $settings): Result;
}
final class Result {
    public function __construct(public string $status, public string $detail, public string $providerId='') {
        if (!in_array($status,['accepted','rejected','unknown'],true)) throw new \InvalidArgumentException('Invalid submission outcome.');
    }
}
final class Transports {
    public const API_VERSION=1;
    public static function all(): array {
        $all=apply_filters('terrible_mail_transports',['smtp'=>new Smtp()]);
        foreach ($all as $id=>$transport) if (!$transport instanceof MailTransport || $transport->id()!==$id) throw new \RuntimeException('Invalid mail transport registration.');
        return $all;
    }
    public static function get(string $id): MailTransport { return self::all()[$id]??throw new \RuntimeException('Mail transport unavailable. Activate the matching Integrations plugin.'); }
}
final class Settings {
    public static function get(): array { $value=get_option('terrible_mail_settings',''); return $value ? Secrets::open($value) : ['enabled'=>false,'transport'=>'smtp','from'=>'','name'=>'','providers'=>[]]; }
    public static function connections(): array { $raw=get_option('terrible_mail_connections',''); return $raw?Secrets::open($raw):[]; }
    public static function remember(string $label): void {
        $label=sanitize_text_field($label); if (!$label) throw new \RuntimeException('Name the connection.'); $all=self::connections();
        foreach ($all as $saved) if (strcasecmp($saved['label'],$label)===0) { if ($saved['settings']===self::get()) return; throw new \RuntimeException('That name is already used. Choose another name or remove the old saved connection.'); }
        if (count($all)>=30) throw new \RuntimeException('Remove an old saved connection before adding another.');
        $all[wp_generate_uuid4()]=['label'=>$label,'settings'=>self::get()]; update_option('terrible_mail_connections',Secrets::seal($all),false);
    }
    public static function forget(string $id): void { $all=self::connections(); unset($all[$id]); update_option('terrible_mail_connections',Secrets::seal($all),false); if (get_option('terrible_mail_active_connection')===$id) delete_option('terrible_mail_active_connection'); }
    public static function useConnection(string $id): void { $saved=self::connections()[$id]??throw new \RuntimeException('Saved connection unavailable.'); self::save($saved['settings']); update_option('terrible_mail_active_connection',$id,false); }
    public static function forMessage(array $message): array { $id=$message['connection']??''; return $id?(self::connections()[$id]['settings']??throw new \RuntimeException('Original mail connection unavailable.')):self::get(); }
    public static function save(array $input): void {
        $old=self::get(); $id=(string)($input['transport']??$old['transport']); $provider=Transports::get($id);
        $config=$input['providers'][$id]??($old['providers'][$id]??[]);
        foreach ($provider->fields() as $key=>$field) if ($field[1]==='password' && ($config[$key]??'')==='') $config[$key]=$old['providers'][$id][$key]??'';
        $provider->validate($config); $from=(string)($input['from']??$old['from']);
        if (!is_email($from)) throw new \RuntimeException('Enter a valid sender email address.');
        $name=(string)($input['name']??''); if (preg_match('/[\r\n]/',$name)) throw new \RuntimeException('Invalid sender name.');
        $old['providers'][$id]=$config;
        $value=['enabled'=>!empty($input['enabled']),'transport'=>$id,'from'=>$from,'name'=>$name,'providers'=>$old['providers']];
        update_option('terrible_mail_settings',Secrets::seal($value),false); delete_option('terrible_mail_active_connection'); do_action('terrible_tools_event','mail.changed',0);
    }
}
final class History {
    public static function table(): string { global $wpdb; return $wpdb->prefix.'terrible_mail_history'; }
    public static function install(): void {
        if (get_option('terrible_mail_schema')==='2') return;
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $table=self::table();
        dbDelta("CREATE TABLE $table (
            seq bigint unsigned NOT NULL AUTO_INCREMENT,
            id varchar(36) NOT NULL,
            created bigint NOT NULL,
            updated bigint NOT NULL,
            transport varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            detail text NOT NULL,
            provider_id varchar(255) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            PRIMARY KEY  (seq),
            UNIQUE KEY id (id),
            KEY created (created)
        ) ".$wpdb->get_charset_collate().';');
        update_option('terrible_mail_schema','2',false);
    }
    public static function create(string $transport, array $message): string {
        global $wpdb; $id=wp_generate_uuid4();
        if (!$wpdb->insert(self::table(),['id'=>$id,'created'=>time(),'updated'=>time(),'transport'=>$transport,'status'=>'sending','detail'=>'Submission started.','provider_id'=>'','payload'=>Secrets::seal($message)])) throw new \RuntimeException('Cannot record mail submission; no message was sent.');
        return $id;
    }
    public static function finish(string $id, Result $result): void {
        global $wpdb; $values=['status'=>$result->status,'detail'=>$result->detail,'provider_id'=>substr($result->providerId,0,255),'updated'=>time()];
        if ($result->status!=='rejected') $values['payload']='';
        if ($wpdb->update(self::table(),$values,['id'=>$id])===false) throw new \RuntimeException('Submission result could not be saved. Check the provider before sending again.');
        self::cleanup();
    }
    public static function rows(): array { global $wpdb; return $wpdb->get_results('SELECT id,created,transport,status,detail,provider_id,(payload != \'\') AS retryable FROM '.self::table().' ORDER BY seq DESC LIMIT 100',ARRAY_A); }
    public static function retry(string $id): Result {
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%s',$id),ARRAY_A);
        if (!$row || $row['status']!=='rejected' || !$row['payload'] || (int)$row['created']<time()-DAY_IN_SECONDS) throw new \RuntimeException('Only definitively rejected messages retained within 24 hours can be retried.');
        $message=Secrets::open($row['payload']); $settings=Settings::forMessage($message);
        if (!Settings::get()['enabled'] || $settings['transport']!==$row['transport']) throw new \RuntimeException('Enable the original transport before retrying.');
        $provider=Transports::get($row['transport']); $message=Secrets::open($row['payload']);
        if ($wpdb->query($wpdb->prepare('UPDATE '.self::table()." SET status='sending',updated=%d WHERE id=%s AND status='rejected'",time(),$id))!==1) throw new \RuntimeException('This message is already being retried.');
        try { $r=$provider->send($message,$settings['providers'][$row['transport']]??[]); }
        catch (\Throwable $e) { $r=new Result('unknown','Submission interrupted. Check the provider before sending again.'); }
        self::finish($id,$r); return $r;
    }
    public static function cleanup(): void {
        global $wpdb; $table=self::table();
        $wpdb->query($wpdb->prepare("UPDATE $table SET status='unknown',detail='Submission interrupted. Check the provider before sending again.',payload='' WHERE status='sending' AND updated<%d",time()-300));
        $wpdb->query($wpdb->prepare("UPDATE $table SET payload='' WHERE created<%d",time()-DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created<%d AND status!='sending'",time()-7*DAY_IN_SECONDS));
        $cutoff=$wpdb->get_var("SELECT seq FROM $table ORDER BY seq DESC LIMIT 1 OFFSET 499");
        if ($cutoff) $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE seq<%d AND status!='sending'",$cutoff));
    }
}
final class Smtp implements MailTransport {
    public function id(): string { return 'smtp'; }
    public function label(): string { return 'SMTP'; }
    public function fields(): array { return ['host'=>['Hostname','text',''],'port'=>['Port','number','587'],'security'=>['Encryption: tls, ssl, or none (trusted relay only)','text','tls'],'username'=>['Username','text',''],'password'=>['Password','password','']]; }
    public function validate(array $c): void {
        if (!preg_match('/\A[a-zA-Z0-9.\-]+\z/',$c['host']??'') || (int)($c['port']??0)<1 || (int)$c['port']>65535 || !in_array($c['security']??'',['tls','ssl','none'],true)) throw new \RuntimeException('Enter a hostname, port 1–65535, and encryption tls, ssl, or none.');
        foreach (['username','password'] as $key) if (preg_match('/[\r\n]/',$c[$key]??'')) throw new \RuntimeException('Invalid SMTP credentials.');
    }
    public function send(array $m, array $c): Result {
        $this->validate($c); $mail=new \PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP(); $mail->Host=$c['host']; $mail->Port=(int)$c['port']; $mail->SMTPSecure=$c['security']==='none'?'':$c['security']; $mail->SMTPAutoTLS=$c['security']!=='none';
        $mail->SMTPAuth=($c['username']??'')!==''; $mail->Username=$c['username']??''; $mail->Password=$c['password']??''; $mail->Timeout=15;
        $mail->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
        $smtp=$mail->getSMTPInstance(); $smtp->Timelimit=30; $dataStarted=false;
        try {
            if (!$mail->smtpConnect()) return new Result('rejected','SMTP connection failed. Check host, port, encryption, and credentials.');
            if (!$smtp->mail($m['from'])) return new Result('rejected','SMTP rejected the sender. Check the configured sender address.');
            foreach (array_merge($m['to'],$m['cc'],$m['bcc']) as $address) if (!$smtp->recipient($address[0])) { $smtp->reset(); return new Result('rejected','SMTP rejected a recipient. No message body was submitted.'); }
            $dataStarted=true; $ok=$smtp->data($m['mime']);
            if ($ok) return new Result('accepted','Accepted by the SMTP server. Inbox delivery is not confirmed.',(string)$smtp->getLastTransactionID());
            $code=(int)($smtp->getError()['smtp_code']??0);
            return new Result($code>=400?'rejected':'unknown',$code>=400?'SMTP rejected the message.':'SMTP acknowledgement was lost. Check the server before sending again.');
        } catch (\Throwable $e) { return new Result($dataStarted?'unknown':'rejected',$dataStarted?'SMTP submission interrupted. Check the server before sending again.':'SMTP connection or authentication failed.'); }
        finally { $mail->smtpClose(); }
    }
}
final class Delivery {
    public static bool $recorded=false;
    public static function prepare($pre, array $atts) {
        if ($pre!==null) return $pre;
        self::$recorded=false;
        try {
            $settings=Settings::get(); if (!$settings['enabled']) return null;
            global $phpmailer;
            if (isset($phpmailer) && !$phpmailer instanceof Mailer && get_class($phpmailer)!=='WP_PHPMailer' && get_class($phpmailer)!=='PHPMailer\\PHPMailer\\PHPMailer') throw new \RuntimeException('Another mail plugin owns delivery. Disable it before enabling Terrible Mail.');
            if (!$phpmailer instanceof Mailer) $phpmailer=new Mailer(true);
            $phpmailer::$validator=static fn($email)=>(bool)is_email($email);
        } catch (\Throwable $e) { do_action('wp_mail_failed',new \WP_Error('terrible_mail_configuration',$e->getMessage())); return false; }
        return null;
    }
    public static function configure($mailer): void {
        if (!$mailer instanceof Mailer) return;
        $c=Settings::get(); if (!$c['enabled']) return;
        $mailer->isSMTP(); $mailer->setFrom($c['from'],$c['name'],false); $mailer->Sender=$c['from'];
    }
    public static function invalid($error): void {
        if (self::$recorded) return;
        try { $c=Settings::get(); if (!$c['enabled']) return; $id=History::create($c['transport'],[]); History::finish($id,new Result('rejected','Message preparation failed. Check addresses, attachments, and mail configuration.')); global $wpdb; $wpdb->update(History::table(),['payload'=>''],['id'=>$id]); } catch (\Throwable $e) { /* Preserve WordPress failure handling. */ }
    }
    public static function submit(array $message): Result {
        $c=Settings::get(); $id=History::create($c['transport'],$message); self::$recorded=true;
        try { $provider=Transports::get($c['transport']); }
        catch (\Throwable $e) { $r=new Result('rejected','Transport unavailable. Activate the matching Integrations plugin.'); History::finish($id,$r); return $r; }
        try { $r=$provider->send($message,$c['providers'][$c['transport']]??[]); }
        catch (\Throwable $e) { $r=new Result('unknown','Submission interrupted. Check the provider before sending again.'); }
        History::finish($id,$r); return $r;
    }
}
foreach (['PHPMailer/PHPMailer.php','PHPMailer/SMTP.php','PHPMailer/Exception.php','class-wp-phpmailer.php'] as $file) require_once ABSPATH.WPINC.'/'.$file;
class Mailer extends \WP_PHPMailer {
    public function postSend() {
        if (!Settings::get()['enabled']) return parent::postSend();
        try {
            $attachments=[];
            foreach ($this->getAttachments() as $a) {
                if (!$a[5] && (!is_file($a[0]) || filesize($a[0])>10485760)) throw new \RuntimeException('An attachment is unavailable or exceeds 10 MiB.');
                $bytes=$a[5]?$a[0]:file_get_contents($a[0]);
                $attachments[]=['filename'=>$a[2],'content'=>base64_encode($bytes),'content_type'=>$a[4],'disposition'=>$a[6],'content_id'=>$a[7]];
            }
            $mime=$this->getSentMIMEMessage(); if (strlen($mime)>15728640) throw new \RuntimeException('Message exceeds the 15 MiB limit.');
            $message=['connection'=>(string)get_option('terrible_mail_active_connection',''),'id'=>wp_generate_uuid4(),'mime'=>$mime,'from'=>$this->From,'from_name'=>$this->FromName,'to'=>$this->getToAddresses(),'cc'=>$this->getCcAddresses(),'bcc'=>$this->getBccAddresses(),'reply_to'=>array_values($this->getReplyToAddresses()),'subject'=>$this->Subject,'content_type'=>$this->ContentType,'html'=>$this->ContentType==='text/html'?$this->Body:'','text'=>$this->ContentType==='text/html'?$this->AltBody:$this->Body,'attachments'=>$attachments,'headers'=>$this->getCustomHeaders()];
            $result=Delivery::submit($message);
            if ($result->status!=='accepted') throw new \RuntimeException($result->detail);
            return true;
        } catch (\Throwable $e) { throw new \PHPMailer\PHPMailer\Exception($e->getMessage()); }
    }
}
