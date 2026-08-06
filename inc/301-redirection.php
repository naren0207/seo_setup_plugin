<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 

function seo_setup_301_redirection_ajax() {
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
 
    
    $source_url = isset( $_POST['source_url'] ) ? esc_url_raw( $_POST['source_url'] ) : '';
    $target_url = get_site_url($source_url);

    // $src_response = wp_remote_head($source_url."/sitemap.xml");
    // $src_status_code = wp_remote_retrieve_response_code($src_response);

    // $trg_response = wp_remote_head($target_url."/sitemap.xml");

    $sitemap_source_urls = get_all_slugs($source_url , $source_url."/sitemap.xml");
    $sitemap_target_urls = get_all_slugs($target_url , $target_url."/sitemap.xml");
    // $trg_status_code = wp_remote_retrieve_response_code($trg_response);

    // if($src_status_code == 200){
    //     $sitemap_source_urls = get_slugs_from_sitemap($source_url."/sitemap.xml");
    // }else{
    //     $sitemap_source_urls = get_slugs_from_sitemap($source_url."/page-sitemap.xml");
    //     $sitemap_source_urls1 = get_slugs_from_sitemap($source_url."/post-sitemap.xml");
    //     $sitemap_source_urls2 = array_merge($sitemap_source_urls,$sitemap_source_urls1);
    // }

    // if($trg_status_code == 200){
    //     $sitemap_target_urls = get_slugs_from_sitemap($target_url."/sitemap.xml"); 
    // }else{
    //     $sitemap_target_urls = get_slugs_from_sitemap($target_url."/page-sitemap.xml");
    // }
 
    $threshold = 60;

    // echo "<pre>";print_r($sitemap_source_urls);print_r($sitemap_target_urls);

    $matched_array = find_similar_words($sitemap_source_urls, $sitemap_target_urls, $threshold);

    $temp_url = [];
    $i = 0;
    foreach($sitemap_source_urls as $source_url_key => $source_url_val){
        foreach($matched_array as $m_url_key => $m_url_val){ 
            if($source_url_val == $m_url_val['word1']){
                $temp_url[$i]['source'] = $source_url_val;
                $temp_url[$i]['target'] = $m_url_val['word2']; 
            }else{
                $temp_url[$i]['source'] = $source_url_val;
                $temp_url[$i]['target'] = '/'; 
            }
            $i++;
        }
    } 
    
    $main_array = array_map("unserialize", array_unique(array_map("serialize", $temp_url)));

    if ( !empty( $main_array ) ) {
        wp_send_json_success( array(
            'message'       => 'Get all similar URLs successfully',
            'main_array' => json_encode($main_array) 
        ) );
    } else {
        wp_send_json_error( 'Failed to get similar URLs' );
    }
} 
add_action( 'wp_ajax_seo_setup_301_redirection_ajax', 'seo_setup_301_redirection_ajax' );

// Function to get all internal links from a website URL
function get_all_page_urls($website_url) {
    $urls = [];
    
    $html_content = file_get_contents($website_url);
    
    if (!$html_content) { 
        return "Unable to retrieve content from the URL."; 
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);  
    $dom->loadHTML($html_content);
    libxml_clear_errors();

    $anchor_tags = $dom->getElementsByTagName('a');
    
    foreach ($anchor_tags as $tag) {
        $href = $tag->getAttribute('href');
        
        if (filter_var($href, FILTER_VALIDATE_URL)) {
            $parsed_url = parse_url($href);
            
            if (isset($parsed_url['host']) && strpos($parsed_url['host'], parse_url($website_url, PHP_URL_HOST)) !== false) {
                if (isset($parsed_url['path']) ) {
                    $url = ltrim($parsed_url['path'], '/');
                    if ($url && !in_array($url, $urls) && !preg_match('/\.(jpg|jpeg|png|gif|bmp|pdf|mp4|mov|webm)$/i', $parsed_url['path'])) {
                        $urls[] = $url;  
                    }
                }
            }
        }
    }
    
    return array_unique($urls);
}

// Combined function to check for sitemap and fall back to scraping header
function get_all_slugs($site_url, $sitemap_url) {
    $slugs = [];
    
    if (@simplexml_load_file($sitemap_url)) {
        $slugs = get_slugs_from_sitemap($sitemap_url);
    } else {
        $slugs = get_all_page_urls($site_url);
    }

    return $slugs;
}

// Function to get slugs from sitemap.xml (if available)
function get_slugs_from_sitemap($sitemap_url) {
    $sitemap = simplexml_load_file($sitemap_url);
    $slugs = [];

    foreach ($sitemap->url as $url) {
        $slug = parse_url((string) $url->loc, PHP_URL_PATH); 
        $slug = trim($slug, '/'); 
        $slugs[] = $slug; 
    }

    return $slugs;
}

// Function to find similar words
function find_similar_words($source, $target, $threshold) { 

    $matched_array = [];

    foreach ($source as $word1) {
        $best_match = null;  
        $highest_similarity = 0;  

        foreach ($target as $word2) {
            similar_text($word1, $word2, $percent);

            if ($percent > $highest_similarity && $percent >= $threshold) {
                $best_match = $word2;
                $highest_similarity = $percent;
            }
        }

        if ($best_match !== null) {
            $matched_array[] = [
                'word1' => $word1,
                'word2' => $best_match,
                'similarity' => $highest_similarity 
            ];
        }
    }

    return $matched_array;
}



function seo_setup_301_redirection_save_ajax() {
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

    // FIXED: Removed debug code: echo "<pre>";print_R($_POST);
    // TODO: Implement actual save logic for 301 redirections
    wp_send_json_success( array( 'message' => 'Redirections saved.' ) );
}
add_action( 'wp_ajax_seo_setup_301_redirection_save_ajax', 'seo_setup_301_redirection_save_ajax' ); 

// Function to get slugs from a sitemap XML file
// function get_slugs_from_sitemap1($sitemap_url) {
//     // Fetch the sitemap content
    
//     $sitemap_content = file_get_contents($sitemap_url);

//     if (!$sitemap_content) {
//         return "Unable to retrieve the sitemap.";
//     }

//     // Load the XML content
//     $xml = simplexml_load_string($sitemap_content);

//     if (!$xml) {
//         return "Error loading XML from sitemap.";
//     }

//     // Create an array to store the slugs
//     $slugs = [];

//     // Loop through each <url> element and extract the <loc> tag
//     foreach ($xml->url as $url) {
//         // Get the full URL from the <loc> tag
//         $full_url = (string)$url->loc;

//         // Parse the URL and get the path (slug) 
//         $parsed_url = parse_url($full_url);

//         // Extract the slug (path) and remove leading '/' if exists
//         $slug = ltrim($parsed_url['path'], '/');
        
//         if($slug){
//             // Add the slug to the array
//             $slugs[] = $slug;
//         }
       
//     }

//     return $slugs; // Return the list of slugs
// }