<?php

/**
 * ChatGPT Fluent Forms Integration Class - FIXED VERSION WITH DUPLICATE PREVENTION
 * 
 * Complete replacement for class-forms-integration.php
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
        // CRITICAL: NO hooks during form submission!
        // Instead, we use wp_loaded to check for queued submissions
        add_action('wp_loaded', array($this, 'process_queued_submissions'), 1);
        
        // Use a very late hook to queue submissions without interfering
        add_action('wp_footer', array($this, 'queue_recent_submissions'), 999);
        add_action('admin_footer', array($this, 'queue_recent_submissions'), 999);
        
        // AJAX handler for immediate async processing
        add_action('wp_ajax_sfaic_process_immediate_async', array($this, 'handle_immediate_async_processing'));
        add_action('wp_ajax_nopriv_sfaic_process_immediate_async', array($this, 'handle_immediate_async_processing'));
        
        // Cron job for processing queued items
        add_action('sfaic_process_queued_items', array($this, 'process_cron_queue'));
        
        // Schedule recurring processing
        if (!wp_next_scheduled('sfaic_process_queued_items')) {
            wp_schedule_event(time() + 60, 'every_30_seconds', 'sfaic_process_queued_items');
        }
        
        // Add frontend duplicate prevention
        add_action('wp_footer', array($this, 'add_duplicate_prevention_js'), 1000);
        
        error_log('SFAIC: Forms integration initialized with duplicate prevention');
    }
    
    /**
     * Create a fingerprint of form submission for duplicate detection
     */
    private function create_submission_fingerprint($form_data, $form_id) {
        if (!is_array($form_data)) {
            return '';
        }
        
        // Extract key fields for fingerprinting (excluding timestamps and tokens)
        $key_data = array();
        foreach ($form_data as $field => $value) {
            // Skip internal fields, timestamps, and tokens
            if (strpos($field, '_') === 0 || 
                in_array(strtolower($field), array('timestamp', 'token', 'nonce', '_token', '_nonce', 'created_at', 'updated_at'))) {
                continue;
            }
            
            // Convert arrays to string for consistent hashing
            if (is_array($value)) {
                $value = serialize($value);
            }
            
            $key_data[$field] = $value;
        }
        
        // Sort for consistency
        ksort($key_data);
        
        // Create fingerprint with form ID
        return md5($form_id . '_' . serialize($key_data));
    }
    
    /**
     * Queue recent submissions without any hooks during submission
     * This runs in footer AFTER redirects have happened
     */
    public function queue_recent_submissions() {
        // Only run once per page load - CRITICAL CHECK
        static $has_run = false;
        if ($has_run) {
            return;
        }
        $has_run = true;
        
        // Add additional check to prevent multiple executions
        $execution_key = 'sfaic_queue_execution_' . wp_get_current_user()->ID . '_' . date('YmdHis');
        if (get_transient($execution_key)) {
            return;
        }
        set_transient($execution_key, true, 5);
        
        // Check if we have any recent submissions to process
        $recent_submissions = $this->get_recent_unprocessed_submissions();
        
        if (empty($recent_submissions)) {
            return;
        }
        
        error_log('SFAIC: Found ' . count($recent_submissions) . ' recent unprocessed submissions');
        
        foreach ($recent_submissions as $submission) {
            $this->queue_submission_for_processing($submission);
        }
    }
    
    /**
     * Get recent submissions that haven't been processed yet
     */
    private function get_recent_unprocessed_submissions() {
        if (!function_exists('wpFluent')) {
            return array();
        }
        
        // Get submissions from the last 2 minutes (reduced from 5)
        $recent_submissions = wpFluent()->table('fluentform_submissions')
            ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-2 minutes')))
            ->orderBy('id', 'DESC')
            ->limit(10) // Limit to prevent overload
            ->get();
            
        if (empty($recent_submissions)) {
            return array();
        }
        
        $unprocessed = array();
        
        foreach ($recent_submissions as $submission) {
            // Check if this submission has already been processed
            $processed_flag = get_transient('sfaic_processed_' . $submission->id);
            if ($processed_flag) {
                continue; // Already processed
            }
            
            // Check if this is a duplicate
            $duplicate_of = get_transient('sfaic_duplicate_' . $submission->id);
            if ($duplicate_of) {
                continue; // This is a duplicate
            }
            
            // Check if we have prompts for this form
            $prompts = get_posts(array(
                'post_type' => 'sfaic_prompt',
                'meta_query' => array(
                    array(
                        'key' => '_sfaic_fluent_form_id',
                        'value' => $submission->form_id,
                        'compare' => '='
                    )
                ),
                'posts_per_page' => 1, // Just check if any exist
                'fields' => 'ids'
            ));
            
            if (!empty($prompts)) {
                $unprocessed[] = $submission;
            }
        }
        
        return $unprocessed;
    }
    
    /**
     * Queue a submission for processing using transients with duplicate prevention
     */
    private function queue_submission_for_processing($submission) {
        // Get form object
        if (!function_exists('wpFluent')) {
            return;
        }
        
        $form = wpFluent()->table('fluentform_forms')
            ->where('id', $submission->form_id)
            ->first();
            
        if (!$form) {
            return;
        }
        
        // Decode form data
        $form_data = json_decode($submission->response, true);
        if (!is_array($form_data)) {
            return;
        }
        
        // Create fingerprint for duplicate detection
        $fingerprint = $this->create_submission_fingerprint($form_data, $submission->form_id);
        $fingerprint_key = 'sfaic_fp_' . $fingerprint;
        
        // Check if we've seen this exact submission data recently
        $existing_id = get_transient($fingerprint_key);
        
        if ($existing_id && $existing_id != $submission->id) {
            // This is a duplicate submission
            error_log('SFAIC: Duplicate detected - Submission ' . $submission->id . ' is duplicate of ' . $existing_id);
            
            // Mark as duplicate and processed
            set_transient('sfaic_duplicate_' . $submission->id, $existing_id, 300);
            set_transient('sfaic_processed_' . $submission->id, true, 600);
            
            return; // Don't process duplicates
        }
        
        // Not a duplicate, store fingerprint
        set_transient($fingerprint_key, $submission->id, 60); // 60 second window for duplicates
        
        // Get prompts for this form
        $prompts = get_posts(array(
            'post_type' => 'sfaic_prompt',
            'meta_query' => array(
                array(
                    'key' => '_sfaic_fluent_form_id',
                    'value' => $submission->form_id,
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1
        ));
        
        $queued_count = 0;
        
        foreach ($prompts as $prompt) {
            // Check if already queued for this prompt
            $queue_check_key = 'sfaic_queued_' . $submission->id . '_' . $prompt->ID;
            
            if (get_transient($queue_check_key)) {
                error_log('SFAIC: Already queued - Submission ' . $submission->id . ' for prompt ' . $prompt->ID);
                continue;
            }
            
            $queue_item = array(
                'prompt_id' => $prompt->ID,
                'entry_id' => $submission->id,
                'form_id' => $submission->form_id,
                'form_data' => $form_data,
                'form_title' => $form->title,
                'timestamp' => current_time('mysql'),
                'background_enabled' => get_post_meta($prompt->ID, '_sfaic_enable_background_processing', true)
            );
            
            // Store in transient queue with unique key
            $queue_key = 'sfaic_queue_' . $submission->id . '_' . $prompt->ID . '_' . uniqid();
            set_transient($queue_key, $queue_item, 300); // 5 minutes expiry
            
            // Mark as queued
            set_transient($queue_check_key, true, 300);
            
            $queued_count++;
            error_log('SFAIC: Queued submission ' . $submission->id . ' for prompt ' . $prompt->ID);
        }
        
        // Mark submission as processed if we queued any items
        if ($queued_count > 0) {
            set_transient('sfaic_processed_' . $submission->id, true, 600); // 10 minutes
        }
    }
    
    /**
     * Process queued submissions from transients
     */
    public function process_queued_submissions() {
        // Prevent concurrent processing
        $lock_key = 'sfaic_processing_lock';
        if (get_transient($lock_key)) {
            return; // Another process is already running
        }
        
        // Set processing lock for 30 seconds
        set_transient($lock_key, true, 30);
        
        global $wpdb;
        
        // Get all queued items from transients
        $queue_transients = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_sfaic_queue_%' 
             ORDER BY option_id ASC 
             LIMIT 5"
        );
        
        if (empty($queue_transients)) {
            delete_transient($lock_key);
            return;
        }
        
        error_log('SFAIC: Processing ' . count($queue_transients) . ' queued items');
        
        foreach ($queue_transients as $transient_name) {
            $queue_key = str_replace('_transient_', '', $transient_name);
            $queue_item = get_transient($queue_key);
            
            if (!$queue_item || !is_array($queue_item)) {
                delete_transient($queue_key);
                continue;
            }
            
            // Process this item
            $this->process_queue_item($queue_item);
            
            // Remove from queue
            delete_transient($queue_key);
        }
        
        // Release lock
        delete_transient($lock_key);
    }
    
    /**
     * Process individual queue item with duplicate check
     */
    private function process_queue_item($queue_item) {
        $prompt_id = $queue_item['prompt_id'];
        $entry_id = $queue_item['entry_id'];
        $form_data = $queue_item['form_data'];
        $background_enabled = $queue_item['background_enabled'];
        
        // Final processing check to prevent duplicates
        $processing_key = 'sfaic_processing_' . $entry_id . '_' . $prompt_id;
        
        if (get_transient($processing_key)) {
            error_log('SFAIC: Already processing entry ' . $entry_id . ' for prompt ' . $prompt_id);
            return;
        }
        
        // Set processing lock
        set_transient($processing_key, true, 300); // 5 minute lock
        
        error_log('SFAIC: Processing queue item - Entry: ' . $entry_id . ', Prompt: ' . $prompt_id);
        
        if ($background_enabled === '1') {
            // Check if background job already exists
            if ($this->background_job_exists($prompt_id, $entry_id)) {
                error_log('SFAIC: Background job already exists for entry ' . $entry_id);
                return;
            }
            
            // Use background job system
            $this->schedule_background_processing($queue_item);
        } else {
            // Use immediate async processing
            $this->schedule_immediate_async_processing_from_queue($queue_item);
        }
    }
    
    /**
     * Check if background job already exists
     */
    private function background_job_exists($prompt_id, $entry_id) {
        if (!isset(sfaic_main()->background_job_manager)) {
            return false;
        }
        
        global $wpdb;
        $jobs_table = $wpdb->prefix . 'sfaic_background_jobs';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$jobs_table}'") !== $jobs_table) {
            return false;
        }
        
        $existing_job = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$jobs_table} 
             WHERE prompt_id = %d 
             AND entry_id = %d 
             AND status IN ('pending', 'processing', 'retry')",
            $prompt_id,
            $entry_id
        ));
        
        return $existing_job > 0;
    }
    
    /**
     * Schedule background processing from queue item
     */
    private function schedule_background_processing($queue_item) {
        if (!isset(sfaic_main()->background_job_manager)) {
            error_log('SFAIC: Background job manager not available, using async processing');
            $this->schedule_immediate_async_processing_from_queue($queue_item);
            return;
        }
        
        $prompt_id = $queue_item['prompt_id'];
        $delay = get_post_meta($prompt_id, '_sfaic_background_processing_delay', true);
        if (empty($delay)) {
            $delay = 5;
        }
        
        $priority = get_post_meta($prompt_id, '_sfaic_job_priority', true);
        if (empty($priority)) {
            $priority = 0;
        }
        
        // Schedule the background job
        $job_id = sfaic_main()->background_job_manager->schedule_job(
            'ai_form_processing',
            $queue_item['prompt_id'],
            $queue_item['form_id'],
            $queue_item['entry_id'],
            array(
                'form_data' => $queue_item['form_data'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'timestamp' => $queue_item['timestamp']
            ),
            intval($delay),
            intval($priority)
        );
        
        if ($job_id) {
            error_log('SFAIC: Scheduled background job ID: ' . $job_id);
        } else {
            error_log('SFAIC: Failed to schedule background job, using async processing');
            $this->schedule_immediate_async_processing_from_queue($queue_item);
        }
    }
    
    /**
     * Schedule immediate async processing from queue item
     */
    private function schedule_immediate_async_processing_from_queue($queue_item) {
        error_log('SFAIC: Scheduling immediate async processing for prompt ID: ' . $queue_item['prompt_id']);
        
        $response = wp_remote_post(admin_url('admin-ajax.php'), array(
            'timeout' => 0.01,
            'blocking' => false,
            'body' => array(
                'action' => 'sfaic_process_immediate_async',
                'prompt_id' => $queue_item['prompt_id'],
                'entry_id' => $queue_item['entry_id'],
                'form_data' => base64_encode(serialize($queue_item['form_data'])),
                'form_id' => $queue_item['form_id'],
                'nonce' => wp_create_nonce('sfaic_immediate_async_' . $queue_item['prompt_id'])
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('SFAIC: Failed to schedule async processing: ' . $response->get_error_message());
        }
    }
    
    /**
     * Cron job to process any remaining queued items
     */
    public function process_cron_queue() {
        $this->process_queued_submissions();
    }
    
    /**
     * AJAX handler for immediate async processing
     */
    public function handle_immediate_async_processing() {
        // Verify nonce
        $prompt_id = intval($_POST['prompt_id'] ?? 0);
        $nonce = $_POST['nonce'] ?? '';
        
        if (!wp_verify_nonce($nonce, 'sfaic_immediate_async_' . $prompt_id)) {
            error_log('SFAIC: Invalid nonce for async processing');
            wp_die('Invalid nonce');
        }
        
        $entry_id = intval($_POST['entry_id'] ?? 0);
        $form_data = unserialize(base64_decode($_POST['form_data'] ?? ''));
        $form_id = intval($_POST['form_id'] ?? 0);
        
        if (!$prompt_id || !$entry_id || !is_array($form_data)) {
            error_log('SFAIC: Invalid data for async processing');
            wp_die('Invalid data');
        }
        
        // Get form object
        if (!function_exists('wpFluent')) {
            error_log('SFAIC: Fluent Forms not available');
            wp_die('Fluent Forms not available');
        }
        
        $form = wpFluent()->table('fluentform_forms')->where('id', $form_id)->first();
        if (!$form) {
            error_log('SFAIC: Form not found: ' . $form_id);
            wp_die('Form not found');
        }
        
        error_log('SFAIC: Processing immediate async job for prompt: ' . $prompt_id);
        
        // Process the prompt immediately
        $result = $this->process_prompt($prompt_id, $form_data, $entry_id, $form);
        
        if ($result) {
            error_log('SFAIC: Async processing completed successfully for prompt: ' . $prompt_id);
        } else {
            error_log('SFAIC: Async processing failed for prompt: ' . $prompt_id);
        }
        
        wp_die('OK');
    }
    
    /**
     * Add JavaScript to prevent duplicate form submissions
     */
    public function add_duplicate_prevention_js() {
        ?>
        <script>
        (function() {
            // Prevent double form submission
            var sfaicFormStates = {};
            
            document.addEventListener('DOMContentLoaded', function() {
                // Find all Fluent Forms
                var forms = document.querySelectorAll('.frm-fluent-form, .fluentform, form.fluent_form_<?php echo get_the_ID(); ?>');
                
                forms.forEach(function(form) {
                    var formId = form.getAttribute('id') || form.getAttribute('data-form_id') || 'form_' + Math.random();
                    sfaicFormStates[formId] = false;
                    
                    form.addEventListener('submit', function(e) {
                        if (sfaicFormStates[formId]) {
                            e.preventDefault();
                            e.stopPropagation();
                            console.log('SFAIC: Preventing duplicate form submission for', formId);
                            return false;
                        }
                        
                        sfaicFormStates[formId] = true;
                        
                        // Find and disable submit buttons
                        var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"], .ff_submit_btn');
                        submitButtons.forEach(function(btn) {
                            btn.disabled = true;
                            btn.style.opacity = '0.6';
                            
                            // Store original text
                            var originalText = btn.textContent || btn.value;
                            btn.setAttribute('data-original-text', originalText);
                            
                            // Update button text
                            if (btn.tagName === 'BUTTON') {
                                btn.innerHTML = '<span style="display: inline-block; animation: sfaic-spin 1s linear infinite;">⟳</span> Processing...';
                            } else {
                                btn.value = 'Processing...';
                            }
                        });
                        
                        // Reset after 10 seconds in case of error
                        setTimeout(function() {
                            sfaicFormStates[formId] = false;
                            submitButtons.forEach(function(btn) {
                                btn.disabled = false;
                                btn.style.opacity = '1';
                                
                                var originalText = btn.getAttribute('data-original-text');
                                if (originalText) {
                                    if (btn.tagName === 'BUTTON') {
                                        btn.textContent = originalText;
                                    } else {
                                        btn.value = originalText;
                                    }
                                }
                            });
                        }, 10000);
                    });
                });
                
                // Listen for Fluent Forms jQuery events if jQuery is available
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).on('fluentform_submission_success', function(e, data) {
                        var formId = data.form_id || jQuery(e.target).attr('id');
                        if (formId && sfaicFormStates[formId] !== undefined) {
                            sfaicFormStates[formId] = false;
                        }
                    });
                    
                    jQuery(document).on('fluentform_submission_failed', function(e, data) {
                        var formId = data.form_id || jQuery(e.target).attr('id');
                        if (formId && sfaicFormStates[formId] !== undefined) {
                            sfaicFormStates[formId] = false;
                            
                            // Re-enable buttons
                            var form = document.getElementById(formId);
                            if (form) {
                                var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"], .ff_submit_btn');
                                submitButtons.forEach(function(btn) {
                                    btn.disabled = false;
                                    btn.style.opacity = '1';
                                    
                                    var originalText = btn.getAttribute('data-original-text');
                                    if (originalText) {
                                        if (btn.tagName === 'BUTTON') {
                                            btn.textContent = originalText;
                                        } else {
                                            btn.value = originalText;
                                        }
                                    }
                                });
                            }
                        }
                    });
                }
            });
        })();
        </script>
        <style>
            @keyframes sfaic-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
        </style>
        <?php
    }

    /**
     * Process a prompt with form data - PUBLIC for background job access
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
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            ' . $email_content . '
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
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="border: 1px solid #ddd; padding: 8px; background-color: #f5f5f5;">Field</th>
                    <th style="border: 1px solid #ddd; padding: 8px; background-color: #f5f5f5;">Value</th>
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
                    <td style="border: 1px solid #ddd; padding: 8px;"><strong>' . esc_html($label) . '</strong></td>
                    <td style="border: 1px solid #ddd; padding: 8px;">' . esc_html($field_value) . '</td>
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

        // Build admin email content
        $admin_email_content = '
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
            </style>
        </head>
        <body>
            <h2>New Form Submission</h2>
            <h3>AI Response:</h3>
            <div>' . wp_kses_post($ai_response) . '</div>
            <h3>Form Data:</h3>
            ' . $this->generate_form_data_table($form_data, $prompt_id) . '
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

// Schedule cleanup of old transients
add_action('init', function() {
    if (!wp_next_scheduled('sfaic_cleanup_old_transients')) {
        wp_schedule_event(time(), 'hourly', 'sfaic_cleanup_old_transients');
    }
});

add_action('sfaic_cleanup_old_transients', function() {
    global $wpdb;
    
    // Clean up old SFAIC transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE (option_name LIKE '_transient_sfaic_%' 
         OR option_name LIKE '_transient_timeout_sfaic_%')
         AND option_name NOT LIKE '%_lock%'"
    );
    
    error_log('SFAIC: Cleaned up old transients');
});