<?php
/**
 * Plugin Name: AromaPro Smart Forms AI Connector
 * Plugin URI: https://aromapro.com/
 * Description: Connect Fluent Forms with ChatGPT, Google Gemini, or Anthropic Claude to generate AI responses for form submissions and create PDF documents with background processing
 * Version: 2.0.12
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
define('SFAIC_VERSION', '2.0.11');

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
            'includes/class-background-job-manager.php'
        );

        foreach ($files_to_include as $file) {
            $file_path = SFAIC_DIR . $file;

            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                // Log missing file error
                error_log('SFAIC: Missing required file: ' . $file_path);

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
                error_log('SFAIC: Missing required class: ' . $class_name);

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
            
            // Log successful initialization
            error_log('SFAIC: Plugin initialized successfully with enhanced background processing');
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
        // NOTE: Forms integration hooks are now registered in the SFAIC_Forms_Integration class itself

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
        
        // Add debug information hook
        add_action('admin_init', array($this, 'maybe_show_debug_info'));
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
        
        // Log activation
        error_log('SFAIC: Plugin activated with enhanced background processing');
    }

    /**
     * Plugin deactivation
     */
    public function plugin_deactivation() {
        // Clear all scheduled cron events
        wp_clear_scheduled_hook('sfaic_process_background_job');
        wp_clear_scheduled_hook('sfaic_cleanup_old_jobs');
        wp_clear_scheduled_hook('sfaic_cleanup_stuck_jobs_periodic');
        
        // Log deactivation
        error_log('SFAIC: Plugin deactivated - background processing stopped');
        
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

        // Show success notice if background processing is working well
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
        
        if (!empty($prompts_with_bg) && $screen->id === 'sfaic_prompt_page_sfaic-background-jobs') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php _e('<strong>✅ Enhanced Background Processing Active:</strong> Forms will be processed after submission is complete. Users will see immediate success messages and receive AI responses via email.', 'chatgpt-fluent-connector'); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Maybe show debug information for troubleshooting
     */
    public function maybe_show_debug_info() {
        if (isset($_GET['sfaic_debug']) && current_user_can('manage_options')) {
            add_action('admin_notices', function() {
                $cron_enabled = !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON;
                $next_cron = wp_next_scheduled('sfaic_process_background_job');
                
                echo '<div class="notice notice-info">';
                echo '<p><strong>SFAIC Debug Information:</strong></p>';
                echo '<ul style="margin-left: 20px;">';
                echo '<li>WP Cron: ' . ($cron_enabled ? '✅ Enabled' : '❌ Disabled') . '</li>';
                echo '<li>Next Background Job: ' . ($next_cron ? date('Y-m-d H:i:s', $next_cron) : '❌ Not scheduled') . '</li>';
                echo '<li>Background Job Manager: ' . (isset($this->background_job_manager) ? '✅ Available' : '❌ Not available') . '</li>';
                echo '<li>Forms Integration: ' . (isset($this->fluent_integration) ? '✅ Available' : '❌ Not available') . '</li>';
                echo '</ul>';
                echo '</div>';
            });
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