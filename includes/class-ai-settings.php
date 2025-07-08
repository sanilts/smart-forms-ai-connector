<?php
/**
 * AI API Settings Class - Complete with PageSnap PDF Service Options
 * 
 * Handles the plugin settings page and API credentials for multiple providers
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Settings{

    /**
     * Constructor
     */
    public function __construct() {
        // Add menu item
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add AJAX handlers for API testing
        add_action('wp_ajax_sfaic_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_sfaic_test_pdf', array($this, 'ajax_test_pdf'));
        add_action('wp_ajax_sfaic_test_pdfshift', array($this, 'ajax_test_pdfshift'));
        add_action('wp_ajax_sfaic_test_pagesnap', array($this, 'ajax_test_pagesnap'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
                __('AI API Connector', 'chatgpt-fluent-connector'),
                __('AI API Connector', 'chatgpt-fluent-connector'),
                'manage_options',
                'sfaic-settings',
                array($this, 'admin_page')
        );
    }

    /**
     * Enqueue admin scripts for settings page
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our settings page
        if ($hook !== 'settings_page_sfaic-settings') {
            return;
        }

        // Enqueue API testing styles
        wp_enqueue_style(
            'sfaic-api-testing-styles',
            SFAIC_URL . 'assets/css/api-testing.css',
            array(),
            SFAIC_VERSION
        );

        // Enqueue API testing script
        wp_enqueue_script(
            'sfaic-api-testing',
            SFAIC_URL . 'assets/js/api-testing.js',
            array('jquery'),
            SFAIC_VERSION,
            true
        );

        // Localize script with AJAX data
        wp_localize_script('sfaic-api-testing', 'sfaic_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'test_nonce' => wp_create_nonce('sfaic_test_api'),
            'pdf_nonce' => wp_create_nonce('sfaic_test_pdf'),
            'pdfshift_nonce' => wp_create_nonce('sfaic_test_pdfshift'),
            'pagesnap_nonce' => wp_create_nonce('sfaic_test_pagesnap'),
            'strings' => array(
                'testing' => __('Testing...', 'chatgpt-fluent-connector'),
                'success' => __('Success!', 'chatgpt-fluent-connector'),
                'failed' => __('Failed', 'chatgpt-fluent-connector'),
                'enter_api_key' => __('Please enter an API key first.', 'chatgpt-fluent-connector'),
                'request_failed' => __('Request failed. Please try again.', 'chatgpt-fluent-connector'),
            )
        ));
    }

    /**
     * AJAX handler for API testing
     */
    public function ajax_test_api() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_test_api')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $provider = sanitize_text_field($_POST['provider']);
        $api_key = sanitize_text_field($_POST['api_key']);
        $model = sanitize_text_field($_POST['model']);

        // Temporarily set the API key for testing
        $original_key = '';
        $original_model = '';

        switch ($provider) {
            case 'openai':
                $original_key = get_option('sfaic_api_key');
                $original_model = get_option('sfaic_model');
                update_option('sfaic_api_key', $api_key);
                update_option('sfaic_model', $model);
                break;
            case 'gemini':
                $original_key = get_option('sfaic_gemini_api_key');
                $original_model = get_option('sfaic_gemini_model');
                update_option('sfaic_gemini_api_key', $api_key);
                update_option('sfaic_gemini_model', $model);
                break;
            case 'claude':
                $original_key = get_option('sfaic_claude_api_key');
                $original_model = get_option('sfaic_claude_model');
                update_option('sfaic_claude_api_key', $api_key);
                update_option('sfaic_claude_model', $model);
                break;
        }

        // Test the API
        $result = $this->test_api_connection($provider);

        // Restore original settings
        switch ($provider) {
            case 'openai':
                update_option('sfaic_api_key', $original_key);
                update_option('sfaic_model', $original_model);
                break;
            case 'gemini':
                update_option('sfaic_gemini_api_key', $original_key);
                update_option('sfaic_gemini_model', $original_model);
                break;
            case 'claude':
                update_option('sfaic_claude_api_key', $original_key);
                update_option('sfaic_claude_model', $original_model);
                break;
        }

        wp_send_json($result);
    }

    /**
     * AJAX handler for PDF testing
     */
    public function ajax_test_pdf() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_test_pdf')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Test PDF generation
        if (isset(sfaic_main()->pdf_generator)) {
            $result = sfaic_main()->pdf_generator->test_mpdf_library();
        } else {
            $result = new WP_Error('pdf_not_available', __('PDF generator is not available', 'chatgpt-fluent-connector'));
        }

        if (is_wp_error($result)) {
            wp_send_json(array(
                'success' => false,
                'message' => $result->get_error_message()
            ));
        } else {
            wp_send_json(array(
                'success' => true,
                'message' => $result['message'],
                'details' => $result
            ));
        }
    }

    /**
     * AJAX handler for PDFShift testing
     */
    public function ajax_test_pdfshift() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_test_pdfshift')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $api_key = sanitize_text_field($_POST['api_key']);

        // Test PDFShift service
        if (isset(sfaic_main()->pdf_generator)) {
            $result = sfaic_main()->pdf_generator->test_pdfshift_service($api_key);
        } else {
            $result = new WP_Error('pdf_not_available', __('PDF generator is not available', 'chatgpt-fluent-connector'));
        }

        if (is_wp_error($result)) {
            wp_send_json(array(
                'success' => false,
                'message' => $result->get_error_message()
            ));
        } else {
            wp_send_json(array(
                'success' => true,
                'message' => $result['message'],
                'details' => $result
            ));
        }
    }

    /**
     * AJAX handler for PageSnap testing
     */
    public function ajax_test_pagesnap() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_test_pagesnap')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $api_key = sanitize_text_field($_POST['api_key']);

        // Test PageSnap service
        if (isset(sfaic_main()->pdf_generator)) {
            $result = sfaic_main()->pdf_generator->test_pagesnap_service($api_key);
        } else {
            $result = new WP_Error('pdf_not_available', __('PDF generator is not available', 'chatgpt-fluent-connector'));
        }

        if (is_wp_error($result)) {
            wp_send_json(array(
                'success' => false,
                'message' => $result->get_error_message()
            ));
        } else {
            wp_send_json(array(
                'success' => true,
                'message' => $result['message'],
                'details' => $result
            ));
        }
    }

    /**
     * Test API connection for a specific provider
     */
    private function test_api_connection($provider) {
        $test_message = array(
            array(
                'role' => 'user',
                'content' => 'Hello! This is a test message. Please respond with "API connection successful!" and include an emoji.'
            )
        );

        $start_time = microtime(true);

        switch ($provider) {
            case 'openai':
                if (!isset(sfaic_main()->api)) {
                    return array('success' => false, 'message' => 'OpenAI API class not available');
                }
                $response = sfaic_main()->api->make_request($test_message, null, 100, 0.7);
                if (!is_wp_error($response)) {
                    $content = sfaic_main()->api->get_response_content($response);
                    $tokens = sfaic_main()->api->get_last_token_usage();
                } else {
                    return array('success' => false, 'message' => $response->get_error_message());
                }
                break;

            case 'gemini':
                if (!isset(sfaic_main()->gemini_api)) {
                    return array('success' => false, 'message' => 'Gemini API class not available');
                }
                $response = sfaic_main()->gemini_api->make_request($test_message, null, 100, 0.7);
                if (!is_wp_error($response)) {
                    $content = sfaic_main()->gemini_api->get_response_content($response);
                    $tokens = sfaic_main()->gemini_api->get_last_token_usage();
                } else {
                    return array('success' => false, 'message' => $response->get_error_message());
                }
                break;

            case 'claude':
                if (!isset(sfaic_main()->claude_api)) {
                    return array('success' => false, 'message' => 'Claude API class not available');
                }
                $response = sfaic_main()->claude_api->make_request($test_message, null, 100, 0.7);
                if (!is_wp_error($response)) {
                    $content = sfaic_main()->claude_api->get_response_content($response);
                    $tokens = sfaic_main()->claude_api->get_last_token_usage();
                } else {
                    return array('success' => false, 'message' => $response->get_error_message());
                }
                break;

            default:
                return array('success' => false, 'message' => 'Unknown provider: ' . $provider);
        }

        $execution_time = microtime(true) - $start_time;

        if (is_wp_error($content)) {
            return array('success' => false, 'message' => $content->get_error_message());
        }

        return array(
            'success' => true,
            'message' => 'API connection successful!',
            'response' => $content,
            'tokens' => $tokens,
            'execution_time' => round($execution_time, 2),
            'model' => $this->get_current_model($provider)
        );
    }

    /**
     * Get current model for provider
     */
    private function get_current_model($provider) {
        switch ($provider) {
            case 'openai':
                return get_option('sfaic_model', 'gpt-3.5-turbo');
            case 'gemini':
                return get_option('sfaic_gemini_model', 'gemini-1.5-pro-latest');
            case 'claude':
                return get_option('sfaic_claude_model', 'claude-opus-4-20250514');
            default:
                return 'unknown';
        }
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // General Settings
        register_setting('sfaic_settings', 'sfaic_api_provider', array(
            'default' => 'openai'
        ));

        // Background Processing Settings
        register_setting('sfaic_settings', 'sfaic_enable_background_processing', array(
            'default' => true
        ));
        register_setting('sfaic_settings', 'sfaic_background_processing_delay', array(
            'default' => 5
        ));
        register_setting('sfaic_settings', 'sfaic_max_concurrent_jobs', array(
            'default' => 3
        ));
        register_setting('sfaic_settings', 'sfaic_job_timeout', array(
            'default' => 300
        ));

        // PDF Service Settings
        register_setting('sfaic_settings', 'sfaic_default_pdf_service', array(
            'default' => 'mpdf'
        ));
        register_setting('sfaic_settings', 'sfaic_pdfshift_api_key');
        register_setting('sfaic_settings', 'sfaic_pagesnap_api_key');

        // OpenAI Settings
        register_setting('sfaic_settings', 'sfaic_api_key');
        register_setting('sfaic_settings', 'sfaic_api_endpoint', array(
            'default' => 'https://api.openai.com/v1/chat/completions'
        ));
        register_setting('sfaic_settings', 'sfaic_model', array(
            'default' => 'gpt-3.5-turbo'
        ));

        // Gemini Settings
        register_setting('sfaic_settings', 'sfaic_gemini_api_key');
        register_setting('sfaic_settings', 'sfaic_gemini_api_endpoint', array(
            'default' => 'https://generativelanguage.googleapis.com/v1beta/models/'
        ));
        register_setting('sfaic_settings', 'sfaic_gemini_model', array(
            'default' => 'gemini-2.5-pro-preview-05-06'
        ));

        // Claude Settings
        register_setting('sfaic_settings', 'sfaic_claude_api_key');
        register_setting('sfaic_settings', 'sfaic_claude_api_endpoint', array(
            'default' => 'https://api.anthropic.com/v1/messages'
        ));
        register_setting('sfaic_settings', 'sfaic_claude_model', array(
            'default' => 'claude-opus-4-20250514'
        ));

        // General Section
        add_settings_section(
                'sfaic_general_section',
                __('General Settings', 'chatgpt-fluent-connector'),
                array($this, 'general_section_callback'),
                'sfaic_settings'
        );

        add_settings_field(
                'sfaic_api_provider',
                __('AI Provider', 'chatgpt-fluent-connector'),
                array($this, 'api_provider_field_callback'),
                'sfaic_settings',
                'sfaic_general_section'
        );

        // Background Processing Section
        add_settings_section(
                'sfaic_background_section',
                __('Background Processing Settings', 'chatgpt-fluent-connector'),
                array($this, 'background_section_callback'),
                'sfaic_settings'
        );

        add_settings_field(
                'sfaic_enable_background_processing',
                __('Enable Background Processing', 'chatgpt-fluent-connector'),
                array($this, 'enable_background_processing_field_callback'),
                'sfaic_settings',
                'sfaic_background_section'
        );

        add_settings_field(
                'sfaic_background_processing_delay',
                __('Processing Delay', 'chatgpt-fluent-connector'),
                array($this, 'background_processing_delay_field_callback'),
                'sfaic_settings',
                'sfaic_background_section'
        );

        add_settings_field(
                'sfaic_max_concurrent_jobs',
                __('Max Concurrent Jobs', 'chatgpt-fluent-connector'),
                array($this, 'max_concurrent_jobs_field_callback'),
                'sfaic_settings',
                'sfaic_background_section'
        );

        add_settings_field(
                'sfaic_job_timeout',
                __('Job Timeout', 'chatgpt-fluent-connector'),
                array($this, 'job_timeout_field_callback'),
                'sfaic_settings',
                'sfaic_background_section'
        );

        // PDF Services Section
        add_settings_section(
                'sfaic_pdf_section',
                __('PDF Generation Settings', 'chatgpt-fluent-connector'),
                array($this, 'pdf_section_callback'),
                'sfaic_settings'
        );

        add_settings_field(
                'sfaic_default_pdf_service',
                __('Default PDF Service', 'chatgpt-fluent-connector'),
                array($this, 'default_pdf_service_field_callback'),
                'sfaic_settings',
                'sfaic_pdf_section'
        );

        add_settings_field(
                'sfaic_pdfshift_api_key',
                __('PDFShift API Key', 'chatgpt-fluent-connector'),
                array($this, 'pdfshift_api_key_field_callback'),
                'sfaic_settings',
                'sfaic_pdf_section'
        );

        add_settings_field(
                'sfaic_pagesnap_api_key',
                __('PageSnap API Key', 'chatgpt-fluent-connector'),
                array($this, 'pagesnap_api_key_field_callback'),
                'sfaic_settings',
                'sfaic_pdf_section'
        );

        // OpenAI Section
        add_settings_section(
                'sfaic_openai_section',
                __('OpenAI API Settings', 'chatgpt-fluent-connector'),
                array($this, 'openai_section_callback'),
                'sfaic_settings'
        );

        add_settings_field(
                'sfaic_api_key',
                __('API Key', 'chatgpt-fluent-connector'),
                array($this, 'api_key_field_callback'),
                'sfaic_settings',
                'sfaic_openai_section'
        );

        add_settings_field(
                'sfaic_api_endpoint',
                __('API Endpoint', 'chatgpt-fluent-connector'),
                array($this, 'api_endpoint_field_callback'),
                'sfaic_settings',
                'sfaic_openai_section'
        );

        add_settings_field(
                'sfaic_model',
                __('GPT Model', 'chatgpt-fluent-connector'),
                array($this, 'model_field_callback'),
                'sfaic_settings',
                'sfaic_openai_section'
        );

        // Gemini Section
        add_settings_section(
                'sfaic_gemini_section',
                __('Google Gemini API Settings', 'chatgpt-fluent-connector'),
                array($this, 'gemini_section_callback'),
                'sfaic_settings'
        );

        add_settings_field(
                'sfaic_gemini_api_key',
                __('Gemini API Key', 'chatgpt-fluent-connector'),
                array($this, 'gemini_api_key_field_callback'),
                'sfaic_settings',
                'sfaic_gemini_section'
        );

        add_settings_field(
                'sfaic_gemini_api_endpoint',
                __('Gemini API Endpoint', 'chatgpt-fluent-connector'),
                array($this, 'gemini_api_endpoint_field_callback'),
                'sfaic_settings',
                'sfaic_gemini_section'
        );

        add_settings_field(
                'sfaic_gemini_model',
                __('Gemini Model', 'chatgpt-fluent-connector'),
                array($this, 'gemini_model_field_callback'),
                'sfaic_settings',
                'sfaic_gemini_section'
        );

        // Claude Section
        add_settings_section(
                'sfaic_claude_section',
                __('Anthropic Claude API Settings', 'chatgpt-fluent-connector'),
                array($this, 'claude_section_callback'),
                'sfaic_settings'
        );

        add_settings_field(
                'sfaic_claude_api_key',
                __('Claude API Key', 'chatgpt-fluent-connector'),
                array($this, 'claude_api_key_field_callback'),
                'sfaic_settings',
                'sfaic_claude_section'
        );

        add_settings_field(
                'sfaic_claude_api_endpoint',
                __('Claude API Endpoint', 'chatgpt-fluent-connector'),
                array($this, 'claude_api_endpoint_field_callback'),
                'sfaic_settings',
                'sfaic_claude_section'
        );

        add_settings_field(
                'sfaic_claude_model',
                __('Claude Model', 'chatgpt-fluent-connector'),
                array($this, 'claude_model_field_callback'),
                'sfaic_settings',
                'sfaic_claude_section'
        );
    }

    /**
     * General section description
     */
    public function general_section_callback() {
        echo '<p>' . esc_html__('Choose which AI provider to use and configure general settings.', 'chatgpt-fluent-connector') . '</p>';
    }

    /**
     * Background processing section description
     */
    public function background_section_callback() {
        $jobs_url = admin_url('edit.php?post_type=sfaic_prompt&page=sfaic-background-jobs');
        
        echo '<div style="background: #f0f8ff; padding: 20px; margin: 15px 0; border-left: 4px solid #0073aa; border-radius: 0 3px 3px 0;">';
        echo '<h3 style="margin-top: 0; color: #0073aa;">🚀 ' . esc_html__('Background Processing', 'chatgpt-fluent-connector') . '</h3>';
        echo '<p>' . esc_html__('When enabled, AI requests are processed in the background using WordPress cron jobs. This prevents users from experiencing delays when submitting forms.', 'chatgpt-fluent-connector') . '</p>';
        
        echo '<div style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-top: 15px;">';
        echo '<h4 style="margin-top: 0; color: #0073aa;">✨ ' . esc_html__('Benefits of Background Processing', 'chatgpt-fluent-connector') . '</h4>';
        echo '<ul style="margin: 10px 0; padding-left: 20px; color: #666;">';
        echo '<li>✅ ' . esc_html__('Faster form submission response for users', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Automatic retry on failures with exponential backoff', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Job monitoring and status tracking', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Better handling of API rate limits', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Prevents timeouts on slow API responses', 'chatgpt-fluent-connector') . '</li>';
        echo '</ul>';
        
        echo '<p><a href="' . esc_url($jobs_url) . '" class="button button-secondary" target="_blank">';
        echo '<span class="dashicons dashicons-admin-tools" style="vertical-align: middle; margin-right: 5px;"></span>';
        echo esc_html__('Monitor Background Jobs', 'chatgpt-fluent-connector') . '</a></p>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * PDF section description (updated for PageSnap)
     */
    public function pdf_section_callback() {
        echo '<div style="background: #e8f5e8; padding: 20px; margin: 15px 0; border-left: 4px solid #28a745; border-radius: 0 3px 3px 0;">';
        echo '<h3 style="margin-top: 0;">' . esc_html__('📄 PDF Generation Services', 'chatgpt-fluent-connector') . '</h3>';
        echo '<p>' . esc_html__('Choose between local mPDF generation or cloud-based services for creating PDF documents from AI responses.', 'chatgpt-fluent-connector') . '</p>';

        echo '<div style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-top: 15px;">';
        echo '<h4 style="margin-top: 0; color: #28a745;">🔧 ' . esc_html__('Available PDF Services', 'chatgpt-fluent-connector') . '</h4>';
        echo '<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin: 15px 0;">';
        
        // Local mPDF info
        echo '<div style="padding: 15px; border: 1px solid #ddd; border-radius: 5px;">';
        echo '<h5 style="margin-top: 0; color: #333;">🏠 Local mPDF</h5>';
        echo '<ul style="margin: 10px 0; padding-left: 20px; color: #666; font-size: 13px;">';
        echo '<li>✅ ' . esc_html__('No external dependencies', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Works offline', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('No API costs', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Privacy friendly', 'chatgpt-fluent-connector') . '</li>';
        echo '</ul>';
        echo '<p style="margin: 0; font-size: 12px;"><strong>Status:</strong> ';
        if (class_exists('Mpdf\Mpdf')) {
            echo '<span style="color: #28a745;">✅ Available</span>';
        } else {
            echo '<span style="color: #dc3545;">❌ Library Missing</span>';
        }
        echo '</p></div>';

        // PDFShift info
        echo '<div style="padding: 15px; border: 1px solid #ddd; border-radius: 5px;">';
        echo '<h5 style="margin-top: 0; color: #333;">☁️ PDFShift Cloud</h5>';
        echo '<ul style="margin: 10px 0; padding-left: 20px; color: #666; font-size: 13px;">';
        echo '<li>✅ ' . esc_html__('Professional quality', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Enterprise reliability', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Scalable for high volume', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Advanced HTML/CSS support', 'chatgpt-fluent-connector') . '</li>';
        echo '</ul>';
        echo '<p style="margin: 0; font-size: 12px;"><strong>Status:</strong> ';
        $pdfshift_key = get_option('sfaic_pdfshift_api_key');
        if (!empty($pdfshift_key)) {
            echo '<span style="color: #28a745;">✅ API Key Configured</span>';
        } else {
            echo '<span style="color: #ffc107;">⚠️ API Key Required</span>';
        }
        echo '</p></div>';

        // PageSnap info
        echo '<div style="padding: 15px; border: 1px solid #ddd; border-radius: 5px;">';
        echo '<h5 style="margin-top: 0; color: #333;">📸 PageSnap Cloud</h5>';
        echo '<ul style="margin: 10px 0; padding-left: 20px; color: #666; font-size: 13px;">';
        echo '<li>✅ ' . esc_html__('Chrome engine rendering', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('High-speed conversion', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Modern CSS3 support', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Cost-effective pricing', 'chatgpt-fluent-connector') . '</li>';
        echo '</ul>';
        echo '<p style="margin: 0; font-size: 12px;"><strong>Status:</strong> ';
        $pagesnap_key = get_option('sfaic_pagesnap_api_key');
        if (!empty($pagesnap_key)) {
            echo '<span style="color: #28a745;">✅ API Key Configured</span>';
        } else {
            echo '<span style="color: #ffc107;">⚠️ API Key Required</span>';
        }
        echo '</p></div>';

        echo '</div></div>';
        echo '</div>';
    }

    /**
     * OpenAI section description
     */
    public function openai_section_callback() {
        echo '<p>' . esc_html__('Enter your ChatGPT (OpenAI) API credentials below.', 'chatgpt-fluent-connector') . '</p>';
    }

    /**
     * Gemini section description
     */
    public function gemini_section_callback() {
        echo '<p>' . esc_html__('Enter your Google Gemini API credentials below.', 'chatgpt-fluent-connector') . '</p>';
    }

    /**
     * Claude section description
     */
    public function claude_section_callback() {
        echo '<p>' . esc_html__('Enter your Anthropic Claude API credentials below.', 'chatgpt-fluent-connector') . '</p>';
    }

    /**
     * API Provider field
     */
    public function api_provider_field_callback() {
        $provider = get_option('sfaic_api_provider', 'openai');
        ?>
        <select name="sfaic_api_provider" id="sfaic_api_provider">
            <option value="openai" <?php selected($provider, 'openai'); ?>><?php echo esc_html__('OpenAI (ChatGPT)', 'chatgpt-fluent-connector'); ?></option>
            <option value="gemini" <?php selected($provider, 'gemini'); ?>><?php echo esc_html__('Google Gemini', 'chatgpt-fluent-connector'); ?></option>
            <option value="claude" <?php selected($provider, 'claude'); ?>><?php echo esc_html__('Anthropic Claude', 'chatgpt-fluent-connector'); ?></option>
        </select>
        <p class="description"><?php echo esc_html__('Select which AI provider you want to use', 'chatgpt-fluent-connector'); ?></p>
        <?php
    }

    /**
     * Enable background processing field
     */
    public function enable_background_processing_field_callback() {
        $enabled = get_option('sfaic_enable_background_processing', true);
        ?>
        <label>
            <input type="checkbox" name="sfaic_enable_background_processing" value="1" <?php checked($enabled, true); ?> />
            <?php echo esc_html__('Enable background processing for AI requests', 'chatgpt-fluent-connector'); ?>
        </label>
        <p class="description">
            <?php echo esc_html__('When enabled, AI requests will be processed in the background using WordPress cron jobs. This prevents form submission delays but requires WordPress cron to be working properly.', 'chatgpt-fluent-connector'); ?>
            <br><strong><?php echo esc_html__('Recommended: Enabled', 'chatgpt-fluent-connector'); ?></strong>
        </p>
        
        <?php
        // Check if WP Cron is working
        $cron_test = wp_get_ready_cron_jobs();
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        
        if ($wp_cron_disabled) {
            echo '<div class="notice notice-warning inline" style="margin-top: 10px;">';
            echo '<p><strong>' . esc_html__('Warning:', 'chatgpt-fluent-connector') . '</strong> ';
            echo esc_html__('WordPress cron is disabled (DISABLE_WP_CRON is set to true). Background processing may not work properly. Consider setting up a real cron job to trigger wp-cron.php.', 'chatgpt-fluent-connector');
            echo '</p></div>';
        }
        ?>
        <?php
    }

    /**
     * Background processing delay field
     */
    public function background_processing_delay_field_callback() {
        $delay = get_option('sfaic_background_processing_delay', 5);
        ?>
        <input type="number" name="sfaic_background_processing_delay" value="<?php echo esc_attr($delay); ?>" min="0" max="300" step="1" class="small-text" />
        <span><?php echo esc_html__('seconds', 'chatgpt-fluent-connector'); ?></span>
        <p class="description">
            <?php echo esc_html__('Delay before starting background job processing. Set to 0 for immediate processing, or add a small delay to ensure form data is fully saved.', 'chatgpt-fluent-connector'); ?>
        </p>
        <?php
    }

    /**
     * Max concurrent jobs field
     */
    public function max_concurrent_jobs_field_callback() {
        $max_jobs = get_option('sfaic_max_concurrent_jobs', 3);
        ?>
        <input type="number" name="sfaic_max_concurrent_jobs" value="<?php echo esc_attr($max_jobs); ?>" min="1" max="10" step="1" class="small-text" />
        <p class="description">
            <?php echo esc_html__('Maximum number of AI jobs that can run simultaneously. Higher values may increase server load and API costs.', 'chatgpt-fluent-connector'); ?>
        </p>
        <?php
    }

    /**
     * Job timeout field
     */
    public function job_timeout_field_callback() {
        $timeout = get_option('sfaic_job_timeout', 300);
        ?>
        <input type="number" name="sfaic_job_timeout" value="<?php echo esc_attr($timeout); ?>" min="30" max="1800" step="30" class="small-text" />
        <span><?php echo esc_html__('seconds', 'chatgpt-fluent-connector'); ?></span>
        <p class="description">
            <?php echo esc_html__('Maximum time a background job can run before being considered failed. Adjust based on your AI provider response times.', 'chatgpt-fluent-connector'); ?>
        </p>
        <?php
    }

    /**
     * Default PDF service field (updated with PageSnap)
     */
    public function default_pdf_service_field_callback() {
        $default_service = get_option('sfaic_default_pdf_service', 'mpdf');
        $mpdf_available = class_exists('Mpdf\Mpdf');
        $pdfshift_available = !empty(get_option('sfaic_pdfshift_api_key'));
        $pagesnap_available = !empty(get_option('sfaic_pagesnap_api_key'));
        ?>
        <select name="sfaic_default_pdf_service" id="sfaic_default_pdf_service">
            <option value="mpdf" <?php selected($default_service, 'mpdf'); ?>>
                <?php echo esc_html__('Local mPDF', 'chatgpt-fluent-connector'); ?>
                <?php if (!$mpdf_available): ?>
                    <?php echo esc_html__('(Not Available)', 'chatgpt-fluent-connector'); ?>
                <?php endif; ?>
            </option>
            <option value="pdfshift" <?php selected($default_service, 'pdfshift'); ?>>
                <?php echo esc_html__('PDFShift Cloud Service', 'chatgpt-fluent-connector'); ?>
                <?php if (!$pdfshift_available): ?>
                    <?php echo esc_html__('(API Key Required)', 'chatgpt-fluent-connector'); ?>
                <?php endif; ?>
            </option>
            <option value="pagesnap" <?php selected($default_service, 'pagesnap'); ?>>
                <?php echo esc_html__('PageSnap Cloud Service', 'chatgpt-fluent-connector'); ?>
                <?php if (!$pagesnap_available): ?>
                    <?php echo esc_html__('(API Key Required)', 'chatgpt-fluent-connector'); ?>
                <?php endif; ?>
            </option>
        </select>
        <p class="description">
            <?php echo esc_html__('Choose the default PDF generation service for new prompts. You can override this setting for individual prompts.', 'chatgpt-fluent-connector'); ?>
            <br><strong><?php echo esc_html__('Note:', 'chatgpt-fluent-connector'); ?></strong> 
            <?php echo esc_html__('If the selected service is unavailable, the system will automatically fall back to mPDF if available.', 'chatgpt-fluent-connector'); ?>
        </p>

        <!-- Service status indicators -->
        <div style="margin-top: 15px;">
            <div class="service-status" style="display: inline-block; margin-right: 15px;">
                <strong><?php echo esc_html__('mPDF:', 'chatgpt-fluent-connector'); ?></strong>
                <?php if ($mpdf_available): ?>
                    <span style="color: #28a745;">✅ <?php echo esc_html__('Available', 'chatgpt-fluent-connector'); ?></span>
                <?php else: ?>
                    <span style="color: #dc3545;">❌ <?php echo esc_html__('Missing', 'chatgpt-fluent-connector'); ?></span>
                <?php endif; ?>
            </div>
            <div class="service-status" style="display: inline-block; margin-right: 15px;">
                <strong><?php echo esc_html__('PDFShift:', 'chatgpt-fluent-connector'); ?></strong>
                <?php if ($pdfshift_available): ?>
                    <span style="color: #28a745;">✅ <?php echo esc_html__('Ready', 'chatgpt-fluent-connector'); ?></span>
                <?php else: ?>
                    <span style="color: #ffc107;">⚠️ <?php echo esc_html__('Key Required', 'chatgpt-fluent-connector'); ?></span>
                <?php endif; ?>
            </div>
            <div class="service-status" style="display: inline-block;">
                <strong><?php echo esc_html__('PageSnap:', 'chatgpt-fluent-connector'); ?></strong>
                <?php if ($pagesnap_available): ?>
                    <span style="color: #28a745;">✅ <?php echo esc_html__('Ready', 'chatgpt-fluent-connector'); ?></span>
                <?php else: ?>
                    <span style="color: #ffc107;">⚠️ <?php echo esc_html__('Key Required', 'chatgpt-fluent-connector'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * PDFShift API Key field
     */
    public function pdfshift_api_key_field_callback() {
        $api_key = get_option('sfaic_pdfshift_api_key');
        ?>
        <input type="password" name="sfaic_pdfshift_api_key" id="sfaic_pdfshift_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
        <p class="description">
            <?php echo esc_html__('Enter your PDFShift API key to enable cloud-based PDF generation.', 'chatgpt-fluent-connector'); ?> 
            <a href="https://pdfshift.io/" target="_blank"><?php echo esc_html__('Get your API key from pdfshift.io', 'chatgpt-fluent-connector'); ?></a>
        </p>

        <!-- Test button for PDFShift -->
        <div style="margin-top: 10px;">
            <button type="button" id="test-pdfshift-btn" class="button button-secondary">
                <span class="dashicons dashicons-cloud" style="vertical-align: middle; margin-right: 5px;"></span>
                <?php _e('Test PDFShift Service', 'chatgpt-fluent-connector'); ?>
            </button>
            <div id="pdfshift-test-result" class="api-test-result" style="margin-top: 10px;"></div>
        </div>

        <div style="background: #e3f2fd; padding: 15px; margin: 15px 0; border-left: 4px solid #2196f3; border-radius: 0 3px 3px 0;">
            <h4 style="margin-top: 0;"><?php _e('💡 About PDFShift', 'chatgpt-fluent-connector'); ?></h4>
            <p style="margin-bottom: 0;"><?php _e('PDFShift is a professional cloud service that converts HTML to PDF with high-quality rendering. It offers reliable, scalable PDF generation with advanced HTML/CSS support and excellent performance for production environments.', 'chatgpt-fluent-connector'); ?></p>
        </div>
        <?php
    }

    /**
     * PageSnap API Key field
     */
    public function pagesnap_api_key_field_callback() {
        $api_key = get_option('sfaic_pagesnap_api_key');
        ?>
        <input type="password" name="sfaic_pagesnap_api_key" id="sfaic_pagesnap_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
        <p class="description">
            <?php echo esc_html__('Enter your PageSnap API key to enable cloud-based PDF generation.', 'chatgpt-fluent-connector'); ?> 
            <a href="https://pagesnap.co/" target="_blank"><?php echo esc_html__('Get your API key from pagesnap.co', 'chatgpt-fluent-connector'); ?></a>
        </p>

        <!-- Test button for PageSnap -->
        <div style="margin-top: 10px;">
            <button type="button" id="test-pagesnap-btn" class="button button-secondary">
                <span class="dashicons dashicons-camera" style="vertical-align: middle; margin-right: 5px;"></span>
                <?php _e('Test PageSnap Service', 'chatgpt-fluent-connector'); ?>
            </button>
            <div id="pagesnap-test-result" class="api-test-result" style="margin-top: 10px;"></div>
        </div>

        <div style="background: #e3f2fd; padding: 15px; margin: 15px 0; border-left: 4px solid #2196f3; border-radius: 0 3px 3px 0;">
            <h4 style="margin-top: 0;"><?php _e('💡 About PageSnap', 'chatgpt-fluent-connector'); ?></h4>
            <p style="margin-bottom: 0;"><?php _e('PageSnap is a high-performance cloud service that converts HTML to PDF using Chrome rendering engine. It offers fast, reliable PDF generation with excellent modern web standards support and competitive pricing.', 'chatgpt-fluent-connector'); ?></p>
        </div>
        <?php
    }

    /**
     * API Key field
     */
    public function api_key_field_callback() {
        $api_key = get_option('sfaic_api_key');
        ?>
        <input type="password" name="sfaic_api_key" id="sfaic_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
        <p class="description">
            <?php echo esc_html__('Enter your OpenAI API key.', 'chatgpt-fluent-connector'); ?> 
            <a href="https://platform.openai.com/api-keys" target="_blank"><?php echo esc_html__('Get your API key', 'chatgpt-fluent-connector'); ?></a>
        </p>
        <?php
    }

    /**
     * API Endpoint field
     */
    public function api_endpoint_field_callback() {
        $api_endpoint = get_option('sfaic_api_endpoint', 'https://api.openai.com/v1/chat/completions');
        ?>
        <input type="text" name="sfaic_api_endpoint" value="<?php echo esc_attr($api_endpoint); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('The default endpoint is the ChatGPT completions API', 'chatgpt-fluent-connector'); ?></p>
        <?php
    }

    /**
     * Model field
     */
    public function model_field_callback() {
        $model = get_option('sfaic_model', 'gpt-3.5-turbo');
        $models = array(
            'gpt-3.5-turbo' => __('GPT-3.5 Turbo (4K tokens, fastest, most cost-effective)', 'chatgpt-fluent-connector'),
            'gpt-4' => __('GPT-4 (8K tokens, more powerful, more expensive)', 'chatgpt-fluent-connector'),
            'gpt-4-turbo' => __('GPT-4 Turbo (128K tokens, latest GPT-4 model)', 'chatgpt-fluent-connector'),
            'gpt-4-1106-preview' => __('GPT-4 Turbo (128K tokens, November 2023 preview)', 'chatgpt-fluent-connector'),
            'gpt-4-0613' => __('GPT-4 (8K tokens, June 2023 snapshot)', 'chatgpt-fluent-connector'),
            'gpt-4-0125-preview' => __('GPT-4 Preview (128K tokens, with advanced reasoning)', 'chatgpt-fluent-connector'),
        );
        ?>
        <select name="sfaic_model" id="sfaic_model">
            <?php foreach ($models as $model_id => $model_name) : ?>
                <option value="<?php echo esc_attr($model_id); ?>" <?php selected($model, $model_id); ?>><?php echo esc_html($model_name); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html__('Select which OpenAI model to use', 'chatgpt-fluent-connector'); ?></p>
        <?php
    }

    /**
     * Gemini API Key field
     */
    public function gemini_api_key_field_callback() {
        $api_key = get_option('sfaic_gemini_api_key');
        ?>
        <input type="password" name="sfaic_gemini_api_key" id="sfaic_gemini_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
        <p class="description">
            <?php echo esc_html__('Enter your Google AI Gemini API key.', 'chatgpt-fluent-connector'); ?> 
            <a href="https://ai.google.dev/" target="_blank"><?php echo esc_html__('Get your API key from Google AI Studio', 'chatgpt-fluent-connector'); ?></a>
        </p>
        <?php
    }

    /**
     * Gemini API Endpoint field
     */
    public function gemini_api_endpoint_field_callback() {
        $api_endpoint = get_option('sfaic_gemini_api_endpoint', 'https://generativelanguage.googleapis.com/v1beta/models/');
        ?>
        <input type="text" name="sfaic_gemini_api_endpoint" value="<?php echo esc_attr($api_endpoint); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('The default endpoint for the Gemini API', 'chatgpt-fluent-connector'); ?></p>
        <?php
    }

    /**
     * Gemini Model field
     */
    public function gemini_model_field_callback() {
        $model = get_option('sfaic_gemini_model', 'gemini-1.5-pro-latest');
        ?>
        <select name="sfaic_gemini_model" id="sfaic_gemini_model">
            <option value="gemini-1.5-pro-latest" <?php selected($model, 'gemini-1.5-pro-latest'); ?>>
                <?php echo esc_html__('Gemini 1.5 Pro (Latest - Stable)', 'chatgpt-fluent-connector'); ?>
            </option>
            <option value="gemini-1.5-flash-latest" <?php selected($model, 'gemini-1.5-flash-latest'); ?>>
                <?php echo esc_html__('Gemini 1.5 Flash (Faster, Lower Cost)', 'chatgpt-fluent-connector'); ?>
            </option>
            <option value="gemini-2.0-flash-exp" <?php selected($model, 'gemini-2.0-flash-exp'); ?>>
                <?php echo esc_html__('Gemini 2.0 Flash (Experimental)', 'chatgpt-fluent-connector'); ?>
            </option>
            <option value="gemini-2.5-flash" <?php selected($model, 'gemini-2.5-flash'); ?>>
                <?php echo esc_html__('Gemini 2.5 Flash (Newest, Fastest)', 'chatgpt-fluent-connector'); ?>
            </option>
            <option value="gemini-2.5-pro" <?php selected($model, 'gemini-2.5-pro'); ?>>
                <?php echo esc_html__('Gemini 2.5 Pro (Most Advanced)', 'chatgpt-fluent-connector'); ?>
            </option>
        </select>
        <p class="description">
            <?php echo esc_html__('Select which Gemini model to use.', 'chatgpt-fluent-connector'); ?><br>
            <strong><?php echo esc_html__('Note:', 'chatgpt-fluent-connector'); ?></strong> <?php echo esc_html__('Gemini 2.5 models require API access. Check if your API key has access to these models.', 'chatgpt-fluent-connector'); ?>
        </p>
        <?php
    }

    /**
     * Claude API Key field
     */
    public function claude_api_key_field_callback() {
        $api_key = get_option('sfaic_claude_api_key');
        ?>
        <input type="password" name="sfaic_claude_api_key" id="sfaic_claude_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
        <p class="description">
            <?php echo esc_html__('Enter your Anthropic Claude API key.', 'chatgpt-fluent-connector'); ?> 
            <a href="https://console.anthropic.com/" target="_blank"><?php echo esc_html__('Get your API key from Anthropic Console', 'chatgpt-fluent-connector'); ?></a>
        </p>
        <?php
    }

    /**
     * Claude API Endpoint field
     */
    public function claude_api_endpoint_field_callback() {
        $api_endpoint = get_option('sfaic_claude_api_endpoint', 'https://api.anthropic.com/v1/messages');
        ?>
        <input type="text" name="sfaic_claude_api_endpoint" value="<?php echo esc_attr($api_endpoint); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('The default endpoint for the Claude API', 'chatgpt-fluent-connector'); ?></p>
        <?php
    }

    /**
     * Claude Model field
     */
    public function claude_model_field_callback() {
        $model = get_option('sfaic_claude_model', 'claude-opus-4-20250514');
        ?>
        <select name="sfaic_claude_model" id="sfaic_claude_model">
            <option value="claude-opus-4-20250514" <?php selected($model, 'claude-opus-4-20250514'); ?>>
                <?php echo esc_html__('Claude Opus 4 (Most powerful, complex tasks)', 'chatgpt-fluent-connector'); ?>
            </option>
            <option value="claude-sonnet-4-20250514" <?php selected($model, 'claude-sonnet-4-20250514'); ?>>
                <?php echo esc_html__('Claude Sonnet 4 (Balanced performance)', 'chatgpt-fluent-connector'); ?>
            </option>
            <option value="claude-3-opus-20240229" <?php selected($model, 'claude-3-opus-20240229'); ?>>
                <?php echo esc_html__('Claude 3 Opus (Previous generation, powerful)', 'chatgpt-fluent-connector'); ?>
            </option>
            <option value="claude-3-sonnet-20240229" <?php selected($model, 'claude-3-sonnet-20240229'); ?>>
                <?php echo esc_html__('Claude 3 Sonnet (Previous generation, balanced)', 'chatgpt-fluent-connector'); ?>
            </option>
            <option value="claude-3-haiku-20240307" <?php selected($model, 'claude-3-haiku-20240307'); ?>>
                <?php echo esc_html__('Claude 3 Haiku (Fast, cost-effective)', 'chatgpt-fluent-connector'); ?>
            </option>
        </select>
        <p class="description">
            <?php echo esc_html__('Select which Claude model to use. Opus 4 is the most advanced model.', 'chatgpt-fluent-connector'); ?>
        </p>
        <?php
    }

    /**
     * Admin page HTML (updated with PageSnap service testing)
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_provider = get_option('sfaic_api_provider', 'openai');
        $background_enabled = get_option('sfaic_enable_background_processing', true);
        $default_pdf_service = get_option('sfaic_default_pdf_service', 'mpdf');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="notice notice-info">
                <p><strong><?php _e('Setup Status:', 'chatgpt-fluent-connector'); ?></strong></p>
                <ul>
                    <li>✅ <?php _e('AI Provider:', 'chatgpt-fluent-connector'); ?> <?php
                        switch ($api_provider) {
                            case 'openai':
                                echo 'OpenAI (ChatGPT)';
                                break;
                            case 'gemini':
                                echo 'Google Gemini';
                                break;
                            case 'claude':
                                echo 'Anthropic Claude';
                                break;
                        }
                        ?></li>
                    <li><?php
                        echo $background_enabled ? '✅' : '⚠️';
                        echo ' ' . __('Background Processing:', 'chatgpt-fluent-connector') . ' ';
                        echo $background_enabled ? 'Enabled' : 'Disabled';
                        ?></li>
                    <li><?php
                        if ($default_pdf_service === 'pagesnap') {
                            $pagesnap_available = !empty(get_option('sfaic_pagesnap_api_key'));
                            echo $pagesnap_available ? '✅' : '⚠️';
                            echo ' ' . __('PDF Service:', 'chatgpt-fluent-connector') . ' ';
                            echo $pagesnap_available ? 'PageSnap (Ready)' : 'PageSnap (API Key Required)';
                        } elseif ($default_pdf_service === 'pdfshift') {
                            $pdfshift_available = !empty(get_option('sfaic_pdfshift_api_key'));
                            echo $pdfshift_available ? '✅' : '⚠️';
                            echo ' ' . __('PDF Service:', 'chatgpt-fluent-connector') . ' ';
                            echo $pdfshift_available ? 'PDFShift (Ready)' : 'PDFShift (API Key Required)';
                        } else {
                            echo class_exists('Mpdf\Mpdf') ? '✅' : '⚠️';
                            echo ' ' . __('PDF Service:', 'chatgpt-fluent-connector') . ' ';
                            echo class_exists('Mpdf\Mpdf') ? 'Local mPDF (Ready)' : 'Local mPDF (Library Missing)';
                        }
                        ?></li>
                </ul>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('sfaic_settings'); ?>

                <!-- General Settings Section -->
                <div class="sfaic-settings-section">
                    <h2><?php _e('General Settings', 'chatgpt-fluent-connector'); ?></h2>
                    <p><?php _e('Choose which AI provider to use and configure general settings.', 'chatgpt-fluent-connector'); ?></p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('AI Provider', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->api_provider_field_callback(); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Background Processing Settings Section -->
                <div class="sfaic-settings-section">
                    <h2><?php _e('Background Processing Settings', 'chatgpt-fluent-connector'); ?> <span class="sfaic-processing-badge">⚡ PERFORMANCE</span></h2>
                    <?php $this->background_section_callback(); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('Enable Background Processing', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->enable_background_processing_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Processing Delay', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->background_processing_delay_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Max Concurrent Jobs', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->max_concurrent_jobs_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Job Timeout', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->job_timeout_field_callback(); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Enhanced PDF Services Section -->
                <div class="sfaic-settings-section" id="sfaic_pdf_section">
                    <h2><?php _e('PDF Generation Settings', 'chatgpt-fluent-connector'); ?> <span class="sfaic-pdf-badge">PDF</span></h2>
                    <?php $this->pdf_section_callback(); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('Default PDF Service', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->default_pdf_service_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('PDFShift API Key', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->pdfshift_api_key_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('PageSnap API Key', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->pagesnap_api_key_field_callback(); ?></td>
                        </tr>
                    </table>

                    <!-- Enhanced PDF Test Section -->
                    <div style="background: #f8f9ff; padding: 15px; margin: 15px 0; border-left: 4px solid #2196f3; border-radius: 0 3px 3px 0;">
                        <h4 style="margin-top: 0;"><?php _e('🧪 Test PDF Services', 'chatgpt-fluent-connector'); ?></h4>
                        <p><?php _e('Test your PDF generation services to ensure they are working correctly:', 'chatgpt-fluent-connector'); ?></p>
                        
                        <div style="margin: 15px 0;">
                            <button type="button" id="test-mpdf-btn" class="button button-secondary">
                                <span class="dashicons dashicons-desktop" style="vertical-align: middle; margin-right: 5px;"></span>
                                <?php _e('Test Local mPDF', 'chatgpt-fluent-connector'); ?>
                            </button>
                            <div id="mpdf-test-result" class="api-test-result" style="margin-top: 10px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Provider-specific settings sections with conditional display -->
                <div id="openai-settings" class="sfaic-provider-settings" <?php echo ($api_provider != 'openai') ? 'style="display:none;"' : ''; ?>>
                    <h2><?php _e('OpenAI API Settings', 'chatgpt-fluent-connector'); ?> <span class="sfaic-api-badge openai">ChatGPT</span></h2>
                    <p><?php _e('Enter your ChatGPT (OpenAI) API credentials below.', 'chatgpt-fluent-connector'); ?></p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('API Key', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->api_key_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('API Endpoint', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->api_endpoint_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('GPT Model', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->model_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Test Connection', 'chatgpt-fluent-connector'); ?></th>
                            <td>
                                <button type="button" id="test-openai-btn" class="button button-secondary api-test-button">
                                    <span class="dashicons dashicons-admin-network" style="vertical-align: middle; margin-right: 5px;"></span>
                                    <?php _e('Test OpenAI API', 'chatgpt-fluent-connector'); ?>
                                </button>
                                <div id="openai-test-result" class="api-test-result"></div>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="gemini-settings" class="sfaic-provider-settings" <?php echo ($api_provider != 'gemini') ? 'style="display:none;"' : ''; ?>>
                    <h2><?php _e('Google Gemini API Settings', 'chatgpt-fluent-connector'); ?> <span class="sfaic-api-badge gemini">Gemini</span></h2>
                    <p><?php _e('Enter your Google Gemini API credentials below.', 'chatgpt-fluent-connector'); ?></p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('Gemini API Key', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->gemini_api_key_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Gemini API Endpoint', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->gemini_api_endpoint_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Gemini Model', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->gemini_model_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Test Connection', 'chatgpt-fluent-connector'); ?></th>
                            <td>
                                <button type="button" id="test-gemini-btn" class="button button-secondary api-test-button">
                                    <span class="dashicons dashicons-admin-network" style="vertical-align: middle; margin-right: 5px;"></span>
                                    <?php _e('Test Gemini API', 'chatgpt-fluent-connector'); ?>
                                </button>
                                <div id="gemini-test-result" class="api-test-result"></div>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="claude-settings" class="sfaic-provider-settings" <?php echo ($api_provider != 'claude') ? 'style="display:none;"' : ''; ?>>
                    <h2><?php _e('Anthropic Claude API Settings', 'chatgpt-fluent-connector'); ?> <span class="sfaic-api-badge claude">Claude</span></h2>
                    <p><?php _e('Enter your Anthropic Claude API credentials below.', 'chatgpt-fluent-connector'); ?></p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('Claude API Key', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->claude_api_key_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Claude API Endpoint', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->claude_api_endpoint_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Claude Model', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->claude_model_field_callback(); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Test Connection', 'chatgpt-fluent-connector'); ?></th>
                            <td>
                                <button type="button" id="test-claude-btn" class="button button-secondary api-test-button">
                                    <span class="dashicons dashicons-admin-network" style="vertical-align: middle; margin-right: 5px;"></span>
                                    <?php _e('Test Claude API', 'chatgpt-fluent-connector'); ?>
                                </button>
                                <div id="claude-test-result" class="api-test-result"></div>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button(__('Save Settings', 'chatgpt-fluent-connector'), 'primary', 'submit', true, array('style' => 'font-size: 16px; padding: 10px 20px;')); ?>
            </form>

            <hr>

            <h2><?php echo esc_html__('🚀 Quick Actions', 'chatgpt-fluent-connector'); ?></h2>
            <p><?php echo esc_html__('Create AI prompts that are triggered when specific Fluent Forms are submitted:', 'chatgpt-fluent-connector'); ?></p>

            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=sfaic_prompt')); ?>" class="button button-primary" style="display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php echo esc_html__('Add New AI Prompt', 'chatgpt-fluent-connector'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=sfaic_prompt')); ?>" class="button" style="display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php echo esc_html__('View All Prompts', 'chatgpt-fluent-connector'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=sfaic_prompt&page=sfaic-response-logs')); ?>" class="button" style="display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php echo esc_html__('View Response Logs', 'chatgpt-fluent-connector'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=sfaic_prompt&page=sfaic-background-jobs')); ?>" class="button" style="display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php echo esc_html__('Monitor Background Jobs', 'chatgpt-fluent-connector'); ?>
                </a>
            </div>

            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    // Toggle API provider settings visibility
                    $('#sfaic_api_provider').on('change', function () {
                        var provider = $(this).val();
                        $('.sfaic-provider-settings').hide();
                        $('#' + provider + '-settings').show();
                    });

                    // API Testing Functions
                    function testAPI(provider) {
                        var apiKey = '';
                        var model = '';
                        var resultDiv = '#' + provider + '-test-result';
                        var button = '#test-' + provider + '-btn';

                        // Get API key and model based on provider
                        switch (provider) {
                            case 'openai':
                                apiKey = $('#sfaic_api_key').val();
                                model = $('#sfaic_model').val();
                                break;
                            case 'gemini':
                                apiKey = $('#sfaic_gemini_api_key').val();
                                model = $('#sfaic_gemini_model').val();
                                break;
                            case 'claude':
                                apiKey = $('#sfaic_claude_api_key').val();
                                model = $('#sfaic_claude_model').val();
                                break;
                        }

                        if (!apiKey) {
                            $(resultDiv).html('<div class="notice notice-error inline"><p>Please enter an API key first.</p></div>');
                            return;
                        }

                        // Show loading state
                        $(button).prop('disabled', true);
                        $(button).find('.dashicons').addClass('spin');
                        $(resultDiv).html('<div class="notice notice-info inline"><p>Testing connection...</p></div>');

                        // Make AJAX request
                        $.post(ajaxurl, {
                            action: 'sfaic_test_api',
                            nonce: sfaic_ajax.test_nonce,
                            provider: provider,
                            api_key: apiKey,
                            model: model
                        }, function (response) {
                            $(button).prop('disabled', false);
                            $(button).find('.dashicons').removeClass('spin');

                            if (response.success) {
                                var html = '<div class="notice notice-success inline">';
                                html += '<p><strong>✅ Connection Successful!</strong></p>';
                                html += '<p><strong>Model:</strong> ' + response.model + '</p>';
                                html += '<p><strong>Response Time:</strong> ' + response.execution_time + 's</p>';
                                if (response.tokens && response.tokens.total_tokens > 0) {
                                    html += '<p><strong>Tokens Used:</strong> ' + response.tokens.total_tokens + 
                                           ' (Input: ' + response.tokens.prompt_tokens + 
                                           ', Output: ' + response.tokens.completion_tokens + ')</p>';
                                }
                                html += '<details style="margin-top: 10px;"><summary style="cursor: pointer; font-weight: bold;">View Response</summary>';
                                html += '<div style="background: #f9f9f9; padding: 10px; margin-top: 5px; border-radius: 3px; font-family: monospace; font-size: 12px;">';
                                html += response.response.replace(/\n/g, '<br>');
                                html += '</div></details>';
                                html += '</div>';
                            } else {
                                var html = '<div class="notice notice-error inline">';
                                html += '<p><strong>❌ Connection Failed</strong></p>';
                                html += '<p><strong>Error:</strong> ' + response.message + '</p>';
                                html += '</div>';
                            }

                            $(resultDiv).html(html);
                        }).fail(function () {
                            $(button).prop('disabled', false);
                            $(button).find('.dashicons').removeClass('spin');
                            $(resultDiv).html('<div class="notice notice-error inline"><p>Request failed. Please try again.</p></div>');
                        });
                    }

                    // Test button click handlers
                    $('#test-openai-btn').click(function () {
                        testAPI('openai');
                    });

                    $('#test-gemini-btn').click(function () {
                        testAPI('gemini');
                    });

                    $('#test-claude-btn').click(function () {
                        testAPI('claude');
                    });

                    // mPDF Test button
                    $('#test-mpdf-btn').click(function () {
                        var button = $(this);
                        var resultDiv = '#mpdf-test-result';

                        button.prop('disabled', true);
                        button.find('.dashicons').addClass('spin');
                        $(resultDiv).html('<div class="notice notice-info inline"><p>Testing mPDF library...</p></div>');

                        $.post(ajaxurl, {
                            action: 'sfaic_test_pdf',
                            nonce: sfaic_ajax.pdf_nonce
                        }, function (response) {
                            button.prop('disabled', false);
                            button.find('.dashicons').removeClass('spin');

                            if (response.success) {
                                var html = '<div class="notice notice-success inline">';
                                html += '<p><strong>✅ mPDF Test Successful!</strong></p>';
                                html += '<p>' + response.message + '</p>';
                                if (response.details && response.details.pdf_size) {
                                    html += '<p><strong>Test PDF Size:</strong> ' + response.details.pdf_size + '</p>';
                                }
                                html += '</div>';
                            } else {
                                var html = '<div class="notice notice-error inline">';
                                html += '<p><strong>❌ mPDF Test Failed</strong></p>';
                                html += '<p><strong>Error:</strong> ' + response.message + '</p>';
                                html += '</div>';
                            }

                            $(resultDiv).html(html);
                        }).fail(function () {
                            button.prop('disabled', false);
                            button.find('.dashicons').removeClass('spin');
                            $(resultDiv).html('<div class="notice notice-error inline"><p>Request failed. Please try again.</p></div>');
                        });
                    });

                    // PDFShift Test button
                    $('#test-pdfshift-btn').click(function () {
                        var button = $(this);
                        var resultDiv = $('#pdfshift-test-result');
                        var apiKey = $('#sfaic_pdfshift_api_key').val();

                        if (!apiKey) {
                            resultDiv.html('<div class="notice notice-error inline"><p>Please enter a PDFShift API key first.</p></div>');
                            return;
                        }

                        button.prop('disabled', true);
                        button.find('.dashicons').addClass('spin');
                        resultDiv.html('<div class="notice notice-info inline"><p>Testing PDFShift service...</p></div>');

                        $.post(ajaxurl, {
                            action: 'sfaic_test_pdfshift',
                            nonce: sfaic_ajax.pdfshift_nonce,
                            api_key: apiKey
                        }, function (response) {
                            button.prop('disabled', false);
                            button.find('.dashicons').removeClass('spin');

                            if (response.success) {
                                var html = '<div class="notice notice-success inline">';
                                html += '<p><strong>✅ PDFShift Test Successful!</strong></p>';
                                html += '<p><strong>Service:</strong> ' + response.details.service + '</p>';
                                if (response.details.pdf_size) {
                                    html += '<p><strong>Test PDF Size:</strong> ' + response.details.pdf_size + '</p>';
                                }
                                html += '<details style="margin-top: 10px;"><summary style="cursor: pointer; font-weight: bold;">View Service Features</summary>';
                                html += '<div style="background: #f9f9f9; padding: 10px; margin-top: 5px; border-radius: 3px; font-size: 12px;">';
                                if (response.details.features) {
                                    for (var feature in response.details.features) {
                                        html += '<strong>' + feature.replace(/_/g, ' ') + ':</strong> ' + response.details.features[feature] + '<br>';
                                    }
                                }
                                html += '</div></details>';
                                html += '</div>';
                            } else {
                                var html = '<div class="notice notice-error inline">';
                                html += '<p><strong>❌ PDFShift Test Failed</strong></p>';
                                html += '<p><strong>Error:</strong> ' + response.message + '</p>';
                                html += '</div>';
                            }

                            resultDiv.html(html);
                        }).fail(function () {
                            button.prop('disabled', false);
                            button.find('.dashicons').removeClass('spin');
                            resultDiv.html('<div class="notice notice-error inline"><p>Request failed. Please try again.</p></div>');
                        });
                    });

                    // PageSnap Test button
                    $('#test-pagesnap-btn').click(function () {
                        var button = $(this);
                        var resultDiv = $('#pagesnap-test-result');
                        var apiKey = $('#sfaic_pagesnap_api_key').val();

                        if (!apiKey) {
                            resultDiv.html('<div class="notice notice-error inline"><p>Please enter a PageSnap API key first.</p></div>');
                            return;
                        }

                        button.prop('disabled', true);
                        button.find('.dashicons').addClass('spin');
                        resultDiv.html('<div class="notice notice-info inline"><p>Testing PageSnap service...</p></div>');

                        $.post(ajaxurl, {
                            action: 'sfaic_test_pagesnap',
                            nonce: sfaic_ajax.pagesnap_nonce,
                            api_key: apiKey
                        }, function (response) {
                            button.prop('disabled', false);
                            button.find('.dashicons').removeClass('spin');

                            if (response.success) {
                                var html = '<div class="notice notice-success inline">';
                                html += '<p><strong>✅ PageSnap Test Successful!</strong></p>';
                                html += '<p><strong>Service:</strong> ' + response.details.service + '</p>';
                                if (response.details.pdf_size) {
                                    html += '<p><strong>Test PDF Size:</strong> ' + response.details.pdf_size + '</p>';
                                }
                                html += '<details style="margin-top: 10px;"><summary style="cursor: pointer; font-weight: bold;">View Service Features</summary>';
                                html += '<div style="background: #f9f9f9; padding: 10px; margin-top: 5px; border-radius: 3px; font-size: 12px;">';
                                if (response.details.features) {
                                    for (var feature in response.details.features) {
                                        html += '<strong>' + feature.replace(/_/g, ' ') + ':</strong> ' + response.details.features[feature] + '<br>';
                                    }
                                }
                                html += '</div></details>';
                                html += '</div>';
                            } else {
                                var html = '<div class="notice notice-error inline">';
                                html += '<p><strong>❌ PageSnap Test Failed</strong></p>';
                                html += '<p><strong>Error:</strong> ' + response.message + '</p>';
                                html += '</div>';
                            }

                            resultDiv.html(html);
                        }).fail(function () {
                            button.prop('disabled', false);
                            button.find('.dashicons').removeClass('spin');
                            resultDiv.html('<div class="notice notice-error inline"><p>Request failed. Please try again.</p></div>');
                        });
                    });
                });
            </script>

            <style>
                .sfaic-settings-section {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    padding: 20px;
                    margin-bottom: 20px;
                    border-radius: 5px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }

                .sfaic-provider-settings {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    padding: 20px;
                    margin-bottom: 20px;
                    border-radius: 5px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }

                .sfaic-api-badge {
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    vertical-align: middle;
                    margin-left: 8px;
                }

                .sfaic-api-badge.openai {
                    background-color: #10a37f;
                    color: white;
                }

                .sfaic-api-badge.gemini {
                    background-color: #4285f4;
                    color: white;
                }

                .sfaic-api-badge.claude {
                    background-color: #8B5CF6;
                    color: white;
                }

                .sfaic-pdf-badge {
                    background-color: #28a745;
                    color: white;
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    vertical-align: middle;
                    margin-left: 8px;
                }

                .sfaic-processing-badge {
                    background-color: #ff6b35;
                    color: white;
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    vertical-align: middle;
                    margin-left: 8px;
                }

                .button {
                    transition: all 0.2s ease;
                }

                .button:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }

                .api-test-result {
                    margin-top: 15px;
                    max-width: 600px;
                }

                .api-test-result .notice {
                    margin: 0;
                }

                @keyframes spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }

                .dashicons.spin {
                    animation: spin 1s linear infinite;
                }

                details summary {
                    outline: none;
                }

                details[open] summary {
                    margin-bottom: 10px;
                }
            </style>
        </div>
        <?php
    }

}