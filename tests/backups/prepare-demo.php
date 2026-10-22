<?php
use TerribleBackups\{Engine, Transport};
// Only used inside the explicitly disposable Compose project after tests finish.
if (home_url() !== 'http://localhost:18765' || !defined('TERRIBLE_BACKUPS_DIR') || TERRIBLE_BACKUPS_DIR !== '/var/lib/terrible-backups') { throw new RuntimeException('Not the isolated demo.'); }
wp_set_current_user(1);
foreach(Engine::listing() as $j) { if(!in_array($j['phase'],['done','ready','failed','cancelled'],true))throw new RuntimeException('Active job exists; refusing cleanup.'); }
foreach(Engine::listing() as $j) { Engine::delete($j['id']); }
$j=Engine::backup(['database','uploads']);
while(!in_array($j['phase'],['done','failed'],true))$j=Engine::step($j['id']);
if($j['phase']!=='done')throw new RuntimeException($j['error']);
echo 'Demo archive ready: '.$j['id']."\n";
