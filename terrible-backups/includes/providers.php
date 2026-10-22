<?php
namespace TerribleBackups;
defined('ABSPATH') || exit;

/** Public storage API v1. State is persisted between bounded transfer steps. */
interface StorageProvider {
    public function id(): string;
    public function label(): string;
    /** field => [label, type, default]. Password fields are never rendered back. */
    public function fields(): array;
    public function validate(array $config): void;
    public function size(array $config, string $key): int;
    public function transfer(array &$job, string $directory, array $config): bool;
    public function abort(array $job, array $config): void;
}
/** Optional readiness contract; checking configuration must not contact the network. */
interface ConfiguredProvider { public function configured(array $config): bool; }
interface CatalogProvider {
    /** One bounded page: ['files' => [['key'=>string, 'size'=>int]], 'cursor'=>string]. */
    public function catalog(array $config, string $cursor = ''): array;
}
final class Providers {
    public const API_VERSION = 1;
    public static function all(): array {
        $providers = ['s3' => new BuiltinStorage('s3'), 'ftp' => new BuiltinStorage('ftp')];
        $providers = apply_filters('terrible_backups_storage_providers', $providers);
        foreach ($providers as $id => $provider) {
            if (!$provider instanceof StorageProvider || $provider->id() !== $id || !preg_match('/\A[a-z][a-z0-9_-]{0,40}\z/', $id)) {
                throw new \RuntimeException('Invalid Terrible Backups storage provider registration.');
            }
        }
        return $providers;
    }
    public static function get(string $id): StorageProvider {
        return self::all()[$id] ?? throw new \RuntimeException('Storage provider unavailable. Activate a compatible Integrations plugin to use this connection.');
    }
    public static function labels(): array { return array_map(static fn($p) => $p->label(), self::all()); }
}
final class BuiltinStorage implements StorageProvider, ConfiguredProvider {
    public function __construct(private string $kind) {}
    public function id(): string { return $this->kind; }
    public function label(): string { return $this->kind === 's3' ? 'S3 / S3-compatible' : 'FTP / FTPS'; }
    public function configured(array $c): bool { return $this->kind === 's3' ? !empty($c['bucket']) && !empty($c['access']) && !empty($c['secret']) : !empty($c['host']) && !empty($c['user']); }
    public function fields(): array { return []; } // Existing core settings UI remains compatible.
    public function validate(array $config): void {}
    public function size(array $config, string $key): int { return Transport::builtinSize($this->kind, $config, $key); }
    public function transfer(array &$job, string $directory, array $config): bool { return Transport::builtinTransfer($job, $directory, $config); }
    public function abort(array $job, array $config): void { Transport::builtinAbort($job, $config); }
}
