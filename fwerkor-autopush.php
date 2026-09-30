<?php
/**
 * Plugin Name: FWERKOR Auto Push
 * Plugin URI: https://github.com/fwerkor/wordpress-plugin-fwerkor-autopush
 * Description: Asynchronous first-party URL submission to Baidu, Bing, and IndexNow when WordPress posts are published or materially updated.
 * Version: 1.0.0
 * Author: FWERKOR
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class FWERKOR_Auto_Push {
    private const OPTION = 'fwerkor_autopush_options';
    private const META_HASH = '_fwerkor_autopush_hash';
    private const CRON_HOOK = 'fwerkor_autopush_submit_post';
    private const TABLE_SUFFIX = 'fwerkor_autopush_log';

    public function __construct() {
        add_action('wp_after_insert_post', array($this, 'after_insert'), 50, 4);
        add_action(self::CRON_HOOK, array($this, 'push_post'), 10, 2);
        add_action('template_redirect', array($this, 'serve_indexnow_key'));
        add_action('admin_menu', array($this, 'menu'));
    }

    public static function activate(): void {
        global $wpdb;

        $existing = (array) get_option(self::OPTION, array());
        $defaults = array(
            'baidu_enabled' => 0,
            'baidu_token' => '',
            'bing_enabled' => 0,
            'bing_token' => '',
            'indexnow_enabled' => 1,
            'indexnow_key' => wp_generate_password(32, false, false),
            'timeout' => 12,
        );

        if (empty($existing)) {
            $legacy = (array) get_option('ggpush_options', array());
            $platforms = array_map('intval', (array) ($legacy['publish_article_platform'] ?? array()));
            $defaults['baidu_token'] = sanitize_text_field((string) ($legacy['baidu_token'] ?? ''));
            $defaults['bing_token'] = sanitize_text_field((string) ($legacy['bing_token'] ?? ''));
            $defaults['indexnow_key'] = sanitize_text_field((string) ($legacy['indexnow_token'] ?? '')) ?: $defaults['indexnow_key'];
            $defaults['baidu_enabled'] = in_array(1, $platforms, true) && '' !== $defaults['baidu_token'] ? 1 : 0;
            $defaults['bing_enabled'] = in_array(4, $platforms, true) && '' !== $defaults['bing_token'] ? 1 : 0;
            $defaults['indexnow_enabled'] = in_array(7, $platforms, true) ? 1 : 0;
        }

        add_option(self::OPTION, $defaults, '', false);

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();
        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL,
                engine varchar(20) NOT NULL,
                status varchar(12) NOT NULL,
                http_code smallint(5) unsigned NOT NULL DEFAULT 0,
                message text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY post_id (post_id),
                KEY created_at (created_at)
            ) {$charset};"
        );
    }

    public static function deactivate(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public function after_insert(int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ('post' !== $post->post_type || 'publish' !== $post->post_status) {
            return;
        }

        $hash = hash(
            'sha256',
            get_permalink($post_id) . '|' . $post->post_title . '|' . $post->post_content . '|' . $post->post_modified_gmt
        );
        $previous = (string) get_post_meta($post_id, self::META_HASH, true);

        if (hash_equals($hash, $previous)) {
            return;
        }

        update_post_meta($post_id, self::META_HASH, $hash);

        $args = array($post_id, $hash);
        if (!wp_next_scheduled(self::CRON_HOOK, $args)) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK, $args);
        }
    }

    public function push_post(int $post_id, string $expected_hash): void {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || 'publish' !== $post->post_status) {
            return;
        }

        $current_hash = (string) get_post_meta($post_id, self::META_HASH, true);
        if (!hash_equals($expected_hash, $current_hash)) {
            return;
        }

        $url = get_permalink($post_id);
        if (!$url) {
            return;
        }

        $o = $this->options();

        if (!empty($o['baidu_enabled']) && '' !== trim((string) $o['baidu_token'])) {
            $this->push_baidu($post_id, $url, $o);
        }
        if (!empty($o['bing_enabled']) && '' !== trim((string) $o['bing_token'])) {
            $this->push_bing($post_id, $url, $o);
        }
        if (!empty($o['indexnow_enabled']) && '' !== trim((string) $o['indexnow_key'])) {
            $this->push_indexnow($post_id, $url, $o);
        }

        $this->prune_logs();
    }

    private function push_baidu(int $post_id, string $url, array $o): void {
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $endpoint = add_query_arg(
            array('site' => $host, 'token' => $o['baidu_token']),
            'https://data.zz.baidu.com/urls'
        );

        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => (int) $o['timeout'],
                'sslverify' => true,
                'headers' => array('Content-Type' => 'text/plain; charset=utf-8'),
                'body' => $url,
            )
        );

        $this->log_response($post_id, 'baidu', $response);
    }

    private function push_bing(int $post_id, string $url, array $o): void {
        $endpoint = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch?apikey=' . rawurlencode((string) $o['bing_token']);
        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => (int) $o['timeout'],
                'sslverify' => true,
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => wp_json_encode(array(
                    'siteUrl' => home_url('/'),
                    'urlList' => array($url),
                )),
            )
        );

        $this->log_response($post_id, 'bing', $response);
    }

    private function push_indexnow(int $post_id, string $url, array $o): void {
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $key = (string) $o['indexnow_key'];
        $key_location = home_url('/' . rawurlencode($key) . '.txt');

        $response = wp_remote_post(
            'https://api.indexnow.org/indexnow',
            array(
                'timeout' => (int) $o['timeout'],
                'sslverify' => true,
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => wp_json_encode(array(
                    'host' => $host,
                    'key' => $key,
                    'keyLocation' => $key_location,
                    'urlList' => array($url),
                )),
            )
        );

        $this->log_response($post_id, 'indexnow', $response);
    }

    private function log_response(int $post_id, string $engine, $response): void {
        global $wpdb;

        $code = 0;
        $status = 'error';
        $message = '';

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = trim((string) wp_remote_retrieve_body($response));
            $status = ($code >= 200 && $code < 300) ? 'ok' : 'error';
            $message = '' !== $body ? $body : wp_remote_retrieve_response_message($response);
        }

        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }

        $wpdb->insert(
            $wpdb->prefix . self::TABLE_SUFFIX,
            array(
                'post_id' => $post_id,
                'engine' => $engine,
                'status' => $status,
                'http_code' => $code,
                'message' => $message,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s')
        );
    }

    private function prune_logs(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $cutoff_id = (int) $wpdb->get_var("SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET 299");
        if ($cutoff_id > 0) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id < %d", $cutoff_id));
        }
    }

    public function serve_indexnow_key(): void {
        $o = $this->options();
        if (empty($o['indexnow_enabled']) || empty($o['indexnow_key'])) {
            return;
        }

        $requested = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $expected = (string) $o['indexnow_key'] . '.txt';

        if (!hash_equals($expected, $requested)) {
            return;
        }

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html((string) $o['indexnow_key']);
        exit;
    }

    public function menu(): void {
        add_options_page(
            'FWERKOR Auto Push',
            'FWERKOR Auto Push',
            'manage_options',
            'fwerkor-autopush',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['fwerkor_autopush_settings'])) {
            check_admin_referer('fwerkor_autopush_settings');
            $current = $this->options();

            foreach (array('baidu_token', 'bing_token', 'indexnow_key') as $secret) {
                $submitted = trim((string) wp_unslash($_POST[$secret] ?? ''));
                if ('' !== $submitted) {
                    $current[$secret] = sanitize_text_field($submitted);
                }
            }

            $current['baidu_enabled'] = isset($_POST['baidu_enabled']) ? 1 : 0;
            $current['bing_enabled'] = isset($_POST['bing_enabled']) ? 1 : 0;
            $current['indexnow_enabled'] = isset($_POST['indexnow_enabled']) ? 1 : 0;
            $current['timeout'] = max(3, min(60, absint($_POST['timeout'] ?? 12)));

            update_option(self::OPTION, $current, false);
            wp_safe_redirect(admin_url('options-general.php?page=fwerkor-autopush&updated=1'));
            exit;
        }

        $o = $this->options();
        global $wpdb;
        $logs = $wpdb->get_results(
            'SELECT * FROM ' . $wpdb->prefix . self::TABLE_SUFFIX . ' ORDER BY id DESC LIMIT 30',
            ARRAY_A
        );

        ?>
        <div class="wrap">
            <h1>FWERKOR Auto Push</h1>
            <p>Asynchronously submits published or materially updated post URLs. API keys remain in the WordPress database and are never written into plugin source files.</p>
            <?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif; ?>

            <form method="post" style="max-width:900px">
                <?php wp_nonce_field('fwerkor_autopush_settings'); ?>
                <input type="hidden" name="fwerkor_autopush_settings" value="1">
                <table class="form-table">
                    <?php $this->engine_row('Baidu', 'baidu', !empty($o['baidu_enabled']), !empty($o['baidu_token'])); ?>
                    <?php $this->engine_row('Bing', 'bing', !empty($o['bing_enabled']), !empty($o['bing_token'])); ?>
                    <?php $this->engine_row('IndexNow', 'indexnow', !empty($o['indexnow_enabled']), !empty($o['indexnow_key'])); ?>
                    <tr><th>Request timeout</th><td><input type="number" name="timeout" min="3" max="60" value="<?php echo esc_attr((string) $o['timeout']); ?>"> seconds</td></tr>
                </table>
                <p><button class="button button-primary" type="submit">Save settings</button></p>
            </form>

            <hr>
            <h2>Recent submissions</h2>
            <table class="widefat striped">
                <thead><tr><th>Time</th><th>Post</th><th>Engine</th><th>Status</th><th>HTTP</th><th>Response</th></tr></thead>
                <tbody>
                <?php if (empty($logs)) : ?>
                    <tr><td colspan="6">No submissions yet.</td></tr>
                <?php else : foreach ($logs as $log) : ?>
                    <tr>
                        <td><?php echo esc_html($log['created_at']); ?></td>
                        <td><?php echo esc_html(get_the_title((int) $log['post_id']) ?: ('#' . $log['post_id'])); ?></td>
                        <td><?php echo esc_html($log['engine']); ?></td>
                        <td><?php echo esc_html($log['status']); ?></td>
                        <td><?php echo esc_html((string) $log['http_code']); ?></td>
                        <td><code><?php echo esc_html(wp_trim_words($log['message'], 20)); ?></code></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function engine_row(string $label, string $key, bool $enabled, bool $configured): void {
        ?>
        <tr>
            <th><?php echo esc_html($label); ?></th>
            <td>
                <label><input type="checkbox" name="<?php echo esc_attr($key); ?>_enabled" value="1" <?php checked($enabled); ?>> Enabled</label><br>
                <input type="password" name="<?php echo esc_attr($key); ?>_<?php echo 'indexnow' === $key ? 'key' : 'token'; ?>" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $configured ? 'Configured — leave blank to keep current value' : 'Enter key/token'; ?>">
            </td>
        </tr>
        <?php
    }

    private function options(): array {
        return wp_parse_args(
            (array) get_option(self::OPTION, array()),
            array(
                'baidu_enabled' => 0,
                'baidu_token' => '',
                'bing_enabled' => 0,
                'bing_token' => '',
                'indexnow_enabled' => 1,
                'indexnow_key' => '',
                'timeout' => 12,
            )
        );
    }
}

register_activation_hook(__FILE__, array('FWERKOR_Auto_Push', 'activate'));
register_deactivation_hook(__FILE__, array('FWERKOR_Auto_Push', 'deactivate'));
new FWERKOR_Auto_Push();
