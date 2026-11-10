<?php
/**
 * Plugin Name: Terrible Social Images
 * Description: Locally generated title cards for shared links.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: Terrible Studios
 * License: GPL-2.0-or-later
 * Update URI: false
 */
namespace TerribleSocialImages;
defined('ABSPATH') || exit;
require_once __DIR__.'/shared/admin.php';
require_once __DIR__.'/core.php';
add_action('plugins_loaded', [Plugin::class, 'boot']);
