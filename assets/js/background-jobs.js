/**
 * FIXED Background Jobs Admin Interface JavaScript - Resolves Refresh Issues
 * 
 * Handles interactions for the background jobs monitoring page with improved reliability
 */

jQuery(document).ready(function($) {
    
    // Auto-refresh functionality
    let autoRefreshInterval;
    let isAutoRefreshEnabled = false;
    let refreshInProgress = false;
    
    // Initialize the interface
    init();
    
    function init() {
        // Bind event handlers
        bindEventHandlers();
        
        // Start auto-refresh if there are pending or processing jobs
        checkAutoRefresh();
        
        // Add refresh countdown
        updateRefreshCountdown();
        
        // Add debugging tools
        addDebuggingTools();
        
        // FIXED: Add periodic status check
        startPeriodicStatusCheck();
    }
    
    /**
     * FIXED: Add periodic status checking to detect stuck states
     */
    function startPeriodicStatusCheck() {
        // Check status every 30 seconds
        setInterval(function() {
            if (!refreshInProgress) {
                checkJobStatusQuietly();
            }
        }, 30000);
    }
    
    /**
     * FIXED: Quiet status check without full refresh
     */
    function checkJobStatusQuietly() {
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_get_job_status',
                nonce: sfaic_jobs_ajax.nonce
            },
            timeout: 10000,
            success: function(response) {
                if (response.success) {
                    updateStatisticsOnly(response.data.stats);
                    
                    // Check if we need to trigger auto-refresh
                    const pendingJobs = parseInt(response.data.stats.pending_jobs) || 0;
                    const processingJobs = parseInt(response.data.stats.processing_jobs) || 0;
                    
                    // If jobs appear stuck, show warning
                    if (pendingJobs > 0 || processingJobs > 0) {
                        if (!isAutoRefreshEnabled) {
                            startAutoRefresh();
                        }
                        
                        // Check for stuck jobs (pending for more than 10 minutes)
                        checkForStuckJobs(response.data.jobs);
                    }
                }
            },
            error: function() {
                console.log('SFAIC: Quiet status check failed');
            }
        });
    }
    
    /**
     * FIXED: Check for jobs that appear to be stuck
     */
    function checkForStuckJobs(jobs) {
        let stuckJobsFound = false;
        const now = new Date().getTime();
        
        if (jobs && Array.isArray(jobs)) {
            jobs.forEach(function(job) {
                if (job.status === 'pending') {
                    const createdTime = new Date(job.created_at).getTime();
                    const ageMinutes = (now - createdTime) / (1000 * 60);
                    
                    if (ageMinutes > 10) {
                        stuckJobsFound = true;
                    }
                }
            });
        }
        
        if (stuckJobsFound) {
            showStuckJobsWarning();
        }
    }
    
    /**
     * FIXED: Show warning for stuck jobs
     */
    function showStuckJobsWarning() {
        // Only show warning once per session
        if (!$('.sfaic-stuck-jobs-warning').length) {
            const warning = $(`
                <div class="notice notice-warning sfaic-stuck-jobs-warning" style="margin: 15px 0;">
                    <p>
                        <strong>⚠️ Stuck Jobs Detected:</strong> 
                        Some jobs have been pending for a long time. 
                        <button type="button" class="button button-small" id="auto-fix-stuck-jobs" style="margin-left: 10px;">
                            Auto-Fix Now
                        </button>
                        <button type="button" class="notice-dismiss" style="float: right;">
                            <span class="screen-reader-text">Dismiss</span>
                        </button>
                    </p>
                </div>
            `);
            
            $('.sfaic-jobs-overview').after(warning);
            
            // Bind auto-fix handler
            $('#auto-fix-stuck-jobs').on('click', function() {
                cleanupStuckJobs();
                warning.remove();
            });
            
            // Bind dismiss handler
            warning.find('.notice-dismiss').on('click', function() {
                warning.remove();
            });
        }
    }
    
    function addDebuggingTools() {
        // Add debugging section to the page
        const debugSection = `
            <div class="sfaic-debug-section" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0; color: #856404;">🔍 Debug Tools (for stuck jobs)</h3>
                <div class="sfaic-debug-actions" style="margin-bottom: 15px;">
                    <button type="button" id="sfaic-debug-cron" class="button">
                        <span class="dashicons dashicons-search"></span>
                        Debug Cron Status
                    </button>
                    <button type="button" id="sfaic-force-process" class="button button-primary">
                        <span class="dashicons dashicons-controls-play"></span>
                        Force Process Jobs
                    </button>
                    <button type="button" id="sfaic-cleanup-stuck" class="button button-secondary">
                        <span class="dashicons dashicons-undo"></span>
                        Reset Stuck Jobs
                    </button>
                </div>
                <div id="sfaic-debug-results" style="background: #f8f9fa; padding: 10px; border-radius: 3px; display: none;">
                    <strong>Debug Results:</strong>
                    <pre id="sfaic-debug-output" style="margin: 10px 0; font-size: 12px; max-height: 200px; overflow-y: auto;"></pre>
                </div>
            </div>
        `;
        
        // Insert after the job actions
        $('.sfaic-job-actions').after(debugSection);
        
        // Bind debug event handlers
        bindDebugHandlers();
    }
    
    function bindDebugHandlers() {
        // Debug cron status
        $('#sfaic-debug-cron').on('click', function(e) {
            e.preventDefault();
            debugCronStatus();
        });
        
        // Force process jobs
        $('#sfaic-force-process').on('click', function(e) {
            e.preventDefault();
            if (confirm('This will force immediate processing of pending jobs. Continue?')) {
                forceProcessJobs();
            }
        });
        
        // Cleanup stuck jobs
        $('#sfaic-cleanup-stuck').on('click', function(e) {
            e.preventDefault();
            if (confirm('This will reset jobs stuck in processing status back to pending. Continue?')) {
                cleanupStuckJobs();
            }
        });
    }
    
    function debugCronStatus() {
        const button = $('#sfaic-debug-cron');
        const originalText = button.text();
        
        button.prop('disabled', true);
        button.find('.dashicons').addClass('spin');
        button.text('Debugging...');
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_debug_cron',
                nonce: sfaic_jobs_ajax.nonce
            },
            timeout: 15000,
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    let output = 'CRON DEBUG INFORMATION:\n';
                    output += '========================\n';
                    output += 'WP Cron Disabled: ' + (data.wp_cron_disabled ? 'YES (This is likely the problem!)' : 'NO') + '\n';
                    output += 'Next Scheduled: ' + data.next_scheduled + '\n';
                    output += 'Pending Jobs: ' + data.pending_jobs + '\n';
                    output += 'Processing Jobs: ' + data.processing_jobs + '\n';
                    
                    // FIXED: Add stuck jobs info
                    if (data.stuck_jobs !== undefined) {
                        output += 'Stuck Jobs: ' + data.stuck_jobs + '\n';
                    }
                    
                    if (data.wp_cron_disabled) {
                        output += '\n⚠️ ISSUE DETECTED: WordPress cron is disabled!\n';
                        output += 'To fix this, you can:\n';
                        output += '1. Remove DISABLE_WP_CRON from wp-config.php\n';
                        output += '2. Set up a real cron job to call wp-cron.php\n';
                        output += '3. Use the "Force Process Jobs" button as a temporary fix\n';
                    }
                    
                    if (data.pending_jobs > 0 && data.next_scheduled === 'None') {
                        output += '\n⚠️ ISSUE DETECTED: Pending jobs but no cron scheduled!\n';
                        output += 'Try clicking "Force Process Jobs" to trigger processing.\n';
                    }
                    
                    // FIXED: Check for stuck jobs
                    if (data.stuck_jobs > 0) {
                        output += '\n⚠️ ISSUE DETECTED: ' + data.stuck_jobs + ' jobs stuck in processing!\n';
                        output += 'Click "Reset Stuck Jobs" to fix this.\n';
                    }
                    
                    $('#sfaic-debug-output').text(output);
                    $('#sfaic-debug-results').show();
                    
                    showNotice('Debug information retrieved successfully.', 'success');
                } else {
                    showNotice('Failed to get debug information.', 'error');
                }
            },
            error: function() {
                showNotice('Error occurred while debugging.', 'error');
            },
            complete: function() {
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
                button.text(originalText);
            }
        });
    }
    
    function forceProcessJobs() {
        const button = $('#sfaic-force-process');
        const originalText = button.text();
        
        button.prop('disabled', true);
        button.find('.dashicons').addClass('spin');
        button.text('Processing...');
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_force_process_jobs',
                nonce: sfaic_jobs_ajax.nonce
            },
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    showNotice('Jobs processed successfully! Refreshing list...', 'success');
                    // Refresh the jobs list after a short delay
                    setTimeout(function() {
                        refreshJobsList();
                    }, 2000);
                } else {
                    showNotice('Failed to process jobs.', 'error');
                }
            },
            error: function() {
                showNotice('Error occurred while processing jobs.', 'error');
            },
            complete: function() {
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
                button.text(originalText);
            }
        });
    }
    
    function cleanupStuckJobs() {
        const button = $('#sfaic-cleanup-stuck');
        const originalText = button.text();
        
        button.prop('disabled', true);
        button.find('.dashicons').addClass('spin');
        button.text('Cleaning...');
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_cleanup_stuck_jobs',
                nonce: sfaic_jobs_ajax.nonce
            },
            timeout: 15000,
            success: function(response) {
                if (response.success) {
                    showNotice(response.data + '. Refreshing list...', 'success');
                    // Refresh the jobs list after a short delay
                    setTimeout(function() {
                        refreshJobsList();
                    }, 2000);
                } else {
                    showNotice('Failed to cleanup stuck jobs.', 'error');
                }
            },
            error: function() {
                showNotice('Error occurred while cleaning up stuck jobs.', 'error');
            },
            complete: function() {
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
                button.text(originalText);
            }
        });
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
            
            // F key for force process (debug)
            if (e.key === 'f' || e.key === 'F') {
                if (!$(e.target).is('input, textarea')) {
                    e.preventDefault();
                    forceProcessJobs();
                }
            }
        });
    }
    
    /**
     * FIXED: Enhanced refresh with better error handling
     */
    function refreshJobsList() {
        if (refreshInProgress) {
            console.log('SFAIC: Refresh already in progress, skipping');
            return;
        }
        
        refreshInProgress = true;
        
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
            timeout: 20000, // FIXED: Increased timeout
            success: function(response) {
                if (response.success) {
                    updateJobsTable(response.data.jobs);
                    updateStatistics(response.data.stats);
                    showNotice('Jobs list refreshed successfully.', 'success');
                } else {
                    showNotice('Failed to refresh jobs list.', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('SFAIC: Refresh error:', status, error);
                if (status === 'timeout') {
                    showNotice('Refresh timed out. The server may be busy processing jobs.', 'warning');
                } else {
                    showNotice('Error occurred while refreshing jobs list.', 'error');
                }
            },
            complete: function() {
                // Restore button state
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
                button.text(originalText);
                
                refreshInProgress = false;
                
                // Check if auto-refresh should continue
                checkAutoRefresh();
            }
        });
    }
    
    /**
     * FIXED: Update only statistics without full refresh
     */
    function updateStatisticsOnly(stats) {
        $('.sfaic-stat-card.total .stat-number').text(stats.total_jobs || 0);
        $('.sfaic-stat-card.pending .stat-number').text(stats.pending_jobs || 0);
        $('.sfaic-stat-card.processing .stat-number').text(stats.processing_jobs || 0);
        $('.sfaic-stat-card.completed .stat-number').text(stats.completed_jobs || 0);
        $('.sfaic-stat-card.failed .stat-number').text(stats.failed_jobs || 0);
        $('.sfaic-stat-card.retry .stat-number').text(stats.retry_jobs || 0);
        
        // Update highlighting
        highlightProblemsInStats(stats);
    }
    
    function updateJobsTable(jobs) {
        const tbody = $('#sfaic-jobs-tbody');
        tbody.empty();
        
        if (jobs.length === 0) {
            tbody.append('<tr><td colspan="10">No jobs found.</td></tr>');
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
        let statusBadge = '<span class="status-badge ' + statusClass + '">' + 
                          job.status.charAt(0).toUpperCase() + job.status.slice(1) + '</span>';
        
        // Add warning for old pending jobs
        if (job.status === 'pending') {
            const createdTime = new Date(job.created_at).getTime();
            const now = new Date().getTime();
            const ageMinutes = (now - createdTime) / (1000 * 60);
            
            if (ageMinutes > 5) {
                statusBadge += ' <span style="color: #dc3545; font-size: 11px;">⚠️ ' + Math.round(ageMinutes) + 'min old</span>';
            }
        }
        
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
        
        const userName = job.user_name || '-';
        const userEmail = job.user_email ? 
            '<a href="mailto:' + job.user_email + '">' + job.user_email + '</a>' : '-';
        
        return $('<tr data-job-id="' + job.id + '">' +
                '<td>' + job.id + '</td>' +
                '<td>' + job.job_type + '</td>' +
                '<td>' + userName + '</td>' +
                '<td>' + userEmail + '</td>' +
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
                '<td colspan="10">' +
                '<div class="error-message">' +
                '<strong>Error:</strong> ' + job.error_message +
                '</div>' +
                '</td>' +
                '</tr>');
    }
    
    /**
     * FIXED: Better highlighting of problems in statistics
     */
    function updateStatistics(stats) {
        $('.sfaic-stat-card.total .stat-number').text(stats.total_jobs || 0);
        $('.sfaic-stat-card.pending .stat-number').text(stats.pending_jobs || 0);
        $('.sfaic-stat-card.processing .stat-number').text(stats.processing_jobs || 0);
        $('.sfaic-stat-card.completed .stat-number').text(stats.completed_jobs || 0);
        $('.sfaic-stat-card.failed .stat-number').text(stats.failed_jobs || 0);
        $('.sfaic-stat-card.retry .stat-number').text(stats.retry_jobs || 0);
        
        highlightProblemsInStats(stats);
    }
    
    /**
     * FIXED: Highlight problems in statistics
     */
    function highlightProblemsInStats(stats) {
        const pendingJobs = stats.pending_jobs || 0;
        const processingJobs = stats.processing_jobs || 0;
        const failedJobs = stats.failed_jobs || 0;
        
        // Reset animations
        $('.sfaic-stat-card').css('animation', 'none');
        
        if (pendingJobs > 5) {
            $('.sfaic-stat-card.pending').css('border-left-color', '#dc3545').css('animation', 'pulse 2s infinite');
        } else {
            $('.sfaic-stat-card.pending').css('border-left-color', '#ffc107');
        }
        
        if (processingJobs > 3) {
            $('.sfaic-stat-card.processing').css('border-left-color', '#dc3545').css('animation', 'pulse 2s infinite');
        } else {
            $('.sfaic-stat-card.processing').css('border-left-color', '#17a2b8');
        }
        
        if (failedJobs > 0) {
            $('.sfaic-stat-card.failed').css('animation', 'pulse 2s infinite');
        }
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
    
    /**
     * FIXED: Better auto-refresh logic
     */
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
        
        // FIXED: Longer interval to reduce server load
        autoRefreshInterval = setInterval(function() {
            if (!refreshInProgress) {
                refreshJobsList();
            }
        }, 15000); // Refresh every 15 seconds instead of 10
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
                             'Auto-refresh is enabled (updates every 15 seconds). ' +
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
    
    /**
     * FIXED: Enhanced notice system with auto-dismiss
     */
    function showNotice(message, type) {
        // Remove any existing notices of the same type
        $('.sfaic-temp-notice.notice-' + type).remove();
        
        const noticeClass = 'notice-' + type;
        const notice = $('<div class="notice ' + noticeClass + ' is-dismissible sfaic-temp-notice">' +
                       '<p>' + message + '</p>' +
                       '<button type="button" class="notice-dismiss">' +
                       '<span class="screen-reader-text">Dismiss this notice.</span>' +
                       '</button>' +
                       '</div>');
        
        // Insert after the h1
        $('.wrap h1').after(notice);
        
        // Auto-dismiss after appropriate time based on type
        const dismissTime = type === 'error' ? 10000 : 5000;
        setTimeout(function() {
            notice.fadeOut(500, function() {
                $(this).remove();
            });
        }, dismissTime);
        
        // Handle dismiss button
        notice.find('.notice-dismiss').on('click', function() {
            notice.remove();
        });
    }
    
    // Add CSS animations for loading states and problem indicators
    $('<style>')
        .prop('type', 'text/css')
        .html('\
            @keyframes spin {\
                from { transform: rotate(0deg); }\
                to { transform: rotate(360deg); }\
            }\
            @keyframes pulse {\
                0% { opacity: 1; }\
                50% { opacity: 0.7; }\
                100% { opacity: 1; }\
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
            .sfaic-debug-section {\
                border-radius: 5px;\
            }\
            .sfaic-debug-actions button {\
                margin-right: 10px;\
                margin-bottom: 5px;\
            }\
            .sfaic-stuck-jobs-warning {\
                border-left: 4px solid #dc3545;\
            }\
        ')
        .appendTo('head');
});