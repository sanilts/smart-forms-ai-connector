<?php
/**
 * PDF Generator Class
 * Handles generating PDFs from AI responses
 */
class SFAIC_PDF_Generator {
    
    /**
     * Upload directory for PDFs
     */
    private $upload_dir;
    
    /**
     * Upload URL for PDFs
     */
    private $upload_url;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Set up upload directories
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/ai-pdfs';
        $this->upload_url = $upload_dir['baseurl'] . '/ai-pdfs';
        
        // Ensure directory exists
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
        
        // Include TCPDF if not already included
        $this->include_tcpdf();
        
        // Register hooks
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Hook to generate PDF after AI response
        add_action('sfaic_after_ai_response_processed', array($this, 'maybe_generate_pdf'), 10, 5);
        
        // Admin columns for PDF status
        add_filter('manage_sfaic_prompt_posts_columns', array($this, 'add_pdf_column'));
        add_action('manage_sfaic_prompt_posts_custom_column', array($this, 'render_pdf_column'), 10, 2);
        
        // AJAX handler for PDF preview
        add_action('wp_ajax_sfaic_preview_pdf', array($this, 'ajax_preview_pdf'));
        
        // Add scripts for PDF preview
        add_action('admin_enqueue_scripts', array($this, 'enqueue_pdf_scripts'));
    }
    
    /**
     * Include TCPDF library
     */
    private function include_tcpdf() {
        if (!class_exists('TCPDF')) {
            $tcpdf_path = SFAIC_DIR . 'vendor/tecnickcom/tcpdf/tcpdf.php';
            
            if (file_exists($tcpdf_path)) {
                require_once $tcpdf_path;
            } else {
                // Try alternative location or use WordPress PDF library if available
                $alt_tcpdf_path = SFAIC_DIR . 'lib/tcpdf/tcpdf.php';
                if (file_exists($alt_tcpdf_path)) {
                    require_once $alt_tcpdf_path;
                }
            }
        }
    }
    
    /**
     * Check if PDF generation is enabled for a prompt
     */
    public function is_pdf_enabled($prompt_id) {
        return get_post_meta($prompt_id, '_sfaic_pdf_generation_enabled', true) == '1';
    }
    
    /**
     * Maybe generate PDF after AI response
     */
    public function maybe_generate_pdf($ai_response, $prompt_id, $entry_id, $form_data, $form) {
        // Check if PDF generation is enabled
        if (!$this->is_pdf_enabled($prompt_id)) {
            return;
        }
        
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            error_log('SFAIC: TCPDF library not found for PDF generation');
            return;
        }
        
        try {
            // Generate the PDF
            $pdf_path = $this->generate_pdf($ai_response, $prompt_id, $entry_id, $form_data, $form);
            
            if ($pdf_path) {
                // Store PDF path in meta
                update_post_meta($entry_id, '_sfaic_pdf_path', $pdf_path);
                update_post_meta($entry_id, '_sfaic_pdf_generated', current_time('mysql'));
                
                error_log('SFAIC: PDF generated successfully for entry ' . $entry_id);
            }
        } catch (Exception $e) {
            error_log('SFAIC: PDF generation error: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate PDF from AI response
     */
    public function generate_pdf($ai_response, $prompt_id, $entry_id, $form_data, $form) {
        // Get PDF settings
        $pdf_settings = $this->get_pdf_settings($prompt_id);
        
        // Create filename
        $filename = $this->generate_pdf_filename($prompt_id, $entry_id, $form_data);
        $pdf_path = $this->upload_dir . '/' . $filename;
        
        // Initialize TCPDF
        $pdf = new TCPDF(
            $pdf_settings['orientation'],
            'mm',
            $pdf_settings['format'],
            true,
            'UTF-8',
            false
        );
        
        // Set document information
        $pdf->SetCreator('AI Forms Connector');
        $pdf->SetAuthor(get_bloginfo('name'));
        $pdf->SetTitle($pdf_settings['title']);
        $pdf->SetSubject($pdf_settings['subject']);
        
        // Set margins
        $pdf->SetMargins($pdf_settings['margin_left'], $pdf_settings['margin_top'], $pdf_settings['margin_right']);
        $pdf->SetHeaderMargin($pdf_settings['margin_header']);
        $pdf->SetFooterMargin($pdf_settings['margin_footer']);
        
        // Set auto page breaks
        $pdf->SetAutoPageBreak(true, $pdf_settings['margin_bottom']);
        
        // Set font - using default fonts that support most characters
        $pdf->SetFont('helvetica', '', 10);
        
        // Add custom header if enabled
        if ($pdf_settings['header_enabled']) {
            $pdf->setHeaderCallback(array($this, 'pdf_header_callback'));
            $pdf->setHeaderData($pdf_settings);
        }
        
        // Add custom footer if enabled
        if ($pdf_settings['footer_enabled']) {
            $pdf->setFooterCallback(array($this, 'pdf_footer_callback'));
            $pdf->setFooterData($pdf_settings);
        }
        
        // Add a page
        $pdf->AddPage();
        
        // Prepare content with custom template
        $content = $this->prepare_pdf_content($ai_response, $prompt_id, $entry_id, $form_data, $pdf_settings);
        
        // Write the content
        $pdf->writeHTML($content, true, false, true, false, '');
        
        // Include form data if enabled
        if ($pdf_settings['include_form_data']) {
            $pdf->AddPage();
            $this->add_form_data_page($pdf, $form_data, $prompt_id);
        }
        
        // Add watermark if enabled
        if ($pdf_settings['watermark_enabled'] && !empty($pdf_settings['watermark_text'])) {
            $this->add_watermark($pdf, $pdf_settings['watermark_text']);
        }
        
        // Save PDF
        $pdf->Output($pdf_path, 'F');
        
        // Return the path if successful
        if (file_exists($pdf_path)) {
            return $pdf_path;
        }
        
        return false;
    }
    
    /**
     * Get PDF settings for a prompt
     */
    private function get_pdf_settings($prompt_id) {
        $defaults = array(
            'format' => 'A4',
            'orientation' => 'P', // Portrait
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_header' => 5,
            'margin_footer' => 10,
            'header_enabled' => true,
            'footer_enabled' => true,
            'header_text' => get_bloginfo('name'),
            'footer_text' => 'Page {PAGE} of {PAGES}',
            'title' => 'AI Generated Response',
            'subject' => 'Form Submission Response',
            'watermark_enabled' => false,
            'watermark_text' => '',
            'include_form_data' => true,
            'template' => 'default',
            'custom_css' => '',
            'logo_enabled' => false,
            'logo_url' => ''
        );
        
        // Get saved settings
        $saved_settings = get_post_meta($prompt_id, '_sfaic_pdf_settings', true);
        
        if (!is_array($saved_settings)) {
            $saved_settings = array();
        }
        
        // Merge with defaults
        $settings = wp_parse_args($saved_settings, $defaults);
        
        // Get individual meta values for backward compatibility
        $settings['format'] = get_post_meta($prompt_id, '_sfaic_pdf_format', true) ?: $settings['format'];
        $settings['orientation'] = get_post_meta($prompt_id, '_sfaic_pdf_orientation', true) ?: $settings['orientation'];
        $settings['template'] = get_post_meta($prompt_id, '_sfaic_pdf_template', true) ?: $settings['template'];
        
        return $settings;
    }
    
    /**
     * Generate PDF filename
     */
    private function generate_pdf_filename($prompt_id, $entry_id, $form_data) {
        $filename_template = get_post_meta($prompt_id, '_sfaic_pdf_filename_template', true);
        
        if (empty($filename_template)) {
            $filename_template = 'response-{entry_id}-{date}';
        }
        
        // Replace placeholders
        $replacements = array(
            '{entry_id}' => $entry_id,
            '{prompt_id}' => $prompt_id,
            '{date}' => date('Y-m-d'),
            '{datetime}' => date('Y-m-d-H-i-s'),
            '{timestamp}' => time()
        );
        
        // Add form field placeholders
        foreach ($form_data as $field_key => $field_value) {
            if (is_scalar($field_value)) {
                $replacements['{' . $field_key . '}'] = sanitize_file_name($field_value);
            }
        }
        
        $filename = str_replace(array_keys($replacements), array_values($replacements), $filename_template);
        
        // Sanitize filename
        $filename = sanitize_file_name($filename);
        
        // Ensure .pdf extension
        if (!preg_match('/\.pdf$/i', $filename)) {
            $filename .= '.pdf';
        }
        
        // Make unique if file exists
        $counter = 1;
        $original_filename = $filename;
        while (file_exists($this->upload_dir . '/' . $filename)) {
            $filename = str_replace('.pdf', '-' . $counter . '.pdf', $original_filename);
            $counter++;
        }
        
        return $filename;
    }
    
    /**
     * Prepare PDF content with template
     */
    private function prepare_pdf_content($ai_response, $prompt_id, $entry_id, $form_data, $pdf_settings) {
        $template = $pdf_settings['template'];
        
        // Get custom HTML template if selected
        if ($template === 'custom') {
            $custom_template = get_post_meta($prompt_id, '_sfaic_pdf_custom_template', true);
            
            if (!empty($custom_template)) {
                $content = $custom_template;
            } else {
                $content = $this->get_default_template();
            }
        } else {
            // Use predefined template
            $content = $this->get_template($template);
        }
        
        // Prepare placeholders
        $placeholders = array(
            '{ai_response}' => $ai_response,
            '{date}' => date_i18n(get_option('date_format')),
            '{time}' => date_i18n(get_option('time_format')),
            '{datetime}' => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => get_site_url(),
            '{entry_id}' => $entry_id,
            '{prompt_title}' => get_the_title($prompt_id)
        );
        
        // Add form field placeholders
        foreach ($form_data as $field_key => $field_value) {
            if (is_array($field_value)) {
                $field_value = implode(', ', $field_value);
            }
            if (is_scalar($field_value)) {
                $placeholders['{' . $field_key . '}'] = htmlspecialchars($field_value);
            }
        }
        
        // Replace placeholders
        $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);
        
        // Add custom CSS if provided
        if (!empty($pdf_settings['custom_css'])) {
            $content = '<style>' . $pdf_settings['custom_css'] . '</style>' . $content;
        }
        
        // Clean HTML for TCPDF
        $content = $this->clean_html_for_pdf($content);
        
        return $content;
    }
    
    /**
     * Get predefined template
     */
    private function get_template($template_name) {
        $templates = array(
            'default' => '
                <h1 style="color: #333; text-align: center;">AI Response</h1>
                <div style="margin-top: 20px;">
                    {ai_response}
                </div>
                <hr style="margin-top: 30px;">
                <p style="font-size: 10px; color: #666; text-align: center;">
                    Generated on {datetime} | Entry ID: {entry_id}
                </p>
            ',
            'professional' => '
                <div style="border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px;">
                    <h1 style="color: #000; margin: 0;">{prompt_title}</h1>
                    <p style="color: #666; margin: 5px 0;">Document ID: {entry_id}</p>
                    <p style="color: #666; margin: 5px 0;">Date: {date}</p>
                </div>
                <div style="line-height: 1.6;">
                    {ai_response}
                </div>
                <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ccc;">
                    <p style="font-size: 9px; color: #999;">
                        This document was automatically generated by {site_name}
                    </p>
                </div>
            ',
            'minimal' => '
                <div style="padding: 20px;">
                    {ai_response}
                </div>
            ',
            'letterhead' => '
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #000; margin: 0;">{site_name}</h1>
                    <p style="color: #666; margin: 5px 0;">{site_url}</p>
                </div>
                <div style="margin: 20px 0;">
                    <p>Date: {date}</p>
                    <p>Reference: {entry_id}</p>
                </div>
                <div style="margin-top: 30px;">
                    {ai_response}
                </div>
            '
        );
        
        return isset($templates[$template_name]) ? $templates[$template_name] : $templates['default'];
    }
    
    /**
     * Get default template
     */
    private function get_default_template() {
        return $this->get_template('default');
    }
    
    /**
     * Clean HTML for PDF generation
     */
    private function clean_html_for_pdf($content) {
        // Remove script and style tags
        $content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content);
        $content = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $content);
        
        // Convert special characters
        $content = htmlspecialchars_decode($content, ENT_QUOTES);
        
        // Fix common HTML issues
        $content = str_replace('&nbsp;', ' ', $content);
        
        // Ensure proper UTF-8 encoding
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        }
        
        return $content;
    }
    
    /**
     * Add form data page to PDF
     */
    private function add_form_data_page(&$pdf, $form_data, $prompt_id) {
        $html = '<h2>Form Submission Data</h2>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%;">';
        $html .= '<thead><tr style="background-color: #f0f0f0;"><th>Field</th><th>Value</th></tr></thead>';
        $html .= '<tbody>';
        
        // Get field labels
        $field_labels = $this->get_form_field_labels($prompt_id);
        
        foreach ($form_data as $field_key => $field_value) {
            // Skip internal fields
            if (strpos($field_key, '_') === 0) {
                continue;
            }
            
            $label = isset($field_labels[$field_key]) ? $field_labels[$field_key] : $field_key;
            
            if (is_array($field_value)) {
                $field_value = implode(', ', $field_value);
            }
            
            $html .= '<tr>';
            $html .= '<td style="font-weight: bold;">' . htmlspecialchars($label) . '</td>';
            $html .= '<td>' . htmlspecialchars($field_value) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
    }
    
    /**
     * Get form field labels
     */
    private function get_form_field_labels($prompt_id) {
        $field_labels = array();
        $form_id = get_post_meta($prompt_id, '_sfaic_fluent_form_id', true);
        
        if (empty($form_id) || !function_exists('wpFluent')) {
            return $field_labels;
        }
        
        $form = wpFluent()->table('fluentform_forms')
            ->where('id', $form_id)
            ->first();
            
        if ($form && !empty($form->form_fields)) {
            $formFields = json_decode($form->form_fields, true);
            
            if (!empty($formFields['fields'])) {
                foreach ($formFields['fields'] as $field) {
                    if (!empty($field['element']) && !empty($field['attributes']['name'])) {
                        $field_name = $field['attributes']['name'];
                        $field_label = !empty($field['settings']['label']) ? 
                            $field['settings']['label'] : $field_name;
                        $field_labels[$field_name] = $field_label;
                    }
                }
            }
        }
        
        return $field_labels;
    }
    
    /**
     * Add watermark to PDF
     */
    private function add_watermark(&$pdf, $watermark_text) {
        $pdf->SetAlpha(0.1);
        $pdf->StartTransform();
        $pdf->Rotate(45, 100, 150);
        $pdf->SetFont('helvetica', 'B', 50);
        $pdf->SetTextColor(200, 200, 200);
        $pdf->Text(35, 120, $watermark_text);
        $pdf->StopTransform();
        $pdf->SetAlpha(1);
    }
    
    /**
     * PDF header callback
     */
    public function pdf_header_callback($pdf) {
        $settings = $pdf->getHeaderData();
        
        if (!empty($settings['logo_enabled']) && !empty($settings['logo_url'])) {
            // Add logo
            $pdf->Image($settings['logo_url'], 10, 5, 30);
            $pdf->SetX(45);
        }
        
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, $settings['header_text'], 0, 1, 'C');
        $pdf->SetLineWidth(0.3);
        $pdf->Line(10, 20, $pdf->getPageWidth() - 10, 20);
    }
    
    /**
     * PDF footer callback
     */
    public function pdf_footer_callback($pdf) {
        $settings = $pdf->getFooterData();
        
        $pdf->SetY(-15);
        $pdf->SetFont('helvetica', 'I', 8);
        
        $footer_text = str_replace(
            array('{PAGE}', '{PAGES}'),
            array($pdf->getAliasNumPage(), $pdf->getAliasNbPages()),
            $settings['footer_text']
        );
        
        $pdf->Cell(0, 10, $footer_text, 0, 0, 'C');
    }
    
    /**
     * Get PDF attachment path for email
     */
    public function get_pdf_attachment($entry_id) {
        $pdf_path = get_post_meta($entry_id, '_sfaic_pdf_path', true);
        
        if (!empty($pdf_path) && file_exists($pdf_path)) {
            return $pdf_path;
        }
        
        return false;
    }
    
    /**
     * Add PDF column to prompt list
     */
    public function add_pdf_column($columns) {
        $columns['pdf_enabled'] = __('PDF', 'chatgpt-fluent-connector');
        return $columns;
    }
    
    /**
     * Render PDF column content
     */
    public function render_pdf_column($column, $post_id) {
        if ($column === 'pdf_enabled') {
            if ($this->is_pdf_enabled($post_id)) {
                echo '<span class="dashicons dashicons-pdf" style="color: #00a32a;" title="' . 
                     esc_attr__('PDF generation enabled', 'chatgpt-fluent-connector') . '"></span>';
            } else {
                echo '<span class="dashicons dashicons-minus" style="color: #ccc;"></span>';
            }
        }
    }
    
    /**
     * Enqueue PDF scripts
     */
    public function enqueue_pdf_scripts($hook) {
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            global $post_type;
            
            if ($post_type === 'sfaic_prompt') {
                wp_enqueue_script(
                    'sfaic-pdf-preview',
                    SFAIC_URL . 'assets/js/pdf-preview.js',
                    array('jquery'),
                    SFAIC_VERSION,
                    true
                );
                
                wp_localize_script('sfaic-pdf-preview', 'sfaic_pdf', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('sfaic_pdf_preview')
                ));
            }
        }
    }
    
    /**
     * AJAX handler for PDF preview
     */
    public function ajax_preview_pdf() {
        check_ajax_referer('sfaic_pdf_preview', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }
        
        $prompt_id = isset($_POST['prompt_id']) ? intval($_POST['prompt_id']) : 0;
        
        if (!$prompt_id) {
            wp_send_json_error('Invalid prompt ID');
        }
        
        // Generate sample PDF content
        $sample_content = $this->generate_sample_pdf_content($prompt_id);
        
        wp_send_json_success(array(
            'content' => $sample_content
        ));
    }
    
    /**
     * Generate sample PDF content for preview
     */
    private function generate_sample_pdf_content($prompt_id) {
        $pdf_settings = $this->get_pdf_settings($prompt_id);
        
        $sample_ai_response = '<p>This is a sample AI response for preview purposes.</p>
            <p>The actual response will be generated based on the form submission and your configured prompts.</p>
            <ul>
                <li>Item 1</li>
                <li>Item 2</li>
                <li>Item 3</li>
            </ul>
            <p>Thank you for your submission!</p>';
            
        $sample_form_data = array(
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'message' => 'This is a sample message'
        );
        
        $content = $this->prepare_pdf_content(
            $sample_ai_response,
            $prompt_id,
            '12345',
            $sample_form_data,
            $pdf_settings
        );
        
        return $content;
    }
}