<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * A small snippet to forcibly disable Yoast's sitemaps & robots once we have ours.
 */
function seo_setup_disable_yoast_callback() {
    check_ajax_referer('seo_setup_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No permission.'));
    }

    // If Yoast is active, disable its sitemaps
    if (is_plugin_active('wordpress-seo/wp-seo.php')) {
        // 1) Turn off Yoast XML sitemaps
        add_filter('option_wpseo', 'seo_setup_disable_yoast_sitemaps_option');

        // 2) Remove Yoast's robots.txt filter if needed
        //    Typically done by removing the callback from 'robots_txt'
        if (class_exists('WPSEO_Frontend') && method_exists('WPSEO_Frontend', 'robots')) {
            remove_filter('robots_txt', array(WPSEO_Frontend::get_instance(), 'robots'));
        }

        // If you want to forcibly flush or reset permalinks, you can do it here:
        flush_rewrite_rules();
        
        wp_send_json_success(array('message' => 'Yoast sitemaps/robots disabled.'));
    }

    wp_send_json_success(array('message' => 'Yoast not active or no action needed.'));
}

/**
 * Filter the 'option_wpseo' to set enablexmlsitemap=0
 */
function seo_setup_disable_yoast_sitemaps_option($options) {
    if (isset($options['enablexmlsitemap'])) {
        $options['enablexmlsitemap'] = false;  // effectively disables Yoast's sitemaps
    }
    return $options;
}
