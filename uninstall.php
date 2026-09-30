<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
delete_option('fwerkor_autopush_options');
delete_option('fwerkor_autopush_log');
wp_clear_scheduled_hook('fwerkor_autopush_submit_post');
