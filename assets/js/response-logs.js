/**
 * ChatGPT Fluent Connector - Response Logs JavaScript with Restart Functionality
 * Save this as: assets/js/response-logs.js
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

        // NEW: Handle AI process restart
        $(document).on('click', '.sfaic-restart-process', function (e) {
            e.preventDefault();

            var $button = $(this);
            var logId = $button.data('log-id');
            var originalHtml = $button.html();

            // Confirm restart
            if (!confirm(sfaic_ajax.strings.confirm_restart)) {
                return;
            }

            // Show loading state
            $button.html('<span class="dashicons dashicons-update spin" style="vertical-align: middle; margin-right: 5px;"></span>' + sfaic_ajax.strings.restarting);
            $button.prop('disabled', true);

            // Make AJAX request to restart process
            $.post(sfaic_ajax.ajax_url, {
                action: 'sfaic_restart_ai_process',
                log_id: logId,
                nonce: sfaic_ajax.restart_nonce
            })
            .done(function (response) {
                if (response.success) {
                    // Show success message
                    showNotification(response.data.message, 'success');
                    
                    // If background processing, show additional info
                    if (response.data.is_background) {
                        showNotification('The process is running in the background. Check the Background Jobs page for status.', 'info');
                    }
                    
                    // Optionally refresh the page after a delay to show new log entry
                    setTimeout(function() {
                        if (confirm('Restart completed. Would you like to refresh the page to see the new log entry?')) {
                            location.reload();
                        }
                    }, 2000);
                    
                } else {
                    showNotification(sfaic_ajax.strings.restart_error + ': ' + response.data.message, 'error');
                }
            })
            .fail(function (xhr, status, error) {
                console.error('Restart request failed:', error);
                showNotification(sfaic_ajax.strings.restart_error + ': ' + error, 'error');
            })
            .always(function () {
                // Reset button state
                $button.html(originalHtml);
                $button.prop('disabled', false);
            });
        });

        // NEW: Show notification function
        function showNotification(message, type) {
            type = type || 'info';
            
            var noticeClass = 'notice notice-' + type;
            if (type === 'success') {
                noticeClass = 'notice notice-success';
            } else if (type === 'error') {
                noticeClass = 'notice notice-error';
            } else if (type === 'warning') {
                noticeClass = 'notice notice-warning';
            } else {
                noticeClass = 'notice notice-info';
            }
            
            var $notice = $('<div class="' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Insert notice after the page title
            $('.wrap h1').after($notice);
            
            // Make notice dismissible
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            });
            
            // Auto-remove success/info notices after 5 seconds
            if (type === 'success' || type === 'info') {
                setTimeout(function() {
                    $notice.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        }

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

        // NEW: Keyboard shortcut for restart (Ctrl+R or Cmd+R on selected row)
        $(document).on('keydown', function(e) {
            // Check if Ctrl+R (or Cmd+R on Mac) is pressed
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 82) {
                var $focusedRow = $('.wp-list-table tbody tr:focus, .wp-list-table tbody tr:hover').first();
                if ($focusedRow.length) {
                    var $restartButton = $focusedRow.find('.sfaic-restart-process');
                    if ($restartButton.length) {
                        e.preventDefault();
                        $restartButton.click();
                    }
                }
            }
        });

        // NEW: Add hover effects for restart buttons
        $(document).on('mouseenter', '.sfaic-restart-process', function() {
            $(this).css({
                'background-color': '#218838',
                'border-color': '#1e7e34',
                'transform': 'translateY(-1px)',
                'box-shadow': '0 2px 4px rgba(0,0,0,0.1)'
            });
        }).on('mouseleave', '.sfaic-restart-process', function() {
            if (!$(this).prop('disabled')) {
                $(this).css({
                    'background-color': '#28a745',
                    'border-color': '#28a745',
                    'transform': 'translateY(0)',
                    'box-shadow': 'none'
                });
            }
        });

        // NEW: Progress indicator for long-running restarts
        var restartProgressInterval;
        
        function startRestartProgress($button) {
            var dots = 0;
            var baseText = sfaic_ajax.strings.restarting.replace('...', '');
            
            restartProgressInterval = setInterval(function() {
                dots = (dots + 1) % 4;
                var dotsText = '.'.repeat(dots);
                $button.find('span').last().text(' ' + baseText + dotsText);
            }, 500);
        }
        
        function stopRestartProgress() {
            if (restartProgressInterval) {
                clearInterval(restartProgressInterval);
                restartProgressInterval = null;
            }
        }

        // NEW: Enhanced restart with progress
        $(document).on('click', '.sfaic-restart-process', function (e) {
            var $button = $(this);
            
            // Start progress animation
            setTimeout(function() {
                if ($button.prop('disabled')) {
                    startRestartProgress($button);
                }
            }, 1000); // Start after 1 second
        });

        // Stop progress on completion
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.data && settings.data.indexOf('sfaic_restart_ai_process') !== -1) {
                stopRestartProgress();
            }
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
                
                /* NEW: Restart button styles */
                .sfaic-restart-process {
                    transition: all 0.2s ease !important;
                }
                
                .sfaic-restart-process:hover {
                    transform: translateY(-1px) !important;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
                }
                
                .sfaic-restart-process:disabled {
                    opacity: 0.7 !important;
                    cursor: not-allowed !important;
                    transform: none !important;
                    box-shadow: none !important;
                }
                
                /* NEW: Notification styles */
                .notice.sfaic-notification {
                    margin: 15px 0;
                    border-left-width: 4px;
                    padding: 12px;
                }
                
                .notice.sfaic-notification p {
                    margin: 0;
                    font-size: 14px;
                }
                
                /* NEW: Progress animation */
                @keyframes restart-pulse {
                    0% { opacity: 1; }
                    50% { opacity: 0.6; }
                    100% { opacity: 1; }
                }
                
                .sfaic-restart-process.processing {
                    animation: restart-pulse 1.5s infinite;
                }
                
                /* NEW: Row highlighting for restart actions */
                .wp-list-table tbody tr:hover .sfaic-restart-process {
                    background-color: #218838 !important;
                    border-color: #1e7e34 !important;
                }
                
                /* NEW: Success state for restart button */
                .sfaic-restart-process.success {
                    background-color: #155724 !important;
                    border-color: #0f4d2b !important;
                }
                
                .sfaic-restart-process.success .dashicons {
                    color: #d4edda;
                }
            </style>
        `);
    }
});

// NEW: Add keyboard shortcuts help
jQuery(document).ready(function ($) {
    // Add help text for keyboard shortcuts
    if ($('.wp-list-table').length) {
        $('.tablenav.top').append(
            '<div class="alignright" style="margin-top: 5px; font-size: 12px; color: #666;">' +
            '<strong>Tip:</strong> Hover over a row and press Ctrl+R (or Cmd+R) to quickly restart the process' +
            '</div>'
        );
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