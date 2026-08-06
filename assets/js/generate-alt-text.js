// REST API route map for alt text endpoints
var seoSetupAltTextRoutes = {
    'seo_setup_get_images_with_alt':           '/alt-text/images-with-alt',
    'seo_setup_generate_alt_text_start_audit': '/alt-text/start-audit',
    'seo_setup_generate_alt_text_process_batch': '/alt-text/process-batch',
    'seo_setup_get_images_without_alt':        '/alt-text/images-without-alt',
    'seo_setup_get_alt_text_report_data':      '/alt-text/report-data',
};

function seoSetupAltTextRestUrl(action) {
    var route = seoSetupAltTextRoutes[action] || '';
    if (route && SEOSetupAltTextAjax.restBase) {
        return SEOSetupAltTextAjax.restBase + route;
    }
    return SEOSetupAltTextAjax.ajaxurl || '';
}

function seoSetupAltTextRestHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-WP-Nonce': SEOSetupAltTextAjax.nonce || ''
    };
}

function seoSetupAltTextAjax(options) {
    if (typeof window.seoSetupRestAjax === 'function') {
        return window.seoSetupRestAjax(options);
    }

    var originalOptions = jQuery.extend(true, {}, options || {});
    var successCallback = originalOptions.success;
    var errorCallback = originalOptions.error;
    var completeCallback = originalOptions.complete;
    var deferred = jQuery.Deferred();

    delete originalOptions.success;
    delete originalOptions.error;
    delete originalOptions.complete;

    var legacyAction = '';
    var url = originalOptions.url || '';
    for (var action in seoSetupAltTextRoutes) {
        if (Object.prototype.hasOwnProperty.call(seoSetupAltTextRoutes, action) && url.indexOf(seoSetupAltTextRoutes[action]) !== -1) {
            legacyAction = action;
            break;
        }
    }

    var legacyUrl = '';
    if (typeof SEOSetupAltTextAjax !== 'undefined' && SEOSetupAltTextAjax.legacyAjaxUrl) {
        legacyUrl = SEOSetupAltTextAjax.legacyAjaxUrl;
    } else if (typeof ajaxurl !== 'undefined') {
        legacyUrl = ajaxurl;
    }

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
        complete(context, xhr, textStatus);
    }

    function parseData(data) {
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

    function sendLegacyAjax(fallbackXhr, fallbackTextStatus, fallbackErrorThrown) {
        var context = originalOptions.context || originalOptions;
        if (!legacyAction || !legacyUrl) {
            fail(context, fallbackXhr, fallbackTextStatus, fallbackErrorThrown);
            return;
        }

        var data = parseData(originalOptions.data);
        var nonce = SEOSetupAltTextAjax.ajaxNonce || SEOSetupAltTextAjax.legacyNonce || '';
        data.action = legacyAction;
        data.security = nonce;

        jQuery.ajax({
            url: legacyUrl,
            method: originalOptions.method || originalOptions.type || 'POST',
            type: originalOptions.type || originalOptions.method || 'POST',
            dataType: originalOptions.dataType || 'json',
            data: data,
            success: function(response, textStatus, xhr) {
                if (successCallback) {
                    successCallback.call(context, response, textStatus, xhr);
                }
                deferred.resolveWith(context, [response, textStatus, xhr]);
            },
            error: function(xhr, textStatus, errorThrown) {
                fail(context, xhr, textStatus, errorThrown);
            },
            complete: function(xhr, textStatus) {
                complete(context, xhr, textStatus);
            }
        });
    }

    function sendRest() {
        var context = originalOptions.context || originalOptions;
        var restOptions = jQuery.extend(true, {}, originalOptions);
        var isFallingBackToLegacy = false;

        restOptions.success = function(response, textStatus, xhr) {
            if (successCallback) {
                successCallback.call(context, response, textStatus, xhr);
            }
            deferred.resolveWith(context, [response, textStatus, xhr]);
        };

        restOptions.error = function(xhr, textStatus, errorThrown) {
            isFallingBackToLegacy = true;
            sendLegacyAjax(xhr, textStatus, errorThrown);
        };

        restOptions.complete = function(xhr, textStatus) {
            if (isFallingBackToLegacy) {
                return;
            }
            complete(context, xhr, textStatus);
        };

        jQuery.ajax(restOptions);
    }

    sendRest();
    return deferred.promise();
}


jQuery(document).ready(function($) {

    function appendMessage(message, type = 'info') {
        let icon;
        let messageClass;

        if (type === 'in-progress') {
            icon = '<span class="meta-wizz-icon">&#x23F3;</span>'; // Hourglass
            messageClass = 'meta-wizz-message in-progress';
        } else if (type === 'success') {
            icon = '<span class="meta-wizz-icon">&#x2714;</span>'; // Checkmark
            messageClass = 'meta-wizz-message success';
        } else if (type === 'summary') {
            icon = '<span class="meta-wizz-icon">&#x1F4C8;</span>'; // Chart icon
            messageClass = 'meta-wizz-message summary';
        } else if (type === 'error') {
            icon = '<span class="meta-wizz-icon">&#x26A0;</span>'; // Warning
            messageClass = 'meta-wizz-message error';
        } else {
            icon = '<span class="meta-wizz-icon">&#x1F4AC;</span>'; // Speech bubble
            messageClass = 'meta-wizz-message';
        }

        $('#seo-setup-generateAltText-progress').append(
            `<div class="${messageClass}">
                ${icon}
                <span class="meta-wizz-message-text">${message}</span>
            </div>`
        );
    }

    function updateBatchStatus(message, type = 'info') {
        let $container = $('#seo-setup-generateAltText-progress');

        let icon;
        let messageClass;

        if (type === 'in-progress') {
            icon = '<span class="meta-wizz-icon">&#x23F3;</span>';
            messageClass = 'meta-wizz-message in-progress';
        } else if (type === 'success') {
            icon = '<span class="meta-wizz-icon">&#x2714;</span>';
            messageClass = 'meta-wizz-message success';
        } else if (type === 'error') {
            icon = '<span class="meta-wizz-icon">&#x26A0;</span>';
            messageClass = 'meta-wizz-message error';
        } else {
            icon = '<span class="meta-wizz-icon">&#x1F4AC;</span>';
            messageClass = 'meta-wizz-message';
        }

        $container.fadeOut(200, function() {
            $container.html(`
                <div class="${messageClass}">
                    ${icon}
                    <span class="meta-wizz-message-text">${message}</span>
                </div>
            `);
            $container.fadeIn(200);
        });
    }

    $('#seo-setup-generateAltText-progress').hide();

    let $generateButton   = $('#seo-setup-generate-alt-text-button');
    let $resultsContainer = $('#seo-setup-generateAltText');

    $generateButton.on('click', function() {

        // 1) Clear and show progress/results
        $('#seo-setup-generateAltText-progress').show().html('');
        appendMessage('Finding images without alt text...', 'in-progress');
        $resultsContainer.html('').show();
        $('#seo-setup-existing-alt-table').html('');

        
        //$('#seo-setup-existing-alt-table').html('Copying existing alt text to image title field...');
        seoSetupAltTextAjax({
            url: seoSetupAltTextRestUrl('seo_setup_get_images_with_alt'),
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupAltTextRestHeaders(),
            data: JSON.stringify({}),
            success: function(response) {
                if (response.success && response.data.length) {
                    let html = '<h3>Copied Alt Text → Image Title Field</h3>'
                             + '<table style="width:100%;border-collapse:collapse;">'
                             + '<thead><tr>'
                             +   '<th style="border:1px solid #ddd;padding:8px;">S.No</th>'
                             +   '<th style="border:1px solid #ddd;padding:8px;">Image ID</th>'
                             +   '<th style="border:1px solid #ddd;padding:8px;">URL</th>'
                             +   '<th style="border:1px solid #ddd;padding:8px;">Existing Alt Text</th>'
                             +   '<th style="border:1px solid #ddd;padding:8px;">Updated Image Title</th>'
                             + '</tr></thead><tbody>';
                    response.data.forEach(function(item, idx) {
                        // since we copied alt text → title, title === alt
                        html += '<tr>'
                             +  '<td style="border:1px solid #ddd;padding:8px;">' + (idx+1) + '</td>'
                             +  '<td style="border:1px solid #ddd;padding:8px;">' + item.id + '</td>'
                             +  '<td style="border:1px solid #ddd;padding:8px;">'
                             +    '<a href="' + item.url + '" target="_blank">' + item.url + '</a>'
                             +  '</td>'
                             +  '<td style="border:1px solid #ddd;padding:8px;">' + item.alt + '</td>'
                             +  '<td style="border:1px solid #ddd;padding:8px;">' + item.alt + '</td>'
                             + '</tr>';
                    });
                    html += '</tbody></table>';
                    $('#seo-setup-existing-alt-table').html(html);
                } else {
                    $('#seo-setup-existing-alt-table').html('No titles were updated.');
                }
            },
            error: function() {
                $('#seo-setup-existing-alt-table').html('Error loading copied-alt→title details.');
            }
        });

        // 3) Audit & batch logic
        seoSetupAltTextAjax({
            url: seoSetupAltTextRestUrl('seo_setup_generate_alt_text_start_audit'),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupAltTextRestHeaders(),
            data: JSON.stringify({}),
            success: function(response) {

            if (response.success) {

                if (response.data.status === 'all_included') {
                    $('#seo-setup-generateAltText-progress').html('');
                    appendMessage('All images already have alt text!', 'summary');

                } else {
                    let missingImages = response.data.issues;
                    let totalImages   = missingImages.length;
                    let batchSize     = 15;
                    let totalBatches  = Math.ceil(totalImages / batchSize);
                    let currentBatch  = 0;
                    let startTime     = Date.now();

                    let resultTable = `
                        <h3>Found ${totalImages} Images Missing Alt Text</h3>
                        <table class="vsp-alt-text-table" style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr><th style="border:1px solid #ddd; padding:8px; text-align:left;">Image URL</th></tr>
                            </thead>
                            <tbody>
                    `;
                    missingImages.forEach(function(url) {
                        resultTable += `<tr><td style="border:1px solid #ddd; padding:8px;">${url}</td></tr>`;
                    });
                    resultTable += '</tbody></table>';

                    $resultsContainer.html(resultTable);
                    $('#seo-setup-missing-alt-table').html(resultTable);

                    $('#seo-setup-generateAltText-progress').html('');

                    setTimeout(function() {
                        processBatch();
                    }, 3000);

                    function processBatch() {
                        let batchData = missingImages.slice(
                            currentBatch * batchSize,
                            (currentBatch + 1) * batchSize
                        );

                        updateBatchStatus(`Processing batch ${currentBatch + 1} of ${totalBatches}...`, 'in-progress');

                        let promises = batchData.map((imgUrl) => {
                            return new Promise((resolve) => {
                                let img = new Image();
                                img.crossOrigin = 'Anonymous';
                                img.onload = function () {
                                    const canvas = document.createElement('canvas');
                                    const scale = Math.min(512 / img.width, 512 / img.height);
                                    canvas.width = img.width * scale;
                                    canvas.height = img.height * scale;
                                    const ctx = canvas.getContext('2d');
                                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

                                    const mime = imgUrl.endsWith('.png') ? 'image/png' : 'image/jpeg';
                                    const base64 = canvas.toDataURL(mime, 0.8); // quality 80%
                                    resolve({ original_url: imgUrl, image_base64: base64 });
                                };
                                img.onerror = function () {
                                    resolve({ original_url: imgUrl, image_base64: null });
                                };
                                img.src = imgUrl;
                            });
                        });

                        Promise.all(promises).then((resizedBatch) => {
                            seoSetupAltTextAjax({
                                url: seoSetupAltTextRestUrl('seo_setup_generate_alt_text_process_batch'),
                                method: 'POST',
                                dataType: 'json',
                                contentType: 'application/json',
                                headers: seoSetupAltTextRestHeaders(),
                                data: JSON.stringify({
                                    batch_data: resizedBatch
                                }),
                                success: function(batchResponse) {
                                    updateBatchStatus(`Batch ${currentBatch + 1} of ${totalBatches} processed successfully.`, 'success');
                                    setTimeout(function () {
                                        currentBatch++;
                                        if (currentBatch < totalBatches) {
                                            processBatch();
                                        } else {
                                            let totalTimeSec = (Date.now() - startTime) / 1000;
                                            appendMessage(
                                                `All done! Processed ${totalImages} images in ${totalTimeSec.toFixed(2)} seconds.`,
                                                'summary'
                                            );

                                            $('#seo-setup-generateAltText-progress').append(
                                                '<button id="seo-setup-download-alt-text-csv" style="margin-top:15px;">Download CSV Report</button>'
                                            );
                                        }
                                    }, 2000);
                                },
                                error: function(xhr) {
                                    updateBatchStatus('Error: ' + (xhr.responseJSON?.message || 'Unknown error during batch.'), 'error');
                                    setTimeout(function () {
                                        currentBatch++;
                                        if (currentBatch < totalBatches) {
                                            processBatch();
                                        }
                                    }, 2000);
                                }
                            });
                        });
                    }
                }

            } else {
                appendMessage('Error: ' + (response.data?.message || 'Unknown error during audit.'), 'error');
            }
            }
        }).fail(function(xhr) {
            appendMessage('Error: ' + (xhr.responseJSON?.message || 'Unknown AJAX error.'), 'error');
        });
    });

    // Get Image URLs without alt text
    $('#seo-setup-get-urls-without-alt-button').on('click', function (e) {
        e.preventDefault();

        $('#seo-setup-missing-alt-table').html('Fetching Images Without Alt Text...');

        seoSetupAltTextAjax({
            url: seoSetupAltTextRestUrl('seo_setup_get_images_without_alt'),
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupAltTextRestHeaders(),
            data: JSON.stringify({}),
            success: function (response) {
                if (response.success && response.data.length > 0) {
                    let tableHtml = '<table style="width: 100%; border-collapse: collapse;">';
                    tableHtml += '<thead><tr><th style="border: 1px solid #ddd; padding: 8px;">S.No</th><th style="border: 1px solid #ddd; padding: 8px;">Image ID</th><th style="border: 1px solid #ddd; padding: 8px;">Image URL</th></tr></thead><tbody>';

                    response.data.forEach(function(item, index) {
                        tableHtml += '<tr>';
                        tableHtml += '<td style="border: 1px solid #ddd; padding: 8px;">' + (index + 1) + '</td>';
                        tableHtml += '<td style="border: 1px solid #ddd; padding: 8px;">' + item.id + '</td>';
                        tableHtml += '<td style="border: 1px solid #ddd; padding: 8px;"><a href="' + item.url + '" target="_blank">' + item.url + '</a></td>';
                        tableHtml += '</tr>';
                    });

                    tableHtml += '</tbody></table>';
                    $('#seo-setup-missing-alt-table').html(tableHtml);
                } else {
                    $('#seo-setup-missing-alt-table').html('No images without alt text found.');
                }
            },
            error: function () {
                $('#seo-setup-missing-alt-table').html('An error occurred. Please try again.');
            }
        });
    });

    /**
     * NEW: Helper function to escape a string for CSV format.
     */
    function escapeCsvCell(str) {
        let cell = str === null || str === undefined ? '' : String(str);
        if (cell.search(/("|,|\n)/g) >= 0) {
            cell = '"' + cell.replace(/"/g, '""') + '"';
        }
        return cell;
    }

    /**
     * Click handler for the download button.
     */
    $('#seo-setup-generateAltText-progress').on('click', '#seo-setup-download-alt-text-csv', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.text('Preparing download...').prop('disabled', true);

        seoSetupAltTextAjax({
            url: seoSetupAltTextRestUrl('seo_setup_get_alt_text_report_data'),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupAltTextRestHeaders(),
            data: JSON.stringify({}),
            success: function(response) {
                if (!response.success) {
                    alert('Could not fetch data for CSV. Error: ' + (response.data.message || 'Unknown Error'));
                    $button.text('Download CSV Report').prop('disabled', false);
                    return;
                }
                
                if (!response.data || response.data.length === 0) {
                    alert('No data was generated in this session to download.');
                    $button.text('Download CSV Report').prop('disabled', false);
                    return;
                }

                const headers = ["Image URL", "Generated Alt Text"];
                let csvContent = headers.join(',') + '\n';

                response.data.forEach(function(item) {
                    const row = [item.image_url, item.alt_text];
                    csvContent += row.map(escapeCsvCell).join(',') + '\n';
                });
                
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'alt_text_report.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);

                $button.text('Download CSV Report').prop('disabled', false);
            },
            error: function() {
                alert('An AJAX error occurred while preparing the download.');
                $button.text('Download CSV Report').prop('disabled', false);
            }
        });
    });
});
