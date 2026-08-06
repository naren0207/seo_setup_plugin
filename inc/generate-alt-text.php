<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function seo_setup_alt_text_log( $message ) {
    $option_key = 'seo_setup_alt_text_logs';
    $existing_logs = get_option( $option_key, array() );

    $timestamp = date( 'Y-m-d H:i:s' );
    $new_entry = sprintf( '[%s] %s', $timestamp, $message );
    $existing_logs[] = $new_entry;

    if ( count( $existing_logs ) > 500 ) {
        $existing_logs = array_slice( $existing_logs, -500 ); // Keep last 500 logs only
    }

    update_option( $option_key, $existing_logs );
}

function seo_setup_generate_alt_text_start_audit() {
    check_ajax_referer('seo_setup_alt_text_nonce', 'security');
    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }

    // Use options table to clear previous results
    $option_key = 'seo_setup_alt_text_results_' . get_current_user_id();
    delete_option($option_key);

    if ( function_exists('seo_setup_logger_alt_text_audit_start') ) {
        seo_setup_logger_alt_text_audit_start();
    }

    $media_items = get_posts( array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
    ) );

    $missing_alt_text = array();

    foreach ( $media_items as $media ) {
        $attachment_id = $media->ID;
        $alt_text      = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

        if ( empty( $alt_text ) ) {
            $img_url = wp_get_attachment_url( $attachment_id );
            if ( $img_url ) {
                $missing_alt_text[] = $img_url;
            }
        }
    }

    seo_setup_alt_text_log( 'Audit complete. Missing Alt Text: ' . json_encode( $missing_alt_text ) );

    if ( ! empty( $missing_alt_text ) ) {
        if ( function_exists('seo_setup_logger_alt_text_audit_issues') ) {
            seo_setup_logger_alt_text_audit_issues( $missing_alt_text );
        }

        wp_send_json_success( array(
            'issues' => $missing_alt_text,
            'status' => 'found',
        ) );
    } else {
        wp_send_json_success( array(
            'status' => 'all_included',
        ) );
    }
}
add_action('wp_ajax_seo_setup_generate_alt_text_start_audit', 'seo_setup_generate_alt_text_start_audit');

function seo_setup_generate_alt_text_process_batch() {
    check_ajax_referer('seo_setup_alt_text_nonce', 'security');
    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }

    if ( ! isset( $_POST['batch_data'] ) || ! is_array( $_POST['batch_data'] ) ) {
        wp_send_json_error( array( 'message' => 'Invalid batch data.' ) );
    }

    $batch_data = $_POST['batch_data'];
    $batch_number = 1;

    if ( function_exists('seo_setup_logger_alt_text_open_ai_request') ) {
        $log_request = array_map(function($item) {
            return $item['original_url'] ?? '';
        }, $batch_data);
        seo_setup_logger_alt_text_open_ai_request( $batch_number, $log_request );
    }

    $credentials = seo_setup_get_api_credentials_by_product('OPEN AI');

    if ( ! $credentials || empty($credentials['endpoint']) || empty($credentials['key']) ) {
        wp_send_json_error( array( 'message' => 'Missing OpenAI credentials.' ) );
    }

    $api_url = $credentials['endpoint'];
    $api_key = $credentials['key'];
    $processed_count = 0;
    $start_time = microtime(true);
    $detailed_response = array();

    // Array that will store results for CSV
    $batch_results_for_csv = array();

    foreach ( $batch_data as $item ) {
        $img_url  = $item['original_url'] ?? '';
        $base64   = $item['image_base64'] ?? '';

        if ( empty($img_url) || empty($base64) ) {
            seo_setup_alt_text_log("Skipping empty image: $img_url");
            continue;
        }

        $alt_text = seo_setup_generate_openai_alt_text($img_url, $api_url, $api_key);

        $detailed_response[] = array(
            'timestamp' => current_time('mysql'),
            'image_url' => $img_url,
            'response'  => $alt_text ?: 'failed',
        );

        seo_setup_alt_text_log(json_encode(end($detailed_response)));

        if ( $alt_text ) {
            $image_id = attachment_url_to_postid($img_url);
            if ( $image_id ) {
                update_post_meta($image_id, '_wp_attachment_image_alt', $alt_text);
                wp_update_post(array(
                    'ID' => $image_id,
                    'post_title' => $alt_text
                ));
                $processed_count++;

                $batch_results_for_csv[] = array(
                    'image_url' => $img_url,
                    'alt_text'  => $alt_text,
                );
            }
        }
    }

    // Save results for the csv to the options table for persistence
    if (!empty($batch_results_for_csv)){
        $option_key = 'seo_setup_alt_text_results_' . get_current_user_id();
        $existing_results = get_option($option_key);
        
        if ( ! is_array($existing_results) ) {
            $existing_results = array();
        }
        $merged_results = array_merge($existing_results, $batch_results_for_csv);
        update_option($option_key, $merged_results);
    }

    if ( function_exists('seo_setup_logger_alt_text_open_ai_response') ) {
        seo_setup_logger_alt_text_open_ai_response( $batch_number, $detailed_response );
    }

    $time_taken = microtime(true) - $start_time;

    wp_send_json_success(array(
        'processed_images' => $processed_count,
        'time_taken'       => round($time_taken, 2),
    ));
}
add_action( 'wp_ajax_seo_setup_generate_alt_text_process_batch', 'seo_setup_generate_alt_text_process_batch' );

function seo_setup_generate_openai_alt_text($img_url, $api_url, $api_key) {
    
    if ( ! filter_var($img_url, FILTER_VALIDATE_URL) ) return false;

    $prompt = "Please generate a short alt tag of this image in no more than 8 words. The alt tag shall be in English only.";
    $retry = 2;

    while ( $retry-- > 0 ) {
        $mime_type = wp_check_filetype($img_url)['type'];

        if ( $mime_type === 'image/svg+xml' ) {
            $openai_body = array(
                'model' => 'gpt-5-mini',
                'messages' => array(
                    array('role' => 'system', 'content' => 'You are an expert in generating SEO-optimized, descriptive alt text for images.'),
                    array('role' => 'user', 'content' => "We have an SVG image at $img_url.\n\n$prompt"),
                ),
            );
        } else {
            $image_response = wp_remote_get($img_url);
            if ( is_wp_error($image_response) || empty($image_response['body']) ) return false;

            $tmp_path = wp_tempnam($img_url);
            if ( ! $tmp_path ) return false;

            file_put_contents($tmp_path, $image_response['body']);
            $mime = mime_content_type($tmp_path);

            $create_func = array(
                'image/jpeg' => 'imagecreatefromjpeg',
                'image/png'  => 'imagecreatefrompng',
                'image/webp' => 'imagecreatefromwebp',
            );

            if ( empty($create_func[$mime]) || ! function_exists($create_func[$mime]) ) {
                @unlink($tmp_path);
                return false;
            }

            $img = @$create_func[$mime]($tmp_path);
            @unlink($tmp_path);
            if ( ! $img ) return false;

            $width = imagesx($img);
            $height = imagesy($img);
            $scale = min(512 / $width, 512 / $height, 1);

            $new_w = (int)($width * $scale);
            $new_h = (int)($height * $scale);

            $resized = imagecreatetruecolor($new_w, $new_h);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_w, $new_h, $width, $height);

            ob_start();
            imagejpeg($resized, null, 85);
            $base64 = base64_encode(ob_get_clean());

            imagedestroy($img);
            imagedestroy($resized);

            $openai_body = array(
                'model' => 'gpt-5-mini',
                'messages' => array(
                    array('role' => 'system', 'content' => 'You are an expert in generating SEO-optimized, descriptive alt text for images.'),
                    array('role' => 'user', 'content' => array(
                        array('type' => 'text', 'text' => $prompt),
                        array('type' => 'image_url', 'image_url' => array('url' => 'data:' . $mime . ';base64,' . $base64)),
                    )),
                ),
            );
        }

        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($openai_body),
            'timeout' => 60,
        ));

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ( ! is_wp_error($response) && isset($body['choices'][0]['message']['content']) ) {
            $alt = trim(sanitize_text_field($body['choices'][0]['message']['content']), '"');
            return ( stripos($alt, 'unable to view') === false && ! empty($alt) ) ? $alt : false;
        }
    }
    return false;
}

function seo_setup_alt_text_cleanup_on_deactivation() {
    
    delete_option( 'seo_setup_alt_text_logs' );
    if ( get_current_user_id() ) {
        // Use options table for deactivation cleanup
        delete_option( 'seo_setup_alt_text_results_' . get_current_user_id() );
    }

}
register_deactivation_hook( __FILE__, 'seo_setup_alt_text_cleanup_on_deactivation' );

function seo_setup_alt_text_uninstall() {
    delete_option( 'seo_setup_alt_text_logs' );
    if ( get_current_user_id() ) {
        // Use options table for uninstall cleanup
        delete_option( 'seo_setup_alt_text_results_' . get_current_user_id() );
    }
}
register_uninstall_hook( __FILE__, 'seo_setup_alt_text_uninstall' );

// Get Image URls without alt text
function seo_setup_get_images_without_alt() {
    check_ajax_referer('seo_setup_alt_text_nonce', 'security');
    if ( ! current_user_can('upload_files') ) {
        wp_send_json_error(array('message' => 'Unauthorized.'));
    }

    $media_items = get_posts(array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
    ));

    $missing_alt_text = array();

    foreach ($media_items as $media) {
        $attachment_id = $media->ID;
        $alt_text      = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

        if (empty($alt_text)) {
            $img_url = wp_get_attachment_url($attachment_id);
            if ($img_url) {
                $missing_alt_text[] = array(
                    'id'  => $attachment_id,
                    'url' => $img_url
                );
            }
        }
    }

    if (!empty($missing_alt_text)) {
        wp_send_json_success($missing_alt_text);
    } else {
        wp_send_json_success(array());
    }
}
add_action('wp_ajax_seo_setup_get_images_without_alt', 'seo_setup_get_images_without_alt');

// Images that already have alt text

function seo_setup_get_images_with_alt() {
    check_ajax_referer('seo_setup_alt_text_nonce', 'security');

    if ( ! current_user_can('upload_files') ) {
        wp_send_json_error(array('message' => 'Unauthorized.'));
    }

    $media_items = get_posts(array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
    ));

    $updated_images = array();

    foreach ( $media_items as $media ) {
        $attachment_id = $media->ID;
        $alt_text      = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

        if ( ! empty( $alt_text ) ) {

            $current_title = get_post_field( 'post_title', $attachment_id );
            if ( $current_title !== $alt_text ) {

                wp_update_post(array(
                    'ID'         => $attachment_id,
                    'post_title' => $alt_text,
                ));

                $updated_images[] = array(
                    'id'    => $attachment_id,
                    'url'   => wp_get_attachment_url( $attachment_id ),
                    'alt'   => $alt_text,
                    'title' => $alt_text,
                );
            }
        }
    }

    if ( function_exists('seo_setup_logger_existing_alt_images') ) {
        seo_setup_logger_existing_alt_images( $updated_images );
    }

    wp_send_json_success( $updated_images );
}
add_action('wp_ajax_seo_setup_get_images_with_alt', 'seo_setup_get_images_with_alt');

// Download csv

function seo_setup_get_alt_text_report_data() {
    check_ajax_referer('seo_setup_alt_text_nonce', 'security');
    if ( ! current_user_can('upload_files') ) {
        wp_send_json_error(array('message' => 'Unauthorized.'));
    }

    // Use options table to retrieve results
    $option_key = 'seo_setup_alt_text_results_' . get_current_user_id();
    $results = get_option($option_key);
    
    wp_send_json_success($results ?: array());
}
add_action('wp_ajax_seo_setup_get_alt_text_report_data', 'seo_setup_get_alt_text_report_data');
