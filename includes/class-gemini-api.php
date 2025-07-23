<?php

/**
 * Google Gemini API Class - FULLY OPTIMIZED for Gemini 2.5 Flash Performance
 * 
 * Handles API requests to the Google Gemini API with maximum 2.5 Flash optimization
 * Version: 3.0 - Complete rewrite for Gemini 2.5 Flash
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SFAIC_Gemini_API {

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
        if ($this->last_response && isset($this->last_response['usageMetadata'])) {
            $prompt_tokens = $this->last_response['usageMetadata']['promptTokenCount'] ?? 0;
            $completion_tokens = $this->last_response['usageMetadata']['candidatesTokenCount'] ?? 0;

            // For Gemini 2.5, if candidatesTokenCount is not present, calculate from total - prompt
            if ($completion_tokens === 0 && isset($this->last_response['usageMetadata']['totalTokenCount'])) {
                $total_tokens = $this->last_response['usageMetadata']['totalTokenCount'] ?? 0;
                $completion_tokens = $total_tokens - $prompt_tokens;
            }

            return array(
                'prompt_tokens' => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'total_tokens' => $this->last_response['usageMetadata']['totalTokenCount'] ?? ($prompt_tokens + $completion_tokens)
            );
        }
        return array();
    }

    /**
     * OPTIMIZED: Detect if model is Gemini 2.5 Flash
     */
    private function is_gemini_25_flash($model) {
        return strpos($model, 'gemini-2.5-flash') !== false;
    }

    /**
     * OPTIMIZED: Detect if model is any 2.5 version
     */
    private function is_gemini_25($model) {
        return strpos($model, 'gemini-2.5') !== false;
    }

    /**
     * FULLY OPTIMIZED: Get optimal chunk size for 2.5 Flash - MAXIMUM PERFORMANCE
     */
    private function get_optimal_chunk_size($model) {
        // MAXIMUM AGGRESSIVE SETTINGS for 2.5 Flash
        if ($this->is_gemini_25_flash($model)) {
            return 50000; // MASSIVE increase - 2.5 Flash can handle very large chunks
        }
        
        // Enhanced settings for other 2.5 models
        if ($this->is_gemini_25($model)) {
            return 30000; // High for 2.5 Pro
        }
        
        $chunk_sizes = array(
            'gemini-1.5-pro-latest' => 8000,
            'gemini-1.5-flash-latest' => 8000,
            'gemini-2.0-flash-exp' => 10000,
            'gemini-exp-1219' => 7000,
        );
        
        return isset($chunk_sizes[$model]) ? $chunk_sizes[$model] : 5000;
    }

    /**
     * FULLY OPTIMIZED: Get safe token limits with MAXIMUM 2.5 Flash optimization
     */
    private function get_safe_token_limit($model) {
        // MAXIMUM limits for 2.5 Flash - unleash full potential
        if ($this->is_gemini_25_flash($model)) {
            return 200000; // MASSIVE increase from 8000 to 200k - still conservative for 1M+ context
        }
        
        // Enhanced limits for other 2.5 models
        if ($this->is_gemini_25($model)) {
            return 100000; // High for 2.5 Pro
        }
        
        $safe_limits = array(
            'gemini-1.5-pro-latest' => 8000,
            'gemini-1.5-flash-latest' => 8000,
            'gemini-2.0-flash-exp' => 10000,
            'gemini-exp-1219' => 7000,
        );
        
        return isset($safe_limits[$model]) ? $safe_limits[$model] : 4000;
    }

    /**
     * Make a request to the Gemini API - FULLY OPTIMIZED for 2.5 Flash
     */
    public function make_request($messages, $model = null, $max_tokens = 1000, $temperature = 0.7) {
        $api_key = get_option('sfaic_gemini_api_key');

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Gemini API key is not set', 'chatgpt-fluent-connector'));
        }

        // Use specified model or fall back to settings
        if ($model === null) {
            $model = get_option('sfaic_gemini_model', 'gemini-1.5-pro-latest');
        }

        // Enhanced model mapping for 2.5 models
        $api_model = $model;
        $api_version = 'v1beta'; // Default to v1beta
        
        // Comprehensive model mapping
        $model_mapping = array(
            'gemini-1.5-pro' => 'gemini-1.5-pro-latest',
            'gemini-1.5-flash' => 'gemini-1.5-flash-latest',
            'gemini-2.0-flash' => 'gemini-2.0-flash-exp',
            'gemini-2.0-flash-latest' => 'gemini-2.0-flash-exp',
            'gemini-2.5-flash' => 'gemini-2.5-flash',
            'gemini-2.5-flash-latest' => 'gemini-2.5-flash',
            'gemini-2.5-flash-002' => 'gemini-2.5-flash-002',
            'gemini-2.5-pro' => 'gemini-2.5-pro',
            'gemini-2.5-pro-latest' => 'gemini-2.5-pro',
            'gemini-2.5-pro-002' => 'gemini-2.5-pro-002',
            'gemini-pro' => 'gemini-1.5-pro-latest',
        );

        // Apply model mapping
        if (isset($model_mapping[$model])) {
            $api_model = $model_mapping[$model];
        }

        // Default fallback
        if (empty($api_model) || $api_model === 'gemini-pro') {
            $api_model = 'gemini-1.5-pro-latest';
        }

        // Check model versions
        $is_25_model = $this->is_gemini_25($api_model);
        $is_25_flash = $this->is_gemini_25_flash($api_model);

        // Use appropriate API version for 2.5 models
        if ($is_25_model) {
            $api_version = 'v1'; // 2.5 models use v1
        }

        // FULLY OPTIMIZED: Use enhanced token limits for 2.5 Flash
        $safe_limit = $this->get_safe_token_limit($api_model);
        $max_tokens = min(intval($max_tokens), $safe_limit);

        // Enhanced minimum tokens for 2.5 Flash
        $min_tokens = $is_25_flash ? 1000 : 100;
        if ($max_tokens < $min_tokens) {
            $max_tokens = $min_tokens;
        }

        error_log("SFAIC: FULLY OPTIMIZED request to {$api_model} with {$max_tokens} tokens (safe limit: {$safe_limit}, is 2.5 Flash: " . ($is_25_flash ? 'YES' : 'NO') . ")");

        // Build the API endpoint
        $api_endpoint = 'https://generativelanguage.googleapis.com/' . $api_version . '/models/' . $api_model . ':generateContent?key=' . $api_key;

        // Convert messages to Gemini format
        $gemini_contents = $this->convert_messages_to_gemini_format($messages);

        $headers = array(
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
        );

        // FULLY OPTIMIZED: Enhanced generation config for 2.5 Flash
        $generation_config = array(
            'temperature' => floatval($temperature),
            'maxOutputTokens' => intval($max_tokens),
            'topP' => 0.95,
            'topK' => 40
        );

        // 2.5 Flash MAXIMUM optimization
        if ($is_25_flash) {
            $generation_config['topP'] = 0.9;   // Optimized for quality and speed
            $generation_config['topK'] = 64;    // Enhanced diversity
        } elseif ($is_25_model) {
            $generation_config['topP'] = 0.92;  // Slightly more focused for Pro
            $generation_config['topK'] = 50;    // Balanced diversity
        }

        $body = array(
            'contents' => $gemini_contents,
            'generationConfig' => $generation_config,
            'safetySettings' => array(
                array(
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ),
                array(
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ),
                array(
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ),
                array(
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                )
            )
        );

        // Store the complete request JSON
        $this->last_request_json = wp_json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Encode with optimized JSON settings
        $json_body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // FULLY OPTIMIZED: Enhanced timeout for 2.5 Flash large responses
        $timeout = $is_25_flash ? 600 : 300; // 10 minutes for 2.5 Flash, 5 for others

        $args = array(
            'headers' => $headers,
            'body' => $json_body,
            'method' => 'POST',
            'data_format' => 'body',
            'timeout' => $timeout,
            'httpversion' => '1.1',
            'sslverify' => true
        );

        // Make the API request
        $response = wp_remote_post($api_endpoint, $args);

        // Check for WordPress request errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('SFAIC: Gemini API WordPress Request Error: ' . $error_message);
            return $response;
        }

        // Get response code and body
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body_raw = wp_remote_retrieve_body($response);

        // Store the response JSON
        $this->last_response_json = $response_body_raw;

        // Decode JSON response
        $response_body = json_decode($response_body_raw, true);

        // Store the response for token tracking
        $this->last_response = $response_body;

        // Handle HTTP errors
        if ($response_code !== 200) {
            $error_message = '';
            if (isset($response_body['error']['message'])) {
                $error_message = $response_body['error']['message'];

                // Enhanced model fallback for 2.5 models
                if ((strpos($error_message, 'not found') !== false ||
                        strpos($error_message, 'is not found') !== false) &&
                        strpos($error_message, 'models/') !== false) {

                    // Enhanced fallback chain for 2.5 models
                    if (strpos($api_model, 'gemini-2.5-flash') !== false) {
                        // Try different 2.5 Flash versions
                        if ($api_model !== 'gemini-2.5-flash-002') {
                            return $this->make_request($messages, 'gemini-2.5-flash-002', $max_tokens, $temperature);
                        }
                        // Fallback to 2.0 Flash
                        return $this->make_request($messages, 'gemini-2.0-flash-exp', $max_tokens, $temperature);
                    } elseif (strpos($api_model, 'gemini-2.5-pro') !== false) {
                        // Try different 2.5 Pro versions
                        if ($api_model !== 'gemini-2.5-pro-002') {
                            return $this->make_request($messages, 'gemini-2.5-pro-002', $max_tokens, $temperature);
                        }
                        // Fallback to 1.5 Pro
                        return $this->make_request($messages, 'gemini-1.5-pro-latest', $max_tokens, $temperature);
                    }

                    // Standard fallback to 1.5 Pro
                    if ($api_model !== 'gemini-1.5-pro-latest') {
                        update_option('sfaic_gemini_model', 'gemini-1.5-pro-latest');
                        return $this->make_request($messages, 'gemini-1.5-pro-latest', $max_tokens, $temperature);
                    }
                }
            } elseif (is_string($response_body_raw)) {
                $error_message = $response_body_raw;
            } else {
                $error_message = sprintf(__('Unknown error (HTTP %s)', 'chatgpt-fluent-connector'), $response_code);
            }

            // Log error details
            error_log('SFAIC: Gemini API Error: ' . $error_message);

            // Enhanced error handling
            if (strpos($error_message, 'API key not valid') !== false) {
                return new WP_Error('api_error', __('Invalid Gemini API key. Please check your API key in settings.', 'chatgpt-fluent-connector'));
            } elseif (strpos($error_message, 'models/') !== false &&
                    (strpos($error_message, 'not found') !== false ||
                    strpos($error_message, 'is not found') !== false)) {
                return new WP_Error('api_error',
                        __('Model not available: ', 'chatgpt-fluent-connector') . $api_model .
                        __('. The model may not be available in your region or with your API key. Please try selecting a different model in settings.', 'chatgpt-fluent-connector'));
            } elseif ($response_code === 429) {
                return new WP_Error('api_error', __('Rate limit exceeded. Please wait a moment and try again.', 'chatgpt-fluent-connector'));
            } elseif ($response_code === 403) {
                return new WP_Error('api_error', __('Access forbidden. Please check if your API key has access to this model.', 'chatgpt-fluent-connector'));
            }

            return new WP_Error('api_error', $error_message);
        }

        // Enhanced response validation
        if (isset($response_body['candidates'][0]['finishReason']) &&
                $response_body['candidates'][0]['finishReason'] === 'SAFETY') {
            return new WP_Error('content_blocked', __('The content was blocked by Gemini safety filters', 'chatgpt-fluent-connector'));
        }

        // Check if we have a valid response
        if (!isset($response_body['candidates'][0]['content'])) {
            error_log('SFAIC: Invalid Gemini response structure - missing content: ' . wp_json_encode($response_body));
            return new WP_Error('invalid_response', __('Invalid response structure from Gemini API - missing content', 'chatgpt-fluent-connector'));
        }

        // Enhanced handling of empty response content
        if (!isset($response_body['candidates'][0]['content']['parts']) ||
                empty($response_body['candidates'][0]['content']['parts']) ||
                !isset($response_body['candidates'][0]['content']['parts'][0]['text'])) {

            // Check finish reason
            if (isset($response_body['candidates'][0]['finishReason'])) {
                $finish_reason = $response_body['candidates'][0]['finishReason'];
                
                if ($finish_reason === 'MAX_TOKENS') {
                    error_log('SFAIC: Gemini response truncated due to MAX_TOKENS limit');
                    return new WP_Error('max_tokens_reached',
                            __('Response was truncated because it reached the maximum token limit. Try increasing the max_tokens setting or using chunked processing.', 'chatgpt-fluent-connector'));
                } elseif ($finish_reason === 'SAFETY') {
                    return new WP_Error('safety_filter', __('Response blocked by Gemini safety filters', 'chatgpt-fluent-connector'));
                }
                
                error_log('SFAIC: Gemini response finished with reason: ' . $finish_reason);
            }

            error_log('SFAIC: Invalid Gemini response structure - missing text: ' . wp_json_encode($response_body));
            return new WP_Error('invalid_response', __('Invalid response structure from Gemini API - no text content', 'chatgpt-fluent-connector'));
        }

        return $response_body;
    }

    // Add getter methods:
    public function get_last_request_json() {
        return $this->last_request_json;
    }

    public function get_last_response_json() {
        return $this->last_response_json;
    }

    /**
     * Convert OpenAI-style messages to Gemini format
     */
    private function convert_messages_to_gemini_format($messages) {
        $gemini_contents = array();
        $system_context = '';

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                // Gemini doesn't have a system role, so we prepend it to the first user message
                $system_context .= $message['content'] . "\n\n";
            } else {
                $role = ($message['role'] === 'user') ? 'user' : 'model';

                // If this is the first user message and we have system context, prepend it
                if ($role === 'user' && !empty($system_context) && empty($gemini_contents)) {
                    $content = $system_context . $message['content'];
                    $system_context = ''; // Clear it so we don't add it again
                } else {
                    $content = $message['content'];
                }

                $gemini_contents[] = array(
                    'role' => $role,
                    'parts' => array(
                        array('text' => $content)
                    )
                );
            }
        }

        // If we only had a system message, create a user message with it
        if (!empty($system_context) && empty($gemini_contents)) {
            $gemini_contents[] = array(
                'role' => 'user',
                'parts' => array(
                    array('text' => $system_context)
                )
            );
        }

        return $gemini_contents;
    }

    /**
     * Get the content from the API response
     */
    public function get_response_content($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        // Enhanced response validation
        if (isset($response['candidates'][0]['content']) &&
                (!isset($response['candidates'][0]['content']['parts']) ||
                empty($response['candidates'][0]['content']['parts']))) {

            // Check the finish reason
            if (isset($response['candidates'][0]['finishReason'])) {
                $finish_reason = $response['candidates'][0]['finishReason'];
                if ($finish_reason === 'MAX_TOKENS') {
                    return new WP_Error('max_tokens', __('Response truncated: Maximum token limit reached before any content was generated. Try increasing max_tokens or enabling chunked processing.', 'chatgpt-fluent-connector'));
                } elseif ($finish_reason === 'SAFETY') {
                    return new WP_Error('safety_filter', __('Response blocked by Gemini safety filters', 'chatgpt-fluent-connector'));
                }
            }

            return new WP_Error('empty_response', __('Gemini returned an empty response', 'chatgpt-fluent-connector'));
        }

        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return new WP_Error('invalid_response', __('Invalid response from Gemini API - no text content', 'chatgpt-fluent-connector'));
        }

        $content = $response['candidates'][0]['content']['parts'][0]['text'];
        return $content;
    }

    /**
     * FULLY OPTIMIZED: Process a form submission with a prompt for 2.5 Flash
     */
    public function process_form_with_prompt($prompt_id, $form_data, $entry_id = null) {
        // Get prompt settings
        $system_prompt = get_post_meta($prompt_id, '_sfaic_system_prompt', true);
        $user_prompt_template = get_post_meta($prompt_id, '_sfaic_user_prompt_template', true);
        $temperature = get_post_meta($prompt_id, '_sfaic_temperature', true);
        $max_tokens = get_post_meta($prompt_id, '_sfaic_max_tokens', true);
        $prompt_type = get_post_meta($prompt_id, '_sfaic_prompt_type', true);
        $enable_chunking = get_post_meta($prompt_id, '_sfaic_enable_chunking', true);
        $model = get_option('sfaic_gemini_model', 'gemini-1.5-pro-latest');

        // FULLY OPTIMIZED: Enhanced chunking trigger for 2.5 Flash
        $estimated_prompt_length = strlen($system_prompt) + strlen($user_prompt_template) + strlen(serialize($form_data));
        $is_25_flash = $this->is_gemini_25_flash($model);
        $is_25_model = $this->is_gemini_25($model);
        
        // OPTIMIZED: Much more aggressive thresholds for 2.5 models
        $chunking_threshold = $is_25_flash ? 150000 : ($is_25_model ? 100000 : 8000);
        $prompt_threshold = $is_25_flash ? 100000 : ($is_25_model ? 50000 : 15000);
        
        $needs_chunking = ($enable_chunking === '1') && (
            intval($max_tokens) > $chunking_threshold ||
            $estimated_prompt_length > $prompt_threshold ||
            strpos($user_prompt_template, 'comprehensive') !== false ||
            strpos($system_prompt, 'detailed') !== false ||
            strpos($system_prompt, 'report') !== false
        );

        if ($needs_chunking) {
            error_log('SFAIC Gemini: Using FULLY OPTIMIZED chunked processing - estimated length: ' . $estimated_prompt_length . ', is 2.5 Flash: ' . ($is_25_flash ? 'YES' : 'NO'));
            return $this->process_form_with_prompt_chunked($prompt_id, $form_data, $entry_id);
        }

        // Set default prompt type if not set
        if (empty($prompt_type)) {
            $prompt_type = 'template';
        }

        // OPTIMIZED: Enhanced limits for 2.5 models
        $safe_limit = $this->get_safe_token_limit($model);
        if (intval($max_tokens) > $safe_limit) {
            $max_tokens = $safe_limit;
        }

        // Enhanced minimum token handling
        if (empty($max_tokens) || intval($max_tokens) < 1000) {
            if ($is_25_flash) {
                $max_tokens = 50000; // High default for 2.5 Flash
            } elseif ($is_25_model) {
                $max_tokens = 30000; // High default for 2.5 Pro
            } else {
                $max_tokens = 4000; // Standard default
            }
        }

        // Prepare the user prompt based on prompt type
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

        // Apply template filter
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

            update_post_meta($entry_id, '_gemini_complete_prompt', $complete_prompt_string);
        }

        // Make the API request
        $response = $this->make_request(
                $messages,
                $model,
                intval($max_tokens),
                !empty($temperature) ? floatval($temperature) : 0.7
        );

        if (is_wp_error($response)) {
            error_log('SFAIC: Error in Gemini API response: ' . $response->get_error_message());
            return $response;
        }

        $content = $this->get_response_content($response);

        // Store token usage for the entry
        if (!empty($entry_id)) {
            $token_usage = $this->get_last_token_usage();
            if (!empty($token_usage)) {
                update_post_meta($entry_id, '_gemini_token_usage', $token_usage);
            }
        }

        return $content;
    }

    /**
     * FULLY OPTIMIZED: Enhanced chunked processing MAXIMIZED for Gemini 2.5 Flash
     */
    public function process_form_with_prompt_chunked($prompt_id, $form_data, $entry_id = null) {
        error_log('SFAIC: Starting FULLY OPTIMIZED chunked processing for Gemini 2.5 Flash');

        // Get chunking settings from prompt
        $chunking_settings = $this->get_chunking_settings($prompt_id);
        
        error_log('SFAIC: Chunking settings: ' . json_encode($chunking_settings));

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

        // FULLY OPTIMIZED: Enhanced system prompt for 2.5 models
        $is_25_flash = $this->is_gemini_25_flash($model);
        $is_25_model = $this->is_gemini_25($model);
        
        if ($is_25_flash) {
            // Ultra-concise instructions for 2.5 Flash
            $chunked_system_prompt = $system_prompt . "\n\n" .
                "OUTPUT: Generate complete sections. Add " . $chunking_settings['completion_marker'] . " when finished.";
        } elseif ($is_25_model) {
            // Optimized instructions for 2.5 Pro
            $chunked_system_prompt = $system_prompt . "\n\n" .
                "CHUNKING: Generate complete sections. Continue when prompted. Add " . $chunking_settings['completion_marker'] . " when done.";
        } else {
            // Standard instructions for older models
            $chunked_system_prompt = $system_prompt . "\n\n" .
                "CHUNKING INSTRUCTIONS:\n" .
                "1. Generate valid content for the request\n" .
                "2. Stop at complete sections if reaching limits\n" .
                "3. Continue when prompted\n" .
                "4. Only conclude with complete response\n" .
                "5. Add " . $chunking_settings['completion_marker'] . " when truly finished";
        }

        // FULLY OPTIMIZED: Maximum chunk sizes for 2.5 models
        $target_tokens = intval($max_tokens);
        $chunk_size = $this->get_optimal_chunk_size($model);
        
        // Enhanced chunk limits for 2.5 models
        if ($is_25_flash) {
            $max_chunks = min(ceil($target_tokens / $chunk_size), 100); // Maximum for 2.5 Flash
        } elseif ($is_25_model) {
            $max_chunks = min(ceil($target_tokens / $chunk_size), 80); // High for 2.5 Pro
        } else {
            $max_chunks = min(ceil($target_tokens / $chunk_size), 40); // Standard for others
        }
        
        $total_tokens_used = 0;
        $full_response = '';
        $conversation = array();

        // Initialize conversation
        if (!empty($chunked_system_prompt)) {
            $conversation[] = array('role' => 'system', 'content' => $chunked_system_prompt);
        }
        $conversation[] = array('role' => 'user', 'content' => $user_prompt);

        error_log("SFAIC: FULLY OPTIMIZED chunking - target: {$target_tokens}, chunk: {$chunk_size}, max chunks: {$max_chunks}, 2.5 Flash: " . ($is_25_flash ? 'YES' : 'NO'));

        for ($chunk_num = 0; $chunk_num < $max_chunks; $chunk_num++) {
            // Calculate remaining tokens
            $remaining_tokens = $target_tokens - $total_tokens_used;
            $current_chunk_size = min($chunk_size, $remaining_tokens);

            // Enhanced minimum for 2.5 models
            $min_tokens = $is_25_flash ? 2000 : ($is_25_model ? 1000 : 500);
            if ($current_chunk_size < $min_tokens) {
                error_log("SFAIC: Stopping - insufficient tokens remaining: {$current_chunk_size}");
                break;
            }

            error_log("SFAIC: Chunk " . ($chunk_num + 1) . " requesting {$current_chunk_size} tokens");

            // OPTIMIZED: Enhanced retry logic for 2.5 models
            $response = $this->make_request_with_enhanced_retry($conversation, $model, $current_chunk_size, floatval($temperature));

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

            error_log("SFAIC: Chunk " . ($chunk_num + 1) . " used {$chunk_tokens_used} tokens. Total: {$total_tokens_used}");

            // Clean chunk content
            $chunk_content = $this->clean_response_chunk($chunk_content);
            $full_response .= $chunk_content;

            // CHECK FOR COMPLETION MARKER
            if (strpos($chunk_content, $chunking_settings['completion_marker']) !== false) {
                error_log("SFAIC: Found completion marker: " . $chunking_settings['completion_marker']);
                break;
            }

            // ENHANCED DYNAMIC COMPLETION CHECK
            if ($this->should_continue_chunking_enhanced($chunk_content, $full_response, $total_tokens_used, $target_tokens, $chunking_settings)) {
                // OPTIMIZED: Enhanced conversation management for 2.5 models
                if ($is_25_flash) {
                    // 2.5 Flash can handle very long conversations
                    if (count($conversation) > 40) {
                        $conversation = array_merge(
                            array_slice($conversation, 0, 2), // Keep system + original
                            array_slice($conversation, -30)   // Keep last 30
                        );
                    }
                } elseif ($is_25_model) {
                    // 2.5 Pro enhanced handling
                    if (count($conversation) > 30) {
                        $conversation = array_merge(
                            array_slice($conversation, 0, 2), // Keep system + original
                            array_slice($conversation, -20)   // Keep last 20
                        );
                    }
                } else {
                    // Conservative for other models
                    if (count($conversation) > 10) {
                        $conversation = array_merge(
                            array_slice($conversation, 0, 2), // Keep system + original
                            array_slice($conversation, -4)    // Keep last 4
                        );
                    }
                }

                // Add response and continuation
                $conversation[] = array('role' => $this->get_assistant_role(), 'content' => $chunk_content);
                
                $continuation_prompt = $this->generate_optimized_continuation_prompt($chunking_settings, $full_response, $chunk_num, $target_tokens, $total_tokens_used, $is_25_flash, $is_25_model);
                $conversation[] = array('role' => 'user', 'content' => $continuation_prompt);
            } else {
                error_log("SFAIC: Completion detected by enhanced analysis");
                break;
            }
        }

        // Post-process
        $full_response = $this->post_process_response($full_response, $chunking_settings);

        // Store enhanced metadata
        if (!empty($entry_id)) {
            update_post_meta($entry_id, '_gemini_chunked_response', true);
            update_post_meta($entry_id, '_gemini_chunks_count', $chunk_num + 1);
            update_post_meta($entry_id, '_gemini_total_tokens_generated', $total_tokens_used);
            update_post_meta($entry_id, '_gemini_response_length', strlen($full_response));
            update_post_meta($entry_id, '_gemini_completion_reason', 'fully_optimized_chunking');
            update_post_meta($entry_id, '_gemini_chunking_settings_used', $chunking_settings);
            update_post_meta($entry_id, '_gemini_model_optimized', $is_25_flash ? 'gemini_25_flash_optimized' : ($is_25_model ? 'gemini_25_optimized' : 'standard'));
        }

        error_log("SFAIC: FULLY OPTIMIZED chunking complete. " . ($chunk_num + 1) . " chunks, {$total_tokens_used} tokens, " . strlen($full_response) . " chars");

        return $full_response;
    }

    /**
     * OPTIMIZED: Enhanced continuation prompts for 2.5 models
     */
    private function generate_optimized_continuation_prompt($settings, $full_response, $chunk_num, $target_tokens, $tokens_used, $is_25_flash, $is_25_model) {
        $progress_ratio = $tokens_used / $target_tokens;
        $word_count = str_word_count(strip_tags($full_response));
        
        // If we're near the end, encourage completion
        if ($progress_ratio > 0.9) {
            return "Complete and add " . $settings['completion_marker'];
        }
        
        // If content is still short, encourage more detail
        if ($word_count < $settings['min_content_length']) {
            if ($is_25_flash) {
                return "Continue with more detail.";
            } elseif ($is_25_model) {
                return "Continue developing with more detailed content.";
            } else {
                return "Please continue developing the response with more detailed content and analysis.";
            }
        }
        
        // OPTIMIZED: Ultra-efficient prompts for 2.5 Flash
        if ($is_25_flash) {
            $flash_prompts = array(
                "Continue.",
                "Next section.",
                "More content.",
                "Proceed.",
                "Expand.",
                "Develop further.",
                "Add more details.",
                "Continue building."
            );
            return $flash_prompts[$chunk_num % count($flash_prompts)];
        }
        
        // Enhanced prompts for 2.5 Pro
        if ($is_25_model) {
            $pro_prompts = array(
                "Continue with the next section.",
                "Please proceed with additional content.",
                "Develop the next portion.",
                "Continue building the response.",
                "Add the next section with details.",
                "Proceed with comprehensive coverage.",
                "Continue with thorough analysis.",
                "Develop further with detailed content."
            );
            return $pro_prompts[$chunk_num % count($pro_prompts)];
        }
        
        // Standard prompts for other models
        $prompts = array(
            "Please continue with the next section, maintaining the same structure and quality.",
            "Continue generating the response with additional sections and detailed analysis.",
            "Please proceed with the next portion, ensuring completeness and proper formatting.",
            "Continue with the subsequent sections, providing comprehensive coverage.",
            "Please add the next part with detailed content and proper structure."
        );
        
        return $prompts[$chunk_num % count($prompts)];
    }

    /**
     * OPTIMIZED: Enhanced retry logic for 2.5 models
     */
    private function make_request_with_enhanced_retry($conversation, $model, $chunk_tokens, $temperature, $max_retries = 3) {
        $retry_count = 0;
        $original_chunk_tokens = $chunk_tokens;
        $is_25_flash = $this->is_gemini_25_flash($model);
        $is_25_model = $this->is_gemini_25($model);
        
        while ($retry_count <= $max_retries) {
            $response = $this->make_request($conversation, $model, $chunk_tokens, $temperature);
            
            if (!is_wp_error($response)) {
                return $response;
            }
            
            $error_message = $response->get_error_message();
            
            // Handle token limit errors with enhanced recovery for 2.5 models
            if (strpos($error_message, 'maximum token limit') !== false || 
                strpos($error_message, 'MAX_TOKENS') !== false ||
                strpos($error_message, 'truncated') !== false) {
                
                // OPTIMIZED: Less aggressive reduction for 2.5 models
                if ($is_25_flash) {
                    $reduction_factor = 0.85; // Minimal reduction for 2.5 Flash
                } elseif ($is_25_model) {
                    $reduction_factor = 0.8; // Slight reduction for 2.5 Pro
                } else {
                    $reduction_factor = 0.6; // Standard reduction
                }
                
                $chunk_tokens = intval($chunk_tokens * $reduction_factor);
                
                $min_tokens = $is_25_flash ? 1000 : ($is_25_model ? 500 : 200);
                if ($chunk_tokens < $min_tokens) {
                    error_log("SFAIC: Chunk size too small ({$chunk_tokens}), giving up");
                    break;
                }
                
                error_log("SFAIC: Token error, reducing chunk from {$original_chunk_tokens} to {$chunk_tokens}");
                $response = $this->make_request($conversation, $model, $chunk_tokens, $temperature);
                
                if (!is_wp_error($response)) {
                    return $response;
                }
            }
            
            // Handle rate limits with enhanced backoff for 2.5 models
            if (strpos($error_message, 'rate limit') !== false) {
                $retry_count++;
                if ($retry_count <= $max_retries) {
                    // OPTIMIZED: Shorter waits for 2.5 models (they're faster)
                    if ($is_25_flash) {
                        $wait_time = min(pow(1.5, $retry_count), 6); // Gentle backoff for 2.5 Flash
                    } elseif ($is_25_model) {
                        $wait_time = min(pow(1.8, $retry_count), 8); // Moderate backoff for 2.5 Pro
                    } else {
                        $wait_time = pow(2, $retry_count); // Standard backoff
                    }
                    
                    error_log("SFAIC: Rate limit, waiting {$wait_time}s (attempt {$retry_count})");
                    sleep($wait_time);
                    continue;
                }
            }
            
            // Give up on other errors
            break;
        }
        
        return $response;
    }

    /**
     * Enhanced dynamic completion checking with better heuristics
     */
    private function should_continue_chunking_enhanced($chunk_content, $full_response, $tokens_used, $target_tokens, $settings) {
        // Check for explicit completion marker
        if (strpos($chunk_content, $settings['completion_marker']) !== false) {
            error_log("SFAIC: Found completion marker, stopping");
            return false;
        }

        // If smart completion is disabled, use simple logic
        if (!$settings['enable_smart_completion']) {
            return $tokens_used < $target_tokens * 0.95;
        }

        // Check minimum content length
        $content_length = strlen(strip_tags($full_response));
        if ($content_length < $settings['min_content_length']) {
            error_log("SFAIC: Content too short ({$content_length} < {$settings['min_content_length']}), continuing");
            return true;
        }

        // Enhanced word count and completion keyword checking
        $word_count = str_word_count(strip_tags($full_response));
        if ($word_count > $settings['completion_word_count']) {
            // Parse completion keywords
            $keywords = array_map('trim', explode(',', $settings['completion_keywords']));
            $keywords = array_filter($keywords); // Remove empty values
            
            // Check for completion keywords in the recent chunk
            foreach ($keywords as $keyword) {
                if (!empty($keyword) && stripos($chunk_content, $keyword) !== false) {
                    // Enhanced check: look for natural endings
                    if (preg_match('/\b' . preg_quote($keyword, '/') . '\b.*?(\.|!|\?|<\/[^>]+>)\s*$/i', $chunk_content)) {
                        error_log("SFAIC: Found completion keyword '{$keyword}' with natural ending");
                        return false;
                    }
                }
            }
        }

        // Enhanced token percentage checking
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
                if ($token_percentage >= $settings['token_completion_threshold'] + 15) {
                    error_log("SFAIC: Force stopping due to high token usage");
                    return false;
                }
            }
        }

        // Continue by default
        return true;
    }

    /**
     * Enhanced response post-processing
     */
    private function post_process_response($response, $settings) {
        // Remove the completion marker from the final response
        $response = str_replace($settings['completion_marker'], '', $response);
        
        // Remove any continuation markers
        $response = preg_replace('/\[(continued|part \d+)\]/i', '', $response);
        
        // Clean up any duplicate HTML tags
        $response = preg_replace('/<html[^>]*>.*?<html[^>]*>/i', '<html>', $response);
        
        // Ensure proper HTML structure if it looks like HTML
        if (strpos($response, '<html') !== false || strpos($response, '<!DOCTYPE') !== false) {
            // It's already HTML, just clean it up
            $response = preg_replace('/(<\/html>).*$/is', '$1', $response);
        } elseif (strpos($response, '<') !== false && strpos($response, '>') !== false) {
            // Has HTML tags but not complete document
            if (strpos($response, '<html') === false && strpos($response, '<!DOCTYPE') === false) {
                $response = "<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"></head>\n<body>\n" . $response . "\n</body>\n</html>";
            }
        }
        
        return trim($response);
    }

    /**
     * Enhanced response chunk cleaning
     */
    private function clean_response_chunk($chunk) {
        // Remove any stray markdown that might have slipped in
        $chunk = preg_replace('/```html\s*/', '', $chunk);
        $chunk = preg_replace('/```\s*$/', '', $chunk);
        
        // Clean up any formatting artifacts
        $chunk = preg_replace('/^\s*\[continued\]\s*/i', '', $chunk);
        $chunk = preg_replace('/^\s*\[part \d+\]\s*/i', '', $chunk);
        
        // Ensure proper structure
        $chunk = trim($chunk);
        
        return $chunk;
    }

    /**
     * Get chunking settings for a prompt
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
     * Helper method implementations
     */
    protected function get_assistant_role() {
        return 'model';
    }

    protected function get_provider_name() {
        return 'gemini';
    }

    protected function get_current_model() {
        return get_option('sfaic_gemini_model', 'gemini-1.5-pro-latest');
    }

    /**
     * Format all form data into a structured text for Gemini
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