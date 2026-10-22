<?php
namespace TerribleMetadata;
defined('ABSPATH') || exit;
UI::header('terrible-metadata','Search & sharing','Choose how your pages look when people find or share them.');
if (!self::active()) echo '<div class="tt-error">Another supported SEO plugin is active. Terrible Metadata output is paused to avoid duplicate tags.</div>';
echo '<div class="tt-grid">';
\TerribleTools\Admin::card('Preview a page', '01 / PAGE SETTINGS');
echo '<p>Open a post or page and use its <strong>Search & sharing</strong> panel to choose a title, description, and image. Blank fields use the page’s existing content.</p>';
ob_start(); \TerribleTools\Admin::picker('content'); $picker=ob_get_clean();
echo str_replace('class="tt-picker"', 'class="tt-picker" data-edit-url="'.esc_url(admin_url('post.php?post=CONTENT_ID&action=edit')).'"', $picker);
echo '</section>';
\TerribleTools\Admin::card('A fallback sharing image', '02 / SITE DEFAULT');
echo '<p>Used when a page has neither a custom sharing image nor a featured image.</p>';
UI::form('terrible-metadata','save'); self::imageControl('image',(int)get_option('terrible_metadata_image',0)); UI::submit('Save default');
echo '</section></div><section class="tt-card"><h2>What changes?</h2><p>A link shared in a chat or social feed can include a title, description, and image. Search engines can use the description in search results. These previews are illustrative; services decide their final presentation.</p><p class="tt-muted">WordPress continues to manage canonical links and XML sitemaps. There are no keyword scores or ranking promises.</p></section></div>';
