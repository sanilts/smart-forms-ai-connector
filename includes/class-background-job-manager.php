<?php
/**
 * Background Job Manager Class
 * 
 * Handles background processing of AI requests using WP Cron
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Background_Job_Manager {
    
    /**
     * Jobs table name
     */
    private $jobs_table;
    
    /**
     * Table version
     */
    private $table_version = '1.1';
    
    /**
     * Hook name for background processing
     */
    const CRON_HOOK = 'sfaic_process_background_job';
    
    /**
     * Job statuses
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_RETRY = 'retry';
    
    /**
     * Maximum retry attempts
     */
    const MAX_RETRIES = 3;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->jobs_table = $wpdb->prefix . 'sfaic_background_jobs';
        
        // Initialize hooks
        $this->init_hooks();
        
        // Create or update jobs table
        $this->ensure_table_exists();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register cron hook
        add_action(self::CRON_HOOK, array($this, 'process_background_job'));
        
        // Register cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        // Admin menu for job monitoring
        add_action('admin_menu', array($this, 'add_jobs_menu'));
        
        // AJAX handlers for job management
        add_action('wp_ajax_sfaic_retry_job', array($this, 'ajax_retry_job'));
        add_action('wp_ajax_sfaic_cancel_job', array($this, 'ajax_cancel_job'));
        add_action('wp_ajax_sfaic_cleanup_jobs', array($this, 'ajax_cleanup_jobs'));
        add_action('wp_ajax_sfaic_get_job_status', array($this, 'ajax_get_job_status'));
        
        // Cleanup old jobs daily
        if (!wp_next_scheduled('sfaic_cleanup_old_jobs')) {
            wp_schedule_event(time(), 'daily', 'sfaic_cleanup_old_jobs');
        }
        add_action('sfaic_cleanup_old_jobs', array($this, 'cleanup_old_jobs'));
        
        // Enqueue scripts for job monitoring
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Check table version on admin_init
        add_action('admin_init', array($this, 'check_table_version'));
    }
    
    /**
     * Check and update table structure if needed
     */
    public function check_table_version() {
        $current_version = get_option('sfaic_jobs_table_version', '0');
        
        if (version_compare($current_version, $this->table_version, '<')) {
            $this->update_table_structure();
            update_option('sfaic_jobs_table_version', $this->table_version);
        }
    }
    
    /**
     * Update table structure
     */
    private function update_table_structure() {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->jobs_table}'") === $this->jobs_table;
        
        if (!$table_exists) {
            $this->create_jobs_table();
            return;
        }
        
        // Check if job_data column exists
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->jobs_table}");
        $column_names = array_column($columns, 'Field');
        
        if (!in_array('job_data', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->jobs_table} ADD COLUMN `job_data` LONGTEXT NOT NULL AFTER `entry_id`");
        }
    }
    
    /**
     * Ensure table exists with correct structure
     */
    private function ensure_table_exists() {
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->jobs_table}'") === $this->jobs_table;
        
        if (!$table_exists) {
            $this->create_jobs_table();
        } else {
            // Check if we need to update the structure
            $this->check_table_version();
        }
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_30_seconds'] = array(
            'interval' => 30,
            'display' => __('Every 30 Seconds', 'chatgpt-fluent-connector')
        );
        
        $schedules['every_2_minutes'] = array(
            'interval' => 120,
            'display' => __('Every 2 Minutes', 'chatgpt-fluent-connector')
        );
        
        return $schedules;
    }
    
    /**
     * Create background jobs table
     */
    public function create_jobs_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->jobs_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            job_type varchar(50) NOT NULL,
            prompt_id bigint(20) NOT NULL,
            form_id bigint(20) NOT NULL,
            entry_id bigint(20) NOT NULL,
            job_data longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            priority int(11) NOT NULL DEFAULT 0,
            retry_count int(11) NOT NULL DEFAULT 0,
            max_retries int(11) NOT NULL DEFAULT 3,
            error_message text DEFAULT NULL,
            scheduled_at datetime NOT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY scheduled_at (scheduled_at),
            KEY priority (priority),
            KEY job_type (job_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Update the table version
        update_option('sfaic_jobs_table_version', $this->table_version);
    }
    
    /**
     * Schedule a background job
     * 
     * @param string $job_type Type of job
     * @param int $prompt_id Prompt ID
     * @param int $form_id Form ID  
     * @param int $entry_id Entry ID
     * @param array $job_data Job data
     * @param int $delay Delay in seconds before processing
     * @param int $priority Job priority (higher = more important)
     * @return int|false Job ID or false on failure
     */
    public function schedule_job($job_type, $prompt_id, $form_id, $entry_id, $job_data, $delay = 0, $priority = 0) {
        // Check if background processing is enabled
        if (!$this->is_background_processing_enabled()) {
            error_log('SFAIC: Background processing is disabled');
            return false;
        }
        
        // Ensure table exists with correct structure
        $this->ensure_table_exists();
        
        global $wpdb;
        
        $scheduled_time = current_time('mysql');
        if ($delay > 0) {
            $scheduled_time = date('Y-m-d H:i:s', current_time('timestamp') + $delay);
        }
        
        $job_data_json = json_encode($job_data);
        
        error_log('SFAIC: Inserting job into database');
        
        $result = $wpdb->insert(
            $this->jobs_table,
            array(
                'job_type' => $job_type,
                'prompt_id' => $prompt_id,
                'form_id' => $form_id,
                'entry_id' => $entry_id,
                'job_data' => $job_data_json,
                'status' => self::STATUS_PENDING,
                'priority' => $priority,
                'scheduled_at' => $scheduled_time,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            error_log('SFAIC: Failed to schedule background job: ' . $wpdb->last_error);
            return false;
        }
        
        $job_id = $wpdb->insert_id;
        error_log('SFAIC: Job inserted with ID: ' . $job_id);
        
        // Schedule the cron event
        $this->schedule_cron_event($delay);
        
        return $job_id;
    }
    
    /**
     * Schedule cron event for processing jobs
     */
    private function schedule_cron_event($delay = 0) {
        $timestamp = time() + $delay;
        
        // Don't schedule if one is already scheduled soon
        $next_scheduled = wp_next_scheduled(self::CRON_HOOK);
        if ($next_scheduled && $next_scheduled <= $timestamp + 30) {
            error_log('SFAIC: Cron already scheduled at ' . date('Y-m-d H:i:s', $next_scheduled));
            return;
        }
        
        wp_schedule_single_event($timestamp, self::CRON_HOOK);
        error_log('SFAIC: Scheduled cron event for ' . date('Y-m-d H:i:s', $timestamp));
    }
    
    /**
     * Process background job
     */
    public function process_background_job() {
        error_log('SFAIC: Background job processor triggered');
        
        global $wpdb;
        
        // Get the next job to process
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->jobs_table} 
             WHERE status = %s 
             AND scheduled_at <= %s 
             ORDER BY priority DESC, scheduled_at ASC 
             LIMIT 1",
            self::STATUS_PENDING,
            current_time('mysql')
        ));
        
        if (!$job) {
            error_log('SFAIC: No pending jobs to process');
            return;
        }
        
        error_log('SFAIC: Processing job ID: ' . $job->id);
        
        // Update job status to processing
        $this->update_job_status($job->id, self::STATUS_PROCESSING);
        
        try {
            // Process the job based on type
            $success = $this->execute_job($job);
            
            if ($success) {
                $this->update_job_status($job->id, self::STATUS_COMPLETED);
                $this->log_job_message($job->id, 'Job completed successfully');
            } else {
                $this->handle_job_failure($job);
            }
            
        } catch (Exception $e) {
            error_log('SFAIC: Job execution error: ' . $e->getMessage());
            $this->handle_job_failure($job, $e->getMessage());
        }
        
        // Schedule next job processing if there are more pending jobs
        $pending_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->jobs_table} WHERE status = %s AND scheduled_at <= %s",
            self::STATUS_PENDING,
            current_time('mysql')
        ));
        
        if ($pending_count > 0) {
            $this->schedule_cron_event(5); // Process next job in 5 seconds
        }
    }
    
    /**
     * Execute a specific job
     */
    private function execute_job($job) {
        $job_data = json_decode($job->job_data, true);
        
        switch ($job->job_type) {
            case 'ai_form_processing':
                return $this->process_ai_form_job($job, $job_data);
                
            default:
                throw new Exception('Unknown job type: ' . $job->job_type);
        }
    }
    
    /**
     * Process AI form job
     */
    private function process_ai_form_job($job, $job_data) {
        error_log('SFAIC Background Job: Processing job ID ' . $job->id . ' for prompt ' . $job->prompt_id);
        
        // Get the forms integration instance
        if (!isset(sfaic_main()->fluent_integration)) {
            throw new Exception('Forms integration not available');
        }
        
        // Get form object
        if (!function_exists('wpFluent')) {
            throw new Exception('Fluent Forms not available');
        }
        
        $form = wpFluent()->table('fluentform_forms')
                ->where('id', $job->form_id)
                ->first();
        
        if (!$form) {
            throw new Exception('Form not found: ' . $job->form_id);
        }
        
        error_log('SFAIC Background Job: Found form ID ' . $form->id . ', executing process_prompt');
        
        // Call the process_prompt method directly (now it's public)
        $result = sfaic_main()->fluent_integration->process_prompt(
            $job->prompt_id,
            $job_data['form_data'],
            $job->entry_id,
            $form
        );
        
        error_log('SFAIC Background Job: Process result: ' . ($result ? 'Success' : 'Failed'));
        
        return $result;
    }
    
    /**
     * Handle job failure
     */
    private function handle_job_failure($job, $error_message = '') {
        global $wpdb;
        
        $retry_count = intval($job->retry_count) + 1;
        $max_retries = intval($job->max_retries);
        
        if ($retry_count <= $max_retries) {
            // Schedule retry with exponential backoff
            $delay = pow(2, $retry_count) * 60; // 2, 4, 8 minutes
            $retry_time = date('Y-m-d H:i:s', current_time('timestamp') + $delay);
            
            $wpdb->update(
                $this->jobs_table,
                array(
                    'status' => self::STATUS_RETRY,
                    'retry_count' => $retry_count,
                    'scheduled_at' => $retry_time,
                    'error_message' => $error_message,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $job->id),
                array('%s', '%d', '%s', '%s', '%s'),
                array('%d')
            );
            
            // Schedule retry
            $this->schedule_cron_event($delay);
            
            $this->log_job_message($job->id, "Job failed, scheduled retry #{$retry_count} in {$delay} seconds. Error: {$error_message}");
        } else {
            // Mark as failed permanently
            $this->update_job_status($job->id, self::STATUS_FAILED, $error_message);
            $this->log_job_message($job->id, "Job failed permanently after {$max_retries} retries. Error: {$error_message}");
        }
    }
    
    /**
     * Update job status
     */
    private function update_job_status($job_id, $status, $error_message = '') {
        global $wpdb;
        
        $data = array(
            'status' => $status,
            'updated_at' => current_time('mysql')
        );
        
        if ($status === self::STATUS_PROCESSING) {
            $data['started_at'] = current_time('mysql');
        } elseif (in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED])) {
            $data['completed_at'] = current_time('mysql');
        }
        
        if (!empty($error_message)) {
            $data['error_message'] = $error_message;
        }
        
        $format = array_fill(0, count($data), '%s');
        
        $wpdb->update(
            $this->jobs_table,
            $data,
            array('id' => $job_id),
            $format,
            array('%d')
        );
    }
    
    /**
     * Log job message
     */
    private function log_job_message($job_id, $message) {
        error_log("SFAIC Background Job #{$job_id}: {$message}");
    }
    
    /**
     * Check if background processing is enabled
     */
    public function is_background_processing_enabled() {
        return get_option('sfaic_enable_background_processing', true);
    }
    
    /**
     * Get job statistics
     */
    public function get_job_statistics() {
        global $wpdb;
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                SUM(CASE WHEN status = 'retry' THEN 1 ELSE 0 END) as retry_jobs
            FROM {$this->jobs_table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        return $stats;
    }
    
    /**
     * Get recent jobs
     */
    public function get_recent_jobs($limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT j.*, p.post_title as prompt_title 
             FROM {$this->jobs_table} j
             LEFT JOIN {$wpdb->posts} p ON j.prompt_id = p.ID
             ORDER BY j.created_at DESC 
             LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Clean up old jobs
     */
    public function cleanup_old_jobs() {
        global $wpdb;
        
        // Delete completed jobs older than 7 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->jobs_table} 
             WHERE status = %s 
             AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            self::STATUS_COMPLETED
        ));
        
        // Delete failed jobs older than 30 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->jobs_table} 
             WHERE status = %s 
             AND completed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            self::STATUS_FAILED
        ));
    }
    
    /**
     * Add jobs monitoring menu
     */
    public function add_jobs_menu() {
        add_submenu_page(
            'edit.php?post_type=sfaic_prompt',
            __('Background Jobs', 'chatgpt-fluent-connector'),
            __('Background Jobs', 'chatgpt-fluent-connector'),
            'manage_options',
            'sfaic-background-jobs',
            array($this, 'render_jobs_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'sfaic_prompt_page_sfaic-background-jobs') {
            return;
        }
        
        wp_enqueue_script(
            'sfaic-background-jobs',
            SFAIC_URL . 'assets/js/background-jobs.js',
            array('jquery'),
            SFAIC_VERSION,
            true
        );
        
        wp_localize_script('sfaic-background-jobs', 'sfaic_jobs_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sfaic_jobs_nonce'),
            'strings' => array(
                'confirm_retry' => __('Are you sure you want to retry this job?', 'chatgpt-fluent-connector'),
                'confirm_cancel' => __('Are you sure you want to cancel this job?', 'chatgpt-fluent-connector'),
                'confirm_cleanup' => __('Are you sure you want to cleanup old jobs?', 'chatgpt-fluent-connector'),
            )
        ));
        
        wp_enqueue_style(
            'sfaic-background-jobs',
            SFAIC_URL . 'assets/css/background-jobs.css',
            array(),
            SFAIC_VERSION
        );
    }
    
    /**
     * Render jobs monitoring page
     */
    public function render_jobs_page() {
        $stats = $this->get_job_statistics();
        $recent_jobs = $this->get_recent_jobs();
        $background_enabled = $this->is_background_processing_enabled();
        ?>
        <div class="wrap">
            <h1><?php _e('Background Jobs Monitor', 'chatgpt-fluent-connector'); ?></h1>
            
            <!-- Status Overview -->
            <div class="sfaic-jobs-overview">
                <h2><?php _e('Job Statistics (Last 24 Hours)', 'chatgpt-fluent-connector'); ?></h2>
                <div class="sfaic-stats-grid">
                    <div class="sfaic-stat-card total">
                        <div class="stat-number"><?php echo esc_html($stats->total_jobs ?? 0); ?></div>
                        <div class="stat-label"><?php _e('Total Jobs', 'chatgpt-fluent-connector'); ?></div>
                    </div>
                    <div class="sfaic-stat-card pending">
                        <div class="stat-number"><?php echo esc_html($stats->pending_jobs ?? 0); ?></div>
                        <div class="stat-label"><?php _e('Pending', 'chatgpt-fluent-connector'); ?></div>
                    </div>
                    <div class="sfaic-stat-card processing">
                        <div class="stat-number"><?php echo esc_html($stats->processing_jobs ?? 0); ?></div>
                        <div class="stat-label"><?php _e('Processing', 'chatgpt-fluent-connector'); ?></div>
                    </div>
                    <div class="sfaic-stat-card completed">
                        <div class="stat-number"><?php echo esc_html($stats->completed_jobs ?? 0); ?></div>
                        <div class="stat-label"><?php _e('Completed', 'chatgpt-fluent-connector'); ?></div>
                    </div>
                    <div class="sfaic-stat-card failed">
                        <div class="stat-number"><?php echo esc_html($stats->failed_jobs ?? 0); ?></div>
                        <div class="stat-label"><?php _e('Failed', 'chatgpt-fluent-connector'); ?></div>
                    </div>
                    <div class="sfaic-stat-card retry">
                        <div class="stat-number"><?php echo esc_html($stats->retry_jobs ?? 0); ?></div>
                        <div class="stat-label"><?php _e('Retrying', 'chatgpt-fluent-connector'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Background Processing Status -->
            <div class="sfaic-processing-status">
                <h3><?php _e('Background Processing Status', 'chatgpt-fluent-connector'); ?></h3>
                <p>
                    <span class="status-indicator <?php echo $background_enabled ? 'enabled' : 'disabled'; ?>"></span>
                    <?php echo $background_enabled ? __('Enabled', 'chatgpt-fluent-connector') : __('Disabled', 'chatgpt-fluent-connector'); ?>
                    <a href="<?php echo admin_url('options-general.php?page=sfaic-settings'); ?>" class="button button-secondary" style="margin-left: 10px;">
                        <?php _e('Configure', 'chatgpt-fluent-connector'); ?>
                    </a>
                </p>
                
                <?php if (!$background_enabled): ?>
                <div class="notice notice-warning inline">
                    <p><?php _e('Background processing is disabled. AI requests will be processed synchronously, which may cause delays for users.', 'chatgpt-fluent-connector'); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Action Buttons -->
            <div class="sfaic-job-actions">
                <button type="button" id="sfaic-refresh-jobs" class="button">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Refresh', 'chatgpt-fluent-connector'); ?>
                </button>
                <button type="button" id="sfaic-cleanup-jobs" class="button">
                    <span class="dashicons dashicons-trash"></span>
                    <?php _e('Cleanup Old Jobs', 'chatgpt-fluent-connector'); ?>
                </button>
            </div>
            
            <!-- Recent Jobs Table -->
            <h3><?php _e('Recent Jobs', 'chatgpt-fluent-connector'); ?></h3>
            <table class="wp-list-table widefat fixed striped sfaic-jobs-table">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php _e('ID', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 120px;"><?php _e('Type', 'chatgpt-fluent-connector'); ?></th>
                        <th><?php _e('Prompt', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 80px;"><?php _e('Status', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 60px;"><?php _e('Retries', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 150px;"><?php _e('Created', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 150px;"><?php _e('Scheduled', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 100px;"><?php _e('Actions', 'chatgpt-fluent-connector'); ?></th>
                    </tr>
                </thead>
                <tbody id="sfaic-jobs-tbody">
                    <?php foreach ($recent_jobs as $job): ?>
                    <tr data-job-id="<?php echo esc_attr($job->id); ?>">
                        <td><?php echo esc_html($job->id); ?></td>
                        <td><?php echo esc_html($job->job_type); ?></td>
                        <td>
                            <?php if ($job->prompt_title): ?>
                                <a href="<?php echo esc_url(get_edit_post_link($job->prompt_id)); ?>">
                                    <?php echo esc_html($job->prompt_title); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html($job->prompt_id); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo esc_attr($job->status); ?>">
                                <?php echo esc_html(ucfirst($job->status)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($job->retry_count); ?>/<?php echo esc_html($job->max_retries); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($job->created_at))); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($job->scheduled_at))); ?></td>
                        <td>
                            <?php if (in_array($job->status, ['failed', 'retry'])): ?>
                            <button type="button" class="button button-small sfaic-retry-job" data-job-id="<?php echo esc_attr($job->id); ?>">
                                <?php _e('Retry', 'chatgpt-fluent-connector'); ?>
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($job->status === 'pending'): ?>
                            <button type="button" class="button button-small sfaic-cancel-job" data-job-id="<?php echo esc_attr($job->id); ?>">
                                <?php _e('Cancel', 'chatgpt-fluent-connector'); ?>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($job->error_message)): ?>
                    <tr class="job-error-row" style="display: none;" id="error-<?php echo esc_attr($job->id); ?>">
                        <td colspan="8">
                            <div class="error-message">
                                <strong><?php _e('Error:', 'chatgpt-fluent-connector'); ?></strong>
                                <?php echo esc_html($job->error_message); ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <?php if (empty($recent_jobs)): ?>
                    <tr>
                        <td colspan="8"><?php _e('No jobs found.', 'chatgpt-fluent-connector'); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * AJAX: Retry job
     */
    public function ajax_retry_job() {
        check_ajax_referer('sfaic_jobs_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $job_id = intval($_POST['job_id']);
        
        global $wpdb;
        $result = $wpdb->update(
            $this->jobs_table,
            array(
                'status' => self::STATUS_PENDING,
                'retry_count' => 0,
                'scheduled_at' => current_time('mysql'),
                'error_message' => '',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $job_id),
            array('%s', '%d', '%s', '%s', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            $this->schedule_cron_event(5);
            wp_send_json_success(__('Job scheduled for retry.', 'chatgpt-fluent-connector'));
        } else {
            wp_send_json_error(__('Failed to retry job.', 'chatgpt-fluent-connector'));
        }
    }
    
    /**
     * AJAX: Cancel job
     */
    public function ajax_cancel_job() {
        check_ajax_referer('sfaic_jobs_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $job_id = intval($_POST['job_id']);
        
        global $wpdb;
        $result = $wpdb->update(
            $this->jobs_table,
            array(
                'status' => self::STATUS_FAILED,
                'error_message' => 'Cancelled by admin',
                'completed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $job_id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(__('Job cancelled.', 'chatgpt-fluent-connector'));
        } else {
            wp_send_json_error(__('Failed to cancel job.', 'chatgpt-fluent-connector'));
        }
    }
    
    /**
     * AJAX: Cleanup old jobs
     */
    public function ajax_cleanup_jobs() {
        check_ajax_referer('sfaic_jobs_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $this->cleanup_old_jobs();
        wp_send_json_success(__('Old jobs cleaned up.', 'chatgpt-fluent-connector'));
    }
    
    /**
     * AJAX: Get job status
     */
    public function ajax_get_job_status() {
        check_ajax_referer('sfaic_jobs_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $stats = $this->get_job_statistics();
        $recent_jobs = $this->get_recent_jobs(20);
        
        wp_send_json_success(array(
            'stats' => $stats,
            'jobs' => $recent_jobs
        ));
    }
}