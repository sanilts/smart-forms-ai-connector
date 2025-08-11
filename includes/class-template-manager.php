<?php
/**
 * Template Manager Class - Generalized for Multiple Template Types
 * 
 * Handles various types of templates for AI prompts (HTML, JSON, XML, Text, etc.)
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Template_Manager {

    /**
     * Constructor
     */
    public function __construct() {
        // Add meta box for templates
        add_action('add_meta_boxes', array($this, 'add_template_meta_box'));

        // Save template data
        add_action('save_post', array($this, 'save_template_data'), 10, 2);

        // Add filter to modify prompts with templates
        add_filter('sfaic_process_form_with_prompt', array($this, 'append_template_to_prompt'), 10, 3);
    }

    /**
     * Get available template types
     */
    private function get_template_types() {
        return array(
            'html' => __('HTML Template', 'chatgpt-fluent-connector'),
            'json' => __('JSON Structure', 'chatgpt-fluent-connector'),
            'xml' => __('XML Format', 'chatgpt-fluent-connector'),
            'markdown' => __('Markdown Template', 'chatgpt-fluent-connector'),
            'csv' => __('CSV Format', 'chatgpt-fluent-connector'),
            'text' => __('Text Template', 'chatgpt-fluent-connector'),
            'custom' => __('Custom Format', 'chatgpt-fluent-connector')
        );
    }

    /**
     * Add meta box for templates
     */
    public function add_template_meta_box() {
        add_meta_box(
                'sfaic_template_box',
                __('Response Template', 'chatgpt-fluent-connector'),
                array($this, 'render_template_meta_box'),
                'sfaic_prompt',
                'normal',
                'default'
        );
    }

    /**
     * Render template meta box
     */
    public function render_template_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('sfaic_template_save', 'sfaic_template_nonce');

        // Get saved values
        $use_template = get_post_meta($post->ID, '_sfaic_use_template', true);
        $template_type = get_post_meta($post->ID, '_sfaic_template_type', true);
        $template_content = get_post_meta($post->ID, '_sfaic_template_content', true);
        $template_instruction = get_post_meta($post->ID, '_sfaic_template_instruction', true);

        // Set defaults
        if (empty($template_type)) {
            $template_type = 'html';
        }
        if (empty($template_instruction)) {
            $template_instruction = __('Please format your response using this template as a reference:', 'chatgpt-fluent-connector');
        }

        $template_types = $this->get_template_types();
        ?>
        <div class="sfaic-template-wrapper">
            <p>
                <label>
                    <input type="checkbox" name="sfaic_use_template" id="sfaic_use_template" value="1" <?php checked($use_template, '1'); ?>>
                    <?php _e('Include a response template in the prompt', 'chatgpt-fluent-connector'); ?>
                </label>
            </p>
            <p class="description">
                <?php _e('When enabled, the template will be included in the prompt sent to the AI to guide the formatting and structure of its response.', 'chatgpt-fluent-connector'); ?>
            </p>

            <div id="template_section" <?php echo ($use_template != '1') ? 'style="display:none;"' : ''; ?>>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="sfaic_template_type"><?php _e('Template Type:', 'chatgpt-fluent-connector'); ?></label>
                        </th>
                        <td>
                            <select name="sfaic_template_type" id="sfaic_template_type" class="regular-text">
                                <?php foreach ($template_types as $type_key => $type_label) : ?>
                                    <option value="<?php echo esc_attr($type_key); ?>" <?php selected($template_type, $type_key); ?>>
                                        <?php echo esc_html($type_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Select the type of template you want to provide to guide the AI response format.', 'chatgpt-fluent-connector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sfaic_template_instruction"><?php _e('Template Instruction:', 'chatgpt-fluent-connector'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="sfaic_template_instruction" id="sfaic_template_instruction" value="<?php echo esc_attr($template_instruction); ?>" class="widefat">
                            <p class="description"><?php _e('This text will be shown to the AI to explain how to use the template.', 'chatgpt-fluent-connector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sfaic_template_content"><?php _e('Template Content:', 'chatgpt-fluent-connector'); ?></label>
                        </th>
                        <td>
                            <textarea name="sfaic_template_content" id="sfaic_template_content" class="widefat code template-editor" rows="20" placeholder="<?php _e('Enter your template content here...', 'chatgpt-fluent-connector'); ?>"><?php echo esc_textarea($template_content); ?></textarea>
                            <p class="description template-description"><?php echo $this->get_template_description($template_type); ?></p>
                            
                            <!-- Template Examples -->
                            <div class="template-examples" style="margin-top: 15px;">
                                <details>
                                    <summary style="cursor: pointer; font-weight: bold; color: #0073aa;"><?php _e('Show Template Examples', 'chatgpt-fluent-connector'); ?></summary>
                                    <div id="template-examples-content" style="margin-top: 10px;">
                                        <?php echo $this->get_template_examples($template_type); ?>
                                    </div>
                                </details>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // Toggle template section visibility
                $('#sfaic_use_template').change(function () {
                    if ($(this).is(':checked')) {
                        $('#template_section').show();
                    } else {
                        $('#template_section').hide();
                    }
                });

                // Update description and examples when template type changes
                $('#sfaic_template_type').change(function () {
                    var templateType = $(this).val();
                    
                    // Update description
                    updateTemplateDescription(templateType);
                    
                    // Update examples
                    updateTemplateExamples(templateType);
                });

                function updateTemplateDescription(type) {
                    var descriptions = {
                        'html': '<?php echo esc_js(__('Enter HTML markup that will guide the AI response formatting with proper structure and styling.', 'chatgpt-fluent-connector')); ?>',
                        'json': '<?php echo esc_js(__('Enter a JSON structure that the AI should follow for its response format.', 'chatgpt-fluent-connector')); ?>',
                        'xml': '<?php echo esc_js(__('Enter an XML structure template for the AI to follow in its response.', 'chatgpt-fluent-connector')); ?>',
                        'markdown': '<?php echo esc_js(__('Enter Markdown formatting template for the AI response structure.', 'chatgpt-fluent-connector')); ?>',
                        'csv': '<?php echo esc_js(__('Enter CSV format template with headers and example data structure.', 'chatgpt-fluent-connector')); ?>',
                        'text': '<?php echo esc_js(__('Enter a plain text template with placeholders and structure guidance.', 'chatgpt-fluent-connector')); ?>',
                        'custom': '<?php echo esc_js(__('Enter your custom format template for the AI to follow.', 'chatgpt-fluent-connector')); ?>'
                    };
                    
                    $('.template-description').text(descriptions[type] || descriptions['custom']);
                }

                function updateTemplateExamples(type) {
                    var examples = getTemplateExamples(type);
                    $('#template-examples-content').html(examples);
                }

                function getTemplateExamples(type) {
                    var examples = {
                        'html': `<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
&lt;div class="response-container"&gt;
    &lt;h2&gt;Response Title&lt;/h2&gt;
    &lt;p&gt;Main content here...&lt;/p&gt;
    &lt;ul&gt;
        &lt;li&gt;List item 1&lt;/li&gt;
        &lt;li&gt;List item 2&lt;/li&gt;
    &lt;/ul&gt;
&lt;/div&gt;</pre>`,
                        'json': `<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
{
    "status": "success",
    "title": "Response Title",
    "content": "Main response content",
    "data": {
        "items": ["item1", "item2"],
        "count": 2
    }
}</pre>`,
                        'xml': `<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
&lt;response&gt;
    &lt;title&gt;Response Title&lt;/title&gt;
    &lt;content&gt;Main content here&lt;/content&gt;
    &lt;items&gt;
        &lt;item&gt;Item 1&lt;/item&gt;
        &lt;item&gt;Item 2&lt;/item&gt;
    &lt;/items&gt;
&lt;/response&gt;</pre>`,
                        'markdown': `<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
# Response Title

## Main Section

Your response content here...

- Bullet point 1
- Bullet point 2

**Bold text** and *italic text*</pre>`,
                        'csv': `<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
Name,Value,Status,Description
Item 1,100,Active,Description here
Item 2,200,Pending,Another description</pre>`,
                        'text': `<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
RESPONSE TITLE: [Title Here]

MAIN CONTENT:
[Your response content here]

KEY POINTS:
- Point 1: [Details]
- Point 2: [Details]

CONCLUSION: [Summary]</pre>`,
                        'custom': `<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
Define your own custom format here.
Use placeholders like [FIELD_NAME] or {variable}
Structure it however you need for your use case.</pre>`
                    };
                    
                    return examples[type] || examples['custom'];
                }

                // Initialize with current template type
                updateTemplateDescription('<?php echo esc_js($template_type); ?>');
            });
        </script>

        <style>
            .sfaic-template-wrapper .widefat.code {
                font-family: Consolas, Monaco, monospace;
                font-size: 13px;
                background-color: #f8f8f8;
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            .sfaic-template-wrapper .description {
                font-style: italic;
                color: #666;
            }
            .sfaic-template-wrapper .template-editor {
                min-height: 740px;
            }
            .sfaic-template-wrapper .form-table th {
                width: 200px;
                font-weight: 600;
            }
            .template-examples details {
                margin-top: 10px;
                padding: 10px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            .template-examples summary {
                outline: none;
            }
            .template-examples pre {
                margin: 0;
                overflow-x: auto;
                max-height: 200px;
                overflow-y: auto;
            }
        </style>
        <?php
    }

    /**
     * Get template description based on type
     */
    private function get_template_description($type) {
        $descriptions = array(
            'html' => __('Enter HTML markup that will guide the AI response formatting with proper structure and styling.', 'chatgpt-fluent-connector'),
            'json' => __('Enter a JSON structure that the AI should follow for its response format.', 'chatgpt-fluent-connector'),
            'xml' => __('Enter an XML structure template for the AI to follow in its response.', 'chatgpt-fluent-connector'),
            'markdown' => __('Enter Markdown formatting template for the AI response structure.', 'chatgpt-fluent-connector'),
            'csv' => __('Enter CSV format template with headers and example data structure.', 'chatgpt-fluent-connector'),
            'text' => __('Enter a plain text template with placeholders and structure guidance.', 'chatgpt-fluent-connector'),
            'custom' => __('Enter your custom format template for the AI to follow.', 'chatgpt-fluent-connector')
        );

        return isset($descriptions[$type]) ? $descriptions[$type] : $descriptions['custom'];
    }

    /**
     * Get template examples based on type
     */
    private function get_template_examples($type) {
        $examples = array(
            'html' => '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
&lt;div class="response-container"&gt;
    &lt;h2&gt;Response Title&lt;/h2&gt;
    &lt;p&gt;Main content here...&lt;/p&gt;
    &lt;ul&gt;
        &lt;li&gt;List item 1&lt;/li&gt;
        &lt;li&gt;List item 2&lt;/li&gt;
    &lt;/ul&gt;
&lt;/div&gt;</pre>',
            'json' => '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
{
    "status": "success",
    "title": "Response Title",
    "content": "Main response content",
    "data": {
        "items": ["item1", "item2"],
        "count": 2
    }
}</pre>',
            'xml' => '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
&lt;response&gt;
    &lt;title&gt;Response Title&lt;/title&gt;
    &lt;content&gt;Main content here&lt;/content&gt;
    &lt;items&gt;
        &lt;item&gt;Item 1&lt;/item&gt;
        &lt;item&gt;Item 2&lt;/item&gt;
    &lt;/items&gt;
&lt;/response&gt;</pre>',
            'markdown' => '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
# Response Title

## Main Section

Your response content here...

- Bullet point 1
- Bullet point 2

**Bold text** and *italic text*</pre>',
            'csv' => '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
Name,Value,Status,Description
Item 1,100,Active,Description here
Item 2,200,Pending,Another description</pre>',
            'text' => '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
RESPONSE TITLE: [Title Here]

MAIN CONTENT:
[Your response content here]

KEY POINTS:
- Point 1: [Details]
- Point 2: [Details]

CONCLUSION: [Summary]</pre>',
            'custom' => '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; font-size: 12px;">
Define your own custom format here.
Use placeholders like [FIELD_NAME] or {variable}
Structure it however you need for your use case.</pre>'
        );

        return isset($examples[$type]) ? $examples[$type] : $examples['custom'];
    }

    /**
     * Save template data
     */
    public function save_template_data($post_id, $post) {
        // Check if our custom post type
        if ($post->post_type !== 'sfaic_prompt') {
            return;
        }

        // Check if our nonce is set
        if (!isset($_POST['sfaic_template_nonce'])) {
            return;
        }

        // Verify the nonce
        if (!wp_verify_nonce($_POST['sfaic_template_nonce'], 'sfaic_template_save')) {
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

        // Save the "use template" option
        $use_template = isset($_POST['sfaic_use_template']) ? '1' : '0';
        update_post_meta($post_id, '_sfaic_use_template', $use_template);

        // Save the template type
        if (isset($_POST['sfaic_template_type'])) {
            update_post_meta($post_id, '_sfaic_template_type', sanitize_text_field($_POST['sfaic_template_type']));
        }

        // Save the template instruction
        if (isset($_POST['sfaic_template_instruction'])) {
            update_post_meta($post_id, '_sfaic_template_instruction', sanitize_text_field($_POST['sfaic_template_instruction']));
        }

        // Save the template content
        if (isset($_POST['sfaic_template_content'])) {
            // For HTML and XML, allow more tags. For others, use basic sanitization
            $template_type = sanitize_text_field($_POST['sfaic_template_type']);
            
            if (in_array($template_type, array('html', 'xml'))) {
                // Use KSES to sanitize HTML/XML but allow tags needed for templates
                $allowed_html = wp_kses_allowed_html('post');
                $allowed_html['style'] = array('type' => true);
                $allowed_html['script'] = array('type' => true);
                $template_content = wp_kses($_POST['sfaic_template_content'], $allowed_html);
            } else {
                // For other types (JSON, CSV, text, etc.), use basic sanitization
                $template_content = sanitize_textarea_field($_POST['sfaic_template_content']);
            }

            update_post_meta($post_id, '_sfaic_template_content', $template_content);
        }
    }

    /**
     * Append template to prompt
     */
    public function append_template_to_prompt($user_prompt, $prompt_id, $form_data) {
        // Check if template is enabled for this prompt
        $use_template = get_post_meta($prompt_id, '_sfaic_use_template', true);

        if ($use_template != '1') {
            return $user_prompt;
        }

        // Get the template data
        $template_type = get_post_meta($prompt_id, '_sfaic_template_type', true);
        $template_content = get_post_meta($prompt_id, '_sfaic_template_content', true);
        $template_instruction = get_post_meta($prompt_id, '_sfaic_template_instruction', true);

        // Don't modify prompt if template is empty
        if (empty($template_content)) {
            return $user_prompt;
        }

        // Default instruction if not set
        if (empty($template_instruction)) {
            $template_instruction = __('Please format your response using this template as a reference:', 'chatgpt-fluent-connector');
        }

        // Get template type label for instruction
        $template_types = $this->get_template_types();
        $type_label = isset($template_types[$template_type]) ? $template_types[$template_type] : __('Template', 'chatgpt-fluent-connector');

        // Optimize template content
        $template_content = $this->optimize_template_content($template_content, $template_type);

        // Add template to the prompt with type-specific formatting
        $template_addition = "\n\n{$template_instruction}\n\n{$type_label}:\n{$template_content}";

        return $user_prompt . $template_addition;
    }

    /**
     * Optimize template content to reduce size based on type
     * 
     * @param string $content The template content
     * @param string $type The template type
     * @return string The optimized template content
     */
    private function optimize_template_content($content, $type) {
        switch ($type) {
            case 'html':
            case 'xml':
                // Remove HTML/XML comments
                $content = preg_replace('/<!--(.*?)-->/s', '', $content);
                // Remove excessive whitespace between tags
                $content = preg_replace('/>\s+</m', '><', $content);
                break;
                
            case 'json':
                // Remove extra whitespace from JSON while keeping it readable
                $decoded = json_decode($content, true);
                if ($decoded !== null) {
                    $content = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }
                break;
                
            case 'csv':
                // Trim each line and remove empty lines
                $lines = array_filter(array_map('trim', explode("\n", $content)));
                $content = implode("\n", $lines);
                break;
                
            default:
                // For text, markdown, and custom formats, just trim and remove excessive empty lines
                $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", trim($content));
                break;
        }

        return $content;
    }
}

// Initialize our template manager class
add_action('plugins_loaded', function () {
    if (class_exists('SFAIC_Main')) {
        new SFAIC_Template_Manager();
    }
}, 20);
















function sfaic_migrate_template_data() {
    global $wpdb;
    
    // Get all prompts that have the old HTML template fields
    $prompts_with_html_templates = $wpdb->get_results("
        SELECT post_id 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_sfaic_use_html_template' 
        AND meta_value = '1'
    ");
    
    foreach ($prompts_with_html_templates as $prompt) {
        $post_id = $prompt->post_id;
        
        // Get existing HTML template data
        $use_html_template = get_post_meta($post_id, '_sfaic_use_html_template', true);
        $html_template = get_post_meta($post_id, '_sfaic_html_template', true);
        $template_instruction = get_post_meta($post_id, '_sfaic_template_instruction', true);
        
        if ($use_html_template === '1') {
            // Migrate to new format
            update_post_meta($post_id, '_sfaic_use_template', '1');
            update_post_meta($post_id, '_sfaic_template_type', 'html');
            
            if (!empty($html_template)) {
                update_post_meta($post_id, '_sfaic_template_content', $html_template);
            }
            
            if (!empty($template_instruction)) {
                update_post_meta($post_id, '_sfaic_template_instruction', $template_instruction);
            } else {
                // Set default instruction if empty
                update_post_meta($post_id, '_sfaic_template_instruction', 
                    __('Please format your response using this template as a reference:', 'chatgpt-fluent-connector'));
            }
            
            // Optional: Remove old meta keys to clean up
            // delete_post_meta($post_id, '_sfaic_use_html_template');
            // delete_post_meta($post_id, '_sfaic_html_template');
            // Note: We keep _sfaic_template_instruction as it's the same field name
        }
    }
    
    // Log the migration
    error_log('SFAIC: Migrated ' . count($prompts_with_html_templates) . ' prompts to new template format');
}

/**
 * Add this to your plugin activation hook or call manually
 */
function sfaic_run_template_migration() {
    // Check if migration has already been run
    if (!get_option('sfaic_template_migration_done', false)) {
        sfaic_migrate_template_data();
        update_option('sfaic_template_migration_done', true);
    }
}

//add_action('admin_init', 'sfaic_run_template_migration');