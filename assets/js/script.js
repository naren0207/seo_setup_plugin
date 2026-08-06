// ===================== REST API CONFIGURATION ======================
// All API calls now go through WordPress REST API (/wp-json/seo-setup/v1/)
// instead of admin-ajax.php. This isolates our plugin from other plugins'
// AJAX errors and broken admin_init hooks.

var seoSetupRoutes = {
    'seo_setup_update_page_names':       '/page-names/update',
    'seo_setup_check_redirect':          '/page-names/check-redirect',
    'seo_setup_check_all_redirects':     '/page-names/check-all-redirects',
    'seo_setup_generate_meta':           '/meta/generate',
    'seo_setup_save_meta':              '/meta/save',
    'afi_set_featured_images':           '/featured-images/set',
    'update_open_graphs':               '/open-graphs/update',
    'seo_setup_generate_canonical':      '/canonical/generate',
    'seo_setup_update_canonical':        '/canonical/update',
    'seo_setup_update_canonical_url':    '/canonical/update-url',
    'seo_setup_refresh_canonical':       '/canonical/refresh',
    'seo_setup_generate_sitemap':        '/sitemap/generate',
    'seo_setup_generate_image_sitemap':  '/image-sitemap/generate',
    'generate_robots_txt':               '/robots-txt/generate',
    'seo_setup_generate_org_schema':     '/schema/generate-org',
    'seo_setup_generate_local_schema':   '/schema/generate-local',
    'seo_setup_generate_schema_batch':   '/schema/generate-batch',
    'seo_setup_301_redirection_ajax':    '/redirects/auto-map',
    'seo_setup_301_redirection_save_ajax': '/redirects/save',
    'seo_setup_disable_yoast':           '/disable-yoast',
    'seo_setup_save_geo_coords':         '/save-geo-coords',
};

/**
 * Get the full REST API URL for a given action name.
 * Falls back to restBase if action not found in routes.
 */
function seoSetupGetRestUrl(action) {
    var route = seoSetupRoutes[action] || '';
    if (route && typeof seoSetupAjax !== 'undefined' && seoSetupAjax.restBase) {
        return seoSetupAjax.restBase + route;
    }
    // Fallback: if restBase not available, try ajaxUrl (shouldn't happen)
    return (typeof seoSetupAjax !== 'undefined' ? seoSetupAjax.ajaxUrl : '') || '';
}

/**
 * Get REST API headers with nonce for authentication.
 */
function seoSetupGetRestHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-WP-Nonce': (typeof seoSetupAjax !== 'undefined' ? seoSetupAjax.nonce : '')
    };
}

var seoSetupRestNonceRefreshRequest = null;

function seoSetupStoreRestNonce(nonce) {
    if (!nonce) {
        return;
    }
    if (typeof seoSetupAjax !== 'undefined') {
        seoSetupAjax.nonce = nonce;
    }
    if (typeof SEOSetupAltTextAjax !== 'undefined') {
        SEOSetupAltTextAjax.nonce = nonce;
    }
}

function seoSetupStoreRestNonceFromXhr(xhr) {
    if (!xhr || typeof xhr.getResponseHeader !== 'function') {
        return;
    }

    seoSetupStoreRestNonce(xhr.getResponseHeader('X-WP-Nonce'));
}

function seoSetupIsInvalidRestNonce(xhr) {
    return !!(
        xhr &&
        xhr.status === 403 &&
        xhr.responseJSON &&
        xhr.responseJSON.code === 'rest_cookie_invalid_nonce'
    );
}

function seoSetupRefreshRestNonce() {
    if (seoSetupRestNonceRefreshRequest) {
        return seoSetupRestNonceRefreshRequest;
    }

    var refreshUrl = '';
    if (typeof seoSetupAjax !== 'undefined') {
        refreshUrl = seoSetupAjax.nonceRefreshUrl || (seoSetupAjax.restBase ? seoSetupAjax.restBase + '/auth/nonce' : '');
    }
    if (!refreshUrl && typeof SEOSetupAltTextAjax !== 'undefined') {
        refreshUrl = SEOSetupAltTextAjax.nonceRefreshUrl || (SEOSetupAltTextAjax.restBase ? SEOSetupAltTextAjax.restBase + '/auth/nonce' : '');
    }

    var deferred = jQuery.Deferred();
    seoSetupRestNonceRefreshRequest = deferred.promise();

    if (!refreshUrl) {
        deferred.reject(null, 'error', 'Missing nonce refresh URL');
        seoSetupRestNonceRefreshRequest = null;
        return deferred.promise();
    }

    jQuery.ajax({
        url: refreshUrl,
        method: 'POST',
        dataType: 'json',
        contentType: 'application/json',
        data: JSON.stringify({})
    }).done(function(response, textStatus, xhr) {
        var nonce = response && (response.nonce || (response.data && response.data.nonce));
        if (!nonce && xhr && typeof xhr.getResponseHeader === 'function') {
            nonce = xhr.getResponseHeader('X-WP-Nonce');
        }

        if (nonce) {
            seoSetupStoreRestNonce(nonce);
            deferred.resolve(nonce);
        } else {
            deferred.reject(xhr, textStatus, 'Missing nonce');
        }
    }).fail(function(xhr, textStatus, errorThrown) {
        deferred.reject(xhr, textStatus, errorThrown);
    }).always(function() {
        seoSetupRestNonceRefreshRequest = null;
    });

    return seoSetupRestNonceRefreshRequest;
}

function seoSetupGetLegacyAjaxUrl() {
    if (typeof seoSetupAjax !== 'undefined' && seoSetupAjax.legacyAjaxUrl) {
        return seoSetupAjax.legacyAjaxUrl;
    }
    if (typeof SEOSetupAltTextAjax !== 'undefined' && SEOSetupAltTextAjax.legacyAjaxUrl) {
        return SEOSetupAltTextAjax.legacyAjaxUrl;
    }
    if (typeof ajaxurl !== 'undefined') {
        return ajaxurl;
    }
    return '';
}

function seoSetupInferLegacyAction(url) {
    var routeMaps = [];
    if (typeof seoSetupRoutes !== 'undefined') {
        routeMaps.push(seoSetupRoutes);
    }
    if (typeof seoSetupAltTextRoutes !== 'undefined') {
        routeMaps.push(seoSetupAltTextRoutes);
    }

    for (var i = 0; i < routeMaps.length; i++) {
        for (var action in routeMaps[i]) {
            if (Object.prototype.hasOwnProperty.call(routeMaps[i], action) && url.indexOf(routeMaps[i][action]) !== -1) {
                return action;
            }
        }
    }

    return '';
}

function seoSetupParseRequestData(data) {
    if (!data) {
        return {};
    }
    if (typeof data === 'string') {
        try {
            return JSON.parse(data);
        } catch (e) {
            return {};
        }
    }
    if (typeof data === 'object') {
        return jQuery.extend(true, {}, data);
    }
    return {};
}

function seoSetupGetLegacyNonce(action) {
    var isAltTextAction = action.indexOf('alt_text') !== -1 || action.indexOf('images_') !== -1;
    if (isAltTextAction && typeof SEOSetupAltTextAjax !== 'undefined') {
        return SEOSetupAltTextAjax.ajaxNonce || SEOSetupAltTextAjax.legacyNonce || '';
    }
    if (typeof seoSetupAjax !== 'undefined') {
        return seoSetupAjax.ajaxNonce || seoSetupAjax.legacyNonce || '';
    }
    return '';
}

function seoSetupShouldFallbackToLegacy(xhr) {
    if (!xhr) {
        return true;
    }

    if (xhr.status === 0 || xhr.status === 401 || xhr.status === 403) {
        return true;
    }

    // Gateway/server timeouts mean the origin is already overloaded. Retrying
    // through admin-ajax immediately can duplicate work and make the timeout worse.
    if (xhr.status === 502 || xhr.status === 503 || xhr.status === 504) {
        return false;
    }

    return false;
}

function seoSetupUseLegacyAjaxFirst() {
    return !!(
        (typeof seoSetupAjax !== 'undefined' && seoSetupAjax.forceLegacyAjax) ||
        (typeof SEOSetupAltTextAjax !== 'undefined' && SEOSetupAltTextAjax.forceLegacyAjax)
    );
}

function seoSetupRestAjax(options) {
    var originalOptions = jQuery.extend(true, {}, options || {});
    var successCallback = originalOptions.success;
    var errorCallback = originalOptions.error;
    var completeCallback = originalOptions.complete;
    var deferred = jQuery.Deferred();
    var legacyFirst = seoSetupUseLegacyAjaxFirst();

    delete originalOptions.success;
    delete originalOptions.error;
    delete originalOptions.complete;

    function complete(context, xhr, textStatus) {
        if (completeCallback) {
            completeCallback.call(context, xhr, textStatus);
        }
    }

    function fail(context, xhr, textStatus, errorThrown) {
        if (errorCallback) {
            errorCallback.call(context, xhr, textStatus, errorThrown);
        }
        deferred.rejectWith(context, [xhr, textStatus, errorThrown]);
    }

    function sendLegacyAjax(fallbackXhr, fallbackTextStatus, fallbackErrorThrown) {
        var legacyAction = originalOptions.legacyAction || seoSetupInferLegacyAction(originalOptions.url || '');
        var legacyUrl = seoSetupGetLegacyAjaxUrl();
        var context = originalOptions.context || originalOptions;

        if (!legacyAction || !legacyUrl) {
            fail(context, fallbackXhr, fallbackTextStatus, fallbackErrorThrown);
            complete(context, fallbackXhr, fallbackTextStatus);
            return;
        }

        var legacyData = seoSetupParseRequestData(originalOptions.data);
        var legacyNonce = seoSetupGetLegacyNonce(legacyAction);
        legacyData.action = legacyAction;
        legacyData.nonce = legacyNonce;
        legacyData._ajax_nonce = legacyNonce;
        legacyData.security = legacyNonce;

        jQuery.ajax({
            url: legacyUrl,
            method: originalOptions.method || originalOptions.type || 'POST',
            type: originalOptions.type || originalOptions.method || 'POST',
            dataType: originalOptions.dataType || 'json',
            data: legacyData,
            success: function(response, textStatus, xhr) {
                if (successCallback) {
                    successCallback.call(context, response, textStatus, xhr);
                }
                deferred.resolveWith(context, [response, textStatus, xhr]);
            },
            error: function(xhr, textStatus, errorThrown) {
                if (legacyFirst) {
                    legacyFirst = false;
                    sendRest(false);
                    return;
                }
                fail(context, xhr, textStatus, errorThrown);
            },
            complete: function(xhr, textStatus) {
                complete(context, xhr, textStatus);
            }
        });
    }

    function sendRest(hasRetried) {
        var requestOptions = jQuery.extend(true, {}, originalOptions);
        var context = requestOptions.context || requestOptions;
        var isFallingBackToLegacy = false;

        requestOptions.headers = jQuery.extend(
            {},
            requestOptions.headers || {},
            seoSetupGetRestHeaders()
        );

        requestOptions.success = function(response, textStatus, xhr) {
            seoSetupStoreRestNonceFromXhr(xhr);
            if (successCallback) {
                successCallback.call(context, response, textStatus, xhr);
            }
            deferred.resolveWith(context, [response, textStatus, xhr]);
        };

        requestOptions.error = function(xhr, textStatus, errorThrown) {
            seoSetupStoreRestNonceFromXhr(xhr);

            if (seoSetupIsInvalidRestNonce(xhr)) {
                if (!hasRetried) {
                    seoSetupRefreshRestNonce().done(function() {
                        sendRest(true);
                    }).fail(function() {
                        isFallingBackToLegacy = true;
                        sendLegacyAjax(xhr, textStatus, errorThrown);
                    });
                    return;
                }

                isFallingBackToLegacy = true;
                sendLegacyAjax(xhr, textStatus, errorThrown);
                return;
            }

            if (seoSetupShouldFallbackToLegacy(xhr)) {
                isFallingBackToLegacy = true;
                sendLegacyAjax(xhr, textStatus, errorThrown);
                return;
            }

            fail(context, xhr, textStatus, errorThrown);
        };

        requestOptions.complete = function(xhr, textStatus) {
            if (isFallingBackToLegacy || seoSetupIsInvalidRestNonce(xhr)) {
                return;
            }
            complete(context, xhr, textStatus);
        };

        jQuery.ajax(requestOptions);
    }

    if (legacyFirst) {
        sendLegacyAjax(null, 'error', '');
    } else {
        sendRest(false);
    }
    return deferred.promise();
}

/**
 * Make a REST API POST call. Replaces jQuery.post to admin-ajax.php.
 * @param {string} action - The old action name (used to look up REST route)
 * @param {object} data - The request payload (action and nonce keys are stripped)
 * @param {function} successCallback - Called on success
 * @param {function} errorCallback - Called on error (optional)
 */
function seoSetupRestPost(action, data, successCallback, errorCallback) {
    var url = seoSetupGetRestUrl(action);
    // Remove action and nonce keys — REST API doesn't need them
    var cleanData = {};
    for (var key in data) {
        if (key !== 'action' && key !== 'nonce' && key !== '_ajax_nonce' && key !== 'security') {
            cleanData[key] = data[key];
        }
    }
    return seoSetupRestAjax({
        url: url,
        method: 'POST',
        dataType: 'json',
        contentType: 'application/json',
        headers: seoSetupGetRestHeaders(),
        data: JSON.stringify(cleanData),
        success: function(response) {
            if (successCallback) successCallback(response);
        },
        error: function(xhr) {
            if (errorCallback) {
                errorCallback(xhr);
            } else {
                console.error('[SEO Setup] REST API error:', xhr.status, xhr.responseText);
            }
        }
    });
}


window.seoSetupRestAjax = seoSetupRestAjax;
window.seoSetupRestPost = seoSetupRestPost;
window.seoSetupGetRestHeaders = seoSetupGetRestHeaders;

// ===================== TAB SWITCHING ======================
function showPage(index) {
    // Get all tab buttons and content
    const tabs = document.querySelectorAll('.seo-setup-sidebar-tab-button');
    const contents = document.querySelectorAll('.seo-setup-tab-content-show');

    // Remove 'seo-setup-current' class from all tabs and contents
    tabs.forEach(tab => tab.classList.remove('seo-setup-current'));
    contents.forEach(content => content.classList.remove('seo-setup-current'));

    // Add 'seo-setup-current' class to the clicked tab and corresponding content
    tabs[index].classList.add('seo-setup-current');
    contents[index].classList.add('seo-setup-current');
}

// Handle "Generate" or "Update" CTA buttons
const ctaButtons = document.querySelectorAll('.seo-setup-cta-button');
ctaButtons.forEach(button => {
    button.addEventListener('click', function () {
        // Show the next sibling content for the clicked button
        const content = this.nextElementSibling;
        if (content) {
            content.style.display = 'block';
            this.classList.add('seo-setup-disabled-button');
        }
    });
});

// ===================== PAGE NAMES AJAX ======================
document.addEventListener('DOMContentLoaded', function () {
    const pageNamesButton = document.getElementById('seo-setup-page-names-button');
    const pageNamesResults = document.getElementById('seo-setup-page-names-results');

    // A global array to store updated items
    window.seoSetupUpdatedItems = [];

    function renderPageNamesResults(data) {
        let html = `<p>Updated <strong>${data.updated_count || 0}</strong> page(s):</p>`;
        window.seoSetupUpdatedItems = data.updated_items || [];

        if (data.updated_count > 0) {
            html += '<table class="widefat fixed" style="margin-top:10px;">';
            html += `
              <thead>
                <tr>
                  <th>Page ID</th>
                  <th>Page Title</th>
                  <th>Yoast SEO Slug</th>
                  <th>Old WP Slug</th>
                  <th>New Slug</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
            `;
            data.updated_items.forEach(item => {
                let oldWPSlug = '';
                if (item.old_slug.includes('|')) {
                    let parts = item.old_slug.split('|');
                    if (parts.length === 2) {
                        let part2 = parts[1].trim();
                        oldWPSlug = (part2 === '(none)') ? '' : part2;
                    }
                } else {
                    if (item.old_slug !== '(none)') {
                        oldWPSlug = item.old_slug;
                    }
                }
                const newYoastSlug = item.new_slug;
                let checkUrl = '';
                if (oldWPSlug) {
                    checkUrl = `${location.protocol}//${location.host}/${oldWPSlug}/`;
                } else if (newYoastSlug) {
                    checkUrl = `${location.protocol}//${location.host}/${newYoastSlug}/`;
                }
                html += `
                  <tr>
                    <td>${item.page_id}</td>
                    <td>${item.page_title}</td>
                    <td>${newYoastSlug}</td>
                    <td>${oldWPSlug}</td>
                    <td>${item.new_slug}</td>
                    <td>
                        ${
                            checkUrl
                            ? `<button class="button-secondary"
                                   onclick="checkRedirection('${checkUrl}', ${item.page_id});">
                                   Check Redirection
                               </button>`
                            : '<em>No old slug</em>'
                        }
                    </td>
                  </tr>
                  <tr id="redirect-result-row-${item.page_id}" style="display:none;">
                    <td colspan="6" id="redirect-result-cell-${item.page_id}" style="background:#fafafa; padding:10px;"></td>
                  </tr>
                `;
            });
            html += '</tbody></table>';
        } else {
            html += '<p>No pages needed updating! All slugs already matched.</p>';
        }
        pageNamesResults.innerHTML = html;
    }

    if (pageNamesButton && pageNamesResults) {
        pageNamesButton.addEventListener('click', function () {
            const batchLimit = 2;
            let offset = 0;
            let updatedItems = [];
            let debugLog = [];

            pageNamesResults.style.display = 'block';
            pageNamesResults.innerHTML = '<p>Updating slugs...</p>';

            function processPageNamesBatch() {
                seoSetupRestPost('seo_setup_update_page_names', {offset: offset, limit: batchLimit}, function (response) {
                    if (response.success) {
                        let data = response.data;
                        let batchUpdatedItems = data.updated_items || [];
                        let batchDebugLog = data.debug_log || [];
                        let totalCount = data.total_count || 0;
                        let nextOffset = parseInt(data.next_offset, 10);

                        updatedItems = updatedItems.concat(batchUpdatedItems);
                        debugLog = debugLog.concat(batchDebugLog);

                        if (data.has_more && !isNaN(nextOffset) && nextOffset > offset) {
                            offset = nextOffset;
                            pageNamesResults.innerHTML = `<p>Updating slugs... checked ${offset} of ${totalCount} item(s).</p>`;
                            setTimeout(processPageNamesBatch, 300);
                            return;
                        }

                        renderPageNamesResults({
                            updated_count: updatedItems.length,
                            updated_items: updatedItems,
                            debug_log: debugLog
                        });
                    } else {
                        pageNamesResults.innerHTML = `<p class="error">Error: ${response.data.message || 'Unknown'}</p>`;
                    }
                }, function(xhr) {
                    pageNamesResults.innerHTML = `<p class="error">Error: request failed with status ${xhr.status || 'unknown'}.</p>`;
                });
            }

            processPageNamesBatch();
        });
    }

    // Single-check function
    window.checkRedirection = function(oldUrl, pageId) {
        const resultRow  = document.getElementById(`redirect-result-row-${pageId}`);
        const resultCell = document.getElementById(`redirect-result-cell-${pageId}`); 
        if (!resultRow || !resultCell) return;

        resultRow.style.display = '';
        resultCell.innerHTML = `<p>Checking redirect for <strong>${oldUrl}</strong> ...</p>`;

        seoSetupRestPost('seo_setup_check_redirect', {url: oldUrl}, function(response) {
            if (!response.success) {
                resultCell.innerHTML = `<p style="color:red;">Error: ${
                    response.data && response.data.message ? response.data.message : 'Unknown error'
                }</p>`;
                return;
            }
            const chain = response.data.chain;
            if (!chain || !chain.length) {
                resultCell.innerHTML = `<p>No redirect data found.</p>`;
                return;
            }
            let output = `<p><strong>Check redirect for:</strong> ${oldUrl}</p>`;
            chain.forEach((step, i) => {
                output += `<p><strong>Status:</strong> ${step.status}<br><strong>URL:</strong> ${step.url}`;
                if (step.message) {
                    output += `<br><span style="color:red;">${step.message}</span>`;
                }
                output += '</p>';
                if ((step.status === 301 || step.status === 302) && i < chain.length - 1) {
                    output += '<p>Redirect step found.</p>';
                } else if (step.status === 200 && i === chain.length - 1) {
                    output += '<p>Page was loaded successfully.</p>';
                }
            });
            output += `<p>If this is not expected, clear your browser cache.</p>`;
            output += `
              <button class="button-secondary" id="view-all-redirects-btn-${pageId}">
                View All Redirections
              </button>
              <div id="all-redirects-result-container-${pageId}" style="margin-top:10px;"></div>
            `;
            resultCell.innerHTML = output;

            // "View All Redirections" button
            const viewAllBtn = document.getElementById(`view-all-redirects-btn-${pageId}`);
            const allResultsDiv = document.getElementById(`all-redirects-result-container-${pageId}`);
            if (viewAllBtn && allResultsDiv) {
                viewAllBtn.addEventListener('click', function() {
                    // We'll load them in increments
                    checkAllRedirectsIncrementally(allResultsDiv, 0, 50);
                });
            }
        });
    };

    // A function to load all redirects in increments of 50
    window.checkAllRedirectsIncrementally = function(container, offset, limit) {
        container.innerHTML = `<p>Loading redirects (${offset} - ${offset + limit})...</p>`;
        seoSetupRestPost('seo_setup_check_all_redirects', {offset: offset, limit: limit}, function(response) {
            if (!response.success) {
                container.innerHTML += `<p style="color:red;">Failed to load redirect batch: ${
                    response.data && response.data.message ? response.data.message : 'Unknown error'
                }</p>`;
                return;
            }
            let results = response.data.all_results;
            let count   = response.data.count;
            if (!results.length) {
                container.innerHTML += `<p>No more redirections found.</p>`;
                return;
            }
            let htmlBlock = '';
            results.forEach(item => {
                htmlBlock += `<div style="border:1px solid #ccc;padding:10px;margin-bottom:10px;">`;
                htmlBlock += `<p><strong>ID:</strong> ${item.id} <br><strong>Old URL:</strong> ${item.old_url}</p>`;
                item.chain.forEach((step, idx) => {
                    htmlBlock += `<p>
                        <strong>Status:</strong> ${step.status}<br>
                        <strong>URL:</strong> ${step.url}
                    `;
                    if (step.message) {
                        htmlBlock += `<br><span style="color:red;">${step.message}</span>`;
                    }
                    htmlBlock += `</p>`;
                });
                htmlBlock += `</div>`;
            });
            container.innerHTML += htmlBlock;

            // If the batch was full, we likely have more
            if (results.length === limit) {
                let nextOffset = offset + limit;
                container.innerHTML += `
                  <button class="button-secondary" id="load-more-button" style="margin-top:10px;">
                    Load Next ${limit} Redirections
                  </button>
                `;
                document.getElementById('load-more-button').addEventListener('click', function() {
                    this.remove();
                    checkAllRedirectsIncrementally(container, nextOffset, limit);
                });
            } else {
                container.innerHTML += `<p>All redirections have been loaded.</p>`;
            }
        });
    };
});

// ===================== BATCHED AJAX FOR META (Progress Bar + Full UI) ======================
jQuery(document).ready(function ($) {

    // We'll store the final data from all batches here
    let allPagesData = [];

    // jQuery UI progress bar elements
    let batchIndex = 0;
    let totalBatches = 1;
    const $generateButton = $('#seo-setup-generate-meta-button');
    const $resultsContainer = $('#title-descriptions-results');

    const $progressContainer = $('<div style="margin-top:10px; width:60%;"></div>');
    const $progressLabel = $('<div>', {
        id: 'progress-label',
        style: 'text-align:center; line-height:1.5em;',
        text: '0%'
    });

    const $jqProgressBar = $('<div>', { id: 'jq-ui-progressbar' }).progressbar({
        value: 0,
        change: function () {
            let val = $jqProgressBar.progressbar('value') || 0;
            $progressLabel.text(val + '%');
        },
        complete: function () {
            $progressLabel.text('All batches processed (100%)');
        }
    });

    // Combine them and place after the "Generate Meta" button
    $progressContainer.append($jqProgressBar).append($progressLabel);
    $generateButton.after($progressContainer);
    $progressContainer.hide(); // hidden by default

    // On click: reset progress, do batches, then build the final UI
    $generateButton.on('click', function (e) {
        e.preventDefault();

        // Clear old UI
        $resultsContainer.html('');
        allPagesData = [];   // reset global array

        batchIndex = 0;
        $jqProgressBar.progressbar('value', 0);
        $progressContainer.show();

        processBatch();
    });

    function processBatch() {
        seoSetupRestAjax({
            url: seoSetupGetRestUrl('seo_setup_generate_meta'),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupGetRestHeaders(),
            data: JSON.stringify({
                batch_index: batchIndex
            }),
            success: function (response) {
                if (!response.success) {
                    // Something went wrong server-side
                    $resultsContainer.html('<p>Error generating meta data.</p>');
                    $progressContainer.hide();
                    return;
                }

                // The server returns {data: [..], batch_index, total_batches, progress}
                const serverData = response.data;
                const batchPages = serverData.data || [];

                // Accumulate these pages in our global array
                allPagesData = allPagesData.concat(batchPages);

                // Update the progress bar
                const progNum = serverData.progress ? parseFloat(serverData.progress) : 0;
                $jqProgressBar.progressbar('value', Math.round(progNum));

                totalBatches = serverData.total_batches || 1;

                // Check if more batches
                if (serverData.batch_index < totalBatches) {
                    batchIndex = serverData.batch_index;
                    setTimeout(processBatch, 200);
                } else {
                    // All batches done: build final UI
                    setTimeout(() => {
                        $progressContainer.hide();
                    }, 1500);

                    buildMetaUI(allPagesData);
                }
            },
            error: function () {
                $resultsContainer.html('<p>AJAX error during batch processing.</p>');
                $progressContainer.hide();
            }
        });
    }

    /*
     * - Two buttons: "Update Meta Data to Pages" and "Download Meta"
     */
    function buildMetaUI(pagesData) {
        if (!pagesData || pagesData.length === 0) {
            $resultsContainer.html('<p>No pages found or no data returned.</p>');
            return;
        }

        let html = '';
        html += '<div style="margin-bottom:10px;">';
        html += '<button id="batch-update-meta" style="margin-right:10px;">Update Meta Data to Pages</button>';
        html += '<button id="download-meta" style="margin-right:10px;">Download Meta</button>';
        html += '</div>';

        pagesData.forEach(function (pageInfo) {
            if (pageInfo.error) {
                html += '<div style="border:1px solid #ccc; margin-bottom:10px; padding:20px;">';
                html += '<strong>Page Name:</strong> ' + pageInfo.page_title + ' (' + pageInfo.page_url + ')<br/>';
                html += '<em>Error:</em> ' + pageInfo.error;
                html += '</div>';
            } else {
                html += '<div style="border:1px solid #ccc; margin-bottom:10px; padding:20px;">';

                html += '<strong>Page Name:</strong> ' + pageInfo.page_title + ' (' + pageInfo.page_url + ')<br/><br/>';

                let currTitle = pageInfo.current_meta_title ? pageInfo.current_meta_title : '';
                let currDesc = pageInfo.current_meta_description ? pageInfo.current_meta_description : '';
                html += '<strong>Current Meta Title:</strong> ' + currTitle + '<br/>';
                html += '<strong>Current Meta Description:</strong> ' + currDesc + '<br/><br/>';

                let suggestedTitle = pageInfo.titles[0] || '';
                let suggestedDesc = pageInfo.descriptions[0] || '';
                let suggestedTitleCount = suggestedTitle.length;
                let suggestedDescCount = suggestedDesc.length;

                html += '<label><strong>Suggested Meta Title: (Character Count: ' + suggestedTitleCount + ')</strong></label><br/>';
                html += '<input style="width:80%;" type="text" name="meta_title_' + pageInfo.page_id + '_0" value="' + suggestedTitle + '"><br/><br/>';

                html += '<label><strong>Suggested Meta Description: (Character Count: ' + suggestedDescCount + ')</strong></label><br/>';
                html += '<textarea style="width:80%;" name="meta_desc_' + pageInfo.page_id + '_0" rows="2">' + suggestedDesc + '</textarea><br/><br/>';

                if (pageInfo.titles.length > 1) {
                    let altTitle1 = pageInfo.titles[1];
                    let altTitle1Count = altTitle1.length;
                    html += '<strong>Alternate Meta Title 1:</strong> ' + altTitle1 + ' (Character Count: ' + altTitle1Count + ')<br/>';
                }
                if (pageInfo.titles.length > 2) {
                    let altTitle2 = pageInfo.titles[2];
                    let altTitle2Count = altTitle2.length;
                    html += '<strong>Alternate Meta Title 2:</strong> ' + altTitle2 + ' (Character Count: ' + altTitle2Count + ')<br/>';
                }
                html += '<br/>';

                if (pageInfo.descriptions.length > 1) {
                    let altDesc1 = pageInfo.descriptions[1];
                    let altDesc1Count = altDesc1.length;
                    html += '<strong>Alternate Meta Description 1:</strong> ' + altDesc1 + ' (Character Count: ' + altDesc1Count + ')<br/>';
                }
                if (pageInfo.descriptions.length > 2) {
                    let altDesc2 = pageInfo.descriptions[2];
                    let altDesc2Count = altDesc2.length;
                    html += '<strong>Alternate Meta Description 2:</strong> ' + altDesc2 + ' (Character Count: ' + altDesc2Count + ')<br/>';
                }
                html += '<br/>';

                let focusKw = pageInfo.focus_keyword || '';
                let semanticKw = pageInfo.semantic_keyword || '';
                let h1Tag = pageInfo.h1_tag || '';
                html += '<strong>Focus Keyword:</strong> ' + focusKw + '<br/>';
                html += '<strong>Semantic Keyword:</strong> ' + semanticKw + '<br/>';
                html += '<strong>H1 Tag:</strong> ' + h1Tag + '<br/><br/>';
                html += '<button class="seo-setup-save-btn" data-pageid="' + pageInfo.page_id + '">Save Selected</button>';

                html += '</div>';
            }
        });

        // Update the container
        $resultsContainer.html(html);
    }

    $resultsContainer.on('click', '#batch-update-meta', function (e) {
        e.preventDefault();
        let pagesArray = [];

        if (!allPagesData.length) {
            alert('No pages found to update.');
            return;
        }

        // Collect updated fields from the DOM
        allPagesData.forEach(function (pageInfo) {
            if (!pageInfo.error) {
                let pageId = pageInfo.page_id;
                let metaTitle = $('input[name="meta_title_' + pageId + '_0"]').val() || '';
                let metaDesc = $('textarea[name="meta_desc_' + pageId + '_0"]').val() || '';

                pagesArray.push({
                    page_id: pageId,
                    meta_title: metaTitle,
                    meta_desc: metaDesc
                });
            }
        });

        if (pagesArray.length === 0) {
            alert('No valid pages to update.');
            return;
        }

        seoSetupRestAjax({
            url: seoSetupGetRestUrl('seo_setup_save_meta'),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupGetRestHeaders(),
            data: JSON.stringify({
                pages: pagesArray
            }),
            success: function (resp) {
                if (!resp.success) {
                    alert('Error saving meta: ' + resp.data);
                    return;
                }
                alert('Successfully updated all pages with suggested meta!');
            },
            error: function () {
                alert('AJAX error while saving all meta.');
            }
        });
    });
    
    /**
     * Helper function to escape a string for CSV format.
     * It handles quotes, commas, and newlines.
     */
    function escapeCsvCell(str) {
        let cell = str === null || str === undefined ? '' : String(str);
        // If the cell contains a comma, a newline, or a double-quote, enclose it in double-quotes.
        if (cell.search(/("|,|\n)/g) >= 0) {
            // Escape any existing double-quotes by doubling them
            cell = '"' + cell.replace(/"/g, '""') + '"';
        }
        return cell;
    }

    // Download meta
    $resultsContainer.on('click', '#download-meta', function (e) {
        e.preventDefault();
        
        if (!allPagesData.length) {
            alert('No data to download.');
            return;
        }
        
        // Define CSV Headers
        const headers = [
            "Page Title",
            "Page URL",
            //"Current Meta Title",
            //"Current Meta Description",
            "Meta Title",
            "Meta Description",
            "Keyword",
            "Semantic Keyword",
            //"H1 Tag",
            // "Alternate Meta Title 1",
            // "Alternate Meta Title 2",
            //"Alternate Meta Description 1",
            // "Alternate Meta Description 2"
        ];
        
        let csvContent = headers.join(',') + '\n';

        // Build each row with data from the form
        allPagesData.forEach(function (pageInfo) {
            if (!pageInfo.error) {
                // Get the LIVE data from the input fields
                let finalMetaTitle = $('input[name="meta_title_' + pageInfo.page_id + '_0"]').val() || '';
                let finalMetaDesc = $('textarea[name="meta_desc_' + pageInfo.page_id + '_0"]').val() || '';

                // Define data row - only active lines are exported
                const row = [
                    pageInfo.page_title,
                    pageInfo.page_url,
                    // pageInfo.current_meta_title || '',
                    // pageInfo.current_meta_description || '',
                    finalMetaTitle,
                    finalMetaDesc,
                    pageInfo.focus_keyword || '',
                    pageInfo.semantic_keyword || '',
                    // pageInfo.h1_tag || '',
                    // pageInfo.titles[1] || '',
                    // pageInfo.titles[2] || '',
                    // pageInfo.descriptions[1] || '',
                    // pageInfo.descriptions[2] || ''
                ];
                
                csvContent += row.map(escapeCsvCell).join(',') + '\n';
            }
        });

        // Create Blob and trigger download
        let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        let url = URL.createObjectURL(blob);

        let link = document.createElement('a');
        link.href = url;
        link.download = 'meta_data.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        URL.revokeObjectURL(url);
    });

    // Save page wise "Save Selected"
    $resultsContainer.on('click', '.seo-setup-save-btn', function (e) {
        e.preventDefault();
        let pageId = $(this).data('pageid');

        let metaTitle = $('input[name="meta_title_' + pageId + '_0"]').val() || '';
        let metaDesc = $('textarea[name="meta_desc_' + pageId + '_0"]').val() || '';

        seoSetupRestAjax({
            url: seoSetupGetRestUrl('seo_setup_save_meta'),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupGetRestHeaders(),
            data: JSON.stringify({
                pages: [
                    {
                        page_id: pageId,
                        meta_title: metaTitle,
                        meta_desc: metaDesc
                    }
                ]
            }),
            success: function (resp) {
                if (!resp.success) {
                    alert('Error saving meta: ' + resp.data);
                    return;
                }
                alert('Meta saved successfully!');
            },
            error: function () {
                // This error handler had a bug where it would continue the batch process
                // It should just show an error for this single save action.
                alert('AJAX error while saving meta for this page.');
            }
        });
    });
});


// ===================== FEATURED IMAGE =====================
jQuery(document).ready(function ($) {
    $('#set-featured-images-btn').on('click', function (e) {
        e.preventDefault();

        const batchLimit = 2;
        let offset = 0;
        let updatedPages = [];

        $('#featured-images-results').html('<p>Processing featured images...</p>');

        function renderFeaturedImagesResults() {
            if (updatedPages.length === 0) {
                $('#featured-images-results').html('<p>No pages needed a featured image update.</p>');
                return;
            }

            let html = '<table style="width:100%; border-collapse: collapse;"><tr>' +
                '<th style="border:1px solid #ccc; padding:5px; text-align:left;">Page Name</th>' +
                '<th style="border:1px solid #ccc; padding:5px; text-align:left;">Page URL</th>' +
                '<th style="border:1px solid #ccc; padding:5px; text-align:left;">Featured Image</th>' +
                '</tr>';

            updatedPages.forEach(function (page) {
                html += '<tr>';
                html += '<td style="border:1px solid #ccc; padding:5px;">' + page.page_name + '</td>';
                html += '<td style="border:1px solid #ccc; padding:5px;">' + page.page_url + '</td>';
                html += '<td style="border:1px solid #ccc; padding:5px;">';
                if (page.featured_image_url) {
                    html += '<img src="' + page.featured_image_url + '" style="max-width:100px;">';
                } else {
                    html += 'No Image';
                }
                html += '</td>';
                html += '</tr>';
            });
            html += '</table>';
            $('#featured-images-results').html(html);
        }

        function processFeaturedImagesBatch() {
            seoSetupRestAjax({
                url: seoSetupGetRestUrl('afi_set_featured_images'),
                method: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                headers: seoSetupGetRestHeaders(),
                data: JSON.stringify({
                    offset: offset,
                    limit: batchLimit
                }),
                success: function (response) {
                    if (!response.success) {
                        $('#featured-images-results').html('<p>Error setting featured images.</p>');
                        return;
                    }

                    let data = response.data || {};
                    updatedPages = updatedPages.concat(data.data || []);

                    let total = data.total_count || 0;
                    let nextOffset = parseInt(data.next_offset, 10);
                    let checked = total ? Math.min(nextOffset || total, total) : 0;

                    if (data.has_more && !isNaN(nextOffset) && nextOffset > offset) {
                        offset = nextOffset;
                        $('#featured-images-results').html('<p>Processing featured images... checked ' + checked + ' of ' + total + ' items.</p>');
                        setTimeout(processFeaturedImagesBatch, 300);
                        return;
                    }

                    renderFeaturedImagesResults();
                },
                error: function () {
                    $('#featured-images-results').html('<p>AJAX error occurred.</p>');
                }
            });
        }

        processFeaturedImagesBatch();
    });
});

// ===================== OPEN GRAPHS =====================
jQuery(document).ready(function ($) {
    $('#update-open-graphs-btn').on('click', function () {
        const batchLimit = 2;
        let offset = 0;
        let updatedPosts = [];
        let failedPosts = [];

        $('#open-graphs-loader').show();
        $('#open-graphs-results-container').html('<p>Updating Open Graphs...</p>');

        function renderOpenGraphsResults() {
            $('#open-graphs-loader').hide();
            $('#open-graphs-results-container').html('');

            if (updatedPosts.length > 0) {
                let table = $('<table/>', {
                    id: 'open-graphs-results',
                    style: 'width: 100%; margin-top: 20px; border-collapse: collapse;'
                });

                let thead = $('<thead/>').append(
                    $('<tr/>', { style: 'text-align: left; border: 1px solid #ddd;' }).append(
                        $('<th/>', { text: 'Page Title', style: 'padding: 12px;' }),
                        $('<th/>', { text: 'URL', style: 'padding: 12px;' }),
                        $('<th/>', { text: 'Open Graph Image', style: 'padding: 12px;' })
                    )
                );
                let tbody = $('<tbody/>');

                updatedPosts.forEach(function (post) {
                    let row = $('<tr/>', { style: 'border: 1px solid #ddd;' }).append(
                        $('<td/>', { text: post.title, style: 'padding: 12px; border-right:1px solid #ddd;' }),
                        $('<td/>', { style: 'padding: 12px; border-right:1px solid #ddd;' }).append(
                            $('<a/>', { href: post.url, text: post.url, target: '_blank', style: 'color: #0073aa; text-decoration: none;' })
                        ),
                        $('<td/>', { style: 'padding: 12px;' }).append(
                            $('<img/>', { src: post.image, width: 100, style: 'width:150px;' })
                        )
                    );
                    tbody.append(row);
                });

                table.append(thead).append(tbody);
                $('#open-graphs-results-container')
                    .append('<h3>Successfully Updated Pages:</h3>')
                    .append(table);
            } else {
                $('#open-graphs-results-container').append('<p>No Open Graph data updated.</p>');
            }

            if (failedPosts.length > 0) {
                let failedList = $('<ul/>', { style: 'margin-top: 20px; color: red;' });
                failedPosts.forEach(function (post) {
                    failedList.append(
                        $('<li/>', {
                            text: `Yoast SEO meta title and/or meta description are missing for post ID: ${post.post_id} (${post.url})`
                        })
                    );
                });
                $('#open-graphs-results-container')
                    .append('<h3 style="color: red;">Failed to Update Pages:</h3>')
                    .append(failedList);
            }
        }

        function processOpenGraphsBatch() {
            seoSetupRestAjax({
                url: seoSetupGetRestUrl('update_open_graphs'),
                type: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                headers: seoSetupGetRestHeaders(),
                data: JSON.stringify({
                    offset: offset,
                    limit: batchLimit
                }),
                success: function (response) {
                    if (!response.success) {
                        $('#open-graphs-loader').hide();
                        alert('Failed to update Open Graphs.');
                        return;
                    }

                    let data = response.data || {};
                    updatedPosts = updatedPosts.concat(data.updated_posts || []);
                    failedPosts = failedPosts.concat(data.failed_posts || []);

                    let total = data.total_count || 0;
                    let nextOffset = parseInt(data.next_offset, 10);
                    let checked = total ? Math.min(nextOffset || total, total) : 0;

                    if (data.has_more && !isNaN(nextOffset) && nextOffset > offset) {
                        offset = nextOffset;
                        $('#open-graphs-results-container').html('<p>Updating Open Graphs... checked ' + checked + ' of ' + total + ' items.</p>');
                        setTimeout(processOpenGraphsBatch, 300);
                        return;
                    }

                    renderOpenGraphsResults();
                },
                error: function () {
                    $('#open-graphs-loader').hide();
                    alert('An error occurred.');
                }
            });
        }

        processOpenGraphsBatch();
    });
});

// ===================== XML Sitemap ======================

jQuery(document).ready(function($) {

    // ===================== XML Sitemap (Batched) =====================
    let generatingXML = false;
    $('#seo-setup-generate-xml-sitemap-btn').on('click', function(e) {
        e.preventDefault();
        if (generatingXML) return;
        generatingXML = true;

        const $button  = $(this);
        const $results = $('#seo-setup-xml-sitemap-result');
        let batch      = 0;

        $button.prop('disabled', true).text('Generating...');
        $results.html('<p>Starting XML Sitemap generation...</p>');

        function processXMLBatch() {
            seoSetupRestAjax({
                url: seoSetupGetRestUrl('seo_setup_generate_sitemap'),
                type: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                headers: seoSetupGetRestHeaders(),
                data: JSON.stringify({
                    batch: batch
                }),
                success: function(response) {
                    if (!response.success) {
                        generatingXML = false;
                        let msg = response.data && response.data.message ? response.data.message : 'Unknown error.';
                        $results.append(`<p style="color:red;">Error: ${msg}</p>`);
                        $button.prop('disabled', false).text('Generate XML Sitemap');
                        return;
                    }
                    $results.append(`<p>Batch #${batch} processed...</p>`);
                    if (response.data.done) {
                        // <<< CHANGED / ADDED >>> Show final success messages
                        $results.append(`
                          <p style="color:green; font-weight:bold;">Sitemap generated successfully</p>
                          <p>Sitemap URL: <a href="${window.location.origin}/sitemap.xml" target="_blank">${window.location.origin}/sitemap.xml</a></p>
                        `);

                        $button.prop('disabled', false).text('Generate XML Sitemap');
                        generatingXML = false;

                        // Optionally disable Yoast sitemaps
                        // disableYoastSitemaps();

                    } else {
                        batch++;
                        processXMLBatch();
                    }
                },
                error: function() {
                    generatingXML = false;
                    $results.append('<p style="color:red;">AJAX request failed.</p>');
                    $button.prop('disabled', false).text('Generate XML Sitemap');
                }
            });
        }

        processXMLBatch();
    });

    // ===================== Image Sitemap (Batched) =====================
    let generatingImage = false;
    $('#seo-setup-generate-image-sitemap-btn').on('click', function(e) {
        e.preventDefault();
        if (generatingImage) return;
        generatingImage = true;

        const $button  = $(this);
        const $results = $('#seo-setup-image-sitemap-result');
        let batch      = 0;

        $button.prop('disabled', true).text('Generating...');
        $results.html('<p>Starting Image Sitemap generation...</p>');

        function processImageBatch() {
            seoSetupRestAjax({
                url: seoSetupGetRestUrl('seo_setup_generate_image_sitemap'),
                type: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                headers: seoSetupGetRestHeaders(),
                data: JSON.stringify({
                    batch: batch
                }),
                success: function(response) {
                    if (!response.success) {
                        generatingImage = false;
                        let msg = response.data && response.data.message ? response.data.message : 'Unknown error.';
                        $results.append(`<p style="color:red;">Error: ${msg}</p>`);
                        $button.prop('disabled', false).text('Generate Image Sitemap');
                        return;
                    }
                    $results.append(`<p>Batch #${batch} processed...</p>`);
                    if (response.data.done) {
                        // <<< CHANGED / ADDED >>> Show final success messages
                        $results.append(`
                          <p style="color:green; font-weight:bold;">Image sitemap generated successfully</p>
                          <p>Image Sitemap URL: <a href="${window.location.origin}/image-sitemap.xml" target="_blank">${window.location.origin}/image-sitemap.xml</a></p>
                        `);

                        generatingImage = false;
                        $button.prop('disabled', false).text('Generate Image Sitemap');

                        // Optionally disable Yoast
                        // disableYoastSitemaps();

                    } else {
                        batch++;
                        processImageBatch();
                    }
                },
                error: function() {
                    generatingImage = false;
                    $results.append('<p style="color:red;">AJAX request failed.</p>');
                    $button.prop('disabled', false).text('Generate Image Sitemap');
                }
            });
        }

        processImageBatch();
    });

    // ===================== Robots.txt =====================
    $('#seo-setup-generate-robots-txt-btn').on('click', function(e) {
        e.preventDefault();
        const $button  = $(this);
        const $results = $('#seo-setup-robots-txt-result');

        $button.prop('disabled', true).text('Generating...');
        $results.html('<p>Generating Robots.txt...</p>');

        seoSetupRestAjax({
            url: seoSetupGetRestUrl('generate_robots_txt'),
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupGetRestHeaders(),
            data: JSON.stringify({}),
            success: function(response) {
                if (response.success) {
                    // <<< CHANGED / ADDED >>> Output success message + URL + Download button
                    $results.append(`<p style="color:green; font-weight:bold;">${response.data.message}</p>`);
                    $results.append(`<p>Robots.txt URL: <a href="${response.data.robots_txt_url}" target="_blank">${response.data.robots_txt_url}</a></p>`);
                    
                    // Insert the Download button
                    $results.append(`
                        <button class="button button-secondary" id="download-robots-txt">Download robots.txt</button>
                    `);

                    // Handle "Download robots.txt" button
                    $('#download-robots-txt').on('click', function(e) {
                        e.preventDefault();
                        const content = response.data.robots_txt_content;
                        const blob    = new Blob([content], {type: 'text/plain'});
                        const url     = URL.createObjectURL(blob);
                        
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'robots.txt';
                        document.body.appendChild(a);
                        a.click();
                        
                        URL.revokeObjectURL(url);
                        document.body.removeChild(a);
                    });

                    // Optionally disable Yoast
                    // disableYoastSitemaps();

                } else {
                    $results.append(`<p style="color:red;">Error: ${response.data.message}</p>`);
                }
            },
            error: function() {
                $results.append('<p style="color:red;">AJAX request failed.</p>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Generate Robots.txt');
            }
        });
    });

    // ===================== Optional: Disabling Yoast =====================
    function disableYoastSitemaps() {
        // If you want to automatically disable Yoast sitemaps once your plugin sitemaps are generated:
        seoSetupRestPost('seo_setup_disable_yoast', {}).done(function(resp) {
            console.log('Disable Yoast response:', resp);
        });
    }
});

// ===================== Canonicals =====================

// Canonical URL Generation
jQuery(document).ready(function ($) {
    $('#generate-canonical-urls').on('click', function (e) {
        e.preventDefault();
        var button = $(this);
        var resultArea = $('#canonical-results');
        var batchLimit = 2;
        var offset = 0;
        var allResults = [];

        button.prop('disabled', true).text('Generating...');
        resultArea.html('<p>Generating canonical URLs, please wait...</p>');

        function renderCanonicalResults() {
            button.prop('disabled', false).text('Generate Canonical URLs');

            if (allResults.length === 0) {
                resultArea.html('<div class="notice notice-error"><p>No posts or pages found.</p></div>');
                return;
            }

            var table = '<table class="canonical-tags-table wp-list-table widefat fixed striped">';
            table += '<thead><tr>';
            table += '<th>ID</th>';
            table += '<th>Title</th>';
            table += '<th>Status</th>';
            table += '<th>Canonical URL</th>';
            table += '<th>Actions</th>';
            table += '</tr></thead><tbody>';

            allResults.forEach(function (item) {
                table += '<tr data-post-id="' + item.post_id + '">';
                table += '<td>' + item.post_id + '</td>';
                table += '<td>' + item.post_title + '</td>';
                table += '<td>' + item.status + '</td>';
                table += '<td class="canonical-url-cell">';
                table += '<span class="canonical-url-text">' + item.canonical_url + '</span>';
                table += '<div class="canonical-url-edit" style="display:none;">';
                table += '<input type="url" class="canonical-url-input" value="' + item.canonical_url + '" style="width:100%;">';
                table += '</div>';
                table += '</td>';
                table += '<td class="action-buttons">';
                table += '<button class="button button-secondary edit-canonical">Edit</button>';
                table += '<div class="update-buttons" style="display:none;">';
                table += '<button class="button button-primary update-canonical-url">Update</button>';
                table += '<button class="button cancel-edit">Cancel</button>';
                table += '</div>';
                table += '<span class="update-status"></span>';
                table += '</td>';
                table += '</tr>';
            });

            table += '</tbody></table>';
            resultArea.html(table);
        }

        function processCanonicalBatch() {
            seoSetupRestAjax({
                url: seoSetupGetRestUrl('seo_setup_generate_canonical'),
                type: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                headers: seoSetupGetRestHeaders(),
                data: JSON.stringify({
                    offset: offset,
                    limit: batchLimit
                }),
                success: function (response) {
                    if (!(response.success && response.data)) {
                        button.prop('disabled', false).text('Generate Canonical URLs');
                        resultArea.html('<div class="notice notice-error"><p>Error: ' + (response.data || 'Unknown error') + '</p></div>');
                        return;
                    }

                    var data = response.data;
                    allResults = allResults.concat(data.results || []);

                    var total = data.total_count || 0;
                    var nextOffset = parseInt(data.next_offset, 10);
                    var checked = total ? Math.min(nextOffset || total, total) : 0;

                    if (data.has_more && !isNaN(nextOffset) && nextOffset > offset) {
                        offset = nextOffset;
                        resultArea.html('<p>Generating canonical URLs... checked ' + checked + ' of ' + total + ' items.</p>');
                        setTimeout(processCanonicalBatch, 300);
                        return;
                    }

                    renderCanonicalResults();
                },
                error: function () {
                    button.prop('disabled', false).text('Generate Canonical URLs');
                    resultArea.html('<div class="notice notice-error"><p>Error: Failed to generate canonical URLs</p></div>');
                }
            });
        }

        processCanonicalBatch();
    });

    // Handle edit button click
    $(document).on('click', '.edit-canonical', function () {
        var row = $(this).closest('tr');
        var currentUrl = row.find('.canonical-url-text').text();

        // Create edit form
        var form = $('<div class="edit-form">');
        form.append('<input type="url" class="canonical-input" value="' + currentUrl + '">');
        form.append('<button class="button button-primary save-canonical">Update</button>');
        form.append('<button class="button cancel-edit">Cancel</button>');

        // Replace cell content with form
        row.find('.canonical-url-cell').html(form);

        // Hide edit button while editing
        $(this).hide();

        // Focus and select the URL
        row.find('.canonical-input').focus().select();

        // Handle Enter key press
        row.find('.canonical-input').on('keypress', function (e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                row.find('.save-canonical').click();
            }
        });
    });

    // Handle cancel button click
    $(document).on('click', '.cancel-edit', function (e) {
        e.preventDefault();
        var row = $(this).closest('tr');
        row.find('.canonical-url-edit').hide();
        row.find('.canonical-url-text').show();
        row.find('.update-buttons').hide();
        row.find('.edit-canonical').show();
    });

    // Handle update button click
    $(document).on('click', '.update-canonical-url', function (e) {
        e.preventDefault();
        var row = $(this).closest('tr');
        var postId = row.data('post-id');
        var canonicalUrl = row.find('.canonical-url-input').val();
        var statusSpan = row.find('.update-status');

        seoSetupRestAjax({
            url: seoSetupGetRestUrl('seo_setup_update_canonical'),
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupGetRestHeaders(),
            data: JSON.stringify({
                post_id: postId,
                canonical_url: canonicalUrl
            }),
            beforeSend: function () {
                statusSpan.html('Updating...').removeClass('success error');
            },
            success: function (response) {
                if (response.success) {
                    row.find('.canonical-url-text').text(response.data.canonical_url).show();
                    row.find('.canonical-url-edit').hide();
                    row.find('.update-buttons').hide();
                    row.find('.edit-canonical').show();
                    statusSpan.html('Updated successfully').addClass('success');
                    setTimeout(function () {
                        statusSpan.html('').removeClass('success');
                    }, 3000);
                } else {
                    statusSpan.html('Error: ' + response.data).addClass('error');
                }
            },
            error: function (xhr, status, error) {
                statusSpan.html('Error: ' + error).addClass('error');
            }
        });
    });
});


// ===================== Schema Markup =====================
jQuery(document).ready(function ($) {
    function triggerAdditionalSchemaBatch(publisherType) {
        let schemaBatchIndex = 0;
        let schemaTotalBatches = 1;
        let schemaPublisherType = publisherType;

        const $schemaProgressContainer = $('<div style="margin-top:10px; width:60%;"></div>');
        const $schemaProgressLabel = $('<div>', {
            id: 'schema-progress-label',
            style: 'text-align:center; line-height:1.5em;',
            text: '0%'
        });
        const $schemaJqProgressBar = $('<div>', { id: 'schema-jq-ui-progressbar' }).progressbar({
            value: 0,
            change: function () {
                let val = $schemaJqProgressBar.progressbar('value') || 0;
                $schemaProgressLabel.text(val + '%');
            },
            complete: function () {
                $schemaProgressLabel.text('100%');
            }
        });

        $schemaProgressContainer.append($schemaJqProgressBar).append($schemaProgressLabel);
        $('#seo-setup-schema-response').append($schemaProgressContainer);
        $schemaProgressContainer.show();

        function processSchemaBatch() {
            seoSetupRestAjax({
                url: seoSetupGetRestUrl('seo_setup_generate_schema_batch'),
                method: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                headers: seoSetupGetRestHeaders(),
                data: JSON.stringify({
                    batch_index: schemaBatchIndex,
                    schema_publisher_type: schemaPublisherType
                }),
                success: function (response) {
                    if (!response.success) {
                        $('#seo-setup-schema-response').append('<p>Error generating additional schema data.</p>');
                        $schemaProgressContainer.hide();
                        return;
                    }
                    const serverData = response.data;
                    const progNum = serverData.progress ? parseFloat(serverData.progress) : 0;
                    $schemaJqProgressBar.progressbar('value', Math.round(progNum));
                    schemaTotalBatches = serverData.total_batches || 1;

                    if (serverData.batch_index < schemaTotalBatches) {
                        schemaBatchIndex = serverData.batch_index;
                        setTimeout(processSchemaBatch, 200);
                    } else {
                        setTimeout(() => {
                            $schemaProgressContainer.hide();
                        }, 1500);
                        $('#seo-setup-schema-response').append('<p>Schema generation complete.</p>');
                    }
                },
                error: function () {
                    $('#seo-setup-schema-response').append('<p>AJAX error during schema batch processing.</p>');
                    $schemaProgressContainer.hide();
                }
            });
        }

        processSchemaBatch();
    }

    // Updated: Generate Organization Schema
    $('#seo-setup-generate-org-schema').on('click', function (e) {
        e.preventDefault();
    
        $('#seo-setup-schema-response').show().empty(); 
        triggerAdditionalSchemaBatch("Organization");
    
        seoSetupRestAjax({
            url: seoSetupGetRestUrl('seo_setup_generate_org_schema'),
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupGetRestHeaders(),
            data: JSON.stringify({})
        }).done(function (response) {
           
        }).fail(function () {
            $('#seo-setup-schema-response').append('<p>Error generating Organization Schema.</p>');
        });
    });

    // Updated: Generate LocalBusiness Schema
    $('#seo-setup-generate-local-schema').on('click', function (e) {
        e.preventDefault();
    
        $('#seo-setup-schema-response').show().empty(); 
        triggerAdditionalSchemaBatch("LocalBusiness");
    
        seoSetupRestAjax({
            url: seoSetupGetRestUrl('seo_setup_generate_local_schema'),
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupGetRestHeaders(),
            data: JSON.stringify({})
        }).done(function (response) {
            
        }).fail(function () {
            $('#seo-setup-schema-response').append('<p>Error generating LocalBusiness Schema.</p>');
        });
    });
});

// ===================== 301 Redirects =====================
jQuery(document).ready(function ($) {
    $(".auto-map-btn").click(function(){

        seoSetupRestAjax({
            url: seoSetupGetRestUrl('seo_setup_301_redirection_ajax'),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupGetRestHeaders(),
            data: JSON.stringify({
                source_url: $("#source-url").val()
            }),
            success: function (response) {
                if (!response.success) {
                    $('#seo-setup-schema-response').append('<p>Error get 301 URL not getting.</p>');
                    return;
                }
                const serverData = response.data;
                var  main_url_array = jQuery.parseJSON(serverData.main_array);
                // console.log(main_url_array);
                var main_table_content = ""; 
                $.each( main_url_array, function( key, value ) {
                    console.log( key + " => : " + value.source + " = " + value.target );
                    main_table_content += "<tr><td>"+value.source+"</td><td class='input-box-td'><input type='text' class='editable' value='"+value.target+"' /><a class='plus-input'>+</a><a class='minus-input'>-</a></td><td>301</td><td>url</td><td>active</td></tr>";
                });

                $(".redirect-suggestions").find("table").find("tbody").html(main_table_content);
                $(".redirect-suggestions").show();
            },
            error: function () {
                $('#seo-setup-301-redirets-response').append('<p>AJAX error during schema batch processing.</p>');
            }
        });
    })

    $("#save_redirection").click(function(){
        seoSetupRestAjax({
            url: seoSetupGetRestUrl('seo_setup_301_redirection_save_ajax'),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupGetRestHeaders(),
            data: JSON.stringify({
                source_url: $("#source-url").val()
            }),
            success: function (response) {
                // if (!response.success) {
                //     $('#seo-setup-schema-response').append('<p>Error get 301 URL not getting.</p>');
                //     return;
                // }
                // const serverData = response.data;
                // var  main_url_array = jQuery.parseJSON(serverData.main_array);
                // var main_table_content = ""; 
                // $.each( main_url_array, function( key, value ) {
                //     console.log( key + " => : " + value.source + " = " + value.target );
                //     main_table_content += "<tr><td>"+value.source+"</td><td><input type='text' class='editable' value='"+value.target+"' /></td><td>301</td><td>url</td><td>active</td></tr>";
                // });

                // $(".redirect-suggestions").find("table").find("tbody").html(main_table_content);
                // $(".redirect-suggestions").show();
            },
            error: function () {
                // $('#seo-setup-301-redirets-response').append('<p>AJAX error during schema batch processing.</p>');
            }
        });
    })

    $(document).on("click",".minus-input" ,function(e){
        e.preventDefault(); 
        $(this).closest(".input-box-td").find("input").remove();
        $(this).closest(".input-box-td").find(".plus-input").show();
        $(this).hide(); 
    })

    $(document).on("click",".plus-input" ,function(e){
        e.preventDefault(); 
        $(this).closest(".input-box-td").prepend( "<input type='text' class='editable' />" );
        $(this).closest(".input-box-td").find(".minus-input").show();
        $(this).hide();
    })
})


// FIXED: Geo coordinate extraction code moved to separate geo.js file
// to prevent loading the entire admin script on the frontend.
