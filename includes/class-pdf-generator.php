<?php

/**
 * Enhanced PDF Generator Class - With api2pdf Integration
 * 
 * Handles PDF generation from AI responses with multiple service options:
 * - Local mPDF (existing)
 * - Cloud-based api2pdf (new)
 */
class SFAIC_PDF_Generator {

    /**
     * Available PDF services
     */
    const SERVICE_MPDF = 'mpdf';
    const SERVICE_API2PDF = 'api2pdf';

    /**
     * Constructor
     */
    public function __construct() {
        // Add meta box for PDF settings
        add_action('add_meta_boxes', array($this, 'add_pdf_settings_meta_box'));

        // Save PDF settings
        add_action('save_post', array($this, 'save_pdf_settings'), 10, 2);

        // Hook into form processing to generate PDFs
        add_action('sfaic_after_ai_response_processed', array($this, 'maybe_generate_pdf'), 10, 5);

        // Include mPDF library
        add_action('init', array($this, 'load_pdf_libraries'));

        // Hook into the AI response to fix encoding early
        add_filter('sfaic_ai_response', array($this, 'fix_response_encoding'), 5);

        // Increase memory and execution time for PDF generation
        add_action('sfaic_before_pdf_generation', array($this, 'prepare_environment_for_pdf'));

        // Add AJAX handlers for service testing
        add_action('wp_ajax_sfaic_test_pdf_service', array($this, 'ajax_test_pdf_service'));
    }

    /**
     * AJAX handler for testing PDF services
     */
    public function ajax_test_pdf_service() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sfaic_test_pdf')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $service = sanitize_text_field($_POST['service']);
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');

        switch ($service) {
            case self::SERVICE_MPDF:
                $result = $this->test_mpdf_library();
                break;
            case self::SERVICE_API2PDF:
                $result = $this->test_api2pdf_service($api_key);
                break;
            default:
                $result = new WP_Error('invalid_service', __('Invalid PDF service specified', 'chatgpt-fluent-connector'));
        }

        if (is_wp_error($result)) {
            wp_send_json(array(
                'success' => false,
                'message' => $result->get_error_message()
            ));
        } else {
            wp_send_json(array(
                'success' => true,
                'message' => $result['message'],
                'details' => $result
            ));
        }
    }

   
    /**
     * Get test HTML for api2pdf
     */
    private function get_api2pdf_test_html() {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>api2pdf Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        .header { background: #007cba; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .test-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚀 api2pdf Service Test</h1>
        <p>Testing cloud-based PDF generation with api2pdf</p>
    </div>
    
    <div class="test-section">
        <h2>✅ Service Functionality Test</h2>
        <p>This PDF was generated using the api2pdf cloud service integration.</p>
    </div>
    
    <div class="test-section">
        <h2>🌐 Unicode Support Test</h2>
        <p>Special characters: café, naïve, résumé, piñata</p>
        <p>Symbols: ©®™°±²³€£¥</p>
        <p>Emojis: 🌟 ⭐ ✨ ❤️ 💙 💚 ✅ ❌</p>
    </div>
    
    <div class="test-section">
        <h2>📊 Table Test</h2>
        <table>
            <thead>
                <tr><th>Feature</th><th>Status</th><th>Description</th></tr>
            </thead>
            <tbody>
                <tr><td>Cloud Generation</td><td>✅ Active</td><td>PDF generated in the cloud</td></tr>
                <tr><td>High Quality</td><td>✅ Enabled</td><td>Professional rendering quality</td></tr>
                <tr><td>Unicode Support</td><td>✅ Full</td><td>Complete character set support</td></tr>
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 40px; text-align: center;">
        <p><strong>api2pdf Test Completed Successfully!</strong></p>
        <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
    </div>
</body>
</html>';
    }

    /**
     * Maybe generate PDF after AI response (enhanced with service selection)
     */
    public function maybe_generate_pdf($ai_response, $prompt_id, $entry_id, $form_data, $form) {
        // Check if PDF generation is enabled
        $generate_pdf = get_post_meta($prompt_id, '_sfaic_generate_pdf', true);
        if ($generate_pdf != '1') {
            return;
        }

        // Get PDF service preference
        $pdf_service = get_post_meta($prompt_id, '_sfaic_pdf_service', true);
        if (empty($pdf_service)) {
            $pdf_service = get_option('sfaic_default_pdf_service', self::SERVICE_MPDF);
        }

        // Prepare environment for PDF generation
        do_action('sfaic_before_pdf_generation');

        // Log the start of PDF generation
        error_log("SFAIC PDF: Starting PDF generation for entry {$entry_id}, prompt {$prompt_id}, service: {$pdf_service}");

        // Generate PDF based on selected service
        switch ($pdf_service) {
            case self::SERVICE_API2PDF:
                $pdf_result = $this->generate_pdf_with_api2pdf_full($ai_response, $prompt_id, $entry_id, $form_data, $form);
                break;
            case self::SERVICE_MPDF:
            default:
                $pdf_result = $this->generate_pdf_with_enhanced_mpdf($ai_response, $prompt_id, $entry_id, $form_data, $form);
                break;
        }

        if (!is_wp_error($pdf_result)) {
            // Store PDF info in entry meta
            update_post_meta($entry_id, '_sfaic_pdf_url', $pdf_result['url']);
            update_post_meta($entry_id, '_sfaic_pdf_filename', $pdf_result['filename']);
            update_post_meta($entry_id, '_sfaic_pdf_path', $pdf_result['path']);
            update_post_meta($entry_id, '_sfaic_pdf_generated_at', current_time('mysql'));
            update_post_meta($entry_id, '_sfaic_pdf_service', $pdf_service);
            update_post_meta($entry_id, '_sfaic_pdf_size', $pdf_result['size']);

            error_log("SFAIC PDF: Successfully generated PDF for entry {$entry_id}: {$pdf_result['filename']} ({$pdf_result['size']} bytes) using {$pdf_service}");
        } else {
            error_log("SFAIC PDF: Failed to generate PDF for entry {$entry_id} using {$pdf_service}: " . $pdf_result->get_error_message());

            // Try fallback service if api2pdf fails
            if ($pdf_service === self::SERVICE_API2PDF) {
                error_log("SFAIC PDF: Attempting fallback to mPDF for entry {$entry_id}");
                $pdf_result = $this->generate_pdf_with_enhanced_mpdf($ai_response, $prompt_id, $entry_id, $form_data, $form);

                if (!is_wp_error($pdf_result)) {
                    update_post_meta($entry_id, '_sfaic_pdf_url', $pdf_result['url']);
                    update_post_meta($entry_id, '_sfaic_pdf_filename', $pdf_result['filename']);
                    update_post_meta($entry_id, '_sfaic_pdf_path', $pdf_result['path']);
                    update_post_meta($entry_id, '_sfaic_pdf_generated_at', current_time('mysql'));
                    update_post_meta($entry_id, '_sfaic_pdf_service', self::SERVICE_MPDF . '_fallback');
                    update_post_meta($entry_id, '_sfaic_pdf_size', $pdf_result['size']);

                    error_log("SFAIC PDF: Fallback successful for entry {$entry_id}");
                }
            }
        }
    }

    /**
     * Generate PDF with api2pdf (full implementation)
     */
    private function generate_pdf_with_api2pdf_full($ai_response, $prompt_id, $entry_id, $form_data, $form) {
        try {
            // Get and validate settings
            $settings = $this->get_pdf_settings($prompt_id);
            if (is_wp_error($settings)) {
                return $settings;
            }

            // Process filename with enhanced placeholders
            $processed_filename = $this->process_enhanced_filename_placeholders(
                    $settings['pdf_filename'], $entry_id, $form_data, $form
            );

            // Enhanced content processing
            $ai_response = $this->prepare_content_for_pdf($ai_response);

            // Prepare enhanced template variables
            $template_vars = $this->prepare_enhanced_template_variables(
                    $settings, $ai_response, $entry_id, $form_data, $form
            );

            // Process template with variables
            $html_content = $this->process_template_with_variables($settings['pdf_template_html'], $template_vars);

            // Generate PDF using api2pdf
            $api2pdf_options = array(
                'format' => $settings['pdf_format'],
                'orientation' => $settings['pdf_orientation'],
                'margin' => $settings['pdf_margin']
            );

            return $this->generate_pdf_with_api2pdf($html_content, $processed_filename, $api2pdf_options);
        } catch (Exception $e) {
            error_log('SFAIC PDF Generation Error (api2pdf): ' . $e->getMessage());
            return new WP_Error('pdf_generation_failed',
                    sprintf(__('PDF generation failed: %s', 'chatgpt-fluent-connector'), $e->getMessage())
            );
        }
    }

    /**
     * Add meta box for PDF settings (enhanced with service selection)
     */
    public function add_pdf_settings_meta_box() {
        add_meta_box(
                'sfaic_pdf_settings',
                __('PDF Settings', 'chatgpt-fluent-connector'),
                array($this, 'render_pdf_settings_meta_box'),
                'sfaic_prompt',
                'normal',
                'default'
        );
    }

    /**
     * Render PDF settings meta box (enhanced with service selection)
     */
    public function render_pdf_settings_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('sfaic_pdf_settings_save', 'sfaic_pdf_settings_nonce');

        // Get saved values
        $generate_pdf = get_post_meta($post->ID, '_sfaic_generate_pdf', true);
        $pdf_service = get_post_meta($post->ID, '_sfaic_pdf_service', true);
        $pdf_filename = get_post_meta($post->ID, '_sfaic_pdf_filename', true);
        $pdf_attach_to_email = get_post_meta($post->ID, '_sfaic_pdf_attach_to_email', true);
        $pdf_title = get_post_meta($post->ID, '_sfaic_pdf_title', true);
        $pdf_format = get_post_meta($post->ID, '_sfaic_pdf_format', true);
        $pdf_orientation = get_post_meta($post->ID, '_sfaic_pdf_orientation', true);
        $pdf_margin = get_post_meta($post->ID, '_sfaic_pdf_margin', true);
        $pdf_template_html = get_post_meta($post->ID, '_sfaic_pdf_template_html', true);

        // Set defaults
        if (empty($pdf_service))
            $pdf_service = get_option('sfaic_default_pdf_service', self::SERVICE_MPDF);
        if (empty($pdf_filename))
            $pdf_filename = 'ai-response-{entry_id}';
        if (empty($pdf_title))
            $pdf_title = 'AI Response Report';
        if (empty($pdf_format))
            $pdf_format = 'A4';
        if (empty($pdf_orientation))
            $pdf_orientation = 'P';
        if (empty($pdf_margin))
            $pdf_margin = '15';
        if (empty($pdf_template_html))
            $pdf_template_html = $this->get_enhanced_default_template();

        // Check service availability
        $mpdf_available = class_exists('Mpdf\Mpdf');
        $api2pdf_key = get_option('sfaic_api2pdf_api_key');
        $api2pdf_available = !empty($api2pdf_key);
        ?>

        <div class="sfaic-pdf-settings-notice" style="background: #e8f5e8; padding: 15px; margin-bottom: 20px; border-left: 4px solid #28a745; border-radius: 3px;">
            <h4 style="margin-top: 0; color: #28a745;">📄 Enhanced PDF Generation with Multiple Services</h4>
            <p style="margin-bottom: 0;">Choose between local mPDF generation or cloud-based api2pdf service for professional PDF creation.</p>
        </div>

        <table class="form-table">
            <tr>
                <th><label for="sfaic_generate_pdf"><?php _e('Generate PDF:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sfaic_generate_pdf" id="sfaic_generate_pdf" value="1" <?php checked($generate_pdf, '1'); ?>>
                        <?php _e('Generate PDF from AI response', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description">
        <?php _e('When enabled, the AI response will be converted to PDF using your selected service.', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_service"><?php _e('PDF Service:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <select name="sfaic_pdf_service" id="sfaic_pdf_service">
                        <option value="<?php echo self::SERVICE_MPDF; ?>" <?php selected($pdf_service, self::SERVICE_MPDF); ?>>
                            <?php _e('Local mPDF', 'chatgpt-fluent-connector'); ?>
        <?php if (!$mpdf_available): ?>
                                <?php _e('(Not Available)', 'chatgpt-fluent-connector'); ?>
                            <?php endif; ?>
                        </option>
                        <option value="<?php echo self::SERVICE_API2PDF; ?>" <?php selected($pdf_service, self::SERVICE_API2PDF); ?>>
                            <?php _e('api2pdf Cloud Service', 'chatgpt-fluent-connector'); ?>
        <?php if (!$api2pdf_available): ?>
            <?php _e('(API Key Required)', 'chatgpt-fluent-connector'); ?>
        <?php endif; ?>
                        </option>
                    </select>

                    <div style="margin-top: 10px;">
                        <div class="pdf-service-info mpdf-info" <?php echo ($pdf_service !== self::SERVICE_MPDF) ? 'style="display:none;"' : ''; ?>>
                            <p class="description">
                                <strong><?php _e('Local mPDF:', 'chatgpt-fluent-connector'); ?></strong>
                                <?php _e('Generate PDFs locally on your server. No external dependencies, works offline.', 'chatgpt-fluent-connector'); ?>
                                <?php if ($mpdf_available): ?>
                                    <span style="color: #28a745;">✅ <?php _e('Available', 'chatgpt-fluent-connector'); ?></span>
        <?php else: ?>
                                    <span style="color: #dc3545;">❌ <?php _e('mPDF library not installed', 'chatgpt-fluent-connector'); ?></span>
        <?php endif; ?>
                            </p>
                        </div>

                        <div class="pdf-service-info api2pdf-info" <?php echo ($pdf_service !== self::SERVICE_API2PDF) ? 'style="display:none;"' : ''; ?>>
                            <p class="description">
                                <strong><?php _e('api2pdf Cloud Service:', 'chatgpt-fluent-connector'); ?></strong>
                                <?php _e('Professional cloud-based PDF generation with high-quality rendering and reliability.', 'chatgpt-fluent-connector'); ?>
        <?php if ($api2pdf_available): ?>
                                    <span style="color: #28a745;">✅ <?php _e('API Key Configured', 'chatgpt-fluent-connector'); ?></span>
                                <?php else: ?>
                                    <span style="color: #dc3545;">❌ <?php _e('API Key Required', 'chatgpt-fluent-connector'); ?></span>
                                    <a href="<?php echo admin_url('options-general.php?page=sfaic-settings'); ?>" target="_blank"><?php _e('Configure API Key', 'chatgpt-fluent-connector'); ?></a>
        <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div style="margin-top: 10px;">
                        <button type="button" id="test-pdf-service-btn" class="button button-secondary">
                            <span class="dashicons dashicons-pdf" style="vertical-align: middle; margin-right: 5px;"></span>
        <?php _e('Test Selected Service', 'chatgpt-fluent-connector'); ?>
                        </button>
                        <div id="pdf-service-test-result" style="margin-top: 10px;"></div>
                    </div>
                </td>
            </tr>

            <!-- Rest of the existing PDF settings fields remain the same -->
            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_title"><?php _e('PDF Title:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="text" name="sfaic_pdf_title" id="sfaic_pdf_title" value="<?php echo esc_attr($pdf_title); ?>" class="regular-text">
                    <p class="description"><?php _e('Title for the PDF document', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_format"><?php _e('PDF Format:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <select name="sfaic_pdf_format" id="sfaic_pdf_format">
                        <option value="A4" <?php selected($pdf_format, 'A4'); ?>>A4</option>
                        <option value="A3" <?php selected($pdf_format, 'A3'); ?>>A3</option>
                        <option value="A5" <?php selected($pdf_format, 'A5'); ?>>A5</option>
                        <option value="Letter" <?php selected($pdf_format, 'Letter'); ?>>Letter</option>
                        <option value="Legal" <?php selected($pdf_format, 'Legal'); ?>>Legal</option>
                    </select>

                    <select name="sfaic_pdf_orientation" id="sfaic_pdf_orientation" style="margin-left: 10px;">
                        <option value="P" <?php selected($pdf_orientation, 'P'); ?>><?php _e('Portrait', 'chatgpt-fluent-connector'); ?></option>
                        <option value="L" <?php selected($pdf_orientation, 'L'); ?>><?php _e('Landscape', 'chatgpt-fluent-connector'); ?></option>
                    </select>
                    <p class="description"><?php _e('PDF page format and orientation', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_margin"><?php _e('PDF Margin:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="number" name="sfaic_pdf_margin" id="sfaic_pdf_margin" value="<?php echo esc_attr($pdf_margin); ?>" min="0" max="50" step="1"> mm
                    <p class="description"><?php _e('Margin for all sides in millimeters', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_template_html"><?php _e('HTML Template:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <textarea name="sfaic_pdf_template_html" id="sfaic_pdf_template_html" class="large-text code" rows="15"><?php echo esc_textarea($pdf_template_html); ?></textarea>
                    <p class="description">
                        <?php _e('HTML template for PDF generation. Works with both local mPDF and api2pdf services.', 'chatgpt-fluent-connector'); ?><br>
                        <strong><?php _e('Available variables:', 'chatgpt-fluent-connector'); ?></strong><br>
                        <code>{title}, {content}, {date}, {time}, {entry_id}, {form_title}, {form_id}, {datetime}, {timestamp}, {site_name}, {site_url}</code><br>
        <?php _e('+ any form field as', 'chatgpt-fluent-connector'); ?> <code>{field_name}</code>
                    </p>
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_attach_to_email"><?php _e('Email Attachment:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sfaic_pdf_attach_to_email" id="sfaic_pdf_attach_to_email" value="1" <?php checked($pdf_attach_to_email, '1'); ?>>
        <?php _e('Attach PDF to email notifications', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('When enabled, the generated PDF will be attached to email notifications', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_filename"><?php _e('PDF Filename:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="text" name="sfaic_pdf_filename" id="sfaic_pdf_filename" value="<?php echo esc_attr($pdf_filename); ?>" class="regular-text">
                    <p class="description">
        <?php _e('Filename for the generated PDF (without .pdf extension).', 'chatgpt-fluent-connector'); ?><br>
        <?php _e('You can use placeholders like {entry_id}, {form_id}, {date}, {time}', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <script>
            jQuery(document).ready(function ($) {
                // Show/hide PDF settings
                $('#sfaic_generate_pdf').change(function () {
                    if ($(this).is(':checked')) {
                        $('.pdf-settings').show();
                    } else {
                        $('.pdf-settings').hide();
                    }
                });

                // Show/hide service-specific info
                $('#sfaic_pdf_service').change(function () {
                    var service = $(this).val();
                    $('.pdf-service-info').hide();
                    $('.' + service + '-info').show();
                });

                // Test PDF service
                $('#test-pdf-service-btn').click(function () {
                    var button = $(this);
                    var resultDiv = $('#pdf-service-test-result');
                    var service = $('#sfaic_pdf_service').val();

                    button.prop('disabled', true);
                    button.find('.dashicons').addClass('spin');
                    resultDiv.html('<div class="notice notice-info inline"><p>Testing ' + service + ' service...</p></div>');

                    $.post(ajaxurl, {
                        action: 'sfaic_test_pdf_service',
                        nonce: '<?php echo wp_create_nonce('sfaic_test_pdf'); ?>',
                        service: service
                    }, function (response) {
                        button.prop('disabled', false);
                        button.find('.dashicons').removeClass('spin');

                        if (response.success) {
                            var html = '<div class="notice notice-success inline">';
                            html += '<p><strong>✅ Service Test Successful!</strong></p>';
                            html += '<p><strong>Service:</strong> ' + response.details.service + '</p>';
                            html += '<p><strong>Status:</strong> ' + response.details.status + '</p>';
                            if (response.details.pdf_size) {
                                html += '<p><strong>Test PDF Size:</strong> ' + response.details.pdf_size + '</p>';
                            }
                            html += '</div>';
                        } else {
                            var html = '<div class="notice notice-error inline">';
                            html += '<p><strong>❌ Service Test Failed</strong></p>';
                            html += '<p><strong>Error:</strong> ' + response.message + '</p>';
                            html += '</div>';
                        }

                        resultDiv.html(html);
                    }).fail(function () {
                        button.prop('disabled', false);
                        button.find('.dashicons').removeClass('spin');
                        resultDiv.html('<div class="notice notice-error inline"><p>Request failed. Please try again.</p></div>');
                    });
                });
            });
        </script>

        <style>
            @keyframes spin {
                from {
                    transform: rotate(0deg);
                }
                to {
                    transform: rotate(360deg);
                }
            }
            .dashicons.spin {
                animation: spin 1s linear infinite;
            }
        </style>
        <?php
    }

    /**
     * Save PDF settings (enhanced with service selection)
     */
    public function save_pdf_settings($post_id, $post) {
        // Check if our custom post type
        if ($post->post_type !== 'sfaic_prompt') {
            return;
        }

        // Check if our nonce is set and verify it
        if (!isset($_POST['sfaic_pdf_settings_nonce']) ||
                !wp_verify_nonce($_POST['sfaic_pdf_settings_nonce'], 'sfaic_pdf_settings_save')) {
            return;
        }

        // Check for autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save all PDF settings
        $settings_to_save = array(
            'sfaic_generate_pdf' => isset($_POST['sfaic_generate_pdf']) ? '1' : '0',
            'sfaic_pdf_service' => sanitize_text_field($_POST['sfaic_pdf_service'] ?? self::SERVICE_MPDF),
            'sfaic_pdf_title' => sanitize_text_field($_POST['sfaic_pdf_title'] ?? ''),
            'sfaic_pdf_format' => sanitize_text_field($_POST['sfaic_pdf_format'] ?? 'A4'),
            'sfaic_pdf_orientation' => sanitize_text_field($_POST['sfaic_pdf_orientation'] ?? 'P'),
            'sfaic_pdf_margin' => intval($_POST['sfaic_pdf_margin'] ?? 15),
            'sfaic_pdf_attach_to_email' => isset($_POST['sfaic_pdf_attach_to_email']) ? '1' : '0',
            'sfaic_pdf_filename' => sanitize_text_field($_POST['sfaic_pdf_filename'] ?? ''),
        );

        // Handle HTML template separately to allow more tags
        if (isset($_POST['sfaic_pdf_template_html'])) {
            $allowed_html = wp_kses_allowed_html('post');
            $allowed_html['style'] = array();
            $allowed_html['meta'] = array('charset' => true);
            $settings_to_save['sfaic_pdf_template_html'] = wp_kses($_POST['sfaic_pdf_template_html'], $allowed_html);
        }

        // Save all settings
        foreach ($settings_to_save as $meta_key => $meta_value) {
            update_post_meta($post_id, '_' . $meta_key, $meta_value);
        }
    }

    // Include all the existing methods from the original PDF generator class
    // (The helper methods like prepare_environment_for_pdf, fix_response_encoding, etc.)
    // ... (keeping all existing methods for mPDF functionality)

    /**
     * Prepare environment for PDF generation
     */
    public function prepare_environment_for_pdf() {
        // Increase memory limit
        if (function_exists('ini_get') && function_exists('ini_set')) {
            $current_memory = ini_get('memory_limit');
            if ($current_memory && intval($current_memory) < 256) {
                @ini_set('memory_limit', '256M');
            }
        }

        // Increase execution time
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // 5 minutes
        }

        // Disable output buffering issues
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        @ini_set('zlib.output_compression', '0');
    }

    /**
     * Fix encoding issues in AI response early
     */
    public function fix_response_encoding($response) {
        if (is_string($response)) {
            return $this->fix_encoding_comprehensive($response);
        }
        return $response;
    }

    /**
     * Comprehensive encoding fix for corrupted UTF-8
     */
    private function fix_encoding_comprehensive($content) {
        // Handle null or empty content
        if (empty($content)) {
            return $content;
        }

        // First, try to detect the actual encoding
        $detected_encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);

        // If it's not UTF-8, convert it
        if ($detected_encoding && $detected_encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $detected_encoding);
        }

        // Decode HTML entities if present
        if (strpos($content, '&') !== false) {
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Final cleanup - remove any remaining invalid UTF-8 sequences
        if (function_exists('iconv')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $content);
            if ($cleaned !== false) {
                $content = $cleaned;
            }
        }

        return $content;
    }

    /**
     * Load PDF libraries with better error handling
     */
    public function load_pdf_libraries() {
        return $this->load_mpdf_library();
    }

    /**
     * Enhanced mPDF library loading with better paths
     */
    private function load_mpdf_library() {
        if (class_exists('Mpdf\Mpdf')) {
            return true;
        }

        // Try multiple possible paths for mPDF
        $possible_paths = array(
            SFAIC_DIR . 'vendor/autoload.php',
            SFAIC_DIR . 'vendor/mpdf/mpdf/vendor/autoload.php',
            SFAIC_DIR . 'includes/mpdf/vendor/autoload.php',
            ABSPATH . 'vendor/autoload.php', // WordPress root
            WP_CONTENT_DIR . '/vendor/autoload.php', // wp-content
        );

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                if (class_exists('Mpdf\Mpdf')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Enhanced PDF library testing
     */
    public function test_mpdf_library() {
        if (!class_exists('Mpdf\Mpdf')) {
            return new WP_Error('mpdf_not_available', __('mPDF library is not installed or not accessible', 'chatgpt-fluent-connector'));
        }

        try {
            // Create simple test PDF
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'orientation' => 'P'
            ]);

            $test_html = '<h1>mPDF Test</h1><p>This is a test PDF generated by mPDF library.</p>';

            $mpdf->WriteHTML($test_html);
            $pdf_content = $mpdf->Output('', 'S');

            if (strlen($pdf_content) > 1000) {
                return array(
                    'service' => 'Local mPDF',
                    'status' => 'success',
                    'message' => 'mPDF library is working perfectly!',
                    'pdf_size' => strlen($pdf_content) . ' bytes'
                );
            } else {
                return new WP_Error('test_failed', 'Generated PDF is too small');
            }
        } catch (Exception $e) {
            return new WP_Error('mpdf_test_error', __('mPDF test error: ', 'chatgpt-fluent-connector') . $e->getMessage());
        }
    }

    // Add remaining helper methods from original class as needed...
    // (get_pdf_settings, prepare_content_for_pdf, etc.)

    /**
     * Get and validate PDF settings
     */
    private function get_pdf_settings($prompt_id) {
        $settings = array(
            'pdf_title' => get_post_meta($prompt_id, '_sfaic_pdf_title', true) ?: 'AI Response Report',
            'pdf_format' => get_post_meta($prompt_id, '_sfaic_pdf_format', true) ?: 'A4',
            'pdf_orientation' => get_post_meta($prompt_id, '_sfaic_pdf_orientation', true) ?: 'P',
            'pdf_margin' => get_post_meta($prompt_id, '_sfaic_pdf_margin', true) ?: 15,
            'pdf_template_html' => get_post_meta($prompt_id, '_sfaic_pdf_template_html', true) ?: $this->get_enhanced_default_template(),
            'pdf_filename' => get_post_meta($prompt_id, '_sfaic_pdf_filename', true) ?: 'ai-response-{entry_id}',
        );

        // Validate settings
        if (empty($settings['pdf_template_html'])) {
            return new WP_Error('invalid_template', __('PDF template is empty', 'chatgpt-fluent-connector'));
        }

        // Ensure numeric values are properly typed
        $settings['pdf_margin'] = intval($settings['pdf_margin']);
        if ($settings['pdf_margin'] < 0 || $settings['pdf_margin'] > 50) {
            $settings['pdf_margin'] = 15; // Reset to default if invalid
        }

        return $settings;
    }

    /**
     * Enhanced content preparation for PDF
     */
    private function prepare_content_for_pdf($content) {
        // Fix encoding issues
        $content = $this->fix_encoding_comprehensive($content);

        // Clean up HTML
        $content = $this->clean_html_for_pdf($content);

        return $content;
    }

    /**
     * Clean HTML content for better PDF rendering
     */
    private function clean_html_for_pdf($html) {
        // Remove problematic elements that might cause issues in PDF
        $html = preg_replace('/<script[^>]*?>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<iframe[^>]*?>.*?<\/iframe>/is', '', $html);

        // Fix self-closing tags
        $html = preg_replace('/<(br|hr|img|input|meta|link)([^>]*?)>/i', '<$1$2 />', $html);

        return $html;
    }

    /**
     * Prepare enhanced template variables
     */
    private function prepare_enhanced_template_variables($settings, $ai_response, $entry_id, $form_data, $form) {
        $current_time = current_time('timestamp');

        $template_vars = array(
            'title' => $settings['pdf_title'],
            'content' => $ai_response,
            'date' => date_i18n(get_option('date_format'), $current_time),
            'time' => date_i18n(get_option('time_format'), $current_time),
            'datetime' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $current_time),
            'timestamp' => $current_time,
            'entry_id' => $entry_id,
            'form_title' => $form->title ?? 'Unknown Form',
            'form_id' => $form->id ?? 0,
            'site_name' => get_bloginfo('name'),
            'site_url' => get_site_url(),
        );

        // Add form field data with proper encoding
        foreach ($form_data as $field_key => $field_value) {
            if (is_scalar($field_key)) {
                if (is_array($field_value)) {
                    $field_value = implode(', ', array_map('strval', $field_value));
                } elseif (is_scalar($field_value)) {
                    $field_value = strval($field_value);
                } else {
                    continue; // Skip non-scalar values
                }

                // Process content for PDF
                $field_value = $this->prepare_content_for_pdf($field_value);
                $template_vars[$field_key] = $field_value;
            }
        }

        return $template_vars;
    }

    /**
     * Process template with variables
     */
    private function process_template_with_variables($template, $variables) {
        // Replace all variables in template
        foreach ($variables as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }

        // Remove any remaining unprocessed placeholders
        $template = preg_replace('/\{[^}]+\}/', '', $template);

        return $template;
    }

    /**
     * Enhanced filename processing with more placeholders
     */
    private function process_enhanced_filename_placeholders($filename, $entry_id, $form_data, $form) {
        $current_time = current_time('timestamp');

        $replacements = array(
            '{entry_id}' => $entry_id,
            '{form_id}' => $form->id ?? 0,
            '{form_title}' => sanitize_title($form->title ?? 'form'),
            '{date}' => date('Y-m-d', $current_time),
            '{time}' => date('H-i-s', $current_time),
            '{datetime}' => date('Y-m-d_H-i-s', $current_time),
            '{timestamp}' => $current_time,
        );

        $processed = str_replace(array_keys($replacements), array_values($replacements), $filename);
        return sanitize_file_name($processed);
    }

    /**
     * Enhanced PDF data saving with validation
     */
    private function save_enhanced_pdf_data($pdf_data, $filename) {
        // Validate PDF data
        if (empty($pdf_data)) {
            return new WP_Error('empty_pdf_data', __('PDF data is empty', 'chatgpt-fluent-connector'));
        }

        if (strlen($pdf_data) < 1000) {
            return new WP_Error('pdf_too_small', __('Generated PDF is too small', 'chatgpt-fluent-connector'));
        }

        // Validate PDF header
        if (substr($pdf_data, 0, 4) !== '%PDF') {
            return new WP_Error('invalid_pdf_format', __('Generated data is not a valid PDF', 'chatgpt-fluent-connector'));
        }

        // Setup directories
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/ai-pdfs';
        $pdf_url_dir = $upload_dir['baseurl'] . '/ai-pdfs';

        // Ensure directory exists
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        // Add .pdf extension if not present
        if (!str_ends_with($filename, '.pdf')) {
            $filename .= '.pdf';
        }

        // Ensure unique filename
        $filename = $this->ensure_unique_filename($pdf_dir, $filename);

        $file_path = $pdf_dir . '/' . $filename;
        $file_url = $pdf_url_dir . '/' . $filename;

        // Write PDF data
        $result = file_put_contents($file_path, $pdf_data, LOCK_EX);

        if ($result === false) {
            return new WP_Error('file_write_failed', __('Failed to write PDF file', 'chatgpt-fluent-connector'));
        }

        return array(
            'filename' => $filename,
            'path' => $file_path,
            'url' => $file_url,
            'size' => filesize($file_path),
            'created' => current_time('mysql'),
            'mime_type' => 'application/pdf'
        );
    }

    /**
     * Ensure unique filename to prevent overwrites
     */
    private function ensure_unique_filename($directory, $filename) {
        $original_filename = $filename;
        $counter = 1;

        while (file_exists($directory . '/' . $filename)) {
            $pathinfo = pathinfo($original_filename);
            $filename = $pathinfo['filename'] . '_' . $counter . '.' . $pathinfo['extension'];
            $counter++;

            // Prevent infinite loop
            if ($counter > 1000) {
                $filename = $pathinfo['filename'] . '_' . time() . '.' . $pathinfo['extension'];
                break;
            }
        }

        return $filename;
    }

    /**
     * Enhanced default HTML template
     */
    private function get_enhanced_default_template() {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{title}</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            line-height: 1.6;
            font-size: 14px;
            color: #333;
        }
        .header { 
            background: #667eea;
            color: white;
            padding: 25px; 
            margin-bottom: 30px; 
            border-radius: 8px;
        }
        .title { 
            font-size: 28px; 
            font-weight: bold; 
            margin: 0;
        }
        .meta { 
            color: rgba(255,255,255,0.9); 
            font-size: 14px; 
            margin-top: 8px; 
        }
        .content { 
            line-height: 1.8; 
            color: #333;
            padding: 0 10px;
        }
        .footer { 
            margin-top: 50px; 
            padding-top: 25px; 
            border-top: 2px solid #667eea; 
            font-size: 12px; 
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 class="title">{title}</h1>
        <div class="meta">
            Generated on {date} at {time} | Entry ID: {entry_id} | Form: {form_title}
        </div>
    </div>
    
    <div class="content">
        {content}
    </div>
    
    <div class="footer">
        <p><strong>AI-Generated Document</strong></p>
        <p>Form: {form_title} | Site: {site_name}</p>
        <p>Generated: {datetime}</p>
    </div>
</body>
</html>';
    }

    /**
     * Get PDF attachment for email with validation
     */
    public function get_pdf_attachment($entry_id) {
        $pdf_path = get_post_meta($entry_id, '_sfaic_pdf_path', true);

        if (!empty($pdf_path) && file_exists($pdf_path) && is_readable($pdf_path)) {
            // Additional validation - check if it's actually a PDF
            $file_handle = fopen($pdf_path, 'r');
            if ($file_handle) {
                $header = fread($file_handle, 4);
                fclose($file_handle);

                if ($header === '%PDF') {
                    return $pdf_path;
                }
            }
        }

        return false;
    }

    /**
     * Generate PDF with enhanced mPDF - Complete implementation from original class
     */
    private function generate_pdf_with_enhanced_mpdf($ai_response, $prompt_id, $entry_id, $form_data, $form) {
        // Check if mPDF is available
        if (!class_exists('Mpdf\Mpdf')) {
            return new WP_Error('mpdf_not_available', __('mPDF library is not available', 'chatgpt-fluent-connector'));
        }

        // Ensure temp directory exists
        $temp_dir = $this->ensure_temp_directory();
        if (!$temp_dir) {
            return new WP_Error('temp_dir_failed', __('Failed to create temporary directory for PDF generation', 'chatgpt-fluent-connector'));
        }

        try {
            // Enhanced environment preparation
            $this->prepare_enhanced_pdf_environment();

            // Get and validate settings
            $settings = $this->get_pdf_settings($prompt_id);
            if (is_wp_error($settings)) {
                return $settings;
            }

            // Process filename with enhanced placeholders
            $processed_filename = $this->process_enhanced_filename_placeholders(
                    $settings['pdf_filename'], $entry_id, $form_data, $form
            );

            // Enhanced content processing
            $ai_response = $this->prepare_content_for_pdf($ai_response);

            // Prepare enhanced template variables
            $template_vars = $this->prepare_enhanced_template_variables(
                    $settings, $ai_response, $entry_id, $form_data, $form
            );

            // Process template with variables
            $html_content = $this->process_template_with_variables($settings['pdf_template_html'], $template_vars);

            // Create enhanced mPDF instance
            $mpdf = $this->create_enhanced_mpdf_instance($settings, $temp_dir);

            // Set document properties
            $mpdf->SetTitle($settings['pdf_title']);
            $mpdf->SetAuthor(get_bloginfo('name'));
            $mpdf->SetCreator('AI API Connector Plugin (Enhanced)');
            $mpdf->SetSubject('AI Generated Response');
            $mpdf->SetKeywords('AI, Response, PDF, Generated');

            // Write HTML content with error handling
            $this->write_html_to_mpdf($mpdf, $html_content);

            // Generate PDF content
            $pdf_content = $mpdf->Output('', 'S');

            // Validate PDF content
            if (empty($pdf_content) || strlen($pdf_content) < 1000) {
                throw new Exception('Generated PDF content is too small or empty');
            }

            // Save PDF with enhanced validation
            return $this->save_enhanced_pdf_data($pdf_content, $processed_filename);
        } catch (Exception $e) {
            error_log('SFAIC PDF Generation Error: ' . $e->getMessage());
            return new WP_Error('pdf_generation_failed',
                    sprintf(__('PDF generation failed: %s', 'chatgpt-fluent-connector'), $e->getMessage())
            );
        } finally {
            // Cleanup
            $this->cleanup_pdf_environment();
        }
    }

    /**
     * Enhanced environment preparation for PDF generation
     */
    private function prepare_enhanced_pdf_environment() {
        // Set higher memory limit
        $current_memory = ini_get('memory_limit');
        if ($current_memory && intval($current_memory) < 512) {
            @ini_set('memory_limit', '512M');
        }

        // Set longer execution time
        @set_time_limit(600); // 10 minutes
        // Enhanced output buffering control
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Start clean output buffering
        ob_start();

        // Disable problematic settings
        @ini_set('display_errors', '0');
        @ini_set('log_errors', '1');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '0');

        // Set proper timezone if not set
        if (!ini_get('date.timezone')) {
            @ini_set('date.timezone', wp_timezone_string());
        }
    }

    /**
     * Create enhanced mPDF instance with better configuration
     */
    private function create_enhanced_mpdf_instance($settings, $temp_dir) {
        $config = array(
            'mode' => 'utf-8',
            'format' => $settings['pdf_format'],
            'orientation' => $settings['pdf_orientation'],
            'margin_left' => $settings['pdf_margin'],
            'margin_right' => $settings['pdf_margin'],
            'margin_top' => $settings['pdf_margin'],
            'margin_bottom' => $settings['pdf_margin'],
            'margin_header' => 0,
            'margin_footer' => 0,
            'default_font' => 'dejavusans',
            'default_font_size' => 12,
            'tempDir' => $temp_dir,
            // Enhanced configuration
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'allow_charset_conversion' => true,
            'charset_in' => 'UTF-8',
            'list_indent_first_level' => 0,
            'img_dpi' => 96,
            'allow_output_buffering' => true,
            'curlAllowUnsafeSslRequests' => true,
            'showImageErrors' => false,
            'debug' => false,
            'debugfonts' => false,
            'useSubstitutions' => true,
            'simpleTables' => false,
            'packTableData' => true,
            'dpi' => 96,
            'PDFA' => false,
            'PDFAauto' => false,
        );

        return new \Mpdf\Mpdf($config);
    }

    /**
     * Write HTML to mPDF with enhanced error handling
     */
    private function write_html_to_mpdf($mpdf, $html_content) {
        try {
            // Validate HTML content
            if (empty(trim($html_content))) {
                throw new Exception('HTML content is empty');
            }

            // Set additional mPDF properties for better rendering
            $mpdf->SetDisplayMode('fullpage');
            $mpdf->SetCompression(true);

            // Write HTML with error handling
            $mpdf->WriteHTML($html_content);

            // Validate that content was written
            if ($mpdf->page < 1) {
                throw new Exception('No pages were generated');
            }
        } catch (Exception $e) {
            throw new Exception('Failed to write HTML to PDF: ' . $e->getMessage());
        }
    }

    /**
     * Ensure temp directory exists with proper setup
     */
    private function ensure_temp_directory() {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/mpdf-temp';

        if (!file_exists($temp_dir)) {
            if (!wp_mkdir_p($temp_dir)) {
                error_log('SFAIC PDF: Failed to create temp directory: ' . $temp_dir);
                return false;
            }

            // Create security files for temp directory
            @file_put_contents($temp_dir . '/index.php', '<?php // Silence is golden');
            @file_put_contents($temp_dir . '/.htaccess', 'deny from all');
        }

        // Ensure directory is writable
        if (!is_writable($temp_dir)) {
            @chmod($temp_dir, 0755);
            if (!is_writable($temp_dir)) {
                error_log('SFAIC PDF: Temp directory not writable: ' . $temp_dir);
                return false;
            }
        }

        // Clean old temp files (older than 1 hour)
        $this->cleanup_temp_directory($temp_dir);

        return $temp_dir;
    }

    /**
     * Clean up old temporary files
     */
    private function cleanup_temp_directory($temp_dir) {
        if (!is_dir($temp_dir)) {
            return;
        }

        $files = glob($temp_dir . '/*');
        $cutoff_time = time() - 3600; // 1 hour ago

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff_time) {
                @unlink($file);
            }
        }
    }

    /**
     * Cleanup PDF environment
     */
    private function cleanup_pdf_environment() {
        // Clean output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Reset error reporting if we changed it
        if (function_exists('error_reporting')) {
            error_reporting(E_ALL);
        }
    }

    /**
     * Official api2pdf.php implementation following GitHub documentation
     * Based on: https://github.com/Api2Pdf/api2pdf.php
     * 
     * Add this to your SFAIC_PDF_Generator class to replace existing api2pdf methods
     */

    /**
     * Create api2pdf client instance
     */
    private function create_api2pdf_client($api_key = null) {
        if ($api_key === null) {
            $api_key = get_option('sfaic_api2pdf_api_key');
        }

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('api2pdf API key is not configured', 'chatgpt-fluent-connector'));
        }

        // Simple api2pdf implementation following their official documentation
        return new SFAIC_Api2Pdf_Client($api_key);
    }

    /**
     * Test api2pdf service using official implementation pattern
     */
    public function test_api2pdf_service($api_key = null) {
        try {
            $client = $this->create_api2pdf_client($api_key);

            if (is_wp_error($client)) {
                return $client;
            }

            // Create test HTML following their documentation examples
            $test_html = $this->get_api2pdf_test_html();

            // Use wkHtmlToPdf method as shown in their documentation (more cost-effective)
            $result = $client->wkHtmlToPdf($test_html, true, 'test-api2pdf.pdf');

            if (is_wp_error($result)) {
                return $result;
            }

            return array(
                'service' => 'api2pdf Cloud Service',
                'status' => 'success',
                'message' => 'api2pdf service is working perfectly! Test PDF generated successfully using wkhtmltopdf engine.',
                'pdf_url' => $result['file_url'],
                'pdf_size' => isset($result['mb_out']) ? ($result['mb_out'] * 1024 * 1024) . ' bytes' : 'Unknown',
                'cost' => $result['cost'] ?? 0,
                'response_id' => $result['response_id'] ?? null,
                'features' => array(
                    'cloud_based' => 'No local resources required',
                    'high_quality' => 'Professional PDF rendering with wkhtmltopdf',
                    'reliable' => 'Enterprise-grade service',
                    'scalable' => 'Handles high volume requests',
                    'cost_effective' => 'wkhtmltopdf engine for optimal pricing',
                    'unicode_support' => 'Full UTF-8 and emoji support'
                )
            );
        } catch (Exception $e) {
            return new WP_Error('api2pdf_test_error', __('api2pdf test error: ', 'chatgpt-fluent-connector') . $e->getMessage());
        }
    }

    /**
     * Generate PDF using official api2pdf pattern
     */
    private function generate_pdf_with_api2pdf($html_content, $filename, $options = array()) {
        try {
            $client = $this->create_api2pdf_client($options['api_key'] ?? null);

            if (is_wp_error($client)) {
                return $client;
            }

            // Set PDF options following their documentation
            $pdf_options = array();

            if (isset($options['orientation'])) {
                $pdf_options['orientation'] = $options['orientation'] === 'L' ? 'Landscape' : 'Portrait';
            }

            if (isset($options['format'])) {
                $pdf_options['pageSize'] = $options['format'];
            }

            if (isset($options['margin'])) {
                $pdf_options['marginTop'] = $options['margin'] . 'mm';
                $pdf_options['marginBottom'] = $options['margin'] . 'mm';
                $pdf_options['marginLeft'] = $options['margin'] . 'mm';
                $pdf_options['marginRight'] = $options['margin'] . 'mm';
            }

            // Add encoding and media type options
            $pdf_options['encoding'] = 'UTF-8';
            $pdf_options['printMediaType'] = true;

            // Choose engine: wkhtmltopdf (cheaper) or Chrome (better CSS support)
            $engine = $options['engine'] ?? 'wkhtmltopdf'; // default to wkhtmltopdf

            if ($engine === 'chrome') {
                // Use Chrome engine for better CSS/JS support (following their chromeHtmlToPdf method)
                $result = $client->chromeHtmlToPdf($html_content, true, $filename . '.pdf', $pdf_options);
            } else {
                // Use wkhtmltopdf engine (default, more cost-effective)
                $result = $client->wkHtmlToPdf($html_content, true, $filename . '.pdf', $pdf_options);
            }

            if (is_wp_error($result)) {
                return $result;
            }

            // Download the PDF from the provided URL
            $pdf_response = wp_remote_get($result['file_url'], array(
                'timeout' => 60
            ));

            if (is_wp_error($pdf_response)) {
                return new WP_Error('pdf_download_failed', __('Failed to download PDF from api2pdf: ', 'chatgpt-fluent-connector') . $pdf_response->get_error_message());
            }

            $pdf_data = wp_remote_retrieve_body($pdf_response);

            if (empty($pdf_data)) {
                return new WP_Error('empty_pdf', __('Received empty PDF from api2pdf service', 'chatgpt-fluent-connector'));
            }

            // Validate PDF header
            if (substr($pdf_data, 0, 4) !== '%PDF') {
                return new WP_Error('invalid_pdf_format', __('Downloaded data is not a valid PDF', 'chatgpt-fluent-connector'));
            }

            // Save the PDF file
            $save_result = $this->save_enhanced_pdf_data($pdf_data, $filename);

            if (!is_wp_error($save_result)) {
                // Add api2pdf specific metadata
                $save_result['service'] = 'api2pdf';
                $save_result['engine'] = $engine;
                $save_result['response_id'] = $result['response_id'] ?? null;
                $save_result['cost'] = $result['cost'] ?? null;
                $save_result['mb_out'] = $result['mb_out'] ?? null;
            }

            return $save_result;
        } catch (Exception $e) {
            return new WP_Error('api2pdf_generation_error', __('api2pdf generation error: ', 'chatgpt-fluent-connector') . $e->getMessage());
        }
    }
}

/**
 * Simple api2pdf client following official documentation
 * Based on: https://github.com/Api2Pdf/api2pdf.php
 */
class SFAIC_Api2Pdf_Client {

    private $apiKey;
    private $base_url;

    public function __construct($apiKey, $base_url = 'https://v2.api2pdf.com') {
        $this->apiKey = $apiKey;
        $this->base_url = $base_url;
    }

    /**
     * Convert HTML to PDF using wkhtmltopdf
     * Following official documentation pattern
     */
    public function wkHtmlToPdf($html, $inline = true, $filename = null, $options = null) {
        $payload = $this->buildPayloadBase($inline, $filename, $options);
        $payload['html'] = $html;

        return $this->makeRequest('/wkhtmltopdf/html', $payload);
    }

    /**
     * Convert HTML to PDF using Chrome
     * Following official documentation pattern  
     */
    public function chromeHtmlToPdf($html, $inline = true, $filename = null, $options = null) {
        $payload = $this->buildPayloadBase($inline, $filename, $options);
        $payload['html'] = $html;

        return $this->makeRequest('/chrome/pdf/html', $payload);
    }

    /**
     * Build payload base following official pattern
     */
    private function buildPayloadBase($inline, $filename, $options) {
        $payload = array(
            'inlinePdf' => $inline
        );

        if ($filename !== null) {
            $payload['fileName'] = $filename;
        }

        if ($options !== null) {
            $payload['options'] = $options;
        }

        return $payload;
    }

    /**
     * Make request following official documentation
     * Based on their makeRequest method
     */
    private function makeRequest($endpoint, $payload) {
        $url = $this->base_url . $endpoint;

        $ch = curl_init($url);

        $jsonDataEncoded = json_encode($payload);

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDataEncoded);

        // Official authorization header format (no "Bearer" prefix)
        curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                [
                    'Content-Type: application/json',
                    'Authorization: ' . $this->apiKey
                ]
        );

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return new WP_Error('curl_error', __('cURL error: ', 'chatgpt-fluent-connector') . $error);
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            return new WP_Error('api_error', __('API request failed with HTTP code: ', 'chatgpt-fluent-connector') . $httpCode);
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from api2pdf', 'chatgpt-fluent-connector'));
        }

        // Handle API response following their documentation
        if (!isset($result['Success']) || $result['Success'] !== true) {
            $error_message = $result['Error'] ?? 'Unknown error occurred';
            return new WP_Error('api2pdf_failed', __('api2pdf request failed: ', 'chatgpt-fluent-connector') . $error_message);
        }

        // Return standardized result following their ApiResult pattern
        return array(
            'file_url' => $result['FileUrl'] ?? null,
            'mb_out' => $result['MbOut'] ?? null,
            'cost' => $result['Cost'] ?? null,
            'response_id' => $result['ResponseId'] ?? null,
            'success' => true
        );
    }
}
