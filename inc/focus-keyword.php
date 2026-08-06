<?php
/**
 * focus-keyword.php
 *
 * Meta title, Meta Description, Focus Keyword, and Semantic Keyword.
 *
 * Includes multi-step validation and fallback chain (ported from Python audit_response.py):
 * Step 1: Generate with full business name
 * Step 2: If validation fails, retry with abbreviated business name
 * Step 3: If that fails, strict retry asking OpenAI to fix only the failing field(s)
 * Step 4: If everything fails, pick the best available option closest to valid range
 *
 * @package SEO_Setup
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// ============================================================================
// Constants for meta validation (matching Python script)
// ============================================================================
define( 'SEO_SETUP_META_TITLE_MIN', 30 );
define( 'SEO_SETUP_META_TITLE_MAX', 60 );
define( 'SEO_SETUP_META_DESC_MIN', 70 );
define( 'SEO_SETUP_META_DESC_MAX', 156 );
define( 'SEO_SETUP_BUSINESS_NAME_ABBR_THRESHOLD', 25 );

// ============================================================================
// Logging helper
// ============================================================================

/**
 * Store logs in the WordPress options table (instead of writing to a local file).
 *
 * @param string $message The log message to store.
 */
function seo_setup_log_meta_update($message)
{
    $option_name = 'seo_setup_plugin_logs';
    $logs = get_option($option_name, array());
    $timestamp = date('Y-m-d H:i:s');
    $logs[] = "[{$timestamp}] {$message}";

    update_option($option_name, $logs);
}

/**
 * Write OpenAI debug logs to WordPress debug.log and inc/error-log.txt.
 *
 * @param string $message The debug message to store.
 */
function seo_setup_openai_debug_log( $message ) {
    $timestamp = date( 'Y-m-d H:i:s' );
    $formatted = "[{$timestamp}] [SEO Setup OpenAI Debug] {$message}";

    error_log( $formatted );

    $plugin_error_log = trailingslashit( __DIR__ ) . 'error-log.txt';
    error_log( $formatted . PHP_EOL, 3, $plugin_error_log );
}

// ============================================================================
// Business name abbreviation (ported from Python _abbreviate_business_name)
// ============================================================================

/**
 * Generate abbreviation from business name by taking first letter of each word.
 * E.g., "Family Life Counseling and Psychological Services, LLC" -> "FLCAPS"
 *
 * @param string $business_name
 * @return string
 */
function seo_setup_abbreviate_business_name( $business_name ) {
    if ( empty( $business_name ) ) {
        return '';
    }
    $cleaned = preg_replace( '/[,.\-;:!?\'"()]/', '', $business_name );
    $words = preg_split( '/\s+/', trim( $cleaned ) );
    if ( empty( $words ) ) {
        return '';
    }
    $abbr = '';
    foreach ( $words as $w ) {
        if ( ! empty( $w ) ) {
            $abbr .= strtoupper( $w[0] );
        }
    }
    return $abbr;
}

/**
 * Returns abbreviation if business name is longer than threshold, otherwise returns full name.
 *
 * @param string $business_name
 * @return string
 */
function seo_setup_get_business_name_for_prompt( $business_name ) {
    if ( strlen( $business_name ) > SEO_SETUP_BUSINESS_NAME_ABBR_THRESHOLD ) {
        $abbr = seo_setup_abbreviate_business_name( $business_name );
        seo_setup_log_meta_update( "Using abbreviation '{$abbr}' for '{$business_name}' (" . strlen( $business_name ) . " chars > " . SEO_SETUP_BUSINESS_NAME_ABBR_THRESHOLD . ")" );
        return $abbr;
    }
    return $business_name;
}

// ============================================================================
// Validation helpers (ported from Python _validate_meta_lengths)
// ============================================================================

/**
 * Validate that both title and description are within valid length ranges.
 *
 * @param string $title
 * @param string $description
 * @return bool
 */
function seo_setup_validate_meta_lengths( $title, $description ) {
    $title_len = strlen( $title );
    $desc_len  = strlen( $description );
    $title_ok  = ( $title_len >= SEO_SETUP_META_TITLE_MIN && $title_len <= SEO_SETUP_META_TITLE_MAX );
    $desc_ok   = ( $desc_len >= SEO_SETUP_META_DESC_MIN && $desc_len <= SEO_SETUP_META_DESC_MAX );
    return $title_ok && $desc_ok;
}

/**
 * From a list of titles, pick the first one within valid range.
 * If none valid, pick the closest to mid-range (45 chars).
 *
 * @param array $titles
 * @return string
 */
function seo_setup_pick_best_title( $titles ) {
    foreach ( $titles as $t ) {
        $len = strlen( $t );
        if ( $len >= SEO_SETUP_META_TITLE_MIN && $len <= SEO_SETUP_META_TITLE_MAX ) {
            return $t;
        }
    }
    if ( ! empty( $titles ) ) {
        $best = $titles[0];
        $best_diff = abs( strlen( $best ) - 45 );
        foreach ( $titles as $t ) {
            $diff = abs( strlen( $t ) - 45 );
            if ( $diff < $best_diff ) {
                $best = $t;
                $best_diff = $diff;
            }
        }
        return $best;
    }
    return '';
}

/**
 * From a list of descriptions, pick the first one within valid range.
 * If none valid, pick the closest to mid-range (120 chars).
 *
 * @param array $descriptions
 * @return string
 */
function seo_setup_pick_best_description( $descriptions ) {
    foreach ( $descriptions as $d ) {
        $len = strlen( $d );
        if ( $len >= SEO_SETUP_META_DESC_MIN && $len <= SEO_SETUP_META_DESC_MAX ) {
            return $d;
        }
    }
    if ( ! empty( $descriptions ) ) {
        $best = $descriptions[0];
        $best_diff = abs( strlen( $best ) - 120 );
        foreach ( $descriptions as $d ) {
            $diff = abs( strlen( $d ) - 120 );
            if ( $diff < $best_diff ) {
                $best = $d;
                $best_diff = $diff;
            }
        }
        return $best;
    }
    return '';
}

// ============================================================================
// Title case helpers
// ============================================================================

/**
 * Convert generated keywords and H1 text to grammatical title case.
 * Keeps short connector words lowercase when they are not first or last.
 *
 * @param string $text
 * @return string
 */
function seo_setup_to_title_case( $text ) {
    $text = trim( (string) $text );
    if ( '' === $text ) {
        return '';
    }

    $small_words = array( 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'with' );
    $words = preg_split( '/\s+/', strtolower( $text ) );
    $last_index = count( $words ) - 1;

    foreach ( $words as $index => $word ) {
        if ( '' === $word ) {
            continue;
        }
        if ( $index > 0 && $index < $last_index && in_array( $word, $small_words, true ) ) {
            continue;
        }
        $words[$index] = ucwords( $word, " \t\r\n\f\v-'" );
    }

    return implode( ' ', $words );
}

/**
 * Convert generated meta titles to grammatical title case.
 * Preserves uppercase acronyms such as CFB and CCPL.
 * Keeps short connector words lowercase when they are not first or last.
 *
 * @param string $title
 * @return string
 */
function seo_setup_to_meta_title_case( $title ) {
    $title = trim( (string) $title );
    if ( '' === $title ) {
        return '';
    }

    $small_words = array( 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'with' );
    $parts = preg_split( '/(\s+)/', $title, -1, PREG_SPLIT_DELIM_CAPTURE );
    $word_indexes = array();

    foreach ( $parts as $index => $part ) {
        if ( '' !== trim( $part ) && ! preg_match( '/^\s+$/', $part ) ) {
            $word_indexes[] = $index;
        }
    }

    $first_word_index = reset( $word_indexes );
    $last_word_index  = end( $word_indexes );

    foreach ( $word_indexes as $index ) {
        $part = $parts[$index];

        $parts[$index] = preg_replace_callback( '/[A-Za-z]+/', function( $matches ) use ( $small_words, $index, $first_word_index, $last_word_index ) {
            $word = $matches[0];

            if ( preg_match( '/^[A-Z]{2,}$/', $word ) ) {
                return $word;
            }

            $lower_word = strtolower( $word );
            if ( $index !== $first_word_index && $index !== $last_word_index && in_array( $lower_word, $small_words, true ) ) {
                return $lower_word;
            }

            return ucfirst( $lower_word );
        }, $part );
    }

    return trim( implode( '', $parts ) );
}

// ============================================================================
// Home page context helpers
// ============================================================================

/**
 * Check whether a page slug should be treated as the home page.
 *
 * @param string $page_slug
 * @return bool
 */
function seo_setup_is_home_page_slug( $page_slug ) {
    $normalized_slug = strtolower( trim( (string) $page_slug, " /\t\n\r\0\x0B" ) );
    return empty( $normalized_slug ) || in_array( $normalized_slug, array( 'home', 'front-page' ), true );
}

/**
 * Check whether the page should be treated as the home page.
 *
 * @param string $page_slug
 * @param string $page_title
 * @return bool
 */
function seo_setup_is_home_page_context( $page_slug, $page_title ) {
    $normalized_title = strtolower( trim( (string) $page_title ) );
    return seo_setup_is_home_page_slug( $page_slug ) || 'home' === $normalized_title;
}

/**
 * Build the strict home page meta title format.
 *
 * @param string $primary_keyword
 * @param string $business_name
 * @return string
 */
function seo_setup_build_home_page_meta_title( $primary_keyword, $business_name ) {
    return seo_setup_to_meta_title_case( trim( $primary_keyword ) . ' | ' . trim( $business_name ) );
}

// ============================================================================
// Meta title validation and inner page fallback
// ============================================================================

/**
 * Validate only the meta title length.
 *
 * @param string $title
 * @return bool
 */
function seo_setup_is_valid_meta_title( $title ) {
    $title_len = strlen( $title );
    return ( $title_len >= SEO_SETUP_META_TITLE_MIN && $title_len <= SEO_SETUP_META_TITLE_MAX );
}

/**
 * Apply the requested local fallback order for inner page meta titles.
 *
 * @param string $generated_title
 * @param string $page_title
 * @param string $primary_keyword
 * @param string $business_name
 * @return string
 */
function seo_setup_pick_inner_page_meta_title( $generated_title, $page_title, $primary_keyword, $business_name ) {
    $short_business_name = seo_setup_get_business_name_for_prompt( $business_name );
    if ( empty( $short_business_name ) ) {
        $short_business_name = $business_name;
    }

    $candidates = array();

    // Format 1: Page Name | Primary Keyword {preposition} Business Name (generated by OpenAI)
    if ( ! empty( $generated_title ) ) {
        $candidates[] = $generated_title;
    }

    // Format 1 fallback: replace full business name with short form while preserving the generated preposition.
    if ( ! empty( $generated_title ) && ! empty( $business_name ) && $short_business_name !== $business_name ) {
        $shortened_generated_title = str_replace( $business_name, $short_business_name, $generated_title );
        if ( $shortened_generated_title !== $generated_title ) {
            $candidates[] = $shortened_generated_title;
        }
    }

    // Format 2: Primary Keyword | Page Name
    if ( ! empty( $primary_keyword ) && ! empty( $page_title ) ) {
        $candidates[] = "{$primary_keyword} | {$page_title}";
    }

    // Format 3: Primary Keyword | Business Name (only when page name is long)
    if ( ! empty( $primary_keyword ) && ! empty( $business_name ) && strlen( $page_title ) >= SEO_SETUP_BUSINESS_NAME_ABBR_THRESHOLD ) {
        $candidates[] = "{$primary_keyword} | {$business_name}";
    }

    // Format 4: Page Name | Short Form of Business Name
    if ( ! empty( $page_title ) && ! empty( $short_business_name ) ) {
        $candidates[] = "{$page_title} | {$short_business_name}";
    }

    $candidates = array_values( array_unique( array_filter( array_map( 'seo_setup_to_meta_title_case', array_map( 'trim', $candidates ) ) ) ) );

    foreach ( $candidates as $candidate ) {
        if ( seo_setup_is_valid_meta_title( $candidate ) ) {
            return $candidate;
        }
    }

    if ( ! empty( $candidates ) ) {
        // No valid candidate — pick the one closest to the valid range rather than the last (shortest)
        $best       = $candidates[0];
        $best_score = PHP_INT_MAX;
        foreach ( $candidates as $candidate ) {
            $len = strlen( $candidate );
            if ( $len < SEO_SETUP_META_TITLE_MIN ) {
                $score = SEO_SETUP_META_TITLE_MIN - $len;
            } elseif ( $len > SEO_SETUP_META_TITLE_MAX ) {
                $score = $len - SEO_SETUP_META_TITLE_MAX;
            } else {
                $score = 0;
            }
            if ( $score < $best_score ) {
                $best       = $candidate;
                $best_score = $score;
            }
        }
        return $best;
    }

    return $generated_title;
}

// ============================================================================
// Strict retry (ported from Python _call_openai_meta_strict_retry)
// ============================================================================

/**
 * When OpenAI returns title/desc that fail validation,
 * retry with a very specific prompt asking to fix ONLY the failing field(s).
 *
 * @param string $api_url
 * @param string $api_key
 * @param string $page_slug
 * @param string $page_title
 * @param string $business_name
 * @param string $failed_title
 * @param string $failed_desc
 * @return array|null  Array with 'title' and 'description' keys, or null if failed.
 */
function seo_setup_strict_retry_meta( $api_url, $api_key, $page_slug, $page_title, $business_name, $failed_title, $failed_desc ) {
    $title_len = strlen( $failed_title );
    $desc_len  = strlen( $failed_desc );
    $title_ok  = ( $title_len >= SEO_SETUP_META_TITLE_MIN && $title_len <= SEO_SETUP_META_TITLE_MAX );
    $desc_ok   = ( $desc_len >= SEO_SETUP_META_DESC_MIN && $desc_len <= SEO_SETUP_META_DESC_MAX );

    if ( $title_ok && $desc_ok ) {
        return array( 'title' => $failed_title, 'description' => $failed_desc );
    }

    $fix_instructions = [];
    if ( ! $title_ok ) {
        $action = ( $title_len > SEO_SETUP_META_TITLE_MAX ) ? 'Shorten' : 'Expand';
        $fix_instructions[] = "The meta title \"{$failed_title}\" is {$title_len} characters. "
            . "It MUST be between " . SEO_SETUP_META_TITLE_MIN . " and " . SEO_SETUP_META_TITLE_MAX . " characters. "
            . "{$action} it while keeping the same keywords.";
    }
    if ( ! $desc_ok ) {
        $action = ( $desc_len > SEO_SETUP_META_DESC_MAX ) ? 'Shorten' : 'Expand';
        $fix_instructions[] = "The meta description \"{$failed_desc}\" is {$desc_len} characters. "
            . "It MUST be between " . SEO_SETUP_META_DESC_MIN . " and " . SEO_SETUP_META_DESC_MAX . " characters. "
            . "{$action} it while keeping the same keywords and business name.";
    }

    $prompt_business_name = seo_setup_get_business_name_for_prompt( $business_name );

    $title_line = $title_ok ? "(keep as is) {$failed_title}" : "your fixed meta title here";
    $desc_line  = $desc_ok ? "(keep as is) {$failed_desc}" : "your fixed meta description here";

    $prompt = "You are an SEO expert. Fix the following meta tag(s) for the page \"{$page_title}\" of \"{$prompt_business_name}\".\n"
        . "Page URL: {$page_slug}\n\n"
        . "STRICT CHARACTER COUNT REQUIREMENTS:\n"
        . implode( "\n", $fix_instructions ) . "\n\n"
        . "Count every character including spaces before responding.\n"
        . "The title MUST contain '|' separator.\n"
        . "MUST NOT contain: @ # ^ * ( ) _ + < > \" [ ] = / \\ ? ; & - -\n\n"
        . "Output ONLY two lines:\n"
        . "Title: {$title_line}\n"
        . "Description: {$desc_line}";

    $request_data = [
        'model'                 => 'gpt-5-mini',
        'reasoning_effort'      => 'minimal',
        'max_completion_tokens' => 1200,
        'messages'              => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $request_body = wp_json_encode( $request_data );
    seo_setup_openai_debug_log( 'Strict retry request endpoint: ' . $api_url );
    seo_setup_openai_debug_log( 'Strict retry request body: ' . $request_body );

    $openai_request_started_at = microtime( true );

    $response = wp_remote_post( $api_url, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body'    => $request_body,
        'timeout' => 120,
    ] );

    $openai_elapsed_seconds = round( microtime( true ) - $openai_request_started_at, 3 );
    seo_setup_openai_debug_log( 'Strict retry elapsed seconds: ' . $openai_elapsed_seconds );

    if ( is_wp_error( $response ) ) {
        seo_setup_openai_debug_log( 'Strict retry WP_Error: ' . $response->get_error_message() );
        seo_setup_log_meta_update( 'Strict retry API call failed.' );
        return null;
    }

    $strict_status_code = wp_remote_retrieve_response_code( $response );
    $strict_raw_body = wp_remote_retrieve_body( $response );
    seo_setup_openai_debug_log( 'Strict retry response code: ' . $strict_status_code );
    seo_setup_openai_debug_log( 'Strict retry raw response body: ' . $strict_raw_body );

    if ( 200 !== $strict_status_code ) {
        seo_setup_log_meta_update( 'Strict retry API call failed.' );
        return null;
    }

    $body = json_decode( $strict_raw_body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        seo_setup_openai_debug_log( 'Strict retry JSON decode error: ' . json_last_error_msg() );
        return null;
    }
    $content = isset( $body['choices'][0]['message']['content'] ) ? trim( $body['choices'][0]['message']['content'] ) : '';

    if ( empty( $content ) ) {
        return null;
    }

    $new_title = $title_ok ? $failed_title : '';
    $new_desc  = $desc_ok ? $failed_desc : '';

    $lines = preg_split( '/\r\n|\r|\n/', $content );
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( stripos( $line, 'Title:' ) === 0 ) {
            $val = trim( substr( $line, 6 ) );
            if ( ! empty( $val ) && stripos( $val, '(keep as is)' ) !== 0 ) {
                $new_title = $val;
            }
        } elseif ( stripos( $line, 'Description:' ) === 0 ) {
            $val = trim( substr( $line, 12 ) );
            if ( ! empty( $val ) && stripos( $val, '(keep as is)' ) !== 0 ) {
                $new_desc = $val;
            }
        }
    }

    $new_title = seo_setup_to_meta_title_case( $new_title );

    if ( seo_setup_validate_meta_lengths( $new_title, $new_desc ) ) {
        seo_setup_log_meta_update( "Strict retry SUCCESS: title_len=" . strlen( $new_title ) . ", desc_len=" . strlen( $new_desc ) );
        return array( 'title' => $new_title, 'description' => $new_desc );
    }

    seo_setup_log_meta_update( "Strict retry still invalid: title_len=" . strlen( $new_title ) . ", desc_len=" . strlen( $new_desc ) );
    return null;
}

// ============================================================================
// Long page name fallback (Step 3.5)
// ============================================================================

/**
 * When the page name is longer than 25 characters and all previous steps
 * failed to produce a valid-length meta title, retry with a prompt that
 * forces the format: "Primary Keyword | Business Name"
 * (dropping the long page name from the title entirely).
 *
 * @param string $api_url
 * @param string $api_key
 * @param string $page_slug
 * @param string $page_title
 * @param string $page_content
 * @param string $business_name
 * @param string $domain
 * @param bool   $has_content
 * @return array|WP_Error  Parsed result array or WP_Error
 */
function seo_setup_long_page_name_retry( $api_url, $api_key, $page_slug, $page_title, $page_content, $business_name, $domain, $has_content ) {
    $prompt_business_name = seo_setup_get_business_name_for_prompt( $business_name );

    $content_section = '';
    if ( $has_content && ! empty( $page_content ) ) {
        $content_section = "Page Content:\n{$page_content}\n\n";
    }

    $prompt = "You are an expert SEO content strategist. Generate SEO elements for a webpage.

{$content_section}Page Slug: {$page_slug}
Page Name: {$page_title}
Business Name: {$prompt_business_name}
Domain: {$domain}

---

## Step 1: Focus Keyword and Semantic Keyword

Analyze the page and determine:

**Focus Keyword (Primary Keyword):**
- Identify one primary, highly relevant keyword.
- It must NOT be the same as the Page Name.
- It must NOT be the Business Name or any variation of the Business Name.

**Semantic Keyword:**
- Identify one closely related semantic keyword.
- It must NOT be the Business Name or any variation of the Business Name.

---

## Step 2: Meta Titles (STRICT — MANDATORY FORMAT)

The page name \"{$page_title}\" is very long. Therefore, you MUST use ONLY this format for all 3 meta titles:

**Primary Keyword | {$prompt_business_name}**

CRITICAL RULES:
1. Each meta title MUST be between 30 and 60 characters (inclusive). This is a HARD requirement.
2. The Primary Keyword comes FIRST, then the pipe symbol |, then the Business Name.
3. DO NOT include the page name in the meta title.
4. DO NOT reverse the order.
5. Count characters carefully before outputting.
6. Meta titles MUST NOT include any of these characters:
   @ # ^ * ( ) _ + < > \" [ ] = / \\ ? ; & - -

---

## Step 3: Meta Descriptions

Generate exactly 3 meta descriptions:
1. Length must be between 70 and 156 characters. This is a HARD requirement.
2. Must use the exact same Primary Keyword used in the Meta Titles.
3. The first 100-120 characters must be a complete standalone sentence.
4. This first sentence must start with a strong CTA.
5. This first sentence must include both the Primary Keyword and {$prompt_business_name}.
6. Meta descriptions MUST NOT include any of these characters:
   @ # ^ * ( ) _ + < > \" [ ] = / \\ ? ; & - -

---

## Step 4: H1 Tag

Generate one H1 tag that:
- Naturally includes the Focus Keyword
- Is between 20 and 45 characters

---

## Output Format (STRICT)

Provide ONLY the generated text.
Each of the 9 items must be separated by a single $ delimiter.
Format: Focus Keyword $ Semantic Keyword $ Title 1 $ Title 2 $ Title 3 $ Desc 1 $ Desc 2 $ Desc 3 $ H1 Tag";

    return seo_setup_call_openai_and_parse( $api_url, $api_key, $prompt, 'long_page_name_retry' );
}

// ============================================================================
// Core OpenAI call + parse (shared by both content and fallback functions)
// ============================================================================

/**
 * Send a prompt to OpenAI and parse the 9-segment response.
 * Returns parsed array or WP_Error.
 *
 * @param string $api_url
 * @param string $api_key
 * @param string $prompt
 * @param string $context_label  For logging ("meta_for_content" or "fallback")
 * @return array|WP_Error
 */
function seo_setup_call_openai_and_parse( $api_url, $api_key, $prompt, $context_label = 'meta' ) {
    $model = 'gpt-5-mini';

    $request_data = [
        'model'                 => $model,
        'reasoning_effort'      => 'minimal',
        'max_completion_tokens' => 1200,
        'messages'              => [
            ['role' => 'system', 'content' => 'You are ChatGPT, a helpful assistant.'],
            ['role' => 'user', 'content' => $prompt],
        ],
    ];
    $headers = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
    ];

    if ( function_exists( 'seo_setup_logger_meta_details_request' ) ) {
        seo_setup_logger_meta_details_request( $request_data );
    }

    $request_body = wp_json_encode( $request_data );
    seo_setup_openai_debug_log( "OpenAI request endpoint ({$context_label}): {$api_url}" );
    seo_setup_openai_debug_log( "OpenAI request body ({$context_label}): {$request_body}" );

    $openai_request_started_at = microtime( true );

    $response = wp_remote_post( $api_url, [
        'headers' => $headers,
        'body'    => $request_body,
        'timeout' => 300,
    ] );

    $openai_elapsed_seconds = round( microtime( true ) - $openai_request_started_at, 3 );
    seo_setup_openai_debug_log( "OpenAI elapsed seconds ({$context_label}): {$openai_elapsed_seconds}" );

    if ( is_wp_error( $response ) ) {
        seo_setup_openai_debug_log( "OpenAI WP_Error ({$context_label}): " . $response->get_error_message() );
        seo_setup_log_meta_update( "API Error ({$context_label}): " . $response->get_error_message() );
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    seo_setup_openai_debug_log( "OpenAI response code ({$context_label}): {$status_code}" );
    seo_setup_openai_debug_log( "OpenAI raw response body ({$context_label}): {$body}" );
    seo_setup_log_meta_update( "ChatGPT response code ({$context_label}): {$status_code}" );

    if ( 200 !== $status_code ) {
        seo_setup_openai_debug_log( "OpenAI non-200 response ({$context_label}): {$body}" );
        seo_setup_log_meta_update( "Non-200 response ({$context_label}): {$body}" );
        return new WP_Error( 'api_error', 'OpenAI API returned a non-200 status.' );
    }

    $json = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        seo_setup_openai_debug_log( 'OpenAI JSON decode error (' . $context_label . '): ' . json_last_error_msg() );
        seo_setup_log_meta_update( 'JSON decode error: ' . json_last_error_msg() );
        return new WP_Error( 'decode_error', 'Failed to decode JSON from OpenAI.' );
    }

    if ( function_exists( 'seo_setup_logger_meta_details_response' ) ) {
        seo_setup_logger_meta_details_response( $json );
    }

    if ( empty( $json['choices'][0]['message']['content'] ) ) {
        seo_setup_openai_debug_log( "OpenAI response structure unexpected ({$context_label}): " . print_r( $json, true ) );
        seo_setup_log_meta_update( 'OpenAI response structure unexpected.' );
        return new WP_Error( 'missing_data', 'The API did not return expected content.' );
    }

    $content_str = trim( $json['choices'][0]['message']['content'] );
    seo_setup_openai_debug_log( "OpenAI parsed message content ({$context_label}): {$content_str}" );

    // --- Parse: try $ delimiter first ---
    $segments = explode( '$', $content_str );

    // --- Fallback: try newline delimiter ---
    if ( count( $segments ) < 9 ) {
        $newline_check = preg_split( '/\r\n|\r|\n/', $content_str );
        $newline_check = array_values( array_filter( $newline_check, 'trim' ) );
        if ( count( $newline_check ) >= 9 ) {
            seo_setup_log_meta_update( "$ delimiter missing, using newline segments ({$context_label})." );
            $segments = $newline_check;
        }
    }

    // --- Fallback: try label-based parsing ---
    if ( count( $segments ) < 9 ) {
        seo_setup_log_meta_update( '$-delimited parsing failed. Attempting label-based parsing. Raw: ' . $content_str );

        $pattern = '/\b(Focus Keyword|Semantic Keyword|Title Tag|Meta Description|H1 Tag)\b(?:\s*\d*)?:/i';
        $parts = preg_split( $pattern, $content_str, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE );

        if ( count( $parts ) > 1 ) {
            $structured_data = [];
            $titles_parsed = [];
            $descriptions_parsed = [];

            for ( $i = 0; $i < count( $parts ); $i += 2 ) {
                $key   = isset( $parts[$i] ) ? strtolower( trim( str_replace( ':', '', $parts[$i] ) ) ) : '';
                $value = isset( $parts[ $i + 1 ] ) ? trim( $parts[ $i + 1 ] ) : '';
                if ( empty( $key ) || empty( $value ) ) continue;

                if ( strpos( $key, 'focus keyword' ) !== false )        $structured_data['focus_keyword'] = $value;
                elseif ( strpos( $key, 'semantic keyword' ) !== false ) $structured_data['semantic_keyword'] = $value;
                elseif ( strpos( $key, 'title tag' ) !== false )        $titles_parsed[] = $value;
                elseif ( strpos( $key, 'meta description' ) !== false ) $descriptions_parsed[] = $value;
                elseif ( strpos( $key, 'h1 tag' ) !== false )           $structured_data['h1_tag'] = $value;
            }

            if (
                isset( $structured_data['focus_keyword'] ) &&
                isset( $structured_data['semantic_keyword'] ) &&
                count( $titles_parsed ) >= 3 &&
                count( $descriptions_parsed ) >= 3 &&
                isset( $structured_data['h1_tag'] )
            ) {
                $segments = [
                    $structured_data['focus_keyword'],
                    $structured_data['semantic_keyword'],
                    $titles_parsed[0], $titles_parsed[1], $titles_parsed[2],
                    $descriptions_parsed[0], $descriptions_parsed[1], $descriptions_parsed[2],
                    $structured_data['h1_tag'],
                ];
                seo_setup_log_meta_update( 'Label-based parsing successful.' );
            }
        }
    }

    // --- Final check ---
    if ( count( $segments ) < 9 ) {
        seo_setup_log_meta_update( "Output did not contain enough segments ({$context_label})." );
        return new WP_Error( 'parse_error', 'ChatGPT content not in expected $-delimited format.' );
    }

    $focus_keyword    = seo_setup_to_title_case( trim( str_replace( 'Focus Keyword:', '', $segments[0] ) ) );
    $semantic_keyword = seo_setup_to_title_case( trim( str_replace( 'Semantic Keyword:', '', $segments[1] ) ) );
    $titles = [
        seo_setup_to_meta_title_case( trim( str_replace( 'Title Tag:', '', $segments[2] ) ) ),
        seo_setup_to_meta_title_case( trim( str_replace( 'Title Tag:', '', $segments[3] ) ) ),
        seo_setup_to_meta_title_case( trim( str_replace( 'Title Tag:', '', $segments[4] ) ) ),
    ];
    $descriptions = [
        trim( str_replace( 'Meta Description:', '', $segments[5] ) ),
        trim( str_replace( 'Meta Description:', '', $segments[6] ) ),
        trim( str_replace( 'Meta Description:', '', $segments[7] ) ),
    ];
    $h1_tag = seo_setup_to_title_case( trim( str_replace( 'H1 Tag:', '', $segments[8] ) ) );

    usort( $titles, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
    usort( $descriptions, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

    return [
        'focus_keyword'    => $focus_keyword,
        'semantic_keyword' => $semantic_keyword,
        'titles'           => $titles,
        'descriptions'     => $descriptions,
        'h1_tag'           => $h1_tag,
    ];
}

// ============================================================================
// Prompt builders
// ============================================================================

/**
 * Build the prompt for pages WITH content.
 */
function seo_setup_build_content_prompt( $page_content, $page_slug, $page_title, $business_name_for_prompt ) {
    $prompt = "
    You are an expert SEO content strategist. Based on the provided page content, page slug, and business name, generate the following SEO elements.

    Page Content:
    {page_content}

    Page Slug: {$page_slug}
    Page Name: {$page_title}
    Business Name: {$business_name_for_prompt}

    ---

    ## Step 1: Focus Keyword and Semantic Keyword

    Analyze the page content and determine:

    **Focus Keyword (Primary Keyword):**
    - Identify one primary, highly relevant keyword.
    - It must NOT be the same as the Page Name.
    - It must NOT be the Business Name or any variation of the Business Name. The Business Name is NEVER a valid keyword.

    **Semantic Keyword:**
    - Identify one closely related semantic keyword.
    - It must NOT be the Business Name or any variation of the Business Name.

    ---

    ## Step 2: Meta Titles (STRICT ENFORCEMENT)

    Generate **exactly 3 meta titles** by following **all rules below without exception**.

    ### Hard Rules (Mandatory)
    1. Each meta title **MUST be between 30 and 60 characters (inclusive)**.
    2. You **MUST count characters**, including spaces and symbols.
    3. If a title exceeds 60 characters or is under 30 characters, you **MUST rewrite it** until it fits.
    4. **DO NOT output** any meta title that violates the character limit.
    5. To reduce length when needed, you may:
    - Remove or replace prepositions (by, with, from)
    - Remove articles (the, a, an)
    - Shorten the Primary Keyword (without changing intent)
    - Truncate the Page Name intelligently
    - Remove the Business Name if required to stay within limits

    ---

    ### Formatting Logic (Apply in Order)

    #### A. Home Page
    If the page slug is home, front-page, or empty:
    Format:
    Primary Keyword | {$business_name_for_prompt}

    CRITICAL: The Primary Keyword comes FIRST, then the pipe symbol |, then the Business Name. DO NOT reverse this order.

    #### B. Inner Pages
    1. **First Attempt (ONLY if <= 60 characters):** {$page_title} | Primary Keyword [preposition] {$business_name_for_prompt}
       Choose the most natural preposition for this page's context: at, by, from, with, or for.
       Examples: \'Contact Us | Web Design at Acme\', \'Blog | Marketing Tips from Acme\', \'About | Legal Help by Acme\'

    2. **Mandatory Fallback (If the above exceeds 60 characters):** Primary Keyword | {$page_title}

    3. **Secondary Fallback (If still > 60 characters):**
    - Shorten the Primary Keyword **or**
    - Truncate the Page Name while keeping clarity

    **Never use the default format if it exceeds 60 characters.**

    ---

    ### Special Character Restriction (STRICT)
    Meta titles MUST NOT include any of these characters:
    @ # ^ * ( ) _ + < > \" [ ] = / \\ ? ; & - -

    ### Final Validation (Required)
    After generating each meta title, **recount the characters**.
    If **any** title is outside the 30-60 character range, **regenerate it internally before output**.

    ---

    ## Step 3: Meta Descriptions

    Generate **exactly 3 meta descriptions** that follow all rules below.

    ### Rules
    1. Length must be **between 70 and 156 characters**.
    2. Each description **MUST use the exact same Primary Keyword** used in the Meta Titles.
    3. Structure requirements:
    - The **first 110-120 characters** must be a **complete standalone sentence**.
    - This first sentence **MUST start with a strong Call-to-Action (CTA)**.
    - This first sentence **MUST include both the Primary Keyword and {$business_name_for_prompt}**.
    4. The description must accurately represent the page content.
    5. Meta descriptions MUST NOT include any of these characters:
    @ # ^ * ( ) _ + < > \" [ ] = / \\ ? ; & - -

    ---

    ## Step 4: H1 Tag

    Generate **one H1 tag** that:
    Naturally includes the final Focus Keyword
    Is **between 20 and 45 characters**
    Clearly reflects the page topic

    ---

    ## Output Format (STRICT)

    Provide **ONLY the generated text**.
    Each of the **9 items** must be separated by a **single $ delimiter**.
    ";

    $prompt = str_replace( '{page_content}', $page_content, $prompt );
    return $prompt;
}

/**
 * Build the prompt for pages WITHOUT content (slug-only fallback).
 */
function seo_setup_build_fallback_prompt( $page_slug, $business_name_for_prompt, $domain ) {
    $prompt = "
    Task: You are an expert SEO content strategist. Generate SEO elements for a webpage that has no content, using only its URL slug, business name, and domain.

    **Inputs**
    URL Slug: {$page_slug}
    Business Name: {$business_name_for_prompt}
    Domain: {$domain}

    ---

    ### Step 1: Infer Keywords
    From the URL slug, infer:
    - **Page Name**
    - **Primary Keyword** (maximum 4 words)
    - **Semantic Keyword** (maximum 3 words)
    If the slug is home, front-page, or empty, treat it as the Home Page and infer a keyword representing the core business.

    **IMPORTANT:** The Primary Keyword and Semantic Keyword must NEVER be the Business Name or any variation of the Business Name. The Business Name is NOT a valid keyword.

    ---

    ### Step 2: Generate Meta Titles (CRITICAL)
    Generate **exactly 3 meta titles**.

    #### Hard Rules (Non-Negotiable)
    1. Character length **must be between 30 and 60 characters (inclusive)**.
    2. You **must explicitly count characters** before finalizing each title.
    3. If a title exceeds 60 characters, **rewrite it until it complies**.
    4. Do **not** add filler words or unnecessary adjectives.
    5. The business name **may be shortened or removed** if required to stay within limits.

    #### Allowed Formats
    **Home Page:** Primary Keyword | {$business_name_for_prompt}

    CRITICAL: The Primary Keyword comes FIRST, then the pipe symbol |, then the Business Name. DO NOT reverse this order.

    **Inner Pages:** Inferred Page Name | Primary Keyword
    **Fallback (if length exceeds 60):** Primary Keyword | Inferred Page Name

    > If **any** title exceeds 60 characters, regenerate **all three** titles.

    ### Special Character Restriction (STRICT)
    Meta titles MUST NOT include any of these characters:
    @ # ^ * ( ) _ + < > \" [ ] = / \\ ? ; & - -

    ---

    ### Step 3: Generate Meta Descriptions
    Generate **exactly 3 meta descriptions**.

    Rules:
    1. Length must be **70-156 characters**.
    2. Must use the **same Primary Keyword** as the meta titles.
    3. The **first 110-120 characters must be a complete sentence**.
    4. This first sentence must:
    - Start with a strong CTA (Discover, Get, Explore, Learn, Find)
    - Include both the **Primary Keyword** and **{$business_name_for_prompt}**
    5. Meta descriptions MUST NOT include any of these characters:
    @ # ^ * ( ) _ + < > \" [ ] = / \\ ? ; & - -

    ---

    ### Step 4: Generate H1 Tag
    Length: **20-45 characters**
    Must include the **Primary Keyword**

    ---

    ### Step 5: Output Validation (Mandatory)
    Before responding:
    Recheck character counts for:
    - All 3 meta titles
    - The H1 tag
    Fix any violations silently.
    Output **only validated results**.

    ---

    ### Output Format (Strict)
    Provide **ONLY the generated text**.
    **DO NOT** use labels like 'Focus Keyword:'.
    **DO NOT** use numbering.
    Separate each of the 9 items using a single $ delimiter.

    **Required Output Structure:**
    Focus Keyword $ Semantic Keyword $ Title 1 $ Title 2 $ Title 3 $ Desc 1 $ Desc 2 $ Desc 3 $ H1 Tag
    ";

    return $prompt;
}

// ============================================================================
// Validation + fallback chain (ported from Python _process_single_page_meta)
// ============================================================================

/**
 * Apply the multi-step validation and fallback chain to a parsed meta result.
 *
 * Step 1: Check if the first title and description pass validation
 * Step 2: If not, retry with abbreviated business name
 * Step 3: If that fails, strict retry to fix specific fields
 * Step 3.5: If page name > 25 chars and title still invalid, retry with
 *           forced format "Primary Keyword | Business Name" (no page name)
 * Step 4: If everything fails, pick the best from all generated options
 *
 * @param array  $parsed_result
 * @param string $api_url
 * @param string $api_key
 * @param string $page_slug
 * @param string $page_title
 * @param string $page_content
 * @param string $business_name
 * @param string $domain
 * @param bool   $has_content
 * @return array
 */
function seo_setup_apply_validation_chain( $parsed_result, $api_url, $api_key, $page_slug, $page_title, $page_content, $business_name, $domain, $has_content ) {
    $titles       = $parsed_result['titles'];
    $descriptions = $parsed_result['descriptions'];

    $all_titles       = $titles;
    $all_descriptions = $descriptions;

    // --- Step 1: Validate the best title and description from the initial response ---
    $best_title = seo_setup_pick_best_title( $titles );
    if ( seo_setup_is_home_page_context( $page_slug, $page_title ) ) {
        $best_title = seo_setup_build_home_page_meta_title( $parsed_result['focus_keyword'], $business_name );
        $all_titles[] = $best_title;
    } else {
        $best_title = seo_setup_pick_inner_page_meta_title( $best_title, $page_title, $parsed_result['focus_keyword'], $business_name );
        $all_titles[] = $best_title;
    }
    $best_desc = seo_setup_pick_best_description( $descriptions );

    if ( seo_setup_validate_meta_lengths( $best_title, $best_desc ) ) {
        seo_setup_log_meta_update( "Step 1 PASSED for {$page_slug}: title_len=" . strlen( $best_title ) . ", desc_len=" . strlen( $best_desc ) );
        $parsed_result['titles'][0]       = $best_title;
        $parsed_result['descriptions'][0] = $best_desc;
        return $parsed_result;
    }

    seo_setup_log_meta_update( "Step 1 FAILED for {$page_slug}: title_len=" . strlen( $best_title ) . ", desc_len=" . strlen( $best_desc ) . ". Trying abbreviated business name." );

    // --- Step 2: Retry with abbreviated business name ---
    if ( strlen( $business_name ) > SEO_SETUP_BUSINESS_NAME_ABBR_THRESHOLD ) {
        $abbr_name = seo_setup_get_business_name_for_prompt( $business_name );

        if ( $has_content ) {
            $retry_prompt = seo_setup_build_content_prompt( $page_content, $page_slug, $page_title, $abbr_name );
        } else {
            $retry_prompt = seo_setup_build_fallback_prompt( $page_slug, $abbr_name, $domain );
        }

        $retry_result = seo_setup_call_openai_and_parse( $api_url, $api_key, $retry_prompt, 'abbr_retry' );

        if ( ! is_wp_error( $retry_result ) ) {
            $all_titles       = array_merge( $all_titles, $retry_result['titles'] );
            $all_descriptions = array_merge( $all_descriptions, $retry_result['descriptions'] );

            $retry_title = seo_setup_pick_best_title( $retry_result['titles'] );
            if ( seo_setup_is_home_page_context( $page_slug, $page_title ) ) {
                $retry_title = seo_setup_build_home_page_meta_title( $retry_result['focus_keyword'], $abbr_name );
                $all_titles[] = $retry_title;
            } else {
                $retry_title = seo_setup_pick_inner_page_meta_title( $retry_title, $page_title, $retry_result['focus_keyword'], $business_name );
                $all_titles[] = $retry_title;
            }
            $retry_desc = seo_setup_pick_best_description( $retry_result['descriptions'] );

            if ( seo_setup_validate_meta_lengths( $retry_title, $retry_desc ) ) {
                seo_setup_log_meta_update( "Step 2 PASSED (abbreviated name) for {$page_slug}: title_len=" . strlen( $retry_title ) . ", desc_len=" . strlen( $retry_desc ) );
                $retry_result['titles'][0]       = $retry_title;
                $retry_result['descriptions'][0] = $retry_desc;
                return $retry_result;
            }

            seo_setup_log_meta_update( "Step 2 FAILED for {$page_slug}: title_len=" . strlen( $retry_title ) . ", desc_len=" . strlen( $retry_desc ) . ". Trying strict retry." );

            // --- Step 3: Strict retry ---
            $strict_result = seo_setup_strict_retry_meta( $api_url, $api_key, $page_slug, $page_title, $business_name, $retry_title, $retry_desc );

            if ( $strict_result ) {
                seo_setup_log_meta_update( "Step 3 PASSED (strict retry) for {$page_slug}" );
                $retry_result['titles'][0]       = $strict_result['title'];
                $retry_result['descriptions'][0] = $strict_result['description'];
                return $retry_result;
            }

            seo_setup_log_meta_update( "Step 3 FAILED for {$page_slug}. Using best available from all attempts." );
        } else {
            seo_setup_log_meta_update( "Step 2 API call failed for {$page_slug}: " . $retry_result->get_error_message() );
        }
    } else {
        // Business name is short, skip abbreviation — go straight to strict retry
        seo_setup_log_meta_update( "Step 2 SKIPPED (business name <= " . SEO_SETUP_BUSINESS_NAME_ABBR_THRESHOLD . " chars). Trying strict retry." );

        $strict_result = seo_setup_strict_retry_meta( $api_url, $api_key, $page_slug, $page_title, $business_name, $best_title, $best_desc );

        if ( $strict_result ) {
            seo_setup_log_meta_update( "Step 3 PASSED (strict retry) for {$page_slug}" );
            $parsed_result['titles'][0]       = $strict_result['title'];
            $parsed_result['descriptions'][0] = $strict_result['description'];
            return $parsed_result;
        }

        seo_setup_log_meta_update( "Step 3 FAILED for {$page_slug}. Using best available from all attempts." );
    }

    // --- Step 3.5: Long page name fallback ---
    $current_best_title = seo_setup_pick_best_title( $all_titles );
    $current_best_title_len = strlen( $current_best_title );
    $title_valid = ( $current_best_title_len >= SEO_SETUP_META_TITLE_MIN && $current_best_title_len <= SEO_SETUP_META_TITLE_MAX );

    if ( ! $title_valid && strlen( $page_title ) > SEO_SETUP_BUSINESS_NAME_ABBR_THRESHOLD ) {
        seo_setup_log_meta_update( "Step 3.5: Page name is long (" . strlen( $page_title ) . " chars). Trying 'Primary Keyword | Business Name' format for {$page_slug}" );

        $long_page_result = seo_setup_long_page_name_retry( $api_url, $api_key, $page_slug, $page_title, $page_content, $business_name, $domain, $has_content );

        if ( ! is_wp_error( $long_page_result ) ) {
            $all_titles       = array_merge( $all_titles, $long_page_result['titles'] );
            $all_descriptions = array_merge( $all_descriptions, $long_page_result['descriptions'] );

            $lp_title = seo_setup_pick_best_title( $long_page_result['titles'] );
            $lp_desc  = seo_setup_pick_best_description( $long_page_result['descriptions'] );

            if ( seo_setup_validate_meta_lengths( $lp_title, $lp_desc ) ) {
                seo_setup_log_meta_update( "Step 3.5 PASSED (long page name fallback) for {$page_slug}: title_len=" . strlen( $lp_title ) . ", desc_len=" . strlen( $lp_desc ) );
                $long_page_result['titles'][0]       = $lp_title;
                $long_page_result['descriptions'][0] = $lp_desc;
                return $long_page_result;
            }

            seo_setup_log_meta_update( "Step 3.5 FAILED for {$page_slug}: title_len=" . strlen( $lp_title ) . ", desc_len=" . strlen( $lp_desc ) );
        } else {
            seo_setup_log_meta_update( "Step 3.5 API call failed for {$page_slug}: " . $long_page_result->get_error_message() );
        }
    }

    // --- Step 4: Pick the best from ALL generated options across all attempts ---
    $final_title = seo_setup_pick_best_title( $all_titles );
    if ( seo_setup_is_home_page_context( $page_slug, $page_title ) ) {
        $final_title = seo_setup_build_home_page_meta_title( $parsed_result['focus_keyword'], $business_name );
    } else {
        $final_title = seo_setup_pick_inner_page_meta_title( $final_title, $page_title, $parsed_result['focus_keyword'], $business_name );
    }
    $final_desc = seo_setup_pick_best_description( $all_descriptions );

    seo_setup_log_meta_update( "Step 4 (pick best): title_len=" . strlen( $final_title ) . ", desc_len=" . strlen( $final_desc ) . " for {$page_slug}" );

    $parsed_result['titles'][0]       = $final_title;
    $parsed_result['descriptions'][0] = $final_desc;
    return $parsed_result;
}

// ============================================================================
// Main generation functions
// ============================================================================

/**
 * Generate meta for pages WITH content.
 *
 * @param string $page_content
 * @param string $page_slug
 * @param string $page_title
 * @return array|WP_Error
 */
function seo_setup_generate_meta_for_content($page_content, $page_slug, $page_title)
{
    $business_name = get_bloginfo('name');

    if (empty($business_name)) {
        seo_setup_log_meta_update('Business name is empty');
        return new WP_Error('business_name_empty', 'Business name is empty');
    }

    $credentials = seo_setup_get_api_credentials_by_product( 'OPEN AI' );
    if ( ! $credentials ) {
        seo_setup_log_meta_update( 'Missing API credentials for OPEN AI.' );
        return new WP_Error( 'missing_api_credentials', 'API credentials for OpenAI are not available.' );
    }
    $api_key = $credentials['key'];
    $api_url = $credentials['endpoint'];
    $domain  = parse_url( home_url(), PHP_URL_HOST );

    $prompt = seo_setup_build_content_prompt( $page_content, $page_slug, $page_title, $business_name );
    $parsed = seo_setup_call_openai_and_parse( $api_url, $api_key, $prompt, 'meta_for_content' );

    if ( is_wp_error( $parsed ) ) {
        return $parsed;
    }

    $validated = seo_setup_apply_validation_chain(
        $parsed, $api_url, $api_key,
        $page_slug, $page_title, $page_content,
        $business_name, $domain, true
    );

    return $validated;
}

/**
 * Generate meta details for pages WITHOUT content (slug-only fallback).
 *
 * @param string $page_slug
 * @return array|WP_Error
 */
function seo_setup_generate_meta_for_fallback($page_slug)
{
    $business_name = get_bloginfo('name');

    if (empty($business_name)) {
        seo_setup_log_meta_update('Business name is empty');
        return new WP_Error('business_name_empty', 'Business name is empty');
    }

    $domain = parse_url( home_url(), PHP_URL_HOST );

    $credentials = seo_setup_get_api_credentials_by_product( 'OPEN AI' );
    if ( ! $credentials ) {
        seo_setup_log_meta_update( 'Missing API credentials for OPEN AI (fallback).' );
        return new WP_Error( 'missing_api_credentials', 'API credentials for OpenAI are not available (fallback).' );
    }
    $api_key = $credentials['key'];
    $api_url = $credentials['endpoint'];

    $prompt = seo_setup_build_fallback_prompt( $page_slug, $business_name, $domain );
    $parsed = seo_setup_call_openai_and_parse( $api_url, $api_key, $prompt, 'fallback' );

    if ( is_wp_error( $parsed ) ) {
        return $parsed;
    }

    $validated = seo_setup_apply_validation_chain(
        $parsed, $api_url, $api_key,
        $page_slug, $page_slug, '',
        $business_name, $domain, false
    );

    return $validated;
}

// ============================================================================
// Page builder content extraction
// ============================================================================

/**
 * Recursively walk Elementor element tree and collect text.
 */
function seo_setup_walk_elementor_elements( $elements, &$texts ) {
    foreach ( $elements as $element ) {
        if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
            foreach ( array( 'text', 'editor', 'title', 'description', 'content', 'html' ) as $field ) {
                if ( ! empty( $element['settings'][ $field ] ) && is_string( $element['settings'][ $field ] ) ) {
                    $cleaned = trim( wp_strip_all_tags( $element['settings'][ $field ] ) );
                    if ( ! empty( $cleaned ) ) {
                        $texts[] = $cleaned;
                    }
                }
            }
        }
        if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
            seo_setup_walk_elementor_elements( $element['elements'], $texts );
        }
    }
}

/**
 * Recursively walk Bricks Builder element tree and collect text.
 */
function seo_setup_walk_bricks_elements( $elements, &$texts ) {
    foreach ( $elements as $element ) {
        if ( isset( $element['settings'] ) ) {
            $settings = is_array( $element['settings'] ) ? $element['settings'] : (array) $element['settings'];
            foreach ( array( 'text', 'content', 'heading', 'title', 'description', 'html' ) as $field ) {
                if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
                    $cleaned = trim( wp_strip_all_tags( $settings[ $field ] ) );
                    if ( ! empty( $cleaned ) ) {
                        $texts[] = $cleaned;
                    }
                }
            }
        }
        if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
            seo_setup_walk_bricks_elements( $element['children'], $texts );
        }
    }
}

/**
 * Extract readable text from page builder–stored content when post_content is empty.
 * Covers Elementor, Beaver Builder, Bricks, Divi, WPBakery, Oxygen.
 *
 * @param int    $post_id          Post ID.
 * @param string $raw_post_content Raw post_content before any stripping.
 * @return string Plain text, or empty string if nothing found.
 */
function seo_setup_get_page_builder_content( $post_id, $raw_post_content ) {

    // Elementor — actual content lives in _elementor_data meta as JSON
    $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
    if ( ! empty( $elementor_data ) ) {
        if ( is_array( $elementor_data ) ) {
            $elements = $elementor_data;
        } else {
            $elements = json_decode( $elementor_data, true );
        }
        if ( is_array( $elements ) ) {
            $texts = array();
            seo_setup_walk_elementor_elements( $elements, $texts );
            $result = trim( implode( ' ', array_filter( array_map( 'trim', $texts ) ) ) );
            if ( ! empty( $result ) ) {
                return $result;
            }
        }
    }

    // Beaver Builder — content in _fl_builder_data meta as serialized objects
    $bb_data = get_post_meta( $post_id, '_fl_builder_data', true );
    if ( ! empty( $bb_data ) && is_array( $bb_data ) ) {
        $texts = array();
        foreach ( $bb_data as $node ) {
            if ( isset( $node->settings ) ) {
                $settings = (array) $node->settings;
                foreach ( array( 'text', 'content', 'title', 'html', 'heading' ) as $field ) {
                    if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
                        $cleaned = trim( wp_strip_all_tags( $settings[ $field ] ) );
                        if ( ! empty( $cleaned ) ) {
                            $texts[] = $cleaned;
                        }
                    }
                }
            }
        }
        $result = trim( implode( ' ', array_filter( $texts ) ) );
        if ( ! empty( $result ) ) {
            return $result;
        }
    }

    // Bricks Builder — content in post meta as JSON (multiple known keys)
    foreach ( array( '_bricks_page_content_2', 'bricks_data', '_bricks_data' ) as $meta_key ) {
        $bricks_data = get_post_meta( $post_id, $meta_key, true );
        if ( ! empty( $bricks_data ) ) {
            $elements = is_string( $bricks_data ) ? json_decode( $bricks_data, true ) : $bricks_data;
            if ( is_array( $elements ) ) {
                $texts = array();
                seo_setup_walk_bricks_elements( $elements, $texts );
                $result = trim( implode( ' ', array_filter( array_map( 'trim', $texts ) ) ) );
                if ( ! empty( $result ) ) {
                    return $result;
                }
            }
        }
    }

    // Divi / WPBakery — shortcodes stored in post_content; strip tag markers, keep inner text
    if ( ! empty( $raw_post_content ) ) {
        $content = preg_replace( '/\[[^\]]*\]/', ' ', $raw_post_content );
        $content = wp_strip_all_tags( $content );
        $content = trim( preg_replace( '/\s+/', ' ', $content ) );
        if ( ! empty( $content ) ) {
            return $content;
        }
    }

    // Oxygen Builder — shortcode content stored in ct_builder_shortcodes meta
    $oxygen_data = get_post_meta( $post_id, 'ct_builder_shortcodes', true );
    if ( ! empty( $oxygen_data ) ) {
        $content = preg_replace( '/\[[^\]]*\]/', ' ', $oxygen_data );
        $content = wp_strip_all_tags( $content );
        $content = trim( preg_replace( '/\s+/', ' ', $content ) );
        if ( ! empty( $content ) ) {
            return $content;
        }
    }

    return '';
}

// ============================================================================
// AJAX handlers
// ============================================================================

/**
 * AJAX handler for "Generate Meta Titles & Descriptions".
 */
function seo_setup_ajax_generate_meta()
{
    check_ajax_referer( 'seo_setup_nonce', '_ajax_nonce' );

    if (function_exists('seo_setup_logger_meta_audit_start')) {
        seo_setup_logger_meta_audit_start();
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    set_time_limit(600);

    $all_pages = get_pages(['post_status' => 'publish']);
    if (empty($all_pages)) {
        wp_send_json_success(['data' => []]);
        return;
    }

    $pages_with_content = [];
    $pages_without_content = [];

    foreach ($all_pages as $page) {
        if (empty(trim(strip_tags($page->post_content)))) {
            $pages_without_content[] = $page;
        } else {
            $pages_with_content[] = $page;
        }
    }

    $ordered_pages = array_merge($pages_with_content, $pages_without_content);

    $page_count  = count($ordered_pages);
    $batch_index = isset($_POST['batch_index']) ? (int)$_POST['batch_index'] : 0;
    $batch_size  = defined('SEO_SETUP_BATCH_SIZE') ? (int) SEO_SETUP_BATCH_SIZE : 1;
    $batch_size  = max( 1, $batch_size );

    $start   = $batch_index * $batch_size;
    $end     = min($start + $batch_size, $page_count);
    $results = [];

    for ($i = $start; $i < $end; $i++) {
        $page       = $ordered_pages[$i];
        $page_id    = $page->ID;
        $page_title = $page->post_title;
        $page_url   = get_permalink($page_id);

        $raw_post_content = $page->post_content;
        $content          = strip_shortcodes($raw_post_content);
        $content          = preg_replace('/<form\b[^>]*>.*?<\/form>/is', '', $content);
        $page_content     = wp_strip_all_tags($content);

        // If post_content is empty, try page builder meta
        if ( empty( trim( strip_tags( $page_content ) ) ) ) {
            $page_content = seo_setup_get_page_builder_content( $page_id, $raw_post_content );
        }

        if ( empty( trim( $page_content ) ) ) {
            $meta_data = seo_setup_generate_meta_for_fallback($page->post_name);
        } else {
            seo_setup_log_meta_update("Generating meta for page ID $page_id (has content).");
            $meta_data = seo_setup_generate_meta_for_content($page_content, $page->post_name, $page_title);
        }

        $current_meta_title = get_post_meta($page_id, '_yoast_wpseo_title', true);
        $current_meta_desc  = get_post_meta($page_id, '_yoast_wpseo_metadesc', true);

        if (is_wp_error($meta_data)) {
            $error_code    = $meta_data->get_error_code();
            $error_message = $meta_data->get_error_message();
            seo_setup_log_meta_update("Page ID {$page_id} - Error from ChatGPT: {$error_code} | {$error_message}");

            if (
                $error_code === 'parse_error' &&
                $error_message === 'ChatGPT content not in expected $-delimited format.'
            ) {
                seo_setup_log_meta_update("Page ID {$page_id} - Attempting fallback after parse_error.");
                $fallback_meta_data = seo_setup_generate_meta_for_fallback($page->post_name);

                if (is_wp_error($fallback_meta_data)) {
                    $results[] = [
                        'page_id'    => $page_id,
                        'page_title' => $page_title,
                        'page_url'   => $page_url,
                        'error'      => $fallback_meta_data->get_error_message(),
                    ];
                } else {
                    $results[] = [
                        'page_id'                  => $page_id,
                        'page_title'               => $page_title,
                        'page_url'                 => $page_url,
                        'focus_keyword'            => $fallback_meta_data['focus_keyword'],
                        'semantic_keyword'         => $fallback_meta_data['semantic_keyword'],
                        'titles'                   => $fallback_meta_data['titles'],
                        'descriptions'             => $fallback_meta_data['descriptions'],
                        'h1_tag'                   => $fallback_meta_data['h1_tag'],
                        'current_meta_title'       => $current_meta_title,
                        'current_meta_description' => $current_meta_desc,
                    ];
                }
            } else {
                $results[] = [
                    'page_id'    => $page_id,
                    'page_title' => $page_title,
                    'page_url'   => $page_url,
                    'error'      => $meta_data->get_error_message(),
                ];
            }
        } else {
            $results[] = [
                'page_id'                  => $page_id,
                'page_title'               => $page_title,
                'page_url'                 => $page_url,
                'focus_keyword'            => $meta_data['focus_keyword'],
                'semantic_keyword'         => $meta_data['semantic_keyword'],
                'titles'                   => $meta_data['titles'],
                'descriptions'             => $meta_data['descriptions'],
                'h1_tag'                   => $meta_data['h1_tag'],
                'current_meta_title'       => $current_meta_title,
                'current_meta_description' => $current_meta_desc,
            ];
        }
    }

    $completed_batches = $batch_index + 1;
    $total_batches     = ($batch_size > 0) ? ceil($page_count / $batch_size) : 0;
    $progress          = ($total_batches > 0) ? ($completed_batches / $total_batches) * 100 : 0;

    wp_send_json_success([
        'data'          => $results,
        'batch_index'   => $completed_batches,
        'total_batches' => $total_batches,
        'progress'      => $progress
    ]);
}
add_action('wp_ajax_seo_setup_generate_meta', 'seo_setup_ajax_generate_meta');

/**
 * AJAX handler to save updated meta data back to Yoast or your custom fields.
 */
function seo_setup_ajax_save_meta()
{
    check_ajax_referer( 'seo_setup_nonce', '_ajax_nonce' );

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    if (empty($_POST['pages']) || !is_array($_POST['pages'])) {
        wp_send_json_error('No page data found.');
        return;
    }

    foreach ($_POST['pages'] as $page_data) {
        $page_id       = isset($page_data['page_id']) ? (int) $page_data['page_id'] : 0;
        $focus_keyword = isset($page_data['focus_keyword']) ? sanitize_text_field($page_data['focus_keyword']) : '';
        $meta_title    = isset($page_data['meta_title']) ? sanitize_text_field($page_data['meta_title']) : '';
        $meta_desc     = isset($page_data['meta_desc']) ? sanitize_text_field($page_data['meta_desc']) : '';

        if ($page_id > 0) {
            update_post_meta($page_id, '_yoast_wpseo_title', $meta_title);
            update_post_meta($page_id, '_yoast_wpseo_metadesc', $meta_desc);

            seo_setup_log_meta_update("Meta updated for Page ID {$page_id}.");
        }
    }

    wp_send_json_success(['message' => 'Successfully saved meta data.']);
}
add_action('wp_ajax_seo_setup_save_meta', 'seo_setup_ajax_save_meta');
