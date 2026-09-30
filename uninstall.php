<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;
delete_option('fwerkor_autopush_options');
delete_post_meta_by_key('_fwerkor_autopush_hash');
wp_clear_scheduled_hook('fwerkor_autopush_submit_post');
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'fwerkor_autopush_log');
