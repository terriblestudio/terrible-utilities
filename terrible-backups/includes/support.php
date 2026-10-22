<?php
namespace TerribleBackups;
defined('ABSPATH') || exit;
final class Support {
    public static function storage(): string {
        $path = defined('TERRIBLE_BACKUPS_DIR') ? TERRIBLE_BACKUPS_DIR : sys_get_temp_dir() . '/terrible-backups-' . substr(hash('sha256', ABSPATH . wp_salt('auth')), 0, 20);
        if (!is_dir($path) && !mkdir($path, 0700, true)) { throw new \RuntimeException('Cannot create private backup storage. Set TERRIBLE_BACKUPS_DIR outside the web root.'); }
        $path = realpath($path);
        foreach ([ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH] as $root) {
            $root = realpath($root);
            if ($root && ($path === $root || str_starts_with($path . '/', $root . '/'))) { throw new \RuntimeException('Backup storage must be outside the web root.'); }
        }
        if (!is_writable($path)) { throw new \RuntimeException('Private backup storage is not writable.'); }
        chmod($path, 0700);
        return $path;
    }
    public static function id(string $id): string {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) { throw new \RuntimeException('Invalid backup identifier.'); }
        return $id;
    }
    public static function path(string $name): string {
        if ($name === '' || preg_match('/[\x00-\x1f\x7f]/', $name) || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('~(^|/)\.\.?(/|$)|:~', $name)) { throw new \RuntimeException('Unsafe archive path.'); }
        return $name;
    }
    public static function json(string $path, array $value): void {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($tmp, $path)) { throw new \RuntimeException('Cannot save backup state.'); }
        chmod($path, 0600);
    }
    public static function read(string $path): array { return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
    public static function remove(string $path): void {
        if (is_link($path) || is_file($path)) { if (!unlink($path)) { throw new \RuntimeException('Cannot remove a working file.'); } }
        elseif (is_dir($path)) { foreach (new \FilesystemIterator($path) as $f) { self::remove($f->getPathname()); } if (!rmdir($path)) { throw new \RuntimeException('Cannot remove a working directory.'); } }
    }
    public static function quote(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
    public static function query(string $sql): mixed {
        global $wpdb;
        $result = $wpdb->query($sql);
        if ($result === false) { throw new \RuntimeException('Database operation failed: ' . $wpdb->last_error); }
        return $result;
    }
    public static function url(string $url): string {
        if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_USER) || strpbrk($url, "\r\n")) { throw new \RuntimeException('Enter a full http:// or https:// site URL without credentials.'); }
        return untrailingslashit($url);
    }
    // Parse PHP serialization as bytes. Never instantiate classes or invoke __wakeup().
    public static function replace(string $value, string $from, string $to): string {
        if ($from === '' || $from === $to || (!str_contains($value, $from) && !str_contains($value, str_replace('/', '\\/', $from)))) { return $value; }
        if (is_serialized($value) || preg_match('/\A[aOCsE]:[0-9]+:/', $value)) {
            $pos = 0;
            try { $out = self::serialized($value, $pos, $from, $to, 0); if ($pos === strlen($value)) { return $out; } }
            catch (\Throwable $e) { throw new \RuntimeException('Unsupported or malformed serialized data; import stopped before database activation.'); }
            throw new \RuntimeException('Malformed serialized data.');
        }
        return str_replace([$from, str_replace('/', '\/', $from)], [$to, str_replace('/', '\/', $to)], $value);
    }
    private static function serialized(string $s, int &$p, string $from, string $to, int $depth): string {
        if ($depth > 128) { throw new \RuntimeException('Serialization nesting limit.'); }
        $rest = substr($s, $p);
        if (preg_match('/\AN;/', $rest, $m) || preg_match('/\A[bidirR]:[^;]*;/', $rest, $m)) { $p += strlen($m[0]); return $m[0]; }
        if (preg_match('/\As:(\d+):"/', $rest, $m)) {
            $p += strlen($m[0]); $v = substr($s, $p, (int)$m[1]); $p += (int)$m[1];
            if (substr($s, $p, 2) !== '";') { throw new \RuntimeException('Invalid string.'); } $p += 2;
            $v = str_replace([$from, str_replace('/', '\/', $from)], [$to, str_replace('/', '\/', $to)], $v);
            return 's:' . strlen($v) . ':"' . $v . '";';
        }
        if (preg_match('/\Aa:(\d+):\{/', $rest, $m)) { $header = $m[0]; $count = (int)$m[1]; }
        elseif (preg_match('/\AO:(\d+):"([^"\r\n]*)":(\d+):\{/', $rest, $m) && strlen($m[2]) === (int)$m[1]) { $header = $m[0]; $count = (int)$m[3]; }
        else { throw new \RuntimeException('Unsupported serialization.'); }
        $p += strlen($header); $out = $header;
        if ($count > 1000000) { throw new \RuntimeException('Serialization size limit.'); }
        for ($i = 0; $i < $count * 2; $i++) { $out .= self::serialized($s, $p, $from, $to, $depth + 1); }
        if (($s[$p++] ?? '') !== '}') { throw new \RuntimeException('Invalid container.'); }
        return $out . '}';
    }
    public static function categories(): array { return ['database' => 'Database (all tables with this site’s prefix)', 'uploads' => 'Media uploads', 'plugins' => 'Plugins', 'themes' => 'Themes', 'mu-plugins' => 'Must-use plugins', 'content' => 'Other wp-content files', 'core' => 'WordPress core', 'root' => 'Root files and configuration (backup only)']; }
    public static function selected(array $input): array { $out = array_values(array_intersect(array_keys(self::categories()), $input)); if (!$out) { throw new \RuntimeException('Choose at least one component.'); } return $out; }
    public static function environment(): void {
        if (is_multisite()) { throw new \RuntimeException('Version 0.1 supports single-site WordPress only.'); }
        if (!class_exists('ZipArchive')) { throw new \RuntimeException('The PHP ZIP extension is required.'); }
        if (wp_normalize_path(WP_PLUGIN_DIR) !== wp_normalize_path(WP_CONTENT_DIR . '/plugins') || wp_normalize_path(WPMU_PLUGIN_DIR) !== wp_normalize_path(WP_CONTENT_DIR . '/mu-plugins')) { throw new \RuntimeException('Custom plugin directories outside wp-content are not supported in this version.'); }
        if (PHP_INT_SIZE < 8) { throw new \RuntimeException('64-bit PHP is required.'); }
    }
}
