<?php
use TerribleSocialImages\Plugin;
function package_check($ok,$label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
update_option(Plugin::OPTION,['backgrounds'=>[],'color'=>'#202838','text'=>'#ffffff','overlay'=>45,'enabled'=>1,'font'=>'bundled']);
$id=wp_insert_post(['post_title'=>'Packaged plugin integration','post_status'=>'publish','post_type'=>'post']);
Plugin::generate($id); $image=(int)get_post_meta($id,Plugin::META,true); $url=wp_get_attachment_url($image);
package_check($image>0 && \TerribleMetadata\Plugin::values($id)['image']===$image,'packaged Metadata selects generated card');
$response=wp_remote_get(get_permalink($id)); $html=wp_remote_retrieve_body($response);
package_check(!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200 && str_contains($html,'property="og:image" content="'.esc_url($url).'"'),'public page advertises generated card');
$response=wp_remote_get($url); package_check(!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200 && str_starts_with(wp_remote_retrieve_body($response),"\xff\xd8"),'public card URL returns JPEG bytes');
$manual=wp_insert_attachment(['post_mime_type'=>'image/jpeg','post_title'=>'Manual override','post_status'=>'inherit'],get_attached_file($image),0,true); update_post_meta($id,'_terrible_meta_image',$manual);
package_check(\TerribleMetadata\Plugin::values($id)['image']===$manual,'manual Metadata image takes priority in packaged plugins');
echo "PACKAGED PLUGINS PASSED\n";
