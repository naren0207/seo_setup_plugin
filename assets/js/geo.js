/**
 * SEO Setup - Geo Coordinates Extraction (Frontend Only)
 * 
 * This script extracts address from Google Maps iframes on the page,
 * geocodes via Nominatim, and sends coordinates to WordPress backend.
 * 
 * Uses REST API endpoint /wp-json/seo-setup/v1/save-geo-coords
 * instead of admin-ajax.php to avoid conflicts.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        if (typeof localStorage === 'undefined') return;
        if (typeof seoSetupAjax === 'undefined' || !seoSetupAjax.restBase) return;

        var $iframe = $('iframe[src*="maps.google.com/maps?q="]');
        if ($iframe.length === 0) {
            return;
        }

        var src = $iframe.attr('src');
        var qParam = src.match(/maps\.google\.com\/maps\?q=([^&]+)/i);
        if (!qParam || !qParam[1]) {
            return;
        }

        var address = decodeURIComponent(qParam[1]);

        if (localStorage.getItem('seo_setup_geo_sent') === address) {
            return;
        }

        $.getJSON('https://nominatim.openstreetmap.org/search', {
            q: address,
            format: 'json',
            limit: 1
        }).done(function (data) {
            if (data && data.length > 0) {
                var lat = data[0].lat;
                var lon = data[0].lon;

                // CHANGED: Use REST API instead of admin-ajax.php
                $.ajax({
                    url: seoSetupAjax.restBase + '/save-geo-coords',
                    method: 'POST',
                    dataType: 'json',
                    contentType: 'application/json',
                    data: JSON.stringify({ lat: lat, lng: lon })
                });

                localStorage.setItem('seo_setup_geo_sent', address);
            }
        });
    });
})(jQuery);
