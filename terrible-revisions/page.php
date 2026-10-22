<?php
namespace TerribleRevisions;
defined('ABSPATH') || exit;
UI::header('terrible-revisions','Draft updates','Rewrite a published page while visitors keep seeing the current version. Publish the replacement when it is ready.');
echo '<div class="tt-flow"><span>1. Choose a live page</span><span>2. Edit its replacement</span><span>3. Publish now or schedule</span></div><div class="tt-grid">';
\TerribleTools\Admin::card('Prepare an update','NEW DRAFT');
UI::form('terrible-revisions','create');
$source=absint($_GET['source']??0); if ($source && (get_post_status($source)!=='publish' || !current_user_can('edit_post',$source))) $source=0;
\TerribleTools\Admin::picker('source',$source);
echo '<p class="submit"><button type="submit" class="button button-primary" '.(!$source?'disabled':'').'>Prepare update</button></p></form></section>';
\TerribleTools\Admin::card('One page, two versions','HOW IT WORKS');
echo '<p>Prepare Monday’s pricing change on Friday. Edit and preview the draft in the normal WordPress editor. Readers keep seeing the live page until you publish its replacement.</p><p>The existing URL stays the same. If someone changes the live page meanwhile, publication stops for review.</p><p class="tt-muted">Updates include title, body, excerpt, taxonomy terms, featured image, and page template. Custom fields are not copied.</p></section></div>';
$drafts=get_posts(['post_type'=>['post','page'],'post_status'=>'draft','numberposts'=>100,'meta_key'=>'_terrible_revision_target']);
echo '<h2>Prepared updates</h2>';
if (!$drafts) echo '<p class="tt-empty">No updates in progress. Choose a published page above to prepare your first draft.</p>';
foreach ($drafts as $p) {
    $target=(int)get_post_meta($p->ID,'_terrible_revision_target',true); $status=get_post_meta($p->ID,'_terrible_revision_status',true); $error=get_post_meta($p->ID,'_terrible_revision_error',true);
    \TerribleTools\Admin::card($p->post_title ?: '(Untitled)', strtoupper($status ?: 'DRAFT'));
    echo '<p>Updates <a href="'.esc_url(get_permalink($target)).'">'.esc_html(get_the_title($target)).'</a></p>';
    if ($error) echo '<p class="tt-error">'.esc_html($error).'</p>';
    if ($status==='scheduled') echo '<p class="tt-status">Scheduled for '.esc_html(wp_date('F j, Y \a\t H:i',(int)get_post_meta($p->ID,'_terrible_revision_time',true))).' ('.esc_html(wp_timezone_string()).')</p>';
    echo '<p class="tt-actions"><a class="button button-primary" href="'.esc_url(get_edit_post_link($p->ID)).'">Edit draft</a><a class="button" href="'.esc_url(get_preview_post_link($p->ID)).'">Preview draft</a><a class="button" href="'.esc_url(\TerribleTools\Admin::url('terrible-revisions',['compare'=>$p->ID])).'">Compare with live page</a></p>';
    if ((int)($_GET['compare']??0)===$p->ID) {
        require_once ABSPATH.'wp-admin/includes/revision.php';
        $live=get_post($target); if ($live) { echo '<div class="tt-table"><h3>Live page → draft</h3>'; $diff=wp_text_diff($live->post_title."\n\n".$live->post_content,$p->post_title."\n\n".$p->post_content,['title_left'=>'Live','title_right'=>'Draft']); echo $diff ?: '<p>Title and body are unchanged.</p>'; echo '<p class="tt-muted">This comparison covers title and body. Review taxonomy, featured image and template in the editor before publishing.</p></div>'; }
    }
    echo '<details><summary>Publish this update</summary><div class="tt-grid"><div>';
    UI::form('terrible-revisions','apply'); echo '<input type="hidden" name="id" value="'.$p->ID.'"><p>Replace the live page with this draft now.</p>'; UI::submit('Publish update now'); echo '</div><div>';
    UI::form('terrible-revisions','schedule'); echo '<input type="hidden" name="id" value="'.$p->ID.'">'; UI::field('when','Publication time','','datetime-local'); UI::submit('Schedule update'); echo '</div></div>';
    if ($status==='scheduled') { UI::form('terrible-revisions','cancel'); echo '<input type="hidden" name="id" value="'.$p->ID.'">'; UI::submit('Cancel schedule'); }
    echo '</details></section>';
}
echo '<p class="tt-help">Schedules use '.esc_html(wp_timezone_string()).' and WordPress cron; quiet sites can publish late. Up to 100 pending drafts are displayed.</p></div>';
