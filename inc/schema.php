<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include Composer's autoloader if needed.
$seo_setup_autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $seo_setup_autoload ) ) {
    require_once $seo_setup_autoload;
} else {
    // Vendor directory missing - log error but don't crash the plugin
    error_log( '[SEO Setup] Composer vendor/autoload.php not found. Schema address parsing features will be limited.' );
}

use CommerceGuys\Addressing\Address;
use CommerceGuys\Addressing\Country\CountryRepository;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;

/**
 * "get_page_by_title()" is deprecated in WP 6.2.
 */
function seo_setup_compat_get_page_by_title( $title, $output = OBJECT, $post_type = 'page' ) {
    global $wpdb;

    $post = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $wpdb->posts
             WHERE post_title = %s
               AND post_type = %s
               AND post_status IN ('publish','draft','pending','private','future')
             ORDER BY ID ASC
             LIMIT 1",
            $title,
            $post_type
        )
    );

    if ( $post ) {
        return get_post( $post, $output );
    }
    return null;
}


// Phone, Email, Social – with fallback logic

function seo_setup_get_phone() {
    $admin_phone = get_option( 'seo_setup_contact_phone', '' );
    if ( ! empty( $admin_phone ) ) {
        return $admin_phone;
    }
    return seo_setup_extract_phone_from_site();
}

function seo_setup_extract_phone_from_site() {
    $phone         = '';
    $homepage_html = wp_remote_retrieve_body( wp_remote_get( home_url() ) );


    $check_tel_anchor = function( $html ) {
        if ( preg_match( '/<a[^>]+href=["\']tel:([^"\']+)["\']/i', $html, $match ) ) {
            return trim( $match[1] );
        }
        return '';
    };

    // header
    if ( preg_match_all( '/<header[^>]*>(.*?)<\/header>/is', $homepage_html, $headers ) ) {
        foreach ( $headers[1] as $block ) {
            $phone = $check_tel_anchor( $block );
            if ( $phone ) break;
        }
    }

    // Elementor header
    if ( empty( $phone ) ) {
        $pattern = '/<div[^>]+data-elementor-type=["\']header["\'][^>]*class=["\'][^"\']*elementor-location-header[^"\']*["\'][^>]*>(.*?)<\/div>/is';
        if ( preg_match_all( $pattern, $homepage_html, $elementor_blocks ) ) {
            foreach ( $elementor_blocks[1] as $block ) {
                $phone = $check_tel_anchor( $block );
                if ( $phone ) break;
            }
        }
    }

    // footer
    if ( empty( $phone ) && preg_match_all( '/<footer[^>]*>(.*?)<\/footer>/is', $homepage_html, $footers ) ) {
        foreach ( $footers[1] as $block ) {
            $phone = $check_tel_anchor( $block );
            if ( $phone ) break;
        }
    }

    // contact page
    if ( empty( $phone ) ) {
        $contact_page = seo_setup_compat_get_page_by_title( 'Contact' ) ?: get_page_by_path( 'contact' );
        if ( $contact_page ) {
            $contact_url = get_permalink( $contact_page->ID );
    
            $contact_html = wp_remote_retrieve_body( wp_remote_get( $contact_url ) );
            if ( ! empty( $contact_html ) ) {
                $phone = $check_tel_anchor( $contact_html );
            }
    
            if ( empty( $phone ) ) {
                $rendered_content = apply_filters( 'the_content', $contact_page->post_content );
                $phone = $check_tel_anchor( $rendered_content );
            }
        }
    }

    return $phone;
}

function seo_setup_cached_get_phone() {
    $cached = get_transient('seo_setup_cached_phone');
    if ($cached !== false) return $cached;

    $phone = seo_setup_get_phone();
    set_transient('seo_setup_cached_phone', $phone, WEEK_IN_SECONDS);
    return $phone;
}

function seo_setup_get_email() {
    
    $homepage_html = wp_remote_retrieve_body(wp_remote_get(home_url()));

    $extract_email = function($html) {

        $blacklist_domains = ['example.com', 'test.com', 'doe.com', 'demo.com', 'yourdomain.com'];
        $found_emails = [];

        if (preg_match_all('/<a[^>]+href=["\']mailto:([^"\'>\s]+)["\']/i', $html, $mailto_matches)) {
            foreach ($mailto_matches[1] as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $domain = strtolower(substr(strrchr($email, "@"), 1));
                    if (!in_array($domain, $blacklist_domains)) {
                        $found_emails[] = $email;
                    }
                }
            }
        }

        $html_without_inputs = preg_replace('/<input\b[^>]*>/i', '', $html);

        if (preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[A-Za-z]{2,}/', $html_without_inputs, $plain_matches)) {
            foreach ($plain_matches[0] as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $domain = strtolower(substr(strrchr($email, "@"), 1));
                    if (!in_array($domain, $blacklist_domains)) {
                        $found_emails[] = $email;
                    }
                }
            }
        }

        return !empty($found_emails) ? $found_emails[0] : '';
    };

    $sections_to_check = [];

    if (preg_match_all('/<header[^>]*>(.*?)<\/header>/is', $homepage_html, $headers)) {
        $sections_to_check = array_merge($sections_to_check, $headers[1]);
    }

    if (preg_match_all('/<div[^>]+data-elementor-type=["\']header["\'][^>]*class=["\'][^"\']*elementor-location-header[^"\']*["\'][^>]*>(.*?)<\/div>/is', $homepage_html, $elementor_blocks)) {
        $sections_to_check = array_merge($sections_to_check, $elementor_blocks[1]);
    }

    if (preg_match_all('/<footer[^>]*>(.*?)<\/footer>/is', $homepage_html, $footers)) {
        $sections_to_check = array_merge($sections_to_check, $footers[1]);
    }

    foreach ($sections_to_check as $html_chunk) {
        $email = $extract_email($html_chunk);
        if (!empty($email)) return $email;
    }

    $contact_page = seo_setup_compat_get_page_by_title('Contact') ?: get_page_by_path('contact');
    if ($contact_page) {
        $contact_url = get_permalink($contact_page->ID);
        $contact_html = wp_remote_retrieve_body(wp_remote_get($contact_url));
        if (!empty($contact_html)) {
            $email = $extract_email($contact_html);
            if (!empty($email)) return $email;
        }

        $rendered_content = apply_filters('the_content', $contact_page->post_content);
        $email = $extract_email($rendered_content);
        if (!empty($email)) return $email;
    }

    $admin_email = get_option('seo_setup_contact_email', '');
    if (!empty($admin_email) && filter_var($admin_email, FILTER_VALIDATE_EMAIL)) return $admin_email;

    $blog_email = get_bloginfo('admin_email');
    return filter_var($blog_email, FILTER_VALIDATE_EMAIL) ? $blog_email : '';
}


function seo_setup_get_social_profiles() {
    // Admin option override
    $admin_social = get_option( 'seo_setup_social_profiles', [] );
    if ( ! empty( $admin_social ) ) {
        return $admin_social;
    }

    $all_links = [];

    $home_links = seo_setup_extract_social_links( home_url() );
    $all_links  = array_merge( $all_links, $home_links );

    // Fallback 1: homepage
    $homepage_html = wp_remote_retrieve_body( wp_remote_get( home_url() ) );
   
    //error_log( 'Home Page HTML: homepage_html length = ...');
    
    if ( preg_match_all( '/<header[^>]*>(.*?)<\/header>/is', $homepage_html, $header_matches ) ) {
        // ... any debug ...
        foreach ( $header_matches[1] as $header_html ) {
            $header_links = seo_setup_find_social_links_in_html( $header_html );
            $all_links = array_merge( $all_links, $header_links );
        }
    }

    $pattern = '/<div[^>]+data-elementor-type=["\']header["\'][^>]*class=["\'][^"\']*elementor-location-header[^"\']*["\'][^>]*>(.*?)<\/div>/is';
    if ( preg_match_all( $pattern, $homepage_html, $elem_header_blocks ) ) {
        foreach ( $elem_header_blocks[1] as $div_html ) {
            // Remove <svg> from the block
            $div_html = preg_replace( '/<svg\b[^>]*>.*?<\/svg>/is', '', $div_html );
            // Then parse anchor links
            $div_links = seo_setup_find_social_links_in_html( $div_html );
            $all_links = array_merge( $all_links, $div_links );
        }
    }

    if ( preg_match_all( '/<footer[^>]*>(.*?)<\/footer>/is', $homepage_html, $footers ) ) {
        foreach ( $footers[1] as $footer_html ) {
            $footer_links = seo_setup_find_social_links_in_html( $footer_html );
            $all_links = array_merge( $all_links, $footer_links );
        }
    }

    // Fallback 2: contact page
    $contact_page = seo_setup_compat_get_page_by_title( 'Contact' );
    if ( ! $contact_page ) {
        $contact_page = get_page_by_path( 'contact' );
    }
    if ( $contact_page ) {
        $contact_url = get_permalink( $contact_page->ID );
        if ( $contact_url ) {
            $contact_rendered_links = seo_setup_extract_social_links( $contact_url );
            $all_links = array_merge( $all_links, $contact_rendered_links );
        }
        // raw post_content
        $contact_raw = $contact_page->post_content;
        $content_links = seo_setup_find_social_links_in_html( $contact_raw );
        $all_links = array_merge( $all_links, $content_links );
    }

    if ( empty($all_links) && ! empty($contact_page) ) {
        $raw_content_links = seo_setup_find_social_links_in_html($contact_page->post_content);
        $all_links = array_merge( $all_links, $raw_content_links );
    }

    if ( empty( $all_links ) ) {
        return [];
    }
    return array_values( array_unique( $all_links ) );
}

function seo_setup_extract_social_links( $page_url ) {
    $html = wp_remote_retrieve_body( wp_remote_get( $page_url ) );
    if ( ! $html ) {
        return [];
    }
    return seo_setup_find_social_links_in_html( $html );
}

function seo_setup_find_social_links_in_html( $html ) {
    if ( empty( $html ) ) {
        return [];
    }
    // updated domain pattern to include x.com
    $pattern = '/href\s*=\s*[\'"]\s*(https?:\/\/(?:www\.)?(?:facebook\.com|twitter\.com|x\.com|instagram\.com|linkedin\.com|youtube\.com|pinterest\.com|tiktok\.com|reddit\.com|tumblr\.com)\/[^\s"\'<]+)\s*[\'"]/i';
    preg_match_all($pattern, $html, $matches);
    if ( empty($matches[1]) ) {
        return [];
    }
    return $matches[1];
}

function seo_setup_get_raw_address_block() {
    $admin_addr = get_option('seo_setup_address', '');
    if (!empty($admin_addr)) return array_unique([trim($admin_addr)]);

    $homepage_html = wp_remote_retrieve_body(wp_remote_get(home_url()));
    $header_html = '';
    $footer_html = '';

    if (preg_match('/<header.*?<\/header>/is', $homepage_html, $hm)) {
        $header_html = $hm[0];
    }
    if (preg_match('/<footer.*?<\/footer>/is', $homepage_html, $fm)) {
        $footer_html = $fm[0];
    }

    $combined_html = $header_html . $footer_html;

    if (!empty($combined_html)) {
        error_log('[SEO Setup] Sending header/footer HTML to OpenAI. Length: ' . strlen($combined_html));
        $ai_addresses = seo_setup_extract_address_via_openai($combined_html);
        error_log('[SEO Setup] AI Header/Footer Response: ' . print_r($ai_addresses, true));

        if (!empty($ai_addresses)) {
            return $ai_addresses;
        }
    }

    $contact_page = seo_setup_compat_get_page_by_title('Contact') ?: get_page_by_path('contact');
    if ($contact_page) {
        $contact_html = wp_remote_retrieve_body(wp_remote_get(get_permalink($contact_page->ID)));
        if (!empty($contact_html)) {
            error_log('[SEO Setup] Sending contact page HTML to OpenAI. Length: ' . strlen($contact_html));
            $ai_contact_addresses = seo_setup_extract_address_via_openai($contact_html);
            error_log('[SEO Setup] AI Contact Page Response: ' . print_r($ai_contact_addresses, true));

            if (!empty($ai_contact_addresses)) {
                return $ai_contact_addresses;
            }
        }
    }

    return [];
}

/*--------------------------------------------------------------
  3) Parsing address block (array or string) into structured parts
--------------------------------------------------------------*/

function seo_setup_get_address_data() {
    $raw_addresses = seo_setup_get_raw_address_block();

    error_log("=== [SEO Setup] Raw Address Lines ===\n" . print_r($raw_addresses, true));

    $filtered_addresses = array_filter($raw_addresses, function($line) {
        return !empty($line) && !preg_match('/(©|copyright|designed by|all rights reserved)/i', $line);
    });

    if (empty($filtered_addresses)) {
        error_log('[SEO Setup] No address lines found after filtering.');
        return [];
    }

    $grouped_addresses = array_map(fn($line) => [$line], array_values($filtered_addresses));

    error_log("=== [SEO Setup] Final Address Group for Schema ===\n" . print_r($grouped_addresses, true));

    $addresses = [];
    foreach ($grouped_addresses as $group) {
        $addresses[] = [
            '@type' => 'PostalAddress',
            'streetAddress' => implode(', ', array_map('trim', $group))
        ];
    }

    return $addresses;
}

function seo_setup_extract_address_via_openai($html) {
    if (empty($html)) return [];

    $credentials = seo_setup_get_api_credentials_by_product('OPEN AI');
    if (!$credentials || empty($credentials['key']) || empty($credentials['endpoint'])) return [];

    $api_key = $credentials['key'];
    $api_url = $credentials['endpoint'];
    $model = 'gpt-5-mini';

    $html_text = wp_strip_all_tags($html);
    $html_text = trim(preg_replace('/\s+/', ' ', $html_text));

    $prompt = "You are an address detection assistant. Your task is to extract only physical mailing addresses from the HTML content of a website. Ignore social links, email addresses, phone numbers, or opening hours. Return only a valid JSON array of address strings.\n\nHTML Content:\n{$html_text}";

    error_log("=== [SEO Setup] OpenAI Prompt Sent ===\n" . $prompt);

    $request_data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful address extraction assistant.'],
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $headers = [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
    ];

    $response = wp_remote_post($api_url, [
        'headers' => $headers,
        'body' => wp_json_encode($request_data),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        error_log('[SEO Setup] OpenAI Error: ' . $response->get_error_message());
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    $raw_output = $json['choices'][0]['message']['content'] ?? '';

    error_log("=== [SEO Setup] OpenAI Raw Output ===\n" . $raw_output);

    $raw_output = trim($raw_output);
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw_output, $matches)) {
        $raw_output = $matches[1];
    }

    $addresses = json_decode($raw_output, true);
    error_log("=== [SEO Setup] Parsed Address Lines from OpenAI ===\n" . print_r($addresses, true));

    if (is_array($addresses)) {
        return array_filter(array_map('trim', $addresses));
    }

    error_log('[SEO Setup] OpenAI returned invalid JSON');
    return [];
}

function seo_setup_parse_address( $raw ) {
    $out = [
        'address_line1'       => '',
        'locality'            => '',
        'administrative_area' => '',
        'postal_code'         => '',
        'country_code'        => 'US',
    ];

    if ( empty( $raw ) ) {
        return $out;
    }

    $address_string = is_array( $raw ) ? implode(', ', $raw) : $raw;

    $out['address_line1'] = trim( $address_string );

    $countryRepo = new CountryRepository();
    foreach ( $countryRepo->getAll() as $code => $country ) {
        if ( stripos( $address_string, $country->getName() ) !== false ) {
            $out['country_code'] = $code;
            break;
        }
    }

    return $out;
}

function seo_setup_guess_country_code( $countryName ) {
    $countryRepo = new CountryRepository();
    $found       = 'US'; 
    if ( preg_match( '/(uk|united\s?kingdom)/i', $countryName ) ) {
        return 'GB';
    }
    foreach ( $countryRepo->getAll() as $c => $obj ) {
        if ( strcasecmp( $obj->getName(), $countryName ) === 0 ) {
            return $c;
        }
    }
    return $found;
}

// Format phone with libphonenumber

function seo_setup_format_phone_number( $raw_phone, $region = 'US' ) {
    $phoneUtil = PhoneNumberUtil::getInstance();
    try {
        $phoneProto = $phoneUtil->parse( $raw_phone, $region );
        if ( $phoneUtil->isValidNumber( $phoneProto ) ) {
            return $phoneUtil->format( $phoneProto, PhoneNumberFormat::E164 );
        }
    } catch ( NumberParseException $e ) {
        // ignore
    }
    return $raw_phone;
}

// 5) Gathering data for Organization / LocalBusiness

function seo_setup_get_organization_data() {
    $data = [];

    $data['organization_name'] = get_bloginfo( 'name' );
    $data['organization_url']  = home_url();

    $data['organization_logo'] = esc_url_raw(seo_setup_get_logo_url());

    // phone
    $raw_phone = seo_setup_get_phone();
    $data['contact_telephone'] = seo_setup_format_phone_number( $raw_phone );
    $data['business_email'] = seo_setup_get_email();
    $data['contact_type']      = 'Customer Support';

    // address
    $data['address'] = get_option( 'seo_setup_address', '' ); // not strictly used except for reference
    // FIXED: Removed duplicate call to seo_setup_get_address_data()
    $parsed_addrs = seo_setup_get_address_data();
    $first_address_raw = isset($parsed_addrs[0]['streetAddress']) ? $parsed_addrs[0]['streetAddress'] : '';
    $parsed_fields = seo_setup_parse_address( $first_address_raw );
    $data['contact_area_served'] = $parsed_fields['locality'];

    // language
    $data['contact_language'] = get_bloginfo( 'language' );

    // social
    $data['social_profiles'] = seo_setup_get_social_profiles();

    return $data;
}


// function to extract business hours from text content
function seo_setup_extract_opening_hours_from_text($text, $return_structured = false) {
    
    // First, check if the text contains line breaks, which might indicate a structured format
    if (strpos($text, '<br>') !== false || strpos($text, '<br/>') !== false || strpos($text, '<br />') !== false) {
        // Convert various line break formats to a standard format
        $text = str_replace(['<br/>', '<br />'], '<br>', $text);
        
        // Split by <br> tags to get each line
        $lines = explode('<br>', $text);
        
        // Clean each line and collect valid business hour lines
        $hour_lines = [];
        foreach ($lines as $line) {
            $line = trim(strip_tags($line));
            if (empty($line)) continue;
            
            // Check if line resembles business hours (contains day name and time)
            if (preg_match('/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b.*?(?:\d{1,2}:\d{2}|Closed)/i', $line)) {
                $hour_lines[] = $line;
            }
        }
        
        // If we found valid business hour lines, combine them
        if (count($hour_lines) >= 2) {
            return implode(', ', $hour_lines);
        }
    }
    
    // If the above structured approach didn't work, continue with the original function logic
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = str_replace(['–', '—'], '-', $text); 
    
    // For "Mon - Fri: 9:00 AM - 5:00 PM, Sat & Sun: Closed" pattern
    if (preg_match('/mon\s*-\s*fri\s*:\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?)/i', $text, $weekday_match) &&
        (preg_match('/sat\s*(?:&|and)\s*sun\s*:\s*closed/i', $text) || 
         preg_match('/sat\s*:\s*closed/i', $text))) {
        
        return "Mon - Fri: " . $weekday_match[1] . ", Sat & Sun: Closed";
    }
    
    // Mon-Fri hours and Sun: Closed but no Saturday
    if (preg_match('/mon\s*-\s*fri\s*:\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?)/i', $text, $weekday_match) &&
        preg_match('/sun\s*:\s*closed/i', $text) && 
        !preg_match('/sat\s*:/i', $text)) {
        
        return "Mon - Fri: " . $weekday_match[1] . ", Sat & Sun: Closed";
    }
    
    // Initialize days arrays for structured return
    $days_map = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];
    
    $structured_hours = [];
    foreach ($days_map as $short => $full) {
        $structured_hours[$full] = [];
    }
    
    // Detect if the entire business is open 24/7
    if (preg_match('/\b(open\s+24\/7|open\s+24\s+hours|always\s+open)\b/i', $text)) {
        if ($return_structured) {
            foreach ($days_map as $full) {
                $structured_hours[$full][] = '24 hours';
            }
            return $structured_hours;
        }
        return 'Open 24/7';
    }
    
    // Patterns for business hours
    $patterns = [
        // 12-hour format
        '/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:day)?(?:\s*[-&,to]+\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:day)?)?:?\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?|Closed|Open 24\/7|24 hours)/i',
        
        // Full day name with time
        '/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday):?\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?|Closed|Open 24\/7|24 hours)/i',
        
        // Group days (weekday/weekend)
        '/\b(Weekdays|Weekends|Daily):?\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?|Closed)/i',
        
        // 24-hour format
        '/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?(?:\s*[-&,to]+\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?|\s*(?:&|and)\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?)?:?\s*(\d{1,2}:\d{2})\s*[-–to]+\s*(\d{1,2}:\d{2})/i',
        
        // Multiple time blocks per day
        '/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?:?\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?),\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?)/i',
        
        // Hours label followed by day/time
        '/\bHours:\s*((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)[^\n]+)/i',
        
        // Special times
        '/\b(By appointment only|Call for hours|Hours vary|Hours by appointment)\b/i',
        
        // Comma-separated day lists
        '/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?,\s*((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?(?:,\s*(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?)*):?\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?|Closed|Open 24\/7|24 hours)/i',
        
        // Seasonal hours
        '/\b(Summer|Winter|Holiday|Seasonal)\s+hours:?\s*([^\.]+)/i',
        
        // Days followed by multiple times separated by "&" or "and"
        '/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?:?\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?)(?:\s*(?:&|and)\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?))/i',
        
        // Day ranges or groups with "Closed" status (IMPROVED)
        '/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?(?:\s*[-&,to]+\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?|\s*(?:&|and)\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?)?:?\s*(Closed)\b/i',
        
        // Multiple days with ampersand and colon followed by status (IMPROVED)
        '/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?\s*(?:&|and)\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:day)?:?\s*(Closed|Open 24\/7|24 hours|\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?)/i',
        
        // SPECIFIC PATTERN: Sat & Sun: Closed
        '/\b(Sat|Saturday)\s*(?:&|and)\s*(Sun|Sunday):?\s*(Closed)\b/i'
    ];
    
    $results = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (isset($match[0]) && strlen($match[0]) > 5) {
                    $entry = trim($match[0]);
                    
                    if ($return_structured) {
                        process_match_to_structured($match, $structured_hours, $days_map);
                    }
                    
                    $results[] = $entry;
                }
            }
        }
    }
    
    // Handle special case of "By appointment only"
    if (empty($results) && preg_match('/\b(by appointment|appointment only|call ahead|call for hours)\b/i', $text)) {
        return "By appointment only";
    }
    
    if ($return_structured) {
        process_grouped_days($text, $structured_hours);
        return array_filter($structured_hours);
    }
    
    // Remove unwanted substrings (e.g. extraneous labels or social links) and then choose the best match.
    $unwanted_keywords = ['Hours:', 'Facebook', 'Twitter', 'X-twitter', 'Youtube', 'Linkedin', 'Instagram'];
    $cleaned_segments = [];
    foreach ($results as $segment) {
        $cleaned = $segment;
        // Remove all unwanted keywords
        foreach ($unwanted_keywords as $keyword) {
            $cleaned = str_replace($keyword, '', $cleaned);
        }
        $cleaned = trim($cleaned);
        // Insert a comma before "Sun:" if missing (e.g. "PMSun:" becomes "PM, Sun:")
        $cleaned = preg_replace('/(AM|PM)(\s*)(Sun:)/i', '$1, $3', $cleaned);
        if (!empty($cleaned)) {
            $cleaned_segments[] = $cleaned;
        }
    }
    // If multiple cleaned segments exist, choose the one that is the longest (assumed to be the most complete)
    if (!empty($cleaned_segments)) {
        usort($cleaned_segments, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        $output = $cleaned_segments[0];
    } else {
        $output = '';
    }
    // -------------------------------------------
    
    // POST-PROCESSING CHECKS
    // Check if we have Mon-Fri and Sun: Closed but no Sat mentioned; then add it.
    $has_weekday_hours = false;
    $has_sunday_closed = false;
    $has_saturday = false;
    
    foreach ($results as $result) {
        if (preg_match('/mon\s*-\s*fri/i', $result)) {
            $has_weekday_hours = true;
        }
        if (preg_match('/\bsun\s*:\s*closed\b/i', $result)) {
            $has_sunday_closed = true;
        }
        if (preg_match('/\bsat\b/i', $result)) {
            $has_saturday = true;
        }
    }
    
    if ($has_weekday_hours && $has_sunday_closed && !$has_saturday) {
        $processed_results = [];
        foreach ($results as $result) {
            if (preg_match('/\bsun\s*:\s*closed\b/i', $result)) {
                $processed_results[] = "Sat & Sun: Closed";
            } else {
                $processed_results[] = $result;
            }
        }
        $output = implode(', ', array_unique(array_map('trim', $processed_results)));
    }
    
    // FINAL CHECK: Specific case replacement
    if (strpos($output, 'Mon - Fri: 9:00 AM - 5:00 PM') !== false && 
        strpos($output, 'Sun: Closed') !== false && 
        strpos($output, 'Sat') === false) {
        $output = str_replace('Sun: Closed', 'Sat & Sun: Closed', $output);
    }
    
    return $output;
}


/**
 * Helper function to process matches into the structured array
 */
function process_match_to_structured($match, &$structured_hours, $days_map) {
    // Skip if no day information
    if (!isset($match[1])) {
        return;
    }
    
    // Normalize day name
    $day_text = strtolower(substr($match[1], 0, 3));
    
    // Handle day ranges like Mon-Fri
    if (isset($match[2]) && !empty($match[2])) {
        $day_range = true;
        $start_day = $day_text;
        $end_day = strtolower(substr($match[2], 0, 3));
        
        // Find the range of days
        $found_start = false;
        $in_range = false;
        
        foreach ($days_map as $short => $full) {
            if ($short === $start_day) {
                $found_start = true;
                $in_range = true;
            }
            
            if ($in_range) {
                // Handle closed status specifically
                if (isset($match[4]) && strtolower($match[4]) === 'closed') {
                    $structured_hours[$full][] = 'Closed';
                }
                // Normal hours
                else if (isset($match[3])) {
                    $structured_hours[$full][] = $match[3];
                }
            }
            
            if ($short === $end_day && $found_start) {
                $in_range = false;
            }
        }
    } 
    // Handle pairs like "Sat & Sun: Closed"
    else if (isset($match[2]) && !empty($match[2]) && 
             ((strtolower(substr($match[1], 0, 3)) === 'sat' && strtolower(substr($match[2], 0, 3)) === 'sun') ||
              (strtolower(substr($match[1], 0, 3)) === 'sun' && strtolower(substr($match[2], 0, 3)) === 'sat'))) {
        // Process both Saturday and Sunday with the status
        $structured_hours['Saturday'][] = isset($match[3]) ? $match[3] : 'Closed';
        $structured_hours['Sunday'][] = isset($match[3]) ? $match[3] : 'Closed';
    }
    else {
        // Single day
        if (isset($days_map[$day_text])) {
            $day = $days_map[$day_text];
            
            // Extract the hours part - check different positions based on pattern matches
            $hours_text = '';
            if (isset($match[4]) && !empty($match[4])) {
                $hours_text = $match[4]; // For patterns with day ranges and "Closed"
            } else if (isset($match[3]) && !empty($match[3])) {
                $hours_text = $match[3];
            } else if (isset($match[2]) && !empty($match[2])) {
                $hours_text = $match[2];
            }
            
            if (!empty($hours_text)) {
                $structured_hours[$day][] = $hours_text;
            }
        } elseif ($day_text === 'wee') {
            // Handle "Weekdays" or "Weekends"
            $is_weekday = stripos($match[1], 'Weekday') !== false;
            $weekday_keys = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            $weekend_keys = ['Saturday', 'Sunday'];
            
            $days_to_update = $is_weekday ? $weekday_keys : $weekend_keys;
            
            foreach ($days_to_update as $day) {
                if (isset($match[2])) {
                    $structured_hours[$day][] = $match[2];
                }
            }
        }
    }
}

/**
 * Helper function to process grouped day patterns like "Weekdays" or "Weekends"
 */
function process_grouped_days($text, &$structured_hours) {
    // Check for weekday/weekend patterns
    if (preg_match('/\bWeekdays:?\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?|Closed)/i', $text, $weekday_match)) {
        $weekday_hours = $weekday_match[1];
        $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        foreach ($weekdays as $day) {
            $structured_hours[$day][] = $weekday_hours;
        }
    }
    
    if (preg_match('/\bWeekends:?\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*[-–to]+\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?|Closed)/i', $text, $weekend_match)) {
        $weekend_hours = $weekend_match[1];
        $weekends = ['Saturday', 'Sunday'];
        foreach ($weekends as $day) {
            $structured_hours[$day][] = $weekend_hours;
        }
    }
    
    // Check for "Sat & Sun: Closed" pattern specifically
    if (preg_match('/\b(Sat|Saturday)\s*(?:&|and)\s*(Sun|Sunday):?\s*(Closed)\b/i', $text, $weekend_closed)) {
        $structured_hours['Saturday'][] = 'Closed';
        $structured_hours['Sunday'][] = 'Closed';
    }
    
    // SPECIAL CASE: If we have Mon-Fri hours and Sun closed but no Sat info, add Sat closed
    $has_weekday_hours = !empty($structured_hours['Monday']) && 
                        !empty($structured_hours['Friday']);
    $has_sunday_closed = !empty($structured_hours['Sunday']) && 
                        in_array('Closed', $structured_hours['Sunday']);
    $has_saturday_info = !empty($structured_hours['Saturday']);
    
    if ($has_weekday_hours && $has_sunday_closed && !$has_saturday_info) {
        $structured_hours['Saturday'][] = 'Closed';
    }
}

function seo_setup_get_local_business_data() {
    $data = [];
    $data['business_name'] = get_bloginfo( 'name' );
    $data['business_url']  = home_url();

    $data['business_image_url'] = esc_url_raw(seo_setup_get_logo_url());

    $raw_phone = seo_setup_get_phone();
    $data['business_telephone'] = seo_setup_format_phone_number( $raw_phone );
    $data['business_email'] = seo_setup_get_email();
    $data['address'] = get_option( 'seo_setup_address', '' );
    $data['social_profiles'] = seo_setup_get_social_profiles();

    $opening_hours = get_option( 'seo_setup_opening_hours', '' );
    if ( empty( $opening_hours ) ) {
        $home_html = wp_remote_retrieve_body( wp_remote_get( home_url() ) );
        if ( ! empty( $home_html ) ) {
            $dom = seo_setup_safe_dom_load($home_html);
            $areas = ['header', 'footer'];
            foreach ( $areas as $tag ) {
                $elements = $dom->getElementsByTagName($tag);
                foreach ( $elements as $el ) {
                    $html = $dom->saveHTML($el);
                    $text = strip_tags($html);
                    $extracted = seo_setup_extract_opening_hours_from_text($text);
                    if ( ! empty( $extracted ) ) {
                        $opening_hours = $extracted;
                        break 2;
                    }
                }
            }
        }

        if ( empty( $opening_hours ) ) {
            $contact_page = seo_setup_compat_get_page_by_title( 'Contact' );
            if ( ! $contact_page ) {
                $contact_page = get_page_by_path( 'contact' );
            }
            if ( $contact_page ) {
                $contact_html = wp_remote_retrieve_body( wp_remote_get( get_permalink($contact_page->ID) ) );
                if ( ! empty( $contact_html ) ) {
                    $dom = seo_setup_safe_dom_load($contact_html);
                    $text = strip_tags($dom->saveHTML());
                    $extracted = seo_setup_extract_opening_hours_from_text($text);
                    if ( ! empty( $extracted ) ) {
                        $opening_hours = $extracted;
                    }
                }
            }
        }
    }
    $data['business_opening_hours'] = $opening_hours;

    $lat = $lng = '';
    $home_html = wp_remote_retrieve_body( wp_remote_get( home_url() ) );

    if ( preg_match( '/center=([-0-9\.]+),([-0-9\.]+)/i', $home_html, $g ) ) {
        $lat = $g[1];
        $lng = $g[2];
    } elseif ( preg_match( '/q=([-0-9\.]+),([-0-9\.]+)/i', $home_html, $g ) ) {
        $lat = $g[1];
        $lng = $g[2];
    } else {
        [ $lat, $lng ] = seo_setup_extract_geo_coordinates_from_iframe( $home_html );

        if ( empty( $lat ) || empty( $lng ) ) {
            $contact_page = seo_setup_compat_get_page_by_title( 'Contact' ) ?: get_page_by_path( 'contact' );
            if ( $contact_page ) {
                $contact_html = wp_remote_retrieve_body( wp_remote_get( get_permalink( $contact_page->ID ) ) );
                [ $lat, $lng ] = seo_setup_extract_geo_coordinates_from_iframe( $contact_html );
            }
        }
    }

    if ( empty( $lat ) || empty( $lng ) ) {
        $lat = get_transient( 'seo_setup_fallback_lat' );
        $lng = get_transient( 'seo_setup_fallback_lng' );
    }
    
    $lat = $lat ?: '';
    $lng = $lng ?: '';

    $data['business_latitude']  = $lat;
    $data['business_longitude'] = $lng;

    $data['business_price_range'] = get_option( 'seo_setup_price_range', '$$' );

    return $data;
}

function seo_setup_extract_geo_coordinates_from_iframe( $html ) {
    if ( empty( $html ) ) {
        return [ '', '' ];
    }

    if ( preg_match_all( '/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $iframes ) ) {
        foreach ( $iframes[1] as $src ) {
            // 1. Try to extract coordinates from `center=` or `q=LAT,LNG`
            if ( preg_match( '/(?:center|q)=([-0-9\.]+),\s*([-0-9\.]+)/', $src, $m ) ) {
                return [ $m[1], $m[2] ];
            }
        }
    }

    return [ '', '' ];
}

function seo_setup_clean_schema_array($schema) {
    foreach ($schema as $key => $value) {
        if (is_array($value)) {
            $cleaned = seo_setup_clean_schema_array($value);

            if (empty($cleaned)) {
                unset($schema[$key]);
            } elseif (count($cleaned) === 1 && isset($cleaned['@type'])) {
                unset($schema[$key]);
            } else {
                $schema[$key] = $cleaned;
            }
        } elseif ($value === '' || $value === null) {
            unset($schema[$key]);
        }
    }

    return $schema;
}


// Generate Organization

function seo_setup_generate_org_schema() {
    
    check_ajax_referer('seo_setup_nonce', '_ajax_nonce');

    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $org_data = seo_setup_get_organization_data();
    $addresses = seo_setup_get_address_data();

    $org_schema = [
        '@context'     => 'https://schema.org',
        '@type'        => 'Organization',
        'name'         => $org_data['organization_name'],
        'url'          => $org_data['organization_url'],
        'logo'         => $org_data['organization_logo'],
        'contactPoint' => [
            '@type'             => 'ContactPoint',
            'telephone'         => $org_data['contact_telephone'],
            'email'             => $org_data['business_email'],
            'contactType'       => $org_data['contact_type'],
            'areaServed'        => $org_data['contact_area_served'],
            'availableLanguage' => $org_data['contact_language'],
        ],
        'sameAs'       => $org_data['social_profiles'],
        'address'      => $addresses,
    ];

    $org_schema = seo_setup_clean_schema_array($org_schema);

    $markup = '<script type="application/ld+json" class="seo-setup-schema">'
        . wp_json_encode($org_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . '</script>';

    seo_setup_logger_generated_schema('Organization', home_url(), wp_json_encode($org_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $target_page_id = isset($_POST['target_page_id']) ? intval($_POST['target_page_id']) : 0;
    if ($target_page_id && (get_permalink($target_page_id) !== home_url('/'))) {
        update_post_meta($target_page_id, 'seo_setup_generated_schema', $markup);
    } else {
        update_option('seo_setup_generated_schema', $markup);
    }

    // FIXED: Create schema persistence MU plugin NOW when user generates schema
    if ( function_exists( 'seo_setup_create_mu_plugin_file_schema' ) ) {
        seo_setup_create_mu_plugin_file_schema();
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_seo_setup_generate_org_schema', 'seo_setup_generate_org_schema' );


// Generate LocalBusiness

function seo_setup_generate_local_schema() {
    
    check_ajax_referer('seo_setup_nonce', '_ajax_nonce');

    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }


    $local_data = seo_setup_get_local_business_data();
    $addresses = seo_setup_get_address_data();
    
    $local_schema = [
        '@context'     => 'https://schema.org',
        '@type'        => 'LocalBusiness',
        'name'         => $local_data['business_name'],
        'image'        => $local_data['business_image_url'],
        'telephone'    => $local_data['business_telephone'],
        'email'        => $local_data['business_email'],
        'address'      => $addresses,
        'url'          => $local_data['business_url'],
        'sameAs'       => $local_data['social_profiles'],
        'openingHours' => $local_data['business_opening_hours'],
        'priceRange'   => $local_data['business_price_range'],
        'geo'          => [
            '@type'     => 'GeoCoordinates',
            'latitude'  => $local_data['business_latitude'],
            'longitude' => $local_data['business_longitude'],
        ],
    ];

    $local_schema = seo_setup_clean_schema_array($local_schema);
    
    $markup = '<script type="application/ld+json" class="seo-setup-schema">'
        . wp_json_encode($local_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . '</script>';

    seo_setup_logger_generated_schema('LocalBusiness', home_url(), wp_json_encode($local_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $target_page_id = isset($_POST['target_page_id']) ? intval($_POST['target_page_id']) : 0;
    if ($target_page_id && (get_permalink($target_page_id) !== home_url('/'))) {
        update_post_meta($target_page_id, 'seo_setup_generated_schema', $markup);
    } else {
        update_option('seo_setup_generated_schema', $markup);
    }

    // FIXED: Create schema persistence MU plugin NOW when user generates schema
    if ( function_exists( 'seo_setup_create_mu_plugin_file_schema' ) ) {
        seo_setup_create_mu_plugin_file_schema();
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_seo_setup_generate_local_schema', 'seo_setup_generate_local_schema' );

// Inject the final schema script into <head> on the homepage

function seo_setup_inject_generated_schema() {
    if ( is_front_page() || is_home() ) {
        // FIXED: Output both the org/local schema from options AND per-post schema from post_meta
        $schema_option = get_option( 'seo_setup_generated_schema', '' );
        if ( ! empty( $schema_option ) ) {
            echo $schema_option;
        }
        // Also output per-post schema (WebPage) for the homepage if it's a static page
        $front_page_id = get_option( 'page_on_front' );
        if ( $front_page_id ) {
            $post_schema = get_post_meta( $front_page_id, 'seo_setup_generated_schema', true );
            if ( ! empty( $post_schema ) ) {
                echo $post_schema;
            }
        }
    } elseif ( is_singular() ) {
        global $post;
        if ( ! $post ) {
            return;
        }
        $post_type = get_post_type( $post );
        $schema_markup = get_post_meta( $post->ID, 'seo_setup_generated_schema', true );

        if ( empty( $schema_markup ) ) {
            return;
        }

        // FIXED: Use stripos for case-insensitive matching to prevent silent failures
        $type_map = array(
            'page'         => 'WebPage',
            'post'         => 'Article',
            'product'      => 'Product',
            'tribe_events' => 'Event',
        );

        if ( isset( $type_map[ $post_type ] ) ) {
            if ( stripos( $schema_markup, $type_map[ $post_type ] ) !== false ) {
                echo $schema_markup;
            }
        } else {
            // For other post types, output the schema without type check
            echo $schema_markup;
        }
    }
}
add_action( 'wp_head', 'seo_setup_inject_generated_schema', 1 );

// Generate additional schema batch

function seo_setup_generate_additional_schema_batch() {
    @set_time_limit(600);
    check_ajax_referer( 'seo_setup_nonce', '_ajax_nonce' );

    $batch_index = isset($_POST['batch_index']) ? intval($_POST['batch_index']) : 0;
    $batch_size  = SEO_SETUP_BATCH_SIZE;

    // Get the publisher type passed via AJAX: "Organization" or "LocalBusiness"
    $publisher_type = isset($_POST['schema_publisher_type']) ? sanitize_text_field($_POST['schema_publisher_type']) : 'Organization';

    // Query pages, posts, and products (only published items)
    $args = array(
        'post_type'      => array('page', 'post', 'product', 'tribe_events'),
        'posts_per_page' => $batch_size,
        'offset'         => $batch_index * $batch_size,
        'post_status'    => 'publish',
    );
    $query = new WP_Query( $args );
    

    while ( $query->have_posts() ) {
        
        $query->the_post();

        usleep(100000);

        $schema_markups = array();

        $post_id    = get_the_ID();
        $post_type  = get_post_type( $post_id );
        $post_title = get_the_title( $post_id );
        $post_url   = get_permalink( $post_id );

        // Get meta description based on post type
        $meta_description = '';
        if ( $post_type === 'page' ) {
            // Try common SEO plugin meta keys:
            $meta_description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
            if ( empty( $meta_description ) ) {
                $meta_description = get_post_meta( $post_id, 'rank_math_description', true );
            }
            if ( empty( $meta_description ) ) {
                $meta_description = get_post_meta( $post_id, 'aioseo_description', true );
            }
            if ( empty( $meta_description ) ) {
                $meta_description = wp_strip_all_tags( get_the_excerpt( $post_id ) );
            }
        } else {
            // For posts and products use excerpt or trimmed content
            $meta_description = wp_strip_all_tags( get_the_excerpt( $post_id ) );
            if ( empty( $meta_description ) ) {
                $meta_description = wp_trim_words( get_the_content( $post_id ), 40, '...' );
            }
        }

        // Build publisher array dynamically
        $publisher = array(
            '@type' => $publisher_type,
            'name'  => get_bloginfo( 'name' ),
            'url'   => home_url(),
        );

        if ( $post_type === 'page' ) {
            // --- WebPage Schema ---
            $webpage_schema = array(
                '@context'    => 'https://schema.org',
                '@type'       => 'WebPage',
                'name'        => $post_title,
                'url'         => $post_url,
                'description' => $meta_description,
                'publisher'   => $publisher,
            );
            $webpage_schema = seo_setup_clean_schema_array( $webpage_schema );
            $schema_markups[] = '<script type="application/ld+json" class="seo-setup-schema">' .
                wp_json_encode( $webpage_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
                '</script>';

            seo_setup_logger_generated_schema( 'WebPage', $post_url, wp_json_encode( $webpage_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

        } elseif ( $post_type === 'post' ) {
            // --- Article Schema ---
            $article_schema = array(
                '@context'            => 'https://schema.org',
                '@type'               => 'Article',
                'headline'            => $post_title,
                'author'              => array(
                    '@type' => 'Person',
                    'name'  => get_the_author_meta( 'display_name', $post_id ),
                    'url'   => get_author_posts_url( get_the_author_meta( 'ID', $post_id ) ),
                ),
                'publisher'           => array(
                    '@type' => $publisher_type, // set dynamically
                    'name'  => get_bloginfo( 'name' ),
                    'logo'  => array(
                        '@type' => 'ImageObject',
                        'url'   => ( get_theme_mod( 'custom_logo' ) ? wp_get_attachment_image_src( get_theme_mod( 'custom_logo' ), 'full' )[0] : '' ),
                    ),
                ),
                'datePublished'       => get_the_date( 'c', $post_id ),
                'dateModified'        => get_the_modified_date( 'c', $post_id ),
                'mainEntityOfPage'    => array(
                    '@type' => 'WebPage',
                    '@id'   => $post_url,
                ),
            );
            $article_schema = seo_setup_clean_schema_array( $article_schema );
            $schema_markups[] = '<script type="application/ld+json" class="seo-setup-schema">' .
                wp_json_encode( $article_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
                '</script>';

            seo_setup_logger_generated_schema( 'Article', $post_url, wp_json_encode( $article_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        } elseif ( $post_type === 'product' ) {
            $product_image_url = get_the_post_thumbnail_url( $post_id, 'full' );
            $sku               = get_post_meta( $post_id, '_sku', true );
            $mpn               = get_post_meta( $post_id, '_mpn', true ); // fallback if exists
            $price             = get_post_meta( $post_id, '_price', true );
            $currency          = get_post_meta( $post_id, '_price_currency', true ) ?: 'USD';
            $price_valid_until = date( 'Y-m-d', strtotime( '+1 year' ) ); // default to 1 year from now
            $condition         = 'https://schema.org/NewCondition';
            $availability      = 'https://schema.org/InStock';
        
            // Ratings and Reviews (basic example using WooCommerce)
            $rating_value  = get_post_meta( $post_id, '_wc_average_rating', true );
            $review_count  = get_post_meta( $post_id, '_wc_review_count', true );
            $review_author = '';
            $review_date   = '';
            $review_text   = '';
            $review_rating = '';
        
            $comments = get_comments( array(
                'post_id' => $post_id,
                'number'  => 1,
                'status'  => 'approve',
                'orderby' => 'comment_date',
                'order'   => 'DESC',
            ) );
        
            if ( ! empty( $comments ) ) {
                $comment        = $comments[0];
                $review_author  = $comment->comment_author;
                $review_date    = get_comment_date( 'c', $comment );
                $review_text    = $comment->comment_content;
                $review_rating  = get_comment_meta( $comment->comment_ID, 'rating', true );
            }
        
            $product_schema = array(
                '@context'    => 'https://schema.org',
                '@type'       => 'Product',
                'name'        => $post_title,
                'image'       => array( $product_image_url ),
                'description' => $meta_description,
                'sku'         => $sku,
                'mpn'         => $mpn,
                'brand'       => array(
                    '@type' => 'Brand',
                    'name'  => get_bloginfo( 'name' ),
                ),
                'offers'      => array(
                    '@type'           => 'Offer',
                    'url'             => $post_url,
                    'priceCurrency'   => $currency,
                    'price'           => $price,
                    'priceValidUntil' => $price_valid_until,
                    'itemCondition'   => $condition,
                    'availability'    => $availability,
                    'seller'          => array(
                        '@type' => $publisher_type,
                        'name'  => get_bloginfo( 'name' ),
                    ),
                ),
            );
        
            if ( $rating_value && $review_count ) {
                $product_schema['aggregateRating'] = array(
                    '@type'       => 'AggregateRating',
                    'ratingValue' => $rating_value,
                    'reviewCount' => $review_count,
                );
            }
        
            if ( $review_author && $review_rating ) {
                $product_schema['review'] = array(
                    '@type'         => 'Review',
                    'author'        => array(
                        '@type' => 'Person',
                        'name'  => $review_author,
                    ),
                    'datePublished' => $review_date,
                    'description'   => $review_text,
                    'reviewRating'  => array(
                        '@type'       => 'Rating',
                        'ratingValue' => $review_rating,
                    ),
                );
            }
            $product_schema = seo_setup_clean_schema_array( $product_schema );
            $schema_markups[] = '<script type="application/ld+json" class="seo-setup-schema">' .
                wp_json_encode( $product_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
                '</script>';

            seo_setup_logger_generated_schema( 'Product', $post_url, wp_json_encode( $product_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        } elseif ( $post_type === 'tribe_events' ) {
            
            $event_schema = array(
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => $post_title,
                'description' => $meta_description,
                'startDate' => get_post_meta( $post_id, '_EventStartDate', true ),
                'endDate' => get_post_meta( $post_id, '_EventEndDate', true ),
                'eventStatus' => 'https://schema.org/EventScheduled', // Default unless you have custom status handling
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', // Default for physical events
                'location' => array(
                    '@type' => 'Place',
                    'name' => get_post_meta( $post_id, '_EventVenueID', true ) ? get_the_title( get_post_meta( $post_id, '_EventVenueID', true ) ) : '',
                    'address' => array(
                        '@type' => 'PostalAddress',
                        'streetAddress' => tribe_get_address( $post_id ),
                        'addressLocality' => tribe_get_city( $post_id ),
                        'postalCode' => tribe_get_zip( $post_id ),
                        'addressRegion' => tribe_get_region( $post_id ),
                        'addressCountry' => tribe_get_country( $post_id ),
                    ),
                ),
                'organizer' => array(
                    '@type' => 'Organization',
                    'name' => get_post_meta( $post_id, '_EventOrganizerID', true ) ? get_the_title( get_post_meta( $post_id, '_EventOrganizerID', true ) ) : '',
                    'url' => tribe_get_organizer_website_url( $post_id ),
                ),
                'offers' => array(
                    '@type' => 'Offer',
                    'url' => $post_url,
                    'price' => tribe_get_cost( $post_id, true ),
                    'priceCurrency' => 'USD', // You might want to set this dynamically
                    'availability' => 'https://schema.org/InStock', // Default unless you track availability
                    'validFrom' => get_post_meta( $post_id, '_EventStartDate', true ),
                ),
            );
            $event_schema = seo_setup_clean_schema_array( $event_schema );
            $schema_markups[] = '<script type="application/ld+json" class="seo-setup-schema">' .
                wp_json_encode( $event_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
                '</script>';

            seo_setup_logger_generated_schema( 'Event', $post_url, wp_json_encode( $event_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }

        if ( ! is_front_page() && ! is_home() ) {
            $breadcrumb_schema = seo_setup_generate_breadcrumb_schema( $post_id );
            $already_has_breadcrumb = false;

            foreach ( $schema_markups as $markup ) {
                if ( strpos( $markup, '"@type":"BreadcrumbList"' ) !== false ) {
                    $already_has_breadcrumb = true;
                    break;
                }
            }

            if ( ! $already_has_breadcrumb && $breadcrumb_schema ) {
                $schema_markups[] = $breadcrumb_schema;
            }
        }

        update_post_meta( $post_id, 'seo_setup_generated_schema', implode("\n", $schema_markups) );
    }
    wp_reset_postdata();

    // Calculate progress for batch processing
    $total_posts    = $query->found_posts;
    $total_batches  = ceil( $total_posts / $batch_size );
    $progress       = (($batch_index + 1) / $total_batches) * 100;

    wp_send_json_success( array(
        'data'          => $schema_markups,
        'batch_index'   => $batch_index + 1,
        'total_batches' => $total_batches,
        'progress'      => $progress,
    ) );
}
add_action( 'wp_ajax_seo_setup_generate_schema_batch', 'seo_setup_generate_additional_schema_batch' );

function seo_setup_generate_breadcrumb_schema( $post_id ) {
    $home_url   = home_url();
    $post_url   = get_permalink( $post_id );

    $ancestors = get_post_ancestors( $post_id );
    $ancestors = array_reverse( $ancestors );

    $breadcrumb_items = array();

    // Home
    $breadcrumb_items[] = array(
        '@type'    => 'ListItem',
        'position' => 1,
        'name'     => 'Home',
        'item'     => $home_url,
    );

    $position = 2;
    foreach ( $ancestors as $ancestor_id ) {
        $breadcrumb_items[] = array(
            '@type'    => 'ListItem',
            'position' => $position++,
            'name'     => get_the_title( $ancestor_id ),
            'item'     => get_permalink( $ancestor_id ),
        );
    }

    // Current page
    $breadcrumb_items[] = array(
        '@type'    => 'ListItem',
        'position' => $position,
        'name'     => get_the_title( $post_id ),
        'item'     => $post_url,
    );

    $breadcrumb = array(
        '@context'        => 'https://schema.org/',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $breadcrumb_items,
    );
    $breadcrumb = seo_setup_clean_schema_array( $breadcrumb );

    $markup = '<script type="application/ld+json" class="seo-setup-schema">' .
           wp_json_encode( $breadcrumb, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
           '</script>';

    // FIXED: Log BEFORE return (was dead code after return)
    seo_setup_logger_generated_schema( 'BreadcrumbList', $post_url, wp_json_encode( $breadcrumb, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

    return $markup;
}
