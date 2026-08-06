<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle XML Sitemap generation with batch-based AJAX to accommodate large websites.
 */

class SEO_Setup_XML_Sitemap {
    private static $batch_size = 500;
    private static $sitemap_file = '';

    public static function init() {
        add_action('wp_ajax_seo_setup_generate_sitemap', [__CLASS__, 'ajax_generate_sitemap']);

        self::$sitemap_file = ABSPATH . 'sitemap.xml';

        // Add rewrite rule to serve sitemap at https://example.com/sitemap.xml
        add_action('init', [__CLASS__, 'add_rewrite_rule']);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        add_action('template_redirect', [__CLASS__, 'catch_sitemap_request']);
    }

    public static function ajax_generate_sitemap() {
        check_ajax_referer('seo_setup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized request.']);
            return; // FIXED: Must return after wp_send_json_error
        }

        $batch = isset($_POST['batch']) ? absint($_POST['batch']) : 0;
        $offset = $batch * self::$batch_size;

        // (A) LOG Start of XML Sitemap generation (only if this is the first batch)
        if ($batch === 0) {
            seo_setup_logger_xml_sitemap_start();
        }

        // On first batch, create or overwrite the file with XML header
        if ($batch === 0) {
            // FIXED: Check directory writability before attempting to write
            if ( ! seo_setup_ensure_directory_writable( ABSPATH ) ) {
                wp_send_json_error(['message' => 'WordPress root directory is not writable. Cannot create sitemap.xml. Please check server permissions.']);
                return;
            }

            $header = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $header .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ' .
                       'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
            if (false === file_put_contents(self::$sitemap_file, $header)) {
                wp_send_json_error(['message' => 'Failed to write sitemap file header.']);
                return;
            }
        }

        // Query published posts
        $args = array(
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => self::$batch_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );
        $query      = new WP_Query($args);
        $post_count = count($query->posts);
        $fh         = null;

        if ($post_count > 0) {
            $fh = fopen(self::$sitemap_file, 'a');
            if (!$fh) {
                wp_send_json_error(['message' => 'Unable to open sitemap file for appending.']);
                return; // FIXED: Must return after wp_send_json_error
            }
        } else {
            // If no posts found in this batch => final close
            file_put_contents(self::$sitemap_file, "</urlset>", FILE_APPEND | LOCK_EX);

            // FIXED: Flush rewrite rules so the sitemap URL becomes accessible
            flush_rewrite_rules( false );

            // (B) LOG completion of the XML Sitemap
            seo_setup_logger_xml_sitemap_completed();

            wp_send_json_success(['done' => true, 'message' => 'Sitemap generation complete.']);
            return; // FIXED: Explicit return after completion
        }

        // We'll store the post IDs for logging
        $batch_post_ids = array();

        foreach ($query->posts as $post) {
            $loc      = get_permalink($post);
            $mod_time = get_post_modified_time('c', true, $post->ID);

            $loc_esc  = esc_url($loc);
            $mod_esc  = esc_html($mod_time);

            $entry  = "\t<url>\n";
            $entry .= "\t\t<loc>{$loc_esc}</loc>\n";
            $entry .= "\t\t<lastmod>{$mod_esc}</lastmod>\n";
            $entry .= "\t\t<changefreq>daily</changefreq>\n";
            if ($loc === home_url('/')) {
                $entry .= "\t\t<priority>1.0</priority>\n";
            } else {
                $entry .= "\t\t<priority>0.8</priority>\n";
            }
            $entry .= "\t</url>\n";

            fwrite($fh, $entry);
            $batch_post_ids[] = $post->ID;
        }

        fclose($fh);

        //     We send the post IDs or any relevant info
        seo_setup_logger_xml_sitemap_batch($batch, $batch_post_ids);

        if ($post_count < self::$batch_size) {
            // Close the urlset
            file_put_contents(self::$sitemap_file, "</urlset>", FILE_APPEND | LOCK_EX);

            // FIXED: Flush rewrite rules so the sitemap URL becomes accessible
            flush_rewrite_rules( false );

            // (B) LOG completion of the XML Sitemap
            seo_setup_logger_xml_sitemap_completed();

            wp_send_json_success(['done' => true, 'message' => 'Sitemap generation complete.']);
        } else {
            wp_send_json_success(['done' => false, 'message' => 'Batch complete.']);
        }
    }

    public static function add_rewrite_rule() {
        add_rewrite_rule('^sitemap\.xml$', 'index.php?sitemap_custom=1', 'top');
    }

    public static function add_query_vars($vars) {
        $vars[] = 'sitemap_custom';
        return $vars;
    }

    public static function catch_sitemap_request() {
        $var = get_query_var('sitemap_custom');
        if ($var) {
            if (file_exists(self::$sitemap_file)) {
                header('Content-Type: application/xml; charset=utf-8');
                readfile(self::$sitemap_file);
            } else {
                status_header(404);
                echo 'Sitemap not found.';
            }
            exit;
        }
    }
}

/**
 * ----------------------------------------------------
 * LOGGING FUNCTIONS FOR XML SITEMAP
 * ----------------------------------------------------
 */
if (!function_exists('seo_setup_logger_xml_sitemap_start')) {
    function seo_setup_logger_xml_sitemap_start() {
        $pinfo = seo_setup_get_plugin_info();
        $sinfo = seo_setup_get_site_user_info();

        // Create the payload
        $payload = array(
            'plugin-name' => $pinfo['name'],
            'version'     => $pinfo['version'],
            'website'     => $sinfo['website'],
            'username'    => $sinfo['username'],
            'date-time'   => seo_setup_get_current_ist_datetime(),
            'type'        => 'SEO Setup XML Sitemap Generation Start',
            'data'        => array(
                'message' => 'XML Sitemap generation initiated.',
            ),
        );

        seo_setup_send_api_log($payload);
    }
}

if (!function_exists('seo_setup_logger_xml_sitemap_batch')) {
    function seo_setup_logger_xml_sitemap_batch($batch_number, $post_ids) {
        $pinfo = seo_setup_get_plugin_info();
        $sinfo = seo_setup_get_site_user_info();

        $payload = array(
            'plugin-name' => $pinfo['name'],
            'version'     => $pinfo['version'],
            'website'     => $sinfo['website'],
            'username'    => $sinfo['username'],
            'date-time'   => seo_setup_get_current_ist_datetime(),
            'type'        => 'SEO Setup XML Sitemap Batch ' . $batch_number,
            'data'        => array(
                'post_ids' => $post_ids,
            ),
        );
        seo_setup_send_api_log($payload);
    }
}

if (!function_exists('seo_setup_logger_xml_sitemap_completed')) {
    function seo_setup_logger_xml_sitemap_completed() {
        $pinfo = seo_setup_get_plugin_info();
        $sinfo = seo_setup_get_site_user_info();

        $payload = array(
            'plugin-name' => $pinfo['name'],
            'version'     => $pinfo['version'],
            'website'     => $sinfo['website'],
            'username'    => $sinfo['username'],
            'date-time'   => seo_setup_get_current_ist_datetime(),
            'type'        => 'SEO Setup XML Sitemap Generation Complete',
            'data'        => array(
                'message' => 'XML Sitemap generation finished successfully.',
            ),
        );
        seo_setup_send_api_log($payload);
    }
}
