<?php
namespace TerribleRedirects;
defined('ABSPATH') || exit;
final class Plugin {
    public static function install(): void {}
    public static function boot(): void { UI::boot('terrible-redirects','Terrible Redirects',[self::class,'page'],[self::class,'action']); add_action('template_redirect',[self::class,'visit'],0); }
    public static function rules(): array { return get_option('terrible_redirects_rules',[]); }
    public static function path(string $path): string {
        if (strlen($path)>2048 || !str_starts_with($path,'/') || str_starts_with($path,'//') || preg_match('/[\\\\\x00-\x20?#]/',$path) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|2f|5c)/i',$path)) throw new \RuntimeException('Use a site-relative path without a query, fragment, or encoded slash.');
        if (preg_match('~^/(?:wp-admin|wp-login\.php|wp-json|xmlrpc\.php)(?:/|$)~i',$path)) throw new \RuntimeException('WordPress administrative and API routes cannot be redirected.');
        return $path;
    }
    public static function target(string $url): string {
        if (str_starts_with($url,'/') && !str_starts_with($url,'//')) { self::path((string)wp_parse_url($url,PHP_URL_PATH)); if (preg_match('/[\\\\\x00-\x20]/',$url)) throw new \RuntimeException('Invalid target URL.'); return $url; }
        $p=wp_parse_url($url); if (!$p || !in_array($p['scheme']??'',['http','https'],true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\\\\\x00-\x20]/',$url)) throw new \RuntimeException('Target must be an HTTP(S) URL or a site-relative path.'); return $url;
    }
    private static function local(string $url): ?string { if (str_starts_with($url,'/')) return (string)wp_parse_url($url,PHP_URL_PATH); $p=wp_parse_url($url); $home=wp_parse_url(home_url()); return strtolower($p['host']??'')===strtolower($home['host']??'') && ($p['port']??null)===($home['port']??null) ? ($p['path']??'/') : null; }
    public static function save(string $from,string $to,int $status): void {
        $from=self::path($from); $to=self::target($to); if (!in_array($status,[301,302,307,308],true)) throw new \RuntimeException('Choose 301, 302, 307, or 308.');
        $rules=self::rules(); $rules[$from]=['target'=>$to,'status'=>$status]; if (count($rules)>1000) throw new \RuntimeException('The limit is 1,000 redirects.');
        foreach ($rules as $start=>$rule) { $seen=[]; $current=$start; while (isset($rules[$current])) { if (isset($seen[$current])) throw new \RuntimeException('This redirect would create a loop.'); $seen[$current]=true; $current=self::local($rules[$current]['target']); if ($current===null) break; } }
        update_option('terrible_redirects_rules',$rules,false); do_action('terrible_tools_event','redirects.changed',0);
    }
    public static function visit(): void {
        if (is_admin() || ($_SERVER['REQUEST_METHOD']??'GET')!=='GET' && ($_SERVER['REQUEST_METHOD']??'')!=='HEAD') return;
        $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        if (isset(self::rules()[$path])) { $r=self::rules()[$path]; $target=$r['target']; if (str_starts_with($target,'/')) { $home=wp_parse_url(home_url()); $target=$home['scheme'].'://'.$home['host'].(isset($home['port'])?':'.$home['port']:'').$target; } wp_redirect($target,$r['status'],'Terrible Redirects'); exit; }
        if (is_404()) self::missing($path);
    }
    public static function missing(string $path): void {
        try { self::path($path); } catch (\Throwable $e) { return; }
        $rows=get_option('terrible_redirects_404',[]); $key=hash('sha256',$path); $rows[$key]=['path'=>$path,'count'=>($rows[$key]['count']??0)+1,'last'=>time()];
        $rows=array_filter($rows,static fn($r)=>$r['last']>time()-30*DAY_IN_SECONDS); uasort($rows,static fn($a,$b)=>$b['last']<=>$a['last']); update_option('terrible_redirects_404',array_slice($rows,0,200,true),false);
    }
    public static function action(string $op,array $d): string {
        if ($op==='save') self::save(trim($d['source']??''),trim($d['target']??''),(int)($d['status']??301));
        elseif ($op==='delete') { $rules=self::rules(); unset($rules[$d['source']??'']); update_option('terrible_redirects_rules',$rules,false); do_action('terrible_tools_event','redirects.changed',0); }
        elseif ($op==='clear') delete_option('terrible_redirects_404');
        elseif ($op==='export') UI::export('terrible-redirects',self::rules());
        else throw new \RuntimeException('Unknown operation.'); return 'Saved.';
    }
    public static function page(): void { require __DIR__.'/page.php'; }
}
