<?php
namespace TerribleLinks;
defined('ABSPATH') || exit;
final class Plugin {
    public static function table(): string { global $wpdb; return $wpdb->prefix.'terrible_links'; }
    public static function install(): void { if (get_option('terrible_links_schema')==='1') return; global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $table=self::table();
        dbDelta("CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint unsigned NOT NULL,
            url text NOT NULL,
            url_hash varchar(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            http_code int NOT NULL DEFAULT 0,
            checked bigint NOT NULL DEFAULT 0,
            ignored tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY reference (post_id,url_hash)
        ) ".$wpdb->get_charset_collate().';'); update_option('terrible_links_schema','1',false);
    }
    public static function boot(): void { self::install(); UI::boot('terrible-links','Terrible Links',[self::class,'page'],[self::class,'action']); }
    public static function urls(string $content,int $id): array {
        $html=new \WP_HTML_Tag_Processor($content); $out=[];
        while ($html->next_tag()) { $tag=$html->get_tag(); $attr=$tag==='A'?'href':($tag==='IMG'?'src':''); if (!$attr) continue; $url=$html->get_attribute($attr); if (!is_string($url) || $url==='' || str_starts_with($url,'#')) continue;
            $url=\WP_Http::make_absolute_url($url,get_permalink($id)); $url=preg_replace('/#.*$/','',$url); $p=wp_parse_url($url);
            if ($p && in_array($p['scheme']??'',['http','https'],true) && !isset($p['user']) && !isset($p['pass']) && strlen($url)<=2048) $out[$url]=true;
        }
        return array_keys($out);
    }
    public static function scan(): int {
        global $wpdb; $wpdb->query('DELETE l FROM '.self::table()." l LEFT JOIN $wpdb->posts p ON p.ID=l.post_id WHERE p.ID IS NULL OR p.post_status!='publish'"); $cursor=(int)get_option('terrible_links_cursor',0); $types=get_post_types(['public'=>true]); unset($types['attachment']); if (!$types) return 0;
        $placeholders=implode(',',array_fill(0,count($types),'%s'));
        $posts=$wpdb->get_results($wpdb->prepare("SELECT ID,post_content FROM $wpdb->posts WHERE post_status='publish' AND post_type IN ($placeholders) AND ID>%d ORDER BY ID LIMIT 20",...array_merge(array_values($types),[$cursor])));
        $remaining=max(0,5000-(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table()));
        foreach ($posts as $post) {
            $urls=self::urls($post->post_content,(int)$post->ID); $hashes=array_map(static fn($u)=>hash('sha256',$u),$urls);
            $existing=$wpdb->get_results($wpdb->prepare('SELECT id,url_hash FROM '.self::table().' WHERE post_id=%d',$post->ID)); foreach ($existing as $r) if (!in_array($r->url_hash,$hashes,true)) $wpdb->delete(self::table(),['id'=>$r->id]);
            foreach ($urls as $url) { if ($remaining<=0) break; $inserted=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.self::table().' (post_id,url,url_hash) VALUES (%d,%s,%s)',$post->ID,$url,hash('sha256',$url))); if ($inserted) $remaining--; }
            update_option('terrible_links_cursor',(int)$post->ID,false);
        }
        if (count($posts)<20) update_option('terrible_links_cursor',0,false); return count($posts);
    }
    public static function check(string $url): array {
        $args=['timeout'=>4,'redirection'=>3,'limit_response_size'=>1024,'user-agent'=>'TerribleLinks/0.1; '.home_url()];
        $r=wp_safe_remote_head($url,$args); if (!is_wp_error($r) && wp_remote_retrieve_response_code($r)===405) $r=wp_safe_remote_get($url,$args);
        if (is_wp_error($r)) return ['status'=>'unverified','http_code'=>0]; $code=wp_remote_retrieve_response_code($r);
        return ['status'=>in_array($code,[404,410],true)?'broken':($code>=200 && $code<300?'working':'unverified'),'http_code'=>$code];
    }
    public static function batch(): int {
        global $wpdb; $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE ignored=0 AND checked<%d ORDER BY checked,id LIMIT 5',time()-7*DAY_IN_SECONDS));
        foreach ($rows as $r) { if (get_post_status($r->post_id)!=='publish') { $wpdb->delete(self::table(),['id'=>$r->id]); continue; } $result=self::check($r->url); $wpdb->update(self::table(),$result+['checked'=>time()],['id'=>$r->id]); } return count($rows);
    }
    public static function replace(int $id,string $target): void {
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$id)); if (!$row || !current_user_can('edit_post',$row->post_id)) throw new \RuntimeException('Reference unavailable.');
        $p=wp_parse_url($target); if (!$p || !in_array($p['scheme']??'',['http','https'],true) || isset($p['user']) || isset($p['pass']) || !filter_var($target,FILTER_VALIDATE_URL)) throw new \RuntimeException('Enter an absolute HTTP(S) URL without credentials.');
        $post=get_post($row->post_id); $html=new \WP_HTML_Tag_Processor($post->post_content); $changed=false;
        while ($html->next_tag()) { $attr=$html->get_tag()==='A'?'href':($html->get_tag()==='IMG'?'src':''); if (!$attr) continue; $old=$html->get_attribute($attr); if (is_string($old) && preg_replace('/#.*$/','',\WP_Http::make_absolute_url($old,get_permalink($post)))===$row->url) { $html->set_attribute($attr,$target); $changed=true; } }
        if (!$changed) throw new \RuntimeException('Content changed since the scan. Scan it again.');
        wp_save_post_revision($post->ID); $result=wp_update_post(wp_slash(['ID'=>$post->ID,'post_content'=>$html->get_updated_html()]),true); if (is_wp_error($result)) throw new \RuntimeException('The content could not be updated.'); $wpdb->delete(self::table(),['id'=>$id]);
    }
    public static function action(string $op,array $d): string {
        global $wpdb; $id=(int)($d['id']??0);
        if ($op==='scan') return 'Indexed '.self::scan().' posts. Continue indexing to cover larger sites.';
        if ($op==='check') return 'Checked '.self::batch().' references. Run again for the next batch.';
        if ($op==='replace') { self::replace($id,trim($d['target']??'')); return 'Reference replaced. WordPress revisions preserve the previous content.'; }
        if ($op==='ignore') { $wpdb->update(self::table(),['ignored'=>1],['id'=>$id]); return 'Reference dismissed.'; }
        if ($op==='restore') { $wpdb->query('UPDATE '.self::table().' SET ignored=0'); return 'Dismissed references restored.'; }
        if ($op==='export') UI::export('terrible-links',self::rows()); throw new \RuntimeException('Unknown operation.');
    }
    public static function rows(): array { global $wpdb; return $wpdb->get_results('SELECT * FROM '.self::table()." WHERE ignored=0 ORDER BY FIELD(status,'broken','unverified','pending','working'),checked DESC,id DESC LIMIT 500",ARRAY_A); }
    public static function page(): void { require __DIR__.'/page.php'; }
}
