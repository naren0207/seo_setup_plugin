<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle Image Sitemap generation (in batches) and related functionality
 */

class SEO_Setup_Image_Sitemap {
    private static $batch_size = 500;
    private static $image_sitemap_file = '';

    public static function init() {
        add_action('wp_ajax_seo_setup_generate_image_sitemap', [__CLASS__, 'ajax_generate_image_sitemap']);

        self::$image_sitemap_file = ABSPATH . 'image-sitemap.xml';

        // Rewrite rule for serving https://example.com/image-sitemap.xml
        add_action('init', [__CLASS__, 'add_rewrite_rule']);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        add_action('template_redirect', [__CLASS__, 'catch_image_sitemap_request']);
    }

    public static function ajax_generate_image_sitemap() {
        check_ajax_referer('seo_setup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized request.']);
            return; // FIXED
        }

        $batch  = isset($_POST['batch']) ? absint($_POST['batch']) : 0;
        $offset = $batch * self::$batch_size;

        // (A) LOG start of image sitemap generation (only on first batch)
        if ($batch === 0) {
            seo_setup_logger_image_sitemap_start();
        }

        // For the first batch, (re)create the file with the header
        if ($batch === 0) {
            // FIXED: Check directory writability before attempting to write
            if ( ! seo_setup_ensure_directory_writable( ABSPATH ) ) {
                wp_send_json_error(['message' => 'WordPress root directory is not writable. Cannot create image-sitemap.xml. Please check server permissions.']);
                return;
            }

            $header  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $header .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
            $header .= 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

            if (false === file_put_contents(self::$image_sitemap_file, $header)) {
                wp_send_json_error(['message' => 'Failed to create image sitemap file header.']);
                return;
            }
        }

        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => self::$batch_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );
        $attachments = new WP_Query($args);
        $found_count = count($attachments->posts);

        if ($found_count === 0) {
            // No more images => close
            file_put_contents(self::$image_sitemap_file, "</urlset>", FILE_APPEND | LOCK_EX);

            // FIXED: Flush rewrite rules so the image sitemap URL becomes accessible
            flush_rewrite_rules( false );

            // (B) LOG completion
            seo_setup_logger_image_sitemap_completed();

            wp_send_json_success(['done' => true, 'message' => 'Image sitemap generation complete.']);
            return; // FIXED: Must return after wp_send_json_success
        }

        $fh = fopen(self::$image_sitemap_file, 'a');
        if (!$fh) {
            wp_send_json_error(['message' => 'Failed to open image sitemap file for writing.']);
            return; // FIXED: Must return after wp_send_json_error
        }

        // Collect attachment IDs for logging
        $batch_attach_ids = array();

        foreach ($attachments->posts as $attachment) {
            $img_url = wp_get_attachment_url($attachment->ID);
            if (!$img_url) {
                continue;
            }
            $post_parent = !empty($attachment->post_parent) ? (int)$attachment->post_parent : 0;
            $parent_link = ($post_parent > 0) ? get_permalink($post_parent) : $img_url;

            $img_url_esc = esc_url($img_url);
            $parent_esc  = esc_url($parent_link);

            $entry  = "\t<url>\n";
            $entry .= "\t\t<loc>{$parent_esc}</loc>\n";
            $entry .= "\t\t<image:image>\n";
            $entry .= "\t\t\t<image:loc>{$img_url_esc}</image:loc>\n";
            $entry .= "\t\t</image:image>\n";
            $entry .= "\t</url>\n";

            fwrite($fh, $entry);
            $batch_attach_ids[] = $attachment->ID;
        }

        fclose($fh);

        // (C) LOG partial data for this batch
        seo_setup_logger_image_sitemap_batch($batch, $batch_attach_ids);

        if ($found_count < self::$batch_size) {
            // close
            file_put_contents(self::$image_sitemap_file, "</urlset>", FILE_APPEND | LOCK_EX);

            // FIXED: Flush rewrite rules so the image sitemap URL becomes accessible
            flush_rewrite_rules( false );

            // (B) LOG completion
            seo_setup_logger_image_sitemap_completed();

            wp_send_json_success(['done' => true, 'message' => 'Image sitemap generation complete.']);
        } else {
            wp_send_json_success(['done' => false, 'message' => 'Batch processed.']);
        }
    }

    public static function add_rewrite_rule() {
        add_rewrite_rule('^image-sitemap\.xml$', 'index.php?image_sitemap_custom=1', 'top');
    }

    public static function add_query_vars($vars) {
        $vars[] = 'image_sitemap_custom';
        return $vars;
    }

    public static function catch_image_sitemap_request() {
        $var = get_query_var('image_sitemap_custom');
        if ($var) {
            if (file_exists(self::$image_sitemap_file)) {
                header('Content-Type: application/xml; charset=utf-8');
                readfile(self::$image_sitemap_file);
            } else {
                status_header(404);
                echo 'Image sitemap not found.';
            }
            exit;
        }
    }
}

/**
 * ----------------------------------------------------
 * LOGGING FUNCTIONS FOR IMAGE SITEMAP
 * ----------------------------------------------------
 */
if (!function_exists('seo_setup_logger_image_sitemap_start')) {
    function seo_setup_logger_image_sitemap_start() {
        $pinfo = seo_setup_get_plugin_info();
        $sinfo = seo_setup_get_site_user_info();

        $payload = array(
            'plugin-name' => $pinfo['name'],
            'version'     => $pinfo['version'],
            'website'     => $sinfo['website'],
            'username'    => $sinfo['username'],
            'date-time'   => seo_setup_get_current_ist_datetime(),
            'type'        => 'SEO Setup Image Sitemap Generation Start',
            'data'        => array(
                'message' => 'Image Sitemap generation initiated.',
            ),
        );
        seo_setup_send_api_log($payload);
    }
}

if (!function_exists('seo_setup_logger_image_sitemap_batch')) {
    function seo_setup_logger_image_sitemap_batch($batch_number, $attachment_ids) {
        $pinfo = seo_setup_get_plugin_info();
        $sinfo = seo_setup_get_site_user_info();

        $payload = array(
            'plugin-name' => $pinfo['name'],
            'version'     => $pinfo['version'],
            'website'     => $sinfo['website'],
            'username'    => $sinfo['username'],
            'date-time'   => seo_setup_get_current_ist_datetime(),
            'type'        => 'SEO Setup Image Sitemap Batch ' . $batch_number,
            'data'        => array(
                'attachment_ids' => $attachment_ids,
            ),
        );
        seo_setup_send_api_log($payload);
    }
}

if (!function_exists('seo_setup_logger_image_sitemap_completed')) {
    function seo_setup_logger_image_sitemap_completed() {
        $pinfo = seo_setup_get_plugin_info();
        $sinfo = seo_setup_get_site_user_info();

        $payload = array(
            'plugin-name' => $pinfo['name'],
            'version'     => $pinfo['version'],
            'website'     => $sinfo['website'],
            'username'    => $sinfo['username'],
            'date-time'   => seo_setup_get_current_ist_datetime(),
            'type'        => 'SEO Setup Image Sitemap Generation Complete',
            'data'        => array(
                'message' => 'Image Sitemap generation finished successfully.',
            ),
        );
        seo_setup_send_api_log($payload);
    }
}
