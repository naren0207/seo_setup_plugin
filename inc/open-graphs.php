<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Get Open Graph data for a post (title, description, url, image) using Yoast SEO meta fields.
 * For the social meta fields (Open Graph and Twitter), if the Yoast SEO meta title or meta description
 * is missing, fallback values are used.
 */
function seo_setup_get_open_graph_data($post_id) {
    // Get Yoast SEO meta title and meta description.
    $yoast_title    = get_post_meta($post_id, '_yoast_wpseo_title', true);
    $yoast_metadesc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);

    // Fallback: if the Yoast meta title is empty, use the fallback string for social title.
    if (empty($yoast_title)) {
        $yoast_title = "%%title%% %%page%% %%sep%% %%sitename%%";
    }
    // Fallback: if the Yoast meta description is empty, use the fallback string for social description.
    if (empty($yoast_metadesc)) {
        $yoast_metadesc = "%%excerpt%%";
    }

    $url = get_permalink($post_id);

    // IMAGE FALLBACK LOGIC:
    if (has_post_thumbnail($post_id)) {
        // Primary: use the featured image.
        $image = get_the_post_thumbnail_url($post_id, 'full');
    } else {
        // Fallback 1: Look for the first <img> in the post content.
        preg_match('/<img[^>]+src="([^"]+)"/', get_post_field('post_content', $post_id), $matches);
        $image = isset($matches[1]) ? esc_url($matches[1]) : '';
        if (empty($image)) {
            // Fallback 2: Get a random image from the media library.
            $random_image = get_posts([
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'orderby'        => 'rand'
            ]);
            $image = !empty($random_image) ? wp_get_attachment_url($random_image[0]->ID) : '';
        }
    }

    return [
        'title'       => esc_attr($yoast_title),
        'description' => esc_attr($yoast_metadesc),
        'url'         => esc_url($url),
        'image'       => esc_url($image),
    ];
}

/**
 * Bulk update Open Graph and Twitter meta fields in Yoast SEO.
 */
function seo_setup_update_open_graphs() {
    check_ajax_referer( 'seo_setup_nonce', '_ajax_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized access' ), 403 );
        return;
    }

    if ( ! defined( 'WPSEO_VERSION' ) ) {
        wp_send_json_error( array( 'message' => 'Yoast SEO plugin is not active.' ), 400 );
        return;
    }

    $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
    $limit  = isset( $_POST['limit'] ) ? max( 1, absint( $_POST['limit'] ) ) : SEO_SETUP_BATCH_SIZE;

    if ( 0 === $offset && function_exists( 'seo_setup_logger_open_graphs_audit_start' ) ) {
        seo_setup_logger_open_graphs_audit_start();
    }

    $query = new WP_Query( array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => false,
    ) );

    $errors        = array();
    $updated_posts = array();
    $total_count   = (int) $query->found_posts;

    foreach ( $query->posts as $post ) {
        $og_data = seo_setup_get_open_graph_data( $post->ID );

        if ( false === $og_data ) {
            $errors[] = array(
                'post_id' => $post->ID,
                'url'     => get_permalink( $post->ID ),
            );
            continue;
        }

        update_post_meta( $post->ID, '_yoast_wpseo_opengraph-title', $og_data['title'] );
        update_post_meta( $post->ID, '_yoast_wpseo_opengraph-description', $og_data['description'] );
        update_post_meta( $post->ID, '_yoast_wpseo_opengraph-image', $og_data['image'] );
        update_post_meta( $post->ID, '_yoast_wpseo_twitter-title', $og_data['title'] );
        update_post_meta( $post->ID, '_yoast_wpseo_twitter-description', $og_data['description'] );
        update_post_meta( $post->ID, '_yoast_wpseo_twitter-image', $og_data['image'] );

        $updated_posts[] = array(
            'title' => get_the_title( $post->ID ),
            'url'   => $og_data['url'],
            'image' => $og_data['image'],
        );
    }

    wp_reset_postdata();

    if ( ! empty( $errors ) && function_exists( 'seo_setup_logger_open_graphs_identified_issues' ) ) {
        seo_setup_logger_open_graphs_identified_issues( array(
            'failed_posts' => $errors,
        ) );
    }

    if ( ! empty( $updated_posts ) && function_exists( 'seo_setup_logger_open_graphs_fixed_issues' ) ) {
        seo_setup_logger_open_graphs_fixed_issues( array(
            'updated_posts' => $updated_posts,
        ) );
    }

    $next_offset = $offset + $limit;

    wp_send_json_success( array(
        'updated_posts'   => $updated_posts,
        'failed_posts'    => $errors,
        'processed_count' => count( $query->posts ),
        'offset'          => $offset,
        'limit'           => $limit,
        'next_offset'     => $next_offset,
        'total_count'     => $total_count,
        'has_more'        => $next_offset < $total_count,
    ) );
}
add_action('wp_ajax_update_open_graphs', 'seo_setup_update_open_graphs');