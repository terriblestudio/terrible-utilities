<?php
wp_set_current_user(1);
// This script is only for the disposable Docker demo.
foreach(['2'=>'yes','19'=>'yes','24'=>'yes','34'=>'yes','25'=>'yes','36'=>'yes','3'=>'','30'=>'','38'=>'','39'=>'','41'=>''] as $key=>$value) update_option('vscf-setting-'.$key,$value);
$p=get_page_by_path('contact-demo'); $id=wp_insert_post(['ID'=>$p?$p->ID:0,'post_type'=>'page','post_name'=>'contact-demo','post_status'=>'publish','post_title'=>'Contact','post_content'=>'<!-- wp:paragraph --><p>A simple contact form with a saved inbox and mail sent through Terrible Mail to the local test server.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[contact]<!-- /wp:shortcode -->']);
file_put_contents('/tmp/contact-demo-id',(string)$id);
// Remove only byte-identical duplicate fixtures made by the earlier test runner.
$c=TerribleBackups\Transport::settings();$seen=[];
foreach($c['_connections']??[] as$id=>$item){if($item['label']!=='Independent drive')continue;$key=hash('sha256',wp_json_encode($item));if(isset($seen[$key]))unset($c['_connections'][$id]);else$seen[$key]=true;}
TerribleBackups\Transport::save($c);
$all=TerribleMail\Settings::connections();$seen=[];foreach($all as$id=>$item){if($item['label']!=='Local SMTP')continue;$key=hash('sha256',wp_json_encode($item));if(isset($seen[$key]))TerribleMail\Settings::forget($id);else$seen[$key]=true;}
TerribleMail\Settings::save(['enabled'=>true,'transport'=>'smtp','from'=>'sender@example.test','name'=>'Terrible demo','providers'=>['smtp'=>['host'=>'mock','port'=>1025,'security'=>'none','username'=>'','password'=>'']]]);
echo "Replacement demo prepared.\n";
