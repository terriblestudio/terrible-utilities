<?php
namespace TerribleRevisions;
defined('ABSPATH') || exit;
final class Plugin {
    public static function install(): void {}
    public static function boot(): void {
        UI::boot('terrible-revisions','Terrible Revisions',[self::class,'page'],[self::class,'action']);
        add_action('terrible_revisions_publish',[self::class,'scheduled']);
        add_filter('wp_insert_post_data',static function($data,$postarr) { if (!empty($postarr['ID']) && get_post_meta($postarr['ID'],'_terrible_revision_target',true) && in_array($data['post_status'],['publish','future'],true)) $data['post_status']='draft'; return $data; },10,2);
        add_filter('post_row_actions',[self::class,'row'],10,2); add_filter('page_row_actions',[self::class,'row'],10,2);
        add_action('add_meta_boxes',static function($type,$post){ if ($post && get_post_meta($post->ID,'_terrible_revision_target',true)) add_meta_box('terrible-revision-info','Pending content replacement',static function($p){ echo '<p>This draft replaces published content only when applied through <a href="'.esc_url(admin_url('tools.php?page=terrible-revisions')).'">Terrible Revisions</a>. Saving here leaves the live page unchanged.</p>'; },$type,'side'); },10,2);
    }
    public static function row(array $actions,$post): array { if ($post->post_status==='publish' && current_user_can('manage_options')) $actions['terrible_revision']='<a href="'.esc_url(admin_url('tools.php?page=terrible-revisions&source='.$post->ID)).'">Prepare update</a>'; return $actions; }
    private static function snapshot(int $id): array {
        $p=get_post($id); if (!$p) throw new \RuntimeException('Content not found.');
        $tax=[]; foreach (get_object_taxonomies($p->post_type) as $taxonomy) { $terms=wp_get_object_terms($id,$taxonomy,['fields'=>'ids']); if (is_wp_error($terms)) throw new \RuntimeException('Could not read terms.'); sort($terms); $tax[$taxonomy]=$terms; } ksort($tax);
        return ['post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt,'tax'=>$tax,'thumbnail'=>get_post_thumbnail_id($id),'template'=>get_post_meta($id,'_wp_page_template',true)];
    }
    public static function create(int $source): int {
        $p=get_post($source); if (!$p || $p->post_status!=='publish' || !current_user_can('edit_post',$source)) throw new \RuntimeException('Choose published content you can edit.');
        $snapshot=self::snapshot($source); $id=wp_insert_post(wp_slash(['post_type'=>$p->post_type,'post_status'=>'draft','post_author'=>get_current_user_id(),'post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt]),true); if (is_wp_error($id)) throw new \RuntimeException($id->get_error_message());
        update_post_meta($id,'_terrible_revision_target',$source); update_post_meta($id,'_terrible_revision_base',hash('sha256',wp_json_encode($snapshot))); update_post_meta($id,'_terrible_revision_status','draft');
        foreach ($snapshot['tax'] as $taxonomy=>$terms) wp_set_object_terms($id,$terms,$taxonomy); if ($snapshot['thumbnail']) set_post_thumbnail($id,$snapshot['thumbnail']); update_post_meta($id,'_wp_page_template',$snapshot['template']); return $id;
    }
    public static function apply(int $id): void {
        if (get_post_meta($id,'_terrible_revision_status',true)==='applied') return;
        global $wpdb; $target=(int)get_post_meta($id,'_terrible_revision_target',true); $draft=get_post($id); $live=get_post($target);
        if (!$draft || !$live || $draft->post_status!=='draft' || $live->post_status!=='publish' || !current_user_can('edit_post',$target) || !current_user_can(get_post_type_object($live->post_type)->cap->publish_posts)) throw new \RuntimeException('This update cannot be published by the current user.');
        $lock='terrible-revision-'.substr(hash('sha256',$wpdb->prefix.':'.$target),0,40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1) throw new \RuntimeException('Another update is being applied. Try again.');
        $transaction=false;
        try {
            foreach ([$wpdb->posts,$wpdb->postmeta,$wpdb->term_relationships,$wpdb->term_taxonomy] as $table) { $engine=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($table))); if (!$engine || strtoupper($engine->Engine)!=='INNODB') throw new \RuntimeException('Safe revision publication requires InnoDB WordPress content tables.'); }
            if ($wpdb->query('START TRANSACTION')===false) throw new \RuntimeException('Cannot start publication transaction.'); $transaction=true;
            $wpdb->get_row($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID=%d FOR UPDATE",$target));
            $wpdb->get_results($wpdb->prepare("SELECT meta_id FROM $wpdb->postmeta WHERE post_id=%d FOR UPDATE",$target));
            $wpdb->get_results($wpdb->prepare("SELECT term_taxonomy_id FROM $wpdb->term_relationships WHERE object_id=%d FOR UPDATE",$target));
            clean_post_cache($target);
            if (!hash_equals((string)get_post_meta($id,'_terrible_revision_base',true),hash('sha256',wp_json_encode(self::snapshot($target))))) throw new \RuntimeException('The live content changed after this draft was created. Create a fresh update and reconcile your changes.');
            $replacement=self::snapshot($id); wp_save_post_revision($target);
            $result=wp_update_post(wp_slash(['ID'=>$target,'post_title'=>$replacement['post_title'],'post_content'=>$replacement['post_content'],'post_excerpt'=>$replacement['post_excerpt']]),true); if (is_wp_error($result)) throw new \RuntimeException('Could not update live content.');
            foreach ($replacement['tax'] as $taxonomy=>$terms) { $set=wp_set_object_terms($target,$terms,$taxonomy); if (is_wp_error($set)) throw new \RuntimeException('Could not update taxonomy terms.'); } if ($replacement['thumbnail']) set_post_thumbnail($target,$replacement['thumbnail']); else delete_post_thumbnail($target); update_post_meta($target,'_wp_page_template',$replacement['template']);
            if ($wpdb->query('COMMIT')===false) throw new \RuntimeException('Publication outcome could not be confirmed. Review the live page.'); $transaction=false;
            update_post_meta($id,'_terrible_revision_status','applied'); wp_clear_scheduled_hook('terrible_revisions_publish',[$id]); wp_trash_post($id); do_action('terrible_tools_event','revision.applied',$target,'Prepared update applied to live content.');
        } catch (\Throwable $e) { if ($transaction) $wpdb->query('ROLLBACK'); clean_post_cache($target); throw $e; } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function schedule(int $id,int $when): void {
        $target=(int)get_post_meta($id,'_terrible_revision_target',true); if (!$target || get_post_status($id)!=='draft' || !current_user_can('edit_post',$target) || !current_user_can(get_post_type_object(get_post_type($target))->cap->publish_posts)) throw new \RuntimeException('You cannot schedule this update.');
        if ($when<=time()+30 || $when>time()+365*DAY_IN_SECONDS) throw new \RuntimeException('Choose a time at least 30 seconds ahead and within one year.');
        wp_clear_scheduled_hook('terrible_revisions_publish',[$id]); $ok=wp_schedule_single_event($when,'terrible_revisions_publish',[$id],true); if (is_wp_error($ok) || !$ok) throw new \RuntimeException('Could not schedule this update.');
        update_post_meta($id,'_terrible_revision_publisher',get_current_user_id()); update_post_meta($id,'_terrible_revision_error',''); update_post_meta($id,'_terrible_revision_status','scheduled'); update_post_meta($id,'_terrible_revision_time',$when); do_action('terrible_tools_event','revision.scheduled',$target);
    }
    public static function scheduled(int $id): void {
        $previous=get_current_user_id(); $publisher=(int)get_post_meta($id,'_terrible_revision_publisher',true);
        try { wp_set_current_user($publisher); self::apply($id); }
        catch (\Throwable $e) { update_post_meta($id,'_terrible_revision_status','failed'); update_post_meta($id,'_terrible_revision_error',$e->getMessage()); }
        finally { wp_set_current_user($previous); }
    }
    public static function action(string $op,array $d): string {
        $id=(int)($d['id']??0);
        if ($op==='create') return 'Draft #'.self::create((int)($d['source']??0)).' created. Open it in the editor below.';
        if ($op==='apply') { self::apply($id); return 'Live content updated.'; }
        if ($op==='schedule') { $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$d['when']??'',wp_timezone()); if (!$date) throw new \RuntimeException('Enter a valid date and time.'); self::schedule($id,$date->getTimestamp()); return 'Update scheduled.'; }
        if ($op==='cancel') { wp_clear_scheduled_hook('terrible_revisions_publish',[$id]); update_post_meta($id,'_terrible_revision_status','draft'); return 'Schedule cancelled; draft retained.'; } throw new \RuntimeException('Unknown operation.');
    }
    public static function page(): void { require __DIR__.'/page.php'; }
}
