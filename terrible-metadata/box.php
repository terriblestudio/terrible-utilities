<?php
namespace TerribleMetadata;
defined('ABSPATH') || exit;
wp_nonce_field('terrible-metadata','terrible_metadata_nonce');
$v=self::values($post->ID);
echo '<div class="terrible-meta-panel" data-fallback-title="'.esc_attr(get_the_title($post)).'" data-fallback-description="'.esc_attr(mb_substr(trim(preg_replace('/\s+/u',' ',wp_strip_all_tags($post->post_excerpt ?: strip_shortcodes($post->post_content)))),0,180)).'"><p>Choose the title, description, and image shown with this page’s link. Leave fields blank to use its content.</p>';
foreach (['title'=>'Search / social title','description'=>'Description'] as $key=>$label) echo '<p><label>'.esc_html($label).'<br><input style="width:100%" name="terrible_meta_'.$key.'" value="'.esc_attr(get_post_meta($post->ID,'_terrible_meta_'.$key,true)).'"></label></p>';
self::imageControl('terrible_meta_image',(int)get_post_meta($post->ID,'_terrible_meta_image',true));
echo '<h3>Sharing preview</h3><div class="tt-preview">';
if ($v['image']) echo wp_get_attachment_image($v['image'],'medium_large',false,['class'=>'tt-social-preview-image']);
else echo '<img class="tt-social-preview-image" alt="" hidden>';
echo '<p><small>'.esc_html(wp_parse_url(home_url(),PHP_URL_HOST)).'</small></p><strong class="tt-preview-title">'.esc_html($v['title']).'</strong><p class="tt-preview-description">'.esc_html($v['description']).'</p></div><p class="description">Preview updates as you edit these fields. Save the post to publish these settings. Search and social services may display them differently.</p></div>';
