<?php
/**
 * Plugin Name: Terrible Media
 * Description: Replace uploaded files, inspect references, and recover the previous version.
 * Version: 0.2.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: Terrible Studios
 * License: GPL-2.0-or-later
 * Update URI: false
 */
namespace TerribleMedia;
defined('ABSPATH') || exit;
require_once __DIR__.'/ui.php';
require_once __DIR__.'/core.php';
register_activation_hook(__FILE__,[Plugin::class,'install']);
add_action('plugins_loaded',[Plugin::class,'boot']);
