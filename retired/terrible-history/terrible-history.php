<?php
/**
 * Plugin Name: Terrible History
 * Description: A readable activity timeline without logging secrets.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: Terrible Studios
 * License: GPL-2.0-or-later
 * Update URI: false
 */
namespace TerribleHistory;
defined('ABSPATH') || exit;
require_once __DIR__.'/ui.php';
require_once __DIR__.'/core.php';
register_activation_hook(__FILE__,[Plugin::class,'install']);
add_action('plugins_loaded',[Plugin::class,'boot']);
