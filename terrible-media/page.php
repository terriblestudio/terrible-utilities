<?php
namespace TerribleMedia;
defined('ABSPATH') || exit;
$id = absint($_GET['id'] ?? 0);
UI::header('terrible-media', 'Replace media', 'Update an image or PDF without changing its link. Keep the previous version for undo.');
echo '<div class="tt-grid">';
\TerribleTools\Admin::card('Choose the file to update', '01 / EXISTING FILE');
echo '<p>Replacing a menu, price list, or image keeps existing links working throughout your site.</p><button type="button" class="button button-primary" id="terrible-media-picker">Choose from Media Library</button>';
if ($id && get_post_type($id) === 'attachment' && current_user_can('edit_post', $id)) {
    echo '<h3>'.esc_html(get_the_title($id)).'</h3>'.wp_get_attachment_image($id, 'medium', false, ['class'=>'tt-media-preview']);
    echo '<p><a href="'.esc_url(wp_get_attachment_url($id)).'">Open current file</a></p><p class="tt-muted">'.esc_html(basename(get_attached_file($id) ?: '')).' · '.esc_html(get_post_mime_type($id)).'</p>';
}
echo '</section>';
\TerribleTools\Admin::card('Upload its replacement', '02 / REPLACE');
if ($id && get_post_type($id) === 'attachment' && current_user_can('edit_post', $id)) {
    UI::form('terrible-media', 'replace');
    echo '<input type="hidden" name="id" value="'.$id.'"><p>Choose the same file type. The URL stays the same; image sizes are regenerated.</p><label>Replacement file<input type="file" name="replacement" id="tt-replacement" required accept="'.esc_attr('.'.pathinfo(get_attached_file($id), PATHINFO_EXTENSION)).'"></label><div id="tt-replacement-preview" aria-live="polite"></div>';
    UI::submit('Replace file');
    $undo = false;
    try { $undo = is_file(self::storage($id).'/previous/version.json'); } catch (\Throwable $e) { echo '<p class="tt-error">'.esc_html($e->getMessage()).'</p>'; }
    if ($undo) { echo '<hr><p>A previous version is available.</p>'; UI::form('terrible-media','undo'); echo '<input type="hidden" name="id" value="'.$id.'">'; UI::submit('Restore previous version'); }
} else echo '<p class="tt-empty">Choose an existing file first. Its replacement and undo controls will appear here.</p>';
echo '</section></div>';
if ($id) {
    \TerribleTools\Admin::card('Where this file appears', 'KNOWN REFERENCES');
    $refs = array_filter(self::usage($id), static fn($post)=>current_user_can('edit_post',$post));
    if (!$refs) echo '<p class="tt-empty">No references found in stored content or featured images. This does not prove the file is unused.</p>';
    else { echo '<ul>'; foreach ($refs as $post) echo '<li><a href="'.esc_url(get_edit_post_link($post)).'">'.esc_html(get_the_title($post)).'</a></li>'; echo '</ul>'; }
    echo '<p class="tt-muted">Checks up to 200 content and featured-image references. Theme code, external sites, and other plugin storage may contain more.</p></section>';
}
echo '<details class="tt-card"><summary>Storage and cache details</summary><p>Undo keeps one previous version. For durable storage, set <code>TERRIBLE_MEDIA_DIR</code> outside the web root; hosts may clean the default temporary directory. External caches may need purging after a replacement. Local images and PDFs only.</p></details></div>';
