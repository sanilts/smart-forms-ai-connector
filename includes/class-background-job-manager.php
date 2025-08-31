<?php
/**
 * WordPress-Style Background Job Manager Class
 * Following WordPress admin design conventions
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Background_Job_Manager {

    private $jobs_table;
    private $table_version = '1.3';

    const CRON_HOOK = 'sfaic_process_background_job';
    const CRON_INTERVAL = 30;
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_RETRY = 'retry';
    const MAX_RETRIES = 3;
    const MAX_PROCESSING_TIME = 10;

    public function __construct() {
        global $wpdb;
        $this->jobs_table = $wpdb->prefix . 'sfaic_background_jobs';

        $this->init_hooks();
        $this->ensure_table_exists();

        // Force cron processing every 30 seconds
        add_action('init', array($this, 'ensure_cron_scheduled'));

        // Add immediate processing trigger
        add_action('wp_loaded', array($this, 'maybe_process_immediately'));
    }

    /**
     * Ensure cron is always scheduled
     */
    public function ensure_cron_scheduled() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            error_log('SFAIC: No cron scheduled, scheduling now');
            wp_schedule_event(time() + 30, 'every_30_seconds', self::CRON_HOOK);
        }
    }

    /**
     * Process jobs immediately if cron is not working
     */
    public function maybe_process_immediately() {
        // Only run on admin requests to avoid frontend impact
        
        // Check if there are pending jobs and if cron hasn't run recently
        global $wpdb;

        $pending_jobs = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->jobs_table} 
             WHERE status = %s 
             AND scheduled_at <= %s",
                        self::STATUS_PENDING,
                        current_time('mysql')
        ));

        if ($pending_jobs > 0) {
            $last_cron_run = get_option('sfaic_last_cron_run', 0);
            $time_since_last_run = time() - $last_cron_run;

            // If no cron run in 2 minutes, process immediately
            if ($time_since_last_run > 120) {
                error_log('SFAIC: Cron not running, processing immediately');
                $this->process_background_job();
            }
        }
    }

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

        // Debug actions
        add_action('wp_ajax_sfaic_debug_cron', array($this, 'ajax_debug_cron'));
        add_action('wp_ajax_sfaic_force_process_jobs', array($this, 'ajax_force_process_jobs'));
        add_action('wp_ajax_sfaic_cleanup_stuck_jobs', array($this, 'ajax_cleanup_stuck_jobs'));

        // Cleanup old jobs daily
        if (!wp_next_scheduled('sfaic_cleanup_old_jobs')) {
            wp_schedule_event(time(), 'daily', 'sfaic_cleanup_old_jobs');
        }
        add_action('sfaic_cleanup_old_jobs', array($this, 'cleanup_old_jobs'));

        // More frequent stuck job cleanup
        if (!wp_next_scheduled('sfaic_cleanup_stuck_jobs_periodic')) {
            wp_schedule_event(time(), 'every_30_seconds', 'sfaic_cleanup_stuck_jobs_periodic');
        }
        add_action('sfaic_cleanup_stuck_jobs_periodic', array($this, 'cleanup_stuck_jobs_periodic'));

        // Enqueue scripts for job monitoring
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_cron_schedules($schedules) {
        $schedules['every_30_seconds'] = array(
            'interval' => 30,
            'display' => __('Every 30 Seconds', 'chatgpt-fluent-connector')
        );

        $schedules['every_2_minutes'] = array(
            'interval' => 60,
            'display' => __('Every 1 Minutes', 'chatgpt-fluent-connector')
        );

        return $schedules;
    }

    /**
     * Simplified and more reliable job scheduling
     */
    public function schedule_job($job_type, $prompt_id, $form_id, $entry_id, $job_data, $delay = 0, $priority = 0) {
        
        if (!$this->is_background_processing_enabled()) {
            error_log('SFAIC: Background processing disabled, processing immediately');
            return $this->process_job_immediately($job_type, $prompt_id, $form_id, $entry_id, $job_data);
        }

        $this->ensure_table_exists();

        global $wpdb;

        $current_timestamp = current_time('timestamp');
        $scheduled_time = date('Y-m-d H:i:s', $current_timestamp + $delay);
        $created_time = current_time('mysql');

        // Extract user name and email from job data
        //$user_name = $this->extract_user_name($job_data['form_data'] ?? array());
        //$user_email = $this->extract_user_email($job_data['form_data'] ?? array());
        $user_name = $this->extract_user_name($job_data['form_data'] ?? array(), $prompt_id);
        $user_email = $this->extract_user_email($job_data['form_data'] ?? array(), $prompt_id);

        $job_data_json = wp_json_encode($job_data);

        error_log('SFAIC: Scheduling job - User: ' . $user_name . ' (' . $user_email . '), Scheduled: ' . $scheduled_time);
        
        $tracking_source = '';
        if (isset($job_data['form_data']['_tracking_source'])) {
            $tracking_source = $job_data['form_data']['_tracking_source'];
        }
        
        
        $result = $wpdb->insert(
                $this->jobs_table,
                array(
                    'job_type' => $job_type,
                    'prompt_id' => $prompt_id,
                    'form_id' => $form_id,
                    'entry_id' => $entry_id,
                    'user_name' => $user_name,
                    'user_email' => $user_email,
                    'job_data' => $job_data_json,
                    'status' => self::STATUS_PENDING,
                    'priority' => $priority,
                    'scheduled_at' => $scheduled_time,
                    'created_at' => $created_time,
                    'updated_at' => $created_time
                ),
                array('%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );

        if ($result === false) {
            error_log('SFAIC: FAILED to schedule background job: ' . $wpdb->last_error);
            return $this->process_job_immediately($job_type, $prompt_id, $form_id, $entry_id, $job_data);
        }

        $job_id = $wpdb->insert_id;
        error_log('SFAIC: Job inserted with ID: ' . $job_id);

        // Always schedule cron event
        $this->schedule_cron_event_reliable($delay);

        return $job_id;
    }

    /**
     * Simplified and reliable cron scheduling
     */
    private function schedule_cron_event_reliable($delay = 0) {
        $timestamp = time() + max($delay, 5); // Minimum 5 second delay
        // Always schedule a new event - WordPress will handle duplicates
        $result = wp_schedule_single_event($timestamp, self::CRON_HOOK);

        if ($result === false) {
            error_log('SFAIC: FAILED to schedule cron event, trying immediate processing');
            // If scheduling fails, try immediate processing in background
            add_action('shutdown', array($this, 'emergency_process_jobs'));
        } else {
            error_log('SFAIC: Successfully scheduled cron event for ' . date('Y-m-d H:i:s', $timestamp));
        }

        // Ensure recurring cron is always active
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'every_30_seconds', self::CRON_HOOK);
        }
    }

    /**
     * Emergency processing method
     */
    public function emergency_process_jobs() {
        if (defined('DOING_CRON') || headers_sent()) {
            return;
        }

        error_log('SFAIC: Emergency processing triggered');

        // Process in background using wp_remote_post
        wp_remote_post(admin_url('admin-ajax.php'), array(
            'timeout' => 0.01,
            'blocking' => false,
            'body' => array(
                'action' => 'sfaic_force_process_jobs',
                'nonce' => wp_create_nonce('sfaic_jobs_nonce')
            )
        ));
    }

    /**
     * Completely rewritten job processing method
     */
    public function process_background_job() {
        update_option('sfaic_last_cron_run', time());

        error_log('SFAIC: Background job processor triggered at ' . date('Y-m-d H:i:s'));

        // Reset stuck jobs first
        $this->reset_stuck_processing_jobs();

        // Simple processing check
        if (!$this->should_process_jobs_simple()) {
            error_log('SFAIC: Job processing conditions not met');
            return;
        }

        global $wpdb;

        // Get the next job to process (simplified query)
        $job = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$this->jobs_table} 
             WHERE status = %s 
             AND scheduled_at <= %s 
             ORDER BY priority DESC, created_at ASC 
             LIMIT 1",
                        self::STATUS_PENDING,
                        current_time('mysql')
        ));

        if (!$job) {
            error_log('SFAIC: No pending jobs found');
            $this->schedule_next_job_if_needed();
            return;
        }

        error_log('SFAIC: Processing job ID: ' . $job->id . ' for user: ' . ($job->user_name ?: 'Unknown'));

        // Update job status to processing
        $update_result = $wpdb->update(
                $this->jobs_table,
                array(
                    'status' => self::STATUS_PROCESSING,
                    'started_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $job->id),
                array('%s', '%s', '%s'),
                array('%d')
        );

        if ($update_result === false) {
            error_log('SFAIC: Failed to update job status to processing');
            return;
        }

        try {
            // Process the job
            $success = $this->execute_job($job);

            if ($success) {
                $this->mark_job_completed($job->id);
                error_log('SFAIC: Job ' . $job->id . ' completed successfully');
            } else {
                $this->handle_job_failure($job, 'Job execution returned false');
            }
        } catch (Exception $e) {
            error_log('SFAIC: Job execution exception: ' . $e->getMessage());
            $this->handle_job_failure($job, $e->getMessage());
        } catch (Error $e) {
            error_log('SFAIC: Job execution fatal error: ' . $e->getMessage());
            $this->handle_job_failure($job, 'Fatal error: ' . $e->getMessage());
        }

        // Always schedule next processing
        $this->schedule_next_job_if_needed();
    }

    /**
     * Simplified processing check
     */
    private function should_process_jobs_simple() {
        if (!$this->is_background_processing_enabled()) {
            return false;
        }

        global $wpdb;

        // Check for too many concurrent jobs
        $processing_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->jobs_table} WHERE status = %s",
                        self::STATUS_PROCESSING
        ));

        return $processing_count < 3; // Allow up to 3 concurrent jobs
    }

    /**
     * More aggressive stuck job reset
     */
    private function reset_stuck_processing_jobs() {
        global $wpdb;

        $timeout_minutes = self::MAX_PROCESSING_TIME;

        $stuck_jobs = $wpdb->query($wpdb->prepare(
                        "UPDATE {$this->jobs_table} 
             SET status = %s, 
                 error_message = CONCAT(COALESCE(error_message, ''), ' [Reset: was stuck in processing]'),
                 updated_at = %s
             WHERE status = %s 
             AND (started_at IS NULL OR started_at < DATE_SUB(NOW(), INTERVAL %d MINUTE))",
                        self::STATUS_PENDING,
                        current_time('mysql'),
                        self::STATUS_PROCESSING,
                        $timeout_minutes
        ));

        if ($wpdb->rows_affected > 0) {
            error_log('SFAIC: Reset ' . $wpdb->rows_affected . ' stuck processing jobs to pending');
        }

        // Also reset very old pending jobs (older than 1 hour)
        $very_old_jobs = $wpdb->query($wpdb->prepare(
                        "UPDATE {$this->jobs_table} 
             SET error_message = CONCAT(COALESCE(error_message, ''), ' [Reset: was pending too long]'),
                 updated_at = %s,
                 scheduled_at = %s
             WHERE status = %s 
             AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                        current_time('mysql'),
                        current_time('mysql'), // Reschedule for immediate processing
                        self::STATUS_PENDING
        ));

        if ($wpdb->rows_affected > 0) {
            error_log('SFAIC: Reset ' . $wpdb->rows_affected . ' very old pending jobs');
        }
    }

    /**
     * Simplified next job scheduling
     */
    private function schedule_next_job_if_needed() {
        global $wpdb;

        // Check for more pending jobs
        $pending_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->jobs_table} 
             WHERE status = %s AND scheduled_at <= %s",
                        self::STATUS_PENDING,
                        current_time('mysql')
        ));

        if ($pending_count > 0) {
            error_log('SFAIC: Found ' . $pending_count . ' more pending jobs, scheduling processing');
            $this->schedule_cron_event_reliable(5);
        }
    }

    private function mark_job_completed($job_id) {
        global $wpdb;

        $wpdb->update(
                $this->jobs_table,
                array(
                    'status' => self::STATUS_COMPLETED,
                    'completed_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $job_id),
                array('%s', '%s', '%s'),
                array('%d')
        );
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
        error_log('SFAIC: Processing AI form job ID ' . $job->id . ' for prompt ' . $job->prompt_id);

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

        // Call the process_prompt method directly
        $result = sfaic_main()->fluent_integration->process_prompt(
                $job->prompt_id,
                $job_data['form_data'],
                $job->entry_id,
                $form
        );

        return $result;
    }

    /**
     * Handle job failure with proper retry logic
     */
    private function handle_job_failure($job, $error_message = '') {
        global $wpdb;

        $retry_count = intval($job->retry_count) + 1;
        $max_retries = intval($job->max_retries);

        if ($retry_count <= $max_retries) {
            // Schedule retry with exponential backoff
            $delay = min(pow(2, $retry_count) * 60, 600); // Max 10 minutes
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

            // Schedule retry processing
            $this->schedule_cron_event_reliable($delay);

            error_log("SFAIC: Job {$job->id} failed, scheduled retry #{$retry_count} in {$delay} seconds");
        } else {
            // Mark as failed permanently
            $wpdb->update(
                    $this->jobs_table,
                    array(
                        'status' => self::STATUS_FAILED,
                        'completed_at' => current_time('mysql'),
                        'error_message' => $error_message,
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $job->id),
                    array('%s', '%s', '%s', '%s'),
                    array('%d')
            );

            error_log("SFAIC: Job {$job->id} failed permanently after {$max_retries} retries");
        }
    }

    /**
     * Process job immediately as fallback
     */
    private function process_job_immediately($job_type, $prompt_id, $form_id, $entry_id, $job_data) {
        error_log('SFAIC: Processing job immediately as fallback');

        try {
            $job = (object) array(
                        'id' => 0,
                        'job_type' => $job_type,
                        'prompt_id' => $prompt_id,
                        'form_id' => $form_id,
                        'entry_id' => $entry_id,
                        'job_data' => wp_json_encode($job_data),
                        'user_name' => $this->extract_user_name($job_data['form_data'] ?? array()),
                        'user_email' => $this->extract_user_email($job_data['form_data'] ?? array()),
            );

            return $this->execute_job($job);
        } catch (Exception $e) {
            error_log('SFAIC: Immediate job processing exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Periodic cleanup of stuck jobs
     */
    public function cleanup_stuck_jobs_periodic() {
        $this->reset_stuck_processing_jobs();

        // Check if we need to schedule more processing
        global $wpdb;
        $pending_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->jobs_table} WHERE status = %s",
                        self::STATUS_PENDING
        ));

        if ($pending_count > 0) {
            $this->schedule_cron_event_reliable(5);
        }
    }

    /**
     * Extract user name from form data
     */
    private function extract_user_name($form_data, $prompt_id = null) {
        if (!is_array($form_data)) {
            return '';
        }
        // First, try to use custom field mappings from prompt settings
        if (!empty($prompt_id)) {
            $first_name_field = get_post_meta($prompt_id, '_sfaic_first_name_field', true);
            $last_name_field = get_post_meta($prompt_id, '_sfaic_last_name_field', true);

            $first_name = '';
            $last_name = '';
                       
            // Get first name from custom mapping
            if (!empty($first_name_field) && isset($form_data[$first_name_field])) {   
                 $fname=$form_data[$first_name_field];
                if(is_array($fname)){
                    $first_name = sanitize_text_field($fname['first_name']);
                }else{
                    $first_name = sanitize_text_field($form_data[$first_name_field]);
                }
            }
            
            
            // Get last name from custom mapping
            if (!empty($last_name_field) && isset($form_data[$last_name_field])) {     
                $lname=$form_data[$last_name_field];
                error_log('SFAIC:$last_name_field: ' . print_r($lname)); 
                if(is_array($lname)){
                    $last_name = sanitize_text_field($lname['last_name']);
                    error_log('SFAIC: $last_name: ' . $last_name); 
                }else{
                     $last_name = sanitize_text_field($form_data[$last_name_field]);
                }               
            }

            // If we got names from custom mappings, return them
            if (!empty($first_name) || !empty($last_name)) {
                return trim($first_name . ' ' . $last_name);
            }
        }

        // Fall back to auto-detection with common field names
        $name_fields = array(
            'name', 'full_name', 'fullname', 'user_name', 'username',
            'first_name', 'last_name', 'contact_name', 'customer_name',
            'client_name', 'your_name', 'applicant_name', 'student_name', 'Voornaam', 'Achternaam'
        );

        foreach ($name_fields as $field) {
            if (isset($form_data[$field]) && !empty($form_data[$field])) { 
                return sanitize_text_field($form_data[$field]);
            }
        }

        // Try to combine first and last name from auto-detection
        $first_name = isset($form_data['first_name']) ? sanitize_text_field($form_data['first_name']) : '';
        $last_name = isset($form_data['last_name']) ? sanitize_text_field($form_data['last_name']) : '';

        if (!empty($first_name) || !empty($last_name)) {
            return trim($first_name . ' ' . $last_name);
        }

        return '';
    }

    /**
     * Extract user email from form data
     */
    private function extract_user_email($form_data, $prompt_id = null) {
        if (!is_array($form_data)) {
            return '';
        }

        // First, try to use custom field mapping from prompt settings
        if (!empty($prompt_id)) {
            $email_field = get_post_meta($prompt_id, '_sfaic_email_field_mapping', true);

            if (!empty($email_field) && isset($form_data[$email_field])) {
                $email = sanitize_email($form_data[$email_field]);
                if (is_email($email)) {
                    return $email;
                }
            }
        }

        // Fall back to auto-detection with common field names
        $email_fields = array(
            'email', 'email_address', 'user_email', 'contact_email',
            'customer_email', 'client_email', 'your_email', 'applicant_email',
            'student_email', 'work_email', 'business_email'
        );

        foreach ($email_fields as $field) {
            if (isset($form_data[$field]) && !empty($form_data[$field])) {
                $email = sanitize_email($form_data[$field]);
                if (is_email($email)) {
                    return $email;
                }
            }
        }

        return '';
    }

    /**
     * Check if background processing is enabled
     */
    public function is_background_processing_enabled() {
        return get_option('sfaic_enable_background_processing', true);
    }

    /**
     * Ensure table exists
     */
    private function ensure_table_exists() {
        global $wpdb;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->jobs_table}'") === $this->jobs_table;
        if (!$table_exists) {
            $this->create_jobs_table();
        } else {
            $this->check_table_version();
        }
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
            user_name varchar(255) DEFAULT NULL,
            user_email varchar(255) DEFAULT NULL,
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
            KEY job_type (job_type),
            KEY user_email (user_email),
            KEY user_name (user_name)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        update_option('sfaic_jobs_table_version', $this->table_version);
    }

    public function check_table_version() {
        $current_version = get_option('sfaic_jobs_table_version', '0');
        if (version_compare($current_version, $this->table_version, '<')) {
            $this->update_table_structure();
            update_option('sfaic_jobs_table_version', $this->table_version);
        }
    }

    private function update_table_structure() {
        global $wpdb;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->jobs_table}'") === $this->jobs_table;
        if (!$table_exists) {
            $this->create_jobs_table();
            return;
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->jobs_table}", ARRAY_A);
        $column_names = array_column($columns, 'Field');

        if (!in_array('user_name', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->jobs_table} ADD `user_name` VARCHAR(255) DEFAULT NULL AFTER `entry_id`");
        }

        if (!in_array('user_email', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->jobs_table} ADD `user_email` VARCHAR(255) DEFAULT NULL AFTER `user_name`");
        }
    }

    /**
     * Get job statistics with proper data formatting
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

        // Ensure all values are integers
        if ($stats) {
            $stats->total_jobs = intval($stats->total_jobs);
            $stats->pending_jobs = intval($stats->pending_jobs);
            $stats->processing_jobs = intval($stats->processing_jobs);
            $stats->completed_jobs = intval($stats->completed_jobs);
            $stats->failed_jobs = intval($stats->failed_jobs);
            $stats->retry_jobs = intval($stats->retry_jobs);
        } else {
            // Return default stats if no data
            $stats = (object) array(
                        'total_jobs' => 0,
                        'pending_jobs' => 0,
                        'processing_jobs' => 0,
                        'completed_jobs' => 0,
                        'failed_jobs' => 0,
                        'retry_jobs' => 0
            );
        }

        return $stats;
    }

    /**
     * Get recent jobs with proper formatting
     */
    public function get_recent_jobs_enhanced($limit = 50) {
        global $wpdb;

        $jobs = $wpdb->get_results($wpdb->prepare(
                        "SELECT j.*, p.post_title as prompt_title 
             FROM {$this->jobs_table} j
             LEFT JOIN {$wpdb->posts} p ON j.prompt_id = p.ID
             ORDER BY j.created_at DESC 
             LIMIT %d",
                        $limit
        ));

        // Process and format each job
        $formatted_jobs = array();

        foreach ($jobs as $job) {
            $formatted_job = array(
                'id' => intval($job->id),
                'job_type' => sanitize_text_field($job->job_type),
                'prompt_id' => intval($job->prompt_id),
                'form_id' => intval($job->form_id),
                'entry_id' => intval($job->entry_id),
                'user_name' => sanitize_text_field($job->user_name ?: ''),
                'user_email' => sanitize_email($job->user_email ?: ''),
                'status' => sanitize_text_field($job->status),
                'priority' => intval($job->priority),
                'retry_count' => intval($job->retry_count),
                'max_retries' => intval($job->max_retries),
                'error_message' => sanitize_textarea_field($job->error_message ?: ''),
                'scheduled_at' => $job->scheduled_at,
                'started_at' => $job->started_at,
                'completed_at' => $job->completed_at,
                'created_at' => $job->created_at,
                'updated_at' => $job->updated_at,
                'prompt_title' => sanitize_text_field($job->prompt_title ?: '')
            );

            $formatted_jobs[] = $formatted_job;
        }

        return $formatted_jobs;
    }

    public function get_recent_jobs($limit = 50) {
        return $this->get_recent_jobs_enhanced($limit);
    }

    public function cleanup_old_jobs() {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$this->jobs_table} 
             WHERE status = %s 
             AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
                        self::STATUS_COMPLETED
        ));
        $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$this->jobs_table} 
             WHERE status = %s 
             AND completed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
                        self::STATUS_FAILED
        ));
    }

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
     * WordPress-style jobs page rendering
     */
    public function render_jobs_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        try {
            $stats = $this->get_job_statistics();
            $recent_jobs = $this->get_recent_jobs_enhanced();
            $background_enabled = $this->is_background_processing_enabled();

            // Check for any prompts with background processing enabled
            $prompts_with_bg = get_posts(array(
                'post_type' => 'sfaic_prompt',
                'meta_query' => array(
                    array(
                        'key' => '_sfaic_enable_background_processing',
                        'value' => '1',
                        'compare' => '='
                    )
                ),
                'posts_per_page' => 1,
                'fields' => 'ids'
            ));

            $has_bg_prompts = !empty($prompts_with_bg);
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline"><?php _e('Background Jobs', 'chatgpt-fluent-connector'); ?></h1>
                <hr class="wp-header-end">

                <!-- System Status -->
                <div class="sfaic-system-status">
                    <p>
                        <strong><?php _e('System Status:', 'chatgpt-fluent-connector'); ?></strong>
                        <?php _e('WP Cron:', 'chatgpt-fluent-connector'); ?> 
                        <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
                            <span class="status-indicator disabled"></span><span style="color: #d63638;"><?php _e('Disabled', 'chatgpt-fluent-connector'); ?></span>
                        <?php else: ?>
                            <span class="status-indicator enabled"></span><span style="color: #00a32a;"><?php _e('Enabled', 'chatgpt-fluent-connector'); ?></span>
                        <?php endif; ?> | 

                        <?php _e('Next Scheduled:', 'chatgpt-fluent-connector'); ?> 
                        <?php
                        $next = wp_next_scheduled(self::CRON_HOOK);
                        if ($next) {
                            echo '<span style="color: #00a32a;">' . date('H:i:s', $next) . '</span>';
                        } else {
                            echo '<span style="color: #d63638;">' . __('None', 'chatgpt-fluent-connector') . '</span>';
                        }
                        ?> | 

                        <?php _e('Background Processing:', 'chatgpt-fluent-connector'); ?> 
                        <?php if ($background_enabled): ?>
                            <span class="status-indicator enabled"></span><span style="color: #00a32a;"><?php _e('Available', 'chatgpt-fluent-connector'); ?></span>
                        <?php else: ?>
                            <span class="status-indicator disabled"></span><span style="color: #dba617;"><?php _e('Disabled', 'chatgpt-fluent-connector'); ?></span>
                        <?php endif; ?> | 

                        <?php _e('Active Prompts:', 'chatgpt-fluent-connector'); ?> 
                        <?php if ($has_bg_prompts): ?>
                            <span class="status-indicator enabled"></span><span style="color: #00a32a;"><?php echo count($prompts_with_bg); ?></span>
                        <?php else: ?>
                            <span class="status-indicator disabled"></span><span style="color: #dba617;"><?php _e('None', 'chatgpt-fluent-connector'); ?></span>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Job Statistics -->
                <div class="sfaic-jobs-overview">
                    <h2><?php _e('Job Statistics (Last 24 Hours)', 'chatgpt-fluent-connector'); ?></h2>
                    <div class="sfaic-stats-grid">
                        <div class="sfaic-stat-card total">
                            <div class="stat-number"><?php echo esc_html($stats->total_jobs); ?></div>
                            <div class="stat-label"><?php _e('Total Jobs', 'chatgpt-fluent-connector'); ?></div>
                        </div>
                        <div class="sfaic-stat-card pending">
                            <div class="stat-number"><?php echo esc_html($stats->pending_jobs); ?></div>
                            <div class="stat-label"><?php _e('Pending', 'chatgpt-fluent-connector'); ?></div>
                        </div>
                        <div class="sfaic-stat-card processing">
                            <div class="stat-number"><?php echo esc_html($stats->processing_jobs); ?></div>
                            <div class="stat-label"><?php _e('Processing', 'chatgpt-fluent-connector'); ?></div>
                        </div>
                        <div class="sfaic-stat-card completed">
                            <div class="stat-number"><?php echo esc_html($stats->completed_jobs); ?></div>
                            <div class="stat-label"><?php _e('Completed', 'chatgpt-fluent-connector'); ?></div>
                        </div>
                        <div class="sfaic-stat-card failed">
                            <div class="stat-number"><?php echo esc_html($stats->failed_jobs); ?></div>
                            <div class="stat-label"><?php _e('Failed', 'chatgpt-fluent-connector'); ?></div>
                        </div>
                        <div class="sfaic-stat-card retry">
                            <div class="stat-number"><?php echo esc_html($stats->retry_jobs); ?></div>
                            <div class="stat-label"><?php _e('Retrying', 'chatgpt-fluent-connector'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="sfaic-job-actions">
                    <button type="button" id="sfaic-refresh-jobs" class="button">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Refresh', 'chatgpt-fluent-connector'); ?>
                    </button>
                    <button type="button" id="sfaic-force-process" class="button button-primary">
                        <span class="dashicons dashicons-controls-play"></span>
                        <?php _e('Force Process Jobs', 'chatgpt-fluent-connector'); ?>
                    </button>
                    <button type="button" id="sfaic-cleanup-stuck" class="button">
                        <span class="dashicons dashicons-undo"></span>
                        <?php _e('Reset Stuck Jobs', 'chatgpt-fluent-connector'); ?>
                    </button>
                    <button type="button" id="sfaic-cleanup-jobs" class="button">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Cleanup Old Jobs', 'chatgpt-fluent-connector'); ?>
                    </button>
                </div>

                <!-- Jobs Table WordPress Style -->
                <div class="sfaic-jobs-table-wrapper">
                    <div class="tablenav top">
                        <h3><?php _e('Recent Jobs', 'chatgpt-fluent-connector'); ?></h3>
                    </div>

                    <?php if (!empty($recent_jobs)): ?>
                        <table class="wp-list-table widefat fixed striped sfaic-jobs-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="manage-column column-id"><?php _e('ID', 'chatgpt-fluent-connector'); ?></th>
                                    <th scope="col" class="manage-column column-type"><?php _e('Type', 'chatgpt-fluent-connector'); ?></th>
                                    <th scope="col" class="manage-column column-name"><?php _e('Name', 'chatgpt-fluent-connector'); ?></th>
                                    <th scope="col" class="manage-column column-email"><?php _e('Email 1', 'chatgpt-fluent-connector'); ?></th>
                                    <th scope="col" class="manage-column column-status"><?php _e('Status', 'chatgpt-fluent-connector'); ?></th>
                                    <th scope="col" class="manage-column column-date"><?php _e('Created', 'chatgpt-fluent-connector'); ?></th>
                                    <th scope="col" class="manage-column column-actions"><?php _e('Actions', 'chatgpt-fluent-connector'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="sfaic-jobs-tbody">
                                <?php foreach ($recent_jobs as $index => $job): ?>
                                    <tr class="<?php echo ($index % 2 === 1) ? 'alternate' : ''; ?>" data-job-id="<?php echo esc_attr($job['id']); ?>">
                                        <td class="column-id" data-label="ID">
                                            <strong><?php echo esc_html($job['id']); ?></strong>
                                        </td>
                                        <td class="column-type" data-label="Type">
                                            <?php echo esc_html($job['job_type']); ?>
                                        </td>
                                        <td class="column-name" data-label="Name">
                                            <?php echo esc_html($job['user_name'] ?: '-'); ?>
                                        </td>
                                        <td class="column-email" data-label="Email">
                                            <?php if ($job['user_email']): ?>
                                                <a href="mailto:<?php echo esc_attr($job['user_email']); ?>"><?php echo esc_html($job['user_email']); ?></a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-status" data-label="Status">
                                            <span class="status-badge status-<?php echo esc_attr($job['status']); ?>">
                                                <?php echo esc_html(ucfirst($job['status'])); ?>
                                            </span>
                                            <?php if ($job['status'] === 'pending'): ?>
                                                <?php
                                                $age_minutes = (time() - strtotime($job['created_at'])) / 60;
                                                if ($age_minutes > 5):
                                                    ?>
                                                    <br><small style="color: #d63638;">⚠️ <?php echo round($age_minutes); ?>min old</small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-date" data-label="Created">
                                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($job['created_at']))); ?>
                                        </td>
                                        <td class="column-actions" data-label="Actions">
                                            <?php if (in_array($job['status'], array('failed', 'retry'))): ?>
                                                <button type="button" class="button button-small sfaic-retry-job" data-job-id="<?php echo esc_attr($job['id']); ?>">
                                                    <?php _e('Retry', 'chatgpt-fluent-connector'); ?>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($job['status'] === 'pending'): ?>
                                                <button type="button" class="button button-small sfaic-cancel-job" data-job-id="<?php echo esc_attr($job['id']); ?>">
                                                    <?php _e('Cancel', 'chatgpt-fluent-connector'); ?>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if (!empty($job['error_message'])): ?>
                                        <tr class="job-error-row" style="display: none;" id="error-<?php echo esc_attr($job['id']); ?>">
                                            <td colspan="7">
                                                <div class="error-message">
                                                    <strong><?php _e('Error:', 'chatgpt-fluent-connector'); ?></strong> 
                                                    <?php echo esc_html($job['error_message']); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="sfaic-no-jobs">
                            <span class="dashicons dashicons-admin-post"></span>
                            <h3><?php _e('No jobs found', 'chatgpt-fluent-connector'); ?></h3>
                            <p><?php _e('Background jobs will appear here when forms are submitted.', 'chatgpt-fluent-connector'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        } catch (Exception $e) {
            echo '<div class="wrap">';
            echo '<h1>' . __('Background Jobs', 'chatgpt-fluent-connector') . '</h1>';
            echo '<div class="notice notice-error"><p>' . sprintf(__('Error loading jobs page: %s', 'chatgpt-fluent-connector'), esc_html($e->getMessage())) . '</p></div>';
            echo '</div>';

            error_log('SFAIC: Error in render_jobs_page: ' . $e->getMessage());
        }
    }

    // AJAX handlers with enhanced error handling
    public function ajax_get_job_status() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_jobs_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        try {
            $stats = $this->get_job_statistics();
            $recent_jobs = $this->get_recent_jobs_enhanced(50);

            $response_data = array(
                'stats' => $stats,
                'jobs' => $recent_jobs,
                'timestamp' => current_time('timestamp'),
                'server_time' => current_time('mysql')
            );

            wp_send_json_success($response_data);
        } catch (Exception $e) {
            error_log('SFAIC: Error in ajax_get_job_status: ' . $e->getMessage());
            wp_send_json_error('Failed to get job status: ' . $e->getMessage());
        }
    }

    public function ajax_retry_job() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_jobs_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        if (!isset($_POST['job_id']) || !is_numeric($_POST['job_id'])) {
            wp_send_json_error('Invalid job ID');
            return;
        }

        $job_id = intval($_POST['job_id']);

        try {
            global $wpdb;

            $job = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$this->jobs_table} WHERE id = %d",
                            $job_id
            ));

            if (!$job) {
                wp_send_json_error('Job not found');
                return;
            }

            if (!in_array($job->status, array('failed', 'retry'))) {
                wp_send_json_error('Job cannot be retried in current status: ' . $job->status);
                return;
            }

            $result = $wpdb->update(
                    $this->jobs_table,
                    array(
                        'status' => self::STATUS_PENDING,
                        'retry_count' => 0,
                        'scheduled_at' => current_time('mysql'),
                        'started_at' => null,
                        'error_message' => '',
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $job_id),
                    array('%s', '%d', '%s', '%s', '%s', '%s'),
                    array('%d')
            );

            if ($result !== false) {
                $this->schedule_cron_event_reliable(5);
                wp_send_json_success('Job scheduled for retry');
            } else {
                wp_send_json_error('Failed to update job in database');
            }
        } catch (Exception $e) {
            error_log('SFAIC: Error in ajax_retry_job: ' . $e->getMessage());
            wp_send_json_error('Database error occurred');
        }
    }

    public function ajax_cancel_job() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_jobs_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        if (!isset($_POST['job_id']) || !is_numeric($_POST['job_id'])) {
            wp_send_json_error('Invalid job ID');
            return;
        }

        $job_id = intval($_POST['job_id']);

        try {
            global $wpdb;

            $job = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$this->jobs_table} WHERE id = %d",
                            $job_id
            ));

            if (!$job) {
                wp_send_json_error('Job not found');
                return;
            }

            if (!in_array($job->status, array('pending', 'retry'))) {
                wp_send_json_error('Job cannot be cancelled in current status: ' . $job->status);
                return;
            }

            $result = $wpdb->update(
                    $this->jobs_table,
                    array(
                        'status' => self::STATUS_FAILED,
                        'error_message' => 'Cancelled by administrator',
                        'completed_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $job_id),
                    array('%s', '%s', '%s', '%s'),
                    array('%d')
            );

            if ($result !== false) {
                wp_send_json_success('Job cancelled successfully');
            } else {
                wp_send_json_error('Failed to cancel job in database');
            }
        } catch (Exception $e) {
            error_log('SFAIC: Error in ajax_cancel_job: ' . $e->getMessage());
            wp_send_json_error('Database error occurred');
        }
    }

    public function ajax_cleanup_jobs() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_jobs_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        try {
            global $wpdb;

            $completed_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$this->jobs_table} 
                 WHERE status = %s 
                 AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
                            self::STATUS_COMPLETED
            ));

            $failed_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$this->jobs_table} 
                 WHERE status = %s 
                 AND completed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
                            self::STATUS_FAILED
            ));

            $this->cleanup_old_jobs();

            $total_cleaned = intval($completed_count) + intval($failed_count);

            wp_send_json_success("Cleaned up {$total_cleaned} old jobs");
        } catch (Exception $e) {
            error_log('SFAIC: Error in ajax_cleanup_jobs: ' . $e->getMessage());
            wp_send_json_error('Failed to cleanup jobs');
        }
    }

    public function ajax_debug_cron() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_jobs_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        try {
            global $wpdb;

            $cron_info = array();
            $cron_info['wp_cron_disabled'] = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

            $next_scheduled = wp_next_scheduled(self::CRON_HOOK);
            $cron_info['next_scheduled'] = $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'None';

            $cron_info['pending_jobs'] = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$this->jobs_table} WHERE status = %s",
                                    self::STATUS_PENDING
                            )) ?: 0;

            $cron_info['processing_jobs'] = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$this->jobs_table} WHERE status = %s",
                                    self::STATUS_PROCESSING
                            )) ?: 0;

            $cron_info['stuck_jobs'] = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$this->jobs_table} 
                 WHERE (status = %s AND started_at < DATE_SUB(NOW(), INTERVAL %d MINUTE))
                 OR (status = %s AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))",
                                    self::STATUS_PROCESSING,
                                    self::MAX_PROCESSING_TIME,
                                    self::STATUS_PENDING
                            )) ?: 0;

            $cron_info['server_time'] = current_time('mysql');
            $cron_info['wp_timezone'] = wp_timezone_string();
            $cron_info['last_cron_run'] = get_option('sfaic_last_cron_run', 0);

            if ($cron_info['last_cron_run'] > 0) {
                $cron_info['last_cron_run_formatted'] = date('Y-m-d H:i:s', $cron_info['last_cron_run']);
                $cron_info['minutes_since_last_run'] = round((time() - $cron_info['last_cron_run']) / 60, 1);
            }

            $cron_info['available_schedules'] = wp_get_schedules();

            wp_send_json_success($cron_info);
        } catch (Exception $e) {
            error_log('SFAIC: Error in ajax_debug_cron: ' . $e->getMessage());
            wp_send_json_error('Failed to get debug information');
        }
    }

    public function ajax_force_process_jobs() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_jobs_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        try {
            $this->reset_stuck_processing_jobs();
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $result = $this->process_background_job();
            $this->schedule_cron_event_reliable(5);

            wp_send_json_success('Background job processing triggered');
        } catch (Exception $e) {
            error_log('SFAIC: Error in ajax_force_process_jobs: ' . $e->getMessage());
            wp_send_json_error('Failed to force process jobs: ' . $e->getMessage());
        }
    }

    public function ajax_cleanup_stuck_jobs() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_jobs_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        try {
            global $wpdb;

            $stuck_processing = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$this->jobs_table} 
                 WHERE status = %s 
                 AND started_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
                            self::STATUS_PROCESSING,
                            self::MAX_PROCESSING_TIME
            ));

            $old_pending = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$this->jobs_table} 
                 WHERE status = %s 
                 AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
                            self::STATUS_PENDING
            ));

            $this->reset_stuck_processing_jobs();
            $this->schedule_cron_event_reliable(0);

            $total_reset = intval($stuck_processing) + intval($old_pending);

            wp_send_json_success("Reset {$total_reset} stuck jobs ({$stuck_processing} processing, {$old_pending} old pending)");
        } catch (Exception $e) {
            error_log('SFAIC: Error in ajax_cleanup_stuck_jobs: ' . $e->getMessage());
            wp_send_json_error('Failed to cleanup stuck jobs: ' . $e->getMessage());
        }
    }
}
