// assets/js/response-logs.js

jQuery(document).ready(function($) {
    'use strict';

    // ===============================================
    // Smooth Horizontal Scrolling Handler
    // ===============================================
    
    function initTableScrolling() {
        const scrollWrapper = $('.sfaic-table-scroll-wrapper');
        
        if (!scrollWrapper.length) return;
        
        // Check if horizontal scrolling is needed
        function checkScroll() {
            scrollWrapper.each(function() {
                const wrapper = $(this);
                const hasScroll = this.scrollWidth > this.clientWidth;
                
                if (hasScroll) {
                    wrapper.addClass('has-scroll');
                } else {
                    wrapper.removeClass('has-scroll');
                }
            });
        }
        
        // Initial check
        checkScroll();
        
        // Re-check on window resize
        $(window).on('resize', debounce(checkScroll, 250));
        
        // Smooth scroll with keyboard navigation
        scrollWrapper.on('keydown', function(e) {
            const scrollAmount = 100;
            
            switch(e.key) {
                case 'ArrowLeft':
                    e.preventDefault();
                    $(this).animate({
                        scrollLeft: $(this).scrollLeft() - scrollAmount
                    }, 200);
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    $(this).animate({
                        scrollLeft: $(this).scrollLeft() + scrollAmount
                    }, 200);
                    break;
            }
        });
        
        // Add focus to enable keyboard navigation
        scrollWrapper.attr('tabindex', '0');
        
        // Horizontal scroll with shift + mouse wheel
        scrollWrapper.on('wheel', function(e) {
            if (e.shiftKey) {
                e.preventDefault();
                const delta = e.originalEvent.deltaY || e.originalEvent.detail || e.originalEvent.wheelDelta;
                $(this).scrollLeft($(this).scrollLeft() + delta);
            }
        });
        
        // Touch scroll optimization for mobile
        let isScrolling = false;
        let startX = 0;
        let scrollLeft = 0;
        
        scrollWrapper.on('touchstart', function(e) {
            isScrolling = true;
            startX = e.touches[0].pageX - this.offsetLeft;
            scrollLeft = this.scrollLeft;
        });
        
        scrollWrapper.on('touchmove', function(e) {
            if (!isScrolling) return;
            e.preventDefault();
            const x = e.touches[0].pageX - this.offsetLeft;
            const walk = (x - startX) * 2;
            this.scrollLeft = scrollLeft - walk;
        });
        
        scrollWrapper.on('touchend', function() {
            isScrolling = false;
        });
    }
    
    // ===============================================
    // View Toggle Handlers
    // ===============================================
    
    $('.sfaic-view-toggle').on('click', function(e) {
        e.preventDefault();
        
        const targetId = $(this).data('target');
        
        // Update active states
        $('.sfaic-view-toggle').removeClass('active');
        $(this).addClass('active');
        
        // Show/hide content
        $('.sfaic-response-view').hide();
        $('#' + targetId).fadeIn(200);
    });
    
    // ===============================================
    // Copy Response Functionality
    // ===============================================
    
    $('.sfaic-copy-response').on('click', function() {
        const button = $(this);
        const responseContent = $('#sfaic-raw-response .sfaic-raw-content').text() || 
                               $('#sfaic-formatted-response .sfaic-formatted-content').text();
        
        if (!responseContent) {
            showNotification('No content to copy', 'error');
            return;
        }
        
        // Create temporary textarea
        const textarea = $('<textarea>')
            .val(responseContent)
            .css({
                position: 'fixed',
                left: '-9999px',
                top: '0'
            })
            .appendTo('body');
        
        textarea[0].select();
        
        try {
            document.execCommand('copy');
            
            // Update button text
            const originalHtml = button.html();
            button.html('<span class="dashicons dashicons-yes"></span> Copied!');
            
            setTimeout(function() {
                button.html(originalHtml);
            }, 2000);
            
        } catch (err) {
            showNotification('Failed to copy content', 'error');
        }
        
        textarea.remove();
    });
    
    // ===============================================
    // Restart Process Handler
    // ===============================================
    
    $('.sfaic-restart-process').on('click', function() {
        const button = $(this);
        const logId = button.data('log-id');
        
        if (!confirm(sfaic_ajax.strings.confirm_restart)) {
            return;
        }
        
        // Disable button and show loading
        button.prop('disabled', true);
        const originalHtml = button.html();
        button.html('<span class="dashicons dashicons-update spin"></span> ' + sfaic_ajax.strings.restarting);
        
        $.ajax({
            url: sfaic_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sfaic_restart_ai_process',
                log_id: logId,
                nonce: sfaic_ajax.restart_nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(sfaic_ajax.strings.restart_success, 'success');
                    
                    // Refresh the page after 2 seconds
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    showNotification(response.data.message || sfaic_ajax.strings.restart_error, 'error');
                    button.prop('disabled', false).html(originalHtml);
                }
            },
            error: function() {
                showNotification(sfaic_ajax.strings.restart_error, 'error');
                button.prop('disabled', false).html(originalHtml);
            }
        });
    });
    
    // ===============================================
    // Download JSON Handlers
    // ===============================================
    
    $('.sfaic-download-json').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const type = button.data('type');
        const logId = button.data('log-id');
        
        // Create download URL
        const downloadUrl = sfaic_ajax.ajax_url + 
            '?action=sfaic_download_' + type + '_json' +
            '&log_id=' + logId +
            '&nonce=' + sfaic_ajax.download_nonce;
        
        // Trigger download
        window.location.href = downloadUrl;
    });
    
    // ===============================================
    // Filter Form Enhancement
    // ===============================================
    
    $('#sfaic-filters-form').on('submit', function() {
        // Remove empty fields to clean up URL
        $(this).find('input, select').each(function() {
            if (!$(this).val()) {
                $(this).prop('disabled', true);
            }
        });
    });
    
    // Re-enable fields after submit
    $('#sfaic-filters-form').on('submit', function() {
        setTimeout(function() {
            $('#sfaic-filters-form').find('input, select').prop('disabled', false);
        }, 100);
    });
    
    // ===============================================
    // Table Row Click Handler (View Details)
    // ===============================================
    
    $('.sfaic-logs-table tbody tr').on('click', function(e) {
        // Don't trigger if clicking on buttons or links
        if ($(e.target).closest('a, button, .row-actions').length) {
            return;
        }
        
        const viewButton = $(this).find('.button-view');
        if (viewButton.length) {
            window.location.href = viewButton.attr('href');
        }
    }).css('cursor', 'pointer');
    
    // ===============================================
    // Auto-refresh for Pending/Processing Status
    // ===============================================
    
    let autoRefreshTimer = null;
    const hasActiveJobs = $('.sfaic-logs-table .sfaic-badge-processing, .sfaic-logs-table .sfaic-badge-pending').length > 0;
    
    if (hasActiveJobs) {
        autoRefreshTimer = setInterval(function() {
            // Check if any jobs are still processing
            $.ajax({
                url: sfaic_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'sfaic_check_job_status',
                    nonce: sfaic_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data.has_active_jobs) {
                        // Reload page if status changed
                        if (response.data.status_changed) {
                            window.location.reload();
                        }
                    } else {
                        // Stop auto-refresh if no active jobs
                        clearInterval(autoRefreshTimer);
                    }
                }
            });
        }, 30000); // Check every 30 seconds
    }
    
    // ===============================================
    // Tooltip Enhancement
    // ===============================================
    
    $('[title]').each(function() {
        const $this = $(this);
        const title = $this.attr('title');
        
        if (!title) return;
        
        $this.on('mouseenter', function() {
            const tooltip = $('<div class="sfaic-tooltip">')
                .text(title)
                .appendTo('body');
            
            const pos = $this.offset();
            tooltip.css({
                top: pos.top - tooltip.outerHeight() - 5,
                left: pos.left + ($this.outerWidth() / 2) - (tooltip.outerWidth() / 2)
            }).fadeIn(200);
            
            $this.data('tooltip', tooltip);
        }).on('mouseleave', function() {
            const tooltip = $this.data('tooltip');
            if (tooltip) {
                tooltip.fadeOut(200, function() {
                    $(this).remove();
                });
            }
        });
        
        // Remove default title to prevent browser tooltip
        $this.attr('data-original-title', title).removeAttr('title');
    });
    
    // ===============================================
    // Responsive Table Handler
    // ===============================================
    
    function handleResponsiveTable() {
        const windowWidth = $(window).width();
        const table = $('.sfaic-logs-table');
        
        if (windowWidth < 782) {
            // Add mobile class for additional styling
            table.addClass('mobile-view');
            
            // Make table scrollable with touch
            $('.sfaic-table-scroll-wrapper').css({
                'overflow-x': 'auto',
                '-webkit-overflow-scrolling': 'touch'
            });
        } else {
            table.removeClass('mobile-view');
        }
    }
    
    handleResponsiveTable();
    $(window).on('resize', debounce(handleResponsiveTable, 250));
    
    // ===============================================
    // Search within logs (Ctrl+F enhancement)
    // ===============================================
    
    $(document).on('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            const searchBox = $('<input>')
                .attr({
                    type: 'text',
                    placeholder: 'Search in logs...',
                    class: 'sfaic-quick-search'
                })
                .css({
                    position: 'fixed',
                    top: '100px',
                    right: '20px',
                    zIndex: 9999,
                    padding: '10px',
                    width: '250px',
                    border: '2px solid #0073aa',
                    borderRadius: '4px'
                });
            
            if (!$('.sfaic-quick-search').length) {
                searchBox.appendTo('body').focus();
                
                searchBox.on('input', debounce(function() {
                    const searchTerm = $(this).val().toLowerCase();
                    
                    $('.sfaic-logs-table tbody tr').each(function() {
                        const row = $(this);
                        const text = row.text().toLowerCase();
                        
                        if (searchTerm && !text.includes(searchTerm)) {
                            row.addClass('hidden-by-search');
                        } else {
                            row.removeClass('hidden-by-search');
                        }
                    });
                }, 300));
                
                // Remove search box on Escape
                searchBox.on('keydown', function(e) {
                    if (e.key === 'Escape') {
                        $(this).remove();
                        $('.hidden-by-search').removeClass('hidden-by-search');
                    }
                });
                
                // Remove search box on click outside
                $(document).on('click', function(e) {
                    if (!$(e.target).hasClass('sfaic-quick-search')) {
                        $('.sfaic-quick-search').remove();
                        $('.hidden-by-search').removeClass('hidden-by-search');
                    }
                });
            }
        }
    });
    
    // ===============================================
    // Helper Functions
    // ===============================================
    
    function showNotification(message, type) {
        const notification = $('<div>')
            .addClass('sfaic-notification')
            .addClass('sfaic-notification-' + type)
            .text(message)
            .appendTo('body');
        
        notification.css({
            position: 'fixed',
            top: '60px',
            right: '20px',
            padding: '15px 20px',
            background: type === 'success' ? '#00a32a' : '#d63638',
            color: '#fff',
            borderRadius: '4px',
            boxShadow: '0 2px 5px rgba(0,0,0,0.2)',
            zIndex: 99999
        }).fadeIn(200);
        
        setTimeout(function() {
            notification.fadeOut(200, function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    // ===============================================
    // Initialize Everything
    // ===============================================
    
    initTableScrolling();
    
    // Add CSS for hidden rows
    $('<style>')
        .text('.hidden-by-search { display: none !important; }')
        .appendTo('head');
    
    // Add loading indicator for AJAX operations
    $(document).ajaxStart(function() {
        if (!$('.sfaic-ajax-loading').length) {
            $('<div class="sfaic-ajax-loading">Processing...</div>').appendTo('body');
        }
    }).ajaxStop(function() {
        $('.sfaic-ajax-loading').fadeOut(200, function() {
            $(this).remove();
        });
    });
    
    // Add CSS for AJAX loading
    $('<style>')
        .text(`
            .sfaic-ajax-loading {
                position: fixed;
                top: 32px;
                right: 20px;
                background: #0073aa;
                color: #fff;
                padding: 10px 20px;
                border-radius: 4px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                z-index: 99999;
                font-size: 13px;
            }
            .dashicons.spin {
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .sfaic-tooltip {
                position: absolute;
                background: #333;
                color: #fff;
                padding: 5px 10px;
                border-radius: 3px;
                font-size: 12px;
                white-space: nowrap;
                z-index: 10000;
                pointer-events: none;
            }
            .sfaic-tooltip::after {
                content: '';
                position: absolute;
                top: 100%;
                left: 50%;
                margin-left: -5px;
                border: 5px solid transparent;
                border-top-color: #333;
            }
        `)
        .appendTo('head');
});