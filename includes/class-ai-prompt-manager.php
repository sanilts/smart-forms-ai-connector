<?php
/**
 * ChatGPT Prompt Custom Post Type - Enhanced Version
 * 
 * Handles registration and management of the ChatGPT Prompt custom post type
 * with improved chunking settings and per-prompt background processing
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Prompt_Manager {

    /**
     * Constructor
     */
    public function __construct() {
        // Register custom post type
        add_action('init', array($this, 'register_post_type'));

        // Add meta boxes
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));

        // Save post meta
        add_action('save_post', array($this, 'save_post_meta'));
    }

    /**
     * Register custom post type for AI prompts
     */
    public function register_post_type() {
        $labels = array(
            'name' => _x('AI Prompts', 'post type general name', 'chatgpt-fluent-connector'),
            'singular_name' => _x('AI Prompt', 'post type singular name', 'chatgpt-fluent-connector'),
            'menu_name' => _x('AI Prompts', 'admin menu', 'chatgpt-fluent-connector'),
            'add_new' => _x('Add New', 'prompt', 'chatgpt-fluent-connector'),
            'add_new_item' => __('Add New Prompt', 'chatgpt-fluent-connector'),
            'edit_item' => __('Edit Prompt', 'chatgpt-fluent-connector'),
            'new_item' => __('New Prompt', 'chatgpt-fluent-connector'),
            'view_item' => __('View Prompt', 'chatgpt-fluent-connector'),
            'search_items' => __('Search Prompts', 'chatgpt-fluent-connector'),
            'not_found' => __('No prompts found', 'chatgpt-fluent-connector'),
            'not_found_in_trash' => __('No prompts found in Trash', 'chatgpt-fluent-connector'),
        );

        $args = array(
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-format-chat',
            'capability_type' => 'post',
            'hierarchical' => false,
            'supports' => array('title'),
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'menu_position' => 30,
            'show_in_rest' => false,
        );

        register_post_type('sfaic_prompt', $args);
    }

    /**
     * Add meta boxes for prompt settings
     */
    public function add_meta_boxes() {
        add_meta_box(
                'sfaic_prompt_settings',
                __('Prompt Settings', 'chatgpt-fluent-connector'),
                array($this, 'render_prompt_settings_meta_box'),
                'sfaic_prompt',
                'normal',
                'high'
        );

        add_meta_box(
                'sfaic_form_selection',
                __('Fluent Form Selection', 'chatgpt-fluent-connector'),
                array($this, 'render_form_selection_meta_box'),
                'sfaic_prompt',
                'side',
                'default'
        );

        add_meta_box(
                'sfaic_response_handling',
                __('Response Handling', 'chatgpt-fluent-connector'),
                array($this, 'render_response_handling_meta_box'),
                'sfaic_prompt',
                'normal',
                'default'
        );

        // Enhanced comprehensive chunking settings
        add_meta_box(
                'sfaic_chunking_settings',
                __('Response Length & Chunking', 'chatgpt-fluent-connector'),
                array($this, 'render_chunking_settings_meta_box'),
                'sfaic_prompt',
                'side',
                'default'
        );

        // NEW: Background Processing Settings
        add_meta_box(
                'sfaic_background_processing',
                __('Background Processing Settings', 'chatgpt-fluent-connector'),
                array($this, 'render_background_processing_meta_box'),
                'sfaic_prompt',
                'side',
                'default'
        );

        add_meta_box(
                'sfaic_field_mapping',
                __('User Field Mapping', 'chatgpt-fluent-connector'),
                array($this, 'render_field_mapping_meta_box'),
                'sfaic_prompt',
                'side',
                'default'
        );
    }

    /**
     * NEW: Render background processing settings meta box
     */
    public function render_background_processing_meta_box($post) {
        // Get saved values with defaults
        $enable_background_processing = get_post_meta($post->ID, '_sfaic_enable_background_processing', true);
        $background_processing_delay = get_post_meta($post->ID, '_sfaic_background_processing_delay', true);
        $job_priority = get_post_meta($post->ID, '_sfaic_job_priority', true);
        $job_timeout = get_post_meta($post->ID, '_sfaic_job_timeout', true);

        // Set defaults if empty
        if ($enable_background_processing === '') {
            $enable_background_processing = '1'; // Default to enabled
        }
        if (empty($background_processing_delay)) {
            $background_processing_delay = 5;
        }
        if (empty($job_priority)) {
            $job_priority = 0;
        }
        if (empty($job_timeout)) {
            $job_timeout = 300; // 5 minutes
        }

        // Check if background job manager is available
        $background_available = isset(sfaic_main()->background_job_manager);
        ?>
        <div style="background: #f9f9f9; padding: 15px; border-radius: 5px;">
            <h4 style="margin-top: 0; color: #0073aa;">⚙️ Processing Method</h4>

            <?php if (!$background_available): ?>
                <div class="notice notice-warning inline" style="margin: 0 0 15px 0;">
                    <p style="margin: 5px 0; font-size: 12px;">
                        <strong>⚠️ Notice:</strong> Background job manager is not available. All processing will be immediate.
                    </p>
                </div>
            <?php endif; ?>

            <table class="form-table" style="margin: 0;">
                <tr>
                    <th style="width: 120px;"><label for="sfaic_enable_background_processing"><?php _e('Processing Mode:', 'chatgpt-fluent-connector'); ?></label></th>
                    <td>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="sfaic_enable_background_processing" value="1" <?php checked($enable_background_processing, '1'); ?> <?php echo!$background_available ? 'disabled' : ''; ?>>
                            <strong><?php _e('Background Processing (Recommended)', 'chatgpt-fluent-connector'); ?></strong>
                        </label>
                        <p class="description" style="margin: 0 0 10px 20px; font-size: 11px;">
                            <?php _e('AI requests are processed in the background. Users get immediate form confirmation and receive responses via email.', 'chatgpt-fluent-connector'); ?>
                        </p>

                        <label style="display: block;">
                            <input type="radio" name="sfaic_enable_background_processing" value="0" <?php checked($enable_background_processing, '0'); ?>>
                            <strong><?php _e('Immediate Processing', 'chatgpt-fluent-connector'); ?></strong>
                        </label>
                        <p class="description" style="margin: 0 0 0 20px; font-size: 11px;">
                            <?php _e('AI requests are processed immediately during form submission. Users wait for AI response to complete.', 'chatgpt-fluent-connector'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- Background Processing Options (shown when enabled) -->
            <div id="background-processing-options" <?php echo ($enable_background_processing != '1' || !$background_available) ? 'style="display:none;"' : ''; ?>>
                <hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">
                <h4 style="margin: 10px 0; color: #0073aa;">⏱️ Background Processing Options</h4>

                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th><label for="sfaic_background_processing_delay"><?php _e('Processing Delay:', 'chatgpt-fluent-connector'); ?></label></th>
                        <td>
                            <input type="number" 
                                   name="sfaic_background_processing_delay" 
                                   id="sfaic_background_processing_delay" 
                                   value="<?php echo esc_attr($background_processing_delay); ?>" 
                                   min="0" 
                                   max="300" 
                                   class="small-text"> seconds
                            <p class="description" style="margin: 5px 0 0 0; font-size: 11px;">
                                <?php _e('Delay before starting AI processing. Useful for allowing form validation to complete.', 'chatgpt-fluent-connector'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="sfaic_job_priority"><?php _e('Job Priority:', 'chatgpt-fluent-connector'); ?></label></th>
                        <td>
                            <select name="sfaic_job_priority" id="sfaic_job_priority" class="regular-text">
                                <option value="0" <?php selected($job_priority, '0'); ?>><?php _e('Normal (0)', 'chatgpt-fluent-connector'); ?></option>
                                <option value="1" <?php selected($job_priority, '1'); ?>><?php _e('High (1)', 'chatgpt-fluent-connector'); ?></option>
                                <option value="2" <?php selected($job_priority, '2'); ?>><?php _e('Urgent (2)', 'chatgpt-fluent-connector'); ?></option>
                                <option value="-1" <?php selected($job_priority, '-1'); ?>><?php _e('Low (-1)', 'chatgpt-fluent-connector'); ?></option>
                            </select>
                            <p class="description" style="margin: 5px 0 0 0; font-size: 11px;">
                                <?php _e('Higher priority jobs are processed first. Use for important forms.', 'chatgpt-fluent-connector'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="sfaic_job_timeout"><?php _e('Timeout:', 'chatgpt-fluent-connector'); ?></label></th>
                        <td>
                            <input type="number" 
                                   name="sfaic_job_timeout" 
                                   id="sfaic_job_timeout" 
                                   value="<?php echo esc_attr($job_timeout); ?>" 
                                   min="30" 
                                   max="900" 
                                   class="small-text"> seconds
                            <p class="description" style="margin: 5px 0 0 0; font-size: 11px;">
                                <?php _e('Maximum time to wait for AI response before marking as failed.', 'chatgpt-fluent-connector'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Processing Impact Notice -->
            <div style="background: #e8f4fd; padding: 10px; border-radius: 3px; margin-top: 15px; border-left: 4px solid #0073aa;">
                <p style="margin: 0; font-size: 11px; color: #0073aa;">
                    <strong>💡 User Experience Impact:</strong><br>
                    <span id="processing-impact-text">
                        <?php if ($enable_background_processing == '1'): ?>
                            Users will receive immediate form confirmation and get AI responses via email within minutes.
                        <?php else: ?>
                            Users will wait for AI processing to complete (5-30 seconds) before seeing form confirmation.
                        <?php endif; ?>
                    </span>
                </p>
            </div>

            <!-- Background Jobs Monitor Link -->
            <?php if ($background_available): ?>
                <div style="margin-top: 15px; text-align: center;">
                    <a href="<?php echo admin_url('edit.php?post_type=sfaic_prompt&page=sfaic-background-jobs'); ?>" 
                       class="button button-secondary" 
                       style="font-size: 11px;">
                        <span class="dashicons dashicons-admin-tools" style="vertical-align: middle; font-size: 12px;"></span>
                        <?php _e('Monitor Background Jobs', 'chatgpt-fluent-connector'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // Toggle background processing options visibility
                $('input[name="sfaic_enable_background_processing"]').change(function () {
                    var isEnabled = $('input[name="sfaic_enable_background_processing"]:checked').val() === '1';
                    var isAvailable = <?php echo $background_available ? 'true' : 'false'; ?>;

                    if (isEnabled && isAvailable) {
                        $('#background-processing-options').show();
                        $('#processing-impact-text').text('Users will receive immediate form confirmation and get AI responses via email within minutes.');
                    } else {
                        $('#background-processing-options').hide();
                        $('#processing-impact-text').text('Users will wait for AI processing to complete (5-30 seconds) before seeing form confirmation.');
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Enhanced prompt settings meta box without redundant chunking options
     */
    public function render_prompt_settings_meta_box($post) {
        wp_nonce_field('sfaic_prompt_meta_save', 'sfaic_prompt_nonce');

        // Get saved values
        $system_prompt = get_post_meta($post->ID, '_sfaic_system_prompt', true);
        $user_prompt_template = get_post_meta($post->ID, '_sfaic_user_prompt_template', true);
        $temperature = get_post_meta($post->ID, '_sfaic_temperature', true);
        $prompt_type = get_post_meta($post->ID, '_sfaic_prompt_type', true);

        if (empty($prompt_type)) {
            $prompt_type = 'template';
        }
        if (empty($temperature)) {
            $temperature = 0.7;
        }
        ?>
        <table class="form-table">
            <tr>
                <th><label for="sfaic_system_prompt"><?php _e('System Prompt:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <textarea name="sfaic_system_prompt" id="sfaic_system_prompt" class="large-text code" rows="50"><?php echo esc_textarea($system_prompt); ?></textarea>
                    <p class="description"><?php _e('Instructions that define how the AI should behave (e.g., "You are a helpful assistant that specializes in...")', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e('Prompt Type:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="radio" name="sfaic_prompt_type" value="template" <?php checked($prompt_type, 'template'); ?> class="prompt-type-radio">
                        <?php _e('Use custom template', 'chatgpt-fluent-connector'); ?>
                    </label><br>

                    <label>
                        <input type="radio" name="sfaic_prompt_type" value="all_form_data" <?php checked($prompt_type, 'all_form_data'); ?> class="prompt-type-radio">
                        <?php _e('Send all form questions and answers', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('If selected, all form field labels and values will be sent to the AI in a structured format.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>
            <tr id="template-row" <?php echo ($prompt_type != 'template') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_user_prompt_template"><?php _e('User Prompt Template:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <textarea name="sfaic_user_prompt_template" id="sfaic_user_prompt_template" class="large-text code" rows="5"><?php echo esc_textarea($user_prompt_template); ?></textarea>
                    <p class="description">
                        <?php _e('The template for the user\'s message. You can use form field placeholders like {field_key} to insert form data.', 'chatgpt-fluent-connector'); ?><br>
                        <?php _e('Example: "Please analyze the following information: Name: {name}, Email: {email}, Message: {message}"', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="sfaic_temperature"><?php _e('Temperature:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="range" min="0" max="2" step="0.1" name="sfaic_temperature" id="sfaic_temperature" value="<?php echo esc_attr($temperature); ?>" oninput="document.getElementById('temp_value').innerHTML = this.value">
                    <span id="temp_value"><?php echo esc_html($temperature); ?></span>
                    <p class="description"><?php _e('Controls randomness: 0 is focused and deterministic, 1 is balanced, 2 is more random and creative', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>
        </table>

        <script>
            jQuery(document).ready(function ($) {
                // Temperature slider update
                document.getElementById('sfaic_temperature').addEventListener('input', function () {
                    document.getElementById('temp_value').innerHTML = this.value;
                });

                // Toggle template visibility based on prompt type
                $('.prompt-type-radio').change(function () {
                    if ($(this).val() === 'template') {
                        $('#template-row').show();
                    } else {
                        $('#template-row').hide();
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Enhanced comprehensive chunking settings meta box
     */
    public function render_chunking_settings_meta_box($post) {
        // Get saved values with defaults
        $max_tokens = get_post_meta($post->ID, '_sfaic_max_tokens', true);
        if (empty($max_tokens)) {
            $max_tokens = 1000;
        }

        $enable_chunking = get_post_meta($post->ID, '_sfaic_enable_chunking', true);
        $chunking_strategy = get_post_meta($post->ID, '_sfaic_chunking_strategy', true);
        $require_completion = get_post_meta($post->ID, '_sfaic_require_completion', true);

        // Advanced chunking settings
        $completion_marker = get_post_meta($post->ID, '_sfaic_completion_marker', true);
        if (empty($completion_marker)) {
            $completion_marker = '<!-- REPORT_END -->';
        }

        $enable_smart_completion = get_post_meta($post->ID, '_sfaic_enable_smart_completion', true);
        $min_content_length = get_post_meta($post->ID, '_sfaic_min_content_length', true);
        if (empty($min_content_length)) {
            $min_content_length = 500;
        }

        $completion_word_count = get_post_meta($post->ID, '_sfaic_completion_word_count', true);
        if (empty($completion_word_count)) {
            $completion_word_count = 800;
        }

        $completion_keywords = get_post_meta($post->ID, '_sfaic_completion_keywords', true);
        if (empty($completion_keywords)) {
            $completion_keywords = 'conclusion, summary, final, recommendations, regards, sincerely';
        }

        $use_token_percentage = get_post_meta($post->ID, '_sfaic_use_token_percentage', true);
        $token_completion_threshold = get_post_meta($post->ID, '_sfaic_token_completion_threshold', true);
        if (empty($token_completion_threshold)) {
            $token_completion_threshold = 70;
        }
        ?>
        <div style="background: #f9f9f9; padding: 15px; border-radius: 5px;">
            <h4 style="margin-top: 0; color: #0073aa;">📏 Response Length Settings</h4>

            <table class="form-table" style="margin: 0;">
                <tr>
                    <th style="width: 120px;"><label for="sfaic_max_tokens"><?php _e('Max Tokens:', 'chatgpt-fluent-connector'); ?></label></th>
                    <td>
                        <input type="number" 
                               min="50" 
                               max="200000" 
                               step="50" 
                               name="sfaic_max_tokens" 
                               id="sfaic_max_tokens" 
                               value="<?php echo esc_attr($max_tokens); ?>" 
                               class="small-text">
                        <p class="description" style="margin: 5px 0 0 0; font-size: 12px;">
                            <?php _e('Maximum tokens for the response. Higher values = longer responses.', 'chatgpt-fluent-connector'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th><label for="sfaic_enable_chunking"><?php _e('Long Responses:', 'chatgpt-fluent-connector'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="sfaic_enable_chunking" id="sfaic_enable_chunking" value="1" <?php checked($enable_chunking, '1'); ?>>
                            <?php _e('Enable chunking for very long responses', 'chatgpt-fluent-connector'); ?>
                        </label>
                        <p class="description" style="margin: 5px 0 0 0; font-size: 12px;">
                            <?php _e('When enabled, the AI can generate responses longer than normal limits by making multiple requests.', 'chatgpt-fluent-connector'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- Advanced Chunking Settings (shown when chunking is enabled) -->
            <div id="advanced-chunking-settings" <?php echo ($enable_chunking != '1') ? 'style="display:none;"' : ''; ?>>
                <hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">
                <h4 style="margin: 10px 0; color: #0073aa;">⚙️ Advanced Chunking Settings</h4>

                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th><label for="sfaic_chunking_strategy"><?php _e('Strategy:', 'chatgpt-fluent-connector'); ?></label></th>
                        <td>
                            <select name="sfaic_chunking_strategy" id="sfaic_chunking_strategy" class="regular-text">
                                <option value="balanced" <?php selected($chunking_strategy, 'balanced'); ?>><?php _e('Balanced (Recommended)', 'chatgpt-fluent-connector'); ?></option>
                                <option value="aggressive" <?php selected($chunking_strategy, 'aggressive'); ?>><?php _e('Aggressive (Maximum Length)', 'chatgpt-fluent-connector'); ?></option>
                                <option value="conservative" <?php selected($chunking_strategy, 'conservative'); ?>><?php _e('Conservative (Safe & Fast)', 'chatgpt-fluent-connector'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="sfaic_completion_marker"><?php _e('Stop Marker:', 'chatgpt-fluent-connector'); ?></label></th>
                        <td>
                            <input type="text" 
                                   name="sfaic_completion_marker" 
                                   id="sfaic_completion_marker" 
                                   value="<?php echo esc_attr($completion_marker); ?>" 
                                   class="regular-text">
                            <p class="description" style="margin: 5px 0 0 0; font-size: 12px;">
                                <?php _e('Text marker that tells the AI to stop generating content.', 'chatgpt-fluent-connector'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="sfaic_enable_smart_completion"><?php _e('Smart Detection:', 'chatgpt-fluent-connector'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="sfaic_enable_smart_completion" 
                                       id="sfaic_enable_smart_completion" 
                                       value="1" 
                                       <?php checked($enable_smart_completion, '1'); ?>>
                                       <?php _e('Auto-detect when response is complete', 'chatgpt-fluent-connector'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- Smart completion settings -->
                <div id="smart-completion-settings" <?php echo ($enable_smart_completion != '1') ? 'style="display:none;"' : ''; ?>>
                    <table class="form-table" style="margin: 10px 0 0 0;">
                        <tr>
                            <th><label for="sfaic_min_content_length"><?php _e('Min Length:', 'chatgpt-fluent-connector'); ?></label></th>
                            <td>
                                <input type="number" 
                                       name="sfaic_min_content_length" 
                                       id="sfaic_min_content_length" 
                                       value="<?php echo esc_attr($min_content_length); ?>" 
                                       min="100" 
                                       max="60000" 
                                       class="small-text"> characters
                            </td>
                        </tr>
                        <tr>
                            <th><label for="sfaic_completion_word_count"><?php _e('Target Words:', 'chatgpt-fluent-connector'); ?></label></th>
                            <td>
                                <input type="number" 
                                       name="sfaic_completion_word_count" 
                                       id="sfaic_completion_word_count" 
                                       value="<?php echo esc_attr($completion_word_count); ?>" 
                                       min="200" 
                                       max="25000" 
                                       class="small-text"> words
                            </td>
                        </tr>
                        <tr>
                            <th><label for="sfaic_completion_keywords"><?php _e('End Keywords:', 'chatgpt-fluent-connector'); ?></label></th>
                            <td>
                                <input type="text" 
                                       name="sfaic_completion_keywords" 
                                       id="sfaic_completion_keywords" 
                                       value="<?php echo esc_attr($completion_keywords); ?>" 
                                       class="regular-text"
                                       placeholder="conclusion, summary, final, recommendations">
                                <p class="description" style="margin: 5px 0 0 0; font-size: 12px;">
                                    <?php _e('Comma-separated words that indicate the response is complete.', 'chatgpt-fluent-connector'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="sfaic_use_token_percentage"><?php _e('Token-Based:', 'chatgpt-fluent-connector'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="sfaic_use_token_percentage" 
                                           id="sfaic_use_token_percentage" 
                                           value="1" 
                                           <?php checked($use_token_percentage, '1'); ?>>
                                           <?php _e('Use token percentage for completion timing', 'chatgpt-fluent-connector'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr class="token-completion-settings" <?php echo ($use_token_percentage != '1') ? 'style="display:none;"' : ''; ?>>
                            <th><label for="sfaic_token_completion_threshold"><?php _e('Token Threshold:', 'chatgpt-fluent-connector'); ?></label></th>
                            <td>
                                <input type="range" 
                                       name="sfaic_token_completion_threshold" 
                                       id="sfaic_token_completion_threshold" 
                                       value="<?php echo esc_attr($token_completion_threshold); ?>" 
                                       min="50" 
                                       max="95" 
                                       step="5" 
                                       oninput="document.getElementById('token_threshold_value').innerHTML = this.value + '%'">
                                <span id="token_threshold_value"><?php echo esc_html($token_completion_threshold); ?>%</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // Show/hide advanced chunking settings
                $('#sfaic_enable_chunking').change(function () {
                    if ($(this).is(':checked')) {
                        $('#advanced-chunking-settings').show();
                    } else {
                        $('#advanced-chunking-settings').hide();
                    }
                });

                // Show/hide smart completion settings
                $('#sfaic_enable_smart_completion').change(function () {
                    if ($(this).is(':checked')) {
                        $('#smart-completion-settings').show();
                    } else {
                        $('#smart-completion-settings').hide();
                    }
                });

                // Show/hide token completion settings
                $('#sfaic_use_token_percentage').change(function () {
                    if ($(this).is(':checked')) {
                        $('.token-completion-settings').show();
                    } else {
                        $('.token-completion-settings').hide();
                    }
                });

                // Token threshold slider update
                document.getElementById('sfaic_token_completion_threshold').addEventListener('input', function () {
                    document.getElementById('token_threshold_value').innerHTML = this.value + '%';
                });
            });
        </script>
        <?php
    }

    /**
     * Render field mapping meta box
     */
    public function render_field_mapping_meta_box($post) {
        // Get saved field mappings
        $first_name_field = get_post_meta($post->ID, '_sfaic_first_name_field', true);
        $last_name_field = get_post_meta($post->ID, '_sfaic_last_name_field', true);
        $email_field = get_post_meta($post->ID, '_sfaic_email_field_mapping', true);

        // Get form ID to fetch available fields
        $form_id = get_post_meta($post->ID, '_sfaic_fluent_form_id', true);

        if (empty($form_id)) {
            ?>
            <p class="description"><?php _e('Please select a form first to see available fields.', 'chatgpt-fluent-connector'); ?></p>
            <?php
            return;
        }

        // Get all form fields
        $all_fields = $this->get_form_fields($form_id);
        ?>

        <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
            <p class="description" style="margin-top: 0;">
                <?php _e('Map form fields to user information for logs and background job listings. If not set, the system will try to auto-detect these fields.', 'chatgpt-fluent-connector'); ?>
            </p>

            <table class="form-table" style="margin-top: 15px;">
                <tr>
                    <th style="padding: 10px 0;">
                        <label for="sfaic_first_name_field"><?php _e('First Name Field:', 'chatgpt-fluent-connector'); ?></label>
                    </th>
                </tr>
                <tr>
                    <td style="padding: 0 0 10px 0;">
                        <select name="sfaic_first_name_field" id="sfaic_first_name_field" class="widefat">
                            <option value=""><?php _e('Auto-detect (default)', 'chatgpt-fluent-connector'); ?></option>
                            <?php foreach ($all_fields as $field_key => $field_label) : ?>
                                <option value="<?php echo esc_attr($field_key); ?>" <?php selected($first_name_field, $field_key); ?>>
                                    <?php echo esc_html($field_label); ?> (<?php echo esc_html($field_key); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th style="padding: 10px 0;">
                        <label for="sfaic_last_name_field"><?php _e('Last Name Field:', 'chatgpt-fluent-connector'); ?></label>
                    </th>
                </tr>
                <tr>
                    <td style="padding: 0 0 10px 0;">
                        <select name="sfaic_last_name_field" id="sfaic_last_name_field" class="widefat">
                            <option value=""><?php _e('Auto-detect (default)', 'chatgpt-fluent-connector'); ?></option>
                            <?php foreach ($all_fields as $field_key => $field_label) : ?>
                                <option value="<?php echo esc_attr($field_key); ?>" <?php selected($last_name_field, $field_key); ?>>
                                    <?php echo esc_html($field_label); ?> (<?php echo esc_html($field_key); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th style="padding: 10px 0;">
                        <label for="sfaic_email_field_mapping"><?php _e('Email Field:', 'chatgpt-fluent-connector'); ?></label>
                    </th>
                </tr>
                <tr>
                    <td style="padding: 0;">
                        <select name="sfaic_email_field_mapping" id="sfaic_email_field_mapping" class="widefat">
                            <option value=""><?php _e('Auto-detect (default)', 'chatgpt-fluent-connector'); ?></option>
                            <?php foreach ($all_fields as $field_key => $field_label) : ?>
                                <option value="<?php echo esc_attr($field_key); ?>" <?php selected($email_field, $field_key); ?>>
                                    <?php echo esc_html($field_label); ?> (<?php echo esc_html($field_key); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <div style="background: #e8f5e8; padding: 10px; border-radius: 3px; margin-top: 15px;">
                <p style="margin: 0; font-size: 12px; color: #155724;">
                    <strong><?php _e('Tip:', 'chatgpt-fluent-connector'); ?></strong> 
                    <?php _e('These mappings are used to display user information in response logs and background job listings.', 'chatgpt-fluent-connector'); ?>
                </p>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // Update field mappings when form selection changes
                $('#sfaic_fluent_form_id').on('change', function () {
                    // Clear the field mapping selections when form changes
                    $('#sfaic_first_name_field, #sfaic_last_name_field, #sfaic_email_field_mapping').val('');
                });
            });
        </script>
        <?php
    }

    /**
     * Render form selection meta box
     */
    public function render_form_selection_meta_box($post) {
        // Get all Fluent Forms
        $fluent_forms = array();

        if (function_exists('wpFluent')) {
            $forms = wpFluent()->table('fluentform_forms')
                    ->select(['id', 'title'])
                    ->orderBy('id', 'DESC')
                    ->get();

            if ($forms) {
                foreach ($forms as $form) {
                    $fluent_forms[$form->id] = $form->title;
                }
            }
        }

        // Get saved form ID
        $selected_form_id = get_post_meta($post->ID, '_sfaic_fluent_form_id', true);
        ?>
        <p>
            <?php if (empty($fluent_forms)) : ?>
            <div class="notice notice-warning inline">
                <p><?php _e('No Fluent Forms found. Please create at least one form first.', 'chatgpt-fluent-connector'); ?></p>
            </div>
        <?php else : ?>
            <label for="sfaic_fluent_form_id"><?php _e('Select Form:', 'chatgpt-fluent-connector'); ?></label><br>
            <select name="sfaic_fluent_form_id" id="sfaic_fluent_form_id" class="widefat">
                <option value=""><?php _e('-- Select a form --', 'chatgpt-fluent-connector'); ?></option>
                <?php foreach ($fluent_forms as $form_id => $form_title) : ?>
                    <option value="<?php echo esc_attr($form_id); ?>" <?php selected($selected_form_id, $form_id); ?>>
                        <?php echo esc_html($form_title); ?> (ID: <?php echo esc_html($form_id); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        </p>

        <?php if (!empty($selected_form_id)) : ?>
            <p>
                <strong><?php _e('Available Form Fields:', 'chatgpt-fluent-connector'); ?></strong><br>
            <div style="max-height: 200px; overflow-y: auto; margin-top: 5px; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                <?php
                $all_fields = $this->get_form_fields($selected_form_id);
                if (!empty($all_fields)) {
                    echo '<ul style="margin-top: 0;">';
                    foreach ($all_fields as $field_key => $field_label) {
                        echo '<li><code>{' . esc_html($field_key) . '}</code> - ' . esc_html($field_label) . '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<div class="notice notice-info inline"><p>' . esc_html__('To see available form fields, please edit and save the selected form in Fluent Forms first.', 'chatgpt-fluent-connector') . '</p>';
                    echo '<p>' . esc_html__('Alternatively, you can manually determine field keys by checking the form structure in Fluent Forms.', 'chatgpt-fluent-connector') . '</p></div>';

                    $edit_link = admin_url('admin.php?page=fluent_forms&route=editor&form_id=' . $selected_form_id);
                    echo '<p><a href="' . esc_url($edit_link) . '" class="button" target="_blank">' . esc_html__('Edit Form in Fluent Forms', 'chatgpt-fluent-connector') . '</a></p>';
                }
                ?>
            </div>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render response handling meta box
     */
    public function render_response_handling_meta_box($post) {
        $response_action = get_post_meta($post->ID, '_sfaic_response_action', true);
        if (empty($response_action)) {
            $response_action = 'email';
        }

        $email_to = get_post_meta($post->ID, '_sfaic_email_to', true);
        $email_subject = get_post_meta($post->ID, '_sfaic_email_subject', true);
        $log_responses = get_post_meta($post->ID, '_sfaic_log_responses', true);
        $email_to_user = get_post_meta($post->ID, '_sfaic_email_to_user', true);
        $selected_email_field = get_post_meta($post->ID, '_sfaic_email_field', true);

        // Enhanced email customization fields
        $email_content_template = get_post_meta($post->ID, '_sfaic_email_content_template', true);
        $email_include_form_data = get_post_meta($post->ID, '_sfaic_email_include_form_data', true);
        $admin_email_enabled = get_post_meta($post->ID, '_sfaic_admin_email_enabled', true);
        $admin_email_to = get_post_meta($post->ID, '_sfaic_admin_email_to', true);
        $admin_email_subject = get_post_meta($post->ID, '_sfaic_admin_email_subject', true);

        // Get form ID for email fields
        $form_id = get_post_meta($post->ID, '_sfaic_fluent_form_id', true);
        $email_fields = array();
        if (!empty($form_id)) {
            $email_fields = $this->get_form_email_fields($form_id);
        }

        // Set default email template if empty
        if (empty($email_content_template)) {
            $email_content_template = '<h2>Thank you for your submission!</h2>
<p>Dear {first_name} {last_name},</p>
<p>We have received your form submission and generated the following response:</p>
<div style="background-color: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0;">
{ai_response}
</div>
<p>Best regards,<br>
{site_name}</p>';
        }

        // Set default admin email if empty
        if (empty($admin_email_to)) {
            $admin_email_to = get_option('admin_email');
        }

        if (empty($admin_email_subject)) {
            $admin_email_subject = 'New Form Submission - {form_title}';
        }
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php _e('What to do with the response:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="radio" name="sfaic_response_action" value="email" <?php checked($response_action, 'email'); ?>>
                        <?php _e('Send via email', 'chatgpt-fluent-connector'); ?>
                    </label><br>

                    <label>
                        <input type="radio" name="sfaic_response_action" value="store" <?php checked($response_action, 'store'); ?>>
                        <?php _e('Store only (no email)', 'chatgpt-fluent-connector'); ?>
                    </label>
                </td>
            </tr>

            <tr class="email-settings" <?php echo ($response_action != 'email') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_email_to_user"><?php _e('Email to Form Submitter:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sfaic_email_to_user" id="sfaic_email_to_user" value="1" <?php checked($email_to_user, '1'); ?>>
                        <?php _e('Send response to the person who submitted the form', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('When enabled, the response will be sent to the email address from the form submission.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="email-field-settings" <?php echo ($response_action != 'email' || $email_to_user != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_email_field"><?php _e('Email Field:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <?php if (empty($email_fields)): ?>
                        <div class="notice notice-warning inline">
                            <p><?php _e('No email fields found in the selected form. Please select a form with an email field or manually specify the recipient email.', 'chatgpt-fluent-connector'); ?></p>
                        </div>
                    <?php else: ?>
                        <select name="sfaic_email_field" id="sfaic_email_field">
                            <option value=""><?php _e('Auto-detect (recommended)', 'chatgpt-fluent-connector'); ?></option>
                            <?php foreach ($email_fields as $field_key => $field_label): ?>
                                <option value="<?php echo esc_attr($field_key); ?>" <?php selected($selected_email_field, $field_key); ?>>
                                    <?php echo esc_html($field_label); ?> (<?php echo esc_html($field_key); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('Select which form field contains the email address. Auto-detect will try to find the first valid email field.', 'chatgpt-fluent-connector'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>

            <tr class="email-settings" <?php echo ($response_action != 'email') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_email_to"><?php _e('Additional Recipients:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="text" name="sfaic_email_to" id="sfaic_email_to" value="<?php echo esc_attr($email_to); ?>" class="regular-text">
                    <p class="description"><?php _e('Optional. Additional email recipients (comma-separated). Leave blank to only send to the form submitter. You can use form field placeholders like {email}.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="email-settings" <?php echo ($response_action != 'email') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_email_subject"><?php _e('Email Subject:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="text" name="sfaic_email_subject" id="sfaic_email_subject" value="<?php echo esc_attr($email_subject); ?>" class="regular-text">
                    <p class="description"><?php _e('You can use placeholders like {first_name}, {last_name}, {form_title}, {date}, {time}', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <!-- Enhanced Email Content Template Field -->
            <tr class="email-settings" <?php echo ($response_action != 'email') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_email_content_template"><?php _e('Email Content Template:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <?php
                    wp_editor($email_content_template, 'sfaic_email_content_template', array(
                        'textarea_name' => 'sfaic_email_content_template',
                        'textarea_rows' => 15,
                        'media_buttons' => false,
                        'teeny' => false,
                        'quicktags' => true,
                        'tinymce' => array(
                            'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
                            'toolbar2' => '',
                        )
                    ));
                    ?>
                    <p class="description" style="margin-top: 10px;">
                        <?php _e('Customize the email content sent to users. Available placeholders:', 'chatgpt-fluent-connector'); ?><br>
                        <code>{ai_response}</code> - <?php _e('The AI-generated response', 'chatgpt-fluent-connector'); ?><br>
                        <code>{first_name}, {last_name}, {email}</code> - <?php _e('Form field values (use your form field names)', 'chatgpt-fluent-connector'); ?><br>
                        <code>{form_title}</code> - <?php _e('The form title', 'chatgpt-fluent-connector'); ?><br>
                        <code>{site_name}, {site_url}</code> - <?php _e('Your website details', 'chatgpt-fluent-connector'); ?><br>
                        <code>{date}, {time}</code> - <?php _e('Current date and time', 'chatgpt-fluent-connector'); ?><br>
                        <code>{entry_id}</code> - <?php _e('Form submission ID', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>

            <tr class="email-settings" <?php echo ($response_action != 'email') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_email_include_form_data"><?php _e('Include Form Data:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sfaic_email_include_form_data" id="sfaic_email_include_form_data" value="1" <?php checked($email_include_form_data, '1'); ?>>
                        <?php _e('Include all form questions and answers at the bottom of the email', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('When enabled, a table with all form data will be added below the email content.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <!-- Admin Email Settings -->
            <tr>
                <th colspan="2">
                    <h3 style="margin-bottom: 0; padding-top: 20px; border-top: 1px solid #ddd;">
                        <?php _e('Admin Notification Email', 'chatgpt-fluent-connector'); ?>
                    </h3>
                </th>
            </tr>

            <tr>
                <th><label for="sfaic_admin_email_enabled"><?php _e('Send Admin Email:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sfaic_admin_email_enabled" id="sfaic_admin_email_enabled" value="1" <?php checked($admin_email_enabled, '1'); ?>>
                        <?php _e('Send a notification email to admin with all form data', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('Admin email will always include complete form data and AI response.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="admin-email-settings" <?php echo ($admin_email_enabled != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_admin_email_to"><?php _e('Admin Email Address:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="text" name="sfaic_admin_email_to" id="sfaic_admin_email_to" value="<?php echo esc_attr($admin_email_to); ?>" class="regular-text">
                    <p class="description"><?php _e('Email address(es) to receive admin notifications (comma-separated for multiple).', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="admin-email-settings" <?php echo ($admin_email_enabled != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_admin_email_subject"><?php _e('Admin Email Subject:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="text" name="sfaic_admin_email_subject" id="sfaic_admin_email_subject" value="<?php echo esc_attr($admin_email_subject); ?>" class="regular-text">
                    <p class="description"><?php _e('Subject line for admin notification emails. Supports placeholders like {form_title}, {date}, {time}', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="sfaic_log_responses"><?php _e('Log Responses:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sfaic_log_responses" id="sfaic_log_responses" value="1" <?php checked($log_responses, '1'); ?>>
                        <?php _e('Save responses to the database', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('Useful for debugging and analytics', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>
        </table>

        <script>
            jQuery(document).ready(function ($) {
                // Toggle email settings visibility
                $('input[name="sfaic_response_action"]').change(function () {
                    if ($(this).val() === 'email') {
                        $('.email-settings').show();
                        // Also check if we should show email field settings
                        if ($('#sfaic_email_to_user').is(':checked')) {
                            $('.email-field-settings').show();
                        }
                    } else {
                        $('.email-settings').hide();
                        $('.email-field-settings').hide();
                    }
                });

                // Toggle email field settings visibility
                $('#sfaic_email_to_user').change(function () {
                    if ($(this).is(':checked')) {
                        $('.email-field-settings').show();
                    } else {
                        $('.email-field-settings').hide();
                    }
                });

                // Toggle admin email settings visibility
                $('#sfaic_admin_email_enabled').change(function () {
                    if ($(this).is(':checked')) {
                        $('.admin-email-settings').show();
                    } else {
                        $('.admin-email-settings').hide();
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Enhanced save method for all settings including background processing settings
     */
    public function save_post_meta($post_id) {
        // Check nonce and permissions
        if (!isset($_POST['sfaic_prompt_nonce']) ||
                !wp_verify_nonce($_POST['sfaic_prompt_nonce'], 'sfaic_prompt_meta_save') ||
                !current_user_can('edit_post', $post_id) ||
                (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        // Save basic prompt settings
        if (isset($_POST['sfaic_system_prompt'])) {
            update_post_meta($post_id, '_sfaic_system_prompt', $_POST['sfaic_system_prompt']);
        }

        if (isset($_POST['sfaic_user_prompt_template'])) {
            update_post_meta($post_id, '_sfaic_user_prompt_template', sanitize_textarea_field($_POST['sfaic_user_prompt_template']));
        }

        if (isset($_POST['sfaic_temperature'])) {
            update_post_meta($post_id, '_sfaic_temperature', floatval($_POST['sfaic_temperature']));
        }

        if (isset($_POST['sfaic_prompt_type'])) {
            update_post_meta($post_id, '_sfaic_prompt_type', sanitize_text_field($_POST['sfaic_prompt_type']));
        }

        // Save enhanced response length and chunking settings
        if (isset($_POST['sfaic_max_tokens'])) {
            $max_tokens = intval($_POST['sfaic_max_tokens']);
            $max_tokens = min($max_tokens, 200000); // Absolute maximum
            update_post_meta($post_id, '_sfaic_max_tokens', $max_tokens);
        }

        // Save chunking enable/disable
        $enable_chunking = isset($_POST['sfaic_enable_chunking']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_enable_chunking', $enable_chunking);

        // Save chunking strategy
        if (isset($_POST['sfaic_chunking_strategy'])) {
            update_post_meta($post_id, '_sfaic_chunking_strategy', sanitize_text_field($_POST['sfaic_chunking_strategy']));
        }

        // Save completion marker
        if (isset($_POST['sfaic_completion_marker'])) {
            update_post_meta($post_id, '_sfaic_completion_marker', sanitize_text_field($_POST['sfaic_completion_marker']));
        }

        // Save smart completion settings
        $enable_smart_completion = isset($_POST['sfaic_enable_smart_completion']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_enable_smart_completion', $enable_smart_completion);

        if (isset($_POST['sfaic_min_content_length'])) {
            $min_content = intval($_POST['sfaic_min_content_length']);
            if ($min_content >= 100 && $min_content <= 600000) {
                update_post_meta($post_id, '_sfaic_min_content_length', $min_content);
            }
        }

        if (isset($_POST['sfaic_completion_word_count'])) {
            $word_count = intval($_POST['sfaic_completion_word_count']);
            if ($word_count >= 200 && $word_count <= 25000) {
                update_post_meta($post_id, '_sfaic_completion_word_count', $word_count);
            }
        }

        if (isset($_POST['sfaic_completion_keywords'])) {
            update_post_meta($post_id, '_sfaic_completion_keywords', sanitize_text_field($_POST['sfaic_completion_keywords']));
        }

        // Save token-based completion settings
        $use_token_percentage = isset($_POST['sfaic_use_token_percentage']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_use_token_percentage', $use_token_percentage);

        if (isset($_POST['sfaic_token_completion_threshold'])) {
            $threshold = intval($_POST['sfaic_token_completion_threshold']);
            if ($threshold >= 50 && $threshold <= 95) {
                update_post_meta($post_id, '_sfaic_token_completion_threshold', $threshold);
            }
        }

        // NEW: Save background processing settings
        $enable_background_processing = isset($_POST['sfaic_enable_background_processing']) && $_POST['sfaic_enable_background_processing'] === '1' ? '1' : '0';
        update_post_meta($post_id, '_sfaic_enable_background_processing', $enable_background_processing);

        if (isset($_POST['sfaic_background_processing_delay'])) {
            $delay = intval($_POST['sfaic_background_processing_delay']);
            if ($delay >= 0 && $delay <= 300) {
                update_post_meta($post_id, '_sfaic_background_processing_delay', $delay);
            }
        }

        if (isset($_POST['sfaic_job_priority'])) {
            $priority = intval($_POST['sfaic_job_priority']);
            if ($priority >= -1 && $priority <= 2) {
                update_post_meta($post_id, '_sfaic_job_priority', $priority);
            }
        }

        if (isset($_POST['sfaic_job_timeout'])) {
            $timeout = intval($_POST['sfaic_job_timeout']);
            if ($timeout >= 30 && $timeout <= 900) {
                update_post_meta($post_id, '_sfaic_job_timeout', $timeout);
            }
        }

        // Save field mappings
        if (isset($_POST['sfaic_first_name_field'])) {
            update_post_meta($post_id, '_sfaic_first_name_field', sanitize_text_field($_POST['sfaic_first_name_field']));
        }

        if (isset($_POST['sfaic_last_name_field'])) {
            update_post_meta($post_id, '_sfaic_last_name_field', sanitize_text_field($_POST['sfaic_last_name_field']));
        }

        if (isset($_POST['sfaic_email_field_mapping'])) {
            update_post_meta($post_id, '_sfaic_email_field_mapping', sanitize_text_field($_POST['sfaic_email_field_mapping']));
        }

        // Save form selection
        if (isset($_POST['sfaic_fluent_form_id'])) {
            update_post_meta($post_id, '_sfaic_fluent_form_id', sanitize_text_field($_POST['sfaic_fluent_form_id']));
        }

        // Save response handling settings
        if (isset($_POST['sfaic_response_action'])) {
            update_post_meta($post_id, '_sfaic_response_action', sanitize_text_field($_POST['sfaic_response_action']));
        }

        if (isset($_POST['sfaic_email_to'])) {
            update_post_meta($post_id, '_sfaic_email_to', sanitize_text_field($_POST['sfaic_email_to']));
        }

        if (isset($_POST['sfaic_email_subject'])) {
            update_post_meta($post_id, '_sfaic_email_subject', sanitize_text_field($_POST['sfaic_email_subject']));
        }

        // Save enhanced email content template
        if (isset($_POST['sfaic_email_content_template'])) {
            $allowed_html = wp_kses_allowed_html('post');
            $email_content = wp_kses($_POST['sfaic_email_content_template'], $allowed_html);
            update_post_meta($post_id, '_sfaic_email_content_template', $email_content);
        }

        // Save email settings
        $email_include_form_data = isset($_POST['sfaic_email_include_form_data']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_email_include_form_data', $email_include_form_data);

        $admin_email_enabled = isset($_POST['sfaic_admin_email_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_admin_email_enabled', $admin_email_enabled);

        if (isset($_POST['sfaic_admin_email_to'])) {
            update_post_meta($post_id, '_sfaic_admin_email_to', sanitize_text_field($_POST['sfaic_admin_email_to']));
        }

        if (isset($_POST['sfaic_admin_email_subject'])) {
            update_post_meta($post_id, '_sfaic_admin_email_subject', sanitize_text_field($_POST['sfaic_admin_email_subject']));
        }

        $log_responses = isset($_POST['sfaic_log_responses']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_log_responses', $log_responses);

        $email_to_user = isset($_POST['sfaic_email_to_user']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_email_to_user', $email_to_user);

        if (isset($_POST['sfaic_email_field'])) {
            update_post_meta($post_id, '_sfaic_email_field', sanitize_text_field($_POST['sfaic_email_field']));
        }
    }

    /**
     * Enhanced method to get chunking settings for a prompt (used by API classes)
     */
    public function get_chunking_settings($prompt_id) {
        return array(
            'completion_marker' => get_post_meta($prompt_id, '_sfaic_completion_marker', true) ?: '<!-- REPORT_END -->',
            'min_content_length' => intval(get_post_meta($prompt_id, '_sfaic_min_content_length', true)) ?: 500,
            'completion_word_count' => intval(get_post_meta($prompt_id, '_sfaic_completion_word_count', true)) ?: 800,
            'completion_keywords' => get_post_meta($prompt_id, '_sfaic_completion_keywords', true) ?: 'conclusion, summary, final, recommendations, regards, sincerely',
            'enable_smart_completion' => get_post_meta($prompt_id, '_sfaic_enable_smart_completion', true) === '1',
            'chunking_strategy' => get_post_meta($prompt_id, '_sfaic_chunking_strategy', true) ?: 'balanced',
            'use_token_percentage' => get_post_meta($prompt_id, '_sfaic_use_token_percentage', true) === '1',
            'token_completion_threshold' => intval(get_post_meta($prompt_id, '_sfaic_token_completion_threshold', true)) ?: 70
        );
    }

    /**
     * Get form fields from a form ID
     * 
     * @param int $form_id The form ID
     * @return array Associative array of field keys and labels
     */
    private function get_form_fields($form_id) {
        $field_labels = array();

        if (empty($form_id) || !function_exists('wpFluent')) {
            return $field_labels;
        }

        // Method 1: Try to get fields from recent submissions first (most reliable)
        $recent_submission = wpFluent()->table('fluentform_submissions')
                ->where('form_id', $form_id)
                ->orderBy('id', 'DESC')
                ->first();

        if ($recent_submission && !empty($recent_submission->response)) {
            $submission_data = json_decode($recent_submission->response, true);
            if (!empty($submission_data)) {
                // Get field labels from form structure and match with submission data
                $form_structure_fields = $this->get_form_structure_fields($form_id);

                foreach ($submission_data as $field_key => $field_value) {
                    // Skip internal fields
                    if (strpos($field_key, '_') === 0) {
                        continue;
                    }

                    // Use form structure label if available, otherwise use field key
                    $field_label = isset($form_structure_fields[$field_key]) ?
                            $form_structure_fields[$field_key] :
                            ucwords(str_replace('_', ' ', $field_key));

                    $field_labels[$field_key] = $field_label;
                }

                // If we got fields from submissions, return them
                if (!empty($field_labels)) {
                    return $field_labels;
                }
            }
        }

        // Method 2: Fall back to form structure parsing
        return $this->get_form_structure_fields($form_id);
    }

    /**
     * Get fields from form structure with multiple fallback methods
     */
    private function get_form_structure_fields($form_id) {
        $field_labels = array();

        if (empty($form_id) || !function_exists('wpFluent')) {
            return $field_labels;
        }

        // Try multiple methods to get field labels
        // Method 1: Try formDatenation first (older versions)
        $formFields = wpFluent()->table('fluentform_form_meta')
                ->where('form_id', $form_id)
                ->where('meta_key', 'formDatenation')
                ->first();

        if ($formFields && !empty($formFields->value)) {
            $fields = json_decode($formFields->value, true);
            if (!empty($fields['fields'])) {
                foreach ($fields['fields'] as $field) {
                    if (!empty($field['element']) && !empty($field['attributes']['name'])) {
                        $field_name = $field['attributes']['name'];
                        $field_label = !empty($field['settings']['label']) ? $field['settings']['label'] : $field_name;
                        $field_labels[$field_name] = $field_label;
                    }
                }
                if (!empty($field_labels)) {
                    return $field_labels;
                }
            }
        }

        // Method 2: Try form_fields_meta (newer versions)
        $formFields = wpFluent()->table('fluentform_form_meta')
                ->where('form_id', $form_id)
                ->where('meta_key', 'form_fields_meta')
                ->first();

        if ($formFields && !empty($formFields->value)) {
            $fields = json_decode($formFields->value, true);
            if (!empty($fields)) {
                foreach ($fields as $field) {
                    if (!empty($field['element']) && !empty($field['attributes']['name'])) {
                        $field_name = $field['attributes']['name'];
                        $field_label = !empty($field['settings']['label']) ? $field['settings']['label'] : $field_name;
                        $field_labels[$field_name] = $field_label;
                    }
                }
                if (!empty($field_labels)) {
                    return $field_labels;
                }
            }
        }

        // Method 3: Try direct form structure (fallback)
        $form = wpFluent()->table('fluentform_forms')
                ->where('id', $form_id)
                ->first();

        if ($form && !empty($form->form_fields)) {
            $formFields = json_decode($form->form_fields, true);

            if (!empty($formFields['fields'])) {
                foreach ($formFields['fields'] as $field) {
                    if (!empty($field['element']) && !empty($field['attributes']['name'])) {
                        $field_name = $field['attributes']['name'];
                        $field_label = !empty($field['settings']['label']) ? $field['settings']['label'] : $field_name;
                        $field_labels[$field_name] = $field_label;
                    }
                }
                if (!empty($field_labels)) {
                    return $field_labels;
                }
            }
        }

        // Method 4: Use Fluent Forms API if available (most reliable)
        if (class_exists('\FluentForm\App\Api\FormFields')) {
            try {
                $formFieldsAPI = new \FluentForm\App\Api\FormFields();
                $formFields = $formFieldsAPI->getFormInputs($form_id);
                if (!empty($formFields)) {
                    foreach ($formFields as $fieldName => $fieldDetails) {
                        // Get a better label if available
                        $label = $fieldName;
                        if (isset($fieldDetails['label']) && !empty($fieldDetails['label'])) {
                            $label = $fieldDetails['label'];
                        } elseif (isset($fieldDetails['element']) && !empty($fieldDetails['element'])) {
                            $label = $fieldDetails['element'];
                        }
                        $field_labels[$fieldName] = $label;
                    }
                    if (!empty($field_labels)) {
                        return $field_labels;
                    }
                }
            } catch (\Exception $e) {
                // Silently fail and continue to next method
                error_log('SFAIC: Fluent Forms API error: ' . $e->getMessage());
            }
        }

        return $field_labels;
    }

    /**
     * Get form email fields from a form ID
     * 
     * @param int $form_id The form ID
     * @return array Associative array of field keys and labels for email fields
     */
    private function get_form_email_fields($form_id) {
        $email_fields = array();

        if (empty($form_id) || !function_exists('wpFluent')) {
            return $email_fields;
        }

        // Get all form fields
        $all_fields = $this->get_form_fields($form_id);

        // Common email field names
        $common_email_fields = array('email', 'your_email', 'user_email', 'email_address', 'customer_email');

        // Filter fields that might be email fields based on name or type
        foreach ($all_fields as $field_key => $field_label) {
            // Check if field name contains 'email'
            if (stripos($field_key, 'email') !== false ||
                    stripos($field_label, 'email') !== false ||
                    in_array(strtolower($field_key), $common_email_fields)) {
                $email_fields[$field_key] = $field_label;
            }
        }

        // If no email fields found directly, try to look in the form structure for email input types
        if (empty($email_fields)) {
            // Get form structure
            $form = wpFluent()->table('fluentform_forms')
                    ->where('id', $form_id)
                    ->first();

            if ($form && !empty($form->form_fields)) {
                $formFields = json_decode($form->form_fields, true);

                if (!empty($formFields['fields'])) {
                    foreach ($formFields['fields'] as $field) {
                        if (
                        // Check for email input types
                                (!empty($field['attributes']['type']) && $field['attributes']['type'] === 'email') ||
                                // Check for specific element types that are emails
                                (!empty($field['element']) && $field['element'] === 'input_email')
                        ) {
                            if (!empty($field['attributes']['name'])) {
                                $field_name = $field['attributes']['name'];
                                $field_label = !empty($field['settings']['label']) ? $field['settings']['label'] : $field_name;
                                $email_fields[$field_name] = $field_label;
                            }
                        }
                    }
                }
            }
        }

        return $email_fields;
    }

    /**
     * Helper method to format all form data for AI processing
     */
    private function format_all_form_data($form_data, $prompt_id) {
        $formatted = "Form Submission Data:\n\n";

        foreach ($form_data as $field_key => $field_value) {
            if (!is_scalar($field_key)) {
                continue;
            }

            // Handle array values (like checkboxes)
            if (is_array($field_value)) {
                $field_value = implode(', ', $field_value);
            } elseif (!is_scalar($field_value)) {
                continue;
            }

            $formatted .= "{$field_key}: {$field_value}\n";
        }

        return $formatted;
    }

    /**
     * Helper method to clean HTML from AI responses
     */
    private function clean_html_response($response) {
        // Remove potentially harmful HTML tags while preserving basic formatting
        $allowed_tags = '<p><br><strong><b><em><i><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre>';
        return strip_tags($response, $allowed_tags);
    }
}