<?php
/**
 * error-log.php
 *
 * Provides a custom error logging system that writes plugin-specific errors
 * to a dedicated log file within the plugin folder.
 *
 * FIXED: Removed set_error_handler() and set_exception_handler() which were
 * overriding PHP's global error handlers for the ENTIRE WordPress installation.
 * This caused issues where errors from other plugins/themes were being intercepted,
 * and could mask or alter error reporting behavior site-wide.
 * 
 * Now only uses register_shutdown_function() to catch fatal errors,
 * and provides seo_setup_error_log() for explicit logging within the plugin.
 *
 * @package SEO_Setup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logs a message to the plugin's error log file.
 *
 * @param string $message The message to log.
 */
function seo_setup_error_log( $message ) {
    $log_file = SEO_SETUP_PLUGIN_DIR . 'inc/error-log.txt';
    $timestamp = wp_date( 'Y-m-d H:i:s' ); // FIXED: Use wp_date() instead of date()
    $log_entry = '[' . $timestamp . '] ' . $message . PHP_EOL;
    @file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
}

/**
 * Shutdown function to capture fatal errors originating from this plugin only.
 */
function seo_setup_shutdown_handler() {
    $error = error_get_last();
    if ( $error !== null && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
        // FIXED: Only log if the fatal error is from our plugin files
        if ( defined( 'SEO_SETUP_PLUGIN_DIR' ) && strpos( $error['file'], SEO_SETUP_PLUGIN_DIR ) !== false ) {
            $message = "Fatal Error [{$error['type']}] in {$error['file']} at line {$error['line']}: {$error['message']}";
            seo_setup_error_log( $message );
        }
    }
}

// FIXED: Only register the shutdown handler (safe, doesn't override anything)
// Removed set_error_handler() and set_exception_handler() which were hijacking
// ALL PHP errors/exceptions site-wide, potentially breaking other plugins
register_shutdown_function( 'seo_setup_shutdown_handler' );
