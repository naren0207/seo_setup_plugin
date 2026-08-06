<?php
/**
 * Plugin Name: SEO Setup
 * Description: A WordPress plugin to help manage and optimize SEO elements like alt text, meta descriptions, title tags, and more — Version 1.1.1.
 * Version: 1.1
 * Author: vSplash
 * License: GPL-2.0+
 * Text Domain: seo-setup
 * Domain Path: /languages
 *
 * @package SEO_Setup
 *
 * FIXED (v1.2): Zero filesystem writes at activation time.
 * Previously the plugin tried to create mu-plugins directory and write 3 MU plugin
 * files during activation, which caused "Unable to create directory uploads/YYYY/MM"
 * errors on servers with restricted permissions.
 *
 * New approach: MU plugin files are created only when the user runs the
 * respective feature (Page Names, Canonical Tags, Schema Markup).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constants
 */
define( 'SEO_SETUP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEO_SETUP_BATCH_SIZE', 5 );

// FIXED: Removed wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' ) that was here.
// This ran at INCLUDE TIME during activation and caused filesystem errors.
// MU plugins directory is now created only when a feature needs to write an MU file.

/**
 * Includes
 */
require_once SEO_SETUP_PLUGIN_DIR . 'inc/error-log.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/enqueue.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/admin-menu.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/generate-alt-text.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/page-names.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/focus-keyword.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/featured-image.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/open-graphs.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/api-logger.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/plugin-installer.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/xml-sitemap.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/image-sitemap.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/robots-txt.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/helpers.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/schema.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/canonical-tags.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/301-redirection.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/disable-yoast.php';
require_once SEO_SETUP_PLUGIN_DIR . 'inc/rest-api.php';

/**
 * Activation
 *
 * FIXED: Only safe operations here — database table creation and rewrite flush.
 * NO filesystem writes (no mu-plugins, no fopen, no wp_mkdir_p).
 * MU plugin files are created when the user actually runs each feature.
 */
register_activation_hook( __FILE__, 'seo_setup_plugin_activate' );

function seo_setup_plugin_activate() {
	// Create redirects table — this is a database operation, always safe.
	if ( function_exists( 'seo_setup_create_redirect_table' ) ) {
		seo_setup_create_redirect_table();
	}

	// FIXED: Removed all MU plugin file creation from here:
	// - seo_setup_create_mu_plugin_file()        → now runs when user clicks "Page Names"
	// - create_seo_setup_mu_plugin_file()         → now runs when user clicks "Generate Canonical URLs"
	// - seo_setup_create_mu_plugin_file_schema()  → now runs when user clicks "Generate Schema"
	// - wp_mkdir_p( mu-plugins )                  → now runs inside each MU creation function

	// FIXED: Removed seo_setup_logger_plugin_installed() from activation.
	// The logger calls wp_remote_post which can fail during activation context.
	// Instead, log on the first admin page load after activation.
	update_option( 'seo_setup_activation_pending_log', true );

	// Clear the deactivated flag
	delete_option( 'seo_setup_plugin_deactivated' );

	flush_rewrite_rules();
}

/**
 * Log activation on first admin load after activation (safe context)
 */
add_action( 'admin_init', 'seo_setup_log_activation_if_pending' );

function seo_setup_log_activation_if_pending() {
	if ( get_option( 'seo_setup_activation_pending_log' ) ) {
		delete_option( 'seo_setup_activation_pending_log' );
		if ( function_exists( 'seo_setup_logger_plugin_installed' ) ) {
			seo_setup_logger_plugin_installed();
		}
	}
}

/**
 * Deactivation
 */
register_deactivation_hook( __FILE__, 'seo_setup_plugin_deactivate' );

function seo_setup_plugin_deactivate() {
	update_option( 'seo_setup_plugin_deactivated', true );
	flush_rewrite_rules();
}

/**
 * Uninstall
 */
register_uninstall_hook( __FILE__, 'seo_setup_plugin_uninstall' );

function seo_setup_plugin_uninstall() {
	delete_option( 'seo_setup_plugin_deactivated' );
	delete_option( 'seo_setup_activation_pending_log' );
}

/**
 * Helper: redirect table exists?
 */
function seo_setup_redirect_table_exists() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'seo_setup_redirects';
	return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name );
}

/**
 * Helper: required MU plugin files exist?
 */
function seo_setup_mu_plugin_file_exists() {
	if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		return false;
	}

	$redirects_file = WPMU_PLUGIN_DIR . '/seo_setup_redirects.php';
	$canonical_file = WPMU_PLUGIN_DIR . '/seo-canonical-deactivation.php';
	$schema_file    = WPMU_PLUGIN_DIR . '/seo-schema-persistence.php';

	return (
		file_exists( $redirects_file ) &&
		file_exists( $canonical_file ) &&
		file_exists( $schema_file )
	);
}

/**
 * Transfer fallback redirects into Simple 301 Redirects.
 * Safe to run only after that plugin is active.
 */
function seo_setup_transfer_option_redirects_to_simple_301() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'simple-301-redirects/wp-simple-301-redirects.php' ) ) {
		return;
	}

	$stored_redirects = get_option( 'seo_setup_redirects_option', array() );
	if ( empty( $stored_redirects ) || ! is_array( $stored_redirects ) ) {
		return;
	}

	$simple_301 = get_option( '301_redirects', array() );
	if ( ! is_array( $simple_301 ) ) {
		$simple_301 = array();
	}

	foreach ( $stored_redirects as $old_url => $new_url ) {
		$site_url = home_url();

		$old_path = str_replace( $site_url, '', $old_url );
		$new_path = str_replace( $site_url, '', $new_url );

		$old_path = '/' . ltrim( $old_path, '/' );
		$new_path = '/' . ltrim( $new_path, '/' );

		if ( ! isset( $simple_301[ $old_path ] ) ) {
			$simple_301[ $old_path ] = $new_path;
		}
	}

	update_option( '301_redirects', $simple_301 );

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	flush_rewrite_rules();
}

/**
 * Init modules
 */
add_action( 'plugins_loaded', 'seo_setup_init_modules' );

function seo_setup_init_modules() {
	if ( class_exists( 'SEO_Setup_XML_Sitemap' ) ) {
		SEO_Setup_XML_Sitemap::init();
	}

	if ( class_exists( 'SEO_Setup_Image_Sitemap' ) ) {
		SEO_Setup_Image_Sitemap::init();
	}

	if ( class_exists( 'SEO_Setup_Robots_Txt' ) ) {
		SEO_Setup_Robots_Txt::init();
	}
}

/**
 * Disable Yoast sitemap/robots on AJAX request
 */
add_action( 'wp_ajax_seo_setup_disable_yoast', 'seo_setup_disable_yoast_callback' );

/**
 * Disable Yoast JSON-LD + canonical while plugin is active
 */
add_filter( 'wpseo_json_ld_output', '__return_empty_array', 9999 );
add_filter( 'wpseo_canonical', '__return_false' );

/**
 * Creates canonical persistence MU plugin.
 *
 * FIXED: This is NOT called during activation anymore.
 * It is called from seo_setup_ajax_generate_canonical() in canonical-tags.php
 * when the user clicks "Generate Canonical URLs".
 */
function create_seo_setup_mu_plugin_file() {
	// Ensure mu-plugins directory exists
	if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins' ) ) {
		if ( ! @wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' ) ) {
			error_log( '[SEO Setup] Cannot create mu-plugins directory for canonical persistence.' );
			return false;
		}
	}

	$mu_plugin_file = WP_CONTENT_DIR . '/mu-plugins/seo-canonical-deactivation.php';

	if ( file_exists( $mu_plugin_file ) ) {
		return true;
	}

	$file = @fopen( $mu_plugin_file, 'w' );
	if ( ! $file ) {
		error_log( '[SEO Setup] Cannot write canonical MU plugin file.' );
		return false;
	}

	$mu_plugin_code  = "<?php\n";
	$mu_plugin_code .= "// MU Plugin created by SEO Setup - canonical tag persistence\n\n";
	$mu_plugin_code .= "function seo_check_main_plugin_deactivation() {\n";
	$mu_plugin_code .= "    if ( get_option( 'seo_setup_plugin_deactivated', false ) ) {\n";
	$mu_plugin_code .= "        add_action( 'wp_head', 'seo_setup_output_mu_plugins_canonical_tag', 1 );\n";
	$mu_plugin_code .= "        add_filter( 'wpseo_canonical', '__return_false' );\n";
	$mu_plugin_code .= "    }\n";
	$mu_plugin_code .= "}\n";
	$mu_plugin_code .= "add_action( 'wp', 'seo_check_main_plugin_deactivation' );\n\n";

	$mu_plugin_code .= "function seo_setup_output_mu_plugins_canonical_tag() {\n";
	$mu_plugin_code .= "    if ( ! is_singular() ) {\n";
	$mu_plugin_code .= "        return;\n";
	$mu_plugin_code .= "    }\n";
	$mu_plugin_code .= "    \$post_id = get_the_ID();\n";
	$mu_plugin_code .= "    if ( ! \$post_id ) {\n";
	$mu_plugin_code .= "        return;\n";
	$mu_plugin_code .= "    }\n";
	$mu_plugin_code .= "    \$canonical_url = get_post_meta( \$post_id, '_seo_setup_canonical_url', true );\n";
	$mu_plugin_code .= "    if ( empty( \$canonical_url ) ) {\n";
	$mu_plugin_code .= "        \$canonical_url = get_permalink( \$post_id );\n";
	$mu_plugin_code .= "    }\n";
	$mu_plugin_code .= "    if ( ! empty( \$canonical_url ) ) {\n";
	$mu_plugin_code .= "        echo '<link rel=\"canonical\" href=\"' . esc_url( \$canonical_url ) . '\" />' . \"\\n\";\n";
	$mu_plugin_code .= "    }\n";
	$mu_plugin_code .= "}\n";

	fwrite( $file, $mu_plugin_code );
	fclose( $file );
	return true;
}

/**
 * Creates schema persistence MU plugin.
 *
 * FIXED: This is NOT called during activation anymore.
 * It is called from seo_setup_generate_org_schema() and
 * seo_setup_generate_local_schema() in schema.php when the user
 * clicks "Generate Organization Schema" or "Generate LocalBusiness Schema".
 */
function seo_setup_create_mu_plugin_file_schema() {
	// Ensure mu-plugins directory exists
	if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins' ) ) {
		if ( ! @wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' ) ) {
			error_log( '[SEO Setup] Cannot create mu-plugins directory for schema persistence.' );
			return false;
		}
	}

	$mu_plugin_file = WP_CONTENT_DIR . '/mu-plugins/seo-schema-persistence.php';

	if ( file_exists( $mu_plugin_file ) ) {
		return true;
	}

	$file = @fopen( $mu_plugin_file, 'w' );
	if ( ! $file ) {
		error_log( '[SEO Setup] Cannot write schema MU plugin file.' );
		return false;
	}

	$mu_plugin_code  = "<?php\n";
	$mu_plugin_code .= "// MU Plugin created by SEO Setup - schema persistence\n\n";
	$mu_plugin_code .= "add_action( 'wp_head', function() {\n";
	$mu_plugin_code .= "    if ( ! get_option( 'seo_setup_plugin_deactivated', false ) ) {\n";
	$mu_plugin_code .= "        return;\n";
	$mu_plugin_code .= "    }\n";
	$mu_plugin_code .= "    if ( is_front_page() || is_home() ) {\n";
	$mu_plugin_code .= "        \$schema = get_option( 'seo_setup_generated_schema' );\n";
	$mu_plugin_code .= "        if ( ! empty( \$schema ) ) {\n";
	$mu_plugin_code .= "            echo \$schema;\n";
	$mu_plugin_code .= "        }\n";
	$mu_plugin_code .= "    } elseif ( is_singular() ) {\n";
	$mu_plugin_code .= "        \$post_id = get_the_ID();\n";
	$mu_plugin_code .= "        if ( ! \$post_id ) {\n";
	$mu_plugin_code .= "            return;\n";
	$mu_plugin_code .= "        }\n";
	$mu_plugin_code .= "        \$schema = get_post_meta( \$post_id, 'seo_setup_generated_schema', true );\n";
	$mu_plugin_code .= "        if ( ! empty( \$schema ) ) {\n";
	$mu_plugin_code .= "            echo \$schema;\n";
	$mu_plugin_code .= "        }\n";
	$mu_plugin_code .= "    }\n";
	$mu_plugin_code .= "}, 1 );\n";

	fwrite( $file, $mu_plugin_code );
	fclose( $file );
	return true;
}
