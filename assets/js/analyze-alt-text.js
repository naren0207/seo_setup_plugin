/**
 * SEO Setup - Analyze Image Alt Text
 *
 * Audits EXISTING alt text via Gemini (unlike generate-alt-text.js, which
 * only fills in MISSING alt text). Shows suitable/not-suitable counts and
 * lets the admin bulk-apply Gemini's suggested replacements.
 *
 * Reuses the REST/legacy-ajax fallback plumbing (seoSetupAltTextAjax(),
 * seoSetupAltTextRestHeaders(), SEOSetupAltTextAjax) already defined by
 * generate-alt-text.js, which this script depends on and loads after.
 */

var seoSetupAltTextAnalysisRoutes = {
    'seo_setup_alt_text_analysis_start_audit':   '/alt-text-analysis/start-audit',
    'seo_setup_alt_text_analysis_process_batch': '/alt-text-analysis/process-batch',
    'seo_setup_alt_text_analysis_fix':           '/alt-text-analysis/fix',
    'seo_setup_alt_text_analysis_pending_count': '/alt-text-analysis/pending-count'
};

function seoSetupAltTextAnalysisRestUrl(action) {
    var route = seoSetupAltTextAnalysisRoutes[action] || '';
    if (route && typeof SEOSetupAltTextAjax !== 'undefined' && SEOSetupAltTextAjax.restBase) {
        return SEOSetupAltTextAjax.restBase + route;
    }
    return (typeof SEOSetupAltTextAjax !== 'undefined' ? SEOSetupAltTextAjax.ajaxurl : '') || '';
}

jQuery(document).ready(function ($) {

    var $analyzeButton = $('#seo-setup-analyze-alt-text-button');
    var $fixButton      = $('#seo-setup-fix-alt-text-button');
    var $progress        = $('#seo-setup-analyze-alt-text-progress');
    var $summary         = $('#seo-setup-analyze-alt-text-summary');
    var $resultsTable   = $('#seo-setup-analyze-alt-text-table');
    var $fixResults     = $('#seo-setup-fix-alt-text-results');

    var runningSent       = 0;
    var runningSuitable   = 0;
    var runningUnsuitable = 0;
    var unsuitableItems   = [];

    function escapeHtml(str) {
        return $('<div>').text(str == null ? '' : str).html();
    }

    function renderSummary() {
        $summary.html(
            '<p><strong>Sent:</strong> ' + runningSent +
            ' &nbsp; <strong>Suitable:</strong> ' + runningSuitable +
            ' &nbsp; <strong>Not Suitable:</strong> ' + runningUnsuitable + '</p>'
        );
    }

    function renderResultsTable() {
        if (!unsuitableItems.length) {
            $resultsTable.html('');
            return;
        }

        var html = '<table style="width:100%; border-collapse:collapse; margin-top:15px;">' +
            '<thead><tr>' +
            '<th style="border:1px solid #ddd; padding:8px; text-align:left;">Image</th>' +
            '<th style="border:1px solid #ddd; padding:8px; text-align:left;">Current Alt Text</th>' +
            '<th style="border:1px solid #ddd; padding:8px; text-align:left;">Suggested Alt Text</th>' +
            '</tr></thead><tbody>';

        unsuitableItems.forEach(function (item) {
            html += '<tr>' +
                '<td style="border:1px solid #ddd; padding:8px;"><a href="' + item.image_url + '" target="_blank">' + escapeHtml(item.image_url) + '</a></td>' +
                '<td style="border:1px solid #ddd; padding:8px;">' + (item.current_alt ? escapeHtml(item.current_alt) : '<em>(empty)</em>') + '</td>' +
                '<td style="border:1px solid #ddd; padding:8px;">' + escapeHtml(item.suggested_alt_text) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $resultsTable.html(html);
    }

    function refreshPendingCount() {
        seoSetupAltTextAjax({
            url: seoSetupAltTextAnalysisRestUrl('seo_setup_alt_text_analysis_pending_count'),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupAltTextRestHeaders(),
            data: JSON.stringify({}),
            success: function (response) {
                if (response.success && response.data.pending_count > 0) {
                    $fixButton.show().text('Fix Image Alt Text (' + response.data.pending_count + ' pending)');
                } else {
                    $fixButton.hide();
                }
            }
        });
    }

    refreshPendingCount();

    $analyzeButton.on('click', function (e) {
        e.preventDefault();

        runningSent = 0;
        runningSuitable = 0;
        runningUnsuitable = 0;
        unsuitableItems = [];

        renderSummary();
        $resultsTable.html('');
        $progress.show().html('<p>Finding images to analyze...</p>');
        $analyzeButton.prop('disabled', true);

        seoSetupAltTextAjax({
            url: seoSetupAltTextAnalysisRestUrl('seo_setup_alt_text_analysis_start_audit'),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: seoSetupAltTextRestHeaders(),
            data: JSON.stringify({}),
            success: function (response) {
                if (!response.success) {
                    $progress.html('<p>Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error') + '</p>');
                    $analyzeButton.prop('disabled', false);
                    return;
                }

                var items = response.data.items || [];
                if (!items.length) {
                    $progress.html('<p>Nothing new to analyze — every image is already up to date.</p>');
                    $analyzeButton.prop('disabled', false);
                    refreshPendingCount();
                    return;
                }

                var batchSize    = 5;
                var totalBatches = Math.ceil(items.length / batchSize);
                var currentBatch = 0;

                function processBatch() {
                    var batchItems = items.slice(currentBatch * batchSize, (currentBatch + 1) * batchSize);

                    $progress.html('<p>Analyzing batch ' + (currentBatch + 1) + ' of ' + totalBatches + ' (' + items.length + ' images total)...</p>');

                    seoSetupAltTextAjax({
                        url: seoSetupAltTextAnalysisRestUrl('seo_setup_alt_text_analysis_process_batch'),
                        method: 'POST',
                        dataType: 'json',
                        contentType: 'application/json',
                        headers: seoSetupAltTextRestHeaders(),
                        data: JSON.stringify({ batch_data: batchItems }),
                        success: function (response) {
                            if (response.success) {
                                runningSent += batchItems.length;
                                runningSuitable += response.data.suitable_count || 0;
                                runningUnsuitable += response.data.unsuitable_count || 0;
                                unsuitableItems = unsuitableItems.concat(response.data.unsuitable_items || []);
                                renderSummary();
                                renderResultsTable();
                            }

                            currentBatch++;
                            if (currentBatch < totalBatches) {
                                setTimeout(processBatch, 300);
                            } else {
                                $progress.html('<p>Analysis complete.</p>');
                                $analyzeButton.prop('disabled', false);
                                refreshPendingCount();
                            }
                        },
                        error: function () {
                            $progress.append('<p style="color:red;">Batch ' + (currentBatch + 1) + ' failed — continuing with remaining batches.</p>');
                            currentBatch++;
                            if (currentBatch < totalBatches) {
                                setTimeout(processBatch, 300);
                            } else {
                                $analyzeButton.prop('disabled', false);
                                refreshPendingCount();
                            }
                        }
                    });
                }

                processBatch();
            },
            error: function () {
                $progress.html('<p style="color:red;">AJAX error starting the audit.</p>');
                $analyzeButton.prop('disabled', false);
            }
        });
    });

    $fixButton.on('click', function (e) {
        e.preventDefault();

        var fixBatchLimit = 10;
        var totalFixed = 0;

        $fixButton.prop('disabled', true);
        $fixResults.html('<p>Applying fixes...</p>');

        function processFixBatch() {
            seoSetupAltTextAjax({
                url: seoSetupAltTextAnalysisRestUrl('seo_setup_alt_text_analysis_fix'),
                method: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                headers: seoSetupAltTextRestHeaders(),
                data: JSON.stringify({ limit: fixBatchLimit }),
                success: function (response) {
                    if (!response.success) {
                        $fixResults.html('<p style="color:red;">Error applying fixes.</p>');
                        $fixButton.prop('disabled', false);
                        return;
                    }

                    totalFixed += response.data.fixed_count || 0;

                    if (response.data.has_more) {
                        $fixResults.html('<p>Fixed ' + totalFixed + ' image(s) so far, ' + response.data.remaining_count + ' remaining...</p>');
                        setTimeout(processFixBatch, 300);
                        return;
                    }

                    $fixResults.html('<p>Done — fixed ' + totalFixed + ' image(s).</p>');
                    $fixButton.prop('disabled', false).hide();
                },
                error: function () {
                    $fixResults.html('<p style="color:red;">AJAX error while applying fixes.</p>');
                    $fixButton.prop('disabled', false);
                }
            });
        }

        processFixBatch();
    });
});
