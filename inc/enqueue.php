<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function seo_setup_enqueue_assets( $hook_suffix ) {
    // FIXED: Only load ALL assets on our plugin page to prevent conflicts with other plugins
    if ( $hook_suffix !== 'toplevel_page_seo-setup' ) {
        return;
    }

    wp_enqueue_style(
        'seo-setup-style',
        plugins_url( 'assets/css/style.css', __DIR__ )
    );

    wp_enqueue_style(
        'jquery-ui-css',
        'https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css',
        array(),
        '1.13.2'
    );

    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-widget');
    wp_enqueue_script('jquery-ui-progressbar');

    wp_enqueue_script(
        'seo-setup-script',
        plugins_url( 'assets/js/script.js', __DIR__ ),
        array( 'jquery', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-progressbar' ),
        '1.0.8',
        true
    );

    wp_enqueue_script(
        'seo-setup-generate-alt-text',
        plugins_url( 'assets/js/generate-alt-text.js', __DIR__ ),
        array( 'jquery', 'seo-setup-script' ),
        '1.0.8',
        true
    );

    // Reuses SEOSetupAltTextAjax (localized below) and the seoSetupAltTextAjax()/
    // seoSetupAltTextRestHeaders() helpers defined in generate-alt-text.js.
    wp_enqueue_script(
        'seo-setup-analyze-alt-text',
        plugins_url( 'assets/js/analyze-alt-text.js', __DIR__ ),
        array( 'jquery', 'seo-setup-script', 'seo-setup-generate-alt-text' ),
        '1.0.0',
        true
    );

    // REST API base URL for our plugin endpoints
    $rest_base = esc_url_raw( rest_url( 'seo-setup/v1' ) );

    wp_localize_script(
        'seo-setup-generate-alt-text',
        'SEOSetupAltTextAjax',
        array(
            // CHANGED: Now points to REST API instead of admin-ajax.php
            'restBase' => $rest_base,
            'nonceRefreshUrl' => $rest_base . '/auth/nonce',
            'legacyAjaxUrl' => admin_url( 'admin-ajax.php' ),
            'ajaxNonce' => wp_create_nonce( 'seo_setup_alt_text_nonce' ),
            'forceLegacyAjax' => false,
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            // Keep legacy for any remaining references
            'ajaxurl'  => admin_url( 'admin-ajax.php' ),
        )
    );

    wp_localize_script(
        'seo-setup-script',
        'seoSetupAjax',
        array(
            // CHANGED: Now points to REST API instead of admin-ajax.php
            'restBase' => $rest_base,
            'nonceRefreshUrl' => $rest_base . '/auth/nonce',
            'legacyAjaxUrl' => admin_url( 'admin-ajax.php' ),
            'ajaxNonce' => wp_create_nonce( 'seo_setup_nonce' ),
            'forceLegacyAjax' => false,
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'pluginUrl' => plugins_url( '/', __DIR__ ),
            // Keep legacy for any remaining references
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
        )
    );
}
add_action( 'admin_enqueue_scripts', 'seo_setup_enqueue_assets' );

function seo_setup_enqueue_frontend_assets() {
    if ( ! is_front_page() && ! is_page( 'contact' ) ) {
        return;
    }

    wp_enqueue_script(
        'seo-setup-frontend-geo',
        plugins_url( 'assets/js/geo.js', __DIR__ ),
        array( 'jquery' ),
        '1.0.8',
        true
    );

    wp_localize_script(
        'seo-setup-frontend-geo',
        'seoSetupAjax',
        array(
            // CHANGED: Frontend geo also uses REST API
            'restBase' => esc_url_raw( rest_url( 'seo-setup/v1' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        )
    );
}
add_action( 'wp_enqueue_scripts', 'seo_setup_enqueue_frontend_assets' );
