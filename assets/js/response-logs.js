/**
 * ChatGPT Fluent Connector - Response Logs JavaScript
 * Save this as: assets/js/logs-script.js
 */

(function ($) {
    'use strict';

    $(document).ready(function () {

        // Handle JSON downloads with better error handling
        jQuery(document).on('click', '.sfaic-download-json', function (e) {
            e.preventDefault();

            var $button = $(this);
            var originalHtml = $button.html();
            var downloadUrl = $button.attr('href');

            // Show loading state
            $button.html('<span class="dashicons dashicons-update spin" style="vertical-align: middle;"></span> Downloading...');
            $button.prop('disabled', true);

            // Create a temporary iframe for download
            var iframe = $('<iframe>', {
                id: 'sfaic-download-frame',
                src: downloadUrl,
                style: 'display:none;'
            }).appendTo('body');

            // Reset button after a delay
            setTimeout(function () {
                $button.html(originalHtml);
                $button.prop('disabled', false);
                iframe.remove();
            }, 2000);

            // Alternative method using fetch if iframe fails
            if (window.fetch) {
                fetch(downloadUrl)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Download failed');
                            }
                            return response.blob();
                        })
                        .then(blob => {
                            var url = window.URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = $button.data('type') + '-' + $button.data('log-id') + '.json';
                            document.body.appendChild(a);
                            a.click();
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(a);
                        })
                        .catch(error => {
                            console.error('Download error:', error);
                            alert('Failed to download JSON file. Please try again.');
                        })
                        .finally(() => {
                            $button.html(originalHtml);
                            $button.prop('disabled', false);
                        });
            }
        });

        // Toggle between raw and rendered response views
        $('.sfaic-view-toggle').on('click', function (e) {
            e.preventDefault();

            var $this = $(this);
            var target = $this.data('target');

            // Update active state
            $('.sfaic-view-toggle').removeClass('active');
            $this.addClass('active');

            // Show/hide views
            $('.sfaic-response-view').hide();
            $('#' + target).fadeIn(200);
        });

        // Copy response to clipboard
        $('.sfaic-copy-response').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            var originalText = $button.text();

            // Get the active view content
            var content = '';
            if ($('#sfaic-raw-response').is(':visible')) {
                content = $('#sfaic-raw-response pre').text();
            } else {
                content = $('#sfaic-rendered-response .sfaic-rendered-response').text();
            }

            // Create temporary textarea for copying
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(content).select();

            try {
                document.execCommand('copy');

                // Show success feedback
                $button.text('✓ Copied!');
                $button.addClass('button-primary');

                setTimeout(function () {
                    $button.text(originalText);
                    $button.removeClass('button-primary');
                }, 2000);
            } catch (err) {
                // Show error feedback
                $button.text('Failed to copy');

                setTimeout(function () {
                    $button.text(originalText);
                }, 2000);
            }

            $temp.remove();
        });

        // Enhanced filter form
        $('#sfaic-filters-form').on('submit', function (e) {
            // Remove empty fields to clean up URL
            $(this).find('input, select').each(function () {
                if (!$(this).val()) {
                    $(this).prop('disabled', true);
                }
            });
        });

        // Date range validation
        $('input[name="date_from"], input[name="date_to"]').on('change', function () {
            var dateFrom = $('input[name="date_from"]').val();
            var dateTo = $('input[name="date_to"]').val();

            if (dateFrom && dateTo) {
                if (dateFrom > dateTo) {
                    alert('Start date cannot be after end date');
                    $(this).val('');
                }
            }
        });

        // Token usage tooltips
        $('.column-tokens span[title]').on('mouseenter', function () {
            var $this = $(this);
            var title = $this.attr('title');

            // Create tooltip
            var $tooltip = $('<div class="sfaic-tooltip">' + title + '</div>');
            $('body').append($tooltip);

            // Position tooltip
            var offset = $this.offset();
            $tooltip.css({
                top: offset.top - $tooltip.outerHeight() - 10,
                left: offset.left + ($this.outerWidth() / 2) - ($tooltip.outerWidth() / 2)
            }).fadeIn(200);

            $this.data('tooltip', $tooltip);
        }).on('mouseleave', function () {
            var $tooltip = $(this).data('tooltip');
            if ($tooltip) {
                $tooltip.fadeOut(200, function () {
                    $(this).remove();
                });
            }
        });

        // Bulk actions enhancement
        $('#doaction, #doaction2').on('click', function (e) {
            var action = $(this).siblings('select').val();

            if (action === 'delete') {
                var checkedCount = $('tbody .check-column input:checked').length;

                if (checkedCount > 0) {
                    if (!confirm('Are you sure you want to delete ' + checkedCount + ' log(s)?')) {
                        e.preventDefault();
                    }
                }
            }
        });

        // Token usage bar animation
        $('.token-usage-bar').each(function () {
            var $bar = $(this);
            var $fill = $bar.find('.token-usage-fill');
            var percentage = $fill.data('percentage');

            // Animate on page load
            setTimeout(function () {
                $fill.css('width', percentage + '%');
            }, 100);
        });

        // Print functionality
        $('#sfaic-print-log').on('click', function (e) {
            e.preventDefault();
            window.print();
        });
    });

})(jQuery);

// Tooltip styles
jQuery(document).ready(function ($) {
    if ($('#sfaic-tooltip-styles').length === 0) {
        $('head').append(`
            <style id="sfaic-tooltip-styles">
                .sfaic-tooltip {
                    position: absolute;
                    background: #333;
                    color: #fff;
                    padding: 8px 12px;
                    border-radius: 4px;
                    font-size: 12px;
                    white-space: nowrap;
                    z-index: 9999;
                    pointer-events: none;
                    display: none;
                    max-width: 300px;
                }
                .sfaic-tooltip:after {
                    content: '';
                    position: absolute;
                    top: 100%;
                    left: 50%;
                    margin-left: -5px;
                    border-width: 5px;
                    border-style: solid;
                    border-color: #333 transparent transparent transparent;
                }
            </style>
        `);
    }
});


jQuery(document).ready(function ($) {
    // Add refresh button to stats dashboard
    $('.sfaic-token-stats-dashboard h3').append(
            '<button class="button button-secondary" id="sfaic-refresh-stats" style="float: right;">' +
            '<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Refresh' +
            '</button>'
            );

    // Handle refresh button click
    $('#sfaic-refresh-stats').on('click', function () {
        var $button = $(this);
        $button.prop('disabled', true);
        $button.find('.dashicons').addClass('spin');

        $.post(ajaxurl, {
            action: 'sfaic_refresh_token_stats',
            nonce: sfaic_ajax.nonce,
            days: 30
        }, function (response) {
            if (response.success) {
                // Update the stats display
                location.reload(); // Simple reload for now
            }
            $button.prop('disabled', false);
            $button.find('.dashicons').removeClass('spin');
        });
    });
});
