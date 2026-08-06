<?php
/**
 * rest-api.php
 *
 * Registers all SEO Setup REST API endpoints under /wp-json/seo-setup/v1/
 * This replaces admin-ajax.php to avoid conflicts with other plugins/themes.
 *
 * Each endpoint routes to the existing PHP handler function — no logic is
 * duplicated. The handler functions continue to use wp_send_json_success()
 * and wp_send_json_error() which work fine in REST context.
 *
 * @package SEO_Setup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'seo_setup_register_rest_routes' );

function seo_setup_register_rest_routes() {
    $namespace = 'seo-setup/v1';

    register_rest_route( $namespace, '/auth/nonce', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_refresh_nonce',
        'permission_callback' => '__return_true',
    ) );

    // -----------------------------------------------------------------------
    // Alt Text
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/alt-text/start-audit', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_alt_text_start_audit',
        'permission_callback' => 'seo_setup_rest_permission_upload_files',
    ) );

    register_rest_route( $namespace, '/alt-text/process-batch', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_alt_text_process_batch',
        'permission_callback' => 'seo_setup_rest_permission_upload_files',
    ) );

    register_rest_route( $namespace, '/alt-text/images-without-alt', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_images_without_alt',
        'permission_callback' => 'seo_setup_rest_permission_upload_files',
    ) );

    register_rest_route( $namespace, '/alt-text/images-with-alt', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_images_with_alt',
        'permission_callback' => 'seo_setup_rest_permission_upload_files',
    ) );

    register_rest_route( $namespace, '/alt-text/report-data', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_alt_text_report_data',
        'permission_callback' => 'seo_setup_rest_permission_upload_files',
    ) );

    // -----------------------------------------------------------------------
    // Alt Text Analysis (Gemini-powered audit of EXISTING alt text)
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/alt-text-analysis/start-audit', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_alt_text_analysis_start_audit',
        'permission_callback' => 'seo_setup_rest_permission_upload_files',
    ) );

    register_rest_route( $namespace, '/alt-text-analysis/process-batch', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_alt_text_analysis_process_batch',
        'permission_callback' => 'seo_setup_rest_permission_upload_files',
    ) );

    register_rest_route( $namespace, '/alt-text-analysis/fix', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_alt_text_analysis_fix',
        'permission_callback' => 'seo_setup_rest_permission_upload_files',
    ) );

    register_rest_route( $namespace, '/alt-text-analysis/pending-count', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_alt_text_analysis_pending_count',
        'permission_callback' => 'seo_setup_rest_permission_upload_files',
    ) );

    // -----------------------------------------------------------------------
    // Page Names
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/page-names/update', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_update_page_names',
        'permission_callback' => 'seo_setup_rest_permission_edit_pages',
    ) );

    register_rest_route( $namespace, '/page-names/check-redirect', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_check_redirect',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    register_rest_route( $namespace, '/page-names/check-all-redirects', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_check_all_redirects',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    // -----------------------------------------------------------------------
    // Meta Titles & Descriptions
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/meta/generate', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_generate_meta',
        'permission_callback' => 'seo_setup_rest_permission_edit_posts',
    ) );

    register_rest_route( $namespace, '/meta/save', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_save_meta',
        'permission_callback' => 'seo_setup_rest_permission_edit_posts',
    ) );

    // -----------------------------------------------------------------------
    // Featured Images
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/featured-images/set', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_set_featured_images',
        'permission_callback' => 'seo_setup_rest_permission_edit_posts',
    ) );

    // -----------------------------------------------------------------------
    // Open Graphs
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/open-graphs/update', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_update_open_graphs',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    // -----------------------------------------------------------------------
    // Canonical Tags
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/canonical/generate', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_generate_canonical',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    register_rest_route( $namespace, '/canonical/update', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_update_canonical',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    register_rest_route( $namespace, '/canonical/update-url', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_update_canonical_url',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    register_rest_route( $namespace, '/canonical/refresh', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_refresh_canonical',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    // -----------------------------------------------------------------------
    // XML Sitemap
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/sitemap/generate', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_generate_sitemap',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    // -----------------------------------------------------------------------
    // Image Sitemap
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/image-sitemap/generate', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_generate_image_sitemap',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    // -----------------------------------------------------------------------
    // Robots.txt
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/robots-txt/generate', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_generate_robots_txt',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    // -----------------------------------------------------------------------
    // Schema Markup
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/schema/generate-org', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_generate_org_schema',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    register_rest_route( $namespace, '/schema/generate-local', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_generate_local_schema',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    register_rest_route( $namespace, '/schema/generate-batch', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_generate_schema_batch',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    // -----------------------------------------------------------------------
    // 301 Redirections
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/redirects/auto-map', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_301_redirection',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    register_rest_route( $namespace, '/redirects/save', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_301_redirection_save',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    // -----------------------------------------------------------------------
    // Disable Yoast
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/disable-yoast', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_disable_yoast',
        'permission_callback' => 'seo_setup_rest_permission_manage_options',
    ) );

    // -----------------------------------------------------------------------
    // Geo Coordinates (frontend)
    // -----------------------------------------------------------------------
    register_rest_route( $namespace, '/save-geo-coords', array(
        'methods'             => 'POST',
        'callback'            => 'seo_setup_rest_save_geo_coords',
        'permission_callback' => '__return_true', // Public endpoint (frontend)
    ) );
}

// ============================================================================
// Permission callbacks
// ============================================================================

function seo_setup_rest_refresh_nonce( $request ) {
    $user_id = get_current_user_id();

    if ( ! $user_id ) {
        $schemes = array_unique( array(
            'logged_in',
            is_ssl() ? 'secure_auth' : 'auth',
            'auth',
            'secure_auth',
        ) );

        foreach ( $schemes as $scheme ) {
            $user_id = wp_validate_auth_cookie( '', $scheme );
            if ( $user_id ) {
                wp_set_current_user( $user_id );
                break;
            }
        }
    }

    if ( ! $user_id ) {
        return new WP_Error(
            'seo_setup_rest_not_logged_in',
            __( 'Your WordPress login session has expired. Please sign in again.', 'seo-setup' ),
            array( 'status' => 403 )
        );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return new WP_Error(
            'seo_setup_rest_forbidden',
            __( 'You do not have permission to use SEO Setup.', 'seo-setup' ),
            array( 'status' => 403 )
        );
    }

    return rest_ensure_response( array(
        'nonce' => wp_create_nonce( 'wp_rest' ),
    ) );
}

function seo_setup_rest_permission_manage_options() {
    return current_user_can( 'manage_options' );
}

function seo_setup_rest_permission_edit_posts() {
    return current_user_can( 'edit_posts' );
}

function seo_setup_rest_permission_edit_pages() {
    return current_user_can( 'edit_pages' );
}

function seo_setup_rest_permission_upload_files() {
    return current_user_can( 'upload_files' );
}

// ============================================================================
// REST callback wrappers
// Each sets up $_POST from the REST request body so existing handler
// functions work without modification, then calls the handler.
// ============================================================================

/**
 * Bridge: copies REST JSON body params into $_POST so existing handlers
 * (which read from $_POST) work unchanged.
 *
 * Also injects nonce values into $_POST and $_REQUEST so that existing
 * check_ajax_referer() calls pass. The REST API framework has ALREADY
 * validated the nonce via the X-WP-Nonce header before reaching this point,
 * so this is safe — we're just satisfying the legacy nonce check.
 */
function seo_setup_rest_populate_post( $request ) {
    $params = $request->get_json_params();
    if ( is_array( $params ) ) {
        foreach ( $params as $key => $value ) {
            $_POST[ $key ] = $value;
        }
    }

    // Generate valid nonces so that check_ajax_referer() passes in legacy handlers.
    // This is safe because REST API already authenticated via X-WP-Nonce header
    // and the permission_callback verified user capabilities.
    $seo_nonce = wp_create_nonce( 'seo_setup_nonce' );
    $alt_nonce = wp_create_nonce( 'seo_setup_alt_text_nonce' );

    // Inject into all possible keys that handlers check
    $_POST['nonce']       = $seo_nonce;
    $_POST['_ajax_nonce'] = $seo_nonce;
    $_POST['security']    = $alt_nonce;
    $_REQUEST['nonce']       = $seo_nonce;
    $_REQUEST['_ajax_nonce'] = $seo_nonce;
    $_REQUEST['security']    = $alt_nonce;
}

// --- Alt Text ---

function seo_setup_rest_alt_text_start_audit( $request ) {
    seo_setup_rest_populate_post( $request );
    // The original checks check_ajax_referer — we skip that since REST has its own auth.
    // We need to temporarily bypass the nonce check.
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_generate_alt_text_start_audit();
}

function seo_setup_rest_alt_text_process_batch( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_generate_alt_text_process_batch();
}

function seo_setup_rest_images_without_alt( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_get_images_without_alt();
}

function seo_setup_rest_images_with_alt( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_get_images_with_alt();
}

function seo_setup_rest_alt_text_report_data( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_get_alt_text_report_data();
}

// --- Alt Text Analysis ---

function seo_setup_rest_alt_text_analysis_start_audit( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_alt_text_analysis_start_audit();
}

function seo_setup_rest_alt_text_analysis_process_batch( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_alt_text_analysis_process_batch();
}

function seo_setup_rest_alt_text_analysis_fix( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_alt_text_analysis_fix();
}

function seo_setup_rest_alt_text_analysis_pending_count( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_alt_text_analysis_pending_count();
}

// --- Page Names ---

function seo_setup_rest_update_page_names( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_update_page_names();
}

function seo_setup_rest_check_redirect( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_check_redirect();
}

function seo_setup_rest_check_all_redirects( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_check_all_redirects();
}

// --- Meta ---

function seo_setup_rest_generate_meta( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_ajax_generate_meta();
}

function seo_setup_rest_save_meta( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_ajax_save_meta();
}

// --- Featured Images ---

function seo_setup_rest_set_featured_images( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    afi_ajax_set_featured_images();
}

// --- Open Graphs ---

function seo_setup_rest_update_open_graphs( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_update_open_graphs();
}

// --- Canonical ---

function seo_setup_rest_generate_canonical( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_ajax_generate_canonical();
}

function seo_setup_rest_update_canonical( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_ajax_update_canonical();
}

function seo_setup_rest_update_canonical_url( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_ajax_update_canonical_url();
}

function seo_setup_rest_refresh_canonical( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_ajax_refresh_canonical();
}

// --- XML Sitemap ---

function seo_setup_rest_generate_sitemap( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    SEO_Setup_XML_Sitemap::ajax_generate_sitemap();
}

// --- Image Sitemap ---

function seo_setup_rest_generate_image_sitemap( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    SEO_Setup_Image_Sitemap::ajax_generate_image_sitemap();
}

// --- Robots.txt ---

function seo_setup_rest_generate_robots_txt( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    SEO_Setup_Robots_Txt::seo_setup_generate_robots_txt_callback();
}

// --- Schema ---

function seo_setup_rest_generate_org_schema( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_generate_org_schema();
}

function seo_setup_rest_generate_local_schema( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_generate_local_schema();
}

function seo_setup_rest_generate_schema_batch( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_generate_additional_schema_batch();
}

// --- 301 Redirections ---

function seo_setup_rest_301_redirection( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_301_redirection_ajax();
}

function seo_setup_rest_301_redirection_save( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    seo_setup_301_redirection_save_ajax();
}

// --- Disable Yoast ---

function seo_setup_rest_disable_yoast( $request ) {
    seo_setup_rest_populate_post( $request );
    add_filter( 'wp_doing_ajax', '__return_true' );
    if ( function_exists( 'seo_setup_disable_yoast_callback' ) ) {
        seo_setup_disable_yoast_callback();
    }
}

// --- Geo Coordinates ---

function seo_setup_rest_save_geo_coords( $request ) {
    $lat = $request->get_param( 'lat' );
    $lng = $request->get_param( 'lng' );

    if ( $lat && $lng ) {
        set_transient( 'seo_setup_fallback_lat', sanitize_text_field( $lat ), MONTH_IN_SECONDS );
        set_transient( 'seo_setup_fallback_lng', sanitize_text_field( $lng ), MONTH_IN_SECONDS );
        wp_send_json_success( array( 'message' => 'Coordinates saved.' ) );
    } else {
        wp_send_json_error( array( 'message' => 'Missing lat/lng.' ) );
    }
}
