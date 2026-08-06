<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create a custom table for storing redirections (runs on plugin activation).
 */
function seo_setup_create_redirect_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_redirects';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        old_url TEXT NOT NULL,
        new_url TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
// FIXED: register_activation_hook(__FILE__) won't work because this file is
// included from the main plugin file. The activation hook must be registered
// in the main plugin file (seo-setup.php). For now, we'll create the table
// on admin_init if it doesn't exist yet as a safe fallback.
add_action( 'admin_init', function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_redirects';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
        seo_setup_create_redirect_table();
    }
});


/**
 * Save old slugs into the custom table instead of wp_options.
 */
function seo_setup_save_redirections($old_slug, $new_slug) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_redirects';

    $old_url = home_url('/' . $old_slug . '/');
    $new_url = home_url('/' . $new_slug . '/');

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE old_url = %s",
        $old_url
    ));
    if (!$exists) {
        $wpdb->insert(
            $table_name,
            array(
                'old_url' => $old_url,
                'new_url' => $new_url
            ),
            array('%s','%s')
        );
        // FIXED: Clear the redirect cache so the new redirect takes effect immediately
        delete_transient( 'seo_setup_redirects_cache' );
    }
    seo_setup_save_redirections_in_options($old_url, $new_url);
}

// store the old->new URL mapping in a WP option as a fallback
function seo_setup_save_redirections_in_options($old_url, $new_url) {
    $stored_redirects = get_option('seo_setup_redirects_option', array());
    if (!is_array($stored_redirects)) {
        $stored_redirects = array();
    }
    if (!isset($stored_redirects[$old_url])) {
        $stored_redirects[$old_url] = $new_url;
        update_option('seo_setup_redirects_option', $stored_redirects);
    }
}

/**
 * AJAX handler to update Yoast SEO Slugs for all published pages
 */
function seo_setup_update_page_names() {

    check_ajax_referer( 'seo_setup_nonce', 'nonce' );

    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to edit pages.', 'seo-setup' ) ) );
    }

    $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
    $limit  = isset( $_POST['limit'] ) ? max( 1, absint( $_POST['limit'] ) ) : SEO_SETUP_BATCH_SIZE;

    $all_post_types = get_post_types( array( 'public' => true ), 'names' );
    $excluded_post_types = array(
        'elementor_library',
        'wp_block',
        'attachment',
        'fl-builder-template',
        'avada_portfolio',
        'avada_faq',
        'fusion_template',
    );
    $post_types = array_values( array_diff( $all_post_types, $excluded_post_types ) );

    $query = new WP_Query( array(
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => false,
    ) );

    $total_count = (int) $query->found_posts;

    if ( 0 === $total_count ) {
        wp_send_json_error( array(
            'message' => 'No published content found.',
        ) );
    }

    $updated_items = array();
    $debug_log     = array();

    foreach ( $query->posts as $item ) {
        $page_id   = $item->ID;
        $old_title = $item->post_title;
        $old_yoast = get_post_meta( $page_id, '_yoast_wpseo_slug', true );
        $wp_slug   = $item->post_name;

        $sanitized_title = sanitize_title( $old_title );
        if ( $sanitized_title === $wp_slug && $old_yoast === $sanitized_title ) {
            continue;
        }

        $new_slug = seo_setup_generate_unique_slug( $old_title, $page_id );

        $debug_log[] = "[SEO Setup] Checking Post ID: $page_id, Title: '$old_title', Old Yoast Slug: '$old_yoast', WP Slug: '$wp_slug', Proposed Slug: '$new_slug'";

        $update_needed  = false;
        $incorrect_slug = '';

        if ( empty( $old_yoast ) || $new_slug !== $old_yoast ) {
            update_post_meta( $page_id, '_yoast_wpseo_slug', $new_slug );
            $debug_log[]    = "[SEO Setup] Updated Yoast Slug for Post ID $page_id: Old: '$old_yoast' => New: '$new_slug'";
            $update_needed  = true;
            $incorrect_slug = $old_yoast ?: '(none)';
        }

        if ( $new_slug !== $wp_slug ) {
            $post_update = wp_update_post( array(
                'ID'        => $page_id,
                'post_name' => $new_slug,
            ), true );

            if ( is_wp_error( $post_update ) ) {
                $debug_log[] = '[SEO Setup] Failed to update WP Slug for Post ID ' . $page_id . ': ' . $post_update->get_error_message();
            } else {
                $debug_log[] = "[SEO Setup] Successfully updated WP Slug for Post ID $page_id to '$new_slug'";
            }

            $update_needed  = true;
            $incorrect_slug = empty( $incorrect_slug ) ? $wp_slug : "$incorrect_slug | $wp_slug";

            seo_setup_save_redirections( $wp_slug, $new_slug );
            seo_setup_handle_subpages( $page_id, $wp_slug, $new_slug );
        }

        if ( $update_needed ) {
            $updated_items[] = array(
                'page_id'    => $page_id,
                'page_title' => $old_title,
                'old_slug'   => $incorrect_slug,
                'new_slug'   => $new_slug,
            );
            wp_cache_delete( $page_id, 'post_meta' );
            clean_post_cache( $page_id );
        }
    }

    wp_reset_postdata();

    $next_offset = $offset + $limit;
    $has_more    = $next_offset < $total_count;

    if ( ! $has_more ) {
        seo_setup_create_mu_plugin_file();

        if ( class_exists( 'Plugin_Installer' ) ) {
            Plugin_Installer::ensure_redirect_plugin_active();
        }
    }

    wp_send_json_success( array(
        'message'         => empty( $updated_items ) ? 'No items needed updating in this batch.' : 'Batch processed.',
        'updated_count'   => count( $updated_items ),
        'updated_items'   => $updated_items,
        'debug_log'       => $debug_log,
        'processed_count' => count( $query->posts ),
        'offset'          => $offset,
        'limit'           => $limit,
        'next_offset'     => $next_offset,
        'total_count'     => $total_count,
        'has_more'        => $has_more,
    ) );
}
add_action('wp_ajax_seo_setup_update_page_names', 'seo_setup_update_page_names');

/**
 * Handle subpages when parent slug changes
 */
function seo_setup_handle_subpages($page_id, $old_parent_slug, $new_parent_slug) {
    // In WP, subpages have post_parent = parent's ID
    $children = get_pages(array('child_of' => $page_id));
    if (!$children) {
        return;
    }
    foreach ($children as $child) {
        $child_id = $child->ID;
        $child_wp_slug = $child->post_name;
        $old_child_url = home_url('/'.$old_parent_slug.'/'.$child_wp_slug.'/');
        $new_child_url = home_url('/'.$new_parent_slug.'/'.$child_wp_slug.'/');
        seo_setup_add_redirect($old_child_url, $new_child_url);
    }
}

/**
 * Add a redirect to the custom table
 */
function seo_setup_add_redirect($old_url, $new_url) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_redirects';
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE old_url = %s",
        $old_url
    ));
    if (!$exists) {
        $wpdb->insert(
            $table_name,
            array(
                'old_url' => $old_url,
                'new_url' => $new_url
            ),
            array('%s','%s')
        );
        // FIXED: Clear the redirect cache
        delete_transient( 'seo_setup_redirects_cache' );
    }
    seo_setup_save_redirections_in_options($old_url, $new_url);
}

/**
 * Redirect from old URLs
 */
function seo_setup_redirect_old_urls() {
    if (is_admin()) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_redirects';

    $current_uri = strtok($_SERVER['REQUEST_URI'], '?');
    $home_url    = home_url();
    $home_path   = parse_url($home_url, PHP_URL_PATH);

    if (!empty($home_path) && $home_path !== '/') {
        if (strpos($current_uri, $home_path) === 0) {
            $current_uri = substr($current_uri, strlen($home_path));
        }
    }
    $current_uri = trim($current_uri, '/');
    $normalized_current_slug = sanitize_title($current_uri);

    // Skip empty slugs (homepage)
    if ( empty( $normalized_current_slug ) ) {
        return;
    }

    // FIXED: Use a transient cache to avoid querying ALL redirects on every page load
    $redirects = get_transient( 'seo_setup_redirects_cache' );
    if ( false === $redirects ) {
        $redirects = $wpdb->get_results("SELECT old_url, new_url FROM $table_name", ARRAY_A);
        if ( ! $redirects ) {
            $redirects = array();
        }
        set_transient( 'seo_setup_redirects_cache', $redirects, HOUR_IN_SECONDS );
    }

    if ($redirects) {
        foreach ($redirects as $redirect) {
            $old_path = parse_url($redirect['old_url'], PHP_URL_PATH);
            if (!empty($home_path) && $home_path !== '/') {
                if (strpos($old_path, $home_path) === 0) {
                    $old_path = substr($old_path, strlen($home_path));
                }
            }
            $old_path = trim($old_path, '/');
            $normalized_old_slug = sanitize_title($old_path);

            if ($normalized_old_slug === $normalized_current_slug) {
                wp_redirect($redirect['new_url'], 301);
                exit;
            }
        }
    }
}
add_action('template_redirect', 'seo_setup_redirect_old_urls');

/**
 * Create MU Plugin file for permanent 301 redirections
 */
function seo_setup_create_mu_plugin_file() {
    if (!defined('WPMU_PLUGIN_DIR')) {
        return;
    }

    // FIXED: Create mu-plugins directory if it doesn't exist, but don't crash if we can't
    if (!is_dir(WPMU_PLUGIN_DIR)) {
        if ( ! @wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
            error_log( '[SEO Setup] Cannot create mu-plugins directory: ' . WPMU_PLUGIN_DIR );
            return;
        }
    }

    // Check writability before attempting to write
    if ( ! is_writable( WPMU_PLUGIN_DIR ) ) {
        error_log( '[SEO Setup] mu-plugins directory is not writable: ' . WPMU_PLUGIN_DIR );
        return;
    }

    $mu_plugin_file = WPMU_PLUGIN_DIR . '/seo_setup_redirects.php';
    if (file_exists($mu_plugin_file)) {
        return;
    }

    $mu_plugin_content = <<<MU
<?php
/**
 * Plugin Name: SEO Setup Permanent Redirects
 * Description: Handles permanent 301 redirections from old slugs to new slugs.
 * Author: SEO Setup Plugin
 * Version: 1.1
 */

if (!is_admin()) {
    add_action('template_redirect', function() {
        global \$wpdb;
        \$table_name = \$wpdb->prefix . 'seo_setup_redirects';

        \$current_uri = strtok(\$_SERVER['REQUEST_URI'], '?');
        \$home_url    = home_url();
        \$home_path   = parse_url(\$home_url, PHP_URL_PATH);

        if (!empty(\$home_path) && \$home_path !== '/') {
            if (strpos(\$current_uri, \$home_path) === 0) {
                \$current_uri = substr(\$current_uri, strlen(\$home_path));
            }
        }
        \$current_uri = trim(\$current_uri, '/');
        if (empty(\$current_uri)) return;

        \$normalized_current_slug = sanitize_title(\$current_uri);

        // Use transient cache to avoid DB query on every page load
        \$redirects = get_transient('seo_setup_redirects_cache');
        if (false === \$redirects) {
            // Check if table exists first
            if (\$wpdb->get_var(\$wpdb->prepare("SHOW TABLES LIKE %s", \$table_name)) !== \$table_name) {
                return;
            }
            \$redirects = \$wpdb->get_results("SELECT old_url, new_url FROM \$table_name", ARRAY_A);
            if (!\$redirects) \$redirects = array();
            set_transient('seo_setup_redirects_cache', \$redirects, HOUR_IN_SECONDS);
        }

        if (\$redirects) {
            foreach (\$redirects as \$redirect) {
                \$old_path = parse_url(\$redirect['old_url'], PHP_URL_PATH);
                if (!empty(\$home_path) && \$home_path !== '/') {
                    if (strpos(\$old_path, \$home_path) === 0) {
                        \$old_path = substr(\$old_path, strlen(\$home_path));
                    }
                }
                \$old_path = trim(\$old_path, '/');
                \$normalized_old_slug = sanitize_title(\$old_path);

                if (\$normalized_old_slug === \$normalized_current_slug) {
                    wp_redirect(\$redirect['new_url'], 301);
                    exit;
                }
            }
        }
    });
}
MU;

    $result = @file_put_contents($mu_plugin_file, $mu_plugin_content);
    if ( $result === false ) {
        error_log( '[SEO Setup] Failed to write MU plugin file: ' . $mu_plugin_file );
    }
}

/**
 * AJAX: Check single 301 redirect chain
 */
function seo_setup_check_redirect() {
    
    check_ajax_referer('seo_setup_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized.', 'seo-setup')]);
    }
    if (empty($_POST['url'])) {
        wp_send_json_error(['message' => 'No URL provided.']);
    }

    $old_url = esc_url_raw($_POST['url']);
    $chain = array();

    $response1 = wp_remote_request($old_url, ['method' => 'HEAD','redirection' => 0]);
    if (is_wp_error($response1)) {
        wp_send_json_error(['message' => $response1->get_error_message()]);
    }
    $status1  = wp_remote_retrieve_response_code($response1);
    $headers1 = wp_remote_retrieve_headers($response1);

    $chain[] = [
        'url'    => $old_url,
        'status' => $status1,
    ];

    if (in_array($status1, [301,302])) {
        $location = !empty($headers1['location']) ? $headers1['location'] : '';
        if (!empty($location) && strpos($location, 'http') !== 0) {
            $location = home_url($location); // Convert relative to absolute
        }

        if ($location) {
            $response2 = wp_remote_request($location, ['method'=>'HEAD','redirection'=>0]);
            if (!is_wp_error($response2)) {
                $status2 = wp_remote_retrieve_response_code($response2);
                $chain[] = [
                    'url'    => $location,
                    'status' => $status2,
                ];
            } else {
                $chain[] = [
                    'url'     => $location,
                    'status'  => 'Error',
                    'message' => $response2->get_error_message(),
                ];
            }
        }
    }

    wp_send_json_success(['chain' => $chain]);
}
add_action('wp_ajax_seo_setup_check_redirect', 'seo_setup_check_redirect');

/**
 * AJAX: Check all redirects - we will chunk this to avoid timeouts
 */
function seo_setup_check_all_redirects() {

    check_ajax_referer('seo_setup_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized.', 'seo-setup')]);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_redirects';

    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $limit  = isset($_POST['limit']) ? intval($_POST['limit']) : 100;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, old_url FROM $table_name ORDER BY id DESC LIMIT %d, %d",
        $offset, $limit
    ), ARRAY_A);
    if (!$rows) {
        wp_send_json_success(['all_results' => [], 'count' => 0]);
    }

    $final_results = [];
    foreach ($rows as $row) {
        $chain = [];
        $old_url = $row['old_url'];

        $response1 = wp_remote_request($old_url, ['method' => 'HEAD', 'redirection' => 0]);
        if (is_wp_error($response1)) {
            $chain[] = [
                'url'     => $old_url,
                'status'  => 'Error',
                'message' => $response1->get_error_message(),
            ];
        } else {
            $status1  = wp_remote_retrieve_response_code($response1);
            $headers1 = wp_remote_retrieve_headers($response1);
            $chain[]  = [
                'url'    => $old_url,
                'status' => $status1
            ];
            if (in_array($status1, [301, 302], true)) {
                $location = isset($headers1['location']) ? $headers1['location'] : '';
                if (!empty($location) && strpos($location, 'http') !== 0) {
                    $location = home_url($location); // Convert relative to absolute
                }
                if ($location) {
                    $response2 = wp_remote_request($location, ['method' => 'HEAD', 'redirection' => 0]);
                    if (is_wp_error($response2)) {
                        $chain[] = [
                            'url'     => $location,
                            'status'  => 'Error',
                            'message' => $response2->get_error_message(),
                        ];
                    } else {
                        $status2 = wp_remote_retrieve_response_code($response2);
                        $chain[] = [
                            'url'    => $location,
                            'status' => $status2
                        ];
                    }
                }
            }
        }

        $final_results[] = [
            'id'      => $row['id'],
            'old_url' => $old_url,
            'chain'   => $chain
        ];
    }

    wp_send_json_success([
        'all_results' => $final_results,
        'count'       => count($final_results)
    ]);
}
add_action('wp_ajax_seo_setup_check_all_redirects', 'seo_setup_check_all_redirects');

/**
 * Generate a globally unique slug
 */
function seo_setup_generate_unique_slug($desired_slug, $post_id = 0) {
    global $wpdb;
    $slug = sanitize_title($desired_slug);
    if (!$slug) {
        $slug = 'item'; // fallback if title is empty
    }
    $base_slug = $slug;
    $suffix = 2;
    while (seo_setup_slug_exists($slug, $post_id)) {
        $slug = $base_slug . '-' . $suffix;
        $suffix++;
    }
    return $slug;
}

/**
 * Check if slug exists in posts table, postmeta, or attachments
 */
function seo_setup_slug_exists($slug, $post_id = 0) {
    global $wpdb;
    $slug = sanitize_title($slug);

    // Check wp_posts for post_name
    $post_conflict = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND ID != %d LIMIT 1",
        $slug, $post_id
    ));

    // Also check _wp_old_slug
    $meta_conflict = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 1",
        $slug
    ));

    return (!empty($post_conflict) || !empty($meta_conflict));
}

/**
 * Utility to check if a URL is reachable
 */
function seo_setup_url_exists($url) {
    if (empty($url)) return false;
    $response = wp_remote_head($url, array('timeout' => 5, 'redirection' => 1));
    if (is_wp_error($response)) {
        return false;
    }
    $status = wp_remote_retrieve_response_code($response);
    $acceptable = array(200, 301, 302);
    return in_array($status, $acceptable);
}