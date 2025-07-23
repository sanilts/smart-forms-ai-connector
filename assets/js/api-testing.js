/**
 * API Testing JavaScript for ChatGPT Fluent Connector Settings
 * Save this as: assets/js/api-testing.js
 */

jQuery(document).ready(function ($) {
    'use strict';

    // Toggle API provider settings visibility
    $('#sfaic_api_provider').on('change', function () {
        var provider = $(this).val();
        $('.sfaic-provider-settings').hide();
        $('#' + provider + '-settings').show();
    });

    /**
     * Test API connection for a specific provider
     */
    function testAPI(provider) {
        var apiKey = '';
        var model = '';
        var resultDiv = '#' + provider + '-test-result';
        var button = '#test-' + provider + '-btn';

        // Get API key and model based on provider
        switch (provider) {
            case 'openai':
                apiKey = $('#sfaic_api_key').val();
                model = $('#sfaic_model').val();
                break;
            case 'gemini':
                apiKey = $('#sfaic_gemini_api_key').val();
                model = $('#sfaic_gemini_model').val();
                break;
            case 'claude':
                apiKey = $('#sfaic_claude_api_key').val();
                model = $('#sfaic_claude_model').val();
                break;
        }

        if (!apiKey) {
            showResult(resultDiv, {
                success: false,
                message: 'Please enter an API key first.',
                type: 'error'
            });
            return;
        }

        // Show loading state
        setButtonLoading(button, true);
        showResult(resultDiv, {
            success: true,
            message: 'Testing connection...',
            type: 'info'
        });

        // Make AJAX request
        $.post(ajaxurl, {
            action: 'sfaic_test_api',
            nonce: sfaic_ajax.test_nonce,
            provider: provider,
            api_key: apiKey,
            model: model
        })
        .done(function (response) {
            if (response.success) {
                showSuccessResult(resultDiv, response, provider);
            } else {
                showErrorResult(resultDiv, response.message);
            }
        })
        .fail(function (xhr, status, error) {
            showErrorResult(resultDiv, 'Request failed: ' + error + '. Please try again.');
        })
        .always(function () {
            setButtonLoading(button, false);
        });
    }

    /**
     * Test PDF generation
     */
    function testPDF() {
        var button = '#test-pdf-btn';
        var resultDiv = '#pdf-test-result';

        setButtonLoading(button, true);
        showResult(resultDiv, {
            success: true,
            message: 'Testing PDF generation...',
            type: 'info'
        });

        $.post(ajaxurl, {
            action: 'sfaic_test_pdf',
            nonce: sfaic_ajax.test_nonce
        })
        .done(function (response) {
            if (response.success) {
                var html = '<div class="notice notice-success inline">';
                html += '<p><strong>✅ PDF Generation Test Successful!</strong></p>';
                html += '<p>' + response.message + '</p>';
                if (response.details && response.details.pdf_size) {
                    html += '<p><strong>Test PDF Size:</strong> ' + response.details.pdf_size + '</p>';
                }
                html += '</div>';
                $(resultDiv).html(html);
            } else {
                showErrorResult(resultDiv, response.message);
            }
        })
        .fail(function (xhr, status, error) {
            showErrorResult(resultDiv, 'Request failed: ' + error + '. Please try again.');
        })
        .always(function () {
            setButtonLoading(button, false);
        });
    }

    /**
     * Show success result with detailed information
     */
    function showSuccessResult(resultDiv, response, provider) {
        var providerNames = {
            'openai': 'OpenAI',
            'gemini': 'Google Gemini',
            'claude': 'Anthropic Claude'
        };

        var html = '<div class="notice notice-success inline">';
        html += '<p><strong>✅ ' + providerNames[provider] + ' Connection Successful!</strong></p>';
        
        if (response.model) {
            html += '<p><strong>Model:</strong> ' + escapeHtml(response.model) + '</p>';
        }
        
        if (response.execution_time) {
            html += '<p><strong>Response Time:</strong> ' + parseFloat(response.execution_time).toFixed(2) + 's</p>';
        }
        
        if (response.tokens && response.tokens.total_tokens > 0) {
            html += '<p><strong>Tokens Used:</strong> ' + response.tokens.total_tokens;
            if (response.tokens.prompt_tokens && response.tokens.completion_tokens) {
                html += ' (Input: ' + response.tokens.prompt_tokens + 
                       ', Output: ' + response.tokens.completion_tokens + ')';
            }
            html += '</p>';
        }
        
        if (response.response) {
            html += '<details style="margin-top: 10px;">';
            html += '<summary style="cursor: pointer; font-weight: bold; padding: 5px 0;">View AI Response</summary>';
            html += '<div style="background: #f9f9f9; padding: 15px; margin-top: 10px; border-radius: 3px; border-left: 4px solid #28a745;">';
            html += '<pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word; font-family: -apple-system, BlinkMacSystemFont, sans-serif; font-size: 14px; line-height: 1.5;">';
            html += escapeHtml(response.response);
            html += '</pre></div></details>';
        }
        
        html += '</div>';
        $(resultDiv).html(html);
    }

    /**
     * Show error result
     */
    function showErrorResult(resultDiv, message) {
        showResult(resultDiv, {
            success: false,
            message: message,
            type: 'error'
        });
    }

    /**
     * Show general result message
     */
    function showResult(resultDiv, result) {
        var noticeClass = 'notice-info';
        var icon = 'ℹ️';

        if (result.type === 'error' || !result.success) {
            noticeClass = 'notice-error';
            icon = '❌';
        } else if (result.type === 'success') {
            noticeClass = 'notice-success';
            icon = '✅';
        }

        var html = '<div class="notice ' + noticeClass + ' inline">';
        html += '<p><strong>' + icon + ' ' + escapeHtml(result.message) + '</strong></p>';
        html += '</div>';

        $(resultDiv).html(html);
    }

    /**
     * Set button loading state
     */
    function setButtonLoading(buttonSelector, isLoading) {
        var button = $(buttonSelector);
        
        if (isLoading) {
            button.prop('disabled', true);
            button.find('.dashicons').addClass('spin');
            
            // Store original text if not already stored
            if (!button.data('original-text')) {
                button.data('original-text', button.text().trim());
            }
        } else {
            button.prop('disabled', false);
            button.find('.dashicons').removeClass('spin');
        }
    }

    /**
     * Escape HTML for safe display
     */
    function escapeHtml(text) {
        if (typeof text !== 'string') {
            return text;
        }
        
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    /**
     * Format file size for display
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Event handlers for test buttons
    $('#test-openai-btn').click(function (e) {
        e.preventDefault();
        testAPI('openai');
    });

    $('#test-gemini-btn').click(function (e) {
        e.preventDefault();
        testAPI('gemini');
    });

    $('#test-claude-btn').click(function (e) {
        e.preventDefault();
        testAPI('claude');
    });

    $('#test-pdf-btn').click(function (e) {
        e.preventDefault();
        testPDF();
    });

    // Auto-clear results when API keys are changed
    $('#sfaic_api_key, #sfaic_gemini_api_key, #sfaic_claude_api_key').on('input', function () {
        $('.api-test-result').empty();
    });

    // Auto-clear results when models are changed
    $('#sfaic_model, #sfaic_gemini_model, #sfaic_claude_model').on('change', function () {
        $('.api-test-result').empty();
    });

    // Show confirmation when navigating away with unsaved changes
    var formChanged = false;
    
    $('form input, form select, form textarea').on('change input', function () {
        formChanged = true;
    });
    
    $('form').on('submit', function () {
        formChanged = false;
    });
    
    $(window).on('beforeunload', function () {
        if (formChanged) {
            return 'You have unsaved changes. Are you sure you want to leave?';
        }
    });

    // Add keyboard shortcuts for testing
    $(document).on('keydown', function (e) {
        // Ctrl/Cmd + T for testing current provider
        if ((e.ctrlKey || e.metaKey) && e.key === 't') {
            e.preventDefault();
            var currentProvider = $('#sfaic_api_provider').val();
            if (currentProvider && $('#' + currentProvider + '-settings').is(':visible')) {
                testAPI(currentProvider);
            }
        }
    });
});