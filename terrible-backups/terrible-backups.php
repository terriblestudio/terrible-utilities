<?php
/**
 * Plugin Name: Terrible Backups
 * Description: Manual WordPress backups and migrations with chunked uploads, FTP/FTPS and S3-compatible storage.
 * Version: 0.3.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: Terrible Studios
 * License: GPL-2.0-or-later
 * Update URI: false
 */
namespace TerribleBackups;
defined('ABSPATH') || exit;
define('TERRIBLE_BACKUPS_FILE', __FILE__);
foreach (['support', 'providers', 'transport', 'engine', 'admin'] as $part) { require_once __DIR__ . '/includes/' . $part . '.php'; }
add_action('plugins_loaded', [Admin::class, 'boot']);
if (defined('WP_CLI') && WP_CLI) { require_once __DIR__ . '/includes/cli.php'; }
