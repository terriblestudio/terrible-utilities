<?php
namespace TerribleBackups;
defined('ABSPATH') || exit;
require_once dirname(__DIR__).'/shared/admin.php';
require_once dirname(__DIR__).'/shared/stream.php';
final class Admin {
    public static function boot(): void {
        \TerribleTools\Admin::register('terrible-backups','Terrible Backups',[self::class,'page']);
        add_action('wp_ajax_terrible_backups', [self::class, 'api']);
        add_action('admin_enqueue_scripts', static function ($hook) {
            if (($_GET['page'] ?? '') !== 'terrible-backups') { return; }
            wp_enqueue_style('terrible-backups', plugins_url('assets/admin.css', TERRIBLE_BACKUPS_FILE), [], '0.3.0');
            wp_enqueue_script('terrible-backups', plugins_url('assets/admin.js', TERRIBLE_BACKUPS_FILE), [], '0.3.0', true);
            $max = wp_convert_hr_to_bytes(ini_get('post_max_size'));
            wp_localize_script('terrible-backups', 'TerribleBackups', ['endpoint' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('terrible-backups'), 'home' => home_url(), 'providers' => Transport::destinations(), 'catalogs' => array_values(array_filter(array_keys(Transport::destinations()), static fn($id) => Transport::connection($id)['provider'] instanceof CatalogProvider)), 'chunkSize' => min(262144, $max > 0 ? max(1024, (int)($max / 4)) : 262144)]);
        });
    }
    public static function api(): void {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Administrator access is required.'], 403); }
        $nonce = $_SERVER['HTTP_X_TERRIBLE_BACKUPS_NONCE'] ?? ($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'terrible-backups')) { wp_send_json_error(['message' => 'Your session expired. Reload this page.'], 403); }
        $op = sanitize_key($_GET['op'] ?? '');
        if ($op !== 'download' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json_error(['message' => 'POST is required.'], 405); }
        try {
            if ($op === 'chunk') {
                $body = file_get_contents('php://input', false, null, 0, 1048577);
                $out = Engine::publicJob(Engine::chunk((string)($_GET['id'] ?? ''), (int)($_GET['offset'] ?? -1), $body));
            } elseif ($op === 'download') { self::download((string)($_GET['id'] ?? '')); return; }
            else {
                $raw = file_get_contents('php://input', false, null, 0, 65537); if (strlen($raw) > 65536) { throw new \RuntimeException('Request is too large.'); }
                $data = json_decode($raw ?: '{}', true, 64, JSON_THROW_ON_ERROR); if (!is_array($data)) { throw new \RuntimeException('Invalid request.'); }
                $out = match ($op) {
                    'list' => Engine::listing(),
                    'backup' => Engine::publicJob(Engine::backup((array)($data['parts'] ?? []))),
                    'upload' => Engine::publicJob(Engine::upload((int)($data['size'] ?? 0))),
                    'remote' => Engine::publicJob(Engine::remote((string)($data['kind'] ?? ''), (string)($data['name'] ?? ''), $data['source'] ?? null)),
                    'inspect' => self::preview((string)($data['id'] ?? '')),
                    'restore' => Engine::publicJob(Engine::restore((string)($data['id'] ?? ''), (array)($data['parts'] ?? []), (string)($data['url'] ?? ''))),
                    'step' => Engine::publicJob(Engine::step((string)($data['id'] ?? ''))),
                    'cancel' => Engine::publicJob(Engine::cancel((string)($data['id'] ?? ''))),
                    'delete' => self::delete((string)($data['id'] ?? '')),
                    'settings' => self::settings($data),
                    'catalog' => self::catalog($data),
                    'remember' => self::remember($data),
                    'destination' => self::destination($data),
                    default => throw new \RuntimeException('Unknown operation.'),
                };
            }
            if (is_array($out) && isset($out['token'])) { setcookie('terrible_backups_recovery', $out['token'], ['expires' => time() + DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict']); }
            wp_send_json_success($out);
        } catch (\Throwable $e) { wp_send_json_error(['message' => $e->getMessage()], 400); }
    }
    private static function preview(string $id): array { $m = Engine::inspect($id); unset($m['files'], $m['tables']); return $m; }
    private static function catalog(array $data): array {
        $kind = (string)($data['kind'] ?? ''); $connection=Transport::connection($kind); $provider=$connection['provider'];
        if (!$provider instanceof CatalogProvider) { throw new \RuntimeException('Enter the archive path for this provider.'); }
        return $provider->catalog($connection['config'], (string)($data['cursor'] ?? ''));
    }
    private static function destination(array $data): bool { Transport::manage((string)($data['id']??''),(string)($data['operation']??''),(string)($data['label']??'')); return true; }
    private static function remember(array $data): bool { Transport::remember((string)($data['provider']??''),(string)($data['label']??'')); return true; }
    private static function settings(array $data): bool { if(!empty($data['_destination'])) { $id=(string)$data['_destination'];$connection=Transport::connection($id);Transport::edit($id,(array)($data[$connection['provider']->id()]??[]));return true; } Transport::save(array_intersect_key($data,Providers::all())); do_action('terrible_tools_event','backups.changed'); return true; }
    private static function delete(string $id): bool { Engine::delete($id); return true; }
    private static function download(string $id): void {
        $j = Engine::load($id); $path = Engine::dir($id) . '/archive.zip';
        if (!in_array($j['phase'], ['done', 'ready'], true) || !is_file($path)) { throw new \RuntimeException('Archive is not ready to download.'); }
        while (ob_get_level()) { ob_end_clean(); } nocache_headers(); header('Content-Type: application/zip'); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: attachment; filename="terrible-backups-' . gmdate('Ymd-His', $j['created']) . '-' . $id . '.zip"'); header('Content-Length: ' . filesize($path));
        $f = fopen($path, 'rb'); while (!feof($f)) { echo fread($f, 1048576); flush(); } fclose($f); exit;
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        try { Support::environment(); $storage = Support::storage(); $settings = Transport::settings(); $editing=sanitize_text_field($_GET['destination']??'');$edit=$settings['_connections'][$editing]??null;if($editing && !$edit)throw new \RuntimeException('Destination no longer exists.');if($edit)$settings[$edit['provider']]=$edit['config']; }
        catch (\Throwable $e) { echo '<div class="wrap"><h1>Terrible Backups</h1><div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div></div>'; return; }
        ?>
        <div class="wrap tb-wrap">
            <header class="tb-header"><div><p class="tb-eyebrow">TERRIBLE STUDIOS / SITE TOOLS</p><h1>Terrible Backups</h1><p>Your site. Packed up. Ready to move.</p></div><span class="tb-badge">Manual backups</span></header>
            <div id="tb-notice" role="status" aria-live="polite" class="tb-success" <?php if(empty($_GET['storage_saved']))echo 'hidden'; ?>><?php if(!empty($_GET['storage_saved']))echo 'Storage settings saved.'; ?></div>
            <section class="tb-card tb-progress" id="tb-progress" hidden><h2 id="tb-progress-title">Working</h2><p id="tb-progress-message" aria-live="polite"></p><progress id="tb-meter" aria-label="Job progress"></progress><p>Keep this tab open while the job runs. If interrupted, use Resume below.</p><button type="button" class="button" id="tb-cancel">Cancel and roll back</button></section>
            <div class="tb-grid">
                <section class="tb-card"><span class="tb-step">01 / EXPORT</span><h2>Create a backup</h2><p>Choose what to take with you. Archives include a checksum for every file.</p>
                    <form id="tb-backup"><fieldset><legend class="screen-reader-text">Backup contents</legend><?php self::parts('backup'); ?></fieldset><button class="button button-primary button-hero">Create backup</button></form>
                    <p class="description">Pause site edits and orders for a consistent snapshot. Symbolic links are skipped and reported. Archives contain credentials and private data; store them securely.</p>
                </section>
                <section class="tb-card"><span class="tb-step">02 / IMPORT</span><h2>Bring a backup here</h2><p>Upload a Terrible Backups ZIP. Small chunks avoid the usual single-file upload ceiling.</p>
                    <form id="tb-upload"><label for="tb-file">Backup archive</label><input type="file" id="tb-file" accept=".zip" required><label for="tb-chunk">Chunk size</label><select id="tb-chunk"><option value="262144">256 KiB — default</option><option value="65536">64 KiB — restrictive hosts</option><option value="16384">16 KiB — very small request limits</option></select><button class="button">Upload archive</button></form>
                    <hr><?php if (Transport::destinations()): ?><form id="tb-fetch"><h3>Fetch from remote storage</h3><label for="tb-fetch-kind">Storage</label><select id="tb-fetch-kind"><?php foreach (Transport::destinations() as $id => $label) { echo '<option value="' . esc_attr($id) . '">' . esc_html($label) . '</option>'; } ?></select><label for="tb-fetch-name">Archive key / path, relative to configured prefix</label><input id="tb-fetch-name" placeholder="my-backup.zip" required><button class="button">Fetch archive</button> <button type="button" class="button" id="tb-browse">Browse provider archives</button><div id="tb-remote-files"></div></form><?php else: ?><p>No remote destinations configured. <a href="#tb-storage" onclick="document.getElementById('tb-storage').open=true">Set up storage</a> to fetch or send archives.</p><?php endif; ?>
                    <p class="description">Disk space, execution limits and individual request limits still apply. Restore accepts this plugin’s archive format.</p>
                </section>
            </div>
            <section class="tb-card" id="tb-restore" hidden><span class="tb-step">REVIEW / RESTORE</span><h2>Restore this archive</h2><p id="tb-archive-summary"></p><form id="tb-restore-form"><fieldset><?php self::parts('restore'); ?></fieldset><label for="tb-url">Replace the source site URL with</label><input type="url" id="tb-url" value="<?php echo esc_attr(home_url()); ?>"><p class="description">Leave blank to keep the source URL. Handles serialized settings and escaped JSON URLs; post GUIDs stay unchanged. WordPress installed in a subdirectory keeps that subdirectory.</p><p><strong>Restore overwrites selected files and database tables, including users and passwords.</strong> The site enters maintenance mode while changes are applied. Extra destination files are kept. Root configuration and this running plugin are not overwritten. Only restore archives you trust.</p><label class="tb-check"><input type="checkbox" id="tb-confirm" required> I have a current backup of this destination and want to restore the selected contents.</label><button class="button button-primary">Restore selected contents</button><button type="button" id="tb-close-restore" class="button">Close</button></form></section>
            <section class="tb-card"><div class="tb-section-heading"><div><span class="tb-step">LOCAL LIBRARY</span><h2>Archives &amp; jobs</h2></div><button class="button" id="tb-refresh">Refresh</button></div><div id="tb-library"><p>Loading…</p></div><p class="description">Delete a completed restore job to remove its archive, rollback files and old database tables. No automatic retention or scheduling.</p></section>
            <details class="tb-card" id="tb-storage"><summary>Destinations &amp; remote storage</summary><p>Credentials are encrypted with this installation’s WordPress salts and kept in private storage, outside the database and backup archives. Blank password, secret and token fields keep the saved value.</p>
                <?php if($edit): ?><p><strong>Editing <?php echo esc_html($edit['label']); ?></strong> · <a href="<?php echo esc_url(\TerribleTools\Admin::url('terrible-backups')); ?>#tb-storage">Back to all destinations</a></p><?php endif; ?><label for="tb-provider-choice">Configure a provider</label><select id="tb-provider-choice" <?php disabled((bool)$edit); ?>><?php foreach (Providers::labels() as $id=>$label) echo '<option value="'.esc_attr($id).'" '.selected($edit['provider']??'s3',$id,false).'>'.esc_html($label).'</option>'; ?></select><form id="tb-settings" data-destination="<?php echo esc_attr($editing); ?>"><div class="tb-grid"><fieldset><legend><h3>FTP / explicit FTPS</h3></legend><?php self::field('ftp', 'host', 'Hostname', $settings); self::field('ftp', 'port', 'Port', $settings, 'number', '21'); self::field('ftp', 'user', 'Username', $settings); self::field('ftp', 'password', 'Password', $settings, 'password'); self::field('ftp', 'prefix', 'Remote directory (relative to FTP login; must exist)', $settings); ?><label class="tb-check"><input name="ftp.tls" type="checkbox" <?php checked(!empty($settings['ftp']['tls'])); ?>> Use explicit TLS (FTPS)</label><p class="description">Plain FTP sends credentials without encryption. SFTP is not included in this version.</p></fieldset><fieldset><legend><h3>S3 / S3-compatible</h3></legend><?php self::field('s3', 'endpoint', 'Endpoint (blank for AWS)', $settings, 'url'); self::field('s3', 'region', 'Region', $settings, 'text', 'us-east-1'); self::field('s3', 'bucket', 'Bucket', $settings); self::field('s3', 'prefix', 'Object prefix', $settings); self::field('s3', 'access', 'Access key ID', $settings); self::field('s3', 'secret', 'Secret access key', $settings, 'password'); self::field('s3', 'token', 'Session token (optional)', $settings, 'password'); ?><label class="tb-check"><input name="s3.clear_token" type="checkbox"> Clear the saved session token</label><label class="tb-check"><input name="s3.virtual" type="checkbox" <?php checked(!empty($settings['s3']['virtual'])); ?>> Virtual-hosted bucket addressing</label><p class="description">Path-style addressing is the default. HTTPS is required. Multipart transfers use 8 MiB parts.</p></fieldset><?php foreach (Providers::all() as $id => $provider) { if (!$provider->fields()) { continue; } echo '<fieldset><legend><h3>' . esc_html($provider->label()) . '</h3></legend>'; foreach ($provider->fields() as $key => [$label, $type, $default]) { self::field($id, $key, $label, $settings, $type, $default); } if(!$edit)do_action('terrible_backups_connection_controls', $id);else echo '<p class="description">For OAuth reconnection, connect this provider on the main storage screen, then use its current settings for this destination.</p>'; echo '</fieldset>'; } ?></div><button class="button button-primary"><?php echo $edit ? 'Save destination' : 'Save storage settings'; ?></button></form>
                <?php if(!$edit): require __DIR__.'/destinations.php'; ?><hr><form id="tb-remember"><h3>Save a named destination</h3><p>Copies a provider’s saved configuration into a separate destination. Later edits to its defaults do not change this destination.</p><label for="tb-remember-provider">Provider</label><select id="tb-remember-provider"><?php foreach (Providers::labels() as $id=>$label) echo '<option value="'.esc_attr($id).'">'.esc_html($label).'</option>'; ?></select><label for="tb-remember-label">Name</label><input id="tb-remember-label" required><button class="button">Save named destination</button></form><?php endif; ?>
            </details>
            <footer class="tb-footer">Private working directory: <code><?php echo esc_html($storage); ?></code><br>For durable storage, set <code>TERRIBLE_BACKUPS_DIR</code> to a writable directory outside the web root. The default system temporary directory may be cleaned by your host.</footer>
        </div>
        <?php
    }
    private static function parts(string $prefix): void { foreach (Support::categories() as $key => $label) { if ($prefix === 'restore' && $key === 'root') { continue; } echo '<label class="tb-check"><input type="checkbox" name="parts[]" value="' . esc_attr($key) . '" checked> ' . esc_html($label) . '</label>'; } }
    private static function field(string $group, string $key, string $label, array $settings, string $type = 'text', string $default = ''): void { $id = 'tb-' . $group . '-' . $key; echo '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><input id="' . esc_attr($id) . '" name="' . esc_attr($group . '.' . $key) . '" type="' . esc_attr($type) . '" value="' . esc_attr($type === 'password' ? '' : ($settings[$group][$key] ?? $default)) . '" autocomplete="' . ($type === 'password' ? 'new-password' : 'off') . '">'; }
}
