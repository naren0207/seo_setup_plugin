<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check if a directory is writable, attempt to fix permissions if not.
 * Used by sitemap/robots generators before writing files.
 *
 * @param string $dir_path  Directory path to check.
 * @return bool  True if writable, false otherwise.
 */
function seo_setup_ensure_directory_writable( $dir_path ) {
    if ( is_writable( $dir_path ) ) {
        return true;
    }

    // Try to fix permissions
    @chmod( $dir_path, 0755 );

    return is_writable( $dir_path );
}

/**
 * Check if the uploads directory is available.
 * IMPORTANT: This does NOT call wp_upload_dir() because that function
 * itself tries to create the directory and triggers the WordPress error.
 * Instead, we just check if the base uploads dir exists and is writable.
 *
 * @return bool True if uploads should work, false if there's a problem.
 */
function seo_setup_can_use_uploads() {
    $upload_basedir = WP_CONTENT_DIR . '/uploads';

    // If base uploads dir doesn't exist, try to create it
    if ( ! file_exists( $upload_basedir ) ) {
        if ( ! @wp_mkdir_p( $upload_basedir ) ) {
            return false;
        }
    }

    return is_writable( $upload_basedir );
}

function seo_setup_get_logo_url() {
    $cached_logo = get_transient('seo_setup_logo_url');
    if ($cached_logo !== false) {
        return $cached_logo;
    }

    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
        if ($logo) {
            $logo_url = $logo[0];
            set_transient('seo_setup_logo_url', $logo_url, DAY_IN_SECONDS);
            return $logo_url;
        }
    }

    $response = wp_remote_get(home_url(), ['timeout' => 5, 'sslverify' => false]);
    if (is_wp_error($response)) {
        set_transient('seo_setup_logo_url', '', HOUR_IN_SECONDS * 6);
        return '';
    }

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
        set_transient('seo_setup_logo_url', '', HOUR_IN_SECONDS * 6);
        return '';
    }

    if (preg_match('/<header.*?<\/header>/is', $html, $header_match)) {
        $header_html = $header_match[0];

        // Step 1: Fallback from base64 src to data-src, data-lazy-src, etc.
        if (preg_match_all('/<img[^>]+>/i', $header_html, $imgs)) {
            foreach ($imgs[0] as $img_tag) {
                if (preg_match('/src=["\']data:image\/[^"\']+["\']/i', $img_tag)) {
                    if (preg_match('/data-src=["\']([^"\']+)["\']/i', $img_tag, $m)) {
                        $logo_url = $m[1];
                        set_transient('seo_setup_logo_url', $logo_url, DAY_IN_SECONDS);
                        return $logo_url;
                    } elseif (preg_match('/data-lazy-src=["\']([^"\']+)["\']/i', $img_tag, $m)) {
                        $logo_url = $m[1];
                        set_transient('seo_setup_logo_url', $logo_url, DAY_IN_SECONDS);
                        return $logo_url;
                    } elseif (preg_match('/data-original=["\']([^"\']+)["\']/i', $img_tag, $m)) {
                        $logo_url = $m[1];
                        set_transient('seo_setup_logo_url', $logo_url, DAY_IN_SECONDS);
                        return $logo_url;
                    } elseif (preg_match('/data-lazy=["\']([^"\']+)["\']/i', $img_tag, $m)) {
                        $logo_url = $m[1];
                        set_transient('seo_setup_logo_url', $logo_url, DAY_IN_SECONDS);
                        return $logo_url;
                    }
                }
            }
        }

        // Step 2: Normalize lazy-load attributes to src for fallback match
        $header_html = str_replace(
            ['data-src', 'data-lazy-src', 'data-original', 'data-lazy'],
            'src',
            $header_html
        );

        // Step 3: Match <img> with class or ID containing "logo"
        if (preg_match('/<img[^>]+(class|id)=["\'][^"\']*logo[^"\']*["\'][^>]+src=["\']([^"\']+)["\']/i', $header_html, $m)) {
            $logo_url = $m[2];
            set_transient('seo_setup_logo_url', $logo_url, DAY_IN_SECONDS);
            return $logo_url;
        }

        // Step 4: Logo inside <a href="{home_url}">
        $pattern = '/<a[^>]+href=["\']' . preg_quote(home_url(), '/') . '["\'][^>]*>.*?<img[^>]+src=["\']([^"\']+)["\']/is';
        if (preg_match($pattern, $header_html, $m)) {
            $logo_url = $m[1];
            set_transient('seo_setup_logo_url', $logo_url, DAY_IN_SECONDS);
            return $logo_url;
        }

        // Step 5: Find <img> with width/height > 30 and non-base64 src
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*(width|height)=["\']?(\d+)/i', $header_html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $src = $match[1];
                $size = (int) $match[3];
                if (stripos($src, 'data:image') === false && $size > 30) {
                    $logo_url = $src;
                    set_transient('seo_setup_logo_url', $logo_url, DAY_IN_SECONDS);
                    return $logo_url;
                }
            }
        }

        // Step 6: Any other <img> with a non-base64 src
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $header_html, $m)) {
            foreach ($m[1] as $src) {
                if (stripos($src, 'data:image') === false) {
                    $logo_url = $src;
                    set_transient('seo_setup_logo_url', $logo_url, DAY_IN_SECONDS);
                    return $logo_url;
                }
            }
        }
    }

    set_transient('seo_setup_logo_url', '', HOUR_IN_SECONDS * 6);
    return '';
}

//Dom Loader

function seo_setup_safe_dom_load($html) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    libxml_clear_errors();
    return $dom;
}

/**
 * Fetch a remote image and return it as base64-encoded JPEG data, resized to
 * fit within 512x512. Used for vision API calls (alt text generation and
 * alt text analysis).
 *
 * @param string $img_url
 * @return array|false  ['base64' => ..., 'mime' => 'image/jpeg'] or false on failure.
 */
function seo_setup_fetch_and_resize_image_base64( $img_url ) {
    $image_response = wp_remote_get( $img_url );
    if ( is_wp_error( $image_response ) || empty( $image_response['body'] ) ) {
        return false;
    }

    // FIXED: wp_tempnam() lives in wp-admin/includes/file.php, which is not
    // guaranteed to be loaded on the REST API request path (only reliably
    // auto-loaded for true wp-admin/admin-ajax.php requests).
    if ( ! function_exists( 'wp_tempnam' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $tmp_path = wp_tempnam( $img_url );
    if ( ! $tmp_path ) {
        return false;
    }

    file_put_contents( $tmp_path, $image_response['body'] );
    $mime = mime_content_type( $tmp_path );

    $create_func = array(
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    );

    if ( empty( $create_func[ $mime ] ) || ! function_exists( $create_func[ $mime ] ) ) {
        @unlink( $tmp_path );
        return false;
    }

    $img = @$create_func[ $mime ]( $tmp_path );
    @unlink( $tmp_path );
    if ( ! $img ) {
        return false;
    }

    $width  = imagesx( $img );
    $height = imagesy( $img );
    $scale  = min( 512 / $width, 512 / $height, 1 );

    $new_w = (int) ( $width * $scale );
    $new_h = (int) ( $height * $scale );

    $resized = imagecreatetruecolor( $new_w, $new_h );
    imagecopyresampled( $resized, $img, 0, 0, 0, 0, $new_w, $new_h, $width, $height );

    ob_start();
    imagejpeg( $resized, null, 85 );
    $base64 = base64_encode( ob_get_clean() );

    imagedestroy( $img );
    imagedestroy( $resized );

    return array(
        'base64' => $base64,
        'mime'   => 'image/jpeg',
    );
}
