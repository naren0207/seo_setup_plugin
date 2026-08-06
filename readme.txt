=== SEO Setup ===
Contributors: vSplash
Donate link: https://vsplashtechlabs.com
Tags: seo, alt text, meta titles, meta descriptions, schema markup, sitemap, canonical tags, open graph, robots txt, featured images
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.0.8
Stable tag: 2.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete SEO automation plugin that generates alt text, meta titles, meta descriptions, schema markup, sitemaps, canonical tags, open graph data, and more — powered by OpenAI GPT-5 mini.

== Description ==

SEO Setup is a comprehensive WordPress SEO automation plugin built by vSplash Techlabs. It covers every major on-page and technical SEO element through a clean tabbed admin interface. All AI-powered features use OpenAI GPT-5 mini via a secure, token-authenticated credential system.

= Features =

**Generate Alt Text**
Scans your entire media library for images missing alt text. For each image, it resizes to 512x512 pixels (client-side via Canvas API), sends it to OpenAI GPT-5 mini with a vision prompt, and writes the generated alt text back to the image attachment. Simultaneously copies any existing alt text into the image title field for consistency. Results can be downloaded as a CSV report. Images already having alt text are listed separately with their updated title fields.

**Page Names**
Sanitizes and normalises slugs for all public post types so they accurately reflect the page title. Old URLs are captured and stored in a custom database table, and 301 redirects are automatically created. A must-use plugin (`seo_setup_redirects.php`) is written to `wp-content/mu-plugins/` on demand to ensure redirects survive plugin deactivation. The Simple 301 Redirects plugin is silently installed and activated if not already present.

**Meta Titles and Meta Descriptions (AI-Powered)**
Generates SEO-optimised meta titles (30–60 characters) and meta descriptions (70–156 characters) for every published page using a multi-step AI pipeline:

1. Page content is extracted from `post_content`. For pages built with **Elementor**, **Beaver Builder**, **Bricks Builder**, **Divi**, **WPBakery**, **Oxygen Builder**, or any other page builder, content is extracted directly from the builder's stored data (post meta JSON, serialised objects, or shortcode bodies) so no content is missed.
2. A structured prompt is sent to OpenAI GPT-5 mini requesting a Focus Keyword, Semantic Keyword, 3 meta title candidates, 3 meta description candidates, and an H1 tag — all returned as a `$`-delimited string.
3. Results go through a 6-step validation and fallback chain:
   - Step 1: Strict local enforcement of length and format rules.
   - Step 2: Keyword consistency fix if the keyword is missing from the description.
   - Step 3: Retry with an abbreviated business name if the name is too long.
   - Step 4: Strict retry with a constrained prompt targeting specific failing fields.
   - Step 5: Long page name retry using `Keyword | Business Name` format (no page name).
   - Step 6: Local deterministic rescue — no API call, generates valid output from slug and business name.
4. Cross-batch deduplication ensures no two pages receive the same meta title or description within a single run.
5. Final values are written to Yoast SEO meta fields (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`) and the H1 tag is stored separately.

Pages with no content (landing pages, page-builder-only pages with no detectable text) fall back to slug-based generation using only the URL slug, page name, business name, and domain — still producing valid, unique SEO meta.

**Featured Images**
Automatically sets a featured image for every published post and page that does not already have one. The plugin tries three sources in order: the first image found in the page content, the site logo, and finally a random image from the media library.

**Open Graph Tags**
Updates Open Graph and Twitter Card meta tags for all published posts, pages, and WooCommerce products. Writes to Yoast SEO's OG and Twitter meta fields (`_yoast_wpseo_opengraph-title`, `_yoast_wpseo_opengraph-description`, `_yoast_wpseo_opengraph-image`, `_yoast_wpseo_twitter-title`, `_yoast_wpseo_twitter-description`, `_yoast_wpseo_twitter-image`) with intelligent fallbacks for title, description, and image.

**Canonical Tags**
Generates and enforces canonical URLs for all published posts and pages. Removes the default WordPress canonical output and suppresses Yoast SEO's canonical to avoid conflicts. Canonical values are stored in post meta and output at priority 1 in `wp_head`. A must-use plugin (`seo-canonical-deactivation.php`) is written to `wp-content/mu-plugins/` to persist canonical tags even if the main plugin is deactivated. Slugs are updated and the canonical is kept in sync automatically on post save and permalink structure changes.

**XML Sitemap**
Generates a standards-compliant `sitemap.xml` at the WordPress root. Processes all public post types in batches of 500. The home page receives priority 1.0; all other URLs receive 0.8. A rewrite rule serves the file at `/sitemap.xml`. Yoast SEO's built-in XML sitemap is disabled automatically to prevent duplication.

**Image Sitemap**
Generates a dedicated `image-sitemap.xml` at the WordPress root, containing entries for every image attachment in the media library. Processed in batches of 500. A rewrite rule serves the file at `/image-sitemap.xml`.

**Robots.txt**
Writes a `robots.txt` file to the WordPress root with standard WordPress disallow rules, sitemap references (both `sitemap.xml` and `image-sitemap.xml`), and a block for the MJ12bot crawler. Also hooks into WordPress's dynamic robots.txt filter to append sitemap lines when the physical file is not present.

**Schema Markup**
Generates and injects structured data (JSON-LD) via `wp_head`. Two organisation-level schema types are supported:

- *Organisation Schema* — includes business name, URL, logo, email, phone (formatted to E.164 via libphonenumber), and social profile URLs (Facebook, Twitter/X, Instagram, LinkedIn, YouTube, Pinterest, TikTok, Reddit, Tumblr). Social links are scraped from the site's header and footer HTML.
- *Local Business Schema* — extends Organisation with address (extracted from header/footer HTML by OpenAI), geo coordinates (extracted from embedded Google Maps iframes, or geocoded via Nominatim/OpenStreetMap as a fallback), and opening hours (parsed from site content using a multi-pattern regex engine covering 10+ business hours formats).

Additionally, per-page schema is generated in batches for all published posts, pages, and WooCommerce products: WebPage, Article, Product, Event, and BreadcrumbList types are supported. A must-use plugin (`seo-schema-persistence.php`) is written to `wp-content/mu-plugins/` to keep schema active after plugin deactivation.

= Technical Details =

* **AI Model**: OpenAI GPT-5 mini (`gpt-5-mini`) for all text and vision generation tasks.
* **Authentication**: All API credentials are retrieved via a token validated against the vSplash partner API. Credentials are stored encrypted (AES-256-CBC) in WordPress options with a 24-hour expiry per domain.
* **REST API**: All AJAX operations run through a custom REST API namespace (`/wp-json/seo-setup/v1/`) to avoid conflicts with other plugins using `admin-ajax.php`.
* **Batched processing**: All heavy operations (meta generation, schema, sitemaps, alt text) are batched server-side or client-side to handle large sites without timeouts.
* **Page builder support**: Meta generation extracts content from Elementor (`_elementor_data`), Beaver Builder (`_fl_builder_data`), Bricks Builder, Divi, WPBakery, and Oxygen Builder — falling back to slug-only generation only when no content can be found anywhere.
* **Yoast SEO integration**: Reads and writes all standard Yoast meta fields. Suppresses Yoast's canonical output and XML sitemap when the plugin's own versions are active.
* **Must-use plugins**: Three MU plugins are written on demand (not at activation) to persist redirects, canonical tags, and schema markup independently of the main plugin's activation state.
* **Composer dependencies**: `commerceguys/addressing` (v1.0.7), `giggsey/libphonenumber-for-php` (v8.12.5), `giggsey/locale` (v1.9.0).

== Installation ==

1. Upload the `seo-setup` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Navigate to **SEO Setup** in the WordPress admin menu.
4. Enter your vSplash access token when prompted. The token is validated and API credentials are stored automatically.
5. Use each tab to run the corresponding SEO operation.

== Frequently Asked Questions ==

= Does this plugin require an OpenAI API key? =
No. API keys are managed centrally through the vSplash partner platform. You only need a valid vSplash access token, which is entered once in the plugin settings.

= Will it work with Elementor, Divi, or other page builders? =
Yes. The meta title and description generator extracts content directly from page builder data stored in post meta — it does not rely on `post_content` alone. Elementor, Beaver Builder, Bricks Builder, Divi, WPBakery, and Oxygen Builder are all supported.

= Does it conflict with Yoast SEO? =
It is designed to work alongside Yoast SEO. It writes to Yoast's meta fields so all values appear correctly in Yoast's interface. It suppresses Yoast's canonical output and XML sitemap only when the plugin's own versions are active.

= What happens to redirects and schema if I deactivate the plugin? =
Must-use plugins written to `wp-content/mu-plugins/` keep redirects, canonical tags, and schema markup active even after the main plugin is deactivated.

= Is page content sent to OpenAI? =
Yes. For meta title and description generation, the plain text of each page (stripped of HTML and shortcodes) is included in the prompt sent to OpenAI GPT-5 mini. For alt text generation, images are resized to 512x512 pixels in the browser and sent as base64-encoded data.

== Screenshots ==

1. Admin dashboard with tabbed navigation covering all SEO operations.
2. Alt text generation progress view with batch status and CSV download.
3. Meta title and description generator with per-page results and inline editing.
4. Schema markup tab with Organisation and LocalBusiness generation options.

== Changelog ==

= 2.0.0 =
* Added page builder content extraction for Elementor, Beaver Builder, Bricks, Divi, WPBakery, and Oxygen Builder — meta generation now uses real page content instead of falling back to slug-only for page builder pages.
* Full REST API migration — all operations now run through `/wp-json/seo-setup/v1/` instead of `admin-ajax.php`.
* Added 6-step meta validation and fallback chain with cross-batch deduplication.
* Added Image Sitemap generation.
* Added Canonical Tags management with must-use plugin persistence.
* Added Open Graph and Twitter Card updates for posts, pages, and WooCommerce products.
* Added LocalBusiness schema with address extraction via OpenAI, geo coordinates from Google Maps iframes, and opening hours parsing.
* Added per-page schema batch generation (WebPage, Article, Product, Event, BreadcrumbList).
* Added CSV download for alt text generation results.
* Added must-use plugin persistence for redirects, canonical tags, and schema markup.

= 1.0.0 =
* Initial release.
= 1.1 =
* Implemented new meta title formats.
= 1.1.1 =
* Added business name shortname validation and generation.