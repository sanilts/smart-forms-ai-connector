<?php
/**
 * AI API Settings Class - Simplified without Background Processing and Chunking Options
 * 
 * Handles the plugin settings page and API credentials for multiple providers
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Settings {

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
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Enqueue admin scripts for settings page
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our settings page
        if ($hook !== 'settings_page_sfaic-settings') {
            return;
        }

        // Enqueue API testing styles and scripts
        wp_enqueue_style(
            'sfaic-admin-styles',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'sfaic-admin-scripts',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // Localize script with AJAX data
        wp_localize_script('sfaic-admin-scripts', 'sfaic_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'test_nonce' => wp_create_nonce('sfaic_test_api'),
            'pdf_nonce' => wp_create_nonce('sfaic_test_pdf'),
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
        if (function_exists('sfaic_main') && isset(sfaic_main()->pdf_generator)) {
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
                if (!function_exists('sfaic_main') || !isset(sfaic_main()->api)) {
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
                if (!function_exists('sfaic_main') || !isset(sfaic_main()->gemini_api)) {
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
                if (!function_exists('sfaic_main') || !isset(sfaic_main()->claude_api)) {
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
     * Register plugin settings
     */
    public function register_settings() {
        // General Settings
        register_setting('sfaic_settings', 'sfaic_api_provider', array(
            'default' => 'openai'
        ));

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
            'default' => 'gemini-1.5-pro-latest'
        ));

        // Claude Settings
        register_setting('sfaic_settings', 'sfaic_claude_api_key');
        register_setting('sfaic_settings', 'sfaic_claude_api_endpoint', array(
            'default' => 'https://api.anthropic.com/v1/messages'
        ));
        register_setting('sfaic_settings', 'sfaic_claude_model', array(
            'default' => 'claude-opus-4-20250514'
        ));

        // Add settings sections and fields
        $this->add_settings_sections_and_fields();
    }

    /**
     * Add all settings sections and fields
     */
    private function add_settings_sections_and_fields() {
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

        // PDF Section
        add_settings_section(
            'sfaic_pdf_section',
            __('PDF Generation Settings', 'chatgpt-fluent-connector'),
            array($this, 'pdf_section_callback'),
            'sfaic_settings'
        );
    }

    /**
     * Section callbacks
     */
    public function general_section_callback() {
        echo '<p>' . esc_html__('Choose which AI provider to use and configure general settings.', 'chatgpt-fluent-connector') . '</p>';
    }

    public function openai_section_callback() {
        echo '<p>' . esc_html__('Enter your ChatGPT (OpenAI) API credentials below.', 'chatgpt-fluent-connector') . '</p>';
    }

    public function gemini_section_callback() {
        echo '<p>' . esc_html__('Enter your Google Gemini API credentials below.', 'chatgpt-fluent-connector') . '</p>';
    }

    public function claude_section_callback() {
        echo '<p>' . esc_html__('Enter your Anthropic Claude API credentials below.', 'chatgpt-fluent-connector') . '</p>';
    }

    public function pdf_section_callback() {
        echo '<div style="background: #e8f5e8; padding: 20px; margin: 15px 0; border-left: 4px solid #28a745; border-radius: 0 3px 3px 0;">';
        echo '<h3 style="margin-top: 0;">' . esc_html__('🏠 Local PDF Generation with mPDF', 'chatgpt-fluent-connector') . '</h3>';
        echo '<p>' . esc_html__('This plugin uses the local mPDF library to convert AI responses to PDF documents.', 'chatgpt-fluent-connector') . '</p>';

        echo '<div style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-top: 15px;">';
        echo '<h4 style="margin-top: 0; color: #28a745;">🔧 ' . esc_html__('mPDF Library Features', 'chatgpt-fluent-connector') . '</h4>';
        echo '<ul style="margin: 10px 0; padding-left: 20px; color: #666;">';
        echo '<li>✅ ' . esc_html__('No external dependencies', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Works offline', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Full HTML/CSS support', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Privacy friendly', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('Emoji support with image conversion', 'chatgpt-fluent-connector') . '</li>';
        echo '<li>✅ ' . esc_html__('UTF-8 and multilingual support', 'chatgpt-fluent-connector') . '</li>';
        echo '</ul>';
        echo '</div>';

        // mPDF Status
        echo '<div style="margin-top: 15px; padding: 15px; border-radius: 5px; ';
        if (class_exists('Mpdf\Mpdf')) {
            echo 'background: #d4edda; border-left: 4px solid #28a745;">';
            echo '<p style="margin: 0; color: #155724;"><strong>✅ ' . esc_html__('Status: mPDF library is installed and ready!', 'chatgpt-fluent-connector') . '</strong></p>';
        } else {
            echo 'background: #f8d7da; border-left: 4px solid #dc3545;">';
            echo '<p style="margin: 0; color: #721c24;"><strong>❌ ' . esc_html__('Status: mPDF library is not installed', 'chatgpt-fluent-connector') . '</strong></p>';
            echo '<div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 3px;">';
            echo '<p style="margin: 0; font-size: 13px;"><strong>' . esc_html__('Installation Options:', 'chatgpt-fluent-connector') . '</strong></p>';
            echo '<ol style="margin: 5px 0 0 20px; font-size: 13px;">';
            echo '<li>' . esc_html__('Via Composer (Recommended):', 'chatgpt-fluent-connector') . ' <code>composer require mpdf/mpdf</code></li>';
            echo '<li><a href="https://github.com/mpdf/mpdf/releases" target="_blank">' . esc_html__('Manual Download from GitHub', 'chatgpt-fluent-connector') . '</a></li>';
            echo '</ol>';
            echo '</div>';
        }
        echo '</div>';

        // PDF Test Button
        echo '<div style="margin-top: 15px;">';
        echo '<button type="button" id="test-pdf-btn" class="button button-secondary" style="background-color: #e74c3c; border-color: #c0392b; color: white;">';
        echo '<span class="dashicons dashicons-pdf" style="vertical-align: middle; margin-right: 5px;"></span>';
        echo esc_html__('Test PDF Generation', 'chatgpt-fluent-connector');
        echo '</button>';
        echo '<div id="pdf-test-result" style="margin-top: 10px;"></div>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Field callbacks
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

    public function api_endpoint_field_callback() {
        $api_endpoint = get_option('sfaic_api_endpoint', 'https://api.openai.com/v1/chat/completions');
        ?>
        <input type="text" name="sfaic_api_endpoint" value="<?php echo esc_attr($api_endpoint); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('The default endpoint is the ChatGPT completions API', 'chatgpt-fluent-connector'); ?></p>
        <?php
    }

    public function model_field_callback() {
        $model = get_option('sfaic_model', 'gpt-3.5-turbo');
        $models = array(
            'gpt-3.5-turbo' => __('GPT-3.5 Turbo (4K tokens, fastest, most cost-effective)', 'chatgpt-fluent-connector'),
            'gpt-4' => __('GPT-4 (8K tokens, more powerful, more expensive)', 'chatgpt-fluent-connector'),
            'gpt-4-turbo' => __('GPT-4 Turbo (128K tokens, latest GPT-4 model)', 'chatgpt-fluent-connector'),
            'gpt-4o' => __('GPT-4o (128K tokens, optimized for speed and cost)', 'chatgpt-fluent-connector'),
            'gpt-4o-mini' => __('GPT-4o Mini (128K tokens, fastest and most affordable)', 'chatgpt-fluent-connector'),
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

    public function gemini_api_endpoint_field_callback() {
        $api_endpoint = get_option('sfaic_gemini_api_endpoint', 'https://generativelanguage.googleapis.com/v1beta/models/');
        ?>
        <input type="text" name="sfaic_gemini_api_endpoint" value="<?php echo esc_attr($api_endpoint); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('The default endpoint for the Gemini API', 'chatgpt-fluent-connector'); ?></p>
        <?php
    }

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

    public function claude_api_endpoint_field_callback() {
        $api_endpoint = get_option('sfaic_claude_api_endpoint', 'https://api.anthropic.com/v1/messages');
        ?>
        <input type="text" name="sfaic_claude_api_endpoint" value="<?php echo esc_attr($api_endpoint); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('The default endpoint for the Claude API', 'chatgpt-fluent-connector'); ?></p>
        <?php
    }

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
     * Admin page HTML
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_provider = get_option('sfaic_api_provider', 'openai');
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
                        echo class_exists('Mpdf\Mpdf') ? '✅' : '⚠️';
                        echo ' ' . __('PDF Generation:', 'chatgpt-fluent-connector') . ' ';
                        echo class_exists('Mpdf\Mpdf') ? 'Local mPDF (Ready)' : 'Local mPDF (Library Missing)';
                        ?></li>
                </ul>
                <p><strong><?php _e('Note:', 'chatgpt-fluent-connector'); ?></strong> <?php _e('Background processing and chunking settings are now configured per-prompt when editing individual prompts.', 'chatgpt-fluent-connector'); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('sfaic_settings'); ?>

                <!-- General Settings Section -->
                <div class="sfaic-settings-section">
                    <h2><?php _e('General Settings', 'chatgpt-fluent-connector'); ?></h2>
                    <?php $this->general_section_callback(); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('AI Provider', 'chatgpt-fluent-connector'); ?></th>
                            <td><?php $this->api_provider_field_callback(); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Provider-specific settings sections with conditional display -->
                <div id="openai-settings" class="sfaic-provider-settings" <?php echo ($api_provider != 'openai') ? 'style="display:none;"' : ''; ?>>
                    <h2><?php _e('OpenAI API Settings', 'chatgpt-fluent-connector'); ?> <span class="sfaic-api-badge openai">ChatGPT</span></h2>
                    <?php $this->openai_section_callback(); ?>

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
                    <?php $this->gemini_section_callback(); ?>

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
                    <?php $this->claude_section_callback(); ?>

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

                <!-- PDF Generation Settings Section -->
                <div class="sfaic-settings-section">
                    <h2><?php _e('PDF Generation Settings', 'chatgpt-fluent-connector'); ?> <span class="sfaic-pdf-badge">PDF</span></h2>
                    <?php $this->pdf_section_callback(); ?>
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

                    // PDF Test button
                    $('#test-pdf-btn').click(function () {
                        var button = $(this);
                        var resultDiv = '#pdf-test-result';

                        button.prop('disabled', true);
                        button.find('.dashicons').addClass('spin');
                        $(resultDiv).html('<div class="notice notice-info inline"><p>Testing PDF generation...</p></div>');

                        $.post(ajaxurl, {
                            action: 'sfaic_test_pdf',
                            nonce: sfaic_ajax.pdf_nonce
                        }, function (response) {
                            button.prop('disabled', false);
                            button.find('.dashicons').removeClass('spin');

                            if (response.success) {
                                var html = '<div class="notice notice-success inline">';
                                html += '<p><strong>✅ PDF Generation Test Successful!</strong></p>';
                                html += '<p>' + response.message + '</p>';
                                if (response.details && response.details.pdf_size) {
                                    html += '<p><strong>Test PDF Size:</strong> ' + response.details.pdf_size + '</p>';
                                }
                                html += '</div>';
                            } else {
                                var html = '<div class="notice notice-error inline">';
                                html += '<p><strong>❌ PDF Test Failed</strong></p>';
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