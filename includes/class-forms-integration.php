<?php

/**
 * ChatGPT Fluent Forms Integration Class - Fixed for immediate redirects
 * 
 * Handles integration with Fluent Forms using the new background job system
 * WITHOUT blocking form submission redirects
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Forms_Integration {

    /**
     * Constructor
     */
    public function __construct() {
        // Prevent duplicate hook registration
        static $hooks_registered = false;
        
        if (!$hooks_registered) {
            // Hook into Fluent Forms submission with LOWER priority to avoid blocking redirects
            add_action('fluentform/submission_inserted', array($this, 'handle_form_submission'), 5, 3);
            
            // Use WordPress shutdown hook for completely non-blocking processing
            add_action('shutdown', array($this, 'process_queued_submissions'));
            
            $hooks_registered = true;
            error_log('SFAIC: Forms integration hooks registered (priority 30 + shutdown)');
        }
        
        // Initialize the queue
        if (!isset($this->queued_submissions)) {
            $this->queued_submissions = array();
        }
    }
    
    private $queued_submissions = array();
    
    /**
     * Handle form submission - ONLY QUEUE, DO NOT PROCESS
     * 
     * @param int $entry_id The submission entry ID
     * @param array $form_data The submitted form data
     * @param object $form The form object
     */
    public function handle_form_submission($insertId, $formData, $form) {
        error_log('SFAIC: Form submission received - Entry ID: ' . $insertId . ', Form ID: ' . $form->id);

        // Find all prompts associated with this form
        $prompts = get_posts(array(
            'post_type' => 'sfaic_prompt',
            'meta_query' => array(
                array(
                    'key' => '_sfaic_fluent_form_id',
                    'value' => $form->id,
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1
        ));

        if (empty($prompts)) {
            error_log('SFAIC: No prompts found for form ID: ' . $form->id);
            return;
        }

        error_log('SFAIC: Found ' . count($prompts) . ' prompt(s) for form ID: ' . $form->id . ' - QUEUING ONLY');

        // ONLY queue for later processing - DO NOT process here
        foreach ($prompts as $prompt) {
            $enable_background_processing = get_post_meta($prompt->ID, '_sfaic_enable_background_processing', true);
            
            // Default to enabled if not set (backwards compatibility)
            if ($enable_background_processing === '') {
                $enable_background_processing = '1';
            }

            // Add to queue - processing happens later during shutdown
            $this->queued_submissions[] = array(
                'prompt_id' => $prompt->ID,
                'entry_id' => $insertId,
                'form_data' => $formData,
                'form' => $form,
                'background_enabled' => ($enable_background_processing === '1')
            );
            
            error_log('SFAIC: Queued prompt ID: ' . $prompt->ID . ' (background: ' . ($enable_background_processing === '1' ? 'yes' : 'no') . ')');
        }
        
        // CRITICAL: Return immediately - NO processing here
        // All processing happens during shutdown hook to avoid blocking redirects
    }
    
    /**
     * Process queued submissions during shutdown (non-blocking)
     * ONLY METHOD THAT ACTUALLY PROCESSES SUBMISSIONS
     */
    public function process_queued_submissions() {
        if (empty($this->queued_submissions)) {
            return;
        }
        
        // Prevent multiple processing by clearing queue immediately
        $submissions_to_process = $this->queued_submissions;
        $this->queued_submissions = array(); // Clear queue immediately
        
        error_log('SFAIC: Processing ' . count($submissions_to_process) . ' queued submissions during shutdown');
        
        foreach ($submissions_to_process as $submission) {
            error_log('SFAIC: Processing queued prompt ID: ' . $submission['prompt_id'] . ' (background: ' . ($submission['background_enabled'] ? 'yes' : 'no') . ')');
            
            if ($submission['background_enabled']) {
                // Process in background
                $this->schedule_background_processing(
                    $submission['prompt_id'], 
                    $submission['entry_id'], 
                    $submission['form_data'], 
                    $submission['form']
                );
            } else {
                // Process immediately but asynchronously using wp_remote_post
                $this->schedule_immediate_async_processing(
                    $submission['prompt_id'], 
                    $submission['entry_id'], 
                    $submission['form_data'], 
                    $submission['form']
                );
            }
        }
        
        error_log('SFAIC: Completed processing all queued submissions');
    }
    
    /**
     * NEW: Schedule immediate async processing using wp_remote_post
     */
    private function schedule_immediate_async_processing($prompt_id, $entry_id, $form_data, $form) {
        error_log('SFAIC: Scheduling immediate async processing for prompt ID: ' . $prompt_id);
        
        // Use wp_remote_post to trigger immediate processing without blocking
        wp_remote_post(admin_url('admin-ajax.php'), array(
            'timeout' => 0.01, // Very short timeout
            'blocking' => false, // Non-blocking
            'body' => array(
                'action' => 'sfaic_process_immediate_async',
                'prompt_id' => $prompt_id,
                'entry_id' => $entry_id,
                'form_data' => base64_encode(serialize($form_data)),
                'form_id' => $form->id,
                'nonce' => wp_create_nonce('sfaic_immediate_async_' . $prompt_id)
            )
        ));
    }
    
    /**
     * AJAX handler for immediate async processing
     */
    public function handle_immediate_async_processing() {
        // Verify nonce
        $prompt_id = intval($_POST['prompt_id'] ?? 0);
        $nonce = $_POST['nonce'] ?? '';
        
        if (!wp_verify_nonce($nonce, 'sfaic_immediate_async_' . $prompt_id)) {
            wp_die('Invalid nonce');
        }
        
        $entry_id = intval($_POST['entry_id'] ?? 0);
        $form_data = unserialize(base64_decode($_POST['form_data'] ?? ''));
        $form_id = intval($_POST['form_id'] ?? 0);
        
        if (!$prompt_id || !$entry_id || !is_array($form_data)) {
            wp_die('Invalid data');
        }
        
        // Get form object
        if (!function_exists('wpFluent')) {
            wp_die('Fluent Forms not available');
        }
        
        $form = wpFluent()->table('fluentform_forms')->where('id', $form_id)->first();
        if (!$form) {
            wp_die('Form not found');
        }
        
        error_log('SFAIC: Processing immediate async job for prompt: ' . $prompt_id);
        
        // Process the prompt immediately
        $this->process_prompt($prompt_id, $form_data, $entry_id, $form);
        
        wp_die('OK');
    }
    
    /**
     * Schedule background processing for a specific prompt
     */
    private function schedule_background_processing($prompt_id, $entry_id, $form_data, $form) {
        // Get prompt-specific background processing settings
        $delay = get_post_meta($prompt_id, '_sfaic_background_processing_delay', true);
        if (empty($delay)) {
            $delay = 5; // Default delay
        }

        $priority = get_post_meta($prompt_id, '_sfaic_job_priority', true);
        if (empty($priority)) {
            $priority = 0; // Default priority
        }

        // Check if background job manager is available
        if (!isset(sfaic_main()->background_job_manager)) {
            error_log('SFAIC: Background job manager not available, scheduling async processing');
            $this->schedule_immediate_async_processing($prompt_id, $entry_id, $form_data, $form);
            return;
        }

        // Schedule the background job
        $job_id = sfaic_main()->background_job_manager->schedule_job(
            'ai_form_processing',
            $prompt_id,
            $form->id,
            $entry_id,
            array(
                'form_data' => $form_data,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'timestamp' => current_time('mysql')
            ),
            intval($delay),
            intval($priority)
        );

        if ($job_id) {
            error_log('SFAIC: Scheduled background job ID: ' . $job_id . ' for prompt: ' . $prompt_id);
        } else {
            error_log('SFAIC: Failed to schedule background job, using async processing');
            $this->schedule_immediate_async_processing($prompt_id, $entry_id, $form_data, $form);
        }
    }

    /**
     * Process a prompt with form data - CHANGED FROM PRIVATE TO PUBLIC
     * 
     * @param int $prompt_id The prompt ID
     * @param array $form_data The form data
     * @param int $entry_id The entry ID
     * @param object $form The form object
     * @return bool Success status
     */
    public function process_prompt($prompt_id, $form_data, $entry_id, $form) {
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
            return false;
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
                return false;
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
            return false;
        }

        // Handle the response according to settings
        $response_action = get_post_meta($prompt_id, '_sfaic_response_action', true);

        // Send email if configured
        if ($response_action === 'email') {
            $email_sent = $this->send_email_response($prompt_id, $entry_id, $form_data, $response_content, $provider);
        }

        return true;
    }

    /**
     * Format all form data into a structured text for AI
     *
     * @param array $form_data The form data
     * @param int $prompt_id The prompt ID (for getting form field labels)
     * @return string Formatted form data as text
     */
    private function format_all_form_data($form_data, $prompt_id) {
        $output = __('Here is the submitted form data:', 'chatgpt-fluent-connector') . "\n\n";

        // Get field labels if possible
        $field_labels = $this->get_form_field_labels($prompt_id);

        // Format each form field
        foreach ($form_data as $field_key => $field_value) {
            // Skip if field_key is not a scalar or starts with '_'
            if (!is_scalar($field_key) || strpos($field_key, '_') === 0) {
                continue;
            }

            // Get label if available, otherwise use field key
            $label = isset($field_labels[$field_key]) ? $field_labels[$field_key] : $field_key;

            // Format value
            if (is_array($field_value)) {
                $field_value = implode(', ', $field_value);
            } elseif (!is_scalar($field_value)) {
                // Skip non-scalar values
                continue;
            }

            // Add to output
            $output .= $label . ': ' . $field_value . "\n";
        }

        $output .= "\n" . __('Please analyze this information and provide a response.', 'chatgpt-fluent-connector');
        return $output;
    }

    /**
     * Get form field labels from a selected form
     *
     * @param int $prompt_id The prompt ID
     * @return array Associative array of field keys and labels
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
                        $field_label = !empty($field['settings']['label']) ? $field['settings']['label'] : $field_name;
                        $field_labels[$field_name] = $field_label;
                    }
                }
            }
        }

        return $field_labels;
    }

    /**
     * Send email with the AI response - Updated with customizable email content
     * 
     * @param int $prompt_id The prompt ID
     * @param int $entry_id The submission entry ID
     * @param array $form_data The submitted form data
     * @param string $ai_response The AI response
     * @param string $provider The AI provider used
     * @return bool True if email sent successfully, false otherwise
     */
    private function send_email_response($prompt_id, $entry_id, $form_data, $ai_response, $provider = 'openai') {
        error_log('SFAIC: Starting email send process for entry: ' . $entry_id);
        
        // Get email settings
        $email_to = get_post_meta($prompt_id, '_sfaic_email_to', true);
        $email_subject = get_post_meta($prompt_id, '_sfaic_email_subject', true);
        $email_to_user = get_post_meta($prompt_id, '_sfaic_email_to_user', true);
        $email_content_template = get_post_meta($prompt_id, '_sfaic_email_content_template', true);
        $email_include_form_data = get_post_meta($prompt_id, '_sfaic_email_include_form_data', true);
        $admin_email_enabled = get_post_meta($prompt_id, '_sfaic_admin_email_enabled', true);

        $recipient_email = '';

        // First try to find an email field in the form if email_to_user is enabled
        if ($email_to_user == '1') {
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
        
        error_log('SFAIC: Email recipients: ' . implode(', ', $all_recipients));

        // Set default subject if empty
        if (empty($email_subject)) {
            $email_subject = __('Response for Your Form Submission', 'chatgpt-fluent-connector');
        }

        // Set default email content template if empty
        if (empty($email_content_template)) {
            $email_content_template = '<h2>Thank you for your submission!</h2>
        <p>We have received your form submission and generated the following response:</p>
        <div style="background-color: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0;">
        {ai_response}
        </div>
        <p>Best regards,<br>    
        {site_name}</p>';
        }

        // Get form info
        $form_title = '';
        if (function_exists('wpFluent')) {
            $form_id = get_post_meta($prompt_id, '_sfaic_fluent_form_id', true);
            $form = wpFluent()->table('fluentform_forms')->find($form_id);
            if ($form) {
                $form_title = $form->title;
            }
        }

        // Prepare placeholders for replacement
        $placeholders = array(
            '{ai_response}' => $ai_response,
            '{site_name}' => esc_html(get_bloginfo('name')),
            '{site_url}' => esc_url(get_site_url()),
            '{date}' => date_i18n(get_option('date_format')),
            '{time}' => date_i18n(get_option('time_format')),
            '{datetime}' => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            '{form_title}' => esc_html($form_title),
            '{entry_id}' => esc_html($entry_id),
            '{provider}' => esc_html(($provider === 'gemini') ? 'Google Gemini' : (($provider === 'claude') ? 'Anthropic Claude' : 'ChatGPT'))
        );

        // Add form field placeholders with proper HTML formatting
        foreach ($form_data as $field_key => $field_value) {
            if (is_scalar($field_key)) {
                if (is_array($field_value)) {
                    $field_value = implode(', ', $field_value);
                } elseif (!is_scalar($field_value)) {
                    continue;
                }
                // Ensure HTML entities are properly encoded for form field values
                $placeholders['{' . $field_key . '}'] = esc_html($field_value);
            }
        }

        // Replace placeholders in subject
        $email_subject = str_replace(array_keys($placeholders), array_values($placeholders), $email_subject);

        // Process the email content template
        $email_content = str_replace(array_keys($placeholders), array_values($placeholders), $email_content_template);

        // Add form data table if enabled
        if ($email_include_form_data == '1') {
            $form_data_html = $this->generate_form_data_table($form_data, $prompt_id);
            $email_content .= $form_data_html;
        }

        // Wrap email content in HTML structure with improved styling
        $final_email_content = '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . esc_html($email_subject) . '</title>
            <style type="text/css">
                body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
                table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
                img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
                table { border-collapse: collapse !important; }
                body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; }

                @media screen and (max-width: 600px) {
                    .container { width: 100% !important; max-width: 100% !important; }
                }

                body {
                    font-family: Arial, Helvetica, sans-serif;
                    font-size: 16px;
                    line-height: 1.6;
                    color: #333333;
                    background-color: #f4f4f4;
                    margin: 0;
                    padding: 0;
                    -webkit-font-smoothing: antialiased;
                    -moz-osx-font-smoothing: grayscale;
                }

                .wrapper {
                    width: 100%;
                    table-layout: fixed;
                    background-color: #f4f4f4;
                    padding: 40px 0;
                }

                .container {
                    background-color: #ffffff;
                    max-width: 100%;
                    margin: 0 auto;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    overflow: hidden;
                }

                .content {
                    padding: 30px;
                }

                h1, h2, h3, h4, h5, h6 {
                    margin: 0 0 15px 0;
                    padding: 0;
                    font-weight: bold;
                    line-height: 1.4;
                }

                h2 {
                    font-size: 24px;
                    color: #333333;
                }

                p {
                    margin: 0 0 15px 0;
                    padding: 0;
                }

                .ai-response {
                    background-color: #f5f5f5;
                    padding: 20px;
                    border-radius: 5px;
                    margin: 20px 0;
                    border-left: 4px solid #0073aa;
                }

                .form-data-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 30px;
                }

                .form-data-table th,
                .form-data-table td {
                    border: 1px solid #dddddd;
                    padding: 12px;
                    text-align: left;
                }

                .form-data-table th {
                    background-color: #f5f5f5;
                    font-weight: bold;
                }

                .form-data-table tr:nth-child(even) {
                    background-color: #f9f9f9;
                }

                .pdf-notice {
                    background-color: #f0f8ff;
                    border-left: 4px solid #007cba;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 0 3px 3px 0;
                }

                .footer {
                    background-color: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    font-size: 14px;
                    color: #666666;
                }
            </style>
        </head>
        <body>
            <div class="wrapper">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" class="container">
                    <tr>
                        <td>
                            <div class="content">
                                ' . $email_content . '
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </body>
        </html>';

        // Set email headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );

        // Check if PDF should be attached
        $attachments = array();
        $pdf_attach_to_email = get_post_meta($prompt_id, '_sfaic_pdf_attach_to_email', true);

        if ($pdf_attach_to_email == '1' && isset(sfaic_main()->pdf_generator)) {
            error_log('SFAIC: Checking for PDF attachment');
            $pdf_path = sfaic_main()->pdf_generator->get_pdf_attachment($entry_id);
            if ($pdf_path) {
                $attachments[] = $pdf_path;
                error_log('SFAIC: PDF attachment found: ' . $pdf_path);

                // Add note about PDF attachment to email
                $final_email_content = str_replace(
                        '</div>
                </td>
            </tr>',
                        '<div class="pdf-notice">
                        <p><strong>📄 PDF Report:</strong> A detailed PDF report has been attached to this email.</p>
                    </div>
                    </div>
                </td>
            </tr>',
                        $final_email_content
                );
            } else {
                error_log('SFAIC: No PDF attachment found');
            }
        }

        // Send the user email
        $user_email_sent = false;
        foreach ($all_recipients as $recipient) {
            error_log('SFAIC: Sending email to: ' . $recipient);
            $sent = wp_mail($recipient, $email_subject, $final_email_content, $headers, $attachments);
            if ($sent) {
                $user_email_sent = true;
                error_log('SFAIC: Email sent successfully to: ' . $recipient);
            } else {
                error_log('SFAIC: Failed to send email to: ' . $recipient);
            }
        }

        // Send admin email if enabled
        if ($admin_email_enabled == '1') {
            error_log('SFAIC: Sending admin email');
            $admin_email_sent = $this->send_admin_email($prompt_id, $entry_id, $form_data, $ai_response, $provider);

            // Return true if at least one email was sent successfully
            return $user_email_sent || $admin_email_sent;
        }

        return $user_email_sent;
    }

    /**
     * Generate form data table HTML
     */
    private function generate_form_data_table($form_data, $prompt_id) {
        $field_labels = $this->get_form_field_labels($prompt_id);

        $html = '
    <div style="margin-top: 40px; border-top: 2px solid #ddd; padding-top: 20px;">
        <h3 style="color: #333; margin-bottom: 15px;">Form Submission Details</h3>
        <table class="form-data-table">
            <thead>
                <tr>
                    <th>Field</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($form_data as $field_key => $field_value) {
            // Skip if field_key is not a scalar or starts with '_'
            if (!is_scalar($field_key) || strpos($field_key, '_') === 0) {
                continue;
            }

            // Get label if available, otherwise use field key
            $label = isset($field_labels[$field_key]) ? $field_labels[$field_key] : $field_key;

            // Format value
            if (is_array($field_value)) {
                $field_value = implode(', ', $field_value);
            } elseif (!is_scalar($field_value)) {
                continue;
            }

            $html .= '
                <tr>
                    <td><strong>' . esc_html($label) . '</strong></td>
                    <td>' . esc_html($field_value) . '</td>
                </tr>';
        }

        $html .= '
            </tbody>
        </table>
    </div>';

        return $html;
    }

    /**
     * Send admin notification email with all form data
     */
    private function send_admin_email($prompt_id, $entry_id, $form_data, $ai_response, $provider = 'openai') {
        // Get admin email settings
        $admin_email_to = get_post_meta($prompt_id, '_sfaic_admin_email_to', true);
        $admin_email_subject = get_post_meta($prompt_id, '_sfaic_admin_email_subject', true);

        // Default admin email if not set
        if (empty($admin_email_to)) {
            $admin_email_to = get_option('admin_email');
        }

        // Get form info
        $form_title = '';
        $form_id = get_post_meta($prompt_id, '_sfaic_fluent_form_id', true);
        if (function_exists('wpFluent') && !empty($form_id)) {
            $form = wpFluent()->table('fluentform_forms')->find($form_id);
            if ($form) {
                $form_title = $form->title;
            }
        }

        // Default subject if not set
        if (empty($admin_email_subject)) {
            $admin_email_subject = 'New Form Submission - {form_title}';
        }

        // Prepare placeholders
        $placeholders = array(
            '{form_title}' => $form_title,
            '{date}' => date_i18n(get_option('date_format')),
            '{time}' => date_i18n(get_option('time_format')),
            '{entry_id}' => $entry_id,
            '{site_name}' => get_bloginfo('name')
        );

        // Replace placeholders in subject
        $admin_email_subject = str_replace(array_keys($placeholders), array_values($placeholders), $admin_email_subject);

        // Get provider name
        switch ($provider) {
            case 'gemini':
                $provider_name = 'Google Gemini';
                break;
            case 'claude':
                $provider_name = 'Anthropic Claude';
                break;
            default:
                $provider_name = 'ChatGPT';
                break;
        }

        // Build admin email content with all form data
        $admin_email_content = '
        <html>
        <head>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    max-width: 900px;
                    margin: 0 auto;
                }
                .container {
                    padding: 20px;
                }
                .header {
                    padding: 20px;
                }
                .section {
                    padding: 20px;
                    border-radius: 5px;
                }
                .section h3 {
                    margin-top: 0;
                    color: #0073aa;
                    padding-bottom: 10px;
                }
                .form-data-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                }
                .form-data-table th,
                .form-data-table td {
                    padding: 12px;
                    text-align: left;
                }
                .form-data-table th {
                    font-weight: bold;
                    color: #333;
                }
                .ai-response {
                    padding: 20px;
                    margin-top: 15px;
                }
                .metadata {
                    padding: 15px;
                    border-radius: 5px;
                    margin-top: 20px;
                }
                .metadata p {
                    margin: 5px 0;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="section">
                    <h3>Response</h3>
                    <div class="ai-response">
                        ' . wp_kses_post($ai_response) . '
                    </div>
                </div>
                              
                <div class="section">
                    <h3>Form Submission Data</h3>
                    ' . $this->generate_form_data_table($form_data, $prompt_id) . '
                </div>

                <div class="metadata">
                    <p><strong>Submission Details:</strong></p>
                    <p>Entry ID: ' . esc_html($entry_id) . '</p>
                    <p>Form ID: ' . esc_html($form_id) . '</p>
                    <p>AI Provider: ' . esc_html($provider_name) . '</p>
                    <p>Prompt Used: ' . esc_html(get_the_title($prompt_id)) . '</p>
                </div>
            </div>
        </body>
        </html>';

        // Set email headers
        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Check if PDF should be attached
        $attachments = array();
        $pdf_attach_to_email = get_post_meta($prompt_id, '_sfaic_pdf_attach_to_email', true);

        if ($pdf_attach_to_email == '1' && isset(sfaic_main()->pdf_generator)) {
            $pdf_path = sfaic_main()->pdf_generator->get_pdf_attachment($entry_id);
            if ($pdf_path) {
                $attachments[] = $pdf_path;
            }
        }

        // Handle multiple admin recipients
        $admin_emails = array_map('trim', explode(',', $admin_email_to));
        $admin_emails = array_filter($admin_emails, 'is_email');

        if (empty($admin_emails)) {
            return false;
        }

        // Send to all admin recipients
        $success = true;
        foreach ($admin_emails as $admin_email) {
            $sent = wp_mail($admin_email, $admin_email_subject, $admin_email_content, $headers, $attachments);
            if (!$sent) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Clean and prepare HTML response for display
     * 
     * @param string|WP_Error $response The response from the API
     * @return string The cleaned HTML response or error message
     */
     private function clean_html_response($response) {
        // Basic HTML cleaning if needed
        return $response;
    }
}

// IMPORTANT: Register the AJAX handler for immediate async processing
//add_action('wp_ajax_nopriv_sfaic_process_immediate_async', array('SFAIC_Forms_Integration', 'handle_immediate_async_processing'));
//add_action('wp_ajax_sfaic_process_immediate_async', array('SFAIC_Forms_Integration', 'handle_immediate_async_processing'));
