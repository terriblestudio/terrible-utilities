<?php
/**
 * Plugin Name: Terrible Mail
 * Description: SMTP delivery, test messages, and a bounded history with explicit outcomes.
 * Version: 0.2.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Terrible Studios
 * License: GPL-2.0-or-later
 * Update URI: false
 */
namespace TerribleMail;
defined('ABSPATH') || exit;
define('TERRIBLE_MAIL_FILE', __FILE__);
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/admin.php';
register_activation_hook(__FILE__, [History::class, 'install']);
register_deactivation_hook(__FILE__, static function () { wp_clear_scheduled_hook('terrible_mail_cleanup'); });
add_action('plugins_loaded', static function () {
    History::install();
    if (!wp_next_scheduled('terrible_mail_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'terrible_mail_cleanup');
    Admin::boot();
    add_action('terrible_mail_cleanup', [History::class, 'cleanup']);
    add_filter('wp_mail_from', static function ($from) { $c=Settings::get(); return $c['enabled'] ? $c['from'] : $from; });
    add_filter('wp_mail_from_name', static function ($name) { $c=Settings::get(); return $c['enabled'] ? $c['name'] : $name; });
    add_action('phpmailer_init', [Delivery::class, 'configure'], PHP_INT_MAX);
    add_filter('pre_wp_mail', [Delivery::class, 'prepare'], PHP_INT_MAX, 2);
    add_action('wp_mail_failed', [Delivery::class, 'invalid']);
});
