<?php
/**
 * analyze-alt-text.php
 *
 * "Analyze Image Alt Text" — audits EXISTING alt text using Gemini 2.5 Flash
 * vision (unlike generate-alt-text.php, which only fills in MISSING alt text).
 * For each image, Gemini is shown the image itself plus its current alt text
 * and asked whether the alt text is fully suitable; if not, Gemini returns an
 * 8-word English replacement.
 *
 * Results are tracked per-attachment in a custom table so that re-running the
 * audit on a later visit (e.g. the same site coming back next month) skips
 * every image already analyzed or fixed — unless its live alt text has since
 * drifted from what we last recorded (someone edited it outside the plugin).
 *
 * @package SEO_Setup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// TEMP: hardcoded Gemini API key for local testing.
// Delete this constant once Gemini credentials are provisioned on the
// partner API (partners.v6live.com) under the product name 'GEMINI' —
// seo_setup_get_gemini_credentials() will automatically fall back to
// seo_setup_get_api_credentials_by_product( 'GEMINI' ) once it's gone.
// ============================================================================
if ( ! defined( 'SEO_SETUP_GEMINI_API_KEY' ) ) {
    define( 'SEO_SETUP_GEMINI_API_KEY', '' ); // <-- put your Gemini API key here for testing
}

define( 'SEO_SETUP_GEMINI_MODEL', 'gemini-2.5-flash' );
define( 'SEO_SETUP_GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/' . SEO_SETUP_GEMINI_MODEL . ':generateContent' );

// ============================================================================
// Table creation
// ============================================================================

function seo_setup_create_alt_text_analysis_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_alt_text_analysis';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        attachment_id BIGINT UNSIGNED NOT NULL,
        image_url TEXT NOT NULL,
        original_alt_text TEXT NULL,
        last_known_alt_text TEXT NULL,
        is_suitable TINYINT(1) NOT NULL DEFAULT 0,
        suggested_alt_text TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'analyzed',
        analyzed_at DATETIME NOT NULL,
        fixed_at DATETIME NULL,
        UNIQUE KEY attachment_id (attachment_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
// FIXED (same rationale as the redirects table): register_activation_hook won't
// fire from a file that's require_once'd from the main plugin file, so this is
// also created as a safe admin_init fallback in addition to the activation call
// in seo-setup.php.
add_action( 'admin_init', function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_alt_text_analysis';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
        seo_setup_create_alt_text_analysis_table();
    }
} );

// ============================================================================
// Credentials
// ============================================================================

function seo_setup_get_gemini_credentials() {
    if ( defined( 'SEO_SETUP_GEMINI_API_KEY' ) && ! empty( SEO_SETUP_GEMINI_API_KEY ) ) {
        return array(
            'endpoint' => SEO_SETUP_GEMINI_ENDPOINT,
            'key'      => SEO_SETUP_GEMINI_API_KEY,
        );
    }

    if ( function_exists( 'seo_setup_get_api_credentials_by_product' ) ) {
        $credentials = seo_setup_get_api_credentials_by_product( 'GEMINI' );
        if ( $credentials ) {
            return $credentials;
        }
    }

    return false;
}

// ============================================================================
// Eligibility — which images should be (re)sent to Gemini
// ============================================================================

/**
 * An image is eligible if it has never been analyzed, or if its live alt text
 * no longer matches what we last recorded (last_known_alt_text) — covering
 * both "analyzed as suitable, then someone changed it" and "we fixed it, then
 * someone changed it again" cases uniformly.
 */
function seo_setup_get_eligible_images_for_analysis() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_alt_text_analysis';

    $media_items = get_posts( array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
    ) );

    if ( empty( $media_items ) ) {
        return array();
    }

    $tracked_rows = $wpdb->get_results( "SELECT attachment_id, last_known_alt_text FROM $table_name", OBJECT_K );

    $eligible = array();

    foreach ( $media_items as $media ) {
        $attachment_id = $media->ID;
        $current_alt   = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

        $tracked = isset( $tracked_rows[ $attachment_id ] ) ? $tracked_rows[ $attachment_id ] : null;

        if ( $tracked && (string) $tracked->last_known_alt_text === (string) $current_alt ) {
            continue; // Already analyzed/fixed and nothing has changed since.
        }

        $img_url = wp_get_attachment_url( $attachment_id );
        if ( ! $img_url ) {
            continue;
        }

        $eligible[] = array(
            'attachment_id' => $attachment_id,
            'image_url'     => $img_url,
            'current_alt'   => $current_alt,
        );
    }

    return $eligible;
}

// ============================================================================
// Gemini call
// ============================================================================

/**
 * Ask Gemini whether $current_alt is a suitable alt text for the image at
 * $img_url, and get an 8-word replacement if not.
 *
 * @return array|false  ['is_suitable' => bool, 'suggested_alt_text' => string] or false on failure.
 */
function seo_setup_analyze_image_with_gemini( $img_url, $current_alt, $credentials ) {
    $api_url = $credentials['endpoint'];
    $api_key = $credentials['key'];

    $prompt = "You are an accessibility and SEO expert. Judge whether the CURRENT ALT TEXT below is fully suitable for the attached image.\n\n"
        . 'Current alt text: "' . $current_alt . "\"\n\n"
        . "The alt text is suitable ONLY if ALL of the following are true:\n"
        . "1. It accurately and specifically describes what is actually shown in this exact image.\n"
        . "2. It is concise — no more than 8 words.\n"
        . "3. It is written in natural English only.\n"
        . "4. It does not start with filler like \"image of\" or \"picture of\".\n"
        . "5. It is not empty, generic, a filename, or keyword-stuffed.\n\n"
        . "If ALL conditions are met, set is_suitable to true and suggested_alt_text to an empty string.\n"
        . "If ANY condition fails, set is_suitable to false and suggested_alt_text to a new, accurate alt text for this exact image — no more than 8 words, English only, no filler words like \"image of\".";

    $parts     = array( array( 'text' => $prompt ) );
    $mime_type = wp_check_filetype( $img_url )['type'];

    if ( 'image/svg+xml' === $mime_type ) {
        // GD can't rasterize SVG for inline vision input — describe the situation in text instead.
        $parts[0]['text'] = "This image is an SVG at $img_url and cannot be attached directly.\n\n" . $prompt;
    } else {
        $image_data = seo_setup_fetch_and_resize_image_base64( $img_url );
        if ( ! $image_data ) {
            return false;
        }
        $parts[] = array(
            'inline_data' => array(
                'mime_type' => $image_data['mime'],
                'data'      => $image_data['base64'],
            ),
        );
    }

    $request_data = array(
        'contents'         => array(
            array( 'parts' => $parts ),
        ),
        'generationConfig' => array(
            'responseMimeType' => 'application/json',
            'responseSchema'   => array(
                'type'       => 'OBJECT',
                'properties' => array(
                    'is_suitable'        => array( 'type' => 'BOOLEAN' ),
                    'suggested_alt_text' => array( 'type' => 'STRING' ),
                ),
                'required'   => array( 'is_suitable', 'suggested_alt_text' ),
            ),
        ),
    );

    $response = wp_remote_post( $api_url, array(
        'headers' => array(
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
        ),
        'body'    => wp_json_encode( $request_data ),
        'timeout' => 60,
    ) );

    if ( is_wp_error( $response ) ) {
        error_log( '[SEO Setup] Gemini alt text analysis request error: ' . $response->get_error_message() );
        return false;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body        = wp_remote_retrieve_body( $response );

    if ( 200 !== $status_code ) {
        error_log( '[SEO Setup] Gemini alt text analysis non-200 response (' . $status_code . '): ' . $body );
        return false;
    }

    $json = json_decode( $body, true );
    $text = isset( $json['candidates'][0]['content']['parts'][0]['text'] ) ? $json['candidates'][0]['content']['parts'][0]['text'] : '';

    if ( empty( $text ) ) {
        error_log( '[SEO Setup] Gemini alt text analysis: empty response text.' );
        return false;
    }

    $parsed = json_decode( $text, true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $parsed['is_suitable'] ) ) {
        error_log( '[SEO Setup] Gemini alt text analysis: could not parse structured response: ' . $text );
        return false;
    }

    $suggested = isset( $parsed['suggested_alt_text'] ) ? sanitize_text_field( trim( $parsed['suggested_alt_text'] ) ) : '';

    // Server-side guard on the word cap, in case the model overshoots.
    if ( ! empty( $suggested ) ) {
        $words = preg_split( '/\s+/', $suggested );
        if ( count( $words ) > 8 ) {
            $suggested = implode( ' ', array_slice( $words, 0, 8 ) );
        }
    }

    return array(
        'is_suitable'        => (bool) $parsed['is_suitable'],
        'suggested_alt_text' => $suggested,
    );
}

// ============================================================================
// AJAX handlers
// ============================================================================

function seo_setup_alt_text_analysis_start_audit() {
    check_ajax_referer( 'seo_setup_alt_text_nonce', 'security' );

    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }

    if ( function_exists( 'seo_setup_logger_alt_text_analysis_start' ) ) {
        seo_setup_logger_alt_text_analysis_start();
    }

    $eligible = seo_setup_get_eligible_images_for_analysis();

    wp_send_json_success( array(
        'items' => $eligible,
        'total' => count( $eligible ),
    ) );
}
add_action( 'wp_ajax_seo_setup_alt_text_analysis_start_audit', 'seo_setup_alt_text_analysis_start_audit' );

function seo_setup_alt_text_analysis_process_batch() {
    @set_time_limit( 300 );

    check_ajax_referer( 'seo_setup_alt_text_nonce', 'security' );

    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }

    if ( ! isset( $_POST['batch_data'] ) || ! is_array( $_POST['batch_data'] ) ) {
        wp_send_json_error( array( 'message' => 'Invalid batch data.' ) );
    }

    $credentials = seo_setup_get_gemini_credentials();
    if ( ! $credentials || empty( $credentials['key'] ) ) {
        wp_send_json_error( array( 'message' => 'Missing Gemini API credentials.' ) );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_alt_text_analysis';

    $suitable_count   = 0;
    $unsuitable_count = 0;
    $unsuitable_items = array();

    foreach ( $_POST['batch_data'] as $item ) {
        $attachment_id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
        $image_url     = isset( $item['image_url'] ) ? esc_url_raw( $item['image_url'] ) : '';
        $current_alt   = isset( $item['current_alt'] ) ? sanitize_text_field( $item['current_alt'] ) : '';

        if ( ! $attachment_id || ! $image_url ) {
            continue;
        }

        $verdict = seo_setup_analyze_image_with_gemini( $image_url, $current_alt, $credentials );
        if ( false === $verdict ) {
            // Leave untracked so this image is retried on the next run.
            continue;
        }

        $is_suitable        = $verdict['is_suitable'];
        $suggested_alt_text = $is_suitable ? '' : $verdict['suggested_alt_text'];

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table_name
                (attachment_id, image_url, original_alt_text, last_known_alt_text, is_suitable, suggested_alt_text, status, analyzed_at, fixed_at)
             VALUES (%d, %s, %s, %s, %d, %s, 'analyzed', %s, NULL)
             ON DUPLICATE KEY UPDATE
                image_url = VALUES(image_url),
                last_known_alt_text = VALUES(last_known_alt_text),
                is_suitable = VALUES(is_suitable),
                suggested_alt_text = VALUES(suggested_alt_text),
                status = 'analyzed',
                analyzed_at = VALUES(analyzed_at),
                fixed_at = NULL",
            $attachment_id,
            $image_url,
            $current_alt,
            $current_alt,
            $is_suitable ? 1 : 0,
            $suggested_alt_text,
            current_time( 'mysql' )
        ) );

        if ( $is_suitable ) {
            $suitable_count++;
        } else {
            $unsuitable_count++;
            $unsuitable_items[] = array(
                'attachment_id'      => $attachment_id,
                'image_url'          => $image_url,
                'current_alt'        => $current_alt,
                'suggested_alt_text' => $suggested_alt_text,
            );
        }
    }

    if ( function_exists( 'seo_setup_logger_alt_text_analysis_batch' ) ) {
        seo_setup_logger_alt_text_analysis_batch( $suitable_count, $unsuitable_count, $unsuitable_items );
    }

    wp_send_json_success( array(
        'suitable_count'   => $suitable_count,
        'unsuitable_count' => $unsuitable_count,
        'unsuitable_items' => $unsuitable_items,
    ) );
}
add_action( 'wp_ajax_seo_setup_alt_text_analysis_process_batch', 'seo_setup_alt_text_analysis_process_batch' );

/**
 * Applies every still-pending unsuitable record (from this run or any past
 * run) to the image's alt text + title, one batch at a time. Always queries
 * from the top of the remaining pending set — rows leave that set as soon as
 * they're marked 'fixed', so no offset bookkeeping is needed or safe here.
 */
function seo_setup_alt_text_analysis_fix() {
    check_ajax_referer( 'seo_setup_alt_text_nonce', 'security' );

    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_alt_text_analysis';

    $limit = isset( $_POST['limit'] ) ? max( 1, absint( $_POST['limit'] ) ) : SEO_SETUP_BATCH_SIZE;

    $pending = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, attachment_id, suggested_alt_text FROM $table_name
         WHERE status = 'analyzed' AND is_suitable = 0
         ORDER BY id ASC LIMIT %d",
        $limit
    ) );

    $fixed_items = array();

    foreach ( $pending as $row ) {
        if ( empty( $row->suggested_alt_text ) ) {
            // Nothing usable to apply — mark resolved anyway so it doesn't loop forever.
            $wpdb->update(
                $table_name,
                array( 'status' => 'fixed', 'fixed_at' => current_time( 'mysql' ) ),
                array( 'id' => $row->id )
            );
            continue;
        }

        update_post_meta( $row->attachment_id, '_wp_attachment_image_alt', $row->suggested_alt_text );
        wp_update_post( array(
            'ID'         => $row->attachment_id,
            'post_title' => $row->suggested_alt_text,
        ) );

        $wpdb->update(
            $table_name,
            array(
                'status'              => 'fixed',
                'last_known_alt_text' => $row->suggested_alt_text,
                'fixed_at'            => current_time( 'mysql' ),
            ),
            array( 'id' => $row->id )
        );

        $fixed_items[] = array(
            'attachment_id' => $row->attachment_id,
            'alt_text'      => $row->suggested_alt_text,
        );
    }

    $remaining_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE status = 'analyzed' AND is_suitable = 0"
    );

    if ( ! empty( $fixed_items ) && function_exists( 'seo_setup_logger_alt_text_analysis_fixed' ) ) {
        seo_setup_logger_alt_text_analysis_fixed( $fixed_items );
    }

    wp_send_json_success( array(
        'fixed_items'     => $fixed_items,
        'fixed_count'     => count( $fixed_items ),
        'remaining_count' => $remaining_count,
        'has_more'        => $remaining_count > 0,
    ) );
}
add_action( 'wp_ajax_seo_setup_alt_text_analysis_fix', 'seo_setup_alt_text_analysis_fix' );

function seo_setup_alt_text_analysis_pending_count() {
    check_ajax_referer( 'seo_setup_alt_text_nonce', 'security' );

    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_alt_text_analysis';

    $pending_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE status = 'analyzed' AND is_suitable = 0"
    );

    wp_send_json_success( array( 'pending_count' => $pending_count ) );
}
add_action( 'wp_ajax_seo_setup_alt_text_analysis_pending_count', 'seo_setup_alt_text_analysis_pending_count' );

// ============================================================================
// Logging (v6 LOGS API — same convention as inc/api-logger.php)
// ============================================================================

function seo_setup_logger_alt_text_analysis_start() {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    seo_setup_send_api_log( array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Alt Text Analysis Start',
        'data'        => new stdClass(),
    ) );
}

function seo_setup_logger_alt_text_analysis_batch( $suitable_count, $unsuitable_count, $unsuitable_items ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    seo_setup_send_api_log( array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Alt Text Analysis Batch',
        'data'        => array(
            'suitable_count'   => $suitable_count,
            'unsuitable_count' => $unsuitable_count,
            'unsuitable_items' => $unsuitable_items,
        ),
    ) );
}

function seo_setup_logger_alt_text_analysis_fixed( $fixed_items ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    seo_setup_send_api_log( array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Alt Text Analysis Fixed',
        'data'        => array(
            'fixed_items' => $fixed_items,
        ),
    ) );
}
