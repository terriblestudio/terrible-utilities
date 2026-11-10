<?php
namespace TerribleTools;
defined('ABSPATH') || exit;
// A bundled helper, not a plugin dependency. Only one copy registers shared hooks.
if (class_exists(__NAMESPACE__ . '\\Admin', false)) return;
final class Admin {
    private static array $pages = [];
    private static bool $booted = false;
    public static function register(string $slug, string $title, callable $callback, string $fallback = 'tools.php'): void {
        self::$pages[$slug] = compact('title', 'callback', 'fallback');
        if (self::$booted) return;
        self::$booted = true;
        add_action('admin_menu', [self::class, 'menu'], 99);
        add_action('admin_menu', [self::class, 'redirect'], 100);
        add_action('admin_enqueue_scripts', static function () {
            $slug = sanitize_key($_GET['page'] ?? '');
            if (isset(self::$pages[$slug]) || $slug === 'terrible') {
                wp_enqueue_style('terrible-tools', plugins_url('admin.css', __FILE__), [], '0.2.0');
                wp_enqueue_script('terrible-tools', plugins_url('admin.js', __FILE__), [], '0.2.0', true);
            }
        });
        add_action('wp_ajax_terrible_content_search', [self::class, 'search']);
    }
    public static function grouped(): bool { return count(self::$pages) >= 2; }
    public static function url(string $slug, array $args = []): string {
        return add_query_arg(['page' => $slug] + $args, admin_url(self::grouped() ? 'admin.php' : (self::$pages[$slug]['fallback'] ?? 'tools.php')));
    }
    public static function menu(): void {
        $labels = ['terrible-backups'=>'Backups','terrible-mail'=>'Mail','terrible-redirects'=>'Redirects','terrible-media'=>'Replace media','terrible-metadata'=>'Search & sharing','terrible-revisions'=>'Draft updates','terrible-links'=>'Links','terrible-login'=>'Login'];
        $pages = array_replace(array_intersect_key($labels, self::$pages), self::$pages);
        if (self::grouped()) {
            $first = reset($pages);
            add_menu_page('Terrible', 'Terrible', 'manage_options', 'terrible', $first['callback'], 'dashicons-admin-tools', 81);
        }
        foreach ($pages as $slug => $p) {
            $parent = self::grouped() ? 'terrible' : $p['fallback'];
            add_submenu_page($parent, $p['title'], self::grouped() ? ($labels[$slug] ?? $p['title']) : $p['title'], 'manage_options', $slug, $p['callback']);
        }
        if (self::grouped()) remove_submenu_page('terrible', 'terrible');
    }
    public static function redirect(): void {
        global $pagenow;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !in_array($pagenow, ['admin.php','tools.php','options-general.php'], true)) return;
        $slug = sanitize_key($_GET['page'] ?? '');
        if ($slug === 'terrible' && self::$pages) $slug = array_key_first(self::$pages);
        if (!isset(self::$pages[$slug]) || !current_user_can('manage_options')) return;
        $expected = self::grouped() ? 'admin.php' : self::$pages[$slug]['fallback'];
        if ($pagenow !== $expected || ($_GET['page'] ?? '') === 'terrible') { $args = wp_unslash($_GET); unset($args['page']); wp_safe_redirect(self::url($slug, $args)); exit; }
    }
    public static function header(string $title, string $description, string $badge = ''): void {
        echo '<div class="wrap tt-wrap"><header class="tt-header"><div><p class="tt-eyebrow">TERRIBLE STUDIOS / SITE TOOLS</p><h1>'.esc_html($title).'</h1><p>'.esc_html($description).'</p></div>';
        if ($badge) echo '<span class="tt-badge">'.esc_html($badge).'</span>';
        echo '</header>';
    }
    public static function card(string $title, string $eyebrow = ''): void {
        echo '<section class="tt-card">'; if ($eyebrow) echo '<span class="tt-eyebrow">'.esc_html($eyebrow).'</span>'; echo '<h2>'.esc_html($title).'</h2>';
    }
    public static function picker(string $field, int $selected = 0): void {
        echo '<div class="tt-picker" data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('terrible-content-search')).'"><label>Find a published post or page<input type="search" class="tt-content-query" placeholder="Search by title…" autocomplete="off"></label><div class="tt-search-status" role="status"></div><div class="tt-search-results"></div><input type="hidden" name="'.esc_attr($field).'" value="'.($selected ?: '').'" required><p class="tt-selection">'.($selected ? 'Selected: '.esc_html(get_the_title($selected)) : 'Choose content to continue.').'</p></div>';
    }
    public static function search(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(null, 403);
        check_ajax_referer('terrible-content-search', 'nonce');
        $query = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $posts = get_posts(['post_type'=>['post','page'], 'post_status'=>'publish', 's'=>$query, 'numberposts'=>15]);
        $out = []; foreach ($posts as $post) if (current_user_can('edit_post', $post->ID)) $out[] = ['id'=>$post->ID,'title'=>$post->post_title ?: '(Untitled)','type'=>$post->post_type,'url'=>get_permalink($post)];
        wp_send_json_success($out);
    }
}
