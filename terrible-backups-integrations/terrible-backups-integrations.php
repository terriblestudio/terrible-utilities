<?php
/**
 * Plugin Name: Terrible Backups — Integrations
 * Description: Dropbox and Google Drive storage for Terrible Backups.
 * Version: 0.3.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Requires Plugins: terrible-backups
 * Author: Terrible Studios
 * License: GPL-2.0-or-later
 * Update URI: false
 */
namespace TerribleBackupsIntegrations;
defined('ABSPATH') || exit;
add_action('plugins_loaded', static function () {
    if (!interface_exists('TerribleBackups\ConfiguredProvider') || !class_exists('TerribleBackups\Providers') || \TerribleBackups\Providers::API_VERSION !== 1) {
        add_action('admin_notices', static function () { if (current_user_can('activate_plugins')) echo '<div class="notice notice-error"><p>Terrible Backups — Integrations requires Terrible Backups with storage API v1 and configuration readiness (0.3.0 or later).</p></div>'; });
        return;
    }
    require_once __DIR__ . '/includes/cloud.php';
    add_filter('terrible_backups_storage_providers', static function ($providers) { $providers['dropbox'] = new CloudStorage('dropbox'); $providers['gdrive'] = new CloudStorage('gdrive'); return $providers; });
    add_action('terrible_backups_connection_controls', static function ($id) {
        if (!in_array($id, ['dropbox', 'gdrive'], true)) return;
        echo '<p class="description">Save your application credentials first, then connect your account. Authorized redirect URI: <code>' . esc_html(OAuth::redirect()) . '</code></p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=terrible_backups_connect&provider=' . $id), 'tb-connect-' . $id)) . '">Connect account</a>';
    });
    add_action('admin_post_terrible_backups_connect', [OAuth::class, 'connect']);
    add_action('admin_post_terrible_backups_oauth', [OAuth::class, 'callback']);
});
