<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once SEO_SETUP_PLUGIN_DIR . 'inc/access-token.php';

function seo_setup_add_admin_menu() {
    add_menu_page(
        'SEO Setup',
        'SEO Setup',
        'manage_options',
        'seo-setup',
        'seo_setup_render_dashboard',
        'dashicons-admin-tools',
        5
    );
}
add_action( 'admin_menu', 'seo_setup_add_admin_menu' );

function seo_setup_render_dashboard() {
    if ( ! seo_setup_check_token() ) {
        seo_setup_render_token_form();
        return;
    }

    include SEO_SETUP_PLUGIN_DIR . 'templates/dashboard.php';
}