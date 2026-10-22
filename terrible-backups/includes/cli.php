<?php
namespace TerribleBackups;
defined('ABSPATH') || exit;
/** Manual backups, imports and job recovery. */
final class CLI {
    /** Create a local archive. Options: --parts=database,uploads,plugins,themes,mu-plugins,content,core,root */
    public function backup(array $args, array $assoc): void { $this->run(Engine::backup(isset($assoc['parts']) ? explode(',', $assoc['parts']) : array_keys(Support::categories()))); }
    /** List local jobs. */
    public function jobs(): void { \WP_CLI::line(wp_json_encode(Engine::listing(), JSON_PRETTY_PRINT)); }
    /** Import a ZIP from a server-local path without HTTP upload limits. */
    public function import(array $args): void {
        if (empty($args[0]) || !is_file($args[0])) { \WP_CLI::error('Provide a local archive path.'); }
        $j = Engine::upload(filesize($args[0])); $f = fopen($args[0], 'rb'); $offset = 0;
        while (!feof($f)) { $chunk = fread($f, 1048576); if ($chunk === '') break; $j = Engine::chunk($j['id'], $offset, $chunk); $offset += strlen($chunk); } fclose($f);
        Engine::inspect($j['id']); \WP_CLI::success('Imported ' . $j['id']);
    }
    /** Restore an archive ID. Requires --yes. Options: --parts=database,uploads --url=https://destination.example */
    public function restore(array $args, array $assoc): void {
        \WP_CLI::confirm('Overwrite the selected site contents from this archive?', $assoc);
        $parts = isset($assoc['parts']) ? explode(',', $assoc['parts']) : array_keys(Support::categories());
        $this->run(Engine::restore($args[0] ?? '', $parts, $assoc['url'] ?? ''));
    }
    /** Resume a job ID. */
    public function resume(array $args): void { $this->run(Engine::load($args[0] ?? '')); }
    /** Cancel an unfinished job and roll back any restore changes. */
    public function cancel(array $args): void { Engine::cancel($args[0] ?? ''); \WP_CLI::success('Job cancelled.'); }
    /** Delete a finished job and retained rollback copies. Requires --yes. */
    public function delete(array $args, array $assoc): void { \WP_CLI::confirm('Delete this archive and its rollback data?', $assoc); Engine::delete($args[0] ?? ''); \WP_CLI::success('Deleted.'); }
    /** Transfer to or from storage. Usage: wp terrible-backups remote s3|ftp key [--source=archive-id] */
    public function remote(array $args, array $assoc): void { $this->run(Engine::remote($args[0] ?? '', $args[1] ?? '', $assoc['source'] ?? null)); }
    private function run(array $j): void {
        while (!in_array($j['phase'], ['done', 'ready', 'failed', 'cancelled', 'recovery'], true)) { $j = Engine::step($j['id']); \WP_CLI::log($j['message']); }
        if (in_array($j['phase'], ['failed', 'recovery'], true)) { \WP_CLI::error($j['error']); }
        \WP_CLI::success($j['id'] . ' — ' . $j['message']);
        if (is_file(Engine::dir($j['id']) . '/archive.zip')) { \WP_CLI::line(Engine::dir($j['id']) . '/archive.zip'); }
    }
}
\WP_CLI::add_command('terrible-backups', CLI::class);
