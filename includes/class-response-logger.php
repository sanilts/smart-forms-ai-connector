<?php

/**
 * Enhanced Response Logger Class with Token Tracking
 */
class SFAIC_Response_Logger{

    /**
     * Table name
     */
    private $table_name;

    /**
     * Table version - Incremented for new columns
     */
    private $table_version = '1.3';

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sfaic_response_logs';

        // Add admin submenu for logs
        add_action('admin_menu', array($this, 'add_logs_submenu'));

        // Make sure table exists
        $this->ensure_table_exists();

        // Check if table needs to be updated
        add_action('admin_init', array($this, 'check_table_version'));

        // Add assets for the logs page
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Add AJAX handlers for JSON downloads
        add_action('wp_ajax_sfaic_download_request_json', array($this, 'ajax_download_request_json'));
        add_action('wp_ajax_sfaic_download_response_json', array($this, 'ajax_download_response_json'));
    }

    /**
     * Check if the table structure needs to be updated
     */
    public function check_table_version() {
        $current_version = get_option('sfaic_logs_table_version', '1.0');

        // If the table version is less than our version, we need to update it
        if (version_compare($current_version, $this->table_version, '<')) {
            $this->update_table_structure();
            update_option('sfaic_logs_table_version', $this->table_version);
        }
    }

    /**
     * AJAX handler to download request JSON
     */
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

    /**
     * AJAX handler to download response JSON
     */
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

        // Add new columns for template and JSON storage
        if (!in_array('prompt_template', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `prompt_template` TEXT AFTER `user_prompt`");
        }

        if (!in_array('request_json', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `request_json` LONGTEXT AFTER `error_message`");
        }

        if (!in_array('response_json', $column_names)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD `response_json` LONGTEXT AFTER `request_json`");
        }

        // Add existing columns if missing
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
        * Create logs table with enhanced fields including token tracking
    */
    public function create_logs_table(){
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
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql);

           // Set the table version
        update_option('sfaic_logs_table_version', $this->table_version);
    }

    /**
     * Log a response with enhanced details including token usage
     * 
     * @param int $prompt_id The prompt ID
     * @param int $entry_id The submission entry ID
     * @param int $form_id The form ID
     * @param string $user_prompt The user prompt (template with placeholders)
     * @param string|WP_Error $ai_response The AI response or error
     * @param string $provider The API provider (openai, gemini, or claude)
     * @param string $model The model used
     * @param float $execution_time The execution time in seconds
     * @param string $status The status (success or error)
     * @param string $error_message The error message if any
     * @param array $token_usage Array with prompt_tokens, completion_tokens, total_tokens
     * @return bool|int The row ID or false on failure
     */
    public function log_response($prompt_id, $entry_id, $form_id, $user_prompt, $ai_response, $provider = null, $model = '', $execution_time = null, $status = 'success', $error_message = '', $token_usage = array(), $prompt_template = '', $request_json = '', $response_json = '') {
        global $wpdb;

        // Ensure table exists before trying to insert
        $this->ensure_table_exists();

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

        // Prepare data with proper types
        $data = array(
            'prompt_id' => $prompt_id,
            'prompt_title' => $prompt_title,
            'form_id' => $form_id,
            'entry_id' => $entry_id,
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

        // Check token limits if enabled
        $this->check_token_limits($data, $provider, $model);

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
     * Check token limits and add warnings if exceeded
     */
    private function check_token_limits($data, $provider, $model) {
        $total_tokens = isset($data['total_tokens']) ? $data['total_tokens'] : 0;

        if ($total_tokens === 0) {
            return;
        }

        // Get token limits based on provider and model
        $token_limits = $this->get_model_token_limits($provider, $model);

        // Get warning threshold from settings (default to 80%)
        $warning_threshold = get_option('sfaic_token_warning_threshold', 80) / 100;

        if ($total_tokens > ($token_limits['max_tokens'] * $warning_threshold)) {
            // Add admin notice
            add_action('admin_notices', function () use ($total_tokens, $token_limits, $model) {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <strong><?php _e('Token Usage Warning:', 'chatgpt-fluent-connector'); ?></strong>
                        <?php
                        printf(
                                __('The last request to %s used %s tokens out of %s maximum (%s%%). Consider reducing prompt size or response length.', 'chatgpt-fluent-connector'),
                                esc_html($model),
                                number_format_i18n($total_tokens),
                                number_format_i18n($token_limits['max_tokens']),
                                round(($total_tokens / $token_limits['max_tokens']) * 100)
                        );
                        ?>
                    </p>
                </div>
                <?php
            });
        }
    }

    /**
     * Get model token limits
     */
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

    /**
     * Get token usage statistics
     */
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

    /**
     * Ensure table exists
     */
    private function ensure_table_exists() {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;

        if (!$table_exists) {
            $this->create_logs_table();
        }
    }

    /**
     * Enqueue admin scripts and styles
     */
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
            'download_nonce' => wp_create_nonce('sfaic_download_json')
        ));
    }

    /**
     * Add logs submenu
     */
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

    /**
     * Render logs page with token usage display
     */
    public function render_logs_page() {
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'chatgpt-fluent-connector'));
        }

        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;

        // Process view log details action
        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['log_id'])) {
            $this->render_log_details((int) $_GET['log_id']);
            return;
        }

        // Get filters
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

        // Get current page and items per page
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        // Get logs with filters
        $logs = array();
        $total_logs = 0;

        if ($table_exists) {
            $logs = $this->get_all_logs($filters, $per_page, $offset);
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
        ?>
        <div class="wrap">
            <h1><?php _e('AI Response Logs', 'chatgpt-fluent-connector'); ?></h1>

            <!-- Token Usage Statistics -->
            <div style="background: #f8f9fa; padding: 20px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #dee2e6;">
                <h2 style="margin-top: 0;"><?php _e('Token Usage Statistics (Last 30 Days)', 'chatgpt-fluent-connector'); ?></h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">

                    <div style="background: #fff; padding: 15px; border-radius: 5px; border: 1px solid #e9ecef;">
                        <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                            <span class="sfaic-api-badge openai">ChatGPT</span>
                        </h3>
                        <?php if ($openai_stats && $openai_stats->request_count > 0) : ?>
                            <p><strong><?php _e('Total Tokens:', 'chatgpt-fluent-connector'); ?></strong> <?php echo number_format_i18n($openai_stats->total_tokens ?? 0); ?></p>
                            <p><strong><?php _e('Avg per Request:', 'chatgpt-fluent-connector'); ?></strong> <?php echo number_format_i18n($openai_stats->avg_total_tokens ?? 0); ?></p>
                            <p><strong><?php _e('Max Single Request:', 'chatgpt-fluent-connector'); ?></strong> <?php echo number_format_i18n($openai_stats->max_total_tokens ?? 0); ?></p>
                        <?php else : ?>
                            <p style="color: #666;"><?php _e('No data available', 'chatgpt-fluent-connector'); ?></p>
                        <?php endif; ?>
                    </div>

                    <div style="background: #fff; padding: 15px; border-radius: 5px; border: 1px solid #e9ecef;">
                        <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                            <span class="sfaic-api-badge gemini">Gemini</span>
                        </h3>
                        <?php if ($gemini_stats && $gemini_stats->request_count > 0) : ?>
                            <p><strong><?php _e('Total Tokens:', 'chatgpt-fluent-connector'); ?></strong> <?php echo number_format_i18n($gemini_stats->total_tokens ?? 0); ?></p>
                            <p><strong><?php _e('Avg per Request:', 'chatgpt-fluent-connector'); ?></strong> <?php echo number_format_i18n($gemini_stats->avg_total_tokens ?? 0); ?></p>
                            <p><strong><?php _e('Max Single Request:', 'chatgpt-fluent-connector'); ?></strong> <?php echo number_format_i18n($gemini_stats->max_total_tokens ?? 0); ?></p>
                        <?php else : ?>
                            <p style="color: #666;"><?php _e('No data available', 'chatgpt-fluent-connector'); ?></p>
                        <?php endif; ?>
                    </div>

                    <div style="background: #fff; padding: 15px; border-radius: 5px; border: 1px solid #e9ecef;">
                        <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                            <span class="sfaic-api-badge claude">Claude</span>
                        </h3>
                        <?php if ($claude_stats && $claude_stats->request_count > 0) : ?>
                            <p><strong><?php _e('Total Tokens:', 'chatgpt-fluent-connector'); ?></strong> <?php echo number_format_i18n($claude_stats->total_tokens ?? 0); ?></p>
                            <p><strong><?php _e('Avg per Request:', 'chatgpt-fluent-connector'); ?></strong> <?php echo number_format_i18n($claude_stats->avg_total_tokens ?? 0); ?></p>
                            <p><strong><?php _e('Max Single Request:', 'chatgpt-fluent-connector'); ?></strong> <?php echo number_format_i18n($claude_stats->max_total_tokens ?? 0); ?></p>
                        <?php else : ?>
                            <p style="color: #666;"><?php _e('No data available', 'chatgpt-fluent-connector'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!$table_exists): ?>
                <div class="notice notice-error">
                    <p><?php _e('The logs table does not exist. Please try reactivating the plugin to create it.', 'chatgpt-fluent-connector'); ?></p>
                </div>
            <?php elseif (empty($logs) && empty($filters)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No logs found. This could be because no forms have been submitted yet, or logging is not enabled on your prompts.', 'chatgpt-fluent-connector'); ?></p>
                    <p><?php _e('To enable logging, edit a prompt and check the "Save responses to the database" option under Response Handling.', 'chatgpt-fluent-connector'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="tablenav top">
                <form method="get" class="alignleft actions">
                    <input type="hidden" name="post_type" value="sfaic_prompt">
                    <input type="hidden" name="page" value="sfaic-response-logs">

                    <div class="alignleft actions" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                        <!-- Prompt filter -->
                        <select name="prompt_id">
                            <option value=""><?php _e('All Prompts', 'chatgpt-fluent-connector'); ?></option>
                            <?php foreach ($prompts as $prompt) : ?>
                                <option value="<?php echo esc_attr($prompt->ID); ?>" <?php selected(isset($filters['prompt_id']) ? $filters['prompt_id'] : '', $prompt->ID); ?>>
                                    <?php echo esc_html($prompt->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Provider filter -->
                        <select name="provider">
                            <option value=""><?php _e('All Providers', 'chatgpt-fluent-connector'); ?></option>
                            <option value="openai" <?php selected(isset($filters['provider']) ? $filters['provider'] : '', 'openai'); ?>><?php _e('OpenAI (ChatGPT)', 'chatgpt-fluent-connector'); ?></option>
                            <option value="gemini" <?php selected(isset($filters['provider']) ? $filters['provider'] : '', 'gemini'); ?>><?php _e('Google Gemini', 'chatgpt-fluent-connector'); ?></option>
                            <option value="claude" <?php selected(isset($filters['provider']) ? $filters['provider'] : '', 'claude'); ?>><?php _e('Anthropic Claude', 'chatgpt-fluent-connector'); ?></option>
                        </select>

                        <!-- Status filter -->
                        <select name="status">
                            <option value=""><?php _e('All Statuses', 'chatgpt-fluent-connector'); ?></option>
                            <option value="success" <?php selected(isset($filters['status']) ? $filters['status'] : '', 'success'); ?>><?php _e('Success', 'chatgpt-fluent-connector'); ?></option>
                            <option value="error" <?php selected(isset($filters['status']) ? $filters['status'] : '', 'error'); ?>><?php _e('Error', 'chatgpt-fluent-connector'); ?></option>
                        </select>

                        <!-- Date filters -->
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

            <!-- Logs Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php _e('ID', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 150px;"><?php _e('Date', 'chatgpt-fluent-connector'); ?></th>
                        <th><?php _e('Prompt', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 60px;"><?php _e('Form', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 60px;"><?php _e('Entry', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 80px;"><?php _e('Status', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 90px;"><?php _e('Provider', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 100px;"><?php _e('Model', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 120px;" title="<?php _e('Prompt / Completion / Total', 'chatgpt-fluent-connector'); ?>"><?php _e('Tokens', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 60px;"><?php _e('Time', 'chatgpt-fluent-connector'); ?></th>
                        <th style="width: 100px;"><?php _e('Actions', 'chatgpt-fluent-connector'); ?></th>
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
                                    $token_display = '<span style="color: #e74c3c;" title="' . esc_attr(sprintf(__('Warning: %s%% of model limit', 'chatgpt-fluent-connector'), round($usage_percentage))) . '">' . $token_display . ' ⚠️</span>';
                                }
                            }
                            ?>
                            <tr class="<?php echo esc_attr($row_class); ?>">
                                <td><?php echo esc_html($log->id); ?></td>
                                <td>
                                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at))); ?>
                                </td>
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
                                <td><?php echo $token_display; ?></td>
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
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="11"><?php _e('No logs found matching your criteria.', 'chatgpt-fluent-connector'); ?></td>
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
        </div>
        <?php
    }

    /**
     * Render log details view with token information
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
        ?>
        <div class="wrap">
            <h1>
                <?php _e('Log Details', 'chatgpt-fluent-connector'); ?>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=sfaic_prompt&page=sfaic-response-logs')); ?>" class="page-title-action">
                    <?php _e('Back to Logs', 'chatgpt-fluent-connector'); ?>
                </a>
            </h1>

            <div class="metabox-holder">
                <!-- Main Info -->
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

                <!-- Token Usage -->
                <?php if (!empty($log->total_tokens)) : ?>
                    <div class="postbox">
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
                                <tr>
                                    <th><?php _e('Model Token Limit:', 'chatgpt-fluent-connector'); ?></th>
                                    <td><?php echo number_format_i18n($model_limits['max_tokens']); ?></td>
                                </tr>
                            </table>

                            <?php if ($usage_percentage > 80) : ?>
                                <div class="notice notice-warning inline" style="margin-top: 15px;">
                                    <p><?php _e('⚠️ This request used more than 80% of the model\'s token limit. Consider optimizing your prompts or using a model with a higher token limit.', 'chatgpt-fluent-connector'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Prompt Template -->
                <?php if (!empty($log->prompt_template)) : ?>
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('Prompt Template', 'chatgpt-fluent-connector'); ?></span></h2>
                        <div class="inside">
                            <div class="sfaic-content-box">
                                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($log->prompt_template); ?></pre>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Processed Prompt -->
                <div class="postbox">
                    <h2 class="hndle"><span><?php _e('Processed Prompt (Sent to API)', 'chatgpt-fluent-connector'); ?></span></h2>
                    <div class="inside">
                        <div class="sfaic-content-box">
                            <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($log->user_prompt); ?></pre>
                        </div>
                    </div>
                </div>

                <!-- AI Response -->
                <div class="postbox">
                    <h2 class="hndle"><span><?php
                            $provider_name = '';
                            switch ($log->provider) {
                                case 'gemini':
                                    $provider_name = __('Google Gemini', 'chatgpt-fluent-connector');
                                    break;
                                case 'claude':
                                    $provider_name = __('Claude', 'chatgpt-fluent-connector');
                                    break;
                                default:
                                    $provider_name = __('ChatGPT', 'chatgpt-fluent-connector');
                                    break;
                            }
                            echo sprintf(__('%s Response', 'chatgpt-fluent-connector'), $provider_name);
                            ?></span></h2>
                    <div class="inside">
                        <?php if ($log->status === 'success' && !empty($log->ai_response)) : ?>
                            <div class="sfaic-response-tabs">
                                <a href="#" class="sfaic-view-toggle active" data-target="sfaic-raw-response"><?php _e('Raw Response', 'chatgpt-fluent-connector'); ?></a>
                                <a href="#" class="sfaic-view-toggle" data-target="sfaic-rendered-response"><?php _e('Rendered Preview', 'chatgpt-fluent-connector'); ?></a>
                                <a href="#" class="button button-small sfaic-copy-response" style="float:right;"><?php _e('Copy to Clipboard', 'chatgpt-fluent-connector'); ?></a>
                            </div>

                            <div id="sfaic-raw-response" class="sfaic-response-view">
                                <div class="sfaic-content-box">
                                    <pre class="sfaic-code-block" style="background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 3px; padding: 15px; font-family: 'Consolas', 'Monaco', 'Courier New', monospace; font-size: 13px; line-height: 1.6; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; max-height: 500px; overflow-y: auto;"><?php echo esc_html($log->ai_response); ?></pre>
                                </div>
                            </div>

                            <div id="sfaic-rendered-response" class="sfaic-response-view" style="display:none;">
                                <div class="sfaic-rendered-response" style="padding: 15px; background-color: #fff; border: 1px solid #e0e0e0; border-radius: 4px; line-height: 1.6; max-height: 500px; overflow-y: auto;">
                                    <div class="notice notice-info inline" style="margin-bottom: 15px;">
                                        <p><strong><?php _e('Preview Note:', 'chatgpt-fluent-connector'); ?></strong> <?php _e('This is a safe preview of how the HTML would render. Some elements may be stripped for security.', 'chatgpt-fluent-connector'); ?></p>
                                    </div>
                                    <?php 
                                    // Safely render HTML with restricted tags
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
                                        'pre' => array(),
                                        'div' => array('class' => array(), 'style' => array()),
                                        'span' => array('class' => array(), 'style' => array()),
                                        'a' => array('href' => array(), 'target' => array()),
                                        'img' => array('src' => array(), 'alt' => array(), 'width' => array(), 'height' => array(), 'style' => array()),
                                        'table' => array('class' => array(), 'style' => array()),
                                        'thead' => array(),
                                        'tbody' => array(),
                                        'tr' => array(),
                                        'th' => array(),
                                        'td' => array(),
                                    );
                                    echo wp_kses($log->ai_response, $allowed_html);
                                    ?>
                                </div>
                            </div>
                        <?php elseif ($log->status === 'error') : ?>
                            <div class="notice notice-error inline">
                                <p><?php _e('The AI request failed.', 'chatgpt-fluent-connector'); ?></p>
                                <?php if (!empty($log->error_message)) : ?>
                                    <p><strong><?php _e('Error:', 'chatgpt-fluent-connector'); ?></strong></p>
                                    <pre class="sfaic-code-block" style="background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; font-family: 'Consolas', 'Monaco', 'Courier New', monospace; font-size: 13px; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($log->error_message); ?></pre>
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <div class="notice notice-warning inline">
                                <p><?php _e('No response content available.', 'chatgpt-fluent-connector'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- API Request/Response JSON -->
                <div class="postbox">
                    <h2 class="hndle"><span><?php _e('API Request/Response Data', 'chatgpt-fluent-connector'); ?></span></h2>
                    <div class="inside">
                        <div style="margin-bottom: 15px;">
                            <?php
                            $download_nonce = wp_create_nonce('sfaic_download_json');
                            ?>
                            <?php if (!empty($log->request_json)) : ?>
                                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=sfaic_download_request_json&log_id=' . $log->id . '&nonce=' . $download_nonce)); ?>" 
                                   class="button button-secondary sfaic-download-json" 
                                   data-type="request" 
                                   data-log-id="<?php echo esc_attr($log->id); ?>">
                                    <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                    <?php _e('Download Request JSON', 'chatgpt-fluent-connector'); ?>
                                </a>
                            <?php else : ?>
                                <button class="button button-secondary" disabled>
                                    <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                    <?php _e('No Request JSON Available', 'chatgpt-fluent-connector'); ?>
                                </button>
                            <?php endif; ?>

                            <?php if (!empty($log->response_json)) : ?>
                                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=sfaic_download_response_json&log_id=' . $log->id . '&nonce=' . $download_nonce)); ?>" 
                                   class="button button-secondary sfaic-download-json" 
                                   data-type="response" 
                                   data-log-id="<?php echo esc_attr($log->id); ?>"
                                   style="margin-left: 10px;">
                                    <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                    <?php _e('Download Response JSON', 'chatgpt-fluent-connector'); ?>
                                </a>
                            <?php else : ?>
                                <button class="button button-secondary" style="margin-left: 10px;" disabled>
                                    <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                    <?php _e('No Response JSON Available', 'chatgpt-fluent-connector'); ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($log->request_json) || !empty($log->response_json)) : ?>
                            <div class="notice notice-info inline">
                                <p><?php _e('JSON data is available for download. Click the buttons above to download the raw API request and response data.', 'chatgpt-fluent-connector'); ?></p>
                            </div>
                        <?php else : ?>
                            <div class="notice notice-warning inline">
                                <p><?php _e('JSON logging was not enabled when this request was made. Enable debug mode to capture API request/response data.', 'chatgpt-fluent-connector'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Form Data -->
                <?php if ($entry_data && is_array($entry_data)) : ?>
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('Form Submission Data', 'chatgpt-fluent-connector'); ?></span></h2>
                        <div class="inside">
                            <table class="widefat striped sfaic-form-data-table">
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
                                        if (!is_scalar($field_key) || strpos($field_key, '_') === 0) {
                                            continue;
                                        }

                                        if (is_array($field_value)) {
                                            $field_value = implode(', ', $field_value);
                                        } elseif (!is_scalar($field_value)) {
                                            continue;
                                        }

                                        $display_label = isset($field_labels[$field_key]) ? $field_labels[$field_key] : $field_key;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($display_label); ?></strong></td>
                                            <td><?php echo esc_html($field_value); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Get all logs with provider filtering capability (existing method from original)
     */
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

    /**
     * Count all logs with filters (existing method from original)
     */
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

    /**
     * Get form field labels for a prompt (existing method from original)
     */
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
}