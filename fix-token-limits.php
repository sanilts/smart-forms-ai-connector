<?php
// Function to fix the token limit issue in the standalone processor
function fix_token_limit($prompt_id) {
    // Get the model being used
    $provider = get_option('cgptfc_api_provider', 'openai');
    
    if ($provider === 'openai') {
        $model = get_option('cgptfc_model', 'gpt-3.5-turbo');
        
        // Token limits for OpenAI models
        $token_limits = [
            'gpt-3.5-turbo' => 4000,  // Actually 4096, but leaving buffer
            'gpt-4' => 8000,          // Actually 8192, but leaving buffer
            'gpt-4-turbo' => 128000,  // Very large context models
            'gpt-4-turbo-preview' => 128000,
            'gpt-4-1106-preview' => 128000,
            'gpt-4-0613' => 8000,
            'gpt-4-0125-preview' => 128000
        ];
        
        // Get the default limit based on model, or use 4000 if unknown
        $max_tokens = isset($token_limits[$model]) ? $token_limits[$model] : 4000;
        
        // Get the current setting
        $current_max_tokens = get_post_meta($prompt_id, '_cgptfc_max_tokens', true);
        
        // If current setting is too high, update it
        if (intval($current_max_tokens) > $max_tokens) {
            update_post_meta($prompt_id, '_cgptfc_max_tokens', $max_tokens);
            return "Fixed max_tokens setting for prompt ID {$prompt_id}: Changed from {$current_max_tokens} to {$max_tokens}";
        } else {
            return "Current max_tokens setting ({$current_max_tokens}) is within limits for model {$model}";
        }
    } else if ($provider === 'gemini') {
        // For Gemini, we'll just set a reasonable limit
        $max_tokens = 8000;
        
        // Get the current setting
        $current_max_tokens = get_post_meta($prompt_id, '_cgptfc_max_tokens', true);
        
        // If current setting is too high, update it
        if (intval($current_max_tokens) > $max_tokens) {
            update_post_meta($prompt_id, '_cgptfc_max_tokens', $max_tokens);
            return "Fixed max_tokens setting for prompt ID {$prompt_id}: Changed from {$current_max_tokens} to {$max_tokens}";
        } else {
            return "Current max_tokens setting ({$current_max_tokens}) is within limits for Gemini API";
        }
    }
    
    return "Unknown provider: {$provider}";
}