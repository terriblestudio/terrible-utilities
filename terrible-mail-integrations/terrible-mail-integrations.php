<?php
/**
 * Plugin Name: Terrible Mail — Integrations
 * Description: Direct Resend, Amazon SES, and Google Workspace / Gmail delivery for Terrible Mail.
 * Version: 0.2.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Requires Plugins: terrible-mail
 * Author: Terrible Studios
 * License: GPL-2.0-or-later
 * Update URI: false
 */
namespace TerribleMailIntegrations;
defined('ABSPATH') || exit;
add_action('plugins_loaded',static function () {
    if (!class_exists('TerribleMail\Transports') || \TerribleMail\Transports::API_VERSION!==1) {
        add_action('admin_notices',static function () { if (current_user_can('activate_plugins')) echo '<div class="notice notice-error"><p>Terrible Mail — Integrations requires Terrible Mail transport API v1 (0.1.x).</p></div>'; }); return;
    }
    require_once __DIR__.'/includes/providers.php';
    add_filter('terrible_mail_transports',static function ($all) { foreach (['resend','ses','gmail'] as $id) $all[$id]=new ApiTransport($id); return $all; });
    add_action('admin_post_terrible_mail_connect',[GoogleOAuth::class,'connect']);
    add_action('admin_post_terrible_mail_oauth',[GoogleOAuth::class,'callback']);
    add_action('terrible_mail_connection_controls',static function ($id) { if ($id==='gmail') echo '<p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=terrible_mail_connect'),'terrible-mail-connect')).'">Connect Google account</a></p><p>Save application credentials first. Authorized redirect URI: <code>'.esc_html(GoogleOAuth::redirect()).'</code></p>'; });
});
