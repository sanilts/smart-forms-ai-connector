/**
 * Complete Background Jobs Admin Interface JavaScript - Fixed Version
 * 
 * Replace your existing assets/js/background-jobs.js with this complete file
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
        console.log('SFAIC: Initializing background jobs interface');
        
        // Bind event handlers
        bindEventHandlers();
        
        // Start auto-refresh if there are pending or processing jobs
        checkAutoRefresh();
        
        // Add debugging tools
        addDebuggingTools();
        
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
                        <button type="button" class="notice-dismiss" style="float: right;">
                            <span class="screen-reader-text">Dismiss</span>
                        </button>
                    </p>
                </div>
            `);
            
            $('.sfaic-jobs-overview').after(warning);
            
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
     * Add debugging tools section
     */
    function addDebuggingTools() {
        // Check if debug section already exists
        if ($('.sfaic-debug-section').length > 0) {
            return;
        }
        
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
                    <button type="button" id="sfaic-test-refresh" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span>
                        Test Refresh
                    </button>
                </div>
                <div id="sfaic-debug-results" style="background: #f8f9fa; padding: 10px; border-radius: 3px; display: none;">
                    <strong>Debug Results:</strong>
                    <pre id="sfaic-debug-output" style="margin: 10px 0; font-size: 12px; max-height: 200px; overflow-y: auto; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 3px;"></pre>
                </div>
            </div>
        `;
        
        // Insert after the job actions
        $('.sfaic-job-actions').after(debugSection);
        
        // Bind debug event handlers
        bindDebugHandlers();
    }
    
    /**
     * Add keyboard shortcuts information
     */
    function addKeyboardShortcutsInfo() {
        const shortcutsInfo = `
            <div class="sfaic-shortcuts-info" style="background: #e8f5e8; border: 1px solid #c3e6cb; padding: 10px; margin: 10px 0; border-radius: 3px; font-size: 12px;">
                <strong>⌨️ Keyboard Shortcuts:</strong> 
                Press <kbd>R</kbd> to refresh, <kbd>F</kbd> to force process jobs
            </div>
        `;
        
        $('.sfaic-job-actions').after(shortcutsInfo);
    }
    
    /**
     * Bind debug event handlers
     */
    function bindDebugHandlers() {
        // Prevent multiple bindings by using off first
        $(document).off('click', '#sfaic-debug-cron').on('click', '#sfaic-debug-cron', function(e) {
            e.preventDefault();
            debugCronStatus();
        });
        
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
        
        $(document).off('click', '#sfaic-test-refresh').on('click', '#sfaic-test-refresh', function(e) {
            e.preventDefault();
            testRefreshFunction();
        });
    }
    
    /**
     * Test refresh function for debugging
     */
    function testRefreshFunction() {
        console.log('SFAIC: Testing refresh function');
        const startTime = Date.now();
        
        refreshJobsList().then(function() {
            const endTime = Date.now();
            console.log('SFAIC: Refresh completed in', (endTime - startTime), 'ms');
            showNotice('Refresh test completed successfully in ' + (endTime - startTime) + 'ms', 'success', 3000);
        }).catch(function(error) {
            console.error('SFAIC: Refresh test failed:', error);
            showNotice('Refresh test failed: ' + error, 'error');
        });
    }
    
    /**
     * Debug cron status
     */
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
                    output += 'Server Time: ' + data.server_time + '\n';
                    output += 'WP Cron Disabled: ' + (data.wp_cron_disabled ? 'YES (This is likely the problem!)' : 'NO') + '\n';
                    output += 'Next Scheduled: ' + data.next_scheduled + '\n';
                    output += 'Pending Jobs: ' + data.pending_jobs + '\n';
                    output += 'Processing Jobs: ' + data.processing_jobs + '\n';
                    
                    if (data.stuck_jobs !== undefined) {
                        output += 'Stuck Jobs: ' + data.stuck_jobs + '\n';
                    }
                    
                    if (data.last_cron_run_formatted) {
                        output += 'Last Cron Run: ' + data.last_cron_run_formatted + ' (' + data.minutes_since_last_run + ' minutes ago)\n';
                    } else {
                        output += 'Last Cron Run: Never\n';
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
                    
                    if (data.stuck_jobs > 0) {
                        output += '\n⚠️ ISSUE DETECTED: ' + data.stuck_jobs + ' jobs stuck in processing!\n';
                        output += 'Click "Reset Stuck Jobs" to fix this.\n';
                    }
                    
                    if (data.minutes_since_last_run > 5) {
                        output += '\n⚠️ WARNING: Last cron run was more than 5 minutes ago!\n';
                        output += 'This might indicate cron issues.\n';
                    }
                    
                    $('#sfaic-debug-output').text(output);
                    $('#sfaic-debug-results').show();
                    
                    showNotice('Debug information retrieved successfully.', 'success');
                } else {
                    showNotice('Failed to get debug information: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice('Error occurred while debugging: ' + status + ' - ' + error, 'error');
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
        
        // Table row click handling for error messages
        $(document).off('click', '.sfaic-jobs-table tbody tr').on('click', '.sfaic-jobs-table tbody tr', function(e) {
            // Don't trigger if clicking on a button
            if ($(e.target).is('button') || $(e.target).closest('button').length) {
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
                case 'c':
                    e.preventDefault();
                    cleanupStuckJobs();
                    break;
                case 'd':
                    e.preventDefault();
                    debugCronStatus();
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
            
            // Add loading overlay to prevent interactions
            const loadingOverlay = $('<div class="sfaic-loading-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.7); z-index: 100; display: flex; align-items: center; justify-content: center;"><div style="background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);"><span class="dashicons dashicons-update spin" style="margin-right: 10px;"></span>Refreshing jobs...</div></div>');
            $('.sfaic-jobs-table').closest('.wrap').css('position', 'relative').append(loadingOverlay);
            
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
                    // Remove loading overlay
                    $('.sfaic-loading-overlay').remove();
                    
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
     * Improved table update that prevents layout issues
     */
    function updateJobsTable(jobs) {
        const tbody = $('#sfaic-jobs-tbody');
        
        if (!tbody.length) {
            console.error('SFAIC: Jobs table body not found');
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
        
        // Use document fragment for better performance
        const fragment = $(document.createDocumentFragment());
        
        if (!jobs || jobs.length === 0) {
            fragment.append('<tr><td colspan="7" style="text-align: center; padding: 20px; color: #666;">No jobs found.</td></tr>');
        } else {
            // Build new content efficiently
            jobs.forEach(function(job) {
                const row = createJobRow(job);
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
        }
        
        // Use animation frame to prevent layout thrashing
        requestAnimationFrame(() => {
            tbody.empty().append(fragment);
            
            // Restore scroll position
            $(window).scrollTop(currentScrollTop);
        });
    }
    
    /**
     * Create job row element
     */
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
        
        return $('<tr data-job-id="' + job.id + '">' +
                '<td>' + job.id + '</td>' +
                '<td>' + job.job_type + '</td>' +
                '<td>' + userName + '</td>' +
                '<td>' + userEmail + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + createdDate + '</td>' +
                '<td>' + actions + '</td>' +
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
            const indicator = $('<div id="sfaic-auto-refresh-indicator" class="notice notice-info inline">' +
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
     * Enhanced notice system with better timing and cleanup
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
        
        // Insert after the h1
        $('.wrap h1').after(notice);
        
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
    
    // Add CSS for loading states and animations
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            @keyframes sfaic-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            @keyframes sfaic-pulse {
                0% { opacity: 1; }
                50% { opacity: 0.7; box-shadow: 0 0 10px rgba(220, 53, 69, 0.3); }
                100% { opacity: 1; }
            }
            .spin {
                animation: sfaic-spin 1s linear infinite;
            }
            .sfaic-pulse-animation {
                animation: sfaic-pulse 2s infinite;
            }
            .sfaic-temp-notice {
                margin: 15px 0;
                position: relative;
                z-index: 10;
            }
            .job-error-row {
                background-color: #ffeaea !important;
            }
            .error-message {
                padding: 10px;
                background-color: #f8d7da;
                border: 1px solid #f5c6cb;
                border-radius: 3px;
                color: #721c24;
                font-size: 13px;
                word-wrap: break-word;
            }
            .sfaic-debug-section {
                border-radius: 5px;
                clear: both;
            }
            .sfaic-debug-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .sfaic-debug-actions button {
                margin: 0;
            }
            .sfaic-stuck-jobs-warning {
                border-left: 4px solid #dc3545;
            }
            .sfaic-loading-overlay {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                background: rgba(255,255,255,0.8) !important;
                z-index: 9999 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
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
    console.log('SFAIC: Background jobs interface initialized successfully');
});