<?php

/**
 * ChatGPT Fluent Forms Integration Class - Ultra-Fast Flag-Based Processing
 * 
 * Zero-delay processing using database flags and background cron
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Forms_Integration {

    const CRON_HOOK = 'sfaic_process_pending_forms';
    const PENDING_FLAG_KEY = '_sfaic_pending_processing';
    const PROCESSED_FLAG_KEY = '_sfaic_processed';

    /**
     * Constructor
     */
    public function __construct() {
        // Register hooks only once
        static $hooks_registered = false;
        
        if (!$hooks_registered) {
            // Ultra-minimal hook - just sets a flag
            add_action('fluentform/submission_inserted', array($this, 'flag_for_processing'), 10000, 3);
            
            // Background cron to process flagged entries
            add_action(self::CRON_HOOK, array($this, 'process_pending_submissions'));
            
            // Ensure cron is scheduled
            add_action('init', array($this, 'ensure_cron_scheduled'));
            
            // Add more frequent check on admin requests
            add_action('admin_init', array($this, 'maybe_process_pending'));
            
            $hooks_registered = true;
            error_log('SFAIC: Ultra-fast flag-based integration initialized');
        }
    }
    
    /**
     * ULTRA-MINIMAL: Just flag the submission for processing
     * This should take less than 0.001 seconds
     */
    public function flag_for_processing($entry_id, $form_data, $form) {
        // Single database write - absolute minimum
        update_post_meta($entry_id, self::PENDING_FLAG_KEY, array(
            'form_id' => $form->id,
            'time' => time()
        ));
        
        // That's it! No scheduling, no processing, just flag and return
        return;
    }
    
    /**
     * Ensure background cron is scheduled
     */
    public function ensure_cron_scheduled() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 10, 'sfaic_every_10_seconds', self::CRON_HOOK);
        }
        
        // Add custom schedule if not exists
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
    }
    
    /**
     * Add custom cron schedule
     */
    public function add_cron_schedule($schedules) {
        if (!isset($schedules['sfaic_every_10_seconds'])) {
            $schedules['sfaic_every_10_seconds'] = array(
                'interval' => 10,
                'display' => __('Every 10 Seconds', 'chatgpt-fluent-connector')
            );
        }
        return $schedules;
    }
    
    /**
     * Process pending submissions on admin requests as backup
     */
    public function maybe_process_pending() {
        // Only run occasionally to avoid performance impact
        $last_check = get_transient('sfaic_last_admin_check');
        if ($last_check) {
            return;
        }
        
        set_transient('sfaic_last_admin_check', true, 5); // Check every 5 seconds max
        
        // Process in background
        wp_schedule_single_event(time(), self::CRON_HOOK);
    }
    
    /**
     * Process all pending submissions (runs via cron)
     */
    public function process_pending_submissions() {
        global $wpdb;
        
        // Find all entries with pending flag
        $pending_entries = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             LIMIT 10",
            self::PENDING_FLAG_KEY
        ));
        
        if (empty($pending_entries)) {
            return;
        }
        
        error_log('SFAIC: Processing ' . count($pending_entries) . ' pending submissions');
        
        foreach ($pending_entries as $entry) {
            $this->process_single_entry($entry->post_id, maybe_unserialize($entry->meta_value));
        }
    }
    
    /**
     * Process a single entry
     */
    private function process_single_entry($entry_id, $meta_data) {
        // Remove pending flag immediately to prevent reprocessing
        delete_post_meta($entry_id, self::PENDING_FLAG_KEY);
        
        // Mark as processing
        update_post_meta($entry_id, self::PROCESSED_FLAG_KEY, 'processing');
        
        error_log('SFAIC: Processing entry ' . $entry_id . ' for form ' . $meta_data['form_id']);
        
        // Get form from database
        if (!function_exists('wpFluent')) {
            error_log('SFAIC: wpFluent not available');
            return;
        }
        
        $form = wpFluent()->table('fluentform_forms')
            ->where('id', $meta_data['form_id'])
            ->first();
            
        if (!$form) {
            error_log('SFAIC: Form not found: ' . $meta_data['form_id']);
            return;
        }
        
        // Get submission data from database
        $submission = wpFluent()->table('fluentform_submissions')
            ->where('id', $entry_id)
            ->first();
            
        if (!$submission || empty($submission->response)) {
            error_log('SFAIC: Submission not found: ' . $entry_id);
            return;
        }
        
        $form_data = json_decode($submission->response, true);
        
        // Add tracking data if available from source URL
        if (!empty($submission->source_url)) {
            $this->add_tracking_data($form_data, $submission->source_url);
        }
        
        // Find all prompts for this form
        $prompts = get_posts(array(
            'post_type' => 'sfaic_prompt',
            'meta_query' => array(
                array(
                    'key' => '_sfaic_fluent_form_id',
                    'value' => $meta_data['form_id'],
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1
        ));
        
        if (empty($prompts)) {
            error_log('SFAIC: No prompts found for form ID: ' . $meta_data['form_id']);
            update_post_meta($entry_id, self::PROCESSED_FLAG_KEY, 'no_prompts');
            return;
        }
        
        // Process each prompt
        foreach ($prompts as $prompt) {
            $this->process_prompt_for_entry($prompt->ID, $entry_id, $form_data, $form);
        }
        
        // Mark as completed
        update_post_meta($entry_id, self::PROCESSED_FLAG_KEY, 'completed');
        update_post_meta($entry_id, '_sfaic_processed_at', current_time('mysql'));
        
        error_log('SFAIC: Completed processing entry: ' . $entry_id);
    }
    
    /**
     * Add tracking data from URL parameters
     */
    private function add_tracking_data(&$form_data, $source_url) {
        $url_parts = parse_url($source_url);
        if (!isset($url_parts['query'])) {
            return;
        }
        
        parse_str($url_parts['query'], $query_params);
        
        // Check all prompts for tracking parameters
        $prompts = get_posts(array(
            'post_type' => 'sfaic_prompt',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        
        foreach ($prompts as $prompt_id) {
            $tracking_param = get_post_meta($prompt_id, '_sfaic_tracking_get_param', true);
            if (!empty($tracking_param) && isset($query_params[$tracking_param])) {
                $form_data['_tracking_source'] = sanitize_text_field($query_params[$tracking_param]);
                error_log('SFAIC: Added tracking source: ' . $form_data['_tracking_source']);
                break;
            }
        }
    }
    
    /**
     * Process a prompt for an entry
     */
    private function process_prompt_for_entry($prompt_id, $entry_id, $form_data, $form) {
        error_log('SFAIC: Processing prompt ' . $prompt_id . ' for entry ' . $entry_id);
        
        // Check if background processing is enabled
        $enable_background = get_post_meta($prompt_id, '_sfaic_enable_background_processing', true);
        if ($enable_background === '') {
            $enable_background = '1';
        }
        
        if ($enable_background === '1' && isset(sfaic_main()->background_job_manager)) {
            // Use background job manager
            $delay = get_post_meta($prompt_id, '_sfaic_background_processing_delay', true) ?: 0;
            $priority = get_post_meta($prompt_id, '_sfaic_job_priority', true) ?: 0;
            
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
                error_log('SFAIC: Scheduled background job ' . $job_id);
            } else {
                // Fallback to immediate processing
                $this->process_prompt($prompt_id, $form_data, $entry_id, $form);
            }
        } else {
            // Process immediately
            $this->process_prompt($prompt_id, $form_data, $entry_id, $form);
        }
    }
    
    /**
     * Process a prompt with form data - PUBLIC for background job manager
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

            // TRIGGER PDF GENERATION ACTION
            if (isset(sfaic_main()->pdf_generator)) {
                do_action('sfaic_after_ai_response_processed', $response_content, $prompt_id, $entry_id, $form_data, $form);
            }
        }

        // Save the response if logging is enabled
        $log_responses = get_post_meta($prompt_id, '_sfaic_log_responses', true);
        if ($log_responses == '1' || $status === 'error') {
            if (isset(sfaic_main()->response_logger)) {
                $result = sfaic_main()->response_logger->log_response(
                    $prompt_id,
                    $entry_id,
                    $form->id,
                    $complete_prompt,
                    $response_content,
                    $provider,
                    $model,
                    $execution_time,
                    $status,
                    $error_message,
                    $token_usage,
                    '',
                    '',
                    '',
                    $form_data
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
     * Send email with the AI response
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
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            </style>
        </head>
        <body>
            <div class="container">
                ' . $email_content . '
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
        <table class="form-data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="border: 1px solid #ddd; padding: 10px; background: #f5f5f5;">Field</th>
                    <th style="border: 1px solid #ddd; padding: 10px; background: #f5f5f5;">Value</th>
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
                    <td style="border: 1px solid #ddd; padding: 10px;"><strong>' . esc_html($label) . '</strong></td>
                    <td style="border: 1px solid #ddd; padding: 10px;">' . esc_html($field_value) . '</td>
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
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { padding: 20px; }
                .section { padding: 20px; border-radius: 5px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
                th { background: #f5f5f5; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="section">
                    <h3>Response</h3>
                    <div>' . wp_kses_post($ai_response) . '</div>
                </div>
                              
                <div class="section">
                    <h3>Form Submission Data</h3>
                    ' . $this->generate_form_data_table($form_data, $prompt_id) . '
                </div>

                <div class="section">
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
     */
    private function clean_html_response($response) {
        // Basic HTML cleaning if needed
        return $response;
    }
}