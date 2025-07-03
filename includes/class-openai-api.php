<?php

/**
 * ChatGPT API Class - FIXED with Working Chunking Support
 * 
 * Handles API requests to the ChatGPT API and tracks token usage
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_OpenAI_API {

    /**
     * Last API response for token tracking
     */
    private $last_response = null;
    private $last_request_json = null;
    private $last_response_json = null;

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to do here yet
    }

    /**
     * Get token usage from last API call
     */
    public function get_last_token_usage() {
        if ($this->last_response && isset($this->last_response['usage'])) {
            return array(
                'prompt_tokens' => $this->last_response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $this->last_response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $this->last_response['usage']['total_tokens'] ?? 0
            );
        }
        return array();
    }

    /**
     * Make a request to the OpenAI API
     *
     * @param array $messages Array of message objects (role, content)
     * @param string $model Optional. The model to use. If null, uses the setting.
     * @param int $max_tokens Optional. Maximum tokens in the response.
     * @param float $temperature Optional. Temperature for response randomness.
     * @return array|WP_Error Response from API or error
     */
    public function make_request($messages, $model = null, $max_tokens = 1000, $temperature = 0.7) {
        $api_key = get_option('sfaic_api_key');
        $api_endpoint = get_option('sfaic_api_endpoint', 'https://api.openai.com/v1/chat/completions');

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('OpenAI API key is not set', 'chatgpt-fluent-connector'));
        }

        // Use specified model or fall back to settings
        if ($model === null) {
            $model = get_option('sfaic_model', 'gpt-3.5-turbo');
        }

        // Token limits for OpenAI models (output limits)
        $token_limits = [
            'gpt-3.5-turbo' => 4096,
            'gpt-4' => 8192,
            'gpt-4-turbo' => 4096, // Output limit is 4096 even though context is 128k
            'gpt-4-turbo-preview' => 4096,
            'gpt-4-1106-preview' => 4096,
            'gpt-4-0613' => 8192,
            'gpt-4-0125-preview' => 4096,
            'gpt-4o' => 4096,
            'gpt-4o-mini' => 4096
        ];

        // Set default max token limit
        $default_limit = 4096;
        $model_limit = isset($token_limits[$model]) ? $token_limits[$model] : $default_limit;

        // Ensure max_tokens is within model limits
        $max_tokens = min(intval($max_tokens), $model_limit);

        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        );

        $body = array(
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => intval($max_tokens),
            'temperature' => floatval($temperature)
        );

        // Store the request JSON
        $this->last_request_json = wp_json_encode($body, JSON_PRETTY_PRINT);

        $args = array(
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'method' => 'POST',
            'data_format' => 'body',
            'timeout' => 240
        );

        // Make the API request
        $response = wp_remote_post($api_endpoint, $args);

        // Check for WordPress request errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('CGPTFC: OpenAI API WordPress Request Error: ' . $error_message);
            return $response;
        }

        // Get response code and body
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body_raw = wp_remote_retrieve_body($response);

        // Store the response JSON
        $this->last_response_json = $response_body_raw;

        $response_body = json_decode($response_body_raw, true);

        // Store the response for token tracking
        $this->last_response = $response_body;

        // Handle HTTP errors
        if ($response_code !== 200) {
            // Try to extract error message from response if possible
            $error_message = '';
            if (isset($response_body['error']['message'])) {
                $error_message = $response_body['error']['message'];
            } else {
                $error_message = sprintf(__('Unknown error (HTTP %s)', 'chatgpt-fluent-connector'), $response_code);
            }

            // Log error details
            error_log('CGPTFC: OpenAI API Error: ' . $error_message);

            return new WP_Error('api_error', $error_message);
        }

        return $response_body;
    }

    // Add these getter methods to retrieve the JSON data:
    public function get_last_request_json() {
        return $this->last_request_json;
    }

    public function get_last_response_json() {
        return $this->last_response_json;
    }

    /**
     * Get the content from the API response
     *
     * @param array $response The API response array
     * @return string|WP_Error The response content or error
     */
    public function get_response_content($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Invalid response from API', 'chatgpt-fluent-connector'));
        }

        return $response['choices'][0]['message']['content'];
    }

    /**
     * Process a form submission with a prompt - FIXED with proper chunking support
     *
     * @param int $prompt_id The prompt post ID
     * @param array $form_data The form submission data
     * @param int $entry_id The entry ID (optional)
     * @return string|WP_Error The response content or error
     */
    public function process_form_with_prompt($prompt_id, $form_data, $entry_id = null) {
        // Get prompt settings
        $system_prompt = get_post_meta($prompt_id, '_sfaic_system_prompt', true);
        $user_prompt_template = get_post_meta($prompt_id, '_sfaic_user_prompt_template', true);
        $temperature = get_post_meta($prompt_id, '_sfaic_temperature', true);
        $max_tokens = get_post_meta($prompt_id, '_sfaic_max_tokens', true);
        $prompt_type = get_post_meta($prompt_id, '_sfaic_prompt_type', true);
        $enable_chunking = get_post_meta($prompt_id, '_sfaic_enable_chunking', true);
        $model = get_option('sfaic_model', 'gpt-3.5-turbo');

        // FIXED: Check if chunking is enabled and needed
        if ($enable_chunking === '1' && intval($max_tokens) > 4096) {
            error_log('SFAIC OpenAI: Using chunked processing for ' . intval($max_tokens) . ' tokens');
            return $this->process_form_with_prompt_chunked($prompt_id, $form_data, $entry_id);
        }

        // Set default prompt type if not set
        if (empty($prompt_type)) {
            $prompt_type = 'template';
        }

        // Get model output limits
        $token_limits = [
            'gpt-3.5-turbo' => 4096,
            'gpt-4' => 8192,
            'gpt-4-turbo' => 4096,
            'gpt-4-turbo-preview' => 4096,
            'gpt-4-1106-preview' => 4096,
            'gpt-4-0613' => 8192,
            'gpt-4-0125-preview' => 4096,
            'gpt-4o' => 4096,
            'gpt-4o-mini' => 4096
        ];

        $model_limit = isset($token_limits[$model]) ? $token_limits[$model] : 4096;

        // Cap max_tokens to model limit for non-chunked requests
        if (intval($max_tokens) > $model_limit) {
            $max_tokens = $model_limit;
        }

        // Prepare the user prompt based on prompt type
        $user_prompt = '';
        if ($prompt_type === 'all_form_data') {
            // Use all form data
            $user_prompt = $this->format_all_form_data($form_data, $prompt_id);
        } else {
            // Use custom template
            if (empty($user_prompt_template)) {
                return new WP_Error('no_prompt_template', __('No user prompt template configured', 'chatgpt-fluent-connector'));
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

        if (empty($user_prompt)) {
            return new WP_Error('empty_prompt', __('User prompt is empty after processing', 'chatgpt-fluent-connector'));
        }

        // Tell ChatGPT it can use HTML in responses
        if (!empty($system_prompt)) {
            $system_prompt .= "\n\nYou can use HTML formatting in your response if needed for better presentation, such as <h3>, <p>, <ul>, <li>, <strong>, <em>, etc.";
        } else {
            $system_prompt = "You are a helpful assistant. You can use HTML formatting in your response if needed for better presentation, such as <h3>, <p>, <ul>, <li>, <strong>, <em>, etc.";
        }

        // Apply HTML template filter - this will add the template if enabled
        $user_prompt = apply_filters('sfaic_process_form_with_prompt', $user_prompt, $prompt_id, $form_data);

        // Prepare the messages
        $messages = array();

        if (!empty($system_prompt)) {
            $messages[] = array(
                'role' => 'system',
                'content' => $system_prompt
            );
        }

        $messages[] = array(
            'role' => 'user',
            'content' => $user_prompt
        );

        // Store the complete prompt for the entry
        if (!empty($entry_id)) {
            $complete_prompt_string = '';
            if (!empty($system_prompt)) {
                $complete_prompt_string .= "System: " . $system_prompt . "\n\n";
            }
            $complete_prompt_string .= "User: " . $user_prompt;

            update_post_meta($entry_id, '_openai_complete_prompt', $complete_prompt_string);
        }

        // Make the API request
        $response = $this->make_request(
                $messages,
                $model,
                !empty($max_tokens) ? intval($max_tokens) : 1000,
                !empty($temperature) ? floatval($temperature) : 0.7
        );

        if (is_wp_error($response)) {
            error_log('CGPTFC: Error in API response: ' . $response->get_error_message());
            return $response;
        }

        $content = $this->get_response_content($response);

        // Store token usage for the entry
        if (!empty($entry_id)) {
            $token_usage = $this->get_last_token_usage();
            if (!empty($token_usage)) {
                update_post_meta($entry_id, '_openai_token_usage', $token_usage);
            }
        }

        return $content;
    }

    /**
     * Format all form data into a structured text for ChatGPT
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

        $output .= "\n" . __('Please analyze this information and provide a response. You can use HTML formatting in your response for better presentation.', 'chatgpt-fluent-connector');
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
     * Process a form submission with chunked responses for long content - FIXED IMPLEMENTATION
     */
    public function process_form_with_prompt_chunked($prompt_id, $form_data, $entry_id = null) {
        error_log('SFAIC: Starting enhanced chunked processing');

        // Get prompt settings
        $system_prompt = get_post_meta($prompt_id, '_sfaic_system_prompt', true);
        $user_prompt_template = get_post_meta($prompt_id, '_sfaic_user_prompt_template', true);
        $temperature = get_post_meta($prompt_id, '_sfaic_temperature', true);
        $max_tokens = get_post_meta($prompt_id, '_sfaic_max_tokens', true);
        $prompt_type = get_post_meta($prompt_id, '_sfaic_prompt_type', true);

        // Get model based on provider
        $model = $this->get_current_model();

        // Prepare the initial user prompt
        $user_prompt = $this->prepare_user_prompt($prompt_id, $form_data, $prompt_type, $user_prompt_template);

        if (is_wp_error($user_prompt)) {
            return $user_prompt;
        }

        // Enhanced system prompt for chunking with specific completion instructions
        $chunked_system_prompt = $system_prompt . "\n\n" .
                "IMPORTANT CHUNKING INSTRUCTIONS:\n" .
                "1. You are generating a comprehensive, detailed response that may require multiple parts\n" .
                "2. Write naturally and thoroughly - do not rush to conclude\n" .
                "3. If you reach your output limit, stop at a complete sentence or paragraph\n" .
                "4. Do NOT add continuation markers like '(continued)', '...', or '[Part X]'\n" .
                "5. Do NOT add premature conclusions or summaries unless specifically asked\n" .
                "6. Continue naturally from where you left off if prompted to continue\n" .
                "7. Only conclude when you have fully addressed all aspects of the request\n" .
                "8. Use clear section headers and proper formatting for readability";

        // Calculate target and chunk parameters with better sizing
        $target_tokens = intval($max_tokens);
        $max_chunks = min(ceil($target_tokens / 2500), 50); // Allow more chunks for thorough responses
        $total_tokens_used = 0;
        $full_response = '';
        $conversation = array();
        $consecutive_short_chunks = 0; // Track short responses
        $forced_completion_attempts = 0; // Track attempts to force completion
        // Initialize conversation
        if (!empty($chunked_system_prompt)) {
            $conversation[] = array('role' => 'system', 'content' => $chunked_system_prompt);
        }
        $conversation[] = array('role' => 'user', 'content' => $user_prompt);

        error_log("SFAIC: Starting enhanced chunked generation - target: {$target_tokens} tokens, max chunks: {$max_chunks}");

        for ($chunk_num = 0; $chunk_num < $max_chunks; $chunk_num++) {
            // Calculate chunk size with better strategy
            $remaining_tokens = $target_tokens - $total_tokens_used;
            $chunk_tokens = $this->calculate_enhanced_chunk_size($model, $remaining_tokens, $chunk_num, $target_tokens);

            if ($chunk_tokens < 100) {
                error_log("SFAIC: Stopping - chunk size too small: {$chunk_tokens}");
                break;
            }

            error_log("SFAIC: Generating chunk " . ($chunk_num + 1) . " with {$chunk_tokens} tokens");

            // Make API request with retry logic
            $response = $this->make_request_with_retry($conversation, $model, $chunk_tokens, floatval($temperature));

            if (is_wp_error($response)) {
                if ($chunk_num === 0) {
                    error_log("SFAIC: First chunk failed: " . $response->get_error_message());
                    return $response;
                }

                error_log("SFAIC: Chunk {$chunk_num} failed, attempting recovery");

                // Try with smaller chunk size
                $recovery_tokens = min($chunk_tokens / 2, 1000);
                $response = $this->make_request_with_retry($conversation, $model, $recovery_tokens, floatval($temperature));

                if (is_wp_error($response)) {
                    error_log("SFAIC: Recovery failed, stopping with partial response");
                    break;
                }
            }

            $chunk_content = $this->get_response_content($response);
            if (is_wp_error($chunk_content)) {
                if ($chunk_num === 0) {
                    return $chunk_content;
                }
                break;
            }

            // Track token usage
            $token_usage = $this->get_last_token_usage();
            $chunk_tokens_used = isset($token_usage['completion_tokens']) ? $token_usage['completion_tokens'] : 0;
            $total_tokens_used += $chunk_tokens_used;

            error_log("SFAIC: Chunk " . ($chunk_num + 1) . " generated {$chunk_tokens_used} tokens. Total: {$total_tokens_used}");

            // Add chunk to full response
            $full_response .= $chunk_content;

            // Enhanced completion detection
            $is_complete = $this->is_response_complete_enhanced($chunk_content, $chunk_num, $full_response, $user_prompt);

            // Track consecutive short chunks
            if ($chunk_tokens_used < $chunk_tokens * 0.4) {
                $consecutive_short_chunks++;
            } else {
                $consecutive_short_chunks = 0;
            }

            // Check stopping conditions with enhanced logic
            if ($total_tokens_used >= $target_tokens * 0.95) {
                error_log("SFAIC: Reached target token limit ({$total_tokens_used}/{$target_tokens})");
                break;
            }

            if ($is_complete) {
                error_log("SFAIC: Response appears complete after chunk " . ($chunk_num + 1));
                break;
            }

            // Stop if we have too many consecutive short chunks (likely complete)
            if ($consecutive_short_chunks >= 2 && $chunk_num >= 3) {
                error_log("SFAIC: Multiple short chunks detected, likely complete");
                break;
            }

            // If we have a substantial response and recent chunks are getting shorter, try to force completion
            if (strlen($full_response) > 2000 && $chunk_num >= 5 && $consecutive_short_chunks >= 1) {
                $forced_completion_attempts++;
                if ($forced_completion_attempts >= 2) {
                    error_log("SFAIC: Forcing completion after multiple short chunks");
                    break;
                }
            }

            // Manage conversation length to prevent context overflow
            if (count($conversation) > 10) {
                // Keep system prompt, original request, and last 6 exchanges
                $conversation = array_merge(
                        array_slice($conversation, 0, 2), // System + original user message
                        array_slice($conversation, -6)    // Last 6 messages
                );
            }

            // Add response and continuation prompt
            $conversation[] = array('role' => $this->get_assistant_role(), 'content' => $chunk_content);

            $continuation_prompt = $this->generate_enhanced_continuation_prompt($chunk_num, $chunk_content, $full_response, $user_prompt, $consecutive_short_chunks);
            $conversation[] = array('role' => 'user', 'content' => $continuation_prompt);

            error_log("SFAIC: Added continuation prompt: " . substr($continuation_prompt, 0, 100) . "...");
        }

        // Post-process the response
        $full_response = $this->post_process_chunked_response($full_response);

        // Store chunking metadata
        if (!empty($entry_id)) {
            update_post_meta($entry_id, '_' . $this->get_provider_name() . '_chunked_response', true);
            update_post_meta($entry_id, '_' . $this->get_provider_name() . '_chunks_count', $chunk_num + 1);
            update_post_meta($entry_id, '_' . $this->get_provider_name() . '_total_tokens_generated', $total_tokens_used);
            update_post_meta($entry_id, '_' . $this->get_provider_name() . '_response_length', strlen($full_response));
            update_post_meta($entry_id, '_' . $this->get_provider_name() . '_completion_reason', $this->get_completion_reason($chunk_num, $max_chunks, $total_tokens_used, $target_tokens));
        }

        error_log("SFAIC: Enhanced chunked generation complete. " . ($chunk_num + 1) . " chunks, {$total_tokens_used} tokens, " . strlen($full_response) . " characters");

        return $full_response;
    }

    /**
     * Enhanced chunk size calculation
     */
    private function calculate_enhanced_chunk_size($model, $remaining_tokens, $chunk_num, $target_tokens) {
        // Base chunk sizes for different models
        $base_chunk_sizes = $this->get_model_chunk_sizes($model);
        $base_chunk = $base_chunk_sizes['default'];

        // Adaptive sizing based on progress
        $progress_ratio = $chunk_num > 0 ? ($target_tokens - $remaining_tokens) / $target_tokens : 0;

        if ($chunk_num === 0) {
            // First chunk can be larger to establish context
            $chunk_size = min($base_chunk * 1.2, $remaining_tokens);
        } elseif ($progress_ratio < 0.3) {
            // Early chunks - use standard size
            $chunk_size = $base_chunk;
        } elseif ($progress_ratio < 0.7) {
            // Middle chunks - slightly smaller to leave room for conclusion
            $chunk_size = $base_chunk * 0.9;
        } else {
            // Later chunks - smaller to allow for proper conclusion
            $chunk_size = $base_chunk * 0.8;
        }

        // Don't exceed remaining tokens or model limits
        return min($chunk_size, $remaining_tokens, $base_chunk_sizes['max']);
    }
    
    /**
     * Enhanced completion detection
     */
    private function is_response_complete_enhanced($content, $chunk_num, $full_response, $original_prompt) {
        // Don't check completion on first chunk
        if ($chunk_num === 0) return false;

        // Very short responses might indicate completion
        if (strlen(trim($content)) < 100) return true;

        // Check for explicit completion markers
        $completion_patterns = array(
            '/\b(conclusion|summary|in summary|to conclude|finally|in conclusion)\b.*[.!]\s*$/im',
            '/\b(that completes|this concludes|this ends|hope this helps)\b.*[.!]\s*$/im',
            '/\b(thank you|best regards|sincerely)\b.*[.!]\s*$/im',
            '/<\/(div|section|article|report|document)>\s*$/i',
            '/---+\s*end\s*---+/i',
            '/\[end\s+of\s+response\]/i',
        );

        foreach ($completion_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        // Check if the response appears to have covered the main topics from the prompt
        if (strlen($full_response) > 1500) {
            $topic_coverage = $this->assess_topic_coverage($full_response, $original_prompt);
            if ($topic_coverage > 0.8) {
                // High topic coverage + natural ending = likely complete
                if (preg_match('/[.!?]\s*$/', trim($content))) {
                    return true;
                }
            }
        }

        return false;
    }    
    
    
    /**
        * Generate enhanced continuation prompts
        */
       private function generate_enhanced_continuation_prompt($chunk_num, $last_chunk, $full_response, $original_prompt, $consecutive_short_chunks) {
           $last_chunk_trimmed = trim($last_chunk);
           $ends_mid_sentence = !preg_match('/[.!?]\s*$/', $last_chunk_trimmed);

           if ($ends_mid_sentence) {
               return "Please complete the current sentence and then continue with more detailed information.";
           }

           // If we've had short chunks, try to encourage more content
           if ($consecutive_short_chunks > 0) {
               $encouraging_prompts = array(
                   "Please continue with much more detailed information and expand on the key points.",
                   "Continue providing comprehensive details and elaborate further on this topic.",
                   "Please add significantly more depth and detail to your response.",
                   "Continue with extensive additional information and thorough analysis.",
               );
               return $encouraging_prompts[$chunk_num % count($encouraging_prompts)];
           }

           // Analyze what might be missing based on the original prompt
           $missing_aspects = $this->identify_missing_aspects($full_response, $original_prompt);

           if (!empty($missing_aspects)) {
               return "Please continue by addressing: " . implode(', ', array_slice($missing_aspects, 0, 3)) . ". Provide detailed information on these aspects.";
           }

           // Standard continuation prompts
           $prompts = array(
               "Please continue with the next section of your comprehensive response.",
               "Continue providing more detailed information and in-depth analysis.",
               "Please proceed with additional insights and thorough elaboration.",
               "Continue developing your response with much more specific details.",
               "Please add more comprehensive information and detailed examples.",
               "Continue with the next part of your detailed explanation.",
               "Please provide further elaboration with extensive details on this topic.",
               "Continue expanding on this subject with comprehensive additional information."
           );

           return $prompts[$chunk_num % count($prompts)];
       }
    
    
    
    /**
 * Make API request with retry logic
 */
private function make_request_with_retry($conversation, $model, $chunk_tokens, $temperature, $max_retries = 2) {
    $retry_count = 0;
    
    while ($retry_count <= $max_retries) {
        $response = $this->make_request($conversation, $model, $chunk_tokens, $temperature);
        
        if (!is_wp_error($response)) {
            return $response;
        }
        
        $error_message = $response->get_error_message();
        
        // Check if it's a retryable error
        if (strpos($error_message, 'rate limit') !== false || 
            strpos($error_message, 'timeout') !== false ||
            strpos($error_message, 'server error') !== false) {
            
            $retry_count++;
            if ($retry_count <= $max_retries) {
                // Wait with exponential backoff
                $wait_time = pow(2, $retry_count);
                error_log("SFAIC: Retrying request in {$wait_time} seconds (attempt {$retry_count})");
                sleep($wait_time);
                continue;
            }
        }
        
        // Non-retryable error or max retries reached
        return $response;
    }
    
    return $response;
}

/**
 * Post-process the chunked response
 */
private function post_process_chunked_response($response) {
    // Remove any accidental continuation markers that might have slipped through
    $response = preg_replace('/\[(continued|part \d+|end of part)\]/i', '', $response);
    $response = preg_replace('/\.\.\.\s*$/', '.', $response);
    
    // Fix any broken HTML tags if this is an HTML response
    if (strpos($response, '<') !== false) {
        // Basic HTML tag fixing
        $response = preg_replace('/<([^>]+)>([^<]*?)(?=<[^\/]|\s*$)/', '<$1>$2', $response);
    }
    
    // Ensure proper spacing between sections
    $response = preg_replace('/\n{3,}/', "\n\n", $response);
    
    return trim($response);
}

/**
 * Assess topic coverage (basic implementation)
 */
private function assess_topic_coverage($response, $prompt) {
    // Extract key terms from the prompt
    $prompt_words = preg_split('/\W+/', strtolower($prompt));
    $prompt_words = array_filter($prompt_words, function($word) {
        return strlen($word) > 3 && !in_array($word, array('the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had', 'her', 'was', 'one', 'our', 'out', 'day', 'get', 'has', 'him', 'his', 'how', 'man', 'new', 'now', 'old', 'see', 'two', 'way', 'who', 'boy', 'did', 'its', 'let', 'put', 'say', 'she', 'too', 'use'));
    });
    
    $response_lower = strtolower($response);
    $covered_words = 0;
    
    foreach ($prompt_words as $word) {
        if (strpos($response_lower, $word) !== false) {
            $covered_words++;
        }
    }
    
    return count($prompt_words) > 0 ? $covered_words / count($prompt_words) : 0;
}

/**
 * Identify missing aspects (basic implementation)
 */
private function identify_missing_aspects($response, $prompt) {
    $common_aspects = array(
        'examples', 'analysis', 'recommendations', 'conclusion', 
        'benefits', 'challenges', 'solutions', 'implementation',
        'timeline', 'costs', 'risks', 'alternatives'
    );
    
    $missing = array();
    $response_lower = strtolower($response);
    $prompt_lower = strtolower($prompt);
    
    foreach ($common_aspects as $aspect) {
        if (strpos($prompt_lower, $aspect) !== false && strpos($response_lower, $aspect) === false) {
            $missing[] = $aspect;
        }
    }
    
    return $missing;
}

/**
 * Get completion reason for logging
 */
private function get_completion_reason($chunks_used, $max_chunks, $tokens_used, $target_tokens) {
    if ($chunks_used >= $max_chunks - 1) {
        return 'max_chunks_reached';
    } elseif ($tokens_used >= $target_tokens * 0.95) {
        return 'token_limit_reached';
    } else {
        return 'natural_completion';
    }
}





protected function get_model_chunk_sizes($model) {
    $chunk_sizes = array(
        'gpt-3.5-turbo' => array('default' => 3200, 'max' => 4096),
        'gpt-4' => array('default' => 6500, 'max' => 8192),
        'gpt-4-turbo' => array('default' => 3500, 'max' => 4096),
        'gpt-4-turbo-preview' => array('default' => 3500, 'max' => 4096),
        'gpt-4-1106-preview' => array('default' => 3500, 'max' => 4096),
        'gpt-4-0613' => array('default' => 6500, 'max' => 8192),
        'gpt-4-0125-preview' => array('default' => 3500, 'max' => 4096),
        'gpt-4o' => array('default' => 3500, 'max' => 4096),
        'gpt-4o-mini' => array('default' => 3500, 'max' => 4096)
    );
    
    return isset($chunk_sizes[$model]) ? $chunk_sizes[$model] : array('default' => 3000, 'max' => 4096);
}

protected function get_assistant_role() {
    return 'assistant';
}

protected function get_provider_name() {
    return 'openai';
}

protected function get_current_model() {
    return get_option('sfaic_model', 'gpt-3.5-turbo');
}



/**
 * Add this method to all three API classes for better debugging
 */
public function debug_chunking_process($prompt_id, $form_data, $entry_id = null) {
    $debug_info = array(
        'timestamp' => current_time('mysql'),
        'prompt_id' => $prompt_id,
        'entry_id' => $entry_id,
        'provider' => $this->get_provider_name(),
        'model' => $this->get_current_model(),
        'max_tokens_setting' => get_post_meta($prompt_id, '_sfaic_max_tokens', true),
        'chunking_enabled' => get_post_meta($prompt_id, '_sfaic_enable_chunking', true),
        'form_data_size' => strlen(serialize($form_data)),
    );
    
    // Log debug info
    error_log('SFAIC Debug Info: ' . json_encode($debug_info, JSON_PRETTY_PRINT));
    
    // Store debug info in post meta for troubleshooting
    if ($entry_id) {
        update_post_meta($entry_id, '_sfaic_debug_info', $debug_info);
    }
    
    return $debug_info;
}

/**
 * Add this method to track chunking performance
 */
public function track_chunking_performance($entry_id, $chunks_used, $total_tokens, $response_length, $completion_reason) {
    $performance_data = array(
        'chunks_used' => $chunks_used,
        'total_tokens' => $total_tokens,
        'response_length' => $response_length,
        'completion_reason' => $completion_reason,
        'tokens_per_chunk' => $chunks_used > 0 ? round($total_tokens / $chunks_used) : 0,
        'characters_per_token' => $total_tokens > 0 ? round($response_length / $total_tokens) : 0,
        'efficiency_score' => $this->calculate_efficiency_score($chunks_used, $total_tokens, $response_length)
    );
    
    update_post_meta($entry_id, '_sfaic_chunking_performance', $performance_data);
    
    // Log performance for analysis
    error_log('SFAIC Chunking Performance: ' . json_encode($performance_data));
    
    return $performance_data;
}

private function calculate_efficiency_score($chunks, $tokens, $length) {
    // Simple efficiency score: higher is better
    // Considers response length vs tokens used vs chunks needed
    if ($chunks === 0 || $tokens === 0) return 0;
    
    $length_efficiency = $length / $tokens; // Characters per token
    $chunk_efficiency = $tokens / $chunks; // Tokens per chunk
    
    // Normalize and combine (ideal: long response, few chunks, good token usage)
    $score = ($length_efficiency * 2) + ($chunk_efficiency / 100) + (100 / max($chunks, 1));
    
    return round($score, 2);
}

    /**
     * Calculate appropriate chunk size based on model, remaining tokens, and chunk number
     */
    private function calculate_chunk_size($model, $remaining_tokens, $chunk_num) {
        // Base chunk sizes for different models
        $base_chunk_sizes = array(
            'gpt-3.5-turbo' => 3000,
            'gpt-4' => 6000,
            'gpt-4-turbo' => 3500,
            'gpt-4-turbo-preview' => 3500,
            'gpt-4-1106-preview' => 3500,
            'gpt-4-0613' => 6000,
            'gpt-4-0125-preview' => 3500,
            'gpt-4o' => 3500,
            'gpt-4o-mini' => 3500
        );

        $base_chunk = isset($base_chunk_sizes[$model]) ? $base_chunk_sizes[$model] : 3000;
        
        // Reduce chunk size slightly for later chunks to maintain quality
        if ($chunk_num > 5) {
            $base_chunk = intval($base_chunk * 0.8);
        }
        
        // Don't exceed remaining tokens
        return min($base_chunk, $remaining_tokens);
    }

    /**
     * Check if response seems complete
     */
    private function is_response_complete($content, $chunk_num) {
        // Don't check completion on first chunk
        if ($chunk_num === 0) return false;

        // Very short responses might indicate completion
        if (strlen(trim($content)) < 150) return true;

        // Check for natural ending patterns
        $ending_patterns = array(
            '/\.\s*$/',                               // Ends with period
            '/<\/(p|div|section|article|conclusion)>\s*$/i', // Ends with closing HTML tag
            '/\n\n\s*$/',                            // Ends with double newline
            '/[Cc]onclusion.*[.!]\s*$/m',           // Contains conclusion
            '/[Tt]hank you.*[.!]\s*$/m',            // Thank you ending
            '/[Hh]ope this helps.*[.!]\s*$/m',      // Common closing phrase
            '/[Bb]est regards.*[.!]\s*$/m',         // Email-style closing
            '/[Ii]n summary.*[.!]\s*$/m',           // Summary ending
            '/[Tt]o conclude.*[.!]\s*$/m',          // Conclusion phrase
        );

        foreach ($ending_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate smart continuation prompts
     */
    private function generate_continuation_prompt($chunk_num, $last_chunk, $total_length) {
        // Check if last chunk ended mid-sentence
        $last_chunk_trimmed = trim($last_chunk);
        $ends_mid_sentence = !preg_match('/[.!?]\s*$/', $last_chunk_trimmed);
        
        if ($ends_mid_sentence) {
            return "Please complete the current sentence and then continue with the rest of your detailed response.";
        }

        // Vary continuation prompts to maintain natural flow
        $prompts = array(
            "Please continue with the next part of your comprehensive response.",
            "Continue providing more detailed information and analysis.",
            "Please proceed with additional insights and elaboration.",
            "Continue developing your response with more specific details.",
            "Please add more comprehensive information to your analysis.",
            "Continue with the next section of your detailed explanation.",
            "Please provide further elaboration and examples on this topic.",
            "Continue expanding on this subject with additional details."
        );

        return $prompts[$chunk_num % count($prompts)];
    }

    /**
     * Prepare user prompt helper method
     */
    private function prepare_user_prompt($prompt_id, $form_data, $prompt_type, $user_prompt_template) {
        if (empty($prompt_type)) {
            $prompt_type = 'template';
        }

        $user_prompt = '';
        if ($prompt_type === 'all_form_data') {
            $user_prompt = $this->format_all_form_data($form_data, $prompt_id);
        } else {
            if (empty($user_prompt_template)) {
                return new WP_Error('no_prompt_template', __('No user prompt template configured', 'chatgpt-fluent-connector'));
            }

            $user_prompt = $user_prompt_template;

            foreach ($form_data as $field_key => $field_value) {
                if (!is_scalar($field_key)) {
                    continue;
                }

                if (is_array($field_value)) {
                    $field_value = implode(', ', $field_value);
                } elseif (!is_scalar($field_value)) {
                    continue;
                }

                $user_prompt = str_replace('{' . $field_key . '}', $field_value, $user_prompt);
            }

            $user_prompt = preg_replace('/\{[^}]+\}/', '', $user_prompt);
        }

        if (empty($user_prompt)) {
            return new WP_Error('empty_prompt', __('User prompt is empty after processing', 'chatgpt-fluent-connector'));
        }

        // Apply HTML template filter
        $user_prompt = apply_filters('sfaic_process_form_with_prompt', $user_prompt, $prompt_id, $form_data);

        return $user_prompt;
    }
}