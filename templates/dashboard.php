<main id="seo-setup-container">
    <header class="seo-setup-header">
        <h1>SEO Setup</h1>
        <img src="<?php echo esc_url(plugins_url('assets/images/logo.png', __DIR__)); ?>" alt="Logo">
    </header>
    <section class="seo-setup-content-area">
        <aside class="seo-setup-side-navigation">
            <button class="seo-setup-tab-buttons seo-setup-current seo-setup-sidebar-tab-button"
                onclick="showPage(0)">Generate Alt Text</button>
            <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(1)">Page Names</button>
            <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(2)">Title Tags &
                Descriptions</button>
            <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(3)">Featured
                Images</button>
            <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(4)">Open
                Graphs</button>
                <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(5)">Canonical
                Tags</button>
            <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(6)">XML Sitemap</button>
            <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(7)">Image Sitemap</button>
            <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(8)">Robots.txt</button>
            <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(9)">Schema Markup</button>
            <button class="seo-setup-tab-buttons seo-setup-sidebar-tab-button" onclick="showPage(10)">Analyze Image Alt Text</button>
        </aside>
        <div class="seo-setup-tab-content">
            <section class="seo-setup-content-tabs">
                <article class="seo-setup-tab-content-show seo-setup-current">
                    <h2 class="seo-setup-content-title">Generate Alt Text</h2>
                    <p>Alt Text will be generated</p>
                    <div id="setup-alt-text-buttons">
                    <button class="seo-setup-cta-button" id="seo-setup-generate-alt-text-button">Generate Alt Text</button>
                    <button class="seo-setup-cta-button" id="seo-setup-get-urls-without-alt-button" style="margin-left:10px;">Get URLs Without Alt Text</button>
                    </div>
                    <div id="seo-setup-generateAltText-progress"></div>
                    <div id="seo-setup-missing-alt-table" style="margin-top: 20px;"></div>
                    <div id="seo-setup-existing-alt-table" style="margin-top: 20px;"></div>

                </article>
                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">Update Page Names</h2>
                    <p>Fix page slugs based on the page names.</p>
                    <button class="seo-setup-cta-button" id="seo-setup-page-names-button">Page Names</button>
                    <section class="seo-setup-tab-sub-content seo-setup-generated-content"
                        id="seo-setup-page-names-results" style="display: none;">

                    </section>
                </article>
                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">Update Meta Title & Meta Description</h2>
                    <p>Update Meta Title & Meta Description in the published pages.</p>

                    <!-- The button your JS is looking for -->
                    <button class="seo-setup-cta-button" id="seo-setup-generate-meta-button">
                        Generate Meta Titles & Descriptions
                    </button>

                    <!-- Container to display the results from AJAX -->
                    <div id="title-descriptions-results" style="margin-top: 20px;">
                        <!-- Will be populated by script.js after AJAX call -->
                    </div>
                </article>
                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">Set Featured Images</h2>
                    <p>Set featured images for all published pages that do not already have one.</p>
                    <button class="seo-setup-cta-button" id="set-featured-images-btn">Set Featured Images</button>
                    <div id="featured-images-results" style="margin-top:20px;"></div>
                </article>

                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">Update Open Graphs</h2>
                    <p>Update Open Graphs for all published posts, pages, and products.</p>

                    <button class="seo-setup-cta-button" id="update-open-graphs-btn">Update Open Graphs</button>

                    <div id="open-graphs-loader" style="display: none;">
                        <p>Updating Open Graphs... Please wait.</p>
                    </div>

                    <!-- Placeholder where JavaScript will insert the table -->
                    <div id="open-graphs-results-container"></div>
                </article>

                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">Canonical Tags</h2>
                    <p>Generate and manage canonical URLs for your posts and pages. This helps search engines understand which URL should be considered as the primary version when similar content appears on multiple URLs.</p>

                    <button class="seo-setup-cta-button" id="generate-canonical-urls">Generate Canonical URLs</button>

                    <div id="canonical-results" style="margin-top: 20px;"></div>
                </article>


                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">XML Sitemap</h2>
                    <p>Automatically generate and implement structured data (e.g., FAQ, Product, Event, or Local Business). Ensure compatibility with schema.org and validate markup using Google’s Rich Results Testing Tool.</p>
                    <button class="seo-setup-cta-button" id="seo-setup-generate-xml-sitemap-btn">
                        Generate XML Sitemap
                    </button>
                    <div id="seo-setup-xml-sitemap-result" style="margin-top:15px;"></div>
                </article>
                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">Image Sitemap</h2>
                    <p>Generate an XML sitemap specifically for images on your website to help search engines discover and index your images better.</p>
                    <button class="seo-setup-cta-button" id="seo-setup-generate-image-sitemap-btn">
                        Generate Image Sitemap
                    </button>
                    <div id="seo-setup-image-sitemap-result" style="margin-top:15px;"></div>
                </article>
                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">Robots.txt</h2>
                    <p>Generate an XML sitemap Robots.txt file on your website.</p>
                    <button class="seo-setup-cta-button" id="seo-setup-generate-robots-txt-btn">
                        Generate Robots.txt
                    </button>
                    <div id="seo-setup-robots-txt-result" style="margin-top:15px;"></div>
                </article>
                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">Add Schema Markup</h2>
                    <p>
                        Automatically generate and implement structured data (Organization or LocalBusiness) Schema Markups.
                    </p>
                    <div class="seo-setup-schema-buttons">
                    <button class="seo-setup-cta-button" id="seo-setup-generate-org-schema">Generate Organization Schema</button>
                    <button class="seo-setup-cta-button" id="seo-setup-generate-local-schema">Generate LocalBusiness Schema</button>
                    </div>
                    <section class="seo-setup-tab-sub-content seo-setup-generated-content" id="seo-setup-schema-response" style="display: none;">
                        <p class="seo-setup-d-flex">
                            <img class="seo-setup-write-mark" src="<?php echo esc_url(plugins_url('assets/images/write-mark.png', __DIR__)); ?>" alt="Write Mark">
                            Schema Markup Added
                        </p>
                    </section>
                </article>

                <article class="seo-setup-tab-content-show">
                    <h2 class="seo-setup-content-title">Analyze Image Alt Text</h2>
                    <p>Sends each image's current alt text, along with the image itself, to Gemini to judge whether it is fully suitable. Unsuitable alt text gets an 8-word suggested replacement you can apply in bulk. Images already analyzed or fixed on a prior visit are skipped automatically unless their alt text has changed since.</p>

                    <button class="seo-setup-cta-button" id="seo-setup-analyze-alt-text-button">Analyze Alt Text</button>
                    <div id="seo-setup-analyze-alt-text-progress" style="margin-top:15px; display:none;"></div>
                    <div id="seo-setup-analyze-alt-text-summary" style="margin-top:10px;"></div>
                    <div id="seo-setup-analyze-alt-text-table"></div>

                    <button class="seo-setup-cta-button" id="seo-setup-fix-alt-text-button" style="display:none; margin-top:15px;">Fix Image Alt Text</button>
                    <div id="seo-setup-fix-alt-text-results" style="margin-top:15px;"></div>
                </article>
            </section>
        </div>
    </section>
    <footer class="seo-setup-footer">
        © 2026 vSplash Techlabs, Inc. All Rights Reserved.
    </footer>
</main> 