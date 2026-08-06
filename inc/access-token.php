<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function decrypt($encryptedData, $token) {
	$encryption_method = 'aes-256-cbc';
	$data              = base64_decode($encryptedData);
	$ivlen             = openssl_cipher_iv_length($encryption_method);
	$iv                = substr($data, 0, $ivlen);
	$encrypted         = substr($data, $ivlen);
	return openssl_decrypt(
		$encrypted,
		$encryption_method,
		$token,
		0,
		$iv
	);
}

function seo_setup_validate_token( $token ) {
	$api_url      = 'https://partners.v6live.com/vwppsettings.php';
	$current_user = wp_get_current_user();

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	$plugin_file    = SEO_SETUP_PLUGIN_DIR . 'seo-setup.php';
	$plugin_data    = get_plugin_data( $plugin_file, false, false );
	$plugin_name    = $plugin_data['Name'];
	$plugin_version = $plugin_data['Version'];

	$payload = array(
		'token'          => $token,
		'plugin-name'    => $plugin_name,
		'plugin-version' => $plugin_version,
		'website'        => home_url(),
		'username'       => $current_user->user_login,
	);
	error_log( "SEO Setup Payload: " . json_encode( $payload ) );

	$args = array(
		'body'    => json_encode( $payload ),
		'headers' => array(
            'token' => $token,
            'Content-Type' => 'application/json'
        ),
		'timeout' => 30,
	);
	$response = wp_remote_post( $api_url, $args );
	if ( is_wp_error( $response ) ) {
		error_log( "SEO Setup: API request error: " . $response->get_error_message() );
		return $response->get_error_message();
	}
	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	error_log( "SEO Setup: Received token validation API response: Code " . $status_code . ", Body: " . $body );

	if ( $status_code == 200 ) {
		$json = json_decode( $body, true );
		if ( is_array( $json ) && isset( $json['code'] ) ) {
			if ( (int) $json['code'] !== 200 ) {
				return isset( $json['message'] ) ? $json['message'] : 'Token validation failed.';
			}
			update_option( 'seo_setup_encrypted_data', $body );
			return true;
		} else {
			$decrypted = decrypt( $body, $token );
			//error_log( "SEO Setup: Decrypted API response: " . $decrypted );
			$decrypted_json = json_decode( $decrypted, true );
			if ( is_array( $decrypted_json ) && isset( $decrypted_json['code'] ) ) {
				if ( (int) $decrypted_json['code'] !== 200 ) {
					return isset( $decrypted_json['message'] ) ? $decrypted_json['message'] : 'Token validation failed.';
				}
				update_option( 'seo_setup_encrypted_data', $body );
				return true;
			} else {
				return 'Token validation failed.';
			}
		}
	} else {
		$json = json_decode( $body, true );
		return isset( $json['message'] ) ? $json['message'] : 'Token validation failed.';
	}
}

function seo_setup_check_token() {
	$current_domain = parse_url( home_url(), PHP_URL_HOST );
	$stored_token   = get_option( "seo_setup_token_$current_domain" );
	$stored_time    = get_option( "seo_setup_token_time_$current_domain" );
	
	if ( ! $stored_token || ! $stored_time ) {
		return false;
	}
	
	// Expire after 24 hours
	if ( ( time() - $stored_time ) > 86400 ) {
		delete_option( "seo_setup_token_$current_domain" );
		delete_option( "seo_setup_token_time_$current_domain" );
		return false;
	}
	
	return true;
}

function seo_setup_save_token( $token ) {
	$current_domain = parse_url( home_url(), PHP_URL_HOST );
	update_option( "seo_setup_token_$current_domain", $token );
	update_option( "seo_setup_token_time_$current_domain", time() );
}

// Handle submission token
function seo_setup_handle_token_submission() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seo-setup' ) {
        return;
    }
    
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    if ( ! isset( $_POST['seo_setup_access_token'] ) ) {
        return;
    }

    $token      = sanitize_text_field( $_POST['seo_setup_access_token'] );
    $validation = seo_setup_validate_token( $token );
    
    if ( true === $validation ) {
        seo_setup_save_token( $token );
        wp_safe_redirect( admin_url( 'admin.php?page=seo-setup' ) );
        exit;
    }
}
add_action( 'admin_init', 'seo_setup_handle_token_submission' );

// Get API credentials by product name
function seo_setup_get_api_credentials_by_product( $product_name ) {
	$encrypted = get_option( 'seo_setup_encrypted_data' );
	if ( ! $encrypted ) {
		return false;
	}

	$stored_token = null;
	$current_domain = parse_url( home_url(), PHP_URL_HOST );
	$stored_token = get_option( "seo_setup_token_$current_domain" );
	if ( ! $stored_token ) {
		return false;
	}

	$decrypted = decrypt( $encrypted, $stored_token );
	$decoded   = json_decode( $decrypted, true );

	if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $decoded['data'] ) ) {
		return false;
	}

	foreach ( $decoded['data'] as $entry ) {
		if ( isset( $entry['product'] ) && strtoupper( $entry['product'] ) === strtoupper( $product_name ) ) {
			return array(
				'endpoint' => $entry['endpoint'],
				'key'      => $entry['key'],
			);
		}
	}

	return false;
}

function seo_setup_render_token_form() {
    $error_message = '';
    
    if ( isset( $_POST['seo_setup_access_token'] ) ) {
        // If we reach here, it means validation failed in admin_init
        // (successful ones already redirected)
        $error_message = 'Token validation failed. Please try again.';
    }
    
    if ( $error_message ) {
        echo '<div class="error"><p>' . esc_html( $error_message ) . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>SEO Setup Access</h1>
        <form method="POST" action="">
            <label for="seo_setup_access_token">Access Token:</label><br><br>
            <input type="text" name="seo_setup_access_token" id="seo_setup_access_token" required style="width:400px;">
            <br><br>
            <button type="submit" class="button button-primary">Submit</button>
        </form>
    </div>
    <?php
}