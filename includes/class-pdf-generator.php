<?php
/**
 * Simplified PDF Generator Class - Fixed for Complete PDF Generation
 * 
 * Handles PDF generation from AI responses without unnecessary emoji processing
 */
class SFAIC_PDF_Generator {

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
    }

    /**
     * Load PDF libraries
     */
    public function load_pdf_libraries() {
        if (class_exists('Mpdf\Mpdf')) {
            return true;
        }

        // Try to load mPDF from various possible locations
        $possible_paths = array(
            SFAIC_DIR . 'vendor/autoload.php',
            ABSPATH . 'vendor/autoload.php',
            WP_CONTENT_DIR . '/vendor/autoload.php',
        );

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                if (class_exists('Mpdf\Mpdf')) {
                    return true;
                }
            }
        }

        add_action('admin_notices', array($this, 'mpdf_missing_notice'));
        return false;
    }

    /**
     * Admin notice for missing mPDF
     */
    public function mpdf_missing_notice() {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('mPDF Library Missing:', 'chatgpt-fluent-connector'); ?></strong>
                <?php _e('To use PDF generation, please install mPDF library via Composer: ', 'chatgpt-fluent-connector'); ?>
                <code>composer require mpdf/mpdf</code>
            </p>
        </div>
        <?php
    }

    /**
     * Add meta box for PDF settings
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
     * Render PDF settings meta box
     */
    public function render_pdf_settings_meta_box($post) {
        wp_nonce_field('sfaic_pdf_settings_save', 'sfaic_pdf_settings_nonce');

        // Get saved values with proper defaults
        $generate_pdf = get_post_meta($post->ID, '_sfaic_generate_pdf', true);
        $pdf_filename = get_post_meta($post->ID, '_sfaic_pdf_filename', true) ?: 'ai-response-{entry_id}';
        $pdf_attach_to_email = get_post_meta($post->ID, '_sfaic_pdf_attach_to_email', true);
        $pdf_title = get_post_meta($post->ID, '_sfaic_pdf_title', true) ?: 'AI Response Report';
        $pdf_format = get_post_meta($post->ID, '_sfaic_pdf_format', true) ?: 'A4';
        $pdf_orientation = get_post_meta($post->ID, '_sfaic_pdf_orientation', true) ?: 'P';
        $pdf_margin = get_post_meta($post->ID, '_sfaic_pdf_margin', true) ?: '15';
        $pdf_template_html = get_post_meta($post->ID, '_sfaic_pdf_template_html', true) ?: $this->get_default_template();
        ?>

        <table class="form-table">
            <tr>
                <th><label for="sfaic_generate_pdf"><?php _e('Generate PDF:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sfaic_generate_pdf" id="sfaic_generate_pdf" value="1" <?php checked($generate_pdf, '1'); ?>>
                        <?php _e('Generate PDF from AI response', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description"><?php _e('PDF will be generated with complete AI response content.', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_title"><?php _e('PDF Title:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="text" name="sfaic_pdf_title" id="sfaic_pdf_title" value="<?php echo esc_attr($pdf_title); ?>" class="regular-text">
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_format"><?php _e('PDF Format:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <select name="sfaic_pdf_format" id="sfaic_pdf_format">
                        <option value="A4" <?php selected($pdf_format, 'A4'); ?>>A4</option>
                        <option value="Letter" <?php selected($pdf_format, 'Letter'); ?>>Letter</option>
                        <option value="Legal" <?php selected($pdf_format, 'Legal'); ?>>Legal</option>
                    </select>
                    <select name="sfaic_pdf_orientation" id="sfaic_pdf_orientation" style="margin-left: 10px;">
                        <option value="P" <?php selected($pdf_orientation, 'P'); ?>>Portrait</option>
                        <option value="L" <?php selected($pdf_orientation, 'L'); ?>>Landscape</option>
                    </select>
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_margin"><?php _e('Margin (mm):', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="number" name="sfaic_pdf_margin" id="sfaic_pdf_margin" value="<?php echo esc_attr($pdf_margin); ?>" min="5" max="50" step="1">
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_template_html"><?php _e('HTML Template:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <textarea name="sfaic_pdf_template_html" id="sfaic_pdf_template_html" class="large-text code" rows="15"><?php echo esc_textarea($pdf_template_html); ?></textarea>
                    <p class="description">
                        <?php _e('Available variables: {title}, {content}, {date}, {time}, {entry_id}, {form_title}, {site_name}', 'chatgpt-fluent-connector'); ?>
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
                </td>
            </tr>

            <tr class="pdf-settings" <?php echo ($generate_pdf != '1') ? 'style="display:none;"' : ''; ?>>
                <th><label for="sfaic_pdf_filename"><?php _e('Filename:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <input type="text" name="sfaic_pdf_filename" id="sfaic_pdf_filename" value="<?php echo esc_attr($pdf_filename); ?>" class="regular-text">
                    <p class="description"><?php _e('Variables: {entry_id}, {form_id}, {date}, {time}', 'chatgpt-fluent-connector'); ?></p>
                </td>
            </tr>
        </table>

        <script>
            jQuery(document).ready(function($) {
                $('#sfaic_generate_pdf').change(function() {
                    if ($(this).is(':checked')) {
                        $('.pdf-settings').show();
                    } else {
                        $('.pdf-settings').hide();
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Simple default template without complex styling
     */
    private function get_default_template() {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{title}</title>
    <style>
        body { 
            font-family: DejaVu Sans, Arial, sans-serif; 
            line-height: 1.6; 
            color: #333; 
            margin: 0; 
            padding: 20px;
        }
        .header { 
            border-bottom: 2px solid #333; 
            padding-bottom: 10px; 
            margin-bottom: 20px;
        }
        .title { 
            font-size: 24px; 
            font-weight: bold; 
            margin: 0 0 10px 0;
        }
        .meta { 
            font-size: 12px; 
            color: #666;
        }
        .content { 
            margin: 20px 0;
        }
        .footer { 
            margin-top: 30px; 
            padding-top: 10px; 
            border-top: 1px solid #ccc; 
            font-size: 10px; 
            color: #666;
        }
        h1, h2, h3, h4, h5, h6 { 
            margin-top: 20px; 
            margin-bottom: 10px;
        }
        p { 
            margin-bottom: 10px;
        }
        ul, ol { 
            margin-bottom: 10px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin: 10px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{title}</div>
        <div class="meta">Generated on {date} at {time} | Entry ID: {entry_id}</div>
    </div>
    
    <div class="content">
        {content}
    </div>
    
    <div class="footer">
        <p>Generated by {site_name} | Form: {form_title}</p>
    </div>
</body>
</html>';
    }

    /**
     * Save PDF settings
     */
    public function save_pdf_settings($post_id, $post) {
        if ($post->post_type !== 'sfaic_prompt') {
            return;
        }

        if (!isset($_POST['sfaic_pdf_settings_nonce']) || 
            !wp_verify_nonce($_POST['sfaic_pdf_settings_nonce'], 'sfaic_pdf_settings_save')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save settings
        update_post_meta($post_id, '_sfaic_generate_pdf', isset($_POST['sfaic_generate_pdf']) ? '1' : '0');
        update_post_meta($post_id, '_sfaic_pdf_title', sanitize_text_field($_POST['sfaic_pdf_title'] ?? ''));
        update_post_meta($post_id, '_sfaic_pdf_format', sanitize_text_field($_POST['sfaic_pdf_format'] ?? 'A4'));
        update_post_meta($post_id, '_sfaic_pdf_orientation', sanitize_text_field($_POST['sfaic_pdf_orientation'] ?? 'P'));
        update_post_meta($post_id, '_sfaic_pdf_margin', intval($_POST['sfaic_pdf_margin'] ?? 15));
        update_post_meta($post_id, '_sfaic_pdf_attach_to_email', isset($_POST['sfaic_pdf_attach_to_email']) ? '1' : '0');
        update_post_meta($post_id, '_sfaic_pdf_filename', sanitize_text_field($_POST['sfaic_pdf_filename'] ?? ''));

        if (isset($_POST['sfaic_pdf_template_html'])) {
            $allowed_html = wp_kses_allowed_html('post');
            $allowed_html['style'] = array();
            $allowed_html['meta'] = array('charset' => true);
            update_post_meta($post_id, '_sfaic_pdf_template_html', wp_kses($_POST['sfaic_pdf_template_html'], $allowed_html));
        }
    }

    /**
     * Maybe generate PDF after AI response
     */
    public function maybe_generate_pdf($ai_response, $prompt_id, $entry_id, $form_data, $form) {
        $generate_pdf = get_post_meta($prompt_id, '_sfaic_generate_pdf', true);
        if ($generate_pdf != '1') {
            return;
        }

        error_log("SFAIC PDF: Starting PDF generation for entry {$entry_id}");

        $pdf_result = $this->generate_pdf_simple($ai_response, $prompt_id, $entry_id, $form_data, $form);

        if (!is_wp_error($pdf_result)) {
            update_post_meta($entry_id, '_sfaic_pdf_url', $pdf_result['url']);
            update_post_meta($entry_id, '_sfaic_pdf_filename', $pdf_result['filename']);
            update_post_meta($entry_id, '_sfaic_pdf_path', $pdf_result['path']);
            update_post_meta($entry_id, '_sfaic_pdf_generated_at', current_time('mysql'));
            
            error_log("SFAIC PDF: Successfully generated PDF: {$pdf_result['filename']} ({$pdf_result['size']} bytes)");
        } else {
            error_log("SFAIC PDF: Failed to generate PDF: " . $pdf_result->get_error_message());
        }
    }

    /**
     * Simple PDF generation without complex processing
     */
    private function generate_pdf_simple($ai_response, $prompt_id, $entry_id, $form_data, $form) {
        if (!class_exists('Mpdf\Mpdf')) {
            return new WP_Error('mpdf_not_available', 'mPDF library is not available');
        }

        try {
            // Increase limits for large PDFs
            @ini_set('memory_limit', '512M');
            @set_time_limit(300);

            // Get settings
            $pdf_title = get_post_meta($prompt_id, '_sfaic_pdf_title', true) ?: 'AI Response Report';
            $pdf_format = get_post_meta($prompt_id, '_sfaic_pdf_format', true) ?: 'A4';
            $pdf_orientation = get_post_meta($prompt_id, '_sfaic_pdf_orientation', true) ?: 'P';
            $pdf_margin = intval(get_post_meta($prompt_id, '_sfaic_pdf_margin', true)) ?: 15;
            $pdf_template_html = get_post_meta($prompt_id, '_sfaic_pdf_template_html', true) ?: $this->get_default_template();
            $pdf_filename = get_post_meta($prompt_id, '_sfaic_pdf_filename', true) ?: 'ai-response-{entry_id}';

            // Clean the AI response content
            $ai_response = $this->clean_content_for_pdf($ai_response);

            // Prepare template variables
            $template_vars = array(
                'title' => $pdf_title,
                'content' => $ai_response,
                'date' => date_i18n(get_option('date_format')),
                'time' => date_i18n(get_option('time_format')),
                'entry_id' => $entry_id,
                'form_title' => $form->title ?? 'Form',
                'form_id' => $form->id ?? 0,
                'site_name' => get_bloginfo('name'),
            );

            // Add form data to variables
            foreach ($form_data as $key => $value) {
                if (is_scalar($key) && is_scalar($value)) {
                    $template_vars[$key] = htmlspecialchars($value);
                }
            }

            // Process template
            $html_content = $this->process_template($pdf_template_html, $template_vars);

            // Create temp directory
            $upload_dir = wp_upload_dir();
            $temp_dir = $upload_dir['basedir'] . '/mpdf-temp';
            if (!file_exists($temp_dir)) {
                wp_mkdir_p($temp_dir);
            }

            // Create mPDF instance with simple configuration
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => $pdf_format,
                'orientation' => $pdf_orientation,
                'margin_left' => $pdf_margin,
                'margin_right' => $pdf_margin,
                'margin_top' => $pdf_margin,
                'margin_bottom' => $pdf_margin,
                'tempDir' => $temp_dir,
                'default_font' => 'dejavusans',
                'allow_charset_conversion' => false,
                'shrink_tables_to_fit' => 1,
                'keep_table_proportions' => true,
                'allow_output_buffering' => true,
            ]);

            // Set properties
            $mpdf->SetTitle($pdf_title);
            $mpdf->SetAuthor(get_bloginfo('name'));
            $mpdf->SetCreator('AI API Connector');

            // IMPORTANT: Set autoScriptToLang and autoLangToFont to handle UTF-8 properly
            $mpdf->autoScriptToLang = true;
            $mpdf->autoLangToFont = true;

            // Write HTML content
            $mpdf->WriteHTML($html_content);

            // Output PDF to string
            $pdf_content = $mpdf->Output('', 'S');

            // Validate PDF
            if (empty($pdf_content) || strlen($pdf_content) < 1000) {
                throw new Exception('Generated PDF is too small or empty');
            }

            // Save PDF
            return $this->save_pdf_file($pdf_content, $pdf_filename, $entry_id);

        } catch (Exception $e) {
            error_log('SFAIC PDF Error: ' . $e->getMessage());
            return new WP_Error('pdf_generation_failed', $e->getMessage());
        }
    }

    /**
     * Clean content for PDF - minimal processing
     */
    private function clean_content_for_pdf($content) {
        // Basic HTML entity decode
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove script and style tags
        $content = preg_replace('/<script[^>]*?>.*?<\/script>/si', '', $content);
        $content = preg_replace('/<style[^>]*?>.*?<\/style>/si', '', $content);
        
        // Convert newlines to paragraphs if needed
        if (strpos($content, '<p>') === false && strpos($content, '<br') === false) {
            $content = '<p>' . str_replace("\n\n", '</p><p>', $content) . '</p>';
        }
        
        // Ensure UTF-8 encoding
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        }
        
        return $content;
    }

    /**
     * Process template with variables
     */
    private function process_template($template, $variables) {
        foreach ($variables as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        return $template;
    }

    /**
     * Save PDF file
     */
    private function save_pdf_file($pdf_data, $filename_template, $entry_id) {
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/ai-pdfs';
        $pdf_url_dir = $upload_dir['baseurl'] . '/ai-pdfs';

        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        // Process filename
        $filename = str_replace(
            array('{entry_id}', '{date}', '{time}', '{form_id}'),
            array($entry_id, date('Y-m-d'), date('H-i-s'), time()),
            $filename_template
        );
        
        $filename = sanitize_file_name($filename);
        if (!str_ends_with($filename, '.pdf')) {
            $filename .= '.pdf';
        }

        $file_path = $pdf_dir . '/' . $filename;
        $file_url = $pdf_url_dir . '/' . $filename;

        // Save file
        $result = file_put_contents($file_path, $pdf_data);
        
        if ($result === false) {
            return new WP_Error('file_write_failed', 'Failed to write PDF file');
        }

        return array(
            'filename' => $filename,
            'path' => $file_path,
            'url' => $file_url,
            'size' => filesize($file_path),
        );
    }

    /**
     * Get PDF attachment for email
     */
    public function get_pdf_attachment($entry_id) {
        $pdf_path = get_post_meta($entry_id, '_sfaic_pdf_path', true);
        
        if (!empty($pdf_path) && file_exists($pdf_path)) {
            return $pdf_path;
        }
        
        return false;
    }
}