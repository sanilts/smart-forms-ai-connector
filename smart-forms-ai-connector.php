<?php
/**
 * Plugin Name: AromaPro Smart Forms AI Connector
 * Plugin URI: https://aromapro.com/
 * Description: Connect Fluent Forms with ChatGPT, Google Gemini, or Anthropic Claude to generate AI responses for form submissions and create PDF documents with background processing
 * Version: 2.0.10
 * Author: Sanil T S
 * Author URI: https://www.fb.com/sanilts
 * License: GPL-2.0+
 * Text Domain: chatgpt-fluent-connector
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SFAIC_DIR', plugin_dir_path(__FILE__));
define('SFAIC_URL', plugin_dir_url(__FILE__));
define('SFAIC_VERSION', '2.0.9');

/**
 * Main plugin class
 */
class SFAIC_Main {

    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * Settings class instance
     */
    public $settings;

    /**
     * OpenAI API class instance
     */
    public $api;

    /**
     * Gemini API class instance
     */
    public $gemini_api;

    /**
     * Claude API class instance
     */
    public $claude_api;

    /**
     * Prompt CPT class instance
     */
    public $prompt_cpt;

    /**
     * Fluent Forms integration class instance
     */
    public $fluent_integration;

    /**
     * Response logger class instance
     */
    public $response_logger;

    /**
     * HTML Template uploader class instance
     */
    public $html_template_uploader;

    /**
     * PDF Generator class instance
     */
    public $pdf_generator;

    /**
     * Background Job Manager class instance
     */
    public $background_job_manager;

    /**
     * Get the singleton instance
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Include required files on plugins_loaded
        add_action('plugins_loaded', array($this, 'include_files'), 5);

        // Initialize plugin after files are loaded
        add_action('plugins_loaded', array($this, 'init_plugin'), 10);
    }

    /**
     * Include the required files with proper error handling
     */
    public function include_files() {
        // Check if files exist before including them
        $files_to_include = array(
            'includes/class-ai-settings.php',
            'includes/class-openai-api.php',
            'includes/class-gemini-api.php',
            'includes/class-claude-api.php',
            'includes/class-ai-prompt-manager.php',
            'includes/class-forms-integration.php',
            'includes/class-response-logger.php',
            'includes/class-template-manager.php',
            'includes/class-pdf-generator.php',
            'includes/class-background-job-manager.php'  // Background job manager
        );

        foreach ($files_to_include as $file) {
            $file_path = SFAIC_DIR . $file;

            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                // Log missing file error
                error_log('CGPTFC: Missing required file: ' . $file_path);

                // Show admin notice for missing file
                add_action('admin_notices', function () use ($file) {
                    echo '<div class="notice notice-error"><p>';
                    echo sprintf(
                            __('AI API Connector: Missing required file %s. Please ensure all plugin files are properly uploaded.', 'chatgpt-fluent-connector'),
                            esc_html($file)
                    );
                    echo '</p></div>';
                });

                return false;
            }
        }

        return true;
    }

    /**
     * Initialize the plugin after all files are included
     */
    public function init_plugin() {
        // Check if all required classes exist before instantiating
        $required_classes = array(
            'SFAIC_Settings',
            'SFAIC_OpenAI_API',
            'SFAIC_Gemini_API',
            'SFAIC_Claude_API',
            'SFAIC_Prompt_Manager',
            'SFAIC_Forms_Integration',
            'SFAIC_Response_Logger',
            'SFAIC_Template_Manager',
            'SFAIC_PDF_Generator',
            'SFAIC_Background_Job_Manager'
        );

        foreach ($required_classes as $class_name) {
            if (!class_exists($class_name)) {
                error_log('CGPTFC: Missing required class: ' . $class_name);

                add_action('admin_notices', function () use ($class_name) {
                    echo '<div class="notice notice-error"><p>';
                    echo sprintf(
                            __('AI API Connector: Required class %s not found. Please check your plugin files.', 'chatgpt-fluent-connector'),
                            esc_html($class_name)
                    );
                    echo '</p></div>';
                });

                return false;
            }
        }

        // Instantiate classes only if all are available
        try {
            $this->settings = new SFAIC_Settings();
            $this->api = new SFAIC_OpenAI_API();
            $this->gemini_api = new SFAIC_Gemini_API();
            $this->claude_api = new SFAIC_Claude_API();
            $this->prompt_cpt = new SFAIC_Prompt_Manager();
            $this->response_logger = new SFAIC_Response_Logger();
            $this->background_job_manager = new SFAIC_Background_Job_Manager();  // Initialize before forms integration
            $this->fluent_integration = new SFAIC_Forms_Integration();
            $this->html_template_uploader = new SFAIC_Template_Manager();
            $this->pdf_generator = new SFAIC_PDF_Generator();

            // Register hooks only after successful initialization
            $this->register_hooks();
        } catch (Exception $e) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo sprintf(
                        __('AI API Connector: Initialization error - %s', 'chatgpt-fluent-connector'),
                        esc_html($e->getMessage())
                );
                echo '</p></div>';
            });
        }
    }

    /**
     * Register plugin hooks
     */
    private function register_hooks() {
        // Register hooks
        add_action('fluentform/submission_inserted', array($this->fluent_integration, 'handle_form_submission'), 20, 3);

        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'plugin_activation'));

        // Register deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'plugin_deactivation'));

        // Load text domain
        add_action('init', array($this, 'load_textdomain'));

        // Add admin notice for first-time setup
        add_action('admin_notices', array($this, 'admin_setup_notice'));

        // Add CSS for admin
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_styles'));

        // Add admin dashboard widget for background jobs
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // Add admin footer text
        add_filter('admin_footer_text', array($this, 'admin_footer_text'), 1);
    }

    /**
     * Plugin activation
     */
    public function plugin_activation() {
        // Make sure the classes are loaded
        if (!isset($this->response_logger)) {
            $this->include_files();
            $this->init_plugin();
        }

        // Create response logs table
        if (isset($this->response_logger)) {
            $this->response_logger->create_logs_table();
            $this->response_logger->check_table_version();
        }

        // Create background jobs table
        if (isset($this->background_job_manager)) {
            $this->background_job_manager->create_jobs_table();
        }

        // Set default options for OpenAI
        if (!get_option('sfaic_api_endpoint')) {
            update_option('sfaic_api_endpoint', 'https://api.openai.com/v1/chat/completions');
        }

        if (!get_option('sfaic_model')) {
            update_option('sfaic_model', 'gpt-3.5-turbo');
        }

        // Set default options for Gemini
        if (!get_option('sfaic_gemini_api_endpoint')) {
            update_option('sfaic_gemini_api_endpoint', 'https://generativelanguage.googleapis.com/v1beta/models/');
        }

        if (!get_option('sfaic_gemini_model')) {
            update_option('sfaic_gemini_model', 'gemini-1.5-pro-latest');
        }

        // Set default options for Claude
        if (!get_option('sfaic_claude_api_endpoint')) {
            update_option('sfaic_claude_api_endpoint', 'https://api.anthropic.com/v1/messages');
        }

        if (!get_option('sfaic_claude_model')) {
            update_option('sfaic_claude_model', 'claude-opus-4-20250514');
        }

        // Set default API provider
        if (!get_option('sfaic_api_provider')) {
            update_option('sfaic_api_provider', 'openai');
        }

        // Create uploads directory for PDFs
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/ai-pdfs';
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);

            // Create .htaccess file to protect PDF directory
            $htaccess_content = "# Protect PDF files\n";
            $htaccess_content .= "<Files *.pdf>\n";
            $htaccess_content .= "    # Allow access to PDFs\n";
            $htaccess_content .= "    Order allow,deny\n";
            $htaccess_content .= "    Allow from all\n";
            $htaccess_content .= "</Files>\n";

            file_put_contents($pdf_dir . '/.htaccess', $htaccess_content);
        }

        // Flush rewrite rules after creating custom post type
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function plugin_deactivation() {
        // Clear all scheduled cron events
        wp_clear_scheduled_hook('sfaic_process_background_job');
        wp_clear_scheduled_hook('sfaic_cleanup_old_jobs');
        wp_clear_scheduled_hook('sfaic_cleanup_stuck_jobs_periodic');
        
        // Note: We don't delete the database tables on deactivation
        // as users might want to keep their data when temporarily deactivating
    }

    /**
     * Add dashboard widget for background jobs monitoring - WordPress style
     */
    public function add_dashboard_widget() {
        if (current_user_can('manage_options') && isset($this->background_job_manager)) {
            wp_add_dashboard_widget(
                'sfaic_background_jobs_widget',
                __('AI Connector - Background Jobs', 'chatgpt-fluent-connector'),
                array($this, 'render_dashboard_widget')
            );
        }
    }

    /**
     * Render dashboard widget - WordPress style
     */
    public function render_dashboard_widget() {
        if (!isset($this->background_job_manager)) {
            echo '<p>' . __('Background job manager not available.', 'chatgpt-fluent-connector') . '</p>';
            return;
        }

        $stats = $this->background_job_manager->get_job_statistics();
        
        // Check if ANY prompt has background processing enabled
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
        
        $background_enabled = !empty($prompts_with_bg);
        $jobs_url = admin_url('edit.php?post_type=sfaic_prompt&page=sfaic-background-jobs');
        ?>
        <div class="sfaic-dashboard-widget">
            <div class="sfaic-widget-status">
                <p>
                    <span class="status-indicator <?php echo $background_enabled ? 'enabled' : 'disabled'; ?>"></span>
                    <?php echo $background_enabled ? __('Background Processing: Active', 'chatgpt-fluent-connector') : __('Background Processing: No Active Prompts', 'chatgpt-fluent-connector'); ?>
                </p>
                <?php if (!$background_enabled): ?>
                <p style="font-size: 12px; color: #646970;">
                    <?php _e('Enable background processing in individual prompt settings.', 'chatgpt-fluent-connector'); ?>
                </p>
                <?php endif; ?>
            </div>
            
            <div class="sfaic-widget-stats">
                <div class="stat-row">
                    <span class="stat-label"><?php _e('Pending Jobs:', 'chatgpt-fluent-connector'); ?></span>
                    <span class="stat-value pending"><?php echo esc_html($stats->pending_jobs ?? 0); ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label"><?php _e('Processing:', 'chatgpt-fluent-connector'); ?></span>
                    <span class="stat-value processing"><?php echo esc_html($stats->processing_jobs ?? 0); ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label"><?php _e('Completed Today:', 'chatgpt-fluent-connector'); ?></span>
                    <span class="stat-value completed"><?php echo esc_html($stats->completed_jobs ?? 0); ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label"><?php _e('Failed:', 'chatgpt-fluent-connector'); ?></span>
                    <span class="stat-value failed"><?php echo esc_html($stats->failed_jobs ?? 0); ?></span>
                </div>
            </div>
            
            <div class="sfaic-widget-actions">
                <a href="<?php echo esc_url($jobs_url); ?>" class="button button-primary button-small">
                    <?php _e('View All Jobs', 'chatgpt-fluent-connector'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=sfaic-settings')); ?>" class="button button-secondary button-small">
                    <?php _e('Settings', 'chatgpt-fluent-connector'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Display admin notice for first-time setup - WordPress style
     */
    public function admin_setup_notice() {
        $screen = get_current_screen();

        // Only show on the plugins page or our settings pages
        if (!in_array($screen->id, array('plugins', 'settings_page_sfaic-settings', 'sfaic_prompt_page_sfaic-background-jobs'))) {
            return;
        }

        // Check if API keys are missing for the selected provider
        $provider = get_option('sfaic_api_provider', 'openai');
        $api_key_option = '';

        switch ($provider) {
            case 'openai':
                $api_key_option = 'sfaic_api_key';
                break;
            case 'gemini':
                $api_key_option = 'sfaic_gemini_api_key';
                break;
            case 'claude':
                $api_key_option = 'sfaic_claude_api_key';
                break;
        }

        if (!empty($api_key_option) && empty(get_option($api_key_option))) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php
                    switch ($provider) {
                        case 'openai':
                            _e('<strong>AI API Connector:</strong> Please configure your OpenAI API key to start using the plugin.', 'chatgpt-fluent-connector');
                            break;
                        case 'gemini':
                            _e('<strong>AI API Connector:</strong> Please configure your Gemini API key to start using the plugin.', 'chatgpt-fluent-connector');
                            break;
                        case 'claude':
                            _e('<strong>AI API Connector:</strong> Please configure your Claude API key to start using the plugin.', 'chatgpt-fluent-connector');
                            break;
                    }
                    ?>
                    <a href="<?php echo admin_url('options-general.php?page=sfaic-settings'); ?>" class="button button-primary" style="margin-left: 10px;">
                        <?php _e('Configure Now', 'chatgpt-fluent-connector'); ?>
                    </a>
                </p>
            </div>
            <?php
        }

        // Check for prompts without background processing
        $prompts_without_bg = get_posts(array(
            'post_type' => 'sfaic_prompt',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_sfaic_enable_background_processing',
                    'value' => '0',
                    'compare' => '='
                ),
                array(
                    'key' => '_sfaic_enable_background_processing',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'posts_per_page' => 1,
            'fields' => 'ids'
        ));
        
        if (!empty($prompts_without_bg) && $screen->id !== 'sfaic_prompt_page_sfaic-background-jobs') {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php _e('<strong>AI API Connector:</strong> Some prompts have background processing disabled. Users may experience delays when submitting forms.', 'chatgpt-fluent-connector'); ?>
                    <a href="<?php echo admin_url('edit.php?post_type=sfaic_prompt'); ?>" class="button button-secondary" style="margin-left: 10px;">
                        <?php _e('Review Prompt Settings', 'chatgpt-fluent-connector'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Enqueue admin styles - WordPress compatible
     */
    public function admin_enqueue_styles($hook) {
        // Load on our settings page and prompt edit pages
        if ($hook === 'settings_page_sfaic-settings' ||
                ($hook === 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) === 'sfaic_prompt') ||
                ($hook === 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'sfaic_prompt') ||
                $hook === 'sfaic_prompt_page_sfaic-background-jobs') {

            wp_enqueue_style(
                    'sfaic-admin-styles',
                    SFAIC_URL . 'assets/css/admin-styles.css',
                    array(),
                    SFAIC_VERSION
            );
        }

        // Load dashboard widget styles on dashboard
        if ($hook === 'index.php') {
            wp_add_inline_style('dashboard', '
                .sfaic-dashboard-widget .status-indicator {
                    display: inline-block;
                    width: 8px;
                    height: 8px;
                    border-radius: 50%;
                    margin-right: 5px;
                    vertical-align: middle;
                }
                .sfaic-dashboard-widget .status-indicator.enabled {
                    background-color: #00a32a;
                }
                .sfaic-dashboard-widget .status-indicator.disabled {
                    background-color: #d63638;
                }
                .sfaic-widget-stats {
                    margin: 15px 0;
                }
                .sfaic-widget-stats .stat-row {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 5px;
                    font-size: 13px;
                }
                .sfaic-widget-stats .stat-value {
                    font-weight: 600;
                }
                .sfaic-widget-stats .stat-value.pending {
                    color: #dba617;
                }
                .sfaic-widget-stats .stat-value.processing {
                    color: #00a0d2;
                }
                .sfaic-widget-stats .stat-value.completed {
                    color: #00a32a;
                }
                .sfaic-widget-stats .stat-value.failed {
                    color: #d63638;
                }
                .sfaic-widget-actions {
                    margin-top: 15px;
                }
                .sfaic-widget-actions .button {
                    margin-right: 5px;
                    margin-bottom: 5px;
                }
            ');
        }
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('chatgpt-fluent-connector', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Admin footer text - WordPress style
     */
    public function admin_footer_text($footer_text) {
        $screen = get_current_screen();
        
        if ($screen && in_array($screen->id, array(
            'sfaic_prompt_page_sfaic-background-jobs',
            'settings_page_sfaic-settings',
            'edit-sfaic_prompt',
            'sfaic_prompt'
        ))) {
            $footer_text = sprintf(
                __('Thank you for using %s', 'chatgpt-fluent-connector'),
                '<strong>' . __('AI API Connector', 'chatgpt-fluent-connector') . '</strong>'
            );
        }
        
        return $footer_text;
    }

    /**
     * Get the active AI API based on settings
     * 
     * @return object The API class instance
     */
    public function get_active_api() {
        $provider = get_option('sfaic_api_provider', 'openai');

        switch ($provider) {
            case 'gemini':
                return $this->gemini_api;
            case 'claude':
                return $this->claude_api;
            default:
                return $this->api; // Default to OpenAI
        }
    }
}

/**
 * Returns the main instance of the plugin
 */
function sfaic_main() {
    return SFAIC_Main::get_instance();
}

// Initialize the plugin
sfaic_main();

/**
 * Background Jobs Diagnostic Tool
 * Temporary diagnostic functionality
 */
add_action('admin_menu', function() {
    if (current_user_can('manage_options')) {
        add_submenu_page(
            'tools.php',
            'AI Jobs Diagnostics',
            'AI Jobs Diagnostics',
            'manage_options',
            'ai-jobs-diagnostics',
            'sfaic_render_diagnostics_page'
        );
    }
});

function sfaic_render_diagnostics_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    // Handle actions
    if (isset($_POST['action'])) {
        $action = sanitize_text_field($_POST['action']);
        
        switch ($action) {
            case 'reset_all_jobs':
                sfaic_reset_all_stuck_jobs();
                echo '<div class="notice notice-success"><p>All stuck jobs have been reset!</p></div>';
                break;
                
            case 'force_cron':
                sfaic_force_cron_setup();
                echo '<div class="notice notice-success"><p>Cron events have been reset and scheduled!</p></div>';
                break;
                
            case 'process_immediate':
                sfaic_process_jobs_immediately();
                echo '<div class="notice notice-success"><p>Attempted immediate job processing!</p></div>';
                break;
        }
    }

    // Get diagnostic info
    $diagnostics = sfaic_get_diagnostic_info();
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">AI Background Jobs Diagnostics</h1>
        <hr class="wp-header-end">
        
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">System Status</h2>
            </div>
            <div class="inside">
                <table class="form-table">
                    <tr>
                        <th scope="row">WordPress Cron</th>
                        <td>
                            <?php if ($diagnostics['wp_cron_disabled']): ?>
                                <span style="color: #d63638;">❌ DISABLED</span>
                                <p class="description">WordPress cron is disabled. This is likely the cause of stuck jobs.</p>
                            <?php else: ?>
                                <span style="color: #00a32a;">✅ ENABLED</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Next Scheduled Job</th>
                        <td>
                            <?php if ($diagnostics['next_scheduled']): ?>
                                <?php echo esc_html($diagnostics['next_scheduled']); ?>
                            <?php else: ?>
                                <span style="color: #d63638;">❌ No cron scheduled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Pending Jobs</th>
                        <td>
                            <?php if ($diagnostics['pending_jobs'] > 0): ?>
                                <span style="color: #dba617;">⚠️ <?php echo $diagnostics['pending_jobs']; ?> pending jobs</span>
                            <?php else: ?>
                                <span style="color: #00a32a;">✅ No pending jobs</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Stuck Jobs</th>
                        <td>
                            <?php if ($diagnostics['stuck_jobs'] > 0): ?>
                                <span style="color: #d63638;">❌ <?php echo $diagnostics['stuck_jobs']; ?> stuck jobs</span>
                            <?php else: ?>
                                <span style="color: #00a32a;">✅ No stuck jobs</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Processing Jobs</th>
                        <td><?php echo $diagnostics['processing_jobs']; ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Server Time</th>
                        <td><?php echo current_time('mysql'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Last Cron Run</th>
                        <td>
                            <?php 
                            $last_run = get_option('sfaic_last_cron_run', 0);
                            if ($last_run > 0) {
                                $minutes_ago = (time() - $last_run) / 60;
                                echo date('Y-m-d H:i:s', $last_run) . ' (' . round($minutes_ago) . ' minutes ago)';
                                if ($minutes_ago > 5) {
                                    echo ' <span style="color: #d63638;">⚠️ Too long ago</span>';
                                }
                            } else {
                                echo '<span style="color: #d63638;">❌ Never</span>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">Recent Stuck Jobs</h2>
            </div>
            <div class="inside">
                <?php $stuck_jobs = sfaic_get_stuck_jobs(); ?>
                <?php if (!empty($stuck_jobs)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Job ID</th>
                                <th>User</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Age (minutes)</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stuck_jobs as $job): ?>
                                <tr>
                                    <td><?php echo esc_html($job->id); ?></td>
                                    <td><?php echo esc_html($job->user_name ?: 'Unknown'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($job->status); ?>">
                                            <?php echo esc_html(ucfirst($job->status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($job->created_at); ?></td>
                                    <td>
                                        <?php 
                                        $age = (time() - strtotime($job->created_at)) / 60;
                                        echo round($age);
                                        if ($age > 10) echo ' <span style="color: #d63638;">⚠️</span>';
                                        ?>
                                    </td>
                                    <td><?php echo esc_html(substr($job->error_message ?: 'No error', 0, 50)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No stuck jobs found.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">Diagnostic Actions</h2>
            </div>
            <div class="inside">
                <form method="post">
                    <p>
                        <button type="submit" name="action" value="reset_all_jobs" class="button button-primary" 
                                onclick="return confirm('This will reset all stuck jobs to pending status. Continue?')">
                            Reset All Stuck Jobs
                        </button>
                        <span class="description">Reset all jobs stuck in processing or old pending jobs</span>
                    </p>
                    
                    <p>
                        <button type="submit" name="action" value="force_cron" class="button button-secondary">
                            Force Cron Setup
                        </button>
                        <span class="description">Clear and reschedule all cron events</span>
                    </p>
                    
                    <p>
                        <button type="submit" name="action" value="process_immediate" class="button button-secondary">
                            Process Jobs Immediately
                        </button>
                        <span class="description">Attempt to process pending jobs right now</span>
                    </p>
                </form>
            </div>
        </div>

        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">Recommended Solutions</h2>
            </div>
            <div class="inside">
                <ol>
                    <?php if ($diagnostics['wp_cron_disabled']): ?>
                        <li><strong>WordPress Cron is disabled!</strong> 
                            <ul>
                                <li>Remove <code>define('DISABLE_WP_CRON', true);</code> from wp-config.php, OR</li>
                                <li>Set up a real cron job: <code>*/1 * * * * wget -q -O - "<?php echo site_url('/wp-cron.php'); ?>" >/dev/null 2>&1</code></li>
                            </ul>
                        </li>
                    <?php endif; ?>
                    
                    <?php if ($diagnostics['pending_jobs'] > 0): ?>
                        <li><strong>Pending jobs found:</strong> Click "Reset All Stuck Jobs" above</li>
                    <?php endif; ?>
                    
                    <li><strong>Add to wp-config.php for better debugging:</strong>
                        <code>define('WP_DEBUG', true);<br>
                        define('WP_DEBUG_LOG', true);</code>
                    </li>
                    
                    <li><strong>Increase PHP limits in wp-config.php:</strong>
                        <code>ini_set('max_execution_time', 300);<br>
                        ini_set('memory_limit', '256M');</code>
                    </li>
                </ol>
            </div>
        </div>

        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">Manual Test</h2>
            </div>
            <div class="inside">
                <p>Test URL to trigger cron manually:</p>
                <p><a href="<?php echo site_url('/wp-cron.php'); ?>" target="_blank" class="button"><?php echo site_url('/wp-cron.php'); ?></a></p>
                <p class="description">Click this link to manually trigger WordPress cron. If it loads quickly, cron is working.</p>
            </div>
        </div>
    </div>
    <?php
}

function sfaic_get_diagnostic_info() {
    global $wpdb;
    
    $jobs_table = $wpdb->prefix . 'sfaic_background_jobs';
    
    return array(
        'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'next_scheduled' => wp_next_scheduled('sfaic_process_background_job') ? 
            date('Y-m-d H:i:s', wp_next_scheduled('sfaic_process_background_job')) : false,
        'pending_jobs' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$jobs_table} WHERE status = %s",
            'pending'
        )) ?: 0,
        'processing_jobs' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$jobs_table} WHERE status = %s",
            'processing'
        )) ?: 0,
        'stuck_jobs' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$jobs_table} 
             WHERE (status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
             OR (status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))",
        )) ?: 0,
    );
}

function sfaic_get_stuck_jobs() {
    global $wpdb;
    
    $jobs_table = $wpdb->prefix . 'sfaic_background_jobs';
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$jobs_table} 
         WHERE (status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
         OR (status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
         ORDER BY created_at DESC 
         LIMIT 20"
    ));
}

function sfaic_reset_all_stuck_jobs() {
    global $wpdb;
    
    $jobs_table = $wpdb->prefix . 'sfaic_background_jobs';
    
    // Reset processing jobs that are stuck
    $wpdb->query($wpdb->prepare(
        "UPDATE {$jobs_table} 
         SET status = 'pending', 
             started_at = NULL,
             error_message = CONCAT(COALESCE(error_message, ''), ' [Reset by diagnostic tool]'),
             updated_at = %s,
             scheduled_at = %s
         WHERE status = 'processing' 
         AND started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
        current_time('mysql'),
        current_time('mysql')
    ));
    
    // Reset very old pending jobs
    $wpdb->query($wpdb->prepare(
        "UPDATE {$jobs_table} 
         SET error_message = CONCAT(COALESCE(error_message, ''), ' [Reset by diagnostic tool]'),
             updated_at = %s,
             scheduled_at = %s
         WHERE status = 'pending' 
         AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
        current_time('mysql'),
        current_time('mysql')
    ));
}

function sfaic_force_cron_setup() {
    // Clear all existing cron events
    wp_clear_scheduled_hook('sfaic_process_background_job');
    wp_clear_scheduled_hook('sfaic_cleanup_old_jobs');
    wp_clear_scheduled_hook('sfaic_cleanup_stuck_jobs_periodic');
    
    // Reschedule them
    wp_schedule_event(time() + 30, 'every_30_seconds', 'sfaic_process_background_job');
    wp_schedule_event(time() + 60, 'daily', 'sfaic_cleanup_old_jobs');
    wp_schedule_event(time() + 45, 'every_30_seconds', 'sfaic_cleanup_stuck_jobs_periodic');
    
    // Update last run time
    update_option('sfaic_last_cron_run', time());
}

function sfaic_process_jobs_immediately() {
    if (isset(sfaic_main()->background_job_manager)) {
        sfaic_main()->background_job_manager->process_background_job();
    }
}


/**
 * Temporary debugging function for field mapping issues
 * Add this to your smart-forms-ai-connector.php file temporarily
 */
function sfaic_debug_field_mapping($prompt_id, $form_data) {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    error_log('SFAIC DEBUG - Field Mapping Check:');
    error_log('Prompt ID: ' . $prompt_id);
    
    // Check custom field mappings
    $first_name_field = get_post_meta($prompt_id, '_sfaic_first_name_field', true);
    $last_name_field = get_post_meta($prompt_id, '_sfaic_last_name_field', true);
    $email_field = get_post_meta($prompt_id, '_sfaic_email_field_mapping', true);
    
    error_log('Custom First Name Field: ' . ($first_name_field ?: 'Not set'));
    error_log('Custom Last Name Field: ' . ($last_name_field ?: 'Not set'));
    error_log('Custom Email Field: ' . ($email_field ?: 'Not set'));
    
    // Log available form data fields
    error_log('Available form data fields:');
    if (is_array($form_data)) {
        foreach ($form_data as $key => $value) {
            if (!is_scalar($key)) continue;
            $value_preview = is_array($value) ? '[array]' : substr((string)$value, 0, 50);
            error_log("  - {$key}: {$value_preview}");
        }
    } else {
        error_log('Form data is not an array: ' . gettype($form_data));
    }
    
    // Test extraction with current logic
    if (!empty($first_name_field) && isset($form_data[$first_name_field])) {
        error_log('Found first name from custom mapping: ' . $form_data[$first_name_field]);
    }
    if (!empty($last_name_field) && isset($form_data[$last_name_field])) {
        error_log('Found last name from custom mapping: ' . $form_data[$last_name_field]);
    }
    if (!empty($email_field) && isset($form_data[$email_field])) {
        error_log('Found email from custom mapping: ' . $form_data[$email_field]);
    }
}

// Add this hook temporarily to debug form submissions
add_action('fluentform/submission_inserted', function($entryId, $formData, $form) {
    // Get the prompt ID associated with this form
    $prompts = get_posts(array(
        'post_type' => 'sfaic_prompt',
        'meta_query' => array(
            array(
                'key' => '_sfaic_fluent_form_id',
                'value' => $form->id,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    ));
    
    if (!empty($prompts)) {
        $prompt_id = $prompts[0]->ID;
        sfaic_debug_field_mapping($prompt_id, $formData);
    }
}, 5, 3); // Run before the main processing





















































































/**
 * Add this to your WordPress admin menu for testing field mapping
 * Add this function to smart-forms-ai-connector.php
 */
function sfaic_add_field_mapping_test_page() {
    add_submenu_page(
        'tools.php',
        'Test Field Mapping',
        'Test Field Mapping',
        'manage_options',
        'test-field-mapping',
        'sfaic_render_field_mapping_test_page'
    );
}
add_action('admin_menu', 'sfaic_add_field_mapping_test_page');

function sfaic_render_field_mapping_test_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    // Get all prompts
    $prompts = get_posts(array(
        'post_type' => 'sfaic_prompt',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ));
    
    ?>
    <div class="wrap">
        <h1>Field Mapping Test</h1>
        
        <?php foreach ($prompts as $prompt): ?>
            <?php
            $form_id = get_post_meta($prompt->ID, '_sfaic_fluent_form_id', true);
            $first_name_field = get_post_meta($prompt->ID, '_sfaic_first_name_field', true);
            $last_name_field = get_post_meta($prompt->ID, '_sfaic_last_name_field', true);
            $email_field = get_post_meta($prompt->ID, '_sfaic_email_field_mapping', true);
            ?>
            <div class="postbox" style="margin-bottom: 20px;">
                <div class="postbox-header">
                    <h2><?php echo esc_html($prompt->post_title); ?> (ID: <?php echo $prompt->ID; ?>)</h2>
                </div>
                <div class="inside">
                    <p><strong>Associated Form ID:</strong> <?php echo $form_id ?: 'Not set'; ?></p>
                    <p><strong>First Name Field Mapping:</strong> <?php echo $first_name_field ?: 'Auto-detect'; ?></p>
                    <p><strong>Last Name Field Mapping:</strong> <?php echo $last_name_field ?: 'Auto-detect'; ?></p>
                    <p><strong>Email Field Mapping:</strong> <?php echo $email_field ?: 'Auto-detect'; ?></p>
                    
                    <?php if (!empty($form_id) && function_exists('wpFluent')): ?>
                        <?php
                        // Get recent submission for this form
                        $recent_submission = wpFluent()->table('fluentform_submissions')
                                ->where('form_id', $form_id)
                                ->orderBy('id', 'DESC')
                                ->first();
                        ?>
                        
                        <?php if ($recent_submission): ?>
                            <?php
                            $submission_data = json_decode($recent_submission->response, true);
                            ?>
                            <h4>Available Form Fields (from recent submission):</h4>
                            <ul>
                                <?php foreach ($submission_data as $field_key => $field_value): ?>
                                    <?php if (strpos($field_key, '_') !== 0): // Skip internal fields ?>
                                        <li>
                                            <code><?php echo esc_html($field_key); ?></code>: 
                                            <?php echo esc_html(is_array($field_value) ? implode(', ', $field_value) : $field_value); ?>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                            
                            <h4>Field Mapping Test Results:</h4>
                            <?php
                            // Test the extraction logic
                            $extracted_name = '';
                            $extracted_email = '';
                            
                            // Test name extraction
                            if (!empty($first_name_field) && isset($submission_data[$first_name_field])) {
                                $first_name = sanitize_text_field($submission_data[$first_name_field]);
                            } else {
                                $first_name = '';
                            }
                            
                            if (!empty($last_name_field) && isset($submission_data[$last_name_field])) {
                                $last_name = sanitize_text_field($submission_data[$last_name_field]);
                            } else {
                                $last_name = '';
                            }
                            
                            if (!empty($first_name) || !empty($last_name)) {
                                $extracted_name = trim($first_name . ' ' . $last_name);
                            }
                            
                            // Test email extraction
                            if (!empty($email_field) && isset($submission_data[$email_field])) {
                                $email = sanitize_email($submission_data[$email_field]);
                                if (is_email($email)) {
                                    $extracted_email = $email;
                                }
                            }
                            ?>
                            
                            <p><strong>Extracted Name:</strong> 
                                <?php if (!empty($extracted_name)): ?>
                                    <span style="color: green;"><?php echo esc_html($extracted_name); ?></span>
                                <?php else: ?>
                                    <span style="color: red;">Not found with current mapping</span>
                                <?php endif; ?>
                            </p>
                            
                            <p><strong>Extracted Email:</strong> 
                                <?php if (!empty($extracted_email)): ?>
                                    <span style="color: green;"><?php echo esc_html($extracted_email); ?></span>
                                <?php else: ?>
                                    <span style="color: red;">Not found with current mapping</span>
                                <?php endif; ?>
                            </p>
                            
                        <?php else: ?>
                            <p><em>No recent submissions found for this form. Field mapping test requires at least one submission.</em></p>
                        <?php endif; ?>
                        
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($prompts)): ?>
            <p>No prompts found. Create a prompt first.</p>
        <?php endif; ?>
    </div>
    <?php
}