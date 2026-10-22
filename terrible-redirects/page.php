<?php
namespace TerribleRedirects;
defined('ABSPATH') || exit;
UI::header('terrible-redirects','Terrible Redirects','Send visitors from an old address to the right page.');
echo '<div class="tt-grid">'; \TerribleTools\Admin::card('Add a redirect','01 / OLD → NEW');
UI::form('terrible-redirects','save'); UI::field('source','Old path',sanitize_text_field($_GET['source']??'')); UI::field('target','New URL or path','');
echo '<p class="tt-muted">For example: /old-menu/ → /menu/</p><label>Redirect type<select name="status"><option value="301">Permanent move (301)</option><option value="302">Temporary move (302)</option><option value="307">Temporary, preserve method (307)</option><option value="308">Permanent, preserve method (308)</option></select></label>'; UI::submit('Save redirect');echo '</section>';
\TerribleTools\Admin::card('Keep old links useful','WHAT TO EXPECT');echo '<p>Use a permanent redirect when a page has moved for good. Use a temporary redirect for a short-lived change.</p><p>Paths match exactly, including their trailing slash. Incoming query strings are discarded; the destination can contain its own query.</p><p class="tt-muted">Loops and WordPress administrative source paths are rejected.</p></section></div>';
\TerribleTools\Admin::card('Your redirects');
if (!self::rules()) echo '<p class="tt-empty">No redirects yet. Add an old path and its new destination above.</p>';
else { echo '<div class="tt-table"><table class="widefat striped"><thead><tr><th>Old path</th><th>Destination</th><th>Type</th><th>Action</th></tr></thead><tbody>'; foreach(self::rules() as $path=>$r){echo '<tr><td>'.esc_html($path).'</td><td>'.esc_html($r['target']).'</td><td>'.(int)$r['status'].'</td><td>';UI::form('terrible-redirects','delete');echo '<input type="hidden" name="source" value="'.esc_attr($path).'">';UI::submit('Remove');echo '</td></tr>';}echo '</tbody></table></div>';UI::form('terrible-redirects','export');UI::submit('Export redirects');}echo '</section>';
\TerribleTools\Admin::card('Pages visitors could not find','RECENT 404s');
$missing=get_option('terrible_redirects_404',[]);
if (!$missing) echo '<p class="tt-empty">No missing pages recorded. New 404 requests will appear here.</p>';
else {echo '<div class="tt-table"><table class="widefat striped"><tr><th>Path</th><th>Visits</th><th>Last seen</th><th>Action</th></tr>';foreach($missing as $r)echo '<tr><td>'.esc_html($r['path']).'</td><td>'.(int)$r['count'].'</td><td>'.esc_html(wp_date('M j, H:i',$r['last'])).'</td><td><a class="button" href="'.esc_url(\TerribleTools\Admin::url('terrible-redirects',['source'=>$r['path']])).'">Create redirect</a></td></tr>';echo '</table></div>';UI::form('terrible-redirects','clear');UI::submit('Clear missing pages');}
echo '<p class="tt-help">Up to 200 paths for 30 days. No IP addresses or query strings are stored.</p></section></div>';
