<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * 1) Retrieve Plugin Name & Version from the main plugin file headers.
 */
function seo_setup_get_plugin_info() {
    $plugin_file = SEO_SETUP_PLUGIN_DIR . 'seo-setup.php';

    if ( ! function_exists('get_file_data') ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $default_headers = array(
        'Name'    => 'Plugin Name',
        'Version' => 'Version',
    );
    $data = get_file_data( $plugin_file, $default_headers );

    return array(
        'name'    => isset( $data['Name'] )    ? sanitize_text_field( $data['Name'] )    : 'Unknown Plugin',
        'version' => isset( $data['Version'] ) ? sanitize_text_field( $data['Version'] ) : '1.0.0',
    );
}

/**
 * 2) Retrieve the current website URL and admin username.
 */
function seo_setup_get_site_user_info() {
    $site_url = home_url();
    $username = 'admin'; // fallback

    if ( is_user_logged_in() ) {
        $current_user = wp_get_current_user();
        if ( $current_user && ! empty( $current_user->user_login ) ) {
            $username = sanitize_text_field( $current_user->user_login );
        }
    }

    return array(
        'website'  => esc_url( $site_url ),
        'username' => $username,
    );
}

/**
 * 3) Return current date/time in IST (Asia/Kolkata) format: 'Y-m-d H:i:s'
 */
function seo_setup_get_current_ist_datetime() {
    $datetime = new DateTime( 'now', new DateTimeZone('Asia/Kolkata') );
    return $datetime->format('Y-m-d H:i:s');
}

/**
 * 4) Function that sends a payload to the remote API.
 *    Now it also logs the request payload and the API response to debug.log
 */
function seo_setup_send_api_log( $payload = array() ) {
    $credentials = seo_setup_get_api_credentials_by_product( 'v6 LOGS' );
    if ( ! $credentials ) {
        // FIXED: Never call wp_send_json_error() here - this is a utility function
        // called from AJAX handlers, activation hooks, and wp_head contexts.
        // Calling wp_send_json_error() would kill the main operation.
        error_log( '[SEO Setup] Missing API credentials for v6 LOGS - skipping log.' );
        return false;
    }

    $api_url   = $credentials['endpoint'];
    $api_token = $credentials['key'];

    // Prepare args for wp_remote_post
    $args = array(
        'headers'  => array(
            'token'        => $api_token,
            'Content-Type' => 'application/json',
        ),
        'body'     => wp_json_encode( $payload ),
        'timeout'  => 5,       // FIXED: Reduced from 300s to 5s - logging should never block operations
        'blocking' => false,   // FIXED: Non-blocking - fire and forget, don't wait for response
    );

    // Send the request (non-blocking, will not wait for response)
    $response = wp_remote_post( $api_url, $args );

    if ( is_wp_error( $response ) ) {
        error_log( '[SEO Setup] API Log - Request Error: ' . $response->get_error_message() );
        return false;
    }

    return true;
}

/* -------------------------------------------------------------------------
   6) Plugin Activation
   ------------------------------------------------------------------------- */
function seo_setup_logger_plugin_installed() {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Plugin Installation',
        'data'        => new stdClass(),
    );

    seo_setup_send_api_log( $payload );
}

/* -------------------------------------------------------------------------
   7) ALT TEXT
   ------------------------------------------------------------------------- */

function seo_setup_logger_alt_text_audit_start() {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Alt text Audit Start',
        'data'        => new stdClass(),
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 8 ALT TEXT AUDIT ISSUES
 */
function seo_setup_logger_alt_text_audit_issues( $missing_urls = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Alt text Audit Issues',
        'data'        => array(
            'Audit complete. Missing Alt Text' => array_values($missing_urls),
        ),
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 9 ALT TEXT OPEN AI API REQUEST
 */
function seo_setup_logger_alt_text_open_ai_request( $batch_number, $batch_data = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Alt text Open AI API Request Batch ' . $batch_number,
        'data'        => array(
            'Processing batch type' => 'image',
            'Batch data'            => array_values($batch_data),
        ),
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 10 ALT TEXT OPEN AI API RESPONSE
 */
function seo_setup_logger_alt_text_open_ai_response( $batch_number, $detailed_response = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Alt text Open AI API Response Batch ' . $batch_number,
        'data'        => $detailed_response,
    );
    seo_setup_send_api_log( $payload );
}

/* -------------------------------------------------------------------------
   11 PAGE NAMES
   ------------------------------------------------------------------------- */
function seo_setup_logger_page_names_audit_start() {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Page Names Audit Start',
        'data'        => new stdClass(),
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 12 PAGE NAMES IDENTIFIED ISSUES
 */
function seo_setup_logger_page_names_identified_issues( $issues_data = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Page Names Identified Issues',
        'data'        => $issues_data,
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 13 PAGE NAMES FIXED ISSUES
 */
function seo_setup_logger_page_names_fixed_issues( $fixed_data = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Page Names Fixed Issues',
        'data'        => $fixed_data,
    );
    seo_setup_send_api_log( $payload );
}

/* -------------------------------------------------------------------------
   14 TITLE & META DESCRIPTIONS
   ------------------------------------------------------------------------- */
function seo_setup_logger_meta_audit_start() {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Meta Audit Start',
        'data'        => new stdClass(),
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 15 META DETAILS REQUEST (OpenAI request)
 */
function seo_setup_logger_meta_details_request( $request_data = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Meta Details Request',
        'data'        => $request_data,
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 16 META DETAILS RESPONSE (OpenAI response)
 */
function seo_setup_logger_meta_details_response( $response_data = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Meta Details Response',
        'data'        => $response_data,
    );
    seo_setup_send_api_log( $payload );
}

/* -------------------------------------------------------------------------
   17 FEATURED IMAGES
   ------------------------------------------------------------------------- */
function seo_setup_logger_featured_images_audit_start() {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Featured Images Audit Start',
        'data'        => new stdClass(),
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 18 FEATURED IMAGES IDENTIFIED ISSUES
 */
function seo_setup_logger_featured_images_identified_issues( $issues_data = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Featured Images Identified Issues',
        'data'        => $issues_data,
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 19 FEATURED IMAGES ISSUES FIXED
 */
function seo_setup_logger_featured_images_fixed_issues( $fixed_data = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Featured Images Issues Fixed',
        'data'        => $fixed_data,
    );
    seo_setup_send_api_log( $payload );
}

/* -------------------------------------------------------------------------
   20 OPEN GRAPHS
   ------------------------------------------------------------------------- */
function seo_setup_logger_open_graphs_audit_start() {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Open Graphs Audit Start',
        'data'        => new stdClass(),
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 21 OPEN GRAPHS IDENTIFIED ISSUES
 */
function seo_setup_logger_open_graphs_identified_issues( $issues_data = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Open Graphs Identified Issues',
        'data'        => $issues_data,
    );
    seo_setup_send_api_log( $payload );
}

/**
 * 22 OPEN GRAPHS ISSUES FIXED
 */
function seo_setup_logger_open_graphs_fixed_issues( $fixed_data = array() ) {
    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'type'        => 'SEO Setup Open Graphs Issues Fixed',
        'data'        => $fixed_data,
    );
    seo_setup_send_api_log( $payload );
}

// Schema Logs
function seo_setup_logger_generated_schema($schema_type, $page_url, $raw_schema_json){
    
    if (empty ($schema_type) || empty ($page_url) || empty ($raw_schema_json)) {
        return;
    }

    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $decoded_data = json_decode($raw_schema_json, true);

    $payload = array(
        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Generated Schema - ' . $schema_type,
        'data'        => array(
            'schema-type' => $schema_type,
            'page-url'    => $page_url,
            'schema-data' => $decoded_data,
        ),
    );
    
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( '[SEO Setup Schema Log] ' . print_r( $payload, true ) );
    }

    seo_setup_send_api_log( $payload );
}

function seo_setup_logger_address_found( $source, $data ) {
    $message = sprintf(
        '[SEO Setup] Address source: %s | Found: %s',
        $source,
        is_array( $data ) ? implode( ' | ', $data ) : $data
    );
    error_log( $message );
}

// Logs that images already have alt text

function seo_setup_logger_existing_alt_images ($images_data = array()){

    $pinfo = seo_setup_get_plugin_info();
    $sinfo = seo_setup_get_site_user_info();

    $payload = array(

        'plugin-name' => $pinfo['name'],
        'version'     => $pinfo['version'],
        'website'     => $sinfo['website'],
        'username'    => $sinfo['username'],
        'date-time'   => seo_setup_get_current_ist_datetime(),
        'type'        => 'SEO Setup Existing Alt Text Images',
        'data'        => $images_data,
    );

    seo_setup_send_api_log( $payload );
}