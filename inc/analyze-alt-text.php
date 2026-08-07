<?php
/**
 * analyze-alt-text.php
 *
 * "Analyze Image Alt Text" — audits EXISTING alt text using Gemini 2.5 Flash
 * vision (unlike generate-alt-text.php, which only fills in MISSING alt text).
 * For each image, Gemini is shown the image itself plus its current alt text
 * and asked whether the alt text is acceptable (lenient — a broad but
 * accurate description passes; only genuine problems are flagged). If not
 * suitable, Gemini returns an 8-10 word English replacement, or flags the
 * image as purely decorative (alt text gets cleared instead of replaced).
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

// External usage-reporting endpoint (separate from the internal v6 LOGS system).
// Reports token usage as returned by Gemini's own usageMetadata — no cost
// figure is computed on our side, since Gemini's API never returns a dollar
// amount, only token counts.
define( 'SEO_SETUP_IMAGE_ALT_ANALYSIS_LOGS_ENDPOINT', 'https://stagingseo.vsplash.com/api/image-alt-analysis-logs' );

// ============================================================================
// Table creation
// ============================================================================

// Bump this whenever the table schema below changes — the admin_init hook
// re-runs dbDelta (safe/idempotent, only ALTERs what's actually different)
// when the stored version doesn't match, so existing installs get migrated.
define( 'SEO_SETUP_ALT_TEXT_ANALYSIS_TABLE_VERSION', '1.1' );

function seo_setup_create_alt_text_analysis_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'seo_setup_alt_text_analysis';
    $charset_collate = $wpdb->get_charset_collate();

    // FIXED: dbDelta() cannot reliably detect/apply column changes on an
    // existing table when the SQL uses "CREATE TABLE IF NOT EXISTS" — this
    // is a documented dbDelta gotcha, confirmed by testing: the same SQL
    // without "IF NOT EXISTS" correctly ALTERs in the missing column, but
    // with it dbDelta silently does nothing. Plain "CREATE TABLE" is safe
    // either way since dbDelta itself checks existence before deciding
    // whether to CREATE or ALTER.
    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        attachment_id BIGINT UNSIGNED NOT NULL,
        image_url TEXT NOT NULL,
        original_alt_text TEXT NULL,
        last_known_alt_text TEXT NULL,
        is_suitable TINYINT(1) NOT NULL DEFAULT 0,
        is_decorative TINYINT(1) NOT NULL DEFAULT 0,
        suggested_alt_text TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'analyzed',
        analyzed_at DATETIME NOT NULL,
        fixed_at DATETIME NULL,
        UNIQUE KEY attachment_id (attachment_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'seo_setup_alt_text_analysis_table_version', SEO_SETUP_ALT_TEXT_ANALYSIS_TABLE_VERSION );
}
// FIXED (same rationale as the redirects table): register_activation_hook won't
// fire from a file that's require_once'd from the main plugin file, so this is
// also created/migrated as a safe admin_init fallback in addition to the
// activation call in seo-setup.php.
add_action( 'admin_init', function() {
    if ( get_option( 'seo_setup_alt_text_analysis_table_version' ) !== SEO_SETUP_ALT_TEXT_ANALYSIS_TABLE_VERSION ) {
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
 * Ask Gemini whether $current_alt is acceptable for the image at $img_url,
 * and get a replacement if not (or a decorative flag if the image carries
 * no informational content).
 *
 * @return array|false  ['is_suitable' => bool, 'is_decorative' => bool, 'suggested_alt_text' => string, 'prompt_tokens' => int, 'candidate_tokens' => int] or false on failure.
 */
function seo_setup_analyze_image_with_gemini( $img_url, $current_alt, $credentials ) {
    $api_url = $credentials['endpoint'];
    $api_key = $credentials['key'];

    $prompt = "Evaluate whether this image's existing HTML alt text is acceptable, not whether a better, more detailed, or more polished description could be written. Preserve valid alt text.\n\n"
        . 'Current alt text: "' . $current_alt . "\"\n\n"
        . "Set is_suitable to false only when the existing text is clearly unrelated to the image, factually incorrect or materially misleading, placeholder/test content, a meaningless filename or ID, keyword-stuffed or spam-like, or so generic that it does not identify the main subject. A broad but accurate description is suitable. Missing secondary objects, colors, weather, background details, or additional specificity does not make it unsuitable. When uncertain, set is_suitable to true.\n\n"
        . "Before setting is_suitable to false, verify that this statement can be completed with a factual problem: \"The current alt text is materially wrong because ...\". If it cannot, set is_suitable to true.\n\n"
        . "If suitable, return an empty suggested_alt_text. If clearly unsuitable, provide a replacement written in English only, using 8 to 10 words (never fewer than 8), within 125 characters.\n\n"
        . "If the image is purely decorative (carries no informational content — e.g. a spacer, divider, or background texture), set is_decorative to true, is_suitable to false, and return an empty suggested_alt_text.\n\n"
        . "Examples:\n"
        . "- Image: terraced rice fields, trees, mist, and a hut. Current alt text: \"Lush green terraced hills and trees\". Result: is_suitable=true, because it is broad but factually accurate and the omitted details are secondary.\n"
        . "- Image: a man taking a selfie on an airplane. Current alt text: \"Abc Testing web\". Result: is_suitable=false, because it is placeholder text unrelated to the image.";

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
                    'is_suitable'        => array(
                        'type'        => 'BOOLEAN',
                        'description' => 'True if the existing alt text is an acceptable, factually accurate description of the image, even if broad or lacking secondary detail. False only for a genuine, nameable factual problem.',
                    ),
                    'is_decorative'      => array(
                        'type'        => 'BOOLEAN',
                        'description' => 'True only if the image is purely decorative and carries no informational content (e.g. spacer, divider, background texture). False for any image with a real subject.',
                    ),
                    'suggested_alt_text' => array(
                        'type'        => 'STRING',
                        'description' => 'Empty string if is_suitable is true or is_decorative is true. Otherwise a complete, grammatical replacement of 8 to 10 words (never fewer than 8), within 125 characters, English only, describing this exact image — never cut off mid-phrase.',
                    ),
                ),
                'required'   => array( 'is_suitable', 'is_decorative', 'suggested_alt_text' ),
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

    // Real usage as reported by Gemini itself — used for the external usage report.
    $prompt_tokens    = isset( $json['usageMetadata']['promptTokenCount'] ) ? (int) $json['usageMetadata']['promptTokenCount'] : 0;
    $candidate_tokens = isset( $json['usageMetadata']['candidatesTokenCount'] ) ? (int) $json['usageMetadata']['candidatesTokenCount'] : 0;

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

    // Never hard-truncate — cutting a suggestion off mid-phrase (e.g.
    // "...under blue" missing "sky") is worse than a slightly-over-target
    // length. The prompt already targets 8-10 words / 125 characters; this
    // is just a log-only backstop against a genuinely runaway response.
    if ( ! empty( $suggested ) && ( strlen( $suggested ) > 200 || str_word_count( $suggested ) > 20 ) ) {
        error_log( '[SEO Setup] Gemini alt text analysis: suggestion far exceeds target length, using as-is: ' . $suggested );
    }

    return array(
        'is_suitable'        => (bool) $parsed['is_suitable'],
        'is_decorative'      => ! empty( $parsed['is_decorative'] ),
        'suggested_alt_text' => $suggested,
        'prompt_tokens'      => $prompt_tokens,
        'candidate_tokens'   => $candidate_tokens,
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

    $suitable_count      = 0;
    $unsuitable_count    = 0;
    $unsuitable_items    = array();
    $analyzed_items      = array();
    $batch_prompt_tokens = 0;
    $batch_candidate_tokens = 0;

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

        $batch_prompt_tokens    += $verdict['prompt_tokens'];
        $batch_candidate_tokens += $verdict['candidate_tokens'];

        $is_suitable        = $verdict['is_suitable'];
        $is_decorative      = $verdict['is_decorative'];
        $suggested_alt_text = $is_suitable ? '' : $verdict['suggested_alt_text'];

        // Full per-image request/response detail, used only for the external
        // usage report — kept separate from $unsuitable_items (UI table data).
        $analyzed_items[] = array(
            'attachment_id'      => $attachment_id,
            'image_url'          => $image_url,
            'current_alt_text'   => $current_alt,
            'is_suitable'        => $is_suitable,
            'is_decorative'      => $is_decorative,
            'suggested_alt_text' => $suggested_alt_text,
            'prompt_tokens'      => $verdict['prompt_tokens'],
            'candidate_tokens'   => $verdict['candidate_tokens'],
        );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table_name
                (attachment_id, image_url, original_alt_text, last_known_alt_text, is_suitable, is_decorative, suggested_alt_text, status, analyzed_at, fixed_at)
             VALUES (%d, %s, %s, %s, %d, %d, %s, 'analyzed', %s, NULL)
             ON DUPLICATE KEY UPDATE
                image_url = VALUES(image_url),
                last_known_alt_text = VALUES(last_known_alt_text),
                is_suitable = VALUES(is_suitable),
                is_decorative = VALUES(is_decorative),
                suggested_alt_text = VALUES(suggested_alt_text),
                status = 'analyzed',
                analyzed_at = VALUES(analyzed_at),
                fixed_at = NULL",
            $attachment_id,
            $image_url,
            $current_alt,
            $current_alt,
            $is_suitable ? 1 : 0,
            $is_decorative ? 1 : 0,
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
                'is_decorative'      => $is_decorative,
            );
        }
    }

    if ( function_exists( 'seo_setup_logger_alt_text_analysis_batch' ) ) {
        seo_setup_logger_alt_text_analysis_batch( $suitable_count, $unsuitable_count, $unsuitable_items );
    }

    wp_send_json_success( array(
        'suitable_count'      => $suitable_count,
        'unsuitable_count'    => $unsuitable_count,
        'unsuitable_items'    => $unsuitable_items,
        'analyzed_items'      => $analyzed_items,
        'prompt_tokens'       => $batch_prompt_tokens,
        'candidate_tokens'    => $batch_candidate_tokens,
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
        "SELECT id, attachment_id, suggested_alt_text, is_decorative FROM $table_name
         WHERE status = 'analyzed' AND is_suitable = 0
         ORDER BY id ASC LIMIT %d",
        $limit
    ) );

    $fixed_items = array();

    foreach ( $pending as $row ) {
        if ( $row->is_decorative ) {
            // Decorative images should carry empty alt text so screen readers
            // skip them — that IS the fix here, distinct from "nothing to apply".
            // Title is left untouched; it's not part of what "decorative" means.
            update_post_meta( $row->attachment_id, '_wp_attachment_image_alt', '' );

            $wpdb->update(
                $table_name,
                array(
                    'status'              => 'fixed',
                    'last_known_alt_text' => '',
                    'fixed_at'            => current_time( 'mysql' ),
                ),
                array( 'id' => $row->id )
            );

            $fixed_items[] = array(
                'attachment_id' => $row->attachment_id,
                'alt_text'      => '',
                'decorative'    => true,
            );
            continue;
        }

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
            'decorative'    => false,
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

/**
 * Sends one combined usage report for this browser session's activity to the
 * external image-alt-analysis-logs endpoint. Called by the client once per
 * session: right after Fix finishes draining the pending backlog, or
 * immediately after Analyze if it found nothing that needed fixing.
 *
 * Token counts are exactly what Gemini itself reported via usageMetadata —
 * no cost figure is computed or estimated on our side.
 */
function seo_setup_alt_text_analysis_send_report() {
    check_ajax_referer( 'seo_setup_alt_text_nonce', 'security' );

    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }

    $images_scanned    = isset( $_POST['images_scanned'] ) ? absint( $_POST['images_scanned'] ) : 0;
    $issues_identified = isset( $_POST['issues_identified'] ) ? absint( $_POST['issues_identified'] ) : 0;
    $images_fixed      = isset( $_POST['images_fixed'] ) ? absint( $_POST['images_fixed'] ) : 0;
    $prompt_tokens     = isset( $_POST['prompt_tokens'] ) ? absint( $_POST['prompt_tokens'] ) : 0;
    $candidate_tokens  = isset( $_POST['candidate_tokens'] ) ? absint( $_POST['candidate_tokens'] ) : 0;

    // Full per-image request/response detail for this session's Analyze run —
    // everything Gemini was sent and returned, sanitized field-by-field (this
    // arrives as a raw JSON-decoded array via the REST bridge, not $_POST
    // superglobal magic-quoted input, so no wp_unslash() is needed here).
    $raw_items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? $_POST['items'] : array();
    $items     = array();
    foreach ( $raw_items as $raw_item ) {
        $items[] = array(
            'attachment_id'      => isset( $raw_item['attachment_id'] ) ? absint( $raw_item['attachment_id'] ) : 0,
            'image_url'          => isset( $raw_item['image_url'] ) ? esc_url_raw( $raw_item['image_url'] ) : '',
            'current_alt_text'   => isset( $raw_item['current_alt_text'] ) ? sanitize_text_field( $raw_item['current_alt_text'] ) : '',
            'is_suitable'        => ! empty( $raw_item['is_suitable'] ),
            'is_decorative'      => ! empty( $raw_item['is_decorative'] ),
            'suggested_alt_text' => isset( $raw_item['suggested_alt_text'] ) ? sanitize_text_field( $raw_item['suggested_alt_text'] ) : '',
            'fixed'              => ! empty( $raw_item['fixed'] ),
            'prompt_tokens'      => isset( $raw_item['prompt_tokens'] ) ? absint( $raw_item['prompt_tokens'] ) : 0,
            'candidate_tokens'   => isset( $raw_item['candidate_tokens'] ) ? absint( $raw_item['candidate_tokens'] ) : 0,
        );
    }

    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'type'        => 'Image Analyzation From Alt Text Plugin',
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'data'        => array(
            'images_scanned'                => $images_scanned,
            'issues_identified'              => $issues_identified,
            'images_fixed'                   => $images_fixed,
            'request_tokens_total'           => $prompt_tokens,
            'response_tokens_total'          => $candidate_tokens,
            'avg_request_tokens_per_image'   => $images_scanned > 0 ? round( $prompt_tokens / $images_scanned, 2 ) : 0,
            'avg_response_tokens_per_image'  => $images_scanned > 0 ? round( $candidate_tokens / $images_scanned, 2 ) : 0,
            'items'                          => $items,
        ),
    );

    // Fire-and-forget, same rationale as seo_setup_send_api_log(): reporting
    // should never block or fail the actual Analyze/Fix UX.
    $response = wp_remote_post( SEO_SETUP_IMAGE_ALT_ANALYSIS_LOGS_ENDPOINT, array(
        'headers'  => array( 'Content-Type' => 'application/json' ),
        'body'     => wp_json_encode( $payload ),
        'timeout'  => 5,
        'blocking' => false,
    ) );

    if ( is_wp_error( $response ) ) {
        error_log( '[SEO Setup] Image alt analysis report send error: ' . $response->get_error_message() );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_seo_setup_alt_text_analysis_send_report', 'seo_setup_alt_text_analysis_send_report' );

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
