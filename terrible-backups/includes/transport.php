<?php
namespace TerribleBackups;
defined('ABSPATH') || exit;
final class Transport {
    public const CHUNK = 8388608;
    public static function save(array $config): void {
        foreach (['ftp', 's3'] as $kind) {
            $c = $config[$kind] ?? [];
            if ($kind === 's3' && !empty($c['endpoint'])) { self::endpoint($c['endpoint']); }
            if ($kind === 'ftp' && !empty($c['host']) && !preg_match('/\A[a-zA-Z0-9.\-]+\z/', $c['host'])) { throw new \RuntimeException('Invalid FTP hostname.'); }
        }
        $old = self::settings();
        foreach (Providers::all() as $id => $provider) {
            if (!isset($config[$id])) { continue; }
            foreach ($provider->fields() as $field => $definition) {
                if (($definition[1] ?? '') === 'password' && ($config[$id][$field] ?? '') === '') { $config[$id][$field] = $old[$id][$field] ?? ''; }
            }
            $provider->validate($config[$id]);
        }
        // Keep unavailable modules' settings when another provider is edited.
        $config = array_replace($old, $config);
        foreach (['ftp' => 'password', 's3' => 'secret'] as $kind => $field) { if (($config[$kind][$field] ?? '') === '') { $config[$kind][$field] = $old[$kind][$field] ?? ''; } }
        if (($config['s3']['token'] ?? '') === '') { $config['s3']['token'] = $old['s3']['token'] ?? ''; }
        if (!empty($config['s3']['clear_token'])) { $config['s3']['token'] = ''; }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt(json_encode($config, JSON_THROW_ON_ERROR), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new \RuntimeException('Cannot encrypt storage settings.'); }
        Support::json(Support::storage() . '/remote.json', ['data' => base64_encode($iv . $tag . $cipher)]);
    }
    public static function connection(string $id): array {
        $settings=self::settings();
        if (str_starts_with($id,'saved-')) {
            $saved=$settings['_connections'][$id]??throw new \RuntimeException('Saved destination unavailable. Restore its configuration before continuing.');
            return ['provider'=>Providers::get($saved['provider']), 'config'=>$saved['config']];
        }
        return ['provider'=>Providers::get($id), 'config'=>$settings[$id]??[]];
    }
    public static function configured(string $id, array $config): bool {
        $provider=Providers::all()[$id]??null;
        return $provider && ($provider instanceof ConfiguredProvider ? $provider->configured($config) : (bool)apply_filters('terrible_backups_destination_configured', !empty($config), $id, $config));
    }
    public static function destinations(): array {
        $settings=self::settings(); $labels=[]; $saved=$settings['_connections']??[];
        foreach (Providers::all() as $id=>$provider) {
            $config=$settings[$id]??[];
            if (!self::configured($id,$config)) continue;
            $duplicate=false; foreach ($saved as $item) if ($item['provider']===$id && $item['config']===$config) $duplicate=true;
            if (!$duplicate) $labels[$id]=$provider->label();
        }
        foreach ($saved as $id=>$item) if (self::configured($item['provider'],$item['config'])) $labels[$id]=$item['label'];
        return $labels;
    }
    public static function remember(string $provider,string $label): void {
        Providers::get($provider); $settings=self::settings(); $config=$settings[$provider]??[];
        if (!self::configured($provider,$config)) throw new \RuntimeException('Configure and connect this provider before saving a destination.');
        $label=sanitize_text_field($label); if ($label==='') throw new \RuntimeException('Name this destination.');
        foreach ($settings['_connections']??[] as $item) if (strcasecmp($label,$item['label'])===0) {
            if ($item['provider']===$provider && $item['config']===$config) return;
            throw new \RuntimeException('That name is already used. Update the existing destination or choose a different name.');
        }
        if (count($settings['_connections']??[])>=30) throw new \RuntimeException('The limit is 30 saved destinations.');
        $settings['_connections']['saved-'.wp_generate_uuid4()]=['label'=>$label,'provider'=>$provider,'config'=>$config]; self::save($settings);
        do_action('terrible_tools_event','backups.changed');
    }
    public static function edit(string $id,array $input): void {
        $settings=self::settings();$item=$settings['_connections'][$id]??throw new \RuntimeException('Destination no longer exists.');
        $provider=Providers::get($item['provider']);$config=array_replace($item['config'],$input);
        $secretFields=$item['provider']==='s3'?['secret','token']:($item['provider']==='ftp'?['password']:[]);
        foreach($provider->fields() as$key=>$field)if(($field[1]??'')==='password')$secretFields[]=$key;
        foreach($secretFields as$key)if(($input[$key]??'')==='')$config[$key]=$item['config'][$key]??'';
        if($item['provider']==='s3') { if(!empty($config['endpoint']))self::endpoint($config['endpoint']);if(!empty($config['clear_token']))$config['token']='';unset($config['clear_token']); }
        if($item['provider']==='ftp' && !preg_match('/\A[a-zA-Z0-9.\-]+\z/',$config['host']??''))throw new \RuntimeException('Invalid FTP hostname.');
        $provider->validate($config);if(!self::configured($item['provider'],$config))throw new \RuntimeException('Complete the required connection settings.');
        $settings['_connections'][$id]['config']=$config;self::save($settings);do_action('terrible_tools_event','backups.changed');
    }
    public static function manage(string $id,string $operation,string $label=''): void {
        $settings=self::settings(); $item=$settings['_connections'][$id]??throw new \RuntimeException('Destination no longer exists.');
        if ($operation==='remove') unset($settings['_connections'][$id]);
        else {
            $label=sanitize_text_field($label); if ($label==='') throw new \RuntimeException('Name this destination.');
            foreach ($settings['_connections'] as $key=>$other) if ($key!==$id && strcasecmp($label,$other['label'])===0) throw new \RuntimeException('That destination name is already used.');
            $item['label']=$label;
            if ($operation==='update') {
                $config=$settings[$item['provider']]??[];
                if (!self::configured($item['provider'],$config)) throw new \RuntimeException('Configure and connect the provider first.');
                $item['config']=$config;
            } elseif ($operation!=='rename') throw new \RuntimeException('Unknown destination operation.');
            $settings['_connections'][$id]=$item;
        }
        self::save($settings); do_action('terrible_tools_event',$operation==='remove'?'backups.removed':'backups.changed');
    }
    public static function settings(): array {
        $path = Support::storage() . '/remote.json'; if (!is_file($path)) { return []; }
        $raw = base64_decode(Support::read($path)['data'], true);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) { throw new \RuntimeException('Storage credentials cannot be decrypted. Save them again.'); }
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
    private static function endpoint(string $url): array {
        $p = parse_url($url);
        $allow = defined('TERRIBLE_BACKUPS_ALLOW_HTTP') && TERRIBLE_BACKUPS_ALLOW_HTTP;
        if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['scheme'] ?? '', $allow ? ['http', 'https'] : ['https'], true)) { throw new \RuntimeException('S3 endpoint must be an HTTPS URL without credentials, query or fragment.'); }
        return $p;
    }
    public static function key(array $config, string $name): string {
        Support::path($name);
        Support::path(trim($config['prefix'] ?? '', '/') === '' ? $name : trim($config['prefix'], '/') . '/' . $name);
        return trim($config['prefix'] ?? '', '/') === '' ? $name : trim($config['prefix'], '/') . '/' . $name;
    }
    public static function s3(array $c, string $method, string $key, array $query = [], string $body = '', array $extra = []): array {
        if (!function_exists('curl_init')) { throw new \RuntimeException('The PHP cURL extension is required.'); }
        $region = $c['region'] ?? 'us-east-1';
        $endpoint = rtrim($c['endpoint'] ?? '', '/');
        if ($endpoint === '') { $endpoint = 'https://s3.' . $region . '.amazonaws.com'; }
        $p = self::endpoint($endpoint);
        if (!preg_match('/\A[a-zA-Z0-9.\-_]+\z/', $c['bucket'] ?? '') || empty($c['access']) || empty($c['secret'])) { throw new \RuntimeException('Configure the S3 bucket and credentials first.'); }
        $host = $p['host']; $base = rtrim($p['path'] ?? '', '/');
        if (!empty($c['virtual'])) { $host = $c['bucket'] . '.' . $host; $path = $base . '/' . $key; }
        else { $path = $base . '/' . $c['bucket'] . '/' . $key; }
        $host .= isset($p['port']) ? ':' . $p['port'] : '';
        $uri = implode('/', array_map('rawurlencode', explode('/', $path)));
        ksort($query); $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $date = gmdate('Ymd\THis\Z'); $day = substr($date, 0, 8); $hash = hash('sha256', $body);
        $headers = array_merge(['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $date], $extra);
        if (!empty($c['token'])) { $headers['x-amz-security-token'] = $c['token']; }
        ksort($headers); $canonicalHeaders = '';
        foreach ($headers as $k => $v) { $canonicalHeaders .= strtolower($k) . ':' . trim(preg_replace('/\s+/', ' ', $v)) . "\n"; }
        $signed = implode(';', array_keys($headers)); $scope = "$day/$region/s3/aws4_request";
        $canonical = "$method\n$uri\n$qs\n$canonicalHeaders\n$signed\n$hash";
        $signKey = hash_hmac('sha256', $day, 'AWS4' . $c['secret'], true);
        foreach ([$region, 's3', 'aws4_request'] as $part) { $signKey = hash_hmac('sha256', $part, $signKey, true); }
        $signature = hash_hmac('sha256', "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical), $signKey);
        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$c['access']}/$scope, SignedHeaders=$signed, Signature=$signature";
        $lines = []; foreach ($headers as $k => $v) { if (strpbrk($v, "\r\n")) { throw new \RuntimeException('Invalid storage header.'); } $lines[] = "$k: $v"; }
        $responseHeaders = []; $response = ''; $ch = curl_init($p['scheme'] . '://' . $host . $uri . ($qs === '' ? '' : '?' . $qs));
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) { if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $responseHeaders[strtolower(trim($k))] = trim($v); } return strlen($line); }, CURLOPT_WRITEFUNCTION => static function ($ch, $data) use (&$response) { if (strlen($response) + strlen($data) > self::CHUNK + 1048576) { return 0; } $response .= $data; return strlen($data); }]);
        if ($body !== '' || in_array($method, ['PUT', 'POST'], true)) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($ok === false || $code < 200 || ($code >= 300 && !($method === 'DELETE' && $code === 404))) { throw new \RuntimeException('S3 request failed (HTTP ' . $code . '). Check endpoint, region, permissions and credentials.'); }
        return ['body' => $response, 'headers' => $responseHeaders, 'status' => $code];
    }
    private static function ftpHandle(array $c, string $key): mixed {
        if (!function_exists('curl_init')) { throw new \RuntimeException('The PHP cURL extension is required.'); }
        if (!preg_match('/\A[a-zA-Z0-9.\-]+\z/', $c['host'] ?? '')) { throw new \RuntimeException('Configure an FTP hostname first.'); }
        $url = 'ftp://' . $c['host'] . ':' . (int)($c['port'] ?? 21) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_USERNAME => $c['user'] ?? '', CURLOPT_PASSWORD => $c['password'] ?? '', CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90, CURLOPT_FTP_SKIP_PASV_IP => true, CURLOPT_USE_SSL => !empty($c['tls']) ? CURLUSESSL_ALL : CURLUSESSL_NONE, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_RETURNTRANSFER => true]);
        return $ch;
    }
    public static function remoteSize(string $kind, array $c, string $key): int { return self::connection($kind)['provider']->size($c, $key); }
    public static function transfer(array &$j, string $dir): bool {
        $connection=self::connection($j['remote']); $provider=$connection['provider']; $config=$connection['config'];
        if (isset($j['connection_hash']) && !hash_equals($j['connection_hash'], hash('sha256', wp_json_encode($config)))) {
            throw new \RuntimeException('Storage settings changed during this transfer. Start a new transfer with the intended connection.');
        }
        return $provider->transfer($j, $dir, $config);
    }
    public static function abort(array $j): void {
        if (($j['type'] ?? '') !== 'transfer' || ($j['phase'] ?? '') === 'done') { return; }
        $connection=self::connection($j['remote']); $connection['provider']->abort($j, $connection['config']);
    }
    public static function builtinSize(string $kind, array $c, string $key): int {
        if ($kind === 's3') { $r = self::s3($c, 'HEAD', $key); $size = (int)($r['headers']['content-length'] ?? -1); }
        else { $ch = self::ftpHandle($c, $key); curl_setopt($ch, CURLOPT_NOBODY, true); $ok = curl_exec($ch); $size = $ok === false ? -1 : (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD); curl_close($ch); }
        if ($size < 1) { throw new \RuntimeException('Remote archive is missing, empty, or its size cannot be read.'); }
        return $size;
    }
    public static function builtinTransfer(array &$j, string $dir, array $c): bool {
        $kind = $j['provider'] ?? $j['remote']; $key = $j['key'];
        $path = $dir . '/archive.zip'; $offset = $j['offset']; $length = min(self::CHUNK, $j['size'] - $offset);
        if ($j['direction'] === 'upload') {
            $f = fopen($path, 'rb'); fseek($f, $offset); $body = fread($f, $length); fclose($f);
            if (strlen($body) !== $length) { throw new \RuntimeException('Cannot read archive for transfer.'); }
            if ($kind === 's3') {
                if (empty($j['upload_id'])) {
                    $r = self::s3($c, 'POST', $key, ['uploads' => '']);
                    $xml = simplexml_load_string($r['body'], 'SimpleXMLElement', LIBXML_NONET);
                    $j['upload_id'] = (string)$xml->UploadId; if (!$j['upload_id']) { throw new \RuntimeException('S3 did not return an upload ID.'); }
                    Support::json($dir . '/job.json', $j);
                }
                $part = intdiv($offset, self::CHUNK) + 1;
                if ($part > 10000) { throw new \RuntimeException('Archive exceeds this version’s 78 GiB S3 upload limit.'); }
                $r = self::s3($c, 'PUT', $key, ['partNumber' => $part, 'uploadId' => $j['upload_id']], $body);
                $j['etags'][$part] = $r['headers']['etag'] ?? ''; if (!$j['etags'][$part]) { throw new \RuntimeException('S3 did not return a part checksum.'); }
            } else {
                $remote = $key . '.part-' . $j['id'];
                $ch = self::ftpHandle($c, $remote); $slice = fopen('php://temp', 'w+b'); fwrite($slice, $body); rewind($slice);
                curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $slice, CURLOPT_INFILESIZE => $length, CURLOPT_PREQUOTE => $offset > 0 ? ['REST ' . $offset] : []]);
                $ok = curl_exec($ch); fclose($slice); curl_close($ch);
                if ($ok === false || self::remoteSize('ftp', $c, $remote) !== $offset + $length) { throw new \RuntimeException('FTP upload failed, or server does not support resumed uploads.'); }

            }
        } else {
            if ($kind === 's3') {
                $r = self::s3($c, 'GET', $key, [], '', ['range' => 'bytes=' . $offset . '-' . ($offset + $length - 1)]);
                $body = $r['body']; if ($r['status'] !== 206 && $j['size'] > self::CHUNK) { throw new \RuntimeException('S3 endpoint did not honor a ranged download.'); }
            } else {
                $body = ''; $ch = self::ftpHandle($c, $key);
                curl_setopt_array($ch, [CURLOPT_RANGE => $offset . '-' . ($offset + $length - 1), CURLOPT_WRITEFUNCTION => static function ($ch, $data) use (&$body, $length) { if (strlen($body) + strlen($data) > $length) { return 0; } $body .= $data; return strlen($data); }]);
                $ok = curl_exec($ch); curl_close($ch); if ($ok === false) { throw new \RuntimeException('FTP download failed. Check credentials, TLS certificate and server range support.'); }

            }
            if (strlen($body) !== $length) { throw new \RuntimeException('Remote archive changed or transfer was incomplete.'); }
            $f = fopen($path, 'c+b'); fseek($f, $offset); $written = fwrite($f, $body); fclose($f);
            if ($written !== $length) { throw new \RuntimeException('Not enough disk space for the remote archive.'); }
        }
        $j['offset'] += $length;
        if ($j['offset'] < $j['size']) { return false; }
        if ($kind === 'ftp' && $j['direction'] === 'upload') {
            if (strpbrk($key, "\r\n")) { throw new \RuntimeException('Invalid FTP path.'); }
            $ch = self::ftpHandle($c, $key . '.part-' . $j['id']); curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_POSTQUOTE => ['RNFR ' . basename($key) . '.part-' . $j['id'], 'RNTO ' . basename($key)]]); $ok = curl_exec($ch); curl_close($ch); if ($ok === false) { throw new \RuntimeException('FTP could not finalize the archive.'); }
        }
        if ($kind === 's3' && $j['direction'] === 'upload') {
            $xml = '<CompleteMultipartUpload>'; foreach ($j['etags'] as $part => $etag) { $xml .= '<Part><PartNumber>' . (int)$part . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>'; } $xml .= '</CompleteMultipartUpload>';
            $r = self::s3($c, 'POST', $key, ['uploadId' => $j['upload_id']], $xml, ['content-type' => 'application/xml']);
            if (str_contains($r['body'], '<Error')) { throw new \RuntimeException('S3 could not complete the multipart upload.'); }
        }
        return true;
    }
    public static function builtinAbort(array $j, array $c): void {
        if (($j['provider'] ?? $j['remote'] ?? '') === 's3' && !empty($j['upload_id']) && ($j['phase'] ?? '') !== 'done') { self::s3($c, 'DELETE', $j['key'], ['uploadId' => $j['upload_id']]); }
    }
}
