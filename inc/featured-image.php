<?php
/**
 * featured-image.php
 *
 * Implements the "Set Featured Images" functionality via AJAX.
 * Retrieves all published posts and pages that do not have a featured image set,
 * sets the featured image using the first image in the content, a default logo,
 * or a random image from the media library, and returns a JSON response with a list
 * of pages (page name and complete URL) for which the featured image was set.
 *
 * @package SEO_Setup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function afi_get_random_media_image() {
    $args = array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => 1,
        'orderby'        => 'rand',
    );
    $random_image = get_posts( $args );
    return $random_image ? $random_image[0]->ID : false;
}

function afi_find_attachment_id_by_url( $image_url ) {
    // Direct lookup
    $id = attachment_url_to_postid( $image_url );
    if ( $id ) {
        return $id;
    }

    // Strip size suffix (e.g. image-300x200.jpg → image.jpg) and retry
    $clean_url = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $image_url );
    if ( $clean_url !== $image_url ) {
        $id = attachment_url_to_postid( $clean_url );
        if ( $id ) {
            return $id;
        }
    }

    return 0;
}

function afi_auto_set_featured_image( $post_id ) {
    if ((get_post_type($post_id) == 'post' || get_post_type($post_id) == 'page') && !has_post_thumbnail($post_id)) {
        $post = get_post($post_id);
        $content = $post->post_content;
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches)) {
            $image_url = $matches[1];

            // Check if this image already exists in the media library
            $existing_id = afi_find_attachment_id_by_url( $image_url );
            if ( $existing_id ) {
                set_post_thumbnail( $post_id, $existing_id );
                return true;
            }

            // Image is not in the media library — only sideload if truly external
            if ( function_exists( 'seo_setup_can_use_uploads' ) && ! seo_setup_can_use_uploads() ) {
                error_log( '[SEO Setup] Cannot sideload image - uploads directory not writable.' );
            } else {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attachment_id = media_sideload_image($image_url, $post_id, null, 'id');
                if (is_int($attachment_id)) {
                    set_post_thumbnail($post_id, $attachment_id);
                    return true;
                }
            }
        }
        $default_logo_id = get_option('afi_default_logo_id');
        if ($default_logo_id) {
            set_post_thumbnail($post_id, $default_logo_id);
            return true;
        }
        $random_image_id = afi_get_random_media_image();
        if ($random_image_id) {
            set_post_thumbnail($post_id, $random_image_id);
            return true;
        }
    }
    return false;
}

function afi_ajax_set_featured_images() {
    check_ajax_referer( 'seo_setup_nonce', '_ajax_nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
        return;
    }

    $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
    $limit  = isset( $_POST['limit'] ) ? max( 1, absint( $_POST['limit'] ) ) : SEO_SETUP_BATCH_SIZE;

    if ( 0 === $offset && function_exists( 'seo_setup_logger_featured_images_audit_start' ) ) {
        seo_setup_logger_featured_images_audit_start();
    }

    $query = new WP_Query( array(
        'post_type'      => array( 'post', 'page' ),
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => false,
    ) );

    $updated_pages    = array();
    $identified_pages = array();
    $total_count      = (int) $query->found_posts;

    foreach ( $query->posts as $post ) {
        if ( has_post_thumbnail( $post->ID ) ) {
            continue;
        }

        $identified_pages[] = array(
            'page_id'   => $post->ID,
            'page_name' => $post->post_title,
            'page_url'  => get_permalink( $post->ID ),
        );

        if ( afi_auto_set_featured_image( $post->ID ) ) {
            $updated_pages[] = array(
                'page_id'            => $post->ID,
                'page_name'          => $post->post_title,
                'page_url'           => get_permalink( $post->ID ),
                'featured_image_url' => get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
            );
        }
    }

    wp_reset_postdata();

    if ( ! empty( $identified_pages ) && function_exists( 'seo_setup_logger_featured_images_identified_issues' ) ) {
        seo_setup_logger_featured_images_identified_issues( array(
            'missing_featured_images' => $identified_pages,
        ) );
    }

    if ( ! empty( $updated_pages ) && function_exists( 'seo_setup_logger_featured_images_fixed_issues' ) ) {
        seo_setup_logger_featured_images_fixed_issues( array(
            'fixed_pages' => $updated_pages,
        ) );
    }

    $next_offset = $offset + $limit;

    wp_send_json_success( array(
        'data'            => $updated_pages,
        'processed_count' => count( $query->posts ),
        'updated_count'   => count( $updated_pages ),
        'offset'          => $offset,
        'limit'           => $limit,
        'next_offset'     => $next_offset,
        'total_count'     => $total_count,
        'has_more'        => $next_offset < $total_count,
    ) );
}
add_action( 'wp_ajax_afi_set_featured_images', 'afi_ajax_set_featured_images' );