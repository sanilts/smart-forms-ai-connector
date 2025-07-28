<?php

/**
 * Anthropic Claude API Class - UPDATED with Dynamic Chunking Settings
 * 
 * Handles API requests to the Anthropic Claude API and tracks token usage
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Claude_API {

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
                'prompt_tokens' => $this->last_response['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $this->last_response['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($this->last_response['usage']['input_tokens'] ?? 0) + ($this->last_response['usage']['output_tokens'] ?? 0)
            );
        }
        return array();
    }

    /**
     * Make a request to the Claude API
     *
     * @param array $messages Array of message objects (role, content)
     * @param string $model Optional. The model to use. If null, uses the setting.
     * @param int $max_tokens Optional. Maximum tokens in the response.
     * @param float $temperature Optional. Temperature for response randomness.
     * @return array|WP_Error Response from API or error
     */
    public function make_request($messages, $model = null, $max_tokens = 1000, $temperature = 0.7) {
        $api_key = get_option('sfaic_claude_api_key');
        $api_endpoint = get_option('sfaic_claude_api_endpoint', 'https://api.anthropic.com/v1/messages');

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Claude API key is not set', 'chatgpt-fluent-connector'));
        }

        // Use specified model or fall back to settings
        if ($model === null) {
            $model = get_option('sfaic_claude_model', 'claude-opus-4-20250514');
        }

        // Set reasonable token limits based on model
        $model_limits = array(
            'claude-opus-4-20250514' => 4096,
            'claude-sonnet-4-20250514' => 4096,
            'claude-3-opus-20240229' => 4096,
            'claude-3-sonnet-20240229' => 4096,
            'claude-3-haiku-20240307' => 4096
        );

        $model_limit = isset($model_limits[$model]) ? $model_limits[$model] : 4096;
        $max_tokens = min(intval($max_tokens), $model_limit);

        if ($max_tokens < 50) {
            $max_tokens = 50;
        }

        // Convert messages to Claude format
        $claude_messages = $this->convert_messages_to_claude_format($messages);

        $headers = array(
            'x-api-key' => $api_key,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        );

        $body = array(
            'model' => $model,
            'max_tokens' => intval($max_tokens),
            'temperature' => floatval($temperature),
            'messages' => $claude_messages['messages']
        );

        // Add system prompt if present
        if (!empty($claude_messages['system'])) {
            $body['system'] = $claude_messages['system'];
        }

        // Store the complete request JSON
        $this->last_request_json = wp_json_encode($body, JSON_PRETTY_PRINT);

        $args = array(
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'method' => 'POST',
            'data_format' => 'body',
            'timeout' => 120,
        );

        $response = wp_remote_post($api_endpoint, $args);

        if (is_wp_error($response)) {
            error_log('CGPTFC: Claude API Request Error: ' . $response->get_error_message());
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Store the response JSON
        $this->last_response_json = $response_body;

        $response_data = json_decode($response_body, true);

        // Store the response for token tracking
        $this->last_response = $response_data;

        if ($response_code !== 200) {
            $error_message = 'Unknown error';
            if (isset($response_data['error']['message'])) {
                $error_message = $response_data['error']['message'];
            }

            error_log('CGPTFC: Claude API Error: ' . $error_message);

            if ($response_code === 401) {
                return new WP_Error('api_error', __('Invalid API key. Please check your Claude API key in settings.', 'chatgpt-fluent-connector'));
            } elseif ($response_code === 429) {
                return new WP_Error('api_error', __('Rate limit exceeded. Please wait a moment and try again.', 'chatgpt-fluent-connector'));
            } elseif ($response_code === 400 && strpos($error_message, 'maximum context length') !== false) {
                return new WP_Error('context_length_exceeded', __('The prompt is too long for this model. Please use a shorter prompt or enable chunking.', 'chatgpt-fluent-connector'));
            }

            return new WP_Error('api_error', $error_message);
        }

        if (!isset($response_data['content'][0]['text'])) {
            error_log('CGPTFC: Invalid Claude response structure: ' . wp_json_encode($response_data));
            return new WP_Error('invalid_response', __('Invalid response structure from Claude API', 'chatgpt-fluent-connector'));
        }

        return $response_data;
    }

    // Add getter methods:
    public function get_last_request_json() {
        return $this->last_request_json;
    }

    public function get_last_response_json() {
        return $this->last_response_json;
    }

    /**
     * Convert OpenAI-style messages to Claude format
     *
     * @param array $messages OpenAI-style messages
     * @return array Claude-formatted messages with system prompt separated
     */
    private function convert_messages_to_claude_format($messages) {
        $claude_messages = array();
        $system_prompt = '';

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system_prompt .= $message['content'] . "\n";
            } else {
                $role = ($message['role'] === 'assistant') ? 'assistant' : 'user';
                $claude_messages[] = array(
                    'role' => $role,
                    'content' => $message['content']
                );
            }
        }

        return array(
            'messages' => $claude_messages,
            'system' => trim($system_prompt)
        );
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

        if (!isset($response['content'][0]['text'])) {
            return new WP_Error('invalid_response', __('Invalid response from Claude API', 'chatgpt-fluent-connector'));
        }

        return $response['content'][0]['text'];
    }

    /**
     * Process a form submission with a prompt - UPDATED with dynamic chunking
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
        $model = get_option('sfaic_claude_model', 'claude-opus-4-20250514');

        // Intelligent chunking trigger
        $estimated_prompt_length = strlen($system_prompt) + strlen($user_prompt_template) + strlen(serialize($form_data));
        $needs_chunking = ($enable_chunking === '1') && (
            intval($max_tokens) > 4000 ||
            $estimated_prompt_length > 8000 ||
            strpos($user_prompt_template, 'HTML') !== false ||
            strpos($system_prompt, 'comprehensive') !== false ||
            strpos($system_prompt, 'detailed') !== false
        );

        if ($needs_chunking) {
            error_log('SFAIC Claude: Using chunked processing - estimated length: ' . $estimated_prompt_length);
            return $this->process_form_with_prompt_chunked($prompt_id, $form_data, $entry_id);
        }

        // Set default prompt type if not set
        if (empty($prompt_type)) {
            $prompt_type = 'template';
        }

        // For non-chunked responses, cap at model limit
        if (intval($max_tokens) > 4096) {
            $max_tokens = 4096;
        }

        // Prepare the user prompt
        $user_prompt = $this->prepare_user_prompt($prompt_id, $form_data, $prompt_type, $user_prompt_template);
        if (is_wp_error($user_prompt)) {
            return $user_prompt;
        }

        // Apply HTML template filter
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

            update_post_meta($entry_id, '_claude_complete_prompt', $complete_prompt_string);
        }

        // Make the API request
        $response = $this->make_request(
            $messages,
            $model,
            !empty($max_tokens) ? intval($max_tokens) : 1000,
            !empty($temperature) ? floatval($temperature) : 0.7
        );

        if (is_wp_error($response)) {
            error_log('CGPTFC: Error in Claude API response: ' . $response->get_error_message());
            return $response;
        }

        $content = $this->get_response_content($response);

        // Store token usage for the entry
        if (!empty($entry_id)) {
            $token_usage = $this->get_last_token_usage();
            if (!empty($token_usage)) {
                update_post_meta($entry_id, '_claude_token_usage', $token_usage);
            }
        }

        return $content;
    }

    /**
     * NEW: Enhanced chunked processing with dynamic settings
     */
    public function process_form_with_prompt_chunked($prompt_id, $form_data, $entry_id = null) {
        error_log('SFAIC: Starting Claude chunked processing with dynamic settings');

        // Get chunking settings from prompt
        $chunking_settings = $this->get_chunking_settings($prompt_id);
        
        error_log('SFAIC: Claude Chunking settings: ' . json_encode($chunking_settings));

        // Get prompt settings
        $system_prompt = get_post_meta($prompt_id, '_sfaic_system_prompt', true);
        $user_prompt_template = get_post_meta($prompt_id, '_sfaic_user_prompt_template', true);
        $temperature = get_post_meta($prompt_id, '_sfaic_temperature', true);
        $max_tokens = get_post_meta($prompt_id, '_sfaic_max_tokens', true);
        $prompt_type = get_post_meta($prompt_id, '_sfaic_prompt_type', true);
        $model = $this->get_current_model();

        // Prepare the initial user prompt
        $user_prompt = $this->prepare_user_prompt($prompt_id, $form_data, $prompt_type, $user_prompt_template);
        if (is_wp_error($user_prompt)) {
            return $user_prompt;
        }

        // Updated system prompt with dynamic completion marker
        $chunked_system_prompt = $system_prompt . "\n\n" .
            "CHUNKING INSTRUCTIONS:\n" .
            "1. Generate valid HTML for PDF conversion\n" .
            "2. Stop at complete elements if reaching limits\n" .
            "3. Continue when prompted\n" .
            "4. Only conclude with complete report\n" .
            "5. Add " . $chunking_settings['completion_marker'] . " when truly finished";

        // Claude-specific chunk sizes (conservative due to stricter limits)
        $target_tokens = intval($max_tokens);
        
        $model_chunk_sizes = array(
            'claude-opus-4-20250514' => 3500,
            'claude-sonnet-4-20250514' => 3500,
            'claude-3-opus-20240229' => 3500,
            'claude-3-sonnet-20240229' => 3500,
            'claude-3-haiku-20240307' => 3000
        );
        
        $chunk_size = isset($model_chunk_sizes[$model]) ? $model_chunk_sizes[$model] : 3500;
        $max_chunks = min(ceil($target_tokens / $chunk_size), 35);
        
        $total_tokens_used = 0;
        $full_response = '';
        $conversation = array();

        // Initialize conversation
        if (!empty($chunked_system_prompt)) {
            $conversation[] = array('role' => 'system', 'content' => $chunked_system_prompt);
        }
        $conversation[] = array('role' => 'user', 'content' => $user_prompt);

        error_log("SFAIC: Claude dynamic chunking - target: {$target_tokens}, chunk: {$chunk_size}, max chunks: {$max_chunks}");

        for ($chunk_num = 0; $chunk_num < $max_chunks; $chunk_num++) {
            // Calculate remaining tokens
            $remaining_tokens = $target_tokens - $total_tokens_used;
            $current_chunk_size = min($chunk_size, $remaining_tokens);

            if ($current_chunk_size < 100) {
                error_log("SFAIC: Stopping - insufficient tokens remaining: {$current_chunk_size}");
                break;
            }

            error_log("SFAIC: Claude Chunk " . ($chunk_num + 1) . " requesting {$current_chunk_size} tokens");

            // Make API request with retry
            $response = $this->make_request_with_retry($conversation, $model, $current_chunk_size, floatval($temperature));

            if (is_wp_error($response)) {
                if ($chunk_num === 0) {
                    error_log("SFAIC: First chunk failed: " . $response->get_error_message());
                    return $response;
                }
                error_log("SFAIC: Chunk {$chunk_num} failed, stopping with partial response");
                break;
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

            error_log("SFAIC: Claude Chunk " . ($chunk_num + 1) . " used {$chunk_tokens_used} tokens. Total: {$total_tokens_used}");

            // Clean chunk content
            $chunk_content = $this->clean_html_chunk($chunk_content);
            $full_response .= $chunk_content;

            // CHECK FOR DYNAMIC COMPLETION MARKER
            if (strpos($chunk_content, $chunking_settings['completion_marker']) !== false) {
                error_log("SFAIC: Found completion marker: " . $chunking_settings['completion_marker']);
                break;
            }

            // DYNAMIC COMPLETION CHECK
            if ($this->should_continue_chunking_dynamic($chunk_content, $full_response, $total_tokens_used, $target_tokens, $chunking_settings)) {
                // Manage conversation history (Claude is more sensitive to context length)
                if (count($conversation) > 6) {
                    $conversation = array_merge(
                        array_slice($conversation, 0, 2), // Keep system + original
                        array_slice($conversation, -2)    // Keep last 2 only
                    );
                }

                // Add response and continuation
                $conversation[] = array('role' => 'assistant', 'content' => $chunk_content);
                
                $continuation_prompt = $this->generate_dynamic_continuation_prompt($chunking_settings, $full_response, $chunk_num, $target_tokens, $total_tokens_used);
                $conversation[] = array('role' => 'user', 'content' => $continuation_prompt);
            } else {
                error_log("SFAIC: Completion detected by dynamic analysis");
                break;
            }
        }

        // Post-process
        $full_response = $this->post_process_html_response($full_response, $chunking_settings);

        // Store metadata
        if (!empty($entry_id)) {
            update_post_meta($entry_id, '_claude_chunked_response', true);
            update_post_meta($entry_id, '_claude_chunks_count', $chunk_num + 1);
            update_post_meta($entry_id, '_claude_total_tokens_generated', $total_tokens_used);
            update_post_meta($entry_id, '_claude_response_length', strlen($full_response));
            update_post_meta($entry_id, '_claude_completion_reason', 'dynamic_chunking');
            update_post_meta($entry_id, '_claude_chunking_settings_used', $chunking_settings);
        }

        error_log("SFAIC: Claude dynamic chunking complete. " . ($chunk_num + 1) . " chunks, {$total_tokens_used} tokens, " . strlen($full_response) . " chars");

        return $full_response;
    }

    /**
     * NEW: Get dynamic chunking settings for a prompt
     */
    private function get_chunking_settings($prompt_id) {
        // Get the prompt manager instance to access the settings
        if (isset(sfaic_main()->prompt_cpt) && method_exists(sfaic_main()->prompt_cpt, 'get_chunking_settings')) {
            return sfaic_main()->prompt_cpt->get_chunking_settings($prompt_id);
        }
        
        // Fallback: Get settings directly
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
     * NEW: Dynamic completion checking with configurable settings
     */
    private function should_continue_chunking_dynamic($chunk_content, $full_response, $tokens_used, $target_tokens, $settings) {
        // Check for explicit completion marker
        if (strpos($chunk_content, $settings['completion_marker']) !== false) {
            error_log("SFAIC: Found completion marker, stopping");
            return false;
        }

        // If smart completion is disabled, use simple logic
        if (!$settings['enable_smart_completion']) {
            return $tokens_used < $target_tokens * 0.9;
        }

        // Check minimum content length
        $content_length = strlen(strip_tags($full_response));
        if ($content_length < $settings['min_content_length']) {
            error_log("SFAIC: Content too short ({$content_length} < {$settings['min_content_length']}), continuing");
            return true;
        }

        // Check word count and completion keywords
        $word_count = str_word_count(strip_tags($full_response));
        if ($word_count > $settings['completion_word_count']) {
            // Parse completion keywords
            $keywords = array_map('trim', explode(',', $settings['completion_keywords']));
            $keywords = array_filter($keywords); // Remove empty values
            
            // Check for completion keywords in the recent chunk
            foreach ($keywords as $keyword) {
                if (!empty($keyword) && stripos($chunk_content, $keyword) !== false) {
                    // Additional check: is this near the end with HTML closing tags?
                    if (preg_match('/\b' . preg_quote($keyword, '/') . '\b.*?<\/html>/i', $chunk_content)) {
                        error_log("SFAIC: Found completion keyword '{$keyword}' with HTML ending");
                        return false;
                    }
                }
            }
        }

        // Check token percentage if enabled
        if ($settings['use_token_percentage']) {
            $token_percentage = ($tokens_used / $target_tokens) * 100;
            if ($token_percentage >= $settings['token_completion_threshold']) {
                error_log("SFAIC: Token threshold reached ({$token_percentage}% >= {$settings['token_completion_threshold']}%)");
                
                // If we're over the threshold and have reasonable content, check for natural ending
                if ($word_count > $settings['min_chunk_words']) {
                    $keywords = array_map('trim', explode(',', $settings['completion_keywords']));
                    foreach ($keywords as $keyword) {
                        if (!empty($keyword) && stripos($chunk_content, $keyword) !== false) {
                            error_log("SFAIC: Natural ending found at token threshold");
                            return false;
                        }
                    }
                }
                
                // Force completion if we're way over threshold
                if ($token_percentage >= $settings['token_completion_threshold'] + 10) {
                    error_log("SFAIC: Force stopping due to high token usage");
                    return false;
                }
            }
        }

        // Continue by default
        return true;
    }

    /**
     * NEW: Generate dynamic continuation prompts
     */
    private function generate_dynamic_continuation_prompt($settings, $full_response, $chunk_num, $target_tokens, $tokens_used) {
        $progress_ratio = $tokens_used / $target_tokens;
        $word_count = str_word_count(strip_tags($full_response));
        
        // If we're near the end, encourage completion
        if ($progress_ratio > 0.8) {
            return "Please conclude the HTML report with final recommendations and add " . $settings['completion_marker'] . " when finished.";
        }
        
        // If content is still short, encourage more detail
        if ($word_count < $settings['min_content_length']) {
            return "Please continue developing the HTML report with more detailed content and analysis.";
        }
        
        // Standard continuation prompts
        $prompts = array(
            "Please continue with the next section of the HTML report, maintaining the same structure and styling.",
            "Continue generating the HTML report with additional sections and detailed analysis.",
            "Please proceed with the next portion of the HTML report, ensuring completeness and proper formatting.",
            "Continue with the subsequent sections of the HTML report, providing comprehensive coverage.",
            "Please add the next part of the HTML report with detailed content and proper structure."
        );
        
        return $prompts[$chunk_num % count($prompts)];
    }

    /**
     * UPDATED: Post-process HTML response with dynamic completion marker
     */
    private function post_process_html_response($response, $settings) {
        // Remove the completion marker from the final response
        $response = str_replace($settings['completion_marker'], '', $response);
        
        // Remove any continuation markers
        $response = preg_replace('/\[(continued|part \d+)\]/i', '', $response);
        
        // Clean up any duplicate HTML tags
        $response = preg_replace('/<html[^>]*>.*?<html[^>]*>/i', '<html>', $response);
        
        // Ensure proper HTML structure
        if (strpos($response, '<html') === false && strpos($response, '<!DOCTYPE') === false) {
            // If it's not a complete HTML document, wrap it properly
            $response = "<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"></head>\n<body>\n" . $response . "\n</body>\n</html>";
        }
        
        return trim($response);
    }

    /**
     * Enhanced retry logic with better error handling
     */
    private function make_request_with_retry($conversation, $model, $chunk_tokens, $temperature, $max_retries = 3) {
        $retry_count = 0;
        
        while ($retry_count <= $max_retries) {
            $response = $this->make_request($conversation, $model, $chunk_tokens, $temperature);
            
            if (!is_wp_error($response)) {
                return $response;
            }
            
            $error_message = $response->get_error_message();
            
            // Handle specific errors
            if (strpos($error_message, 'rate limit') !== false) {
                $retry_count++;
                if ($retry_count <= $max_retries) {
                    $wait_time = pow(2, $retry_count); // Exponential backoff
                    error_log("SFAIC: Rate limit hit, waiting {$wait_time} seconds (attempt {$retry_count})");
                    sleep($wait_time);
                    continue;
                }
            } elseif (strpos($error_message, 'maximum context length') !== false) {
                // If we hit context length, try with smaller chunk
                $chunk_tokens = intval($chunk_tokens * 0.7);
                if ($chunk_tokens < 100) {
                    break;
                }
                error_log("SFAIC: Reducing chunk size to {$chunk_tokens} due to context length error");
                $response = $this->make_request($conversation, $model, $chunk_tokens, $temperature);
                if (!is_wp_error($response)) {
                    return $response;
                }
            }
            
            // Non-retryable error or max retries reached
            break;
        }
        
        return $response;
    }

    /**
     * Clean HTML chunks
     */
    private function clean_html_chunk($chunk) {
        // Remove any stray markdown that might have slipped in
        $chunk = preg_replace('/```html\s*/', '', $chunk);
        $chunk = preg_replace('/```\s*$/', '', $chunk);
        
        // Ensure proper HTML structure
        $chunk = trim($chunk);
        
        return $chunk;
    }

    /**
     * Helper method implementations
     */
    protected function get_assistant_role() {
        return 'assistant';
    }

    protected function get_provider_name() {
        return 'claude';
    }

    protected function get_current_model() {
        return get_option('sfaic_claude_model', 'claude-opus-4-20250514');
    }

    /**
     * Format all form data into a structured text for Claude
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

        return $user_prompt;
    }
}