<?php
/**
 * Plugin Name: Terrible Revisions
 * Description: Prepare and schedule changes to published content.
 * Version: 0.2.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: Terrible Studios
 * License: GPL-2.0-or-later
 * Update URI: false
 */
namespace TerribleRevisions;
defined('ABSPATH') || exit;
require_once __DIR__.'/ui.php';
require_once __DIR__.'/core.php';
register_activation_hook(__FILE__,[Plugin::class,'install']);
add_action('plugins_loaded',[Plugin::class,'boot']);

register_deactivation_hook(__FILE__,static function(){ foreach (get_posts(['post_type'=>'any','post_status'=>'draft','numberposts'=>-1,'meta_key'=>'_terrible_revision_target']) as $post) { wp_clear_scheduled_hook('terrible_revisions_publish',[$post->ID]); if (get_post_meta($post->ID,'_terrible_revision_status',true)==='scheduled') { update_post_meta($post->ID,'_terrible_revision_status','draft'); update_post_meta($post->ID,'_terrible_revision_error','Schedule cancelled when the plugin was deactivated. Reschedule explicitly.'); } } });
