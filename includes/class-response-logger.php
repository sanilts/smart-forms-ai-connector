<?php

/**
 * Enhanced Response Logger Class with Token Tracking, Restart Functionality, and User Name/Email
 */
class SFAIC_Response_Logger {

    /**
     * Table name
     */
    private $table_name;

    /**
     * Table version - Incremented for new columns
     */
    private $table_version = '1.6';

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sfaic_response_logs';

        // Add admin submenu for logs
        add_action('admin_menu', array($this, 'add_logs_submenu'));

        // Make sure table exists AND is up to date
        $this->ensure_table_exists();

        // Check if table needs to be updated - run this early
        add_action('init', array($this, 'check_and_update_table'), 1);

        // Add assets for the logs page
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Add AJAX handlers for JSON downloads
        add_action('wp_ajax_sfaic_download_request_json', array($this, 'ajax_download_request_json'));
        add_action('wp_ajax_sfaic_download_response_json', array($this, 'ajax_download_response_json'));

        // Add AJAX handler for restart functionality
        add_action('wp_ajax_sfaic_restart_ai_process', array($this, 'ajax_restart_ai_process'));
    }

    /**
     * Extract user name from form data using prompt-specific field mappings
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
                $fname = $form_data[$first_name_field];
                if (is_array($fname)) {
                    $first_name = sanitize_text_field($fname['first_name']);
                } else {
                    $first_name = sanitize_text_field($form_data[$first_name_field]);
                }
            }


            // Get last name from custom mapping
            if (!empty($last_name_field) && isset($form_data[$last_name_field])) {
                $lname = $form_data[$last_name_field];
                error_log('SFAIC:$last_name_field: ' . print_r($lname));
                if (is_array($lname)) {
                    $last_name = sanitize_text_field($lname['last_name']);
                    error_log('SFAIC: $last_name: ' . $last_name);
                } else {
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
     * Extract user email from form data using prompt-specific field mappings
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
     * Check and update table structure if needed
     */
    public function check_and_update_table() {
        $current_version = get_option('sfaic_logs_table_version', '1.0');

        // Force update if version is less than required
        if (version_compare($current_version, $this->table_version, '<')) {
            $this->update_table_structure();
            update_option('sfaic_logs_table_version', $this->table_version);
            error_log('SFAIC: check_and_update_table');
        }else{
             error_log('SFAIC: check_and_update_table error'.$current_version);
        }
    }

    /**
     * Check if a column exists in the table
     */
    private function column_exists($column_name) {
        global $wpdb;
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name}", ARRAY_A);
        $column_names = array_column($columns, 'Field');
        return in_array($column_name, $column_names);
    }

    /**
     * Update table structure to the latest version
     */
    public function update_table_structure() {
        global $wpdb;

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
            // If table doesn't exist, create it with the new structure
            $this->create_logs_table();
            return;
        }

        // Check if columns already exist to avoid errors
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name}", ARRAY_A);
        $column_names = array_column($columns, 'Field');

        // Add tracking_source column if it doesn't exist
        if (!in_array('tracking_source', $column_names)) {
            $result = $wpdb->query("ALTER TABLE {$this->table_name} ADD `tracking_source` VARCHAR(255) DEFAULT NULL AFTER `user_email`");
            if ($result === false) {
                error_log('SFAIC: Failed to add tracking_source column: ' . $wpdb->last_error);
            } else {
                error_log('SFAIC: Successfully added tracking_source column to response logs table');
            }
        }

        // Add user name and email columns if they don't exist
        if (!in_array('user_name', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `user_name` VARCHAR(255) DEFAULT NULL AFTER `entry_id`");
        }

        if (!in_array('user_email', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `user_email` VARCHAR(255) DEFAULT NULL AFTER `user_name`");
        }

        // Add other missing columns
        if (!in_array('prompt_template', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `prompt_template` TEXT AFTER `user_prompt`");
        }

        if (!in_array('request_json', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `request_json` LONGTEXT AFTER `error_message`");
        }

        if (!in_array('response_json', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `response_json` LONGTEXT AFTER `request_json`");
        }

        if (!in_array('status', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `status` VARCHAR(50) NOT NULL DEFAULT 'success' AFTER `ai_response`");
        }

        if (!in_array('provider', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `provider` VARCHAR(50) NOT NULL DEFAULT 'openai' AFTER `status`");
        }

        if (!in_array('error_message', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `error_message` TEXT AFTER `provider`");
        }

        if (!in_array('prompt_title', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `prompt_title` VARCHAR(255) AFTER `prompt_id`");
        }

        if (!in_array('model', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `model` VARCHAR(100) AFTER `provider`");
        }

        if (!in_array('execution_time', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `execution_time` FLOAT AFTER `model`");
        }

        // Add token tracking columns
        if (!in_array('prompt_tokens', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `prompt_tokens` INT DEFAULT NULL AFTER `execution_time`");
        }

        if (!in_array('completion_tokens', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `completion_tokens` INT DEFAULT NULL AFTER `prompt_tokens`");
        }

        if (!in_array('total_tokens', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `total_tokens` INT DEFAULT NULL AFTER `completion_tokens`");
        }
    }

    /**
     * Create logs table with enhanced fields including token tracking and user name/email
     */
    public function create_logs_table() {
        global $wpdb;

        // Include WordPress upgrade functions for dbDelta
        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            prompt_id bigint(20) NOT NULL,
            prompt_title varchar(255) DEFAULT NULL,
            form_id bigint(20) NOT NULL,
            entry_id bigint(20) NOT NULL,
            user_name varchar(255) DEFAULT NULL,
            user_email varchar(255) DEFAULT NULL,
            tracking_source varchar(255) DEFAULT NULL,
            user_prompt longtext NOT NULL,
            prompt_template text DEFAULT NULL,
            ai_response longtext NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'success',
            provider varchar(50) NOT NULL DEFAULT 'openai',
            model varchar(100) DEFAULT NULL,
            execution_time float DEFAULT NULL,
            prompt_tokens int DEFAULT NULL,
            completion_tokens int DEFAULT NULL,
            total_tokens int DEFAULT NULL,
            error_message text DEFAULT NULL,
            request_json longtext DEFAULT NULL,
            response_json longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY prompt_id (prompt_id),
            KEY form_id (form_id),
            KEY entry_id (entry_id),
            KEY status (status),
            KEY created_at (created_at),
            KEY user_email (user_email),
            KEY user_name (user_name),
            KEY tracking_source (tracking_source)
        ) $charset_collate;";

        dbDelta($sql);

        // Set the table version
        update_option('sfaic_logs_table_version', $this->table_version);
    }

    private function extract_tracking_source($form_data, $prompt_id = null) {
        // Get the configured GET parameter name for this prompt
        $get_param_name = '';
        if (!empty($prompt_id)) {
            $get_param_name = get_post_meta($prompt_id, '_sfaic_tracking_get_param', true);
        }

        // If no parameter configured, return empty
        if (empty($get_param_name)) {
            $get_param_name='from';
        }
        
        return $this->extractReferrerParameter($form_data, $get_param_name);
       
    }
    
    
    private function extractReferrerParameter($data, $parameter = 'from') {
        // Check if _wp_http_referer exists
        if (!isset($data['_wp_http_referer'])) {
            return null;
        }

        // Get the referrer URL
        $referrer = $data['_wp_http_referer'];

        // Parse the URL to get the query string
        $query_string = parse_url($referrer, PHP_URL_QUERY);

        if (!$query_string) {
            return null;
        }

        // Decode HTML entities and parse the query string
        parse_str(html_entity_decode($query_string), $params);

        // Return the requested parameter value
        return $params[$parameter] ?? null;
    }

    /**
     * Log a response with enhanced details including token usage and user name/email
     */
    public function log_response($prompt_id, $entry_id, $form_id, $user_prompt, $ai_response, $provider = null, $model = '', $execution_time = null, $status = 'success', $error_message = '', $token_usage = array(), $prompt_template = '', $request_json = '', $response_json = '', $form_data = array()) {

        global $wpdb;

        // Ensure table exists before trying to insert
        $this->ensure_table_exists();

        // Extract tracking source from form data
        $tracking_source = $this->extract_tracking_source($form_data, $prompt_id);
        // Debug logging
        if (!empty($tracking_source)) {
            error_log('SFAIC Logger: Tracking source - ' . $tracking_source);
        }

        // Check for WP_Error
        $response_text = '';
        if (is_wp_error($ai_response)) {
            $status = 'error';
            if (empty($error_message)) {
                $error_message = $ai_response->get_error_message();
            }
            $response_text = ''; // Empty response for errors
        } else {
            $response_text = $ai_response;
        }

        // Get prompt title
        $prompt_title = get_the_title($prompt_id);

        // If provider is not specified, get it from the system setting
        if (empty($provider)) {
            $provider = get_option('sfaic_api_provider', 'openai');
        }

        // Get the prompt template if not provided
        if (empty($prompt_template)) {
            $template = get_post_meta($prompt_id, '_sfaic_user_prompt_template', true);
            $prompt_type = get_post_meta($prompt_id, '_sfaic_prompt_type', true);

            if ($prompt_type === 'all_form_data') {
                $prompt_template = '[All Form Data Mode]';
            } else {
                $prompt_template = $template;
            }
        }

        // Check if model is missing - use default values based on provider
        if (empty($model)) {
            switch ($provider) {
                case 'gemini':
                    $model = get_option('sfaic_gemini_model', 'gemini-pro');
                    break;
                case 'claude':
                    $model = get_option('sfaic_claude_model', 'claude-opus-4-20250514');
                    break;
                default:
                    $model = get_option('sfaic_model', 'gpt-3.5-turbo');
                    break;
            }
        }

        // If form_data is not provided, try to get it from the entry
        if (empty($form_data) && !empty($entry_id) && !empty($form_id)) {
            $retrieved_form_data = $this->get_form_data_from_entry($form_id, $entry_id);
            if (!is_wp_error($retrieved_form_data)) {
                $form_data = $retrieved_form_data;
            }
        }

        // Extract user name and email from form data
        $user_name = $this->extract_user_name($form_data, $prompt_id);
        $user_email = $this->extract_user_email($form_data, $prompt_id);
        // Debug logging
        error_log('SFAIC Logger: Extracted user info - Name: ' . $user_name . ', Email: ' . $user_email);

        // Prepare data with proper types
        $data = array(
            'prompt_id' => $prompt_id,
            'prompt_title' => $prompt_title,
            'form_id' => $form_id,
            'entry_id' => $entry_id,
            'user_name' => $user_name,
            'user_email' => $user_email,
            'tracking_source' => $tracking_source, // Add this line
            'user_prompt' => $user_prompt,
            'prompt_template' => $prompt_template,
            'ai_response' => $response_text,
            'status' => $status,
            'provider' => $provider,
            'model' => $model,
            'error_message' => $error_message,
            'request_json' => $request_json,
            'response_json' => $response_json,
            'created_at' => current_time('mysql')
        );

        // Add execution_time only if it's provided
        if ($execution_time !== null) {
            $data['execution_time'] = $execution_time;
        }

        // Add token usage if provided
        if (!empty($token_usage) && is_array($token_usage)) {
            if (isset($token_usage['prompt_tokens'])) {
                $data['prompt_tokens'] = intval($token_usage['prompt_tokens']);
            }
            if (isset($token_usage['completion_tokens'])) {
                $data['completion_tokens'] = intval($token_usage['completion_tokens']);
            }
            if (isset($token_usage['total_tokens'])) {
                $data['total_tokens'] = intval($token_usage['total_tokens']);
            }
        }

        // Insert the log
        $result = $wpdb->insert($this->table_name, $data);

        if ($result === false) {
            // Log error for debugging
            error_log('CGPTFC: Failed to log response. WP Database Error: ' . $wpdb->last_error);
            error_log('CGPTFC: Data attempted to insert: ' . print_r($data, true));
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Render logs page with user name and email display
     */
    /**
     * Enhanced Response Logger Methods for Chunking Information
     * Add these methods to your SFAIC_Response_Logger class
     */

    /**
     * Enhanced render logs page with chunking indicators
     */
    public function render_logs_page() {
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'chatgpt-fluent-connector'));
        }

        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;

        // Check if tracking_source column exists
        $has_tracking_column = false;
        if ($table_exists) {
            $has_tracking_column = $this->column_exists('tracking_source');
        }

        // Process view log details action
        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['log_id'])) {
            $this->render_log_details((int) $_GET['log_id']);
            return;
        }

        // Get filters (existing filter code...)
        $filters = array();
        if (isset($_GET['prompt_id']) && !empty($_GET['prompt_id'])) {
            $filters['prompt_id'] = (int) $_GET['prompt_id'];
        }
        if (isset($_GET['form_id']) && !empty($_GET['form_id'])) {
            $filters['form_id'] = (int) $_GET['form_id'];
        }
        if (isset($_GET['status']) && in_array($_GET['status'], array('success', 'error'))) {
            $filters['status'] = sanitize_text_field($_GET['status']);
        }
        if (isset($_GET['provider']) && in_array($_GET['provider'], array('openai', 'gemini', 'claude'))) {
            $filters['provider'] = sanitize_text_field($_GET['provider']);
        }
        if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
            $filters['date_from'] = sanitize_text_field($_GET['date_from']);
        }
        if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
            $filters['date_to'] = sanitize_text_field($_GET['date_to']);
        }
        // Only add tracking source filter if column exists
        if (isset($_GET['tracking_source']) && !empty($_GET['tracking_source']) && $has_tracking_column) {
            $filters['tracking_source'] = sanitize_text_field($_GET['tracking_source']);
        }

        // NEW: Add chunking filter
        if (isset($_GET['chunked']) && in_array($_GET['chunked'], array('yes', 'no'))) {
            $filters['chunked'] = sanitize_text_field($_GET['chunked']);
        }

        // Get current page and items per page
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        // Get logs with filters including chunking info
        $logs = array();
        $total_logs = 0;

        if ($table_exists) {
            $logs = $this->get_all_logs_with_chunking($filters, $per_page, $offset);
            $total_logs = $this->count_all_logs($filters);
        }

        // Get prompts for filter dropdown
        $prompts = get_posts(array(
            'post_type' => 'sfaic_prompt',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ));

        // Calculate pagination
        $total_pages = ceil($total_logs / $per_page);

        // Get token usage stats
        $openai_stats = $this->get_token_usage_stats(30, 'openai');
        $gemini_stats = $this->get_token_usage_stats(30, 'gemini');
        $claude_stats = $this->get_token_usage_stats(30, 'claude');

        // Get chunking statistics
        $chunking_stats = $this->get_chunking_statistics();
        ?>
        <div class="wrap">
            <h1><?php _e('AI Response Logs', 'chatgpt-fluent-connector'); ?></h1>

            <!-- Enhanced Token Usage Statistics with Chunking -->
            <div class="sfaic-token-stats">
                <h2><?php _e('Usage Statistics (Last 30 Days)', 'chatgpt-fluent-connector'); ?></h2>
                <div class="stats-grid">
                    <!-- Existing token stats boxes... -->
                    <div class="stat-box">
                        <h3>
                            <span class="sfaic-api-badge openai">ChatGPT</span>
                        </h3>
                        <?php if ($openai_stats && $openai_stats->request_count > 0) : ?>
                            <p><strong><?php _e('Total Tokens:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($openai_stats->total_tokens ?? 0); ?></span></p>
                            <p><strong><?php _e('Avg per Request:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($openai_stats->avg_total_tokens ?? 0); ?></span></p>
                            <p><strong><?php _e('Max Single Request:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($openai_stats->max_total_tokens ?? 0); ?></span></p>
                        <?php else : ?>
                            <p style="color: #666;"><?php _e('No data available', 'chatgpt-fluent-connector'); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="stat-box">
                        <h3>
                            <span class="sfaic-api-badge gemini">Gemini</span>
                        </h3>
                        <?php if ($gemini_stats && $gemini_stats->request_count > 0) : ?>
                            <p><strong><?php _e('Total Tokens:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($gemini_stats->total_tokens ?? 0); ?></span></p>
                            <p><strong><?php _e('Avg per Request:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($gemini_stats->avg_total_tokens ?? 0); ?></span></p>
                            <p><strong><?php _e('Max Single Request:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($gemini_stats->max_total_tokens ?? 0); ?></span></p>
                        <?php else : ?>
                            <p style="color: #666;"><?php _e('No data available', 'chatgpt-fluent-connector'); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="stat-box">
                        <h3>
                            <span class="sfaic-api-badge claude">Claude</span>
                        </h3>
                        <?php if ($claude_stats && $claude_stats->request_count > 0) : ?>
                            <p><strong><?php _e('Total Tokens:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($claude_stats->total_tokens ?? 0); ?></span></p>
                            <p><strong><?php _e('Avg per Request:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($claude_stats->avg_total_tokens ?? 0); ?></span></p>
                            <p><strong><?php _e('Max Single Request:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($claude_stats->max_total_tokens ?? 0); ?></span></p>
                        <?php else : ?>
                            <p style="color: #666;"><?php _e('No data available', 'chatgpt-fluent-connector'); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- NEW: Chunking Statistics Box -->
                    <div class="stat-box chunking-stats">
                        <h3>
                            📊 <?php _e('Chunking Stats', 'chatgpt-fluent-connector'); ?>
                        </h3>
                        <p><strong><?php _e('Chunked Responses:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($chunking_stats->chunked_count ?? 0); ?></span></p>
                        <p><strong><?php _e('Avg Chunks:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($chunking_stats->avg_chunks ?? 0, 1); ?></span></p>
                        <p><strong><?php _e('Max Chunks:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($chunking_stats->max_chunks ?? 0); ?></span></p>
                        <p><strong><?php _e('Chunking Rate:', 'chatgpt-fluent-connector'); ?></strong> <span><?php echo number_format_i18n($chunking_stats->chunking_percentage ?? 0, 1); ?>%</span></p>
                    </div>
                </div>
            </div>

            <?php if (!$table_exists): ?>
                <div class="notice notice-error">
                    <p><?php _e('The logs table does not exist. Please try reactivating the plugin to create it.', 'chatgpt-fluent-connector'); ?></p>
                </div>
            <?php elseif (!$has_tracking_column): ?>
                <div class="notice notice-warning">
                    <p><?php _e('Database update needed for tracking features. Please deactivate and reactivate the plugin to update the database structure.', 'chatgpt-fluent-connector'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Enhanced Filters with Chunking Filter -->
            <div class="tablenav top">
                <form method="get" class="alignleft actions" id="sfaic-filters-form">
                    <input type="hidden" name="post_type" value="sfaic_prompt">
                    <input type="hidden" name="page" value="sfaic-response-logs">

                    <div class="alignleft actions" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                        <!-- Existing filters... -->
                        <select name="prompt_id">
                            <option value=""><?php _e('All Prompts', 'chatgpt-fluent-connector'); ?></option>
                            <?php foreach ($prompts as $prompt) : ?>
                                <option value="<?php echo esc_attr($prompt->ID); ?>" <?php selected(isset($filters['prompt_id']) ? $filters['prompt_id'] : '', $prompt->ID); ?>>
                                    <?php echo esc_html($prompt->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="provider">
                            <option value=""><?php _e('All Providers', 'chatgpt-fluent-connector'); ?></option>
                            <option value="openai" <?php selected(isset($filters['provider']) ? $filters['provider'] : '', 'openai'); ?>><?php _e('OpenAI (ChatGPT)', 'chatgpt-fluent-connector'); ?></option>
                            <option value="gemini" <?php selected(isset($filters['provider']) ? $filters['provider'] : '', 'gemini'); ?>><?php _e('Google Gemini', 'chatgpt-fluent-connector'); ?></option>
                            <option value="claude" <?php selected(isset($filters['provider']) ? $filters['provider'] : '', 'claude'); ?>><?php _e('Anthropic Claude', 'chatgpt-fluent-connector'); ?></option>
                        </select>

                        <select name="status">
                            <option value=""><?php _e('All Statuses', 'chatgpt-fluent-connector'); ?></option>
                            <option value="success" <?php selected(isset($filters['status']) ? $filters['status'] : '', 'success'); ?>><?php _e('Success', 'chatgpt-fluent-connector'); ?></option>
                            <option value="error" <?php selected(isset($filters['status']) ? $filters['status'] : '', 'error'); ?>><?php _e('Error', 'chatgpt-fluent-connector'); ?></option>
                        </select>

                        <!-- NEW: Chunking Filter -->
                        <select name="chunked">
                            <option value=""><?php _e('All Responses', 'chatgpt-fluent-connector'); ?></option>
                            <option value="yes" <?php selected(isset($filters['chunked']) ? $filters['chunked'] : '', 'yes'); ?>><?php _e('Chunked Only', 'chatgpt-fluent-connector'); ?></option>
                            <option value="no" <?php selected(isset($filters['chunked']) ? $filters['chunked'] : '', 'no'); ?>><?php _e('Single Response', 'chatgpt-fluent-connector'); ?></option>
                        </select>
                        <?php if ($has_tracking_column): ?>
                            <?php
                            // Get unique tracking sources for filter dropdown
                            $tracking_sources = $wpdb->get_col("
                            SELECT DISTINCT tracking_source 
                            FROM {$this->table_name} 
                            WHERE tracking_source IS NOT NULL 
                            AND tracking_source != '' 
                            ORDER BY tracking_source
                        ");
                            ?>
                            <?php if (!empty($tracking_sources)): ?>
                                <select name="tracking_source">
                                    <option value=""><?php _e('All Sources', 'chatgpt-fluent-connector'); ?></option>
                                    <?php foreach ($tracking_sources as $source): ?>
                                        <option value="<?php echo esc_attr($source); ?>" <?php selected(isset($filters['tracking_source']) ? $filters['tracking_source'] : '', $source); ?>>
                                            <?php echo esc_html($source); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php
                        // In get_all_logs method, add filter condition (around line 1600)
                        if (!empty($filters['tracking_source'])) {
                            $where_clauses[] = 'l.tracking_source = %s';
                            $query_params[] = $filters['tracking_source'];
                        }
                        ?>

                        <span>
                            <input type="date" name="date_from" placeholder="<?php _e('From date', 'chatgpt-fluent-connector'); ?>" 
                                   value="<?php echo isset($filters['date_from']) ? esc_attr($filters['date_from']) : ''; ?>">
                        </span>
                        <span>
                            <input type="date" name="date_to" placeholder="<?php _e('To date', 'chatgpt-fluent-connector'); ?>"
                                   value="<?php echo isset($filters['date_to']) ? esc_attr($filters['date_to']) : ''; ?>">
                        </span>

                        <input type="submit" class="button" value="<?php _e('Filter', 'chatgpt-fluent-connector'); ?>">

                        <?php if (!empty($filters)): ?>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=sfaic_prompt&page=sfaic-response-logs')); ?>" class="button">
                                <?php _e('Reset Filters', 'chatgpt-fluent-connector'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Pagination -->
                <div class="tablenav-pages">
                    <?php
                    if ($total_pages > 1) :
                        $pagination_url_args = array(
                            'post_type' => 'sfaic_prompt',
                            'page' => 'sfaic-response-logs'
                        );

                        foreach ($filters as $key => $value) {
                            $pagination_url_args[$key] = $value;
                        }

                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%', admin_url('edit.php?' . http_build_query($pagination_url_args))),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page
                        ));
                    endif;
                    ?>
                </div>
            </div>

            <!-- Enhanced Logs Table with Chunking Information -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php _e('ID', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 130px;"><?php _e('Date', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 120px;"><?php _e('Name', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 150px;"><?php _e('Email', 'chatgpt-fluent-connector'); ?></th>
                        <?php if ($has_tracking_column): ?>
                            <th style="width: 100px;"><?php _e('Source', 'chatgpt-fluent-connector'); ?></th>
                        <?php endif; ?>
                        <th><?php _e('Prompt', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 50px;"><?php _e('Form', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 50px;"><?php _e('Entry', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 70px;"><?php _e('Status', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 80px;"><?php _e('Provider', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 90px;"><?php _e('Model', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 80px;" title="<?php _e('Chunking Information', 'chatgpt-fluent-connector'); ?>"><?php _e('Chunks', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 100px;" title="<?php _e('Prompt / Completion / Total', 'chatgpt-fluent-connector'); ?>"><?php _e('Tokens', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 50px;"><?php _e('Time', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 140px;"><?php _e('Actions', 'chatgpt-fluent-connector'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($logs)) : ?>
                        <?php
                        foreach ($logs as $log) :
                            $row_class = ($log->status === 'error') ? 'error' : '';
                            $execution_time = isset($log->execution_time) ? round($log->execution_time, 2) . 's' : '-';
                            $status_badge = ($log->status === 'error') ? '<span class="sfaic-badge sfaic-badge-error">' . __('Error', 'chatgpt-fluent-connector') . '</span>' : '<span class="sfaic-badge sfaic-badge-success">' . __('Success', 'chatgpt-fluent-connector') . '</span>';

                            // Prepare provider badge
                            $provider_badge = '';
                            switch ($log->provider) {
                                case 'openai':
                                    $provider_badge = '<span class="sfaic-api-badge openai">ChatGPT</span>';
                                    break;
                                case 'gemini':
                                    $provider_badge = '<span class="sfaic-api-badge gemini">Gemini</span>';
                                    break;
                                case 'claude':
                                    $provider_badge = '<span class="sfaic-api-badge claude">Claude</span>';
                                    break;
                            }

                            // Format token usage
                            $token_display = '-';
                            if (!empty($log->total_tokens)) {
                                $token_display = '<span title="' . esc_attr(sprintf(__('Prompt: %s, Completion: %s, Total: %s', 'chatgpt-fluent-connector'),
                                                        number_format_i18n($log->prompt_tokens ?? 0),
                                                        number_format_i18n($log->completion_tokens ?? 0),
                                                        number_format_i18n($log->total_tokens)
                                                )) . '">' . number_format_i18n($log->total_tokens) . '</span>';

                                // Check if tokens are high
                                $model_limits = $this->get_model_token_limits($log->provider, $log->model);
                                $usage_percentage = ($log->total_tokens / $model_limits['max_tokens']) * 100;

                                if ($usage_percentage > 80) {
                                    $token_display = '<span class="token-warning" title="' . esc_attr(sprintf(__('Warning: %s%% of model limit', 'chatgpt-fluent-connector'), round($usage_percentage))) . '">' . $token_display . ' ⚠️</span>';
                                }
                            }

                            // NEW: Get chunking information
                            $chunking_info = $this->get_log_chunking_info($log->id, $log->entry_id);
                            $chunking_display = $this->format_chunking_display($chunking_info);

                            // Format user name and email with proper display
                            $user_name_display = !empty($log->user_name) ? esc_html($log->user_name) : '-';
                            $user_email_display = '-';

                            if (!empty($log->user_email)) {
                                $user_email_display = '<a href="mailto:' . esc_attr($log->user_email) . '">' . esc_html($log->user_email) . '</a>';
                            }
                            ?>
                            <tr class="<?php echo esc_attr($row_class); ?>">
                                <td><?php echo esc_html($log->id); ?></td>
                                <td>
                                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at))); ?>
                                </td>
                                <td title="<?php echo esc_attr($log->user_name ?? ''); ?>"><?php echo $user_name_display; ?></td>
                                <td title="<?php echo esc_attr($log->user_email ?? ''); ?>"><?php echo $user_email_display; ?></td>
                                <?php if ($has_tracking_column): ?>
                                    <td>
                                        <?php if (!empty($log->tracking_source)): ?>
                                            <span class="tracking-badge" style="background: #f0f8ff; color: #0073aa; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600;">
                                                <?php echo esc_html($log->tracking_source); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <?php if (isset($log->prompt_title) && !empty($log->prompt_title)) : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($log->prompt_id)); ?>">
                                            <?php echo esc_html($log->prompt_title); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($log->prompt_id)); ?>">
                                            <?php echo esc_html(get_the_title($log->prompt_id)); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log->form_id); ?></td>
                                <td><?php echo esc_html($log->entry_id); ?></td>
                                <td><?php echo $status_badge; ?></td>
                                <td><?php echo $provider_badge; ?></td>
                                <td style="font-size: 11px;"><?php echo esc_html($log->model); ?></td>
                                <!-- NEW: Chunking Display -->
                                <td class="column-chunks"><?php echo $chunking_display; ?></td>
                                <td class="column-tokens"><?php echo $token_display; ?></td>
                                <td><?php echo esc_html($execution_time); ?></td>
                                <td>
                                    <a href="<?php
                                    echo esc_url(add_query_arg(array(
                                        'post_type' => 'sfaic_prompt',
                                        'page' => 'sfaic-response-logs',
                                        'action' => 'view',
                                        'log_id' => $log->id
                                    )));
                                    ?>" class="button button-small">
                                           <?php _e('View', 'chatgpt-fluent-connector'); ?>
                                    </a>

                                    <!-- Restart button -->
                                    <button type="button" 
                                            class="button button-small sfaic-restart-process" 
                                            data-log-id="<?php echo esc_attr($log->id); ?>"
                                            style="margin-left: 5px;"
                                            title="<?php _e('Restart AI process for this entry', 'chatgpt-fluent-connector'); ?>">
                                        <span class="dashicons dashicons-update" style="vertical-align: middle; font-size: 12px; line-height: 1;"></span>
                                        <?php _e('Restart', 'chatgpt-fluent-connector'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="14"><?php _e('No logs found matching your criteria.', 'chatgpt-fluent-connector'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Bottom Pagination -->
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    if ($total_pages > 1) :
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%', admin_url('edit.php?' . http_build_query($pagination_url_args))),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page
                        ));
                    endif;
                    ?>
                </div>
            </div>

            <!-- NEW: Enhanced CSS for Chunking Display -->
            <style>
                .chunking-stats {
                    border-left: 4px solid #ff922b !important;
                }
                .chunking-stats .stat-number {
                    color: #ff922b !important;
                }
                .column-chunks {
                    text-align: center;
                    font-size: 12px;
                }
                .chunk-badge {
                    display: inline-block;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 10px;
                    font-weight: 600;
                    text-transform: uppercase;
                    white-space: nowrap;
                }
                .chunk-badge.single {
                    background-color: #e8f5e8;
                    color: #0a7e07;
                    border: 1px solid #00a32a;
                }
                .chunk-badge.chunked {
                    background-color: #fff4e6;
                    color: #b45309;
                    border: 1px solid #ff922b;
                }
                .chunk-badge.large {
                    background-color: #fbeaea;
                    color: #a00;
                    border: 1px solid #d63638;
                }
                .chunk-info {
                    font-size: 10px;
                    color: #666;
                    display: block;
                    margin-top: 2px;
                }
            </style>
        </div>
        <?php
    }

    /**
     * NEW: Get logs with chunking information
     */
    public function get_all_logs_with_chunking($filters = array(), $limit = 20, $offset = 0) {
        global $wpdb;

        $where_clauses = array();
        $query_params = array();
        $joins = array();

        // Process existing filters
        if (!empty($filters['prompt_id'])) {
            $where_clauses[] = 'l.prompt_id = %d';
            $query_params[] = $filters['prompt_id'];
        }

        if (!empty($filters['form_id'])) {
            $where_clauses[] = 'l.form_id = %d';
            $query_params[] = $filters['form_id'];
        }

        if (!empty($filters['entry_id'])) {
            $where_clauses[] = 'l.entry_id = %d';
            $query_params[] = $filters['entry_id'];
        }

        if (!empty($filters['status'])) {
            $where_clauses[] = 'l.status = %s';
            $query_params[] = $filters['status'];
        }

        if (!empty($filters['provider'])) {
            $where_clauses[] = 'l.provider = %s';
            $query_params[] = $filters['provider'];
        }

        if (!empty($filters['date_from'])) {
            $where_clauses[] = 'l.created_at >= %s';
            $query_params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where_clauses[] = 'l.created_at <= %s';
            $query_params[] = $filters['date_to'] . ' 23:59:59';
        }

        // NEW: Handle chunking filter
        if (!empty($filters['chunked'])) {
            $joins[] = "LEFT JOIN {$wpdb->postmeta} pm_chunked ON l.entry_id = pm_chunked.post_id AND pm_chunked.meta_key = '_gemini_chunked_response'";

            if ($filters['chunked'] === 'yes') {
                $where_clauses[] = "pm_chunked.meta_value = '1'";
            } elseif ($filters['chunked'] === 'no') {
                $where_clauses[] = "(pm_chunked.meta_value IS NULL OR pm_chunked.meta_value != '1')";
            }
        }

        // Build joins
        $join_sql = '';
        if (!empty($joins)) {
            $join_sql = implode(' ', array_unique($joins));
        }

        // Build the WHERE clause
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Add limit and offset
        $query_params[] = $limit;
        $query_params[] = $offset;

        $sql = "SELECT l.*, p.post_title as prompt_title 
            FROM {$this->table_name} l
            LEFT JOIN {$wpdb->posts} p ON l.prompt_id = p.ID
            $join_sql
            $where_sql
            ORDER BY l.created_at DESC 
            LIMIT %d OFFSET %d";

        $prepared_sql = $wpdb->prepare($sql, $query_params);

        return $wpdb->get_results($prepared_sql);
    }

    /**
     * NEW: Get chunking statistics
     */
    public function get_chunking_statistics($days = 30) {
        global $wpdb;

        $date_limit = date('Y-m-d H:i:s', strtotime("-$days days"));

        $sql = $wpdb->prepare(
                "SELECT 
            COUNT(DISTINCT l.id) as total_responses,
            COUNT(DISTINCT CASE WHEN pm_chunked.meta_value = '1' THEN l.id END) as chunked_count,
            AVG(CASE WHEN pm_chunks.meta_value IS NOT NULL THEN CAST(pm_chunks.meta_value AS UNSIGNED) END) as avg_chunks,
            MAX(CASE WHEN pm_chunks.meta_value IS NOT NULL THEN CAST(pm_chunks.meta_value AS UNSIGNED) END) as max_chunks,
            (COUNT(DISTINCT CASE WHEN pm_chunked.meta_value = '1' THEN l.id END) * 100.0 / COUNT(DISTINCT l.id)) as chunking_percentage
        FROM {$this->table_name} l
        LEFT JOIN {$wpdb->postmeta} pm_chunked ON l.entry_id = pm_chunked.post_id AND pm_chunked.meta_key = '_gemini_chunked_response'
        LEFT JOIN {$wpdb->postmeta} pm_chunks ON l.entry_id = pm_chunks.post_id AND pm_chunks.meta_key = '_gemini_chunks_count'
        WHERE l.created_at >= %s",
                $date_limit
        );

        return $wpdb->get_row($sql);
    }

    /**
     * NEW: Get chunking information for a specific log
     */
    public function get_log_chunking_info($log_id, $entry_id) {
        global $wpdb;

        // Get chunking metadata from postmeta
        $chunking_meta = $wpdb->get_results($wpdb->prepare(
                        "SELECT meta_key, meta_value 
         FROM {$wpdb->postmeta} 
         WHERE post_id = %d 
         AND meta_key IN (
             '_gemini_chunked_response',
             '_gemini_chunks_count', 
             '_gemini_total_tokens_generated',
             '_gemini_response_length',
             '_gemini_completion_reason',
             '_gemini_model_optimized'
         )",
                        $entry_id
                ), ARRAY_A);

        $chunking_info = array(
            'is_chunked' => false,
            'chunks_count' => 0,
            'total_tokens_generated' => 0,
            'response_length' => 0,
            'completion_reason' => '',
            'model_optimized' => ''
        );

        foreach ($chunking_meta as $meta) {
            switch ($meta['meta_key']) {
                case '_gemini_chunked_response':
                    $chunking_info['is_chunked'] = ($meta['meta_value'] === '1');
                    break;
                case '_gemini_chunks_count':
                    $chunking_info['chunks_count'] = intval($meta['meta_value']);
                    break;
                case '_gemini_total_tokens_generated':
                    $chunking_info['total_tokens_generated'] = intval($meta['meta_value']);
                    break;
                case '_gemini_response_length':
                    $chunking_info['response_length'] = intval($meta['meta_value']);
                    break;
                case '_gemini_completion_reason':
                    $chunking_info['completion_reason'] = $meta['meta_value'];
                    break;
                case '_gemini_model_optimized':
                    $chunking_info['model_optimized'] = $meta['meta_value'];
                    break;
            }
        }

        return $chunking_info;
    }

    /**
     * NEW: Format chunking display for table
     */
    public function format_chunking_display($chunking_info) {
        if (!$chunking_info['is_chunked']) {
            return '<span class="chunk-badge single" title="' . __('Single response', 'chatgpt-fluent-connector') . '">1</span>';
        }

        $chunks_count = $chunking_info['chunks_count'];
        $response_length = $chunking_info['response_length'];

        // Determine badge class based on chunk count
        $badge_class = 'chunked';
        if ($chunks_count >= 10) {
            $badge_class = 'large';
        }

        $title = sprintf(
                __('Chunked response: %d chunks, %s characters', 'chatgpt-fluent-connector'),
                $chunks_count,
                number_format_i18n($response_length)
        );

        if (!empty($chunking_info['model_optimized'])) {
            $title .= sprintf(
                    __(' (Optimized for %s)', 'chatgpt-fluent-connector'),
                    $chunking_info['model_optimized']
            );
        }

        $display = '<span class="chunk-badge ' . $badge_class . '" title="' . esc_attr($title) . '">' . $chunks_count . '</span>';

        if ($response_length > 0) {
            $display .= '<span class="chunk-info">' . size_format($response_length, 0) . '</span>';
        }

        return $display;
    }

    /**
     * Render log details view with enhanced user information
     */
    /**
     * FIXED: Render log details view with enhanced user information and HTML safety
     */

    /**
     * Enhanced render_log_details method with comprehensive chunking information
     * Replace or enhance the existing render_log_details method in SFAIC_Response_Logger class
     */
    private function render_log_details($log_id) {
        global $wpdb;

        // Get the log entry
        $log = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$this->table_name} WHERE id = %d",
                        $log_id
        ));

        if (!$log) {
            wp_die(__('Log entry not found.', 'chatgpt-fluent-connector'));
        }

        // Get comprehensive chunking information
        $chunking_info = $this->get_comprehensive_chunking_info($log->entry_id);

        // FIXED: Safely process AI response to prevent HTML crashes
        $ai_response_safe = $this->safe_process_ai_response($log->ai_response);
        $ai_response_length = strlen($log->ai_response);
        $is_long_response = $ai_response_length > 50000; // 50KB threshold
        // Get form name
        $form_name = '';
        if (function_exists('wpFluent')) {
            $form = wpFluent()->table('fluentform_forms')
                    ->select('title')
                    ->where('id', $log->form_id)
                    ->first();

            if ($form) {
                $form_name = $form->title;
            }
        }

        // Get entry data if available
        $entry_data = null;
        if (function_exists('wpFluent')) {
            $entry = wpFluent()->table('fluentform_submissions')
                    ->where('form_id', $log->form_id)
                    ->where('id', $log->entry_id)
                    ->first();

            if ($entry && !empty($entry->response)) {
                $entry_data = json_decode($entry->response, true);
            }
        }

        // Format execution time
        $execution_time = isset($log->execution_time) ? round($log->execution_time, 2) . 's' : '-';

        // Prepare status badge
        $status_badge = ($log->status === 'error') ?
                '<span class="sfaic-badge sfaic-badge-error">' . __('Error', 'chatgpt-fluent-connector') . '</span>' :
                '<span class="sfaic-badge sfaic-badge-success">' . __('Success', 'chatgpt-fluent-connector') . '</span>';

        // Prepare provider badge
        $provider_badge = '';
        switch ($log->provider) {
            case 'openai':
                $provider_badge = '<span class="sfaic-api-badge openai">ChatGPT</span>';
                break;
            case 'gemini':
                $provider_badge = '<span class="sfaic-api-badge gemini">Gemini</span>';
                break;
            case 'claude':
                $provider_badge = '<span class="sfaic-api-badge claude">Claude</span>';
                break;
        }
        ?>
        <div class="wrap">
            <h1>
                <?php _e('Log Details', 'chatgpt-fluent-connector'); ?>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=sfaic_prompt&page=sfaic-response-logs')); ?>" class="page-title-action">
                    <?php _e('Back to Logs', 'chatgpt-fluent-connector'); ?>
                </a>

                <!-- Restart button in details view -->
                <button type="button" 
                        class="page-title-action sfaic-restart-process" 
                        data-log-id="<?php echo esc_attr($log->id); ?>">
                    <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>
                    <?php _e('Restart Process', 'chatgpt-fluent-connector'); ?>
                </button>
            </h1>

            <div class="metabox-holder">
                <!-- Main Info with User Details -->
                <div class="postbox">
                    <h2 class="hndle"><span><?php _e('Log Information', 'chatgpt-fluent-connector'); ?></span></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th><?php _e('Log ID:', 'chatgpt-fluent-connector'); ?></th>
                                <td><?php echo esc_html($log->id); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Date:', 'chatgpt-fluent-connector'); ?></th>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at))); ?></td>
                            </tr>
                            <?php if (!empty($log->user_name)) : ?>
                                <tr>
                                    <th><?php _e('User Name:', 'chatgpt-fluent-connector'); ?></th>
                                    <td>
                                        <strong><?php echo esc_html($log->user_name); ?></strong>
                                        <?php if (!empty($log->user_email)) : ?>
                                            <a href="mailto:<?php echo esc_attr($log->user_email); ?>" class="button button-small" style="margin-left: 10px;">
                                                <span class="dashicons dashicons-email" style="vertical-align: middle; font-size: 14px;"></span>
                                                <?php _e('Email', 'chatgpt-fluent-connector'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty($log->user_email)) : ?>
                                <tr>
                                    <th><?php _e('User Email:', 'chatgpt-fluent-connector'); ?></th>
                                    <td><a href="mailto:<?php echo esc_attr($log->user_email); ?>"><?php echo esc_html($log->user_email); ?></a></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th><?php _e('Status:', 'chatgpt-fluent-connector'); ?></th>
                                <td><?php echo $status_badge; ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Execution Time:', 'chatgpt-fluent-connector'); ?></th>
                                <td><?php echo esc_html($execution_time); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Prompt:', 'chatgpt-fluent-connector'); ?></th>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link($log->prompt_id)); ?>">
                                        <?php echo esc_html(get_the_title($log->prompt_id)); ?>
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Form:', 'chatgpt-fluent-connector'); ?></th>
                                <td>
                                    <?php
                                    if (!empty($form_name)) {
                                        echo esc_html($form_name) . ' (ID: ' . esc_html($log->form_id) . ')';
                                    } else {
                                        echo esc_html(__('Form ID:', 'chatgpt-fluent-connector') . ' ' . $log->form_id);
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Entry ID:', 'chatgpt-fluent-connector'); ?></th>
                                <td><?php echo esc_html($log->entry_id); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Provider:', 'chatgpt-fluent-connector'); ?></th>
                                <td><?php echo $provider_badge; ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Model:', 'chatgpt-fluent-connector'); ?></th>
                                <td><?php echo esc_html($log->model); ?></td>
                            </tr>
                            <?php if (!empty($ai_response_length)) : ?>
                                <tr>
                                    <th><?php _e('Response Size:', 'chatgpt-fluent-connector'); ?></th>
                                    <td>
                                        <?php echo esc_html(size_format($ai_response_length, 2)); ?>
                                        <?php if ($is_long_response) : ?>
                                            <span style="color: #856404;">⚠️ Large response</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($log->status === 'error' && !empty($log->error_message)) : ?>
                                <tr>
                                    <th><?php _e('Error:', 'chatgpt-fluent-connector'); ?></th>
                                    <td>
                                        <div class="sfaic-error-message">
                                            <?php echo esc_html($log->error_message); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <!-- NEW: Comprehensive Chunking Information -->
                <?php if ($chunking_info['is_chunked']) : ?>
                    <div class="postbox chunking-details">
                        <h2 class="hndle">
                            <span>🧩 <?php _e('Chunking Details', 'chatgpt-fluent-connector'); ?></span>
                            <?php if ($chunking_info['model_optimized']) : ?>
                                <span class="chunking-optimization-badge"><?php echo esc_html($chunking_info['model_optimized']); ?></span>
                            <?php endif; ?>
                        </h2>
                        <div class="inside">
                            <div class="chunking-overview">
                                <div class="chunking-stats-grid">
                                    <div class="chunking-stat-card">
                                        <div class="stat-number"><?php echo esc_html($chunking_info['chunks_count']); ?></div>
                                        <div class="stat-label"><?php _e('Total Chunks', 'chatgpt-fluent-connector'); ?></div>
                                    </div>
                                    <div class="chunking-stat-card">
                                        <div class="stat-number"><?php echo esc_html(size_format($chunking_info['response_length'], 0)); ?></div>
                                        <div class="stat-label"><?php _e('Response Size', 'chatgpt-fluent-connector'); ?></div>
                                    </div>
                                    <div class="chunking-stat-card">
                                        <div class="stat-number"><?php echo esc_html(number_format_i18n($chunking_info['total_tokens_generated'])); ?></div>
                                        <div class="stat-label"><?php _e('Total Tokens', 'chatgpt-fluent-connector'); ?></div>
                                    </div>
                                    <div class="chunking-stat-card">
                                        <div class="stat-number"><?php echo esc_html(round($chunking_info['response_length'] / $chunking_info['chunks_count'])); ?></div>
                                        <div class="stat-label"><?php _e('Avg Chunk Size', 'chatgpt-fluent-connector'); ?></div>
                                    </div>
                                </div>
                            </div>

                            <table class="form-table" style="margin-top: 20px;">
                                <tr>
                                    <th><?php _e('Chunking Strategy:', 'chatgpt-fluent-connector'); ?></th>
                                    <td>
                                        <?php echo esc_html($chunking_info['chunking_strategy'] ?: 'Not specified'); ?>
                                        <?php if ($chunking_info['chunking_strategy']) : ?>
                                            <span class="description">
                                                <?php echo $this->get_chunking_strategy_description($chunking_info['chunking_strategy']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php _e('Completion Reason:', 'chatgpt-fluent-connector'); ?></th>
                                    <td>
                                        <span class="completion-reason-badge <?php echo esc_attr($chunking_info['completion_reason']); ?>">
                                            <?php echo esc_html($this->format_completion_reason($chunking_info['completion_reason'])); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php _e('Smart Completion:', 'chatgpt-fluent-connector'); ?></th>
                                    <td>
                                        <?php if ($chunking_info['enable_smart_completion']) : ?>
                                            <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                            <?php _e('Enabled', 'chatgpt-fluent-connector'); ?>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-marker" style="color: #d63638;"></span>
                                            <?php _e('Disabled', 'chatgpt-fluent-connector'); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($chunking_info['completion_marker'])) : ?>
                                    <tr>
                                        <th><?php _e('Completion Marker:', 'chatgpt-fluent-connector'); ?></th>
                                        <td><code><?php echo esc_html($chunking_info['completion_marker']); ?></code></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($chunking_info['use_token_percentage']) : ?>
                                    <tr>
                                        <th><?php _e('Token Threshold:', 'chatgpt-fluent-connector'); ?></th>
                                        <td>
                                            <?php echo esc_html($chunking_info['token_completion_threshold']); ?>%
                                            <span class="description"><?php _e('(Token-based completion enabled)', 'chatgpt-fluent-connector'); ?></span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <th><?php _e('Min Content Length:', 'chatgpt-fluent-connector'); ?></th>
                                    <td><?php echo esc_html(number_format_i18n($chunking_info['min_content_length'])); ?> characters</td>
                                </tr>
                                <tr>
                                    <th><?php _e('Target Word Count:', 'chatgpt-fluent-connector'); ?></th>
                                    <td><?php echo esc_html(number_format_i18n($chunking_info['completion_word_count'])); ?> words</td>
                                </tr>
                                <?php if (!empty($chunking_info['completion_keywords'])) : ?>
                                    <tr>
                                        <th><?php _e('Completion Keywords:', 'chatgpt-fluent-connector'); ?></th>
                                        <td>
                                            <?php
                                            $keywords = explode(',', $chunking_info['completion_keywords']);
                                            foreach ($keywords as $keyword) {
                                                echo '<span class="keyword-tag">' . esc_html(trim($keyword)) . '</span> ';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </table>

                            <!-- Chunking Performance Analysis -->
                            <div class="chunking-performance-analysis" style="margin-top: 20px;">
                                <h4><?php _e('Performance Analysis', 'chatgpt-fluent-connector'); ?></h4>
                                <?php $this->render_chunking_performance_analysis($chunking_info, $log); ?>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <!-- Single Response Info -->
                    <div class="postbox single-response-info">
                        <h2 class="hndle"><span>📄 <?php _e('Response Method', 'chatgpt-fluent-connector'); ?></span></h2>
                        <div class="inside">
                            <p>
                                <span class="dashicons dashicons-yes-alt" style="color: #00a32a; vertical-align: middle;"></span>
                                <strong><?php _e('Single Response', 'chatgpt-fluent-connector'); ?></strong> - 
                                <?php _e('This response was generated in a single API call without chunking.', 'chatgpt-fluent-connector'); ?>
                            </p>
                            <?php if ($ai_response_length > 10000) : ?>
                                <div class="notice notice-info inline" style="margin: 15px 0;">
                                    <p>
                                        <strong><?php _e('Note:', 'chatgpt-fluent-connector'); ?></strong>
                                        <?php _e('This is a large single response. Consider enabling chunking for better performance and reliability.', 'chatgpt-fluent-connector'); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Enhanced Token Usage with Chunking Context -->
                <?php if (!empty($log->total_tokens)) : ?>
                    <div class="postbox token-usage">
                        <h2 class="hndle"><span><?php _e('Token Usage', 'chatgpt-fluent-connector'); ?></span></h2>
                        <div class="inside">
                            <?php
                            $model_limits = $this->get_model_token_limits($log->provider, $log->model);
                            $usage_percentage = ($log->total_tokens / $model_limits['max_tokens']) * 100;
                            ?>
                            <table class="form-table">
                                <tr>
                                    <th><?php _e('Prompt Tokens:', 'chatgpt-fluent-connector'); ?></th>
                                    <td><?php echo number_format_i18n($log->prompt_tokens ?? 0); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Completion Tokens:', 'chatgpt-fluent-connector'); ?></th>
                                    <td><?php echo number_format_i18n($log->completion_tokens ?? 0); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Total Tokens:', 'chatgpt-fluent-connector'); ?></th>
                                    <td>
                                        <?php echo number_format_i18n($log->total_tokens); ?>
                                        <span style="color: <?php echo ($usage_percentage > 80) ? '#e74c3c' : '#27ae60'; ?>;">
                                            (<?php echo round($usage_percentage, 1); ?>% of model limit)
                                        </span>
                                    </td>
                                </tr>
                                <?php if ($chunking_info['is_chunked'] && $chunking_info['total_tokens_generated'] > 0) : ?>
                                    <tr>
                                        <th><?php _e('Chunked Generation Tokens:', 'chatgpt-fluent-connector'); ?></th>
                                        <td>
                                            <?php echo number_format_i18n($chunking_info['total_tokens_generated']); ?>
                                            <span class="description">
                                                (<?php echo round(($chunking_info['total_tokens_generated'] / $log->total_tokens) * 100, 1); ?>% of total)
                                            </span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <th><?php _e('Model Token Limit:', 'chatgpt-fluent-connector'); ?></th>
                                    <td><?php echo number_format_i18n($model_limits['max_tokens']); ?></td>
                                </tr>
                            </table>

                            <!-- Token usage visual bar -->
                            <div class="token-usage-bar">
                                <div class="token-usage-fill <?php echo ($usage_percentage > 80) ? 'danger' : ($usage_percentage > 60 ? 'warning' : ''); ?>" 
                                     style="width: <?php echo min($usage_percentage, 100); ?>%;"
                                     data-percentage="<?php echo round($usage_percentage, 1); ?>">
                                         <?php if ($usage_percentage > 20) : ?>
                                        <?php echo round($usage_percentage, 1); ?>%
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($usage_percentage > 80) : ?>
                                <div class="notice notice-warning inline" style="margin-top: 15px;">
                                    <p><?php _e('⚠️ This request used more than 80% of the model\'s token limit. Consider optimizing your prompts or using a model with a higher token limit.', 'chatgpt-fluent-connector'); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (!$chunking_info['is_chunked'] && $usage_percentage > 60) : ?>
                                <div class="notice notice-info inline" style="margin-top: 15px;">
                                    <p>
                                        <strong><?php _e('Chunking Recommendation:', 'chatgpt-fluent-connector'); ?></strong>
                                        <?php _e('This response used a significant portion of the token limit. Consider enabling chunking for more reliable long-form responses.', 'chatgpt-fluent-connector'); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Rest of the existing metaboxes (Form Data, AI Response, etc.) -->
                <!-- Form Data -->
                <?php if (!empty($entry_data)) : ?>
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('Form Data', 'chatgpt-fluent-connector'); ?></span></h2>
                        <div class="inside">
                            <table class="sfaic-form-data-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Field', 'chatgpt-fluent-connector'); ?></th>
                                        <th><?php _e('Value', 'chatgpt-fluent-connector'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $field_labels = $this->get_form_field_labels($log->prompt_id);
                                    foreach ($entry_data as $field_key => $field_value) :
                                        // Skip internal fields
                                        if (strpos($field_key, '_') === 0) {
                                            continue;
                                        }

                                        $field_label = isset($field_labels[$field_key]) ? $field_labels[$field_key] : $field_key;

                                        if (is_array($field_value)) {
                                            $field_value = implode(', ', $field_value);
                                        }
                                        ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($field_label); ?></strong></td>
                                            <td><?php echo esc_html($field_value); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- AI Response (existing code with chunking context) -->
                <div class="postbox">
                    <h2 class="hndle">
                        <span>
                            <?php _e('AI Response', 'chatgpt-fluent-connector'); ?>
                            <?php if ($chunking_info['is_chunked']) : ?>
                                <span class="chunked-response-indicator">
                                    🧩 <?php printf(__('(%d chunks)', 'chatgpt-fluent-connector'), $chunking_info['chunks_count']); ?>
                                </span>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($log->response_json)) : ?>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=sfaic_download_response_json&log_id=' . $log->id), 'sfaic_download_json', 'nonce')); ?>" 
                               class="button button-secondary sfaic-download-json" 
                               data-type="response" 
                               data-log-id="<?php echo esc_attr($log->id); ?>"
                               style="float: right;">
                                <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                <?php _e('Download JSON', 'chatgpt-fluent-connector'); ?>
                            </a>
                        <?php endif; ?>
                    </h2>
                    <div class="inside">
                        <?php if ($is_long_response) : ?>
                            <div class="notice notice-info inline" style="margin: 0 0 15px 0;">
                                <p>
                                    <strong><?php _e('Large Response Detected:', 'chatgpt-fluent-connector'); ?></strong>
                                    <?php _e('This response is very large. Use the tabs below to view different formats, or download the JSON for external viewing.', 'chatgpt-fluent-connector'); ?>
                                    <?php if ($chunking_info['is_chunked']) : ?>
                                        <?php printf(__('Generated using %d chunks for optimal performance.', 'chatgpt-fluent-connector'), $chunking_info['chunks_count']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <div class="sfaic-response-tabs">
                            <a href="#" class="sfaic-view-toggle active" data-target="sfaic-rendered-response"><?php _e('Safe Rendered', 'chatgpt-fluent-connector'); ?></a>
                            <a href="#" class="sfaic-view-toggle" data-target="sfaic-formatted-response"><?php _e('Formatted', 'chatgpt-fluent-connector'); ?></a>
                            <a href="#" class="sfaic-view-toggle" data-target="sfaic-raw-response"><?php _e('Raw', 'chatgpt-fluent-connector'); ?></a>
                            <button type="button" class="button sfaic-copy-response" style="margin-left: auto;">
                                <span class="dashicons dashicons-clipboard" style="vertical-align: middle;"></span>
                                <?php _e('Copy Response', 'chatgpt-fluent-connector'); ?>
                            </button>
                        </div>

                        <!-- Safe Rendered View -->
                        <div class="sfaic-response-view" id="sfaic-rendered-response">
                            <div class="sfaic-rendered-response sfaic-safe-content">
                                <?php echo $ai_response_safe['rendered']; ?>
                            </div>
                        </div>

                        <!-- Formatted View for better readability -->
                        <div class="sfaic-response-view" id="sfaic-formatted-response" style="display: none;">
                            <div class="sfaic-content-box">
                                <div class="sfaic-formatted-content">
                                    <?php echo $ai_response_safe['formatted']; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Raw View with length protection -->
                        <div class="sfaic-response-view" id="sfaic-raw-response" style="display: none;">
                            <div class="sfaic-content-box">
                                <?php if ($is_long_response) : ?>
                                    <div class="notice notice-warning inline" style="margin: 0 0 15px 0;">
                                        <p><?php _e('Response is very large. Showing first 50,000 characters.', 'chatgpt-fluent-connector'); ?></p>
                                    </div>
                                    <pre class="sfaic-raw-content"><?php echo esc_html(substr($log->ai_response, 0, 50000)); ?><?php if (strlen($log->ai_response) > 50000) echo "\n\n... [Response truncated for display. Download full JSON for complete content.]"; ?></pre>
                                <?php else : ?>
                                    <pre class="sfaic-raw-content"><?php echo esc_html($log->ai_response); ?></pre>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Request Data (existing) -->
                <?php if (!empty($log->request_json)) : ?>
                    <div class="postbox">
                        <h2 class="hndle">
                            <span><?php _e('Request Data', 'chatgpt-fluent-connector'); ?></span>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=sfaic_download_request_json&log_id=' . $log->id), 'sfaic_download_json', 'nonce')); ?>" 
                               class="button button-secondary sfaic-download-json" 
                               data-type="request" 
                               data-log-id="<?php echo esc_attr($log->id); ?>"
                               style="float: right;">
                                <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                <?php _e('Download JSON', 'chatgpt-fluent-connector'); ?>
                            </a>
                        </h2>
                        <div class="inside">
                            <div class="sfaic-content-box">
                                <pre class="sfaic-code-block"><?php echo esc_html($log->request_json); ?></pre>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Enhanced CSS for Chunking Display -->
            <style>
                /* Chunking-specific styles */
                .chunking-details {
                    border-left: 4px solid #ff922b !important;
                }

                .chunking-optimization-badge {
                    background: #e8f5e8;
                    color: #155724;
                    padding: 4px 8px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    margin-left: 10px;
                }

                .chunking-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                    gap: 15px;
                    margin: 15px 0;
                }

                .chunking-stat-card {
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    padding: 15px;
                    text-align: center;
                    border-radius: 5px;
                    border-left: 4px solid #ff922b;
                }

                .chunking-stat-card .stat-number {
                    font-size: 24px;
                    font-weight: 600;
                    color: #ff922b;
                    line-height: 1;
                    margin-bottom: 5px;
                }

                .chunking-stat-card .stat-label {
                    font-size: 12px;
                    color: #6c757d;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                .completion-reason-badge {
                    display: inline-block;
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }

                .completion-reason-badge.fully_optimized_chunking {
                    background: #d4edda;
                    color: #155724;
                    border: 1px solid #c3e6cb;
                }

                .completion-reason-badge.token_limit_reached {
                    background: #fff3cd;
                    color: #856404;
                    border: 1px solid #ffeaa7;
                }

                .completion-reason-badge.completion_marker_found {
                    background: #e8f5e8;
                    color: #0a7e07;
                    border: 1px solid #00a32a;
                }

                .keyword-tag {
                    display: inline-block;
                    background: #f8f9fa;
                    color: #495057;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 11px;
                    margin: 1px;
                    border: 1px solid #dee2e6;
                }

                .chunked-response-indicator {
                    color: #ff922b;
                    font-size: 14px;
                    font-weight: 500;
                }

                .single-response-info {
                    border-left: 4px solid #00a32a !important;
                }

                .chunking-performance-analysis {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 5px;
                    border: 1px solid #dee2e6;
                }

                .performance-metric {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 8px 0;
                    border-bottom: 1px solid #e0e0e0;
                }

                .performance-metric:last-child {
                    border-bottom: none;
                }

                .performance-metric .metric-label {
                    font-weight: 500;
                }

                .performance-metric .metric-value {
                    font-family: monospace;
                    background: #fff;
                    padding: 2px 6px;
                    border-radius: 3px;
                    border: 1px solid #ccc;
                }

                .performance-metric .metric-value.good {
                    color: #28a745;
                    border-color: #28a745;
                }

                .performance-metric .metric-value.warning {
                    color: #ffc107;
                    border-color: #ffc107;
                }

                .performance-metric .metric-value.poor {
                    color: #dc3545;
                    border-color: #dc3545;
                }
            </style>
        </div>
        <?php
    }

    /**
     * NEW: Get comprehensive chunking information including settings
     */
    private function get_comprehensive_chunking_info($entry_id) {
        global $wpdb;

        // Get all chunking-related metadata
        $chunking_meta = $wpdb->get_results($wpdb->prepare(
                        "SELECT meta_key, meta_value 
         FROM {$wpdb->postmeta} 
         WHERE post_id = %d 
         AND meta_key LIKE '_gemini_%'",
                        $entry_id
                ), ARRAY_A);

        $chunking_info = array(
            'is_chunked' => false,
            'chunks_count' => 0,
            'total_tokens_generated' => 0,
            'response_length' => 0,
            'completion_reason' => '',
            'model_optimized' => '',
            'chunking_strategy' => '',
            'enable_smart_completion' => false,
            'completion_marker' => '',
            'min_content_length' => 0,
            'completion_word_count' => 0,
            'completion_keywords' => '',
            'use_token_percentage' => false,
            'token_completion_threshold' => 0,
            'chunking_settings_used' => array()
        );

        foreach ($chunking_meta as $meta) {
            switch ($meta['meta_key']) {
                case '_gemini_chunked_response':
                    $chunking_info['is_chunked'] = ($meta['meta_value'] === '1');
                    break;
                case '_gemini_chunks_count':
                    $chunking_info['chunks_count'] = intval($meta['meta_value']);
                    break;
                case '_gemini_total_tokens_generated':
                    $chunking_info['total_tokens_generated'] = intval($meta['meta_value']);
                    break;
                case '_gemini_response_length':
                    $chunking_info['response_length'] = intval($meta['meta_value']);
                    break;
                case '_gemini_completion_reason':
                    $chunking_info['completion_reason'] = $meta['meta_value'];
                    break;
                case '_gemini_model_optimized':
                    $chunking_info['model_optimized'] = $meta['meta_value'];
                    break;
                case '_gemini_chunking_settings_used':
                    $settings = maybe_unserialize($meta['meta_value']);
                    if (is_array($settings)) {
                        $chunking_info = array_merge($chunking_info, $settings);
                        $chunking_info['chunking_settings_used'] = $settings;
                    }
                    break;
            }
        }

        return $chunking_info;
    }

    /**
     * NEW: Get chunking strategy description
     */
    private function get_chunking_strategy_description($strategy) {
        $descriptions = array(
            'balanced' => __('Balanced approach optimizing for both quality and performance', 'chatgpt-fluent-connector'),
            'aggressive' => __('Maximum length generation with higher token usage', 'chatgpt-fluent-connector'),
            'conservative' => __('Safe and fast processing with shorter chunks', 'chatgpt-fluent-connector')
        );

        return isset($descriptions[$strategy]) ? $descriptions[$strategy] : '';
    }

    /**
     * NEW: Format completion reason for display
     */
    private function format_completion_reason($reason) {
        $reasons = array(
            'fully_optimized_chunking' => __('Optimized Chunking Complete', 'chatgpt-fluent-connector'),
            'token_limit_reached' => __('Token Limit Reached', 'chatgpt-fluent-connector'),
            'completion_marker_found' => __('Completion Marker Found', 'chatgpt-fluent-connector'),
            'smart_completion_detected' => __('Smart Completion Detected', 'chatgpt-fluent-connector'),
            'max_chunks_reached' => __('Maximum Chunks Reached', 'chatgpt-fluent-connector')
        );

        return isset($reasons[$reason]) ? $reasons[$reason] : ucfirst(str_replace('_', ' ', $reason));
    }

    /**
     * NEW: Render chunking performance analysis
     */
    private function render_chunking_performance_analysis($chunking_info, $log) {
        $metrics = array();

        // Calculate efficiency metrics
        if ($chunking_info['chunks_count'] > 0 && $chunking_info['response_length'] > 0) {
            $avg_chunk_size = $chunking_info['response_length'] / $chunking_info['chunks_count'];
            $tokens_per_chunk = $chunking_info['total_tokens_generated'] / $chunking_info['chunks_count'];

            $metrics[] = array(
                'label' => __('Average Chunk Size', 'chatgpt-fluent-connector'),
                'value' => size_format($avg_chunk_size, 0),
                'class' => ($avg_chunk_size > 5000) ? 'good' : (($avg_chunk_size > 2000) ? 'warning' : 'poor')
            );

            $metrics[] = array(
                'label' => __('Tokens per Chunk', 'chatgpt-fluent-connector'),
                'value' => number_format_i18n($tokens_per_chunk),
                'class' => ($tokens_per_chunk > 1000) ? 'good' : (($tokens_per_chunk > 500) ? 'warning' : 'poor')
            );

            // Efficiency ratio
            $efficiency = ($chunking_info['response_length'] / $chunking_info['total_tokens_generated']) * 100;
            $metrics[] = array(
                'label' => __('Character/Token Ratio', 'chatgpt-fluent-connector'),
                'value' => number_format($efficiency, 1) . '%',
                'class' => ($efficiency > 70) ? 'good' : (($efficiency > 50) ? 'warning' : 'poor')
            );
        }

        // Chunking strategy effectiveness
        if ($chunking_info['completion_reason']) {
            $strategy_effectiveness = array(
                'fully_optimized_chunking' => 'good',
                'completion_marker_found' => 'good',
                'smart_completion_detected' => 'good',
                'token_limit_reached' => 'warning',
                'max_chunks_reached' => 'poor'
            );

            $effectiveness_class = isset($strategy_effectiveness[$chunking_info['completion_reason']]) ? $strategy_effectiveness[$chunking_info['completion_reason']] : 'warning';

            $metrics[] = array(
                'label' => __('Completion Strategy', 'chatgpt-fluent-connector'),
                'value' => $this->format_completion_reason($chunking_info['completion_reason']),
                'class' => $effectiveness_class
            );
        }

        // Render metrics
        if (!empty($metrics)) {
            foreach ($metrics as $metric) {
                echo '<div class="performance-metric">';
                echo '<span class="metric-label">' . esc_html($metric['label']) . ':</span>';
                echo '<span class="metric-value ' . esc_attr($metric['class']) . '">' . esc_html($metric['value']) . '</span>';
                echo '</div>';
            }
        } else {
            echo '<p class="description">' . __('Performance analysis not available for this response.', 'chatgpt-fluent-connector') . '</p>';
        }
    }

    /**
     * FIXED: Safely process AI response to prevent HTML crashes
     */
    private function safe_process_ai_response($ai_response) {
        if (empty($ai_response)) {
            return array(
                'rendered' => '<p><em>' . __('No response content.', 'chatgpt-fluent-connector') . '</em></p>',
                'formatted' => 'No response content.'
            );
        }

        // Check if response is too large
        $is_large = strlen($ai_response) > 50000;

        if ($is_large) {
            $truncated_response = substr($ai_response, 0, 50000);
        } else {
            $truncated_response = $ai_response;
        }

        // Enhanced HTML cleaning and safety
        $safe_rendered = $this->create_safe_html_content($truncated_response, $is_large);

        // Create formatted version (plain text with basic formatting)
        $formatted = $this->create_formatted_content($truncated_response, $is_large);

        return array(
            'rendered' => $safe_rendered,
            'formatted' => $formatted
        );
    }

    /**
     * FIXED: Create safe HTML content that won't crash the page
     */
    private function create_safe_html_content($content, $is_truncated = false) {
        // First, try to detect if content is HTML or plain text
        $has_html_tags = preg_match('/<[^>]+>/', $content);

        if ($has_html_tags) {
            // Content appears to be HTML - process carefully
            // Define allowed HTML tags for safe display
            $allowed_html = array(
                'p' => array(),
                'br' => array(),
                'strong' => array(),
                'b' => array(),
                'em' => array(),
                'i' => array(),
                'u' => array(),
                'h1' => array(),
                'h2' => array(),
                'h3' => array(),
                'h4' => array(),
                'h5' => array(),
                'h6' => array(),
                'ul' => array(),
                'ol' => array(),
                'li' => array(),
                'blockquote' => array(),
                'code' => array(),
                'pre' => array('class' => array()),
                'div' => array('class' => array(), 'style' => array()),
                'span' => array('class' => array(), 'style' => array()),
                'table' => array('class' => array()),
                'thead' => array(),
                'tbody' => array(),
                'tr' => array(),
                'th' => array(),
                'td' => array(),
                'a' => array('href' => array(), 'target' => array()),
                'img' => array('src' => array(), 'alt' => array(), 'width' => array(), 'height' => array()),
            );

            // Clean the HTML
            $cleaned_html = wp_kses($content, $allowed_html);

            // Fix any unclosed tags that might remain
            $cleaned_html = $this->fix_unclosed_html_tags($cleaned_html);

            // Wrap in container with safety CSS
            $safe_html = '<div class="ai-response-container">' . $cleaned_html . '</div>';
        } else {
            // Content is plain text - convert to safe HTML
            $safe_html = '<div class="ai-response-container">' . wpautop(esc_html($content)) . '</div>';
        }

        // Add truncation notice if needed
        if ($is_truncated) {
            $safe_html .= '<div class="response-length-warning"><strong>' . __('Note:', 'chatgpt-fluent-connector') . '</strong> ' . __('Response was truncated for display. Download the full JSON to see complete content.', 'chatgpt-fluent-connector') . '</div>';
        }

        return $safe_html;
    }

    /**
     * FIXED: Create formatted plain text version
     */
    private function create_formatted_content($content, $is_truncated = false) {
        // Strip all HTML tags and decode entities
        $plain_text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up extra whitespace
        $plain_text = preg_replace('/\n\s*\n\s*\n/', "\n\n", $plain_text);
        $plain_text = trim($plain_text);

        // Add truncation notice if needed
        if ($is_truncated) {
            $plain_text .= "\n\n[Response was truncated for display. Download the full JSON to see complete content.]";
        }

        return $plain_text;
    }

    /**
     * FIXED: Fix unclosed HTML tags to prevent page crashes
     */
    private function fix_unclosed_html_tags($html) {
        // List of self-closing tags that don't need to be closed
        $self_closing_tags = array('br', 'hr', 'img', 'input', 'meta', 'link');

        // List of tags that need to be properly closed
        $container_tags = array('p', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th');

        // Use DOMDocument to fix the HTML structure
        if (class_exists('DOMDocument')) {
            $dom = new DOMDocument();

            // Suppress errors for malformed HTML
            libxml_use_internal_errors(true);

            // Load HTML with UTF-8 encoding
            $dom->loadHTML('<meta charset="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            // Clear any errors
            libxml_clear_errors();

            // Get the body content (strip the meta tag we added)
            $fixed_html = $dom->saveHTML();
            $fixed_html = str_replace('<meta charset="UTF-8">', '', $fixed_html);

            return $fixed_html;
        }

        // Fallback: simple tag balancing for critical tags
        foreach ($container_tags as $tag) {
            $open_count = substr_count($html, '<' . $tag);
            $close_count = substr_count($html, '</' . $tag . '>');

            // Add missing closing tags
            if ($open_count > $close_count) {
                $missing = $open_count - $close_count;
                for ($i = 0; $i < $missing; $i++) {
                    $html .= '</' . $tag . '>';
                }
            }
        }

        return $html;
    }

    // Essential methods for functionality
    public function get_all_logs($filters = array(), $limit = 20, $offset = 0) {
        global $wpdb;

        $where_clauses = array();
        $query_params = array();

        // Process filters
        if (!empty($filters['prompt_id'])) {
            $where_clauses[] = 'l.prompt_id = %d';
            $query_params[] = $filters['prompt_id'];
        }

        if (!empty($filters['form_id'])) {
            $where_clauses[] = 'l.form_id = %d';
            $query_params[] = $filters['form_id'];
        }

        if (!empty($filters['entry_id'])) {
            $where_clauses[] = 'l.entry_id = %d';
            $query_params[] = $filters['entry_id'];
        }

        if (!empty($filters['status'])) {
            $where_clauses[] = 'l.status = %s';
            $query_params[] = $filters['status'];
        }

        if (!empty($filters['provider'])) {
            $where_clauses[] = 'l.provider = %s';
            $query_params[] = $filters['provider'];
        }

        if (!empty($filters['date_from'])) {
            $where_clauses[] = 'l.created_at >= %s';
            $query_params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where_clauses[] = 'l.created_at <= %s';
            $query_params[] = $filters['date_to'] . ' 23:59:59';
        }

        // Build the WHERE clause
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Add limit and offset
        $query_params[] = $limit;
        $query_params[] = $offset;

        $sql = "SELECT l.*, p.post_title as prompt_title 
            FROM {$this->table_name} l
            LEFT JOIN {$wpdb->posts} p ON l.prompt_id = p.ID
            $where_sql
            ORDER BY l.created_at DESC 
            LIMIT %d OFFSET %d";

        $prepared_sql = $wpdb->prepare($sql, $query_params);

        return $wpdb->get_results($prepared_sql);
    }

    public function count_all_logs($filters = array()) {
        global $wpdb;

        $where_clauses = array();
        $query_params = array();

        // Process filters - same as get_all_logs
        if (!empty($filters['prompt_id'])) {
            $where_clauses[] = 'prompt_id = %d';
            $query_params[] = $filters['prompt_id'];
        }

        if (!empty($filters['form_id'])) {
            $where_clauses[] = 'form_id = %d';
            $query_params[] = $filters['form_id'];
        }

        if (!empty($filters['entry_id'])) {
            $where_clauses[] = 'entry_id = %d';
            $query_params[] = $filters['entry_id'];
        }

        if (!empty($filters['status'])) {
            $where_clauses[] = 'status = %s';
            $query_params[] = $filters['status'];
        }

        if (!empty($filters['provider'])) {
            $where_clauses[] = 'provider = %s';
            $query_params[] = $filters['provider'];
        }

        if (!empty($filters['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $query_params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $query_params[] = $filters['date_to'] . ' 23:59:59';
        }

        // Build the WHERE clause
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        $sql = "SELECT COUNT(*) FROM {$this->table_name} $where_sql";

        if (!empty($query_params)) {
            $sql = $wpdb->prepare($sql, $query_params);
        }

        return (int) $wpdb->get_var($sql);
    }

    public function get_token_usage_stats($days = 30, $provider = null) {
        global $wpdb;

        $date_limit = date('Y-m-d H:i:s', strtotime("-$days days"));

        $where_clause = "WHERE created_at >= %s";
        $params = array($date_limit);

        if ($provider) {
            $where_clause .= " AND provider = %s";
            $params[] = $provider;
        }

        $sql = $wpdb->prepare(
                "SELECT 
                SUM(prompt_tokens) as total_prompt_tokens,
                SUM(completion_tokens) as total_completion_tokens,
                SUM(total_tokens) as total_tokens,
                AVG(prompt_tokens) as avg_prompt_tokens,
                AVG(completion_tokens) as avg_completion_tokens,
                AVG(total_tokens) as avg_total_tokens,
                MAX(total_tokens) as max_total_tokens,
                COUNT(*) as request_count
            FROM {$this->table_name}
            $where_clause",
                $params
        );

        return $wpdb->get_row($sql);
    }

    private function get_model_token_limits($provider, $model) {
        $limits = array(
            'openai' => array(
                'gpt-3.5-turbo' => array('max_tokens' => 4096, 'max_output' => 4096),
                'gpt-4' => array('max_tokens' => 8192, 'max_output' => 8192),
                'gpt-4-turbo' => array('max_tokens' => 128000, 'max_output' => 4096),
                'gpt-4-1106-preview' => array('max_tokens' => 128000, 'max_output' => 4096),
                'gpt-4-0613' => array('max_tokens' => 8192, 'max_output' => 8192),
                'gpt-4-0125-preview' => array('max_tokens' => 128000, 'max_output' => 4096),
            ),
            'gemini' => array(
                'gemini-pro' => array('max_tokens' => 32768, 'max_output' => 8192),
                'gemini-1.5-pro' => array('max_tokens' => 1048576, 'max_output' => 8192),
                'gemini-1.5-flash' => array('max_tokens' => 1048576, 'max_output' => 8192),
                'gemini-2.5-flash' => array('max_tokens' => 1048576, 'max_output' => 8192),
            ),
            'claude' => array(
                'claude-opus-4-20250514' => array('max_tokens' => 200000, 'max_output' => 4096),
                'claude-sonnet-4-20250514' => array('max_tokens' => 200000, 'max_output' => 4096),
                'claude-3-opus-20240229' => array('max_tokens' => 200000, 'max_output' => 4096),
                'claude-3-sonnet-20240229' => array('max_tokens' => 200000, 'max_output' => 4096),
                'claude-3-haiku-20240307' => array('max_tokens' => 200000, 'max_output' => 4096),
            )
        );

        if (isset($limits[$provider][$model])) {
            return $limits[$provider][$model];
        }

        // Default limits if model not found
        return array('max_tokens' => 4096, 'max_output' => 4096);
    }

    private function get_form_field_labels($prompt_id) {
        $field_labels = array();
        $form_id = get_post_meta($prompt_id, '_sfaic_fluent_form_id', true);

        if (empty($form_id) || !function_exists('wpFluent')) {
            return $field_labels;
        }

        // Get the form structure
        $form = wpFluent()->table('fluentform_forms')
                ->where('id', $form_id)
                ->first();

        if ($form && !empty($form->form_fields)) {
            $formFields = json_decode($form->form_fields, true);

            if (!empty($formFields['fields'])) {
                foreach ($formFields['fields'] as $field) {
                    if (!empty($field['element']) && !empty($field['attributes']['name'])) {
                        $field_name = $field['attributes']['name'];

                        if (!empty($field['settings']['label'])) {
                            $field_label = $field['settings']['label'];
                        } elseif (!empty($field['settings']['admin_field_label'])) {
                            $field_label = $field['settings']['admin_field_label'];
                        } else {
                            $field_label = $field_name;
                        }

                        $field_labels[$field_name] = $field_label;
                    }
                }
            }
        }

        return $field_labels;
    }

    private function ensure_table_exists() {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;

        if (!$table_exists) {
            $this->create_logs_table();
        }
    }

    public function add_logs_submenu() {
        add_submenu_page(
                'edit.php?post_type=sfaic_prompt',
                __('Response Logs', 'chatgpt-fluent-connector'),
                __('Response Logs', 'chatgpt-fluent-connector'),
                'manage_options',
                'sfaic-response-logs',
                array($this, 'render_logs_page')
        );
    }

    public function enqueue_admin_assets($hook) {
        // Only load on our logs page
        if ($hook !== 'sfaic_prompt_page_sfaic-response-logs') {
            return;
        }

        // Register and enqueue custom CSS
        wp_enqueue_style(
                'sfaic-logs-styles',
                SFAIC_URL . 'assets/css/response-logs.css',
                array(),
                SFAIC_VERSION
        );

        // Register and enqueue custom JS
        wp_enqueue_script(
                'sfaic-logs-script',
                SFAIC_URL . 'assets/js/response-logs.js',
                array('jquery'),
                SFAIC_VERSION,
                true
        );

        // Localize script with AJAX data
        wp_localize_script('sfaic-logs-script', 'sfaic_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sfaic_ajax_nonce'),
            'download_nonce' => wp_create_nonce('sfaic_download_json'),
            'restart_nonce' => wp_create_nonce('sfaic_restart_process'),
            'strings' => array(
                'confirm_restart' => __('Are you sure you want to restart this AI process? This will generate a new response.', 'chatgpt-fluent-connector'),
                'restarting' => __('Restarting...', 'chatgpt-fluent-connector'),
                'restart_success' => __('Process restarted successfully', 'chatgpt-fluent-connector'),
                'restart_error' => __('Failed to restart process', 'chatgpt-fluent-connector'),
            )
        ));
    }

    // Add the remaining AJAX and helper methods...
    public function ajax_download_request_json() {
        // Check nonce for security
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'sfaic_download_json')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $log_id = isset($_GET['log_id']) ? intval($_GET['log_id']) : 0;

        if (!$log_id) {
            wp_die('Invalid log ID');
        }

        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare(
                        "SELECT request_json, created_at FROM {$this->table_name} WHERE id = %d",
                        $log_id
        ));

        if (!$log || empty($log->request_json)) {
            wp_die('No request data found');
        }

        // Clean any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers for download
        $filename = 'api-request-' . $log_id . '-' . date('Y-m-d-His', strtotime($log->created_at)) . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Pretty print the JSON
        $json = json_decode($log->request_json, true);
        if ($json) {
            echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            echo $log->request_json;
        }

        exit;
    }

    public function ajax_download_response_json() {
        // Check nonce for security
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'sfaic_download_json')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $log_id = isset($_GET['log_id']) ? intval($_GET['log_id']) : 0;

        if (!$log_id) {
            wp_die('Invalid log ID');
        }

        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare(
                        "SELECT response_json, created_at FROM {$this->table_name} WHERE id = %d",
                        $log_id
        ));

        if (!$log || empty($log->response_json)) {
            wp_die('No response data found');
        }

        // Clean any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers for download
        $filename = 'api-response-' . $log_id . '-' . date('Y-m-d-His', strtotime($log->created_at)) . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Pretty print the JSON
        $json = json_decode($log->response_json, true);
        if ($json) {
            echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            echo $log->response_json;
        }

        exit;
    }

    public function ajax_restart_ai_process() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_restart_process')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;

        if (!$log_id) {
            wp_send_json_error(array(
                'message' => __('Invalid log ID', 'chatgpt-fluent-connector')
            ));
        }

        global $wpdb;

        // Get the original log entry
        $log = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$this->table_name} WHERE id = %d",
                        $log_id
        ));

        if (!$log) {
            wp_send_json_error(array(
                'message' => __('Log entry not found', 'chatgpt-fluent-connector')
            ));
        }

        // Get the form data from the original submission
        $form_data = $this->get_form_data_from_entry($log->form_id, $log->entry_id);

        if (is_wp_error($form_data)) {
            wp_send_json_error(array(
                'message' => $form_data->get_error_message()
            ));
        }

        // Get the form object
        $form = $this->get_form_object($log->form_id);

        if (is_wp_error($form)) {
            wp_send_json_error(array(
                'message' => $form->get_error_message()
            ));
        }

        try {
            // Check if background processing is enabled
            $background_enabled = get_option('sfaic_enable_background_processing', true);

            if ($background_enabled && isset(sfaic_main()->background_job_manager)) {
                // Schedule background job for restart
                $job_id = sfaic_main()->background_job_manager->schedule_job(
                        'ai_form_processing',
                        $log->prompt_id,
                        $log->form_id,
                        $log->entry_id,
                        array(
                            'form_data' => $form_data,
                            'restart_from_log_id' => $log_id
                        ),
                        5, // 5 second delay
                        1  // Higher priority for restarts
                );

                if ($job_id) {
                    wp_send_json_success(array(
                        'message' => __('AI process restart scheduled in background', 'chatgpt-fluent-connector'),
                        'job_id' => $job_id,
                        'is_background' => true
                    ));
                } else {
                    // Fallback to immediate processing
                    $this->process_restart_immediately($log, $form_data, $form);
                }
            } else {
                // Process immediately
                $this->process_restart_immediately($log, $form_data, $form);
            }
        } catch (Exception $e) {
            error_log('SFAIC: Restart process error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('Failed to restart process: ', 'chatgpt-fluent-connector') . $e->getMessage()
            ));
        }
    }

    /**
     * Process restart immediately (synchronous)
     */
    private function process_restart_immediately($log, $form_data, $form) {
        // Get the active API provider
        $api = sfaic_main()->get_active_api();

        if (!$api) {
            wp_send_json_error(array(
                'message' => __('AI API not available', 'chatgpt-fluent-connector')
            ));
        }

        // Process the form with the prompt
        $start_time = microtime(true);
        $ai_response = $api->process_form_with_prompt($log->prompt_id, $form_data, $log->entry_id);
        $execution_time = microtime(true) - $start_time;

        // Get token usage
        $token_usage = array();
        if (method_exists($api, 'get_last_token_usage')) {
            $token_usage = $api->get_last_token_usage();
        }

        // Get provider and model info
        $provider = get_option('sfaic_api_provider', 'openai');
        $model = $this->get_current_model($provider);

        // Determine status and error message
        $status = 'success';
        $error_message = '';
        $response_content = '';

        if (is_wp_error($ai_response)) {
            $status = 'error';
            $error_message = $ai_response->get_error_message();
        } else {
            $response_content = $ai_response;
        }

        // Get request/response JSON if available
        $request_json = '';
        $response_json = '';
        if (method_exists($api, 'get_last_request_json')) {
            $request_json = $api->get_last_request_json();
        }
        if (method_exists($api, 'get_last_response_json')) {
            $response_json = $api->get_last_response_json();
        }

        // Create new log entry for the restart
        $new_log_id = $this->log_response(
                $log->prompt_id,
                $log->entry_id,
                $log->form_id,
                $log->user_prompt,
                $response_content,
                $provider,
                $model,
                $execution_time,
                $status,
                $error_message,
                $token_usage,
                $log->prompt_template,
                $request_json,
                $response_json,
                $form_data  // Pass form_data for name/email extraction
        );

        if ($new_log_id) {
            // Add metadata to indicate this is a restart
            global $wpdb;
            $wpdb->update(
                    $this->table_name,
                    array('error_message' => ($error_message ? $error_message . ' ' : '') . '[Restarted from log #' . $log->id . ']'),
                    array('id' => $new_log_id),
                    array('%s'),
                    array('%d')
            );

            wp_send_json_success(array(
                'message' => $status === 'success' ? __('AI process restarted successfully', 'chatgpt-fluent-connector') : __('AI process restarted but encountered an error', 'chatgpt-fluent-connector'),
                'new_log_id' => $new_log_id,
                'status' => $status,
                'is_background' => false
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to create new log entry', 'chatgpt-fluent-connector')
            ));
        }
    }

    /**
     * Get current model based on provider
     */
    private function get_current_model($provider) {
        switch ($provider) {
            case 'gemini':
                return get_option('sfaic_gemini_model', 'gemini-1.5-pro-latest');
            case 'claude':
                return get_option('sfaic_claude_model', 'claude-opus-4-20250514');
            default:
                return get_option('sfaic_model', 'gpt-3.5-turbo');
        }
    }

    /**
     * Get form data from entry ID
     */
    private function get_form_data_from_entry($form_id, $entry_id) {
        if (!function_exists('wpFluent')) {
            return new WP_Error('fluent_not_available', __('Fluent Forms not available', 'chatgpt-fluent-connector'));
        }

        $entry = wpFluent()->table('fluentform_submissions')
                ->where('form_id', $form_id)
                ->where('id', $entry_id)
                ->first();

        if (!$entry) {
            return new WP_Error('entry_not_found', __('Form entry not found', 'chatgpt-fluent-connector'));
        }

        $form_data = json_decode($entry->response, true);

        if (!$form_data) {
            return new WP_Error('invalid_form_data', __('Invalid form data', 'chatgpt-fluent-connector'));
        }

        return $form_data;
    }

    /**
     * Get form object
     */
    private function get_form_object($form_id) {
        if (!function_exists('wpFluent')) {
            return new WP_Error('fluent_not_available', __('Fluent Forms not available', 'chatgpt-fluent-connector'));
        }

        $form = wpFluent()->table('fluentform_forms')
                ->where('id', $form_id)
                ->first();

        if (!$form) {
            return new WP_Error('form_not_found', __('Form not found', 'chatgpt-fluent-connector'));
        }

        return $form;
    }
}
