<?php
/**
 * ChatGPT Prompt Custom Post Type - Updated with Dynamic Chunking Settings
 * 
 * Handles registration and management of the ChatGPT Prompt custom post type
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

        // Add new chunking settings meta box
        add_meta_box(
                'sfaic_chunking_settings',
                __('Advanced Chunking Settings', 'chatgpt-fluent-connector'),
                array($this, 'render_chunking_settings_meta_box'),
                'sfaic_prompt',
                'side',
                'default'
        );
    }

    /**
     * Render prompt settings meta box
     */
    public function render_prompt_settings_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('sfaic_prompt_meta_save', 'sfaic_prompt_nonce');

        // Get saved values
        $system_prompt = get_post_meta($post->ID, '_sfaic_system_prompt', true);
        $user_prompt_template = get_post_meta($post->ID, '_sfaic_user_prompt_template', true);
        $temperature = get_post_meta($post->ID, '_sfaic_temperature', true);
        $prompt_type = get_post_meta($post->ID, '_sfaic_prompt_type', true);

        if (empty($prompt_type)) {
            $prompt_type = 'template'; // Default to template
        }

        if (empty($temperature)) {
            $temperature = 0.7;
        }
        $max_tokens = get_post_meta($post->ID, '_sfaic_max_tokens', true);
        if (empty($max_tokens)) {
            $max_tokens = 500;
        }
        ?>
        <table class="form-table">
            <tr>
                <th><label for="sfaic_system_prompt"><?php _e('System Prompt:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <textarea name="sfaic_system_prompt" id="sfaic_system_prompt" class="large-text code" rows="20"><?php echo esc_textarea($system_prompt); ?></textarea>
                    <p class="description"><?php _e('Instructions that define how ChatGPT should behave (e.g., "You are a helpful assistant that specializes in...")', 'chatgpt-fluent-connector'); ?></p>
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
                    <p class="description"><?php _e('If selected, all form field labels and values will be sent to ChatGPT in a structured format.', 'chatgpt-fluent-connector'); ?></p>
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
            <tr>
                <th><label for="sfaic_max_tokens"><?php _e('Max Tokens:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="number" 
                           min="50" 
                           max="200000" 
                           step="50" 
                           name="sfaic_max_tokens" 
                           id="sfaic_max_tokens" 
                           value="<?php echo esc_attr($max_tokens); ?>" 
                           class="small-text">
                    <p class="description">
                        <?php _e('Maximum tokens for the complete response when chunking is enabled.', 'chatgpt-fluent-connector'); ?><br>
                        <strong><?php _e('With chunking:', 'chatgpt-fluent-connector'); ?></strong> 
                        <?php _e('Can generate up to 128,000 tokens (≈500k characters)', 'chatgpt-fluent-connector'); ?><br>
                        <strong><?php _e('Without chunking:', 'chatgpt-fluent-connector'); ?></strong> 
                        <?php _e('Limited by model: GPT-3.5 (4k), GPT-4 (8k), GPT-4 Turbo (4k output)', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>
            <?php $enable_chunking = get_post_meta($post->ID, '_sfaic_enable_chunking', true); ?>
            <tr>
                <th><label for="sfaic_enable_chunking"><?php _e('Enable Response Chunking:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sfaic_enable_chunking" id="sfaic_enable_chunking" value="1" <?php checked(get_post_meta($post->ID, '_sfaic_enable_chunking', true), '1'); ?>>
                        <?php _e('Enable chunking for responses longer than 4096 tokens', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description">
                        <?php _e('When enabled, the plugin will make multiple API calls to generate longer responses. This may increase API costs.', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>
            <tr id="chunking-settings-row" <?php echo ($enable_chunking != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_chunking_strategy"><?php _e('Chunking Strategy:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <?php $chunking_strategy = get_post_meta($post->ID, '_sfaic_chunking_strategy', true); ?>
                    <select name="sfaic_chunking_strategy" id="sfaic_chunking_strategy">
                        <option value="balanced" <?php selected($chunking_strategy, 'balanced'); ?>><?php _e('Balanced (Recommended)', 'chatgpt-fluent-connector'); ?></option>
                        <option value="aggressive" <?php selected($chunking_strategy, 'aggressive'); ?>><?php _e('Aggressive (Maximum Length)', 'chatgpt-fluent-connector'); ?></option>
                        <option value="conservative" <?php selected($chunking_strategy, 'conservative'); ?>><?php _e('Conservative (Safe & Fast)', 'chatgpt-fluent-connector'); ?></option>
                    </select>
                    <p class="description">
                        <?php _e('Balanced: Good for most use cases. Aggressive: Maximizes response length but may take longer. Conservative: Faster processing, shorter responses.', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>

            <tr id="chunking-quality-row" <?php echo ($enable_chunking != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_require_completion"><?php _e('Completion Requirements:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <?php $require_completion = get_post_meta($post->ID, '_sfaic_require_completion', true); ?>
                    <label>
                        <input type="checkbox" name="sfaic_require_completion" id="sfaic_require_completion" value="1" <?php checked($require_completion, '1'); ?>>
                        <?php _e('Require natural completion (may use more tokens)', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('When enabled, the system will try harder to generate complete responses, even if it means using more tokens.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <script>
                jQuery(document).ready(function ($) {
                    // Show/hide chunking settings
                    $('#sfaic_enable_chunking').change(function () {
                        if ($(this).is(':checked')) {
                            $('#chunking-settings-row, #chunking-quality-row').show();
                        } else {
                            $('#chunking-settings-row, #chunking-quality-row').hide();
                        }
                    });
                });
            </script>

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
     * NEW: Render chunking settings meta box
     */
    /**
 * NEW: Render chunking settings meta box - FIXED VERSION
 */
public function render_chunking_settings_meta_box($post) {
    // Get saved values with defaults
    $completion_marker = get_post_meta($post->ID, '_sfaic_completion_marker', true);
    if (empty($completion_marker)) {
        $completion_marker = '<!-- REPORT_END -->';
    }

    $min_content_length = get_post_meta($post->ID, '_sfaic_min_content_length', true);
    if (empty($min_content_length)) {
        $min_content_length = 500;
    }

    $completion_word_count = get_post_meta($post->ID, '_sfaic_completion_word_count', true);
    if (empty($completion_word_count)) {
        $completion_word_count = 800;
    }

    $min_chunk_words = get_post_meta($post->ID, '_sfaic_min_chunk_words', true);
    if (empty($min_chunk_words)) {
        $min_chunk_words = 300;
    }

    $completion_keywords = get_post_meta($post->ID, '_sfaic_completion_keywords', true);
    if (empty($completion_keywords)) {
        $completion_keywords = 'conclusion, summary, final, recommendations, regards, sincerely';
    }

    $enable_smart_completion = get_post_meta($post->ID, '_sfaic_enable_smart_completion', true);
    $use_token_percentage = get_post_meta($post->ID, '_sfaic_use_token_percentage', true);
    
    $token_completion_threshold = get_post_meta($post->ID, '_sfaic_token_completion_threshold', true);
    if (empty($token_completion_threshold)) {
        $token_completion_threshold = 70;
    }

    // DEBUG: Log current values
    error_log('SFAIC: Loading chunking settings for post ' . $post->ID);
    error_log('SFAIC: completion_marker = ' . $completion_marker);
    error_log('SFAIC: enable_smart_completion = ' . $enable_smart_completion);
    error_log('SFAIC: min_content_length = ' . $min_content_length);
    ?>
    <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
        <h4 style="margin-top: 0; color: #0073aa;">🎯 Smart Completion Controls</h4>
        <p style="font-size: 13px; color: #666; margin-bottom: 15px;">
            Configure how the AI determines when a response is complete during chunking.
        </p>

        <table class="form-table" style="margin-top: 0;">
            <tr>
                <th style="width: 180px;"><label for="sfaic_completion_marker"><?php _e('Completion Marker:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="text" 
                           name="sfaic_completion_marker" 
                           id="sfaic_completion_marker" 
                           value="<?php echo esc_attr($completion_marker); ?>" 
                           class="regular-text" 
                           placeholder="<!-- REPORT_END -->">
                    <p class="description">
                        <?php _e('HTML comment or text marker that signals completion. The AI will stop chunking when this marker is found.', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th><label for="sfaic_enable_smart_completion"><?php _e('Smart Completion:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="sfaic_enable_smart_completion" 
                               id="sfaic_enable_smart_completion" 
                               value="1" 
                               <?php checked($enable_smart_completion, '1'); ?>>
                        <?php _e('Enable intelligent completion detection', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('When enabled, the system will analyze content patterns to detect natural completion points.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="smart-completion-settings" <?php echo ($enable_smart_completion != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_min_content_length"><?php _e('Min Content Length:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="number" 
                           name="sfaic_min_content_length" 
                           id="sfaic_min_content_length" 
                           value="<?php echo esc_attr($min_content_length); ?>" 
                           min="100" 
                           max="50000" 
                           step="50" 
                           class="small-text">
                    <span><?php _e('characters', 'chatgpt-fluent-connector'); ?></span>
                    <p class="description"><?php _e('Minimum content length before checking for completion patterns.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="smart-completion-settings" <?php echo ($enable_smart_completion != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_completion_word_count"><?php _e('Completion Word Count:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="number" 
                           name="sfaic_completion_word_count" 
                           id="sfaic_completion_word_count" 
                           value="<?php echo esc_attr($completion_word_count); ?>" 
                           min="200" 
                           max="20000" 
                           step="50" 
                           class="small-text">
                    <span><?php _e('words', 'chatgpt-fluent-connector'); ?></span>
                    <p class="description"><?php _e('Word count threshold for natural completion detection.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="smart-completion-settings" <?php echo ($enable_smart_completion != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_min_chunk_words"><?php _e('Min Chunk Words:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="number" 
                           name="sfaic_min_chunk_words" 
                           id="sfaic_min_chunk_words" 
                           value="<?php echo esc_attr($min_chunk_words); ?>" 
                           min="100" 
                           max="5000" 
                           step="25" 
                           class="small-text">
                    <span><?php _e('words', 'chatgpt-fluent-connector'); ?></span>
                    <p class="description"><?php _e('Minimum words required in each chunk before completion analysis.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="smart-completion-settings" <?php echo ($enable_smart_completion != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_completion_keywords"><?php _e('Completion Keywords:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <textarea name="sfaic_completion_keywords" 
                              id="sfaic_completion_keywords" 
                              rows="3" 
                              class="regular-text"
                              placeholder="conclusion, summary, final, recommendations"><?php echo esc_textarea($completion_keywords); ?></textarea>
                    <p class="description">
                        <?php _e('Comma-separated keywords that indicate completion. Used for natural ending detection.', 'chatgpt-fluent-connector'); ?>
                        <br><strong><?php _e('Examples:', 'chatgpt-fluent-connector'); ?></strong> 
                        <?php _e('conclusion, summary, final thoughts, recommendations, regards, best wishes, thank you', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>

            <tr class="smart-completion-settings" <?php echo ($enable_smart_completion != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_use_token_percentage"><?php _e('Token-Based Completion:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="sfaic_use_token_percentage" 
                               id="sfaic_use_token_percentage" 
                               value="1" 
                               <?php checked($use_token_percentage, '1'); ?>>
                        <?php _e('Use token percentage for completion timing', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('When enabled, the system will consider token usage percentage when deciding completion.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="token-completion-settings" <?php echo ($enable_smart_completion != '1' || $use_token_percentage != '1') ? 'style="display:none;"' : ''; ?>>
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
                    <p class="description"><?php _e('Percentage of target tokens used before triggering completion analysis.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>
        </table>

        <div style="background: #e8f5e8; padding: 10px; border-radius: 3px; margin-top: 15px;">
            <h5 style="margin: 0 0 5px 0; color: #155724;">💡 <?php _e('Pro Tips:', 'chatgpt-fluent-connector'); ?></h5>
            <ul style="margin: 5px 0 0 20px; font-size: 12px; color: #155724;">
                <li><?php _e('Lower word counts = faster completion, higher word counts = more thorough responses', 'chatgpt-fluent-connector'); ?></li>
                <li><?php _e('Add domain-specific completion keywords for better detection (e.g., "diagnosis, treatment" for medical)', 'chatgpt-fluent-connector'); ?></li>
                <li><?php _e('Token percentage completion helps balance thoroughness with cost control', 'chatgpt-fluent-connector'); ?></li>
            </ul>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Show/hide smart completion settings
        $('#sfaic_enable_smart_completion').change(function() {
            if ($(this).is(':checked')) {
                $('.smart-completion-settings').show();
                // Also check token percentage settings
                if ($('#sfaic_use_token_percentage').is(':checked')) {
                    $('.token-completion-settings').show();
                }
            } else {
                $('.smart-completion-settings, .token-completion-settings').hide();
            }
        });

        // Show/hide token completion settings
        $('#sfaic_use_token_percentage').change(function() {
            if ($(this).is(':checked') && $('#sfaic_enable_smart_completion').is(':checked')) {
                $('.token-completion-settings').show();
            } else {
                $('.token-completion-settings').hide();
            }
        });

        // Token threshold slider update
        document.getElementById('sfaic_token_completion_threshold').addEventListener('input', function() {
            document.getElementById('token_threshold_value').innerHTML = this.value + '%';
        });

        // DEBUG: Log form values on change
        $('input, textarea', '.form-table').change(function() {
            console.log('SFAIC: Field changed - ' + this.name + ' = ' + this.value);
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
                // Try multiple field storage methods in Fluent Forms
                $fields_found = false;

                // Method 1: Try formDatenation first (older versions)
                if (function_exists('wpFluent')) {
                    $formFields = wpFluent()->table('fluentform_form_meta')
                            ->where('form_id', $selected_form_id)
                            ->where('meta_key', 'formDatenation')
                            ->first();

                    if ($formFields && !empty($formFields->value)) {
                        $fields = json_decode($formFields->value, true);
                        if (!empty($fields['fields'])) {
                            echo '<ul style="margin-top: 0;">';
                            foreach ($fields['fields'] as $field) {
                                if (!empty($field['element']) && !empty($field['attributes']['name'])) {
                                    echo '<li><code>{' . esc_html($field['attributes']['name']) . '}</code> - ' . esc_html($field['element']) . '</li>';
                                }
                            }
                            echo '</ul>';
                            $fields_found = true;
                        }
                    }
                }

                // Method 2: Try form_fields_meta (newer versions)
                if (!$fields_found && function_exists('wpFluent')) {
                    $formFields = wpFluent()->table('fluentform_form_meta')
                            ->where('form_id', $selected_form_id)
                            ->where('meta_key', 'form_fields_meta')
                            ->first();

                    if ($formFields && !empty($formFields->value)) {
                        $fields = json_decode($formFields->value, true);
                        if (!empty($fields)) {
                            echo '<ul style="margin-top: 0;">';
                            foreach ($fields as $field) {
                                if (!empty($field['element']) && !empty($field['attributes']['name'])) {
                                    echo '<li><code>{' . esc_html($field['attributes']['name']) . '}</code> - ' . esc_html($field['element']) . '</li>';
                                }
                            }
                            echo '</ul>';
                            $fields_found = true;
                        }
                    }
                }

                // Method 3: Try direct form structure (fallback)
                if (!$fields_found && function_exists('wpFluent')) {
                    $form = wpFluent()->table('fluentform_forms')
                            ->where('id', $selected_form_id)
                            ->first();

                    if ($form && !empty($form->form_fields)) {
                        $formFields = json_decode($form->form_fields, true);

                        if (!empty($formFields['fields'])) {
                            echo '<ul style="margin-top: 0;">';
                            foreach ($formFields['fields'] as $field) {
                                if (!empty($field['element']) && !empty($field['attributes']['name'])) {
                                    echo '<li><code>{' . esc_html($field['attributes']['name']) . '}</code> - ' . esc_html($field['element']) . '</li>';
                                }
                            }
                            echo '</ul>';
                            $fields_found = true;
                        }
                    }
                }

                // Method 4: Use Fluent Forms API if available (most reliable)
                if (!$fields_found && class_exists('\FluentForm\App\Api\FormFields')) {
                    try {
                        $formFields = (new \FluentForm\App\Api\FormFields())->getFormInputs($selected_form_id);
                        if (!empty($formFields)) {
                            echo '<ul style="margin-top: 0;">';
                            foreach ($formFields as $fieldName => $fieldDetails) {
                                echo '<li><code>{' . esc_html($fieldName) . '}</code> - ' . esc_html($fieldDetails['element']) . '</li>';
                            }
                            echo '</ul>';
                            $fields_found = true;
                        }
                    } catch (\Exception $e) {
                        // Silently fail, we'll show the default message below
                    }
                }

                // If no fields found with any method
                if (!$fields_found) {
                    echo '<div class="notice notice-info inline"><p>' . esc_html__('To see available form fields, please edit and save the selected form in Fluent Forms first.', 'chatgpt-fluent-connector') . '</p>';
                    echo '<p>' . esc_html__('Alternatively, you can manually determine field keys by checking the form structure in Fluent Forms.', 'chatgpt-fluent-connector') . '</p></div>';

                    // Add link to edit the form
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

        // New fields for email customization
        $email_content_template = get_post_meta($post->ID, '_sfaic_email_content_template', true);
        $email_include_form_data = get_post_meta($post->ID, '_sfaic_email_include_form_data', true);
        $admin_email_enabled = get_post_meta($post->ID, '_sfaic_admin_email_enabled', true);
        $admin_email_to = get_post_meta($post->ID, '_sfaic_admin_email_to', true);
        $admin_email_subject = get_post_meta($post->ID, '_sfaic_admin_email_subject', true);

        // Get form ID to fetch available email fields
        $form_id = get_post_meta($post->ID, '_sfaic_fluent_form_id', true);

        // Get available email fields from the form
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

            <!-- New Email Content Template Field -->
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
     * Save post meta - UPDATED with new chunking settings
     */

    /**
     * Save post meta - UPDATED with ALL chunking settings
     */
    public function save_post_meta($post_id) {
        // Check if our nonce is set
        if (!isset($_POST['sfaic_prompt_nonce'])) {
            return;
        }

        // Verify the nonce
        if (!wp_verify_nonce($_POST['sfaic_prompt_nonce'], 'sfaic_prompt_meta_save')) {
            return;
        }

        // If this is an autosave, our form has not been submitted, so we don't want to do anything
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check the user's permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save the prompt settings
        if (isset($_POST['sfaic_system_prompt'])) {
            update_post_meta($post_id, '_sfaic_system_prompt', $_POST['sfaic_system_prompt']);
        }

        if (isset($_POST['sfaic_user_prompt_template'])) {
            update_post_meta($post_id, '_sfaic_user_prompt_template', sanitize_textarea_field($_POST['sfaic_user_prompt_template']));
        }

        if (isset($_POST['sfaic_temperature'])) {
            update_post_meta($post_id, '_sfaic_temperature', floatval($_POST['sfaic_temperature']));
        }

        // Fix how max_tokens is saved
        if (isset($_POST['sfaic_max_tokens'])) {
            $max_tokens = intval($_POST['sfaic_max_tokens']);
            // Set an absolute upper limit as a failsafe
            if ($max_tokens > 200000) {
                $max_tokens = 200000;
            }
            update_post_meta($post_id, '_sfaic_max_tokens', $max_tokens);
        }

        // Save form selection
        if (isset($_POST['sfaic_fluent_form_id'])) {
            update_post_meta($post_id, '_sfaic_fluent_form_id', sanitize_text_field($_POST['sfaic_fluent_form_id']));
        }

        // Save response handling
        if (isset($_POST['sfaic_response_action'])) {
            update_post_meta($post_id, '_sfaic_response_action', sanitize_text_field($_POST['sfaic_response_action']));
        }

        if (isset($_POST['sfaic_email_to'])) {
            update_post_meta($post_id, '_sfaic_email_to', sanitize_text_field($_POST['sfaic_email_to']));
        }

        if (isset($_POST['sfaic_email_subject'])) {
            update_post_meta($post_id, '_sfaic_email_subject', sanitize_text_field($_POST['sfaic_email_subject']));
        }

        // Save email content template
        if (isset($_POST['sfaic_email_content_template'])) {
            // Allow HTML content
            $allowed_html = wp_kses_allowed_html('post');
            $email_content = wp_kses($_POST['sfaic_email_content_template'], $allowed_html);
            update_post_meta($post_id, '_sfaic_email_content_template', $email_content);
        }

        // Save email include form data option
        $email_include_form_data = isset($_POST['sfaic_email_include_form_data']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_email_include_form_data', $email_include_form_data);

        // Save admin email settings
        $admin_email_enabled = isset($_POST['sfaic_admin_email_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_admin_email_enabled', $admin_email_enabled);

        if (isset($_POST['sfaic_admin_email_to'])) {
            update_post_meta($post_id, '_sfaic_admin_email_to', sanitize_text_field($_POST['sfaic_admin_email_to']));
        }

        if (isset($_POST['sfaic_admin_email_subject'])) {
            update_post_meta($post_id, '_sfaic_admin_email_subject', sanitize_text_field($_POST['sfaic_admin_email_subject']));
        }

        // Save the log responses option
        $log_responses = isset($_POST['sfaic_log_responses']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_log_responses', $log_responses);

        // Save the "Email to user" option
        $email_to_user = isset($_POST['sfaic_email_to_user']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_email_to_user', $email_to_user);

        // Save the selected email field
        if (isset($_POST['sfaic_email_field'])) {
            update_post_meta($post_id, '_sfaic_email_field', sanitize_text_field($_POST['sfaic_email_field']));
        }

        // Save prompt type
        if (isset($_POST['sfaic_prompt_type'])) {
            update_post_meta($post_id, '_sfaic_prompt_type', sanitize_text_field($_POST['sfaic_prompt_type']));
        }

        // Save the enable chunking option
        $enable_chunking = isset($_POST['sfaic_enable_chunking']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_enable_chunking', $enable_chunking);

        if (isset($_POST['sfaic_chunking_strategy'])) {
            update_post_meta($post_id, '_sfaic_chunking_strategy', sanitize_text_field($_POST['sfaic_chunking_strategy']));
        }

        $require_completion = isset($_POST['sfaic_require_completion']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_require_completion', $require_completion);

        // ==== FIXED: Save ALL dynamic chunking settings ====
        // Completion Marker
        if (isset($_POST['sfaic_completion_marker'])) {
            update_post_meta($post_id, '_sfaic_completion_marker', sanitize_text_field($_POST['sfaic_completion_marker']));
        }

        // Smart Completion checkbox
        $enable_smart_completion = isset($_POST['sfaic_enable_smart_completion']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_enable_smart_completion', $enable_smart_completion);

        // Min Content Length
        if (isset($_POST['sfaic_min_content_length'])) {
            $min_content = intval($_POST['sfaic_min_content_length']);
            if ($min_content >= 100 && $min_content <= 30000) {
                update_post_meta($post_id, '_sfaic_min_content_length', $min_content);
            }
        }

        // Completion Word Count
        if (isset($_POST['sfaic_completion_word_count'])) {
            $word_count = intval($_POST['sfaic_completion_word_count']);
            if ($word_count >= 200 && $word_count <= 5000) {
                update_post_meta($post_id, '_sfaic_completion_word_count', $word_count);
            }
        }

        // Min Chunk Words
        if (isset($_POST['sfaic_min_chunk_words'])) {
            $chunk_words = intval($_POST['sfaic_min_chunk_words']);
            if ($chunk_words >= 100 && $chunk_words <= 3000) {
                update_post_meta($post_id, '_sfaic_min_chunk_words', $chunk_words);
            }
        }

        // Completion Keywords
        if (isset($_POST['sfaic_completion_keywords'])) {
            update_post_meta($post_id, '_sfaic_completion_keywords', sanitize_textarea_field($_POST['sfaic_completion_keywords']));
        }

        // Token-Based Completion checkbox
        $use_token_percentage = isset($_POST['sfaic_use_token_percentage']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_use_token_percentage', $use_token_percentage);

        // Token Threshold
        if (isset($_POST['sfaic_token_completion_threshold'])) {
            $threshold = intval($_POST['sfaic_token_completion_threshold']);
            if ($threshold >= 50 && $threshold <= 95) {
                update_post_meta($post_id, '_sfaic_token_completion_threshold', $threshold);
            }
        }

        // ==== Debug logging to check if values are being received ====
        error_log('SFAIC: Saving chunking settings for post ' . $post_id);
        error_log('SFAIC: completion_marker = ' . ($_POST['sfaic_completion_marker'] ?? 'NOT SET'));
        error_log('SFAIC: enable_smart_completion = ' . ($enable_smart_completion ? 'YES' : 'NO'));
        error_log('SFAIC: min_content_length = ' . ($_POST['sfaic_min_content_length'] ?? 'NOT SET'));
        error_log('SFAIC: completion_word_count = ' . ($_POST['sfaic_completion_word_count'] ?? 'NOT SET'));
        error_log('SFAIC: min_chunk_words = ' . ($_POST['sfaic_min_chunk_words'] ?? 'NOT SET'));
        error_log('SFAIC: completion_keywords = ' . ($_POST['sfaic_completion_keywords'] ?? 'NOT SET'));
        error_log('SFAIC: use_token_percentage = ' . ($use_token_percentage ? 'YES' : 'NO'));
        error_log('SFAIC: token_completion_threshold = ' . ($_POST['sfaic_token_completion_threshold'] ?? 'NOT SET'));
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
                return $field_labels;
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
                return $field_labels;
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
                return $field_labels;
            }
        }

        // Method 4: Use Fluent Forms API if available (most reliable)
        if (class_exists('\FluentForm\App\Api\FormFields')) {
            try {
                $formFields = (new \FluentForm\App\Api\FormFields())->getFormInputs($form_id);
                if (!empty($formFields)) {
                    foreach ($formFields as $fieldName => $fieldDetails) {
                        $field_labels[$fieldName] = $fieldDetails['element'];
                    }
                    return $field_labels;
                }
            } catch (\Exception $e) {
                // Silently fail, we'll return an empty array below
            }
        }

        return $field_labels;
    }

    /**
     * NEW: Get dynamic chunking settings for a prompt
     * 
     * @param int $prompt_id The prompt ID
     * @return array Chunking settings array
     */
    public function get_chunking_settings($prompt_id) {
        return array(
            'completion_marker' => get_post_meta($prompt_id, '_sfaic_completion_marker', true) ?: '<!-- REPORT_END -->',
            'min_content_length' => intval(get_post_meta($prompt_id, '_sfaic_min_content_length', true)) ?: 500,
            'completion_word_count' => intval(get_post_meta($prompt_id, '_sfaic_completion_word_count', true)) ?: 800,
            'min_chunk_words' => intval(get_post_meta($prompt_id, '_sfaic_min_chunk_words', true)) ?: 300,
            'completion_keywords' => get_post_meta($prompt_id, '_sfaic_completion_keywords', true) ?: 'conclusion, summary, final, recommendations, regards, sincerely',
            'enable_smart_completion' => get_post_meta($prompt_id, '_sfaic_enable_smart_completion', true) === '1',
            'use_token_percentage' => get_post_meta($prompt_id, '_sfaic_use_token_percentage', true) === '1',
            'token_completion_threshold' => intval(get_post_meta($prompt_id, '_sfaic_token_completion_threshold', true)) ?: 70
        );
    }

    /**
     * Send email with the AI response
     * 
     * @param int $prompt_id The prompt ID
     * @param int $entry_id The submission entry ID
     * @param array $form_data The submitted form data
     * @param string $ai_response The AI response
     * @return bool True if email sent successfully, false otherwise
     */
    private function send_email_response($prompt_id, $entry_id, $form_data, $ai_response) {
        // Get email settings
        $email_to = get_post_meta($prompt_id, '_sfaic_email_to', true);
        $email_subject = get_post_meta($prompt_id, '_sfaic_email_subject', true);
        $email_to_user = get_post_meta($prompt_id, '_sfaic_email_to_user', true);
        $selected_email_field = get_post_meta($prompt_id, '_sfaic_email_field', true);

        $recipient_email = '';

        // First try to find an email field in the form if email_to_user is enabled
        if ($email_to_user == '1') {

            // If a specific email field is selected, try to use that first
            if (!empty($selected_email_field) && isset($form_data[$selected_email_field])) {
                $field_value = $form_data[$selected_email_field];

                // Make sure it's a valid email
                if (is_string($field_value) && filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
                    $recipient_email = $field_value;
                }
            }

            // If no specific field selected or the selected field didn't work, try auto-detection
            if (empty($recipient_email)) {
                // Look for common email field names
                $common_email_fields = array('email', 'your_email', 'user_email', 'email_address', 'customer_email');

                foreach ($form_data as $field_key => $field_value) {
                    // If the field name contains "email" and the value looks like an email
                    if ((is_string($field_value) && filter_var($field_value, FILTER_VALIDATE_EMAIL)) &&
                            (strpos(strtolower($field_key), 'email') !== false || in_array(strtolower($field_key), $common_email_fields))) {
                        $recipient_email = $field_value;
                        break;
                    }
                }

                // If no direct match found, try to look for nested arrays or complex field structures
                if (empty($recipient_email)) {
                    foreach ($form_data as $field_key => $field_value) {
                        if (is_array($field_value)) {
                            foreach ($field_value as $sub_key => $sub_value) {
                                if (is_string($sub_value) && filter_var($sub_value, FILTER_VALIDATE_EMAIL)) {
                                    $recipient_email = $sub_value;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Process additional recipients from email_to setting
        $additional_recipients = array();

        if (!empty($email_to)) {
            // If email_to contains placeholders, replace them with form values
            if (strpos($email_to, '{') !== false) {
                foreach ($form_data as $field_key => $field_value) {
                    if (is_string($field_value) && strpos($email_to, '{' . $field_key . '}') !== false) {
                        $email_to = str_replace('{' . $field_key . '}', $field_value, $email_to);
                    }
                }
            }

            // Split by comma for multiple recipients
            $additional_emails = explode(',', $email_to);
            foreach ($additional_emails as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $additional_recipients[] = $email;
                }
            }
        }

        // Combine all recipients
        $all_recipients = array();

        if (!empty($recipient_email)) {
            $all_recipients[] = $recipient_email;
        }

        if (!empty($additional_recipients)) {
            $all_recipients = array_merge($all_recipients, $additional_recipients);
        }

        // If no recipients found, use admin email as fallback
        if (empty($all_recipients)) {
            $all_recipients[] = get_option('admin_email');
        }

        // Make recipients unique
        $all_recipients = array_unique($all_recipients);

        // Set default subject if empty
        if (empty($email_subject)) {
            $email_subject = __('Response for Your Form Submission', 'chatgpt-fluent-connector');
        }

        // Prepare email content
        $email_content = '
    <html>
    <head>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
            }
            .container {
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            .header {
                background-color: #f5f5f5;
                padding: 10px 20px;
                border-radius: 5px 5px 0 0;
                border-bottom: 1px solid #ddd;
            }
            .content {
                padding: 20px;
            }
            .response {
                background-color: #f9f9f9;
                padding: 15px;
                border-left: 4px solid #0073aa;
                margin-bottom: 20px;
            }
            .footer {
                font-size: 12px;
                color: #777;
                border-top: 1px solid #ddd;
                padding-top: 15px;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>' . __('ChatGPT Response', 'chatgpt-fluent-connector') . '</h2>
            </div>
            <div class="content">
                <p>' . __('Thank you for your submission. Here\'s the response from ChatGPT:', 'chatgpt-fluent-connector') . '</p>
                
                <div class="response">
                    ' . nl2br(esc_html($ai_response)) . '
                </div>
            </div>
            <div class="footer">
                ' . __('This is an automated email sent in response to your form submission.', 'chatgpt-fluent-connector') . '
            </div>
        </div>
    </body>
    </html>';

        // Set email headers
        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Track success
        $success = true;

        // Send email to each recipient
        foreach ($all_recipients as $to_email) {
            $sent = wp_mail($to_email, $email_subject, $email_content, $headers);

            // If any send fails, mark as unsuccessful
            if (!$sent) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Process a prompt with form data
     * 
     * @param int $prompt_id The prompt ID
     * @param array $form_data The form data
     * @param int $entry_id The entry ID
     * @param object $form The form object
     * @return void
     */
    private function process_prompt($prompt_id, $form_data, $entry_id, $form) {
        $start_time = microtime(true);

        // Get the active provider setting
        $provider = get_option('sfaic_api_provider', 'openai');

        if ($provider === 'gemini') {
            $model = get_option('sfaic_gemini_model', 'gemini-2.5-pro-preview-05-06');
        } elseif ($provider === 'claude') {
            $model = get_option('sfaic_claude_model', 'claude-opus-4-20250514');
        } else {
            $model = get_option('sfaic_model', 'gpt-3.5-turbo');
        }

        // Get the API instance based on the provider
        $api = sfaic_main()->get_active_api();

        if (!$api) {
            // Log the error if possible
            if (isset(sfaic_main()->response_logger)) {
                sfaic_main()->response_logger->log_response(
                        $prompt_id,
                        $entry_id,
                        $form->id,
                        "Error: API instance not available",
                        "",
                        $provider,
                        "",
                        0,
                        "error",
                        "API instance not available"
                );
            }
            return;
        }

        // Get prompt settings
        $system_prompt = get_post_meta($prompt_id, '_sfaic_system_prompt', true);
        $user_prompt_template = get_post_meta($prompt_id, '_sfaic_user_prompt_template', true);
        $prompt_type = get_post_meta($prompt_id, '_sfaic_prompt_type', true);

        // Set default prompt type if not set
        if (empty($prompt_type)) {
            $prompt_type = 'template';
        }

        // Prepare the user prompt based on prompt type
        $user_prompt = '';
        if ($prompt_type === 'all_form_data') {
            // Use all form data
            $user_prompt = $this->format_all_form_data($form_data, $prompt_id);
        } else {
            // Use custom template
            if (empty($user_prompt_template)) {
                // Log the error
                if (isset(sfaic_main()->response_logger)) {
                    sfaic_main()->response_logger->log_response(
                            $prompt_id,
                            $entry_id,
                            $form->id,
                            "Error: No user prompt template configured",
                            "",
                            $provider,
                            "",
                            0,
                            "error",
                            "No user prompt template configured"
                    );
                }
                return;
            }

            // Replace placeholders in user prompt
            $user_prompt = $user_prompt_template;

            // Replace field placeholders with actual values
            foreach ($form_data as $field_key => $field_value) {
                // Skip if field_key is not a scalar (string/number)
                if (!is_scalar($field_key)) {
                    continue;
                }

                // Handle array values (like checkboxes)
                if (is_array($field_value)) {
                    $field_value = implode(', ', $field_value);
                } elseif (!is_scalar($field_value)) {
                    // Skip non-scalar values
                    continue;
                }

                $user_prompt = str_replace('{' . $field_key . '}', $field_value, $user_prompt);
            }

            // Check for any remaining placeholders and replace with empty string
            $user_prompt = preg_replace('/\{[^}]+\}/', '', $user_prompt);
        }

        // Build the complete prompt that will be sent
        $complete_prompt = '';
        if (!empty($system_prompt)) {
            $complete_prompt .= $system_prompt . "\n";
        }
        $complete_prompt .= $user_prompt;

        // Process the form with the prompt (handle potential errors)
        try {
            $ai_response_raw = $api->process_form_with_prompt($prompt_id, $form_data, $entry_id);
            $ai_response = is_wp_error($ai_response_raw) ? $ai_response_raw : $this->clean_html_response($ai_response_raw);
        } catch (Exception $e) {
            // Convert exception to WP_Error
            $ai_response = new WP_Error('exception', $e->getMessage());
        }

        // Calculate execution time
        $execution_time = microtime(true) - $start_time;

        // Get token usage from the API
        $token_usage = array();
        if (method_exists($api, 'get_last_token_usage')) {
            $token_usage = $api->get_last_token_usage();
        }

        // Check if we got a valid response or an error
        $status = 'success';
        $error_message = '';
        $response_content = '';

        if (is_wp_error($ai_response)) {
            $status = 'error';
            $error_message = $ai_response->get_error_message();
        } else {
            $response_content = $ai_response;

            // TRIGGER PDF GENERATION ACTION - This is the key addition for PDF support
            if (isset(sfaic_main()->pdf_generator)) {
                do_action('sfaic_after_ai_response_processed', $response_content, $prompt_id, $entry_id, $form_data, $form);
            }
        }

        // Save the response if logging is enabled
        $log_responses = get_post_meta($prompt_id, '_sfaic_log_responses', true);
        if ($log_responses == '1' || $status === 'error') {
            // Use the enhanced response logger with proper provider, model information, and token usage
            if (isset(sfaic_main()->response_logger)) {
                $result = sfaic_main()->response_logger->log_response(
                        $prompt_id,
                        $entry_id,
                        $form->id,
                        $complete_prompt, // Save the actual prompt sent
                        $response_content,
                        $provider, // Pass the correct provider
                        $model, // Pass the correct model
                        $execution_time,
                        $status,
                        $error_message,
                        $token_usage // Pass the token usage
                );
            }
        }

        // Don't proceed with email if there was an error
        if ($status === 'error') {
            return;
        }

        // Handle the response according to settings
        $response_action = get_post_meta($prompt_id, '_sfaic_response_action', true);

        // Send email if configured
        if ($response_action === 'email') {
            $email_sent = $this->send_email_response($prompt_id, $entry_id, $form_data, $response_content, $provider);
        }
    }
}
