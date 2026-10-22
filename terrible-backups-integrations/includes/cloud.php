<?php
namespace TerribleBackupsIntegrations;
use TerribleBackups\{StorageProvider, CatalogProvider, Support, Transport};
defined('ABSPATH') || exit;

final class Http {
    public static function base(string $service): string {
        $defaults = ['dropbox_api'=>'https://api.dropboxapi.com', 'dropbox_content'=>'https://content.dropboxapi.com', 'dropbox_auth'=>'https://www.dropbox.com', 'google'=>'https://www.googleapis.com', 'google_token'=>'https://oauth2.googleapis.com', 'google_auth'=>'https://accounts.google.com'];
        return rtrim(defined('TERRIBLE_BACKUPS_TEST_ENDPOINTS') ? (TERRIBLE_BACKUPS_TEST_ENDPOINTS[$service] ?? $defaults[$service]) : $defaults[$service], '/');
    }
    public static function request(string $url, string $method, array $headers = [], string $body = '', array $allowed = [200]): array {
        $origins = array_map([self::class, 'base'], ['dropbox_api','dropbox_content','dropbox_auth','google','google_token','google_auth']);
        $permitted = false; foreach ($origins as $origin) { if (str_starts_with($url, $origin . '/')) $permitted = true; }
        if (!$permitted) throw new \RuntimeException('The provider returned an untrusted transfer endpoint.');
        $r = wp_remote_request($url, ['method'=>$method, 'headers'=>$headers, 'body'=>$body, 'timeout'=>60, 'redirection'=>0, 'limit_response_size'=>Transport::CHUNK + 1048576]);
        if (is_wp_error($r)) throw new \RuntimeException('Storage connection failed. Check connectivity and retry the transfer.');
        $status = wp_remote_retrieve_response_code($r);
        if (!in_array($status, $allowed, true)) throw new \RuntimeException('Storage request failed (HTTP ' . $status . '). Check connection permissions, available space, and rate limits.');
        return ['status'=>$status, 'body'=>wp_remote_retrieve_body($r), 'headers'=>wp_remote_retrieve_headers($r)];
    }
    public static function json(array $response): array { $v = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR); if (!is_array($v)) throw new \RuntimeException('Invalid provider response.'); return $v; }
}
final class OAuth {
    public static function redirect(): string { return admin_url('admin-post.php?action=terrible_backups_oauth'); }
    public static function token(string $kind, array $config): string {
        $url = $kind === 'dropbox' ? Http::base('dropbox_api') . '/oauth2/token' : Http::base('google_token') . '/token';
        if (empty($config['refresh_token'])) throw new \RuntimeException('Connect this storage account first.');
        $r = Http::json(Http::request($url, 'POST', ['Content-Type'=>'application/x-www-form-urlencoded'], http_build_query(['grant_type'=>'refresh_token','refresh_token'=>$config['refresh_token'],'client_id'=>$config['client_id'] ?? '', 'client_secret'=>$config['client_secret'] ?? ''])));
        if (empty($r['access_token']) || preg_match('/[\r\n]/', $r['access_token'])) throw new \RuntimeException('Storage authorization failed. Reconnect the account.');
        return $r['access_token'];
    }
    public static function connect(): void {
        if (!current_user_can('manage_options')) wp_die('Administrator access required.', '', ['response'=>403]);
        $kind = sanitize_key($_GET['provider'] ?? ''); check_admin_referer('tb-connect-' . $kind);
        if (!in_array($kind, ['dropbox','gdrive'], true)) wp_die('Unknown provider.');
        $config = Transport::settings()[$kind] ?? [];
        if (empty($config['client_id']) || empty($config['client_secret'])) wp_die('Save the application ID and secret first.');
        $state = bin2hex(random_bytes(32)); set_transient('tb_oauth_' . hash('sha256', $state), ['user'=>get_current_user_id(), 'kind'=>$kind], 600);
        $args = ['client_id'=>$config['client_id'], 'response_type'=>'code', 'redirect_uri'=>self::redirect(), 'state'=>$state];
        if ($kind === 'dropbox') { $url = Http::base('dropbox_auth') . '/oauth2/authorize'; $args['token_access_type'] = 'offline'; }
        else { $url = Http::base('google_auth') . '/o/oauth2/v2/auth'; $args += ['scope'=>'https://www.googleapis.com/auth/drive.file','access_type'=>'offline','prompt'=>'consent']; }
        wp_redirect($url . '?' . http_build_query($args)); exit;
    }
    public static function callback(): void {
        if (!current_user_can('manage_options')) wp_die('Administrator access required.', '', ['response'=>403]);
        $state = (string)($_GET['state'] ?? ''); $key = 'tb_oauth_' . hash('sha256', $state); $saved = get_transient($key); delete_transient($key);
        if (!$saved || $saved['user'] !== get_current_user_id() || empty($_GET['code'])) wp_die('Connection expired or was denied. Start again.');
        try {
            $kind = $saved['kind']; $config = Transport::settings()[$kind] ?? [];
            $url = $kind === 'dropbox' ? Http::base('dropbox_api') . '/oauth2/token' : Http::base('google_token') . '/token';
            $r = Http::json(Http::request($url, 'POST', ['Content-Type'=>'application/x-www-form-urlencoded'], http_build_query(['grant_type'=>'authorization_code','code'=>wp_unslash($_GET['code']),'client_id'=>$config['client_id'],'client_secret'=>$config['client_secret'],'redirect_uri'=>self::redirect()])));
            if (empty($r['refresh_token'])) throw new \RuntimeException('No offline access was granted. Reconnect and grant offline access.');
            $config['refresh_token'] = $r['refresh_token']; Transport::save([$kind=>$config]);
            wp_safe_redirect(admin_url('tools.php?page=terrible-backups')); exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage())); }
    }
}
final class CloudStorage implements StorageProvider, CatalogProvider, \TerribleBackups\ConfiguredProvider {
    public function __construct(private string $kind) {}
    public function id(): string { return $this->kind; }
    public function label(): string { return $this->kind === 'dropbox' ? 'Dropbox' : 'Google Drive'; }
    public function configured(array $c): bool { return !empty($c['client_id']) && !empty($c['client_secret']) && !empty($c['refresh_token']); }
    public function fields(): array { return ['client_id'=>['Application client ID','text',''], 'client_secret'=>['Application secret','password',''], 'refresh_token'=>['Refresh token (or use Connect account)','password',''], 'prefix'=>[$this->kind === 'dropbox' ? 'Archive folder (must exist)' : 'Archive name prefix','text','']]; }
    public function validate(array $c): void {
        foreach (['client_id','client_secret','refresh_token'] as $k) if (isset($c[$k]) && (!is_string($c[$k]) || strlen($c[$k]) > 8192 || preg_match('/[\r\n]/', $c[$k]))) throw new \RuntimeException('Invalid storage credential.');
        if (!empty($c['prefix'])) Support::path(trim($c['prefix'], '/'));
    }
    private function call(array $c, string $route, array $args = [], ?string $body = null, array $headers = [], array $allowed = [200]): array {
        $token = OAuth::token($this->kind, $c);
        if ($this->kind === 'dropbox') {
            $headers['Authorization'] = 'Bearer ' . $token;
            if ($body !== null) { $headers['Dropbox-API-Arg'] = wp_json_encode($args); $headers['Content-Type'] = 'application/octet-stream'; $base = Http::base('dropbox_content'); }
            else { $body = wp_json_encode($args); $headers['Content-Type'] = 'application/json'; $base = Http::base('dropbox_api'); }
            return Http::request($base . '/2/files/' . $route, 'POST', $headers, $body, $allowed);
        }
        return Http::request(Http::base('google') . $route, $body === null ? 'GET' : 'POST', ['Authorization'=>'Bearer ' . $token] + $headers, $body ?? '', $allowed);
    }
    private function metadata(array $c, string $key): array {
        if ($this->kind === 'dropbox') return Http::json($this->call($c, 'get_metadata', ['path'=>'/' . $key]));
        $q = "trashed = false and name = '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $key) . "' and appProperties has { key='terrible-backups' and value='1' }";
        $r = Http::json($this->call($c, '/drive/v3/files?' . http_build_query(['q'=>$q,'fields'=>'files(id,name,size,md5Checksum)','pageSize'=>2])));
        if (count($r['files'] ?? []) !== 1) throw new \RuntimeException('Archive missing or ambiguous. Use a unique archive name.');
        return $r['files'][0];
    }
    public function size(array $c, string $key): int { $n = (int)($this->metadata($c, $key)['size'] ?? 0); if ($n < 1) throw new \RuntimeException('Remote archive is empty or missing.'); return $n; }
    public function catalog(array $c, string $cursor = ''): array {
        if ($this->kind === 'dropbox') {
            $r = Http::json($this->call($c, $cursor ? 'list_folder/continue' : 'list_folder', $cursor ? ['cursor'=>$cursor] : ['path'=>empty($c['prefix']) ? '' : '/' . trim($c['prefix'], '/'), 'limit'=>100]));
            $files = []; foreach ($r['entries'] ?? [] as $f) if (($f['.tag'] ?? '') === 'file' && str_ends_with(strtolower($f['name']), '.zip')) $files[] = ['key'=>$f['name'],'size'=>(int)$f['size']];
            return ['files'=>$files, 'cursor'=>!empty($r['has_more']) ? $r['cursor'] : ''];
        }
        $args = ['q'=>"trashed = false and appProperties has { key='terrible-backups' and value='1' }",'fields'=>'nextPageToken,files(name,size)','pageSize'=>100]; if ($cursor) $args['pageToken']=$cursor;
        $r = Http::json($this->call($c, '/drive/v3/files?' . http_build_query($args))); $files = [];
        $prefix = trim($c['prefix'] ?? '', '/'); $prefix = $prefix === '' ? '' : $prefix . '/';
        foreach ($r['files'] ?? [] as $f) if (str_starts_with($f['name'], $prefix) && str_ends_with(strtolower($f['name']), '.zip')) $files[]=['key'=>substr($f['name'],strlen($prefix)), 'size'=>(int)$f['size']];
        return ['files'=>$files, 'cursor'=>$r['nextPageToken'] ?? ''];
    }
    private static function checkpoint(array $j, string $dir): void { Support::json($dir . '/job.json', $j); }
    private function digest(string $path): string {
        if ($this->kind !== 'dropbox') return hash_file('md5', $path);
        $outer = hash_init('sha256'); $f = fopen($path, 'rb'); while (!feof($f)) { $block = fread($f, 4194304); if ($block !== '') hash_update($outer, hash('sha256', $block, true)); } fclose($f); return hash_final($outer);
    }
    public function transfer(array &$j, string $dir, array $c): bool {
        $path = $dir . '/archive.zip'; $offset = $j['offset']; $length = min(Transport::CHUNK, $j['size'] - $offset); $key = $j['key'];
        if ($j['direction'] === 'download') {
            if (!isset($j['cloud_metadata'])) { $j['cloud_metadata'] = $this->metadata($c, $key); self::checkpoint($j, $dir); }
            $m = $j['cloud_metadata'];
            if ($this->kind === 'dropbox') $r = $this->call($c, 'download', ['path'=>$m['id'] ?? '/' . $key, 'rev'=>$m['rev'] ?? ''], '', ['Range'=>'bytes=' . $offset . '-' . ($offset + $length - 1)], [200,206]);
            else $r = $this->call($c, '/drive/v3/files/' . rawurlencode($m['id']) . '?alt=media', [], null, ['Range'=>'bytes=' . $offset . '-' . ($offset + $length - 1)], [200,206]);
            if (strlen($r['body']) !== $length || ($j['size'] > Transport::CHUNK && $r['status'] !== 206)) throw new \RuntimeException('Incomplete or unsupported ranged download.');
            $f = fopen($path,'c+b'); fseek($f,$offset); $written=fwrite($f,$r['body']); fclose($f); if ($written !== $length) throw new \RuntimeException('Cannot write downloaded archive.');
            $j['offset'] += $length;
            if ($j['offset'] < $j['size']) return false;
            $expected = $m[$this->kind === 'dropbox' ? 'content_hash' : 'md5Checksum'] ?? '';
            if ($expected === '' || !hash_equals($expected, $this->digest($path))) throw new \RuntimeException('Remote archive checksum mismatch. Download again.');
            return true;
        }
        if (!isset($j['cloud_digest'])) { $j['cloud_digest'] = $this->digest($path); self::checkpoint($j, $dir); }
        $f=fopen($path,'rb'); fseek($f,$offset); $body=fread($f,$length); fclose($f); if (strlen($body)!==$length) throw new \RuntimeException('Cannot read archive.');
        if ($this->kind === 'dropbox') {
            // Empty start is safe to replay; checkpoints keep credentials out of state.
            if (empty($j['cloud_session'])) { $r=Http::json($this->call($c,'upload_session/start',['close'=>false],'')); $j['cloud_session']=$r['session_id']; self::checkpoint($j,$dir); }
            $final = $offset + $length === $j['size'];
            $args=['cursor'=>['session_id'=>$j['cloud_session'],'offset'=>$offset]];
            if ($final) $args['commit']=['path'=>'/'.$key,'mode'=>'add','autorename'=>false,'mute'=>true,'strict_conflict'=>true]; else $args['close']=false;
            $r=$this->call($c,$final?'upload_session/finish':'upload_session/append_v2',$args,$body,[],[200,409]);
            if ($r['status']===409) {
                $error=Http::json($r); $ack=null;
                array_walk_recursive($error,static function($value,$name) use (&$ack) { if ($name==='correct_offset') $ack=(int)$value; });
                if (!$final && $ack===$offset+$length) { $j['offset']=$ack; return false; }
                if ($final) { $m=$this->metadata($c,$key); if ((int)($m['size']??0)!==$j['size'] || ($m['content_hash']??'')!==$j['cloud_digest']) throw new \RuntimeException('Dropbox upload conflict. Use a new archive name.'); }
                else throw new \RuntimeException('Dropbox upload offset conflict. Start a new transfer.');
            } elseif ($final) $m=Http::json($r);
            $j['offset'] += $length;
            if (!$final) return false;
        } else {
            $token=OAuth::token($this->kind,$c); $headers=['Authorization'=>'Bearer '.$token];
            if (empty($j['cloud_file'])) { $r=Http::json($this->call($c,'/drive/v3/files/generateIds?count=1&space=drive&type=files')); $j['cloud_file']=$r['ids'][0]; self::checkpoint($j,$dir); }
            $resume = !empty($j['cloud_session']);
            if (!$resume) {
                $r=Http::request(Http::base('google').'/upload/drive/v3/files?uploadType=resumable&fields=id,size,md5Checksum','POST',$headers+['Content-Type'=>'application/json','X-Upload-Content-Length'=>(string)$j['size'],'X-Upload-Content-Type'=>'application/zip'],wp_json_encode(['id'=>$j['cloud_file'],'name'=>$key,'appProperties'=>['terrible-backups'=>'1']]),[200]);
                $j['cloud_session']=(string)($r['headers']['location']??''); if (!$j['cloud_session']) throw new \RuntimeException('Provider did not return an upload session.'); self::checkpoint($j,$dir);
            }
            if ($resume) {
                $status=Http::request($j['cloud_session'],'PUT',$headers+['Content-Range'=>'bytes */'.$j['size']],'',[200,201,308,404]);
                if (in_array($status['status'],[200,201],true)) { $m=Http::json($status); if ((int)($m['size']??0)!==$j['size'] || ($m['md5Checksum']??'')!==$j['cloud_digest']) throw new \RuntimeException('Remote upload verification failed.'); $j['offset']=$j['size']; $j['cloud_verified']=true; return true; }
                if ($status['status']===404) {
                    $m=Http::json($this->call($c,'/drive/v3/files/'.rawurlencode($j['cloud_file']).'?fields=id,size,md5Checksum'));
                    if ((int)($m['size']??0)!==$j['size'] || ($m['md5Checksum']??'')!==$j['cloud_digest']) throw new \RuntimeException('Upload session expired. Start a new transfer.');
                    $j['offset']=$j['size']; $j['cloud_verified']=true; return true;
                }
                $ack=0; if (preg_match('/^bytes=0-(\d+)$/',(string)($status['headers']['range']??''),$match)) $ack=(int)$match[1]+1;
                if ($ack!==$offset) { if ($ack<$offset || $ack>$j['size'] || $ack%Transport::CHUNK!==0) throw new \RuntimeException('Unexpected provider upload offset.'); $j['offset']=$ack; return false; }
            }
            $r=Http::request($j['cloud_session'],'PUT',$headers+['Content-Type'=>'application/zip','Content-Range'=>'bytes '.$offset.'-'.($offset+$length-1).'/'.$j['size']],$body,[200,201,308]);
            if ($r['status']===308) {
                if (($r['headers']['range']??'') !== 'bytes=0-'.($offset+$length-1)) throw new \RuntimeException('Provider acknowledged an unexpected upload offset.');
                $j['offset'] += $length; return false;
            }
            $m=Http::json($r); $j['offset'] += $length;
        }
        $actual=$m[$this->kind==='dropbox'?'content_hash':'md5Checksum']??'';
        if ((int)($m['size']??0)!==$j['size'] || !hash_equals($j['cloud_digest'],$actual)) throw new \RuntimeException('Uploaded archive verification failed. Keep the local backup.');
        $j['cloud_verified']=true; return true;
    }
    public function abort(array $j, array $c): void {
        // No committed archive is deleted on cancellation. Incomplete provider sessions expire.
    }
}
