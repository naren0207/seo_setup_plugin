<?php
/**
 * Plugin Installer - Installs "Simple 301 Redirects" when needed
 * 
 * FIXED: Previously ran on every admin_init via __construct(), which called
 * Plugin_Upgrader->install() which internally calls wp_upload_dir() and
 * triggers "Unable to create directory uploads/YYYY/MM" during plugin activation.
 * 
 * NOW: ZERO code runs at activation or admin_init. Nothing runs automatically.
 * The install is triggered ONLY when the user clicks "Page Names" button
 * via Plugin_Installer::ensure_redirect_plugin_active()
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin_Installer {
	const REQUIRED_PLUGIN_SLUG = 'simple-301-redirects';
	const REQUIRED_PLUGIN_FILE = 'simple-301-redirects/wp-simple-301-redirects.php';

	// NO __construct(). NO admin_init hook. NOTHING runs at include/activation time.

	/**
	 * Call this explicitly from the Page Names AJAX handler
	 * when 301 redirect functionality is actually needed.
	 * 
	 * @return bool True if plugin is active, false if install failed.
	 */
	public static function ensure_redirect_plugin_active() {
		self::load_admin_includes();

		if ( is_plugin_active( self::REQUIRED_PLUGIN_FILE ) ) {
			return true;
		}

		if ( ! self::is_plugin_installed() ) {
			$upload_base = WP_CONTENT_DIR . '/uploads';
			if ( ! is_dir( $upload_base ) || ! is_writable( $upload_base ) ) {
				error_log( '[SEO Setup] Cannot auto-install Simple 301 Redirects - uploads directory not writable.' );
				return false;
			}
			self::install_required_plugin();
		}

		wp_cache_delete( 'plugins', 'plugins' );

		if ( self::is_plugin_installed() ) {
			self::activate_plugin_now();
			return true;
		}

		error_log( '[SEO Setup] Simple 301 Redirects could not be installed.' );
		return false;
	}

	private static function load_admin_includes() {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		include_once ABSPATH . 'wp-admin/includes/file.php';
		include_once ABSPATH . 'wp-admin/includes/misc.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
	}

	private static function is_plugin_installed() {
		$installed_plugins = get_plugins();
		return isset( $installed_plugins[ self::REQUIRED_PLUGIN_FILE ] );
	}

	private static function install_required_plugin() {
		$api = plugins_api( 'plugin_information', [
			'slug'   => self::REQUIRED_PLUGIN_SLUG,
			'fields' => [ 'sections' => false ]
		] );
		if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
			error_log( 'Failed to fetch plugin information for ' . self::REQUIRED_PLUGIN_SLUG );
			return;
		}
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			error_log( 'Failed to install the plugin: ' . $result->get_error_message() );
		}
	}

	private static function activate_plugin_now() {
		if ( is_plugin_active( self::REQUIRED_PLUGIN_FILE ) ) {
			return;
		}
		if ( file_exists( WP_PLUGIN_DIR . '/' . self::REQUIRED_PLUGIN_FILE ) ) {
			$result = activate_plugin( self::REQUIRED_PLUGIN_FILE );
			if ( is_wp_error( $result ) ) {
				error_log( 'Failed to activate the required plugin: ' . $result->get_error_message() );
			}
		}
	}
}
