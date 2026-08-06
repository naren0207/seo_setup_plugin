<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Remove default WordPress canonical
remove_action( 'wp_head', 'rel_canonical' );

// FIXED: Also disable Yoast's canonical to prevent duplicate canonical tags
add_filter( 'wpseo_canonical', '__return_false' );

// Add our canonical tag with high priority
add_action( 'wp_head', 'seo_setup_output_canonical_tag', 1 );

// Function to update both slug and canonical URL
function seo_setup_update_post_slug_and_canonical( $post_id, $new_url ) {
    // Parse the new URL to get the slug
    $url_parts = parse_url( $new_url );
    $path = trim( $url_parts['path'], '/' );
    $path_parts = explode( '/', $path );
    $new_slug = end( $path_parts );

    // Get the post
    $post = get_post( $post_id );
    if ( ! $post ) {
        return false;
    }

    // Update the post slug
    $post_data = array(
        'ID'        => $post_id,
        'post_name' => sanitize_title( $new_slug )
    );
    
    // Update the post
    $updated = wp_update_post( $post_data );
    
    if ( $updated ) {
        // Clear the post cache
        clean_post_cache( $post_id );
        
        // Flush rewrite rules
        flush_rewrite_rules( false );
        
        // Update canonical URL
        update_post_meta( $post_id, '_seo_setup_canonical_url', esc_url_raw( $new_url ) );
        
        return true;
    }
    
    return false;
}

// AJAX handler for updating canonical URL and slug
function seo_setup_ajax_update_canonical() {
    // Verify nonce
    if ( ! check_ajax_referer( 'seo_setup_nonce', 'nonce', false ) ) {
        wp_send_json_error( 'Invalid security token' );
        return;
    }

    // Check permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
        return;
    }

    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    $canonical_url = isset( $_POST['canonical_url'] ) ? esc_url_raw( $_POST['canonical_url'] ) : '';

    if ( ! $post_id || empty( $canonical_url ) ) {
        wp_send_json_error( 'Invalid post ID or URL' );
        return;
    }

    // Update both slug and canonical URL
    $success = seo_setup_update_post_slug_and_canonical( $post_id, $canonical_url );

    if ( $success ) {
        // Get the updated permalink
        $new_permalink = get_permalink( $post_id );
        
        wp_send_json_success( array(
            'message'       => 'URL and canonical updated successfully',
            'canonical_url' => $new_permalink,
            'post_url'      => $new_permalink
        ) );
    } else {
        wp_send_json_error( 'Failed to update URL' );
    }
}
add_action( 'wp_ajax_seo_setup_update_canonical', 'seo_setup_ajax_update_canonical' );

// Function to update canonical URL and ensure it's displayed
function seo_setup_update_canonical_url_and_display( $post_id, $canonical_url ) {
    // If empty canonical URL, use the default permalink
    if ( empty( $canonical_url ) ) {
        $canonical_url = get_permalink( $post_id );
    }

    // Update the canonical URL
    update_post_meta( $post_id, '_seo_setup_canonical_url', esc_url_raw( $canonical_url ) );

    // Clear all caches
    clean_post_cache( $post_id );
    wp_cache_delete( $post_id, 'post_meta' );
    wp_cache_delete( $post_id, 'posts' );
    
    // Flush rewrite rules to ensure permalinks are updated
    flush_rewrite_rules( false );

    return $canonical_url;
}

// Function to sync canonical URL with current permalink
function seo_setup_sync_canonical_with_permalink( $post_id ) {
    $current_permalink = get_permalink( $post_id );
    
    if ( ! empty( $current_permalink ) ) {
        return seo_setup_update_canonical_url_and_display( $post_id, $current_permalink );
    }
    return false;
}

// AJAX handler for refreshing canonical URL
function seo_setup_ajax_refresh_canonical() {
    // Verify nonce
    if ( ! check_ajax_referer( 'seo_setup_nonce', 'nonce', false ) ) {
        wp_send_json_error( 'Invalid security token' );
        return;
    }

    // Check permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
        return;
    }

    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    if ( ! $post_id || ! get_post( $post_id ) ) {
        wp_send_json_error( 'Invalid post ID' );
        return;
    }

    try {
        // Get the post's current URL
        $current_url = get_permalink( $post_id );
        
        // Update canonical URL to match current URL
        seo_setup_update_canonical_url_and_display( $post_id, $current_url );
        
        wp_send_json_success( array(
            'message'       => 'Canonical URL refreshed successfully',
            'canonical_url' => $current_url
        ) );
    } catch ( Exception $e ) {
        wp_send_json_error( 'Failed to refresh canonical URL' );
    }
}
add_action( 'wp_ajax_seo_setup_refresh_canonical', 'seo_setup_ajax_refresh_canonical' );

// Update canonical URL immediately when post name/slug changes
function seo_setup_update_canonical_on_slug_change( $post_id, $post_after, $post_before ) {
    // Skip if this is an autosave or revision
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || wp_is_post_revision( $post_id ) ) {
        return;
    }

    // Skip if post is not published
    if ( 'publish' !== get_post_status( $post_id ) ) {
        return;
    }

    // Check if slug has changed
    if ( $post_before->post_name !== $post_after->post_name ) {
        // Force immediate sync canonical with new permalink
        add_action( 'wp_head', function() use ( $post_id ) {
            $new_permalink = get_permalink( $post_id );
            if ( ! empty( $new_permalink ) ) {
                echo '<link rel="canonical" href="' . esc_url( $new_permalink ) . '" />' . "\n";
            }
        }, 0 );
        
        // Update stored canonical URL
        seo_setup_sync_canonical_with_permalink( $post_id );
    }
}
add_action( 'post_updated', 'seo_setup_update_canonical_on_slug_change', 0, 3 );

// Update canonical URL when post is published
function seo_setup_update_canonical_on_publish( $new_status, $old_status, $post ) {
    if ( $new_status === 'publish' ) {
        seo_setup_sync_canonical_with_permalink( $post->ID );
    }
}
add_action( 'transition_post_status', 'seo_setup_update_canonical_on_publish', 10, 3 );

// Update canonical URL when permalink structure changes
add_action( 'permalink_structure_changed', function() {
    global $wpdb;
    
    // Get all published posts and pages
    $posts = $wpdb->get_results(
        "SELECT ID FROM {$wpdb->posts} 
         WHERE post_status = 'publish' 
         AND (post_type = 'post' OR post_type = 'page')"
    );

    foreach ( $posts as $post ) {
        seo_setup_sync_canonical_with_permalink( $post->ID );
    }
});

// Function to generate canonical URLs
function seo_setup_generate_canonical_urls( $offset = 0, $limit = null ) {
    global $wpdb;

    $offset = max( 0, absint( $offset ) );
    $limit  = $limit ? max( 1, absint( $limit ) ) : SEO_SETUP_BATCH_SIZE;

    $where_sql = "post_status = 'publish' AND (post_type = 'post' OR post_type = 'page')";
    $total_count = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE {$where_sql}" );

    $posts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE {$where_sql} ORDER BY ID ASC LIMIT %d OFFSET %d",
            $limit,
            $offset
        )
    );

    $results = array();

    if ( $posts ) {
        foreach ( $posts as $post ) {
            seo_setup_sync_canonical_with_permalink( $post->ID );

            $results[] = array(
                'post_id'       => $post->ID,
                'post_title'    => $post->post_title,
                'canonical_url' => get_permalink( $post->ID ),
                'status'        => 'Updated',
            );
        }
    }

    return array(
        'results'     => $results,
        'total_count' => $total_count,
    );
}

// Output canonical tag
if ( ! function_exists( 'seo_setup_output_canonical_tag' ) ) {
function seo_setup_output_canonical_tag() {
    // Check if we're on a singular page
    if ( ! is_singular() ) {
        return;
    }

    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return;
    }

    // FIXED: Check for a stored custom canonical URL first
    $canonical_url = get_post_meta( $post_id, '_seo_setup_canonical_url', true );

    // If no custom canonical is stored, fall back to the current permalink
    if ( empty( $canonical_url ) ) {
        $canonical_url = get_permalink( $post_id );
        // Store the default so it shows up in the admin UI
        update_post_meta( $post_id, '_seo_setup_canonical_url', $canonical_url );
    }

    // Output the canonical tag
    if ( ! empty( $canonical_url ) ) {
        echo '<link rel="canonical" href="' . esc_url( $canonical_url ) . '" />' . "\n";
    }
}
}
// AJAX handler for generating canonical URLs
function seo_setup_ajax_generate_canonical() {
    if ( ! check_ajax_referer( 'seo_setup_nonce', 'nonce', false ) ) {
        wp_send_json_error( 'Invalid security token' );
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
        return;
    }

    $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
    $limit  = isset( $_POST['limit'] ) ? max( 1, absint( $_POST['limit'] ) ) : SEO_SETUP_BATCH_SIZE;

    try {
        $generated   = seo_setup_generate_canonical_urls( $offset, $limit );
        $results     = $generated['results'];
        $total_count = (int) $generated['total_count'];

        if ( 0 === $offset ) {
            seo_setup_create_mu_plugin_file_canonical();

            if ( function_exists( 'create_seo_setup_mu_plugin_file' ) ) {
                create_seo_setup_mu_plugin_file();
            }
        }

        $next_offset = $offset + $limit;

        wp_send_json_success( array(
            'results'         => $results,
            'processed_count' => count( $results ),
            'offset'          => $offset,
            'limit'           => $limit,
            'next_offset'     => $next_offset,
            'total_count'     => $total_count,
            'has_more'        => $next_offset < $total_count,
        ) );
    } catch ( Exception $e ) {
        wp_send_json_error( 'Error generating canonical URLs: ' . $e->getMessage() );
    }
}
add_action( 'wp_ajax_seo_setup_generate_canonical', 'seo_setup_ajax_generate_canonical' );

// AJAX handler for updating canonical URL
function seo_setup_ajax_update_canonical_url() {
    error_log( 'Starting canonical URL update...' );

    // Verify nonce
    if ( ! check_ajax_referer( 'seo_setup_nonce', 'nonce', false ) ) {
        error_log( 'Invalid nonce in canonical URL update' );
        wp_send_json_error( 'Invalid security token' );
        return;
    }

    // Check permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        error_log( 'Permission denied for canonical URL update' );
        wp_send_json_error( 'Permission denied' );
        return;
    }

    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    if ( ! $post_id || ! get_post( $post_id ) ) {
        error_log( 'Invalid post ID: ' . $post_id );
        wp_send_json_error( 'Invalid post ID' );
        return;
    }

    $canonical_url = isset( $_POST['canonical_url'] ) ? trim( $_POST['canonical_url'] ) : '';
    if ( $canonical_url && ! filter_var( $canonical_url, FILTER_VALIDATE_URL ) ) {
        error_log( 'Invalid URL format: ' . $canonical_url );
        wp_send_json_error( 'Invalid URL format' );
        return;
    }

    try {
        // Update the canonical URL
        $canonical_url = seo_setup_update_canonical_url_and_display( $post_id, $canonical_url );
        
        error_log( 'Successfully updated canonical URL: ' . $canonical_url );
        wp_send_json_success( array(
            'message'       => 'Canonical URL updated successfully',
            'canonical_url' => $canonical_url
        ) );
    } catch ( Exception $e ) {
        error_log( 'Failed to update canonical URL: ' . $e->getMessage() );
        wp_send_json_error( 'Failed to update canonical URL' );
    }
}
add_action( 'wp_ajax_seo_setup_update_canonical_url', 'seo_setup_ajax_update_canonical_url' );
// FIXED: Was previously registered as 'wp_ajax_seo_setup_update_canonical' which
// overwrote the first handler. Now uses a distinct action name.

// Add meta box for custom canonical URL
function seo_setup_add_canonical_metabox() {
    add_meta_box(
        'seo_setup_canonical_metabox',
        'Canonical URL',
        'seo_setup_render_canonical_metabox',
        array( 'post', 'page' ),
        'normal',
        'high'
    );
}
add_action( 'add_meta_boxes', 'seo_setup_add_canonical_metabox' );

// Render canonical meta box
function seo_setup_render_canonical_metabox( $post ) {
    wp_nonce_field( 'seo_setup_canonical_metabox', 'seo_setup_canonical_nonce' );
    
    $canonical_url = get_permalink( $post->ID );
    ?>
    <p>
        <label for="seo_setup_canonical_url">Canonical URL:</label>
        <input type="url" id="seo_setup_canonical_url" name="seo_setup_canonical_url" value="<?php echo esc_url( $canonical_url ); ?>" style="width: 100%;" />
        <span class="description">Current canonical URL will automatically sync with the page permalink.</span>
    </p>
    <?php
}

// Save canonical meta box data
function seo_setup_save_canonical_metabox( $post_id ) {
    if ( ! isset( $_POST['seo_setup_canonical_nonce'] ) || 
         ! wp_verify_nonce( $_POST['seo_setup_canonical_nonce'], 'seo_setup_canonical_metabox' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Always sync with current permalink
    seo_setup_sync_canonical_with_permalink( $post_id );
}
add_action( 'save_post', 'seo_setup_save_canonical_metabox' );


// === MU Plugin Creation Logic for Canonical ===
// FIXED: MU plugin is now created ONLY when user clicks "Generate Canonical URLs",
// not during plugin activation or admin_init. This prevents file write errors
// during installation when permissions may not be ready.

function seo_setup_create_mu_plugin_file_canonical() {
    if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins' ) ) {
        if ( ! @wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' ) ) {
            error_log( '[SEO Setup] Cannot create mu-plugins directory.' );
            return false;
        }
    }

    if ( ! is_writable( WP_CONTENT_DIR . '/mu-plugins' ) ) {
        error_log( '[SEO Setup] mu-plugins directory is not writable.' );
        return false;
    }

    $mu_plugin_file = WP_CONTENT_DIR . '/mu-plugins/seo-canonical-deactivation.php';

    if ( file_exists( $mu_plugin_file ) ) {
        return true; // Already exists
    }

    $file = @fopen( $mu_plugin_file, 'w' );
    if ( $file ) {
        $mu_plugin_code = "<?php\n";
        $mu_plugin_code .= "// MU Plugin for persistent canonical tags - created by SEO Setup.\n\n";
        $mu_plugin_code .= "add_filter('wpseo_canonical', '__return_false');\n\n";
        $mu_plugin_code .= "add_action('wp_head', 'seo_setup_output_mu_plugins_canonical_tag', 1);\n\n";
        $mu_plugin_code .= "function seo_setup_output_mu_plugins_canonical_tag() {\n";
        $mu_plugin_code .= "    if ( ! is_singular() ) {\n";
        $mu_plugin_code .= "        return;\n";
        $mu_plugin_code .= "    }\n";
        $mu_plugin_code .= "    \$post_id = get_the_ID();\n";
        $mu_plugin_code .= "    if ( ! \$post_id ) {\n";
        $mu_plugin_code .= "        return;\n";
        $mu_plugin_code .= "    }\n";
        $mu_plugin_code .= "    \$canonical_url = get_post_meta(\$post_id, '_seo_setup_canonical_url', true);\n";
        $mu_plugin_code .= "    if ( empty(\$canonical_url) ) {\n";
        $mu_plugin_code .= "        \$canonical_url = get_permalink(\$post_id);\n";
        $mu_plugin_code .= "    }\n";
        $mu_plugin_code .= "    if ( ! empty(\$canonical_url) ) {\n";
        $mu_plugin_code .= "        echo '<link rel=\"canonical\" href=\"' . esc_url(\$canonical_url) . '\" />' . \"\\n\";\n";
        $mu_plugin_code .= "    }\n";
        $mu_plugin_code .= "}\n";
        fwrite( $file, $mu_plugin_code );
        fclose( $file );
        return true;
    }

    return false;
}
// NOTE: No add_action here. This function is called from inside
// seo_setup_ajax_generate_canonical() when the user clicks the button.