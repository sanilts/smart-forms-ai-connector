/**
 * WordPress-Style Background Jobs Admin Interface JavaScript
 */

jQuery(document).ready(function($) {
    
    // Auto-refresh functionality
    let autoRefreshInterval;
    let isAutoRefreshEnabled = false;
    let refreshInProgress = false;
    let quietCheckInProgress = false;
    let pageVisible = true;
    let lastRefreshTime = 0;
    
    // Initialize the interface
    init();
    
    function init() {
        console.log('SFAIC: Initializing WordPress-style background jobs interface');
        
        // Bind event handlers
        bindEventHandlers();
        
        // Start auto-refresh if there are pending or processing jobs
        checkAutoRefresh();
        
        // Add periodic status check with visibility handling
        startPeriodicStatusCheck();
        
        // Add page visibility handling to prevent unnecessary refreshes
        handlePageVisibility();
        
        // Add keyboard shortcuts info
        addKeyboardShortcutsInfo();
    }
    
    /**
     * Handle page visibility to prevent refreshes when page is not visible
     */
    function handlePageVisibility() {
        if (typeof document.hidden !== "undefined") {
            $(document).on('visibilitychange', function() {
                pageVisible = !document.hidden;
                if (pageVisible) {
                    // Page became visible, do a quick refresh if it's been a while
                    const now = Date.now();
                    if (now - lastRefreshTime > 60000) { // 1 minute
                        setTimeout(function() {
                            if (!refreshInProgress) {
                                refreshJobsList();
                            }
                        }, 1000);
                    }
                }
            });
        }
    }
    
    /**
     * Add periodic status checking with better throttling
     */
    function startPeriodicStatusCheck() {
        // Check status every 30 seconds, but only if page is visible
        setInterval(function() {
            if (!refreshInProgress && !quietCheckInProgress && pageVisible) {
                const now = Date.now();
                if (now - lastRefreshTime > 25000) { // At least 25 seconds between checks
                    checkJobStatusQuietly();
                }
            }
        }, 30000);
    }
    
    /**
     * Improved quiet status check with proper throttling
     */
    function checkJobStatusQuietly() {
        if (quietCheckInProgress || refreshInProgress) {
            return;
        }
        
        quietCheckInProgress = true;
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_get_job_status',
                nonce: sfaic_jobs_ajax.nonce,
                quiet: true
            },
            timeout: 10000,
            success: function(response) {
                if (response.success) {
                    updateStatisticsOnly(response.data.stats);
                    
                    // Check if we need to trigger auto-refresh
                    const pendingJobs = parseInt(response.data.stats.pending_jobs) || 0;
                    const processingJobs = parseInt(response.data.stats.processing_jobs) || 0;
                    
                    if (pendingJobs > 0 || processingJobs > 0) {
                        if (!isAutoRefreshEnabled) {
                            startAutoRefresh();
                        }
                        
                        // Check for stuck jobs (pending for more than 10 minutes)
                        if (response.data.jobs) {
                            checkForStuckJobs(response.data.jobs);
                        }
                    } else if (pendingJobs === 0 && processingJobs === 0 && isAutoRefreshEnabled) {
                        stopAutoRefresh();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.log('SFAIC: Quiet status check failed:', status, error);
            },
            complete: function() {
                quietCheckInProgress = false;
            }
        });
    }
    
    /**
     * Check for jobs that appear to be stuck
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
     * Show warning for stuck jobs (only once per session)
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
                        <button type="button" class="notice-dismiss">
                            <span class="screen-reader-text">Dismiss</span>
                        </button>
                    </p>
                </div>
            `);
            
            $('.wp-header-end').after(warning);
            
            // Bind auto-fix handler
            $('#auto-fix-stuck-jobs').off('click').on('click', function() {
                cleanupStuckJobs();
                warning.remove();
            });
            
            // Bind dismiss handler
            warning.find('.notice-dismiss').off('click').on('click', function() {
                warning.remove();
            });
        }
    }
    
    /**
     * Add keyboard shortcuts information
     */
    function addKeyboardShortcutsInfo() {
        const shortcutsInfo = `
            <div class="notice notice-info inline" style="margin: 15px 0; font-size: 13px;">
                <p>
                    <strong>⌨️ Keyboard Shortcuts:</strong> 
                    Press <kbd>R</kbd> to refresh, <kbd>F</kbd> to force process jobs
                </p>
            </div>
        `;
        
        $('.sfaic-job-actions').after(shortcutsInfo);
    }
    
    /**
     * Bind all event handlers
     */
    function bindEventHandlers() {
        console.log('SFAIC: Binding event handlers');
        
        // Prevent multiple event bindings by using off first
        $(document).off('click', '#sfaic-refresh-jobs').on('click', '#sfaic-refresh-jobs', function(e) {
            e.preventDefault();
            refreshJobsList();
        });
        
        $(document).off('click', '#sfaic-cleanup-jobs').on('click', '#sfaic-cleanup-jobs', function(e) {
            e.preventDefault();
            if (confirm(sfaic_jobs_ajax.strings.confirm_cleanup || 'Are you sure you want to cleanup old jobs?')) {
                cleanupOldJobs();
            }
        });
        
        // Main action buttons
        $(document).off('click', '#sfaic-force-process').on('click', '#sfaic-force-process', function(e) {
            e.preventDefault();
            if (confirm('This will force immediate processing of pending jobs. Continue?')) {
                forceProcessJobs();
            }
        });
        
        $(document).off('click', '#sfaic-cleanup-stuck').on('click', '#sfaic-cleanup-stuck', function(e) {
            e.preventDefault();
            if (confirm('This will reset jobs stuck in processing status back to pending. Continue?')) {
                cleanupStuckJobs();
            }
        });
        
        // Use event delegation to prevent duplicate bindings for dynamic content
        $(document).off('click', '.sfaic-retry-job').on('click', '.sfaic-retry-job', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (confirm(sfaic_jobs_ajax.strings.confirm_retry || 'Are you sure you want to retry this job?')) {
                const jobId = $(this).data('job-id');
                retryJob(jobId);
            }
        });
        
        $(document).off('click', '.sfaic-cancel-job').on('click', '.sfaic-cancel-job', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (confirm(sfaic_jobs_ajax.strings.confirm_cancel || 'Are you sure you want to cancel this job?')) {
                const jobId = $(this).data('job-id');
                cancelJob(jobId);
            }
        });
        
        // Table row click handling for error messages - Updated for WordPress table structure
        $(document).off('click', '.sfaic-jobs-table tbody tr').on('click', '.sfaic-jobs-table tbody tr', function(e) {
            // Don't trigger if clicking on a button or link
            if ($(e.target).is('button, a') || $(e.target).closest('button, a').length) {
                return;
            }
            
            const jobId = $(this).data('job-id');
            if (jobId) {
                const errorRow = $('#error-' + jobId);
                if (errorRow.length) {
                    errorRow.toggle();
                }
            }
        });
        
        // Auto-refresh toggle
        $(document).off('click', '#sfaic-toggle-auto-refresh').on('click', '#sfaic-toggle-auto-refresh', function(e) {
            e.preventDefault();
            toggleAutoRefresh();
        });
        
        // Keyboard shortcuts with proper event handling
        $(document).off('keydown.sfaic').on('keydown.sfaic', function(e) {
            // Only if not in input/textarea and no modifiers
            if ($(e.target).is('input, textarea, select') || e.ctrlKey || e.altKey || e.metaKey) {
                return;
            }
            
            switch(e.key.toLowerCase()) {
                case 'r':
                    e.preventDefault();
                    refreshJobsList();
                    break;
                case 'f':
                    e.preventDefault();
                    forceProcessJobs();
                    break;
            }
        });
    }
    
    /**
     * Enhanced refresh with better error handling and layout preservation
     */
    function refreshJobsList() {
        return new Promise((resolve, reject) => {
            if (refreshInProgress) {
                console.log('SFAIC: Refresh already in progress, skipping');
                reject('Refresh already in progress');
                return;
            }
            
            refreshInProgress = true;
            lastRefreshTime = Date.now();
            
            const button = $('#sfaic-refresh-jobs');
            const originalText = button.text();
            
            // Show loading state
            button.prop('disabled', true);
            button.find('.dashicons').addClass('spin');
            button.text('Refreshing...');
            
            // Store scroll position to restore after refresh
            const scrollPosition = $(window).scrollTop();
            
            $.ajax({
                url: sfaic_jobs_ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'sfaic_get_job_status',
                    nonce: sfaic_jobs_ajax.nonce
                },
                timeout: 20000,
                success: function(response) {
                    if (response.success && response.data) {
                        // Update in the correct order to prevent layout jumps
                        updateStatistics(response.data.stats);
                        updateJobsTable(response.data.jobs);
                        
                        // Restore scroll position
                        $(window).scrollTop(scrollPosition);
                        
                        showNotice('Jobs list refreshed successfully.', 'success', 3000);
                        resolve(response.data);
                    } else {
                        const errorMsg = response.data || 'Failed to refresh jobs list.';
                        showNotice(errorMsg, 'error');
                        reject(errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('SFAIC: Refresh error:', status, error, xhr.responseText);
                    let errorMsg = 'Error occurred while refreshing jobs list.';
                    
                    if (status === 'timeout') {
                        errorMsg = 'Refresh timed out. The server may be busy processing jobs.';
                        showNotice(errorMsg, 'warning');
                    } else if (xhr.status === 403) {
                        errorMsg = 'Permission denied. Please refresh the page and try again.';
                        showNotice(errorMsg, 'error');
                    } else if (xhr.status === 500) {
                        errorMsg = 'Server error occurred. Please check the error logs.';
                        showNotice(errorMsg, 'error');
                    } else {
                        showNotice(errorMsg, 'error');
                    }
                    
                    reject(errorMsg);
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
        });
    }
    
    /**
     * Update only statistics without affecting the table
     */
    function updateStatisticsOnly(stats) {
        if (!stats) {
            console.warn('SFAIC: No stats provided to updateStatisticsOnly');
            return;
        }
        
        // Use animation frame to prevent layout thrashing
        requestAnimationFrame(() => {
            $('.sfaic-stat-card.total .stat-number').text(stats.total_jobs || 0);
            $('.sfaic-stat-card.pending .stat-number').text(stats.pending_jobs || 0);
            $('.sfaic-stat-card.processing .stat-number').text(stats.processing_jobs || 0);
            $('.sfaic-stat-card.completed .stat-number').text(stats.completed_jobs || 0);
            $('.sfaic-stat-card.failed .stat-number').text(stats.failed_jobs || 0);
            $('.sfaic-stat-card.retry .stat-number').text(stats.retry_jobs || 0);
            
            // Update highlighting
            highlightProblemsInStats(stats);
        });
    }
    
    /**
     * Improved table update that prevents layout issues - Updated for WordPress style
     */
    function updateJobsTable(jobs) {
        const tbody = $('#sfaic-jobs-tbody');
        const tableWrapper = $('.sfaic-jobs-table-wrapper');
        
        if (!tbody.length && !tableWrapper.length) {
            console.error('SFAIC: Jobs table not found');
            return;
        }
        
        // Store current state
        const currentScrollTop = $(window).scrollTop();
        const expandedRows = [];
        
        // Remember which error rows were expanded
        tbody.find('tr[id^="error-"]').each(function() {
            if ($(this).is(':visible')) {
                expandedRows.push($(this).attr('id'));
            }
        });
        
        if (!jobs || jobs.length === 0) {
            // Show empty state
            const emptyState = `
                <div class="sfaic-no-jobs">
                    <span class="dashicons dashicons-admin-post"></span>
                    <h3>No jobs found</h3>
                    <p>Background jobs will appear here when forms are submitted.</p>
                </div>
            `;
            
            if (tableWrapper.length) {
                tableWrapper.html(emptyState);
            }
            return;
        }
        
        // Ensure we have the table structure
        if (!$('.sfaic-jobs-table').length) {
            const tableHTML = `
                <div class="tablenav top">
                    <h3>Recent Jobs</h3>
                </div>
                <table class="wp-list-table widefat fixed striped sfaic-jobs-table">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-id">ID</th>
                            <th scope="col" class="manage-column column-type">Type</th>
                            <th scope="col" class="manage-column column-name">Name</th>
                            <th scope="col" class="manage-column column-email">Email</th>
                            <th scope="col" class="manage-column column-status">Status</th>
                            <th scope="col" class="manage-column column-date">Created</th>
                            <th scope="col" class="manage-column column-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="sfaic-jobs-tbody">
                    </tbody>
                </table>
            `;
            
            if (tableWrapper.length) {
                tableWrapper.html(tableHTML);
            }
        }
        
        // Update tbody reference after potential recreation
        const newTbody = $('#sfaic-jobs-tbody');
        
        // Use document fragment for better performance
        const fragment = $(document.createDocumentFragment());
        
        // Build new content efficiently
        jobs.forEach(function(job, index) {
            const row = createJobRow(job, index);
            fragment.append(row);
            
            // Add error row if there's an error message
            if (job.error_message) {
                const errorRow = createErrorRow(job);
                fragment.append(errorRow);
                
                // Restore expanded state
                if (expandedRows.includes('error-' + job.id)) {
                    errorRow.show();
                }
            }
        });
        
        // Use animation frame to prevent layout thrashing
        requestAnimationFrame(() => {
            newTbody.empty().append(fragment);
            
            // Restore scroll position
            $(window).scrollTop(currentScrollTop);
        });
    }
    
    /**
     * Create job row element - Updated for WordPress table style
     */
    function createJobRow(job, index) {
        const statusClass = 'status-' + job.status;
        let statusBadge = '<span class="status-badge ' + statusClass + '">' + 
                          job.status.charAt(0).toUpperCase() + job.status.slice(1) + '</span>';
        
        // Add warning for old pending jobs
        if (job.status === 'pending') {
            const createdTime = new Date(job.created_at).getTime();
            const now = new Date().getTime();
            const ageMinutes = (now - createdTime) / (1000 * 60);
            
            if (ageMinutes > 5) {
                statusBadge += '<br><small style="color: #d63638;">⚠️ ' + Math.round(ageMinutes) + 'min old</small>';
            }
        }
        
        const createdDate = new Date(job.created_at).toLocaleString();
        
        let actions = '';
        if (job.status === 'failed' || job.status === 'retry') {
            actions += '<button type="button" class="button button-small sfaic-retry-job" data-job-id="' + job.id + '">Retry</button> ';
        }
        if (job.status === 'pending') {
            actions += '<button type="button" class="button button-small sfaic-cancel-job" data-job-id="' + job.id + '">Cancel</button>';
        }
        
        const userName = job.user_name || '-';
        const userEmail = job.user_email ? 
            '<a href="mailto:' + job.user_email + '">' + job.user_email + '</a>' : '-';
        
        const alternateClass = (index % 2 === 1) ? 'alternate' : '';
        
        return $('<tr class="' + alternateClass + '" data-job-id="' + job.id + '">' +
                '<td class="column-id" data-label="ID"><strong>' + job.id + '</strong></td>' +
                '<td class="column-type" data-label="Type">' + job.job_type + '</td>' +
                '<td class="column-name" data-label="Name">' + userName + '</td>' +
                '<td class="column-email" data-label="Email">' + userEmail + '</td>' +
                '<td class="column-status" data-label="Status">' + statusBadge + '</td>' +
                '<td class="column-date" data-label="Created">' + createdDate + '</td>' +
                '<td class="column-actions" data-label="Actions">' + actions + '</td>' +
                '</tr>');
    }
    
    /**
     * Create error row element
     */
    function createErrorRow(job) {
        return $('<tr class="job-error-row" style="display: none;" id="error-' + job.id + '">' +
                '<td colspan="7">' +
                '<div class="error-message">' +
                '<strong>Error:</strong> ' + (job.error_message || 'Unknown error') +
                '</div>' +
                '</td>' +
                '</tr>');
    }
    
    /**
     * Update statistics with layout preservation
     */
    function updateStatistics(stats) {
        updateStatisticsOnly(stats);
    }
    
    /**
     * Highlight problems in statistics with controlled animation
     */
    function highlightProblemsInStats(stats) {
        const pendingJobs = stats.pending_jobs || 0;
        const processingJobs = stats.processing_jobs || 0;
        const failedJobs = stats.failed_jobs || 0;
        
        // Clear any existing animations
        $('.sfaic-stat-card').removeClass('sfaic-pulse-animation');
        
        if (pendingJobs > 5) {
            $('.sfaic-stat-card.pending').addClass('sfaic-pulse-animation');
        }
        
        if (processingJobs > 3) {
            $('.sfaic-stat-card.processing').addClass('sfaic-pulse-animation');
        }
        
        if (failedJobs > 0) {
            $('.sfaic-stat-card.failed').addClass('sfaic-pulse-animation');
        }
    }
    
    /**
     * Retry job with proper error handling
     */
    function retryJob(jobId) {
        const button = $('.sfaic-retry-job[data-job-id="' + jobId + '"]');
        const originalText = button.text();
        
        button.prop('disabled', true).text('Retrying...');
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_retry_job',
                nonce: sfaic_jobs_ajax.nonce,
                job_id: jobId
            },
            timeout: 15000,
            success: function(response) {
                if (response.success) {
                    showNotice('Job scheduled for retry.', 'success');
                    // Delay refresh to allow job to be processed
                    setTimeout(function() {
                        refreshJobsList();
                    }, 1000);
                } else {
                    showNotice(response.data || 'Failed to retry job.', 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice('Error occurred while retrying job: ' + status, 'error');
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    }
    
    /**
     * Cancel job with proper error handling
     */
    function cancelJob(jobId) {
        const button = $('.sfaic-cancel-job[data-job-id="' + jobId + '"]');
        const originalText = button.text();
        
        button.prop('disabled', true).text('Cancelling...');
        
        $.ajax({
            url: sfaic_jobs_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sfaic_cancel_job',
                nonce: sfaic_jobs_ajax.nonce,
                job_id: jobId
            },
            timeout: 15000,
            success: function(response) {
                if (response.success) {
                    showNotice('Job cancelled.', 'success');
                    refreshJobsList();
                } else {
                    showNotice(response.data || 'Failed to cancel job.', 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice('Error occurred while cancelling job: ' + status, 'error');
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    }
    
    /**
     * Cleanup old jobs
     */
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
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    showNotice('Old jobs cleaned up successfully.', 'success');
                    refreshJobsList();
                } else {
                    showNotice(response.data || 'Failed to cleanup old jobs.', 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice('Error occurred while cleaning up jobs: ' + status, 'error');
            },
            complete: function() {
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
                button.text(originalText);
            }
        });
    }
    
    /**
     * Force process jobs
     */
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
                    showNotice('Failed to process jobs: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice('Error occurred while processing jobs: ' + status + ' - ' + error, 'error');
            },
            complete: function() {
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
                button.text(originalText);
            }
        });
    }
    
    /**
     * Cleanup stuck jobs
     */
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
                    showNotice('Failed to cleanup stuck jobs: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice('Error occurred while cleaning up stuck jobs: ' + status + ' - ' + error, 'error');
            },
            complete: function() {
                button.prop('disabled', false);
                button.find('.dashicons').removeClass('spin');
                button.text(originalText);
            }
        });
    }
    
    /**
     * Better auto-refresh logic with proper state management
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
    
    /**
     * Start auto-refresh
     */
    function startAutoRefresh() {
        if (isAutoRefreshEnabled) return;
        
        console.log('SFAIC: Starting auto-refresh');
        isAutoRefreshEnabled = true;
        addAutoRefreshIndicator();
        
        // Refresh every 15 seconds, but only if page is visible and not already refreshing
        autoRefreshInterval = setInterval(function() {
            if (!refreshInProgress && pageVisible) {
                const now = Date.now();
                if (now - lastRefreshTime > 12000) { // At least 12 seconds between auto-refreshes
                    refreshJobsList().catch(function(error) {
                        console.warn('SFAIC: Auto-refresh failed:', error);
                    });
                }
            }
        }, 15000);
    }
    
    /**
     * Stop auto-refresh
     */
    function stopAutoRefresh() {
        if (!isAutoRefreshEnabled) return;
        
        console.log('SFAIC: Stopping auto-refresh');
        isAutoRefreshEnabled = false;
        removeAutoRefreshIndicator();
        
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
    
    /**
     * Toggle auto-refresh
     */
    function toggleAutoRefresh() {
        if (isAutoRefreshEnabled) {
            stopAutoRefresh();
        } else {
            startAutoRefresh();
        }
    }
    
    /**
     * Add auto-refresh indicator
     */
    function addAutoRefreshIndicator() {
        if ($('#sfaic-auto-refresh-indicator').length === 0) {
            const indicator = $('<div id="sfaic-auto-refresh-indicator">' +
                             '<p><span class="dashicons dashicons-update spin"></span> ' +
                             'Auto-refresh is enabled (updates every 15 seconds). ' +
                             '<a href="#" id="sfaic-toggle-auto-refresh">Disable</a></p>' +
                             '</div>');
            $('.sfaic-job-actions').after(indicator);
        }
    }
    
    /**
     * Remove auto-refresh indicator
     */
    function removeAutoRefreshIndicator() {
        $('#sfaic-auto-refresh-indicator').remove();
    }
    
    /**
     * Enhanced notice system with better timing and cleanup - WordPress style
     */
    function showNotice(message, type, duration) {
        // Remove any existing notices of the same type to prevent stacking
        $('.sfaic-temp-notice.notice-' + type).remove();
        
        const noticeClass = 'notice-' + type;
        const noticeId = 'sfaic-notice-' + Date.now();
        const notice = $('<div class="notice ' + noticeClass + ' is-dismissible sfaic-temp-notice" id="' + noticeId + '">' +
                       '<p>' + message + '</p>' +
                       '<button type="button" class="notice-dismiss">' +
                       '<span class="screen-reader-text">Dismiss this notice.</span>' +
                       '</button>' +
                       '</div>');
        
        // Insert after the header end
        $('.wp-header-end').after(notice);
        
        // Auto-dismiss after appropriate time
        const dismissTime = duration || (type === 'error' ? 8000 : 4000);
        setTimeout(function() {
            $('#' + noticeId).fadeOut(500, function() {
                $(this).remove();
            });
        }, dismissTime);
        
        // Handle dismiss button
        notice.find('.notice-dismiss').off('click').on('click', function() {
            notice.remove();
        });
        
        // Log to console for debugging
        console.log('SFAIC Notice (' + type + '):', message);
    }
    
    // Add CSS for WordPress-style elements
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            kbd {
                background: #f7f7f7;
                border: 1px solid #ccc;
                border-radius: 3px;
                box-shadow: 0 1px 0 rgba(0,0,0,0.2);
                color: #333;
                display: inline-block;
                font-size: 11px;
                font-weight: 700;
                line-height: 1;
                padding: 2px 4px;
                white-space: nowrap;
                margin: 0 2px;
            }
            .spin {
                animation: rotation 1s infinite linear;
            }
            @keyframes rotation {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .sfaic-pulse-animation {
                animation: pulse 2s infinite;
            }
            @keyframes pulse {
                0% { opacity: 1; }
                50% { opacity: 0.7; box-shadow: 0 0 0 0 rgba(214, 54, 56, 0.4); }
                100% { opacity: 1; }
            }
        `)
        .appendTo('head');
    
    // Global error handler for AJAX requests
    $(document).ajaxError(function(event, xhr, settings, error) {
        if (settings.url && settings.url.indexOf('sfaic_') !== -1) {
            console.error('SFAIC AJAX Error:', {
                url: settings.url,
                status: xhr.status,
                statusText: xhr.statusText,
                responseText: xhr.responseText,
                error: error
            });
        }
    });
    
    // Initialize complete
    console.log('SFAIC: WordPress-style background jobs interface initialized successfully');
});