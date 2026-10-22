<?php
namespace TerribleBackups;
defined('ABSPATH') || exit;
final class Engine {
    public static function dir(string $id): string { return Support::storage() . '/' . Support::id($id); }
    public static function load(string $id): array { return Support::read(self::dir($id) . '/job.json'); }
    private static function save(array $j): void { $j['updated'] = time(); Support::json(self::dir($j['id']) . '/job.json', $j); }
    private static function locked(callable $fn): mixed {
        $f = fopen(Support::storage() . '/lock', 'c');
        if (!flock($f, LOCK_EX | LOCK_NB)) { fclose($f); throw new \RuntimeException('Another backup request is running. Try again.'); }
        try { return $fn(); } finally { flock($f, LOCK_UN); fclose($f); }
    }
    private static function create(string $type): array {
        Support::environment();
        foreach (glob(Support::storage() . '/*/job.json') as $path) { $j = Support::read($path); if (!in_array($j['phase'], ['done', 'failed', 'cancelled', 'ready'], true)) { throw new \RuntimeException('Finish or cancel the current job first.'); } }
        $id = bin2hex(random_bytes(16)); mkdir(self::dir($id), 0700);
        return ['id' => $id, 'type' => $type, 'owner' => get_current_user_id(), 'created' => time(), 'updated' => time(), 'phase' => 'starting', 'message' => 'Starting', 'warnings' => [], 'index' => 0];
    }
    public static function backup(array $parts): array {
        return self::locked(static function () use ($parts) {
            global $wpdb;
            $j = self::create('backup'); $j['parts'] = Support::selected($parts); $j['phase'] = 'scan'; $j['tables'] = []; $j['table_index'] = 0; $j['row_offset'] = 0;
            $manifest = ['format' => 'terrible-backups', 'version' => 1, 'created' => gmdate('c'), 'home' => home_url(), 'siteurl' => site_url(), 'prefix' => $wpdb->prefix, 'wordpress' => get_bloginfo('version'), 'parts' => $j['parts'], 'files' => [], 'tables' => []];
            if (in_array('database', $j['parts'], true)) {
                $triggers = $wpdb->get_results($wpdb->prepare('SHOW TRIGGERS LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
                if ($triggers) { throw new \RuntimeException('Database triggers are not supported in this version.'); }
                $tables = $wpdb->get_results($wpdb->prepare('SHOW FULL TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'), ARRAY_N);
                foreach ($tables as $table) { if ($table[1] !== 'BASE TABLE') { throw new \RuntimeException('Database views are not supported in this version.'); } $j['tables'][] = $table[0]; }
            }
            Support::json(self::dir($j['id']) . '/manifest.json', $manifest); self::save($j); return $j;
        });
    }
    public static function upload(int $size): array {
        return self::locked(static function () use ($size) {
            if ($size < 1 || $size > (int)disk_free_space(Support::storage()) - 104857600) { throw new \RuntimeException('Archive exceeds available disk space.'); }
            $j = self::create('upload'); $j['phase'] = 'upload'; $j['size'] = $size; $j['offset'] = 0; self::save($j); return $j;
        });
    }
    public static function chunk(string $id, int $offset, string $input): array {
        return self::locked(static function () use ($id, $offset, $input) {
            $j = self::load($id); if (!in_array($j['phase'], ['upload', 'ready'], true)) { throw new \RuntimeException('This upload is not accepting chunks.'); }
            $length = strlen($input); if (!$length || $length > 1048576 || $offset < 0 || $offset + $length > $j['size']) { throw new \RuntimeException('Invalid upload chunk.'); }
            $path = self::dir($id) . '/archive.zip';
            // A lost response can safely replay its last acknowledged chunk.
            if ($offset < $j['offset']) {
                $f = fopen($path, 'rb'); fseek($f, $offset); $same = hash_equals(hash('sha256', fread($f, $length)), hash('sha256', $input)); fclose($f);
                if (!$same || $offset + $length > $j['offset']) { throw new \RuntimeException('Upload replay did not match the saved data.'); } return $j;
            }
            if ($offset !== $j['offset']) { throw new \RuntimeException('Upload offset does not match. Reselect the same file to resume.'); }
            $f = fopen($path, 'c+b'); fseek($f, $offset); $written = fwrite($f, $input); fflush($f); fclose($f);
            if ($written !== $length) { throw new \RuntimeException('Cannot write upload chunk. Check free disk space.'); }
            $j['offset'] += $length; if ($j['offset'] === $j['size']) { $j['phase'] = 'ready'; $j['message'] = 'Uploaded; ready to inspect.'; }
            self::save($j); return $j;
        });
    }
    public static function remote(string $kind, string $name, ?string $source = null): array {
        return self::locked(static function () use ($kind, $name, $source) {
            $connection=Transport::connection($kind);
            $c = $connection['config']; $key = Transport::key($c, $name);
            $j = self::create('transfer'); $dir = self::dir($j['id']);
            $j['provider']=$connection['provider']->id(); $j['connection_hash'] = hash('sha256', wp_json_encode($c)); $j['remote'] = $kind; $j['key'] = $key; $j['direction'] = $source ? 'upload' : 'download'; $j['offset'] = 0;
            if ($source) {
                $src = self::load($source); if (!in_array($src['phase'], ['done', 'ready'], true) || !is_file(self::dir($source) . '/archive.zip')) { throw new \RuntimeException('Source archive is not ready.'); }
                if (!copy(self::dir($source) . '/archive.zip', $dir . '/archive.zip')) { throw new \RuntimeException('Cannot prepare remote transfer.'); }
                $j['size'] = filesize($dir . '/archive.zip');
            } else { $j['size'] = Transport::remoteSize($kind, $c, $key); if ($j['size'] > disk_free_space($dir) - 104857600) { throw new \RuntimeException('Not enough disk space.'); } }
            $j['phase'] = 'transfer'; self::save($j); return $j;
        });
    }
    public static function inspect(string $id): array {
        $j = self::load($id); if (!in_array($j['phase'], ['done', 'ready'], true)) { throw new \RuntimeException('Archive is not ready.'); }
        $zip = self::zip(self::dir($id) . '/archive.zip');
        try {
            $stat = $zip->statName('manifest.json'); if (!$stat || $stat['size'] > 33554432) { throw new \RuntimeException('Missing or oversized archive manifest.'); }
            $m = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($m['format'] ?? '') !== 'terrible-backups' || ($m['version'] ?? 0) !== 1 || !is_array($m['files'] ?? null) || !is_array($m['tables'] ?? null) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $m['prefix'] ?? '')) { throw new \RuntimeException('Unsupported backup format. Use a Terrible Backups ZIP archive.'); }
            Support::url($m['home']); Support::url($m['siteurl']);
            $seen = []; $total = 0;
            if (count($m['files']) > 200000 || count($m['tables']) > 5000 || $zip->numFiles !== count($m['files']) + 1) { throw new \RuntimeException('Archive inventory is invalid or exceeds supported limits.'); }
            foreach ($m['files'] as $f) {
                $name = Support::path($f['name']);
                if (!preg_match('~\A(database|uploads|plugins|themes|mu-plugins|content|core|root)/.+~', $name) || isset($seen[$name]) || !preg_match('/\A[a-f0-9]{64}\z/', $f['sha256'] ?? '') || !is_int($f['size']) || $f['size'] < 0) { throw new \RuntimeException('Archive contains an invalid entry.'); }
                $stat = $zip->statName($name); if (!$stat || $stat['size'] !== $f['size']) { throw new \RuntimeException('Archive file size does not match its manifest.'); }
                $opsys = 0; $attr = 0; $zip->getExternalAttributesName($name, $opsys, $attr);
                if ((($attr >> 16) & 0170000) === 0120000) { throw new \RuntimeException('Symbolic links are not allowed in an archive.'); }
                $seen[$name] = true; $total += $f['size'];
            }
            for ($i = 0; $i < $zip->numFiles; $i++) { $name = $zip->getNameIndex($i); if ($name !== 'manifest.json' && !isset($seen[$name])) { throw new \RuntimeException('Unexpected archive entry.'); } }
            $tables = [];
            foreach ($m['tables'] as $t) {
                if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $t['name']) || !str_starts_with($t['name'], $m['prefix']) || isset($tables[$t['name']]) || !isset($seen[$t['file']]) || !str_starts_with($t['file'], 'database/')) { throw new \RuntimeException('Invalid database inventory.'); }
                $tables[$t['name']] = true;
            }
            $m['expanded_size'] = $total; return $m;
        } finally { $zip->close(); }
    }
    public static function restore(string $id, array $parts, string $url): array {
        return self::locked(static function () use ($id, $parts, $url) {
            $m = self::inspect($id); $parts = array_values(array_intersect(Support::selected($parts), $m['parts'])); $parts = array_values(array_diff($parts, ['root']));
            if (!$parts) { throw new \RuntimeException('No restorable components selected. Root configuration is backup only.'); }
            if ($url !== '') { $url = Support::url($url); }
            if (file_exists(ABSPATH . '.maintenance')) { throw new \RuntimeException('WordPress is already in maintenance mode.'); }
            $j = self::create('restore'); $dir = self::dir($j['id']);
            if ($m['expanded_size'] * 2 + filesize(self::dir($id) . '/archive.zip') + 104857600 > disk_free_space($dir)) { throw new \RuntimeException('Restore needs space for the archive, staged files and rollback copies.'); }
            if (!copy(self::dir($id) . '/archive.zip', $dir . '/archive.zip')) { throw new \RuntimeException('Cannot stage archive.'); }
            $j['source'] = $id; $j['parts'] = $parts; $j['url'] = $url; $j['phase'] = 'validate'; $j['index'] = 0; $j['token'] = bin2hex(random_bytes(32)); $j['staged_tables'] = []; $j['table_index'] = 0; $j['row_offset'] = 0; $j['journal'] = []; $j['db_swapped'] = false;
            Support::json($dir . '/manifest.json', $m); mkdir($dir . '/stage', 0700); mkdir($dir . '/rollback', 0700); self::save($j); return $j;
        });
    }
    private static function zip(string $path, bool $create = false): \ZipArchive {
        $zip = new \ZipArchive(); if ($zip->open($path, $create ? \ZipArchive::CREATE : \ZipArchive::RDONLY) !== true) { throw new \RuntimeException('Cannot open backup ZIP.'); } return $zip;
    }
    public static function step(string $id): array {
        return self::locked(static function () use ($id) {
            $j = self::load($id); $dir = self::dir($id);
            if (in_array($j['phase'], ['done', 'ready', 'failed', 'cancelled'], true)) { if ($j['phase'] === 'done' && $j['type'] === 'restore') { self::unmaintenance($j); } return $j; }
            try {
                if ($j['type'] === 'backup') { self::backupStep($j, $dir); }
                elseif ($j['type'] === 'transfer') { if (Transport::transfer($j, $dir)) { $j['phase'] = $j['direction'] === 'download' ? 'ready' : 'done'; } $j['message'] = 'Transferred ' . size_format($j['offset']) . ' / ' . size_format($j['size']); }
                elseif ($j['type'] === 'restore') { self::restoreStep($j, $dir); }
            } catch (\Throwable $e) {
                $j['error'] = $e->getMessage();
                if ($j['type'] === 'restore') {
                    try { self::rollback($j, $dir); } catch (\Throwable $rollback) { $j['error'] .= ' Rollback needs attention: ' . $rollback->getMessage(); $j['phase'] = 'recovery'; self::save($j); return $j; }
                }
                $j['phase'] = 'failed'; $j['message'] = $j['error'];
            }
            self::save($j);
            if ($j['phase']==='done' && in_array($j['type'],['backup','restore'],true)) do_action('terrible_tools_event',$j['type']==='restore'?'backups.restored':'backups.completed',0);
            return $j;
        });
    }
    private static function scan(array &$j, string $dir): void {
        $list = []; $root = rtrim(ABSPATH, '/'); $content = rtrim(WP_CONTENT_DIR, '/'); $uploads = rtrim(wp_upload_dir()['basedir'], '/');
        $walk = static function (string $base, string $prefix, callable $classify) use (&$list, &$j) {
            if (!is_dir($base)) { return; }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $f) {
                $rel = substr($f->getPathname(), strlen($base) + 1); $category = $classify($rel);
                if (!$category || !in_array($category, $j['parts'], true)) { continue; }
                if ($f->isLink()) { if (count($j['warnings']) < 100) { $j['warnings'][] = 'Skipped symbolic link: ' . $prefix . $rel; } continue; }
                if (!$f->isFile()) { continue; }
                if (!$f->isReadable()) { throw new \RuntimeException('Unreadable file: ' . $prefix . $rel); }
                $name = $category . '/' . ($prefix === 'content/' && in_array($category, ['plugins', 'themes', 'uploads', 'mu-plugins'], true) ? substr($rel, strlen($category) + 1) : $rel);
                Support::path($name); $list[] = ['source' => $f->getPathname(), 'name' => $name];
                if (count($list) > 200000) { throw new \RuntimeException('This version supports up to 200,000 files per archive.'); }
            }
        };
        $walk($root, '', static function ($rel) use ($content, $root, $uploads) {
            if (str_starts_with($root . '/' . $rel, $content . '/') || str_starts_with($root . '/' . $rel, $uploads . '/') || $rel === '.maintenance') { return null; }
            if (str_starts_with($rel, 'wp-admin/') || str_starts_with($rel, 'wp-includes/') || (!str_contains($rel, '/') && preg_match('/^(wp-.*\.php|index\.php|xmlrpc\.php|license\.txt|readme\.html)$/', $rel) && $rel !== 'wp-config.php')) { return 'core'; }
            return 'root';
        });
        $walk($content, 'content/', static function ($rel) use ($content, $uploads) { if (str_starts_with($content . '/' . $rel, $uploads . '/')) { return null; } $first = explode('/', $rel)[0]; return in_array($first, ['plugins', 'themes', 'mu-plugins'], true) ? $first : 'content'; });
        $walk($uploads, 'uploads/', static fn($rel) => 'uploads');
        Support::json($dir . '/inventory.json', $list); $j['count'] = count($list); $j['phase'] = 'files'; $j['message'] = 'Inventoried ' . count($list) . ' files.';
    }
    private static function backupStep(array &$j, string $dir): void {
        global $wpdb;
        if ($j['phase'] === 'scan') { self::scan($j, $dir); return; }
        $m = Support::read($dir . '/manifest.json');
        if ($j['phase'] === 'files') {
            $list = Support::read($dir . '/inventory.json'); $zip = self::zip($dir . '/archive.zip', true); $bytes = 0; $n = 0;
            try {
                while ($j['index'] < count($list) && $n++ < 40 && $bytes < 33554432) {
                    $f = $list[$j['index']]; $source = $f['source'];
                    if (is_link($source) || !is_file($source)) { throw new \RuntimeException('A file changed during backup. Retry while site writes are paused.'); }
                    $size = filesize($source); $hash = hash_file('sha256', $source);
                    if (!$zip->addFile($source, $f['name'])) { throw new \RuntimeException('Cannot add file to archive.'); }
                    $zip->setCompressionName($f['name'], \ZipArchive::CM_STORE);
                    $m['files'][] = ['name' => $f['name'], 'size' => $size, 'sha256' => $hash]; $bytes += $size; $j['index']++;
                }
            } finally { if (!$zip->close()) { throw new \RuntimeException('Cannot finish archive batch. Check free space.'); } }
            $j['message'] = 'Archived ' . $j['index'] . ' / ' . count($list) . ' files.';
            if ($j['index'] >= count($list)) { $j['phase'] = 'database'; }
        } elseif ($j['phase'] === 'database') {
            $name = $j['tables'][$j['table_index']] ?? null;
            if ($name === null) { $j['phase'] = 'seal'; }
            else {
                $file = 'database/' . $name . '.ndjson'; $path = $dir . '/table.ndjson';
                if ($j['row_offset'] === 0) {
                    $create = $wpdb->get_row('SHOW CREATE TABLE ' . Support::quote($name), ARRAY_N);
                    if (!$create || str_contains($create[1], 'FOREIGN KEY')) { throw new \RuntimeException('Tables with foreign keys are not supported in this version.'); }
                    $columns = $wpdb->get_results('SHOW FULL COLUMNS FROM ' . Support::quote($name), ARRAY_A);
                    $cols = array_values(array_map(static fn($c) => $c['Field'], array_filter($columns, static fn($c) => !str_contains($c['Extra'], 'GENERATED'))));
                    $j['columns'] = $cols;
                    file_put_contents($path, json_encode(['schema' => $create[1], 'columns' => $cols], JSON_THROW_ON_ERROR) . "\n");
                }
                $cols = implode(',', array_map([Support::class, 'quote'], $j['columns']));
                $rows = $wpdb->get_results('SELECT ' . $cols . ' FROM ' . Support::quote($name) . ' LIMIT 100 OFFSET ' . (int)$j['row_offset'], ARRAY_N);
                if ($wpdb->last_error) { throw new \RuntimeException('Cannot read database table.'); }
                $f = fopen($path, 'ab'); foreach ($rows as $row) { $line = json_encode(array_map(static fn($v) => $v === null ? null : base64_encode($v), $row), JSON_THROW_ON_ERROR) . "\n"; if (fwrite($f, $line) !== strlen($line)) { throw new \RuntimeException('Cannot write database export.'); } } fclose($f);
                $j['row_offset'] += count($rows); $j['message'] = 'Exporting ' . $name . ': ' . $j['row_offset'] . ' rows.';
                if (count($rows) < 100) {
                    $zip = self::zip($dir . '/archive.zip', true); $zip->addFile($path, $file); $zip->setCompressionName($file, \ZipArchive::CM_STORE); if (!$zip->close()) { throw new \RuntimeException('Cannot archive database.'); }
                    $m['files'][] = ['name' => $file, 'size' => filesize($path), 'sha256' => hash_file('sha256', $path)]; $m['tables'][] = ['name' => $name, 'file' => $file, 'rows' => $j['row_offset']];
                    unlink($path); $j['table_index']++; $j['row_offset'] = 0;
                }
            }
        } elseif ($j['phase'] === 'seal') {
            $zip = self::zip($dir . '/archive.zip', true); $zip->addFromString('manifest.json', json_encode($m, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) { throw new \RuntimeException('Cannot finalize archive.'); }
            $j['phase'] = 'verify'; $j['index'] = 0; $j['message'] = 'Checking completed archive.'; $j['size'] = filesize($dir . '/archive.zip'); $j['sha256'] = hash_file('sha256', $dir . '/archive.zip');
        }
        if ($j['phase'] === 'verify') {
            $zip = self::zip($dir . '/archive.zip'); $bytes = 0; $n = 0;
            try { while ($j['index'] < count($m['files']) && $n++ < 40 && $bytes < 33554432) {
                $entry = $m['files'][$j['index']]; $stream = $zip->getStream($entry['name']);
                if (!$stream) { throw new \RuntimeException('Cannot verify completed archive.'); }
                $hash = hash_init('sha256'); $read = hash_update_stream($hash, $stream); fclose($stream);
                if ($read !== $entry['size'] || !hash_equals($entry['sha256'], hash_final($hash))) { throw new \RuntimeException('A source file changed during backup. Pause site writes and retry.'); }
                $bytes += $read; $j['index']++;
            } } finally { $zip->close(); }
            $j['message'] = 'Verified ' . $j['index'] . ' / ' . count($m['files']) . ' files.';
            if ($j['index'] === count($m['files'])) { $j['phase'] = 'done'; $j['message'] = 'Backup ready.'; }
        }
        Support::json($dir . '/manifest.json', $m);
    }
    private static function destination(string $name): ?string {
        [$part, $rel] = explode('/', Support::path($name), 2);
        if (in_array($part, ['root', 'database'], true)) { return null; }
        if ($part === 'core') {
            if (!(str_starts_with($rel, 'wp-admin/') || str_starts_with($rel, 'wp-includes/') || (!str_contains($rel, '/') && preg_match('/^(wp-.*\.php|index\.php|xmlrpc\.php|license\.txt|readme\.html)$/', $rel) && $rel !== 'wp-config.php'))) { throw new \RuntimeException('Archive has an invalid WordPress core path.'); }
            $base = rtrim(ABSPATH, '/');
        } else {
            if (!in_array($part, ['uploads', 'plugins', 'themes', 'mu-plugins', 'content'], true)) { throw new \RuntimeException('Invalid content category.'); }
            if ($part === 'plugins' && explode('/', $rel)[0] === basename(dirname(TERRIBLE_BACKUPS_FILE))) { return null; }
            if ($part === 'content' && in_array(explode('/', $rel)[0], ['uploads', 'plugins', 'themes', 'mu-plugins'], true)) { throw new \RuntimeException('Overlapping content category.'); }
            $base = $part === 'uploads' ? rtrim(wp_upload_dir()['basedir'], '/') : rtrim(WP_CONTENT_DIR, '/') . ($part === 'content' ? '' : '/' . $part);
        }
        $path = $base . '/' . $rel;
        // Check all ancestors, including the root, to prevent writes through existing symlinks.
        $cursor = $path;
        while ($cursor !== dirname($cursor)) { if (is_link($cursor)) { throw new \RuntimeException('Restore destination contains a symbolic link.'); } $cursor = dirname($cursor); }
        return $path;
    }
    private static function restoreStep(array &$j, string $dir): void {
        global $wpdb;
        $m = Support::read($dir . '/manifest.json');
        if ($j['phase'] === 'validate') {
            $zip = self::zip($dir . '/archive.zip'); $n = 0; $bytes = 0;
            try {
                while ($j['index'] < count($m['files']) && $n++ < 30 && $bytes < 33554432) {
                    $entry = $m['files'][$j['index']]; $name = $entry['name']; $part = explode('/', $name)[0];
                    $input = $zip->getStream($name); if (!$input) { throw new \RuntimeException('Cannot read archive entry.'); }
                    $selected = in_array($part, $j['parts'], true); $output = null;
                    if ($selected) { $path = $dir . '/stage/' . $name; if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); } $output = fopen($path, 'wb'); if (!$output) { throw new \RuntimeException('Cannot stage archive entry.'); } }
                    $hash = hash_init('sha256'); $size = 0;
                    try { while (!feof($input)) { $chunk = fread($input, 1048576); if ($chunk === false) { throw new \RuntimeException('Cannot read ZIP stream.'); } $size += strlen($chunk); if ($size > $entry['size']) { throw new \RuntimeException('Archive entry exceeds declared size.'); } hash_update($hash, $chunk); if ($output && fwrite($output, $chunk) !== strlen($chunk)) { throw new \RuntimeException('Cannot stage file. Check disk space.'); } } }
                    finally { fclose($input); if ($output) { fclose($output); } }
                    if ($size !== $entry['size'] || !hash_equals($entry['sha256'], hash_final($hash))) { throw new \RuntimeException('Archive checksum failed: ' . $name); }
                    if ($selected && $part !== 'database') { self::destination($name); }
                    $bytes += $size; $j['index']++;
                }
            } finally { $zip->close(); }
            $j['message'] = 'Verified ' . $j['index'] . ' / ' . count($m['files']) . ' archive entries.';
            if ($j['index'] === count($m['files'])) { $j['phase'] = 'stage_database'; $j['index'] = 0; }
        } elseif ($j['phase'] === 'stage_database') {
            $t = in_array('database', $j['parts'], true) ? ($m['tables'][$j['table_index']] ?? null) : null;
            if (!$t) { $j['phase'] = 'commit_files'; $j['message'] = 'Validation complete. Applying files.'; return; }
            $f = fopen($dir . '/stage/' . $t['file'], 'rb'); $header = json_decode(fgets($f, 16777216), true, 512, JSON_THROW_ON_ERROR);
            $target = $wpdb->prefix . substr($t['name'], strlen($m['prefix']));
            if (strlen($target) > 64 || !preg_match('/\A[a-zA-Z0-9_]+\z/', $target)) { throw new \RuntimeException('Invalid target database table.'); }
            $stage = 'tb_' . substr($j['id'], 0, 12) . '_' . $j['table_index'];
            if ($j['row_offset'] === 0) {
                $schema = $header['schema']; $prefix = 'CREATE TABLE ' . Support::quote($t['name']);
                if (!str_starts_with($schema, $prefix . ' (') || str_contains($schema, 'FOREIGN KEY') || str_contains($schema, 'DATA DIRECTORY') || str_contains($schema, 'INDEX DIRECTORY')) { throw new \RuntimeException('Unsupported database schema.'); }
                $j['staged_tables'][$target] = $stage; self::save($j);
                Support::query('DROP TABLE IF EXISTS ' . Support::quote($stage));
                Support::query('CREATE TABLE ' . Support::quote($stage) . substr($schema, strlen($prefix)));
                $j['row_offset'] = ftell($f);
            } else { fseek($f, $j['row_offset']); }
            $columns = $header['columns']; foreach ($columns as $col) { if (!is_string($col) || strlen($col) > 64) { throw new \RuntimeException('Invalid database column.'); } }
            $n = 0;
            while ($n++ < 100 && ($line = fgets($f, 67108865)) !== false) {
                if (!str_ends_with($line, "\n")) { throw new \RuntimeException('A database row exceeds the 64 MiB import limit.'); }
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR); if (count($row) !== count($columns)) { throw new \RuntimeException('Database row has the wrong column count.'); }
                $values = [];
                foreach ($row as $i => $raw) {
                    if ($raw === null) { $values[] = 'NULL'; continue; }
                    $v = base64_decode($raw, true); if ($v === false) { throw new \RuntimeException('Invalid encoded database value.'); }
                    if ($j['url'] !== '' && $columns[$i] !== 'guid') {
                        $sourceSite = untrailingslashit($m['siteurl']); $sourceHome = untrailingslashit($m['home']);
                        // Replace a distinct site URL through a temporary value to avoid cascading
                        // when the destination itself contains the source URL (e.g. /staging).
                        $placeholder = 'urn:terrible-backups:' . $j['id'];
                        if ($sourceSite !== $sourceHome && !str_starts_with($sourceSite, $sourceHome . '/')) { $v = Support::replace($v, $sourceSite, $placeholder); }
                        $v = Support::replace($v, $sourceHome, $j['url']);
                        $v = Support::replace($v, $placeholder, $j['url']);
                    }
                    // WordPress capability keys contain the installation's table prefix.
                    if (($target === $wpdb->usermeta && $columns[$i] === 'meta_key') || ($target === $wpdb->options && $columns[$i] === 'option_name')) { if (str_starts_with($v, $m['prefix'])) { $v = $wpdb->prefix . substr($v, strlen($m['prefix'])); } }
                    $values[] = "'" . $wpdb->_real_escape($v) . "'";
                }
                Support::query('INSERT INTO ' . Support::quote($stage) . ' (' . implode(',', array_map([Support::class, 'quote'], $columns)) . ') VALUES (' . implode(',', $values) . ')');
            }
            $j['row_offset'] = ftell($f); $finished = feof($f); fclose($f);
            if ($finished) { $j['table_index']++; $j['row_offset'] = 0; }
            $j['message'] = 'Preparing database table ' . ($j['table_index'] + ($finished ? 0 : 1)) . ' / ' . count($m['tables']);
        } elseif ($j['phase'] === 'commit_files') {
            self::maintenance($j); $n = 0;
            while ($j['index'] < count($m['files']) && $n++ < 30) {
                $entry = $m['files'][$j['index']]; $name = $entry['name']; $part = explode('/', $name)[0];
                if (in_array($part, $j['parts'], true) && ($dest = self::destination($name))) {
                    $backup = $dir . '/rollback/' . $j['index']; $existed = file_exists($dest);
                    if ($existed && !is_file($dest)) { throw new \RuntimeException('Restore file conflicts with a directory.'); }
                    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) { throw new \RuntimeException('Cannot create destination directory.'); }
                    if (!isset($j['journal'][$j['index']]) && $existed && !copy($dest, $backup)) { throw new \RuntimeException('Cannot preserve an existing file for rollback.'); }
                    if (!isset($j['journal'][$j['index']])) { $j['journal'][$j['index']] = ['path' => $dest, 'backup' => $backup, 'existed' => $existed]; self::save($j); }
                    $temp = $dest . '.tb-' . $j['id'];
                    if (!copy($dir . '/stage/' . $name, $temp) || !chmod($temp, $existed ? (fileperms($dest) & 0777) : 0644) || !rename($temp, $dest)) { throw new \RuntimeException('Cannot apply restored file.'); }
                    if (function_exists('opcache_invalidate')) { opcache_invalidate($dest, true); }
                }
                $j['index']++;
            }
            $j['message'] = 'Applied ' . $j['index'] . ' / ' . count($m['files']) . ' entries.';
            if ($j['index'] >= count($m['files'])) { $j['phase'] = 'commit_database'; }
        } elseif ($j['phase'] === 'commit_database') {
            self::maintenance($j); $renames = []; $j['old_tables'] = [];
            // Keep the recovery UI available after migrating a site's active-plugin list.
            if (isset($j['staged_tables'][$wpdb->options])) {
                $table = Support::quote($j['staged_tables'][$wpdb->options]);
                $active = $wpdb->get_var("SELECT option_value FROM $table WHERE option_name='active_plugins'");
                $plugins = @unserialize($active ?: 'a:0:{}', ['allowed_classes' => false]); if (!is_array($plugins)) { $plugins = []; }
                $plugins = array_values(array_filter($plugins, 'is_string')); $plugins[] = plugin_basename(TERRIBLE_BACKUPS_FILE);
                Support::query($wpdb->prepare("UPDATE $table SET option_value=%s WHERE option_name='active_plugins'", serialize(array_values(array_unique($plugins)))));
            }
            foreach ($j['staged_tables'] as $target => $stage) {
                $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($target)));
                if ($exists) { $old = 'tb_old_' . substr($j['id'], 0, 12) . '_' . count($j['old_tables']); $j['old_tables'][$target] = $old; $renames[] = Support::quote($target) . ' TO ' . Support::quote($old); }
                $renames[] = Support::quote($stage) . ' TO ' . Support::quote($target);
            }
            // Persist intent before the atomic rename so a crash can be recovered deterministically.
            $j['phase'] = 'swapping'; self::save($j);
            if ($renames) { Support::query('RENAME TABLE ' . implode(', ', $renames)); }
            $j['db_swapped'] = true; $j['phase'] = 'done'; $j['message'] = 'Restore complete. Sign in using an account from the restored site.'; self::save($j);
            wp_cache_flush(); self::unmaintenance($j); // Rollback copies remain until explicitly deleted.
        } elseif ($j['phase'] === 'swapping' || $j['phase'] === 'recovery') {
            throw new \RuntimeException('Restore was interrupted during activation. Rolling back.');
        }
    }
    private static function maintenance(array $j): void {
        $path = ABSPATH . '.maintenance'; $marker = '// terrible-backups:' . $j['id'];
        if (file_exists($path) && !str_contains(file_get_contents($path), $marker)) { throw new \RuntimeException('Another process enabled maintenance mode.'); }
        $code = '<?php ' . $marker . "\n" . '$upgrading = time(); if (isset($_SERVER[\'HTTP_X_TERRIBLE_BACKUPS_JOB\']) && hash_equals(\'' . $j['token'] . '\', $_SERVER[\'HTTP_X_TERRIBLE_BACKUPS_JOB\'])) { $upgrading = 0; } if (isset($_COOKIE[\'terrible_backups_recovery\']) && hash_equals(\'' . $j['token'] . '\', $_COOKIE[\'terrible_backups_recovery\'])) { $upgrading = 0; }';
        if (file_put_contents($path, $code, LOCK_EX) === false) { throw new \RuntimeException('Cannot enable maintenance mode.'); }
    }
    private static function unmaintenance(array $j): void { $p = ABSPATH . '.maintenance'; if (is_file($p) && str_contains(file_get_contents($p), '// terrible-backups:' . $j['id'])) { unlink($p); } }
    private static function rollback(array &$j, string $dir): void {
        global $wpdb;
        // If the saved state precedes RENAME's acknowledgement, table existence reveals the outcome.
        $swapped = $j['db_swapped'] ?? false;
        if (($j['phase'] ?? '') === 'swapping' && !empty($j['staged_tables'])) { $stage = reset($j['staged_tables']); $swapped = !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($stage))); }
        if ($swapped) {
            $renames = [];
            foreach ($j['staged_tables'] as $target => $stage) { $renames[] = Support::quote($target) . ' TO ' . Support::quote($stage); if (isset($j['old_tables'][$target])) { $renames[] = Support::quote($j['old_tables'][$target]) . ' TO ' . Support::quote($target); } }
            if ($renames) { Support::query('RENAME TABLE ' . implode(', ', $renames)); }
            $j['db_swapped'] = false; self::save($j);
        }
        foreach (array_reverse($j['journal'] ?? []) as $entry) {
            $cursor = $entry['path']; while ($cursor !== dirname($cursor)) { if (is_link($cursor)) { throw new \RuntimeException('Rollback path contains a symbolic link.'); } $cursor = dirname($cursor); }
            if ($entry['existed']) { if (!copy($entry['backup'], $entry['path'])) { throw new \RuntimeException('Cannot restore original file.'); } }
            elseif (file_exists($entry['path']) && !unlink($entry['path'])) { throw new \RuntimeException('Cannot remove restored file.'); }
            if (function_exists('opcache_invalidate')) { opcache_invalidate($entry['path'], true); }
        }
        foreach ($j['staged_tables'] ?? [] as $stage) { Support::query('DROP TABLE IF EXISTS ' . Support::quote($stage)); }
        $j['journal'] = []; self::unmaintenance($j); wp_cache_flush();
    }
    public static function cancel(string $id): array {
        return self::locked(static function () use ($id) {
            $j = self::load($id);
            if ($j['phase'] === 'done') { throw new \RuntimeException('Completed jobs cannot be cancelled.'); }
            if ($j['type'] === 'restore') { self::rollback($j, self::dir($id)); }
            try { Transport::abort($j); } catch (\Throwable $e) { $j['warnings'][]='Remote cleanup was not completed. Reconnect the provider to inspect any partial upload.'; } $j['phase'] = 'cancelled'; $j['message'] = 'Cancelled; any applied restore changes were rolled back.'; self::save($j); return $j;
        });
    }
    public static function delete(string $id): void {
        self::locked(static function () use ($id) {
            global $wpdb; $j = self::load($id);
            if (!in_array($j['phase'], ['done', 'ready', 'failed', 'cancelled'], true)) { throw new \RuntimeException('Cancel the job before deleting it.'); }
            Transport::abort($j);
            foreach ($j['old_tables'] ?? [] as $old) { Support::query('DROP TABLE IF EXISTS ' . Support::quote($old)); }
            Support::remove(self::dir($id));
        });
    }
    public static function listing(): array {
        $out = []; foreach (glob(Support::storage() . '/*/job.json') as $path) { $j = Support::read($path); $out[] = self::publicJob($j); }
        usort($out, static fn($a, $b) => $b['created'] <=> $a['created']); return $out;
    }
    public static function publicJob(array $j): array {
        $out = array_intersect_key($j, array_flip(['id', 'type', 'created', 'phase', 'message', 'error', 'warnings', 'size', 'offset', 'sha256', 'token', 'parts', 'direction', 'remote', 'key']));
        $out['downloadable'] = in_array($j['phase'], ['done','ready'], true) && is_file(self::dir($j['id']).'/archive.zip');
        return $out;
    }
}
