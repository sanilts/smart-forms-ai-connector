<?php
/**
 * Enhanced ChatGPT Fluent Forms Integration Class
 * 
 * COMPLETELY NON-BLOCKING approach that starts background processing 
 * AFTER form submission is complete and user sees success/redirect
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
        // Hook into Fluent Forms submission with VERY LOW priority (runs last)
        add_action('fluentform/submission_inserted', array($this, 'handle_form_submission'), 999, 3);
        
        // Add AJAX handlers for completely non-blocking processing
        add_action('wp_ajax_nopriv_sfaic_trigger_background_processing', array($this, 'ajax_trigger_background_processing'));
        add_action('wp_ajax_sfaic_trigger_background_processing', array($this, 'ajax_trigger_background_processing'));
        
        // Hook to run after all form processing is complete
        add_action('fluentform/after_form_submission_api_response', array($this, 'trigger_delayed_processing'), 10, 3);
        add_action('fluentform/form_submitted', array($this, 'trigger_delayed_processing_alternative'), 10, 2);
        
        error_log('SFAIC: Enhanced Forms integration hooks registered');
    }
    
    /**
     * Handle form submission - ONLY TRIGGER DELAYED PROCESSING
     * This runs with very low priority to ensure it happens AFTER everything else
     * 
     * @param int $entry_id The submission entry ID
     * @param array $form_data The submitted form data
     * @param object $form The form object
     */
    public function handle_form_submission($insertId, $formData, $form) {
        error_log('SFAIC: Form submission detected - Entry ID: ' . $insertId . ', Form ID: ' . $form->id);

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

        error_log('SFAIC: Found ' . count($prompts) . ' prompt(s) for form ID: ' . $form->id);

        // Store submission data for delayed processing
        $submission_data = array(
            'entry_id' => $insertId,
            'form_data' => $formData,
            'form_id' => $form->id,
            'form_title' => $form->title,
            'prompts' => array()
        );

        foreach ($prompts as $prompt) {
            $enable_background_processing = get_post_meta($prompt->ID, '_sfaic_enable_background_processing', true);
            
            // Default to enabled if not set
            if ($enable_background_processing === '') {
                $enable_background_processing = '1';
            }

            $submission_data['prompts'][] = array(
                'prompt_id' => $prompt->ID,
                'background_enabled' => ($enable_background_processing === '1')
            );
        }

        // Schedule COMPLETELY non-blocking processing using wp_remote_post
        $this->schedule_non_blocking_processing($submission_data);
        
        error_log('SFAIC: Scheduled non-blocking processing for entry ' . $insertId);
        
        // CRITICAL: Return immediately - no further processing here
    }
    
    /**
     * Alternative hook for delayed processing (runs after form APIs)
     */
    public function trigger_delayed_processing($responseData, $formData, $form) {
        error_log('SFAIC: Alternative delayed processing trigger activated');
        // This can serve as a backup if the main hook doesn't work properly
    }
    
    /**
     * Another alternative hook
     */
    public function trigger_delayed_processing_alternative($responseData, $form) {
        error_log('SFAIC: Alternative processing hook triggered');
    }
    
    /**
     * Schedule completely non-blocking processing using wp_remote_post
     */
    private function schedule_non_blocking_processing($submission_data) {
        // Add a delay to ensure the form response is sent first
        $delay = 2; // 2 seconds delay
        
        // Create a nonce for security
        $nonce = wp_create_nonce('sfaic_background_trigger_' . $submission_data['entry_id']);
        
        // Store submission data temporarily (will be cleaned up after processing)
        $temp_key = 'sfaic_temp_submission_' . $submission_data['entry_id'] . '_' . time();
        set_transient($temp_key, $submission_data, 300); // 5 minutes expiry
        
        // Use wp_remote_post to trigger processing in a completely separate request
        wp_remote_post(admin_url('admin-ajax.php'), array(
            'timeout' => 0.01,     // Very short timeout - fire and forget
            'blocking' => false,   // Non-blocking request
            'body' => array(
                'action' => 'sfaic_trigger_background_processing',
                'temp_key' => $temp_key,
                'nonce' => $nonce,
                'delay' => $delay
            )
        ));
        
        error_log('SFAIC: Triggered non-blocking request for entry ' . $submission_data['entry_id']);
    }
    
    /**
     * AJAX handler for non-blocking background processing trigger
     */
    public function ajax_trigger_background_processing() {
        // Get and validate parameters
        $temp_key = sanitize_text_field($_POST['temp_key'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $delay = intval($_POST['delay'] ?? 0);
        
        if (empty($temp_key)) {
            wp_die('Invalid temp key');
        }
        
        // Retrieve submission data
        $submission_data = get_transient($temp_key);
        if (!$submission_data) {
            wp_die('Submission data not found or expired');
        }
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'sfaic_background_trigger_' . $submission_data['entry_id'])) {
            wp_die('Invalid nonce');
        }
        
        // Clean up the temporary data
        delete_transient($temp_key);
        
        // Add the requested delay to ensure form response is complete
        if ($delay > 0) {
            sleep($delay);
        }
        
        error_log('SFAIC: Starting delayed background processing for entry ' . $submission_data['entry_id']);
        
        // Process each prompt
        foreach ($submission_data['prompts'] as $prompt_data) {
            if ($prompt_data['background_enabled']) {
                $this->schedule_background_job(
                    $prompt_data['prompt_id'],
                    $submission_data['entry_id'],
                    $submission_data['form_data'],
                    $submission_data['form_id'],
                    $submission_data['form_title']
                );
            } else {
                $this->process_immediate_job(
                    $prompt_data['prompt_id'],
                    $submission_data['entry_id'],
                    $submission_data['form_data'],
                    $submission_data['form_id'],
                    $submission_data['form_title']
                );
            }
        }
        
        error_log('SFAIC: Completed delayed processing setup for entry ' . $submission_data['entry_id']);
        
        wp_die('OK');
    }
    
    /**
     * Schedule background job using the background job manager
     */
    private function schedule_background_job($prompt_id, $entry_id, $form_data, $form_id, $form_title) {
        if (!isset(sfaic_main()->background_job_manager)) {
            error_log('SFAIC: Background job manager not available, processing immediately');
            $this->process_immediate_job($prompt_id, $entry_id, $form_data, $form_id, $form_title);
            return;
        }
        
        // Get prompt-specific settings
        $delay = get_post_meta($prompt_id, '_sfaic_background_processing_delay', true);
        if (empty($delay)) {
            $delay = 5;
        }
        
        $priority = get_post_meta($prompt_id, '_sfaic_job_priority', true);
        if (empty($priority)) {
            $priority = 0;
        }
        
        // Create form object for the job
        $form = (object) array(
            'id' => $form_id,
            'title' => $form_title
        );
        
        // Schedule the job
        $job_id = sfaic_main()->background_job_manager->schedule_job(
            'ai_form_processing',
            $prompt_id,
            $form_id,
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
            error_log('SFAIC: Failed to schedule background job, processing immediately');
            $this->process_immediate_job($prompt_id, $entry_id, $form_data, $form_id, $form_title);
        }
    }
    
    /**
     * Process job immediately (for prompts with immediate processing enabled)
     */
    private function process_immediate_job($prompt_id, $entry_id, $form_data, $form_id, $form_title) {
        error_log('SFAIC: Processing immediate job for prompt: ' . $prompt_id);
        
        try {
            // Create form object
            $form = (object) array(
                'id' => $form_id,
                'title' => $form_title
            );
            
            // Process the prompt directly
            $result = $this->process_prompt($prompt_id, $form_data, $entry_id, $form);
            
            if ($result) {
                error_log('SFAIC: Immediate processing completed successfully for prompt: ' . $prompt_id);
            } else {
                error_log('SFAIC: Immediate processing failed for prompt: ' . $prompt_id);
            }
        } catch (Exception $e) {
            error_log('SFAIC: Immediate processing exception for prompt ' . $prompt_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Process a prompt with form data - PUBLIC method for background job manager
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
                    $token_usage
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

        $recipient_email = '';

        // Try to find an email field in the form if email_to_user is enabled
        if ($email_to_user == '1') {
            $common_email_fields = array('email', 'your_email', 'user_email', 'email_address', 'customer_email');

            foreach ($form_data as $field_key => $field_value) {
                if ((is_string($field_value) && filter_var($field_value, FILTER_VALIDATE_EMAIL)) &&
                        (strpos(strtolower($field_key), 'email') !== false || in_array(strtolower($field_key), $common_email_fields))) {
                    $recipient_email = $field_value;
                    break;
                }
            }
        }

        // Process additional recipients
        $additional_recipients = array();
        if (!empty($email_to)) {
            $additional_emails = explode(',', $email_to);
            foreach ($additional_emails as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $additional_recipients[] = $email;
                }
            }
        }

        // Combine recipients
        $all_recipients = array();
        if (!empty($recipient_email)) {
            $all_recipients[] = $recipient_email;
        }
        if (!empty($additional_recipients)) {
            $all_recipients = array_merge($all_recipients, $additional_recipients);
        }
        if (empty($all_recipients)) {
            $all_recipients[] = get_option('admin_email');
        }

        $all_recipients = array_unique($all_recipients);

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

        // Prepare placeholders for replacement
        $placeholders = array(
            '{ai_response}' => $ai_response,
            '{site_name}' => esc_html(get_bloginfo('name')),
            '{site_url}' => esc_url(get_site_url()),
            '{date}' => date_i18n(get_option('date_format')),
            '{time}' => date_i18n(get_option('time_format')),
            '{entry_id}' => esc_html($entry_id),
        );

        // Replace placeholders in subject and content
        $email_subject = str_replace(array_keys($placeholders), array_values($placeholders), $email_subject);
        $email_content = str_replace(array_keys($placeholders), array_values($placeholders), $email_content_template);

        // Set email headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );

        // Send emails
        $success = true;
        foreach ($all_recipients as $recipient) {
            $sent = wp_mail($recipient, $email_subject, $email_content, $headers);
            if (!$sent) {
                error_log('SFAIC: Failed to send email to: ' . $recipient);
                $success = false;
            } else {
                error_log('SFAIC: Email sent successfully to: ' . $recipient);
            }
        }

        return $success;
    }

    /**
     * Clean HTML response
     */
    private function clean_html_response($response) {
        return $response;
    }
}