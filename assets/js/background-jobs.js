/**
 * Background Jobs Admin Interface JavaScript
 * 
 * Handles interactions for the background jobs monitoring page
 */

jQuery(document).ready(function($) {
    
    // Auto-refresh functionality
    let autoRefreshInterval;
    let isAutoRefreshEnabled = false;
    
    // Initialize the interface
    init();
    
    function init() {
        // Bind event handlers
        bindEventHandlers();
        
        // Start auto-refresh if there are pending or processing jobs
        checkAutoRefresh();
        
        // Add refresh countdown
        updateRefreshCountdown();
    }
    
    function bindEventHandlers() {
        // Refresh button
        $('#sfaic-refresh-jobs').on('click', function(e) {
            e.preventDefault();
            refreshJobsList();
        });
        
        // Cleanup button
        $('#sfaic-cleanup-jobs').on('click', function(e) {
            e.preventDefault();
            if (confirm(sfaic_jobs_ajax.strings.confirm_cleanup)) {
                cleanupOldJobs();
            }
        });
        
        // Retry job buttons
        $(document).on('click', '.sfaic-retry-job', function(e) {
            e.preventDefault();
            if (confirm(sfaic_jobs_ajax.strings.confirm_retry)) {
                const jobId = $(this).data('job-id');
                retryJob(jobId);
            }
        });
        
        // Cancel job buttons
        $(document).on('click', '.sfaic-cancel-job', function(e) {
            e.preventDefault();
            if (confirm(sfaic_jobs_ajax.strings.confirm_cancel)) {
                const jobId = $(this).data('job-id');
                cancelJob(jobId);
            }
        });
        
        // Toggle error message visibility
        $(document).on('click', '.sfaic-jobs-table tr', function() {
            const jobId = $(this).data('job-id');
            if (jobId) {
                const errorRow = $('#error-' + jobId);
                if (errorRow.length) {
                    errorRow.toggle();
                }
            }
        });
        
        // Auto-refresh toggle
        $(document).on('click', '#sfaic-toggle-auto-refresh', function(e) {
            e.preventDefault();
            toggleAutoRefresh();
        });
        
        // Keyboard shortcuts
        $(document).on('keydown', function(e) {
            // R key for refresh
            if (e.key === 'r' || e.key === 'R') {
                if (!$(e.target).is('input, textarea')) {
                    e.preventDefault();
                    refreshJobsList();
                }
            }
        });
    }
    
    function refreshJobsList() {
        const button = $('#sfaic-refresh-jobs');
        const originalText = button.text();
        
        // Show loading state
        button.prop('disabled', true);
        button.find('.dashicons').addClass('spin');
        button.text('Refreshing...');
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_get_job_status',
                nonce: sfaic_jobs_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateJobsTable(response.data.jobs);
                    updateStatistics(response.data.stats);
                    showNotice('Jobs list refreshed successfully.', 'success');
                } else {
                    showNotice('Failed to refresh jobs list.', 'error');
                }
            },
            error: function() {
                showNotice('Error occurred while refreshing jobs list.', 'error');
            },
            complete: function() {
                // Restore button state
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
                button.text(originalText);
                
                // Check if auto-refresh should continue
                checkAutoRefresh();
            }
        });
    }
    
    function updateJobsTable(jobs) {
        const tbody = $('#sfaic-jobs-tbody');
        tbody.empty();
        
        if (jobs.length === 0) {
            tbody.append('<tr><td colspan="8">No jobs found.</td></tr>');
            return;
        }
        
        jobs.forEach(function(job) {
            const row = createJobRow(job);
            tbody.append(row);
            
            // Add error row if there's an error message
            if (job.error_message) {
                const errorRow = createErrorRow(job);
                tbody.append(errorRow);
            }
        });
    }
    
    function createJobRow(job) {
        const statusClass = 'status-' + job.status;
        const statusBadge = '<span class="status-badge ' + statusClass + '">' + 
                          job.status.charAt(0).toUpperCase() + job.status.slice(1) + '</span>';
        
        const createdDate = new Date(job.created_at).toLocaleString();
        const scheduledDate = new Date(job.scheduled_at).toLocaleString();
        
        let actions = '';
        if (job.status === 'failed' || job.status === 'retry') {
            actions += '<button type="button" class="button button-small sfaic-retry-job" data-job-id="' + job.id + '">Retry</button> ';
        }
        if (job.status === 'pending') {
            actions += '<button type="button" class="button button-small sfaic-cancel-job" data-job-id="' + job.id + '">Cancel</button>';
        }
        
        const promptTitle = job.prompt_title ? 
            '<a href="' + '/wp-admin/post.php?post=' + job.prompt_id + '&action=edit">' + job.prompt_title + '</a>' :
            job.prompt_id;
        
        return $('<tr data-job-id="' + job.id + '">' +
                '<td>' + job.id + '</td>' +
                '<td>' + job.job_type + '</td>' +
                '<td>' + promptTitle + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + job.retry_count + '/' + job.max_retries + '</td>' +
                '<td>' + createdDate + '</td>' +
                '<td>' + scheduledDate + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>');
    }
    
    function createErrorRow(job) {
        return $('<tr class="job-error-row" style="display: none;" id="error-' + job.id + '">' +
                '<td colspan="8">' +
                '<div class="error-message">' +
                '<strong>Error:</strong> ' + job.error_message +
                '</div>' +
                '</td>' +
                '</tr>');
    }
    
    function updateStatistics(stats) {
        $('.sfaic-stat-card.total .stat-number').text(stats.total_jobs || 0);
        $('.sfaic-stat-card.pending .stat-number').text(stats.pending_jobs || 0);
        $('.sfaic-stat-card.processing .stat-number').text(stats.processing_jobs || 0);
        $('.sfaic-stat-card.completed .stat-number').text(stats.completed_jobs || 0);
        $('.sfaic-stat-card.failed .stat-number').text(stats.failed_jobs || 0);
        $('.sfaic-stat-card.retry .stat-number').text(stats.retry_jobs || 0);
    }
    
    function retryJob(jobId) {
        const button = $('.sfaic-retry-job[data-job-id="' + jobId + '"]');
        button.prop('disabled', true).text('Retrying...');
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_retry_job',
                nonce: sfaic_jobs_ajax.nonce,
                job_id: jobId
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Job scheduled for retry.', 'success');
                    refreshJobsList();
                } else {
                    showNotice(response.data || 'Failed to retry job.', 'error');
                }
            },
            error: function() {
                showNotice('Error occurred while retrying job.', 'error');
            },
            complete: function() {
                button.prop('disabled', false).text('Retry');
            }
        });
    }
    
    function cancelJob(jobId) {
        const button = $('.sfaic-cancel-job[data-job-id="' + jobId + '"]');
        button.prop('disabled', true).text('Cancelling...');
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_cancel_job',
                nonce: sfaic_jobs_ajax.nonce,
                job_id: jobId
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Job cancelled.', 'success');
                    refreshJobsList();
                } else {
                    showNotice(response.data || 'Failed to cancel job.', 'error');
                }
            },
            error: function() {
                showNotice('Error occurred while cancelling job.', 'error');
            },
            complete: function() {
                button.prop('disabled', false).text('Cancel');
            }
        });
    }
    
    function cleanupOldJobs() {
        const button = $('#sfaic-cleanup-jobs');
        const originalText = button.text();
        
        button.prop('disabled', true);
        button.find('.dashicons').addClass('spin');
        button.text('Cleaning up...');
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_cleanup_jobs',
                nonce: sfaic_jobs_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Old jobs cleaned up successfully.', 'success');
                    refreshJobsList();
                } else {
                    showNotice(response.data || 'Failed to cleanup old jobs.', 'error');
                }
            },
            error: function() {
                showNotice('Error occurred while cleaning up jobs.', 'error');
            },
            complete: function() {
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
                button.text(originalText);
            }
        });
    }
    
    function checkAutoRefresh() {
        const pendingJobs = parseInt($('.sfaic-stat-card.pending .stat-number').text()) || 0;
        const processingJobs = parseInt($('.sfaic-stat-card.processing .stat-number').text()) || 0;
        
        if ((pendingJobs > 0 || processingJobs > 0) && !isAutoRefreshEnabled) {
            startAutoRefresh();
        } else if (pendingJobs === 0 && processingJobs === 0 && isAutoRefreshEnabled) {
            stopAutoRefresh();
        }
    }
    
    function startAutoRefresh() {
        if (isAutoRefreshEnabled) return;
        
        isAutoRefreshEnabled = true;
        addAutoRefreshIndicator();
        
        autoRefreshInterval = setInterval(function() {
            refreshJobsList();
        }, 10000); // Refresh every 10 seconds
    }
    
    function stopAutoRefresh() {
        if (!isAutoRefreshEnabled) return;
        
        isAutoRefreshEnabled = false;
        removeAutoRefreshIndicator();
        
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
    
    function toggleAutoRefresh() {
        if (isAutoRefreshEnabled) {
            stopAutoRefresh();
        } else {
            startAutoRefresh();
        }
    }
    
    function addAutoRefreshIndicator() {
        if ($('#sfaic-auto-refresh-indicator').length === 0) {
            const indicator = $('<div id="sfaic-auto-refresh-indicator" class="notice notice-info inline">' +
                             '<p><span class="dashicons dashicons-update spin"></span> ' +
                             'Auto-refresh is enabled (updates every 10 seconds). ' +
                             '<a href="#" id="sfaic-toggle-auto-refresh">Disable</a></p>' +
                             '</div>');
            $('.sfaic-job-actions').after(indicator);
        }
    }
    
    function removeAutoRefreshIndicator() {
        $('#sfaic-auto-refresh-indicator').remove();
    }
    
    function updateRefreshCountdown() {
        // This could be enhanced to show a countdown timer
        // For now, it's just a placeholder for future enhancement
    }
    
    function showNotice(message, type) {
        const noticeClass = 'notice-' + type;
        const notice = $('<div class="notice ' + noticeClass + ' is-dismissible sfaic-temp-notice">' +
                       '<p>' + message + '</p>' +
                       '<button type="button" class="notice-dismiss">' +
                       '<span class="screen-reader-text">Dismiss this notice.</span>' +
                       '</button>' +
                       '</div>');
        
        // Insert after the h1
        $('.wrap h1').after(notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            notice.fadeOut(500, function() {
                $(this).remove();
            });
        }, 5000);
        
        // Handle dismiss button
        notice.find('.notice-dismiss').on('click', function() {
            notice.remove();
        });
    }
    
    // Utility function to format dates
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString();
    }
    
    // Utility function to get relative time
    function getRelativeTime(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / (1000 * 60));
        
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return diffMins + ' minutes ago';
        if (diffMins < 1440) return Math.floor(diffMins / 60) + ' hours ago';
        return Math.floor(diffMins / 1440) + ' days ago';
    }
    
    // Add CSS animations for loading states
    $('<style>')
        .prop('type', 'text/css')
        .html('\
            @keyframes spin {\
                from { transform: rotate(0deg); }\
                to { transform: rotate(360deg); }\
            }\
            .spin {\
                animation: spin 1s linear infinite;\
            }\
            .sfaic-temp-notice {\
                margin: 15px 0;\
            }\
            .job-error-row {\
                background-color: #ffeaea !important;\
            }\
            .error-message {\
                padding: 10px;\
                background-color: #f8d7da;\
                border: 1px solid #f5c6cb;\
                border-radius: 3px;\
                color: #721c24;\
                font-size: 13px;\
            }\
        ')
        .appendTo('head');
});