<?php

/**
 * Enhanced PDF Generator Class - Fixed for Complete PDF Generation
 * 
 * Handles PDF generation from AI responses with improved reliability and emoji support
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

        // Hook into the AI response to fix encoding early
        add_filter('sfaic_ai_response', array($this, 'fix_response_encoding'), 5);

        // Increase memory and execution time for PDF generation
        add_action('sfaic_before_pdf_generation', array($this, 'prepare_environment_for_pdf'));
    }

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

        // Fix double-encoded UTF-8 sequences
        $content = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UTF-16BE');
        }, $content);

        // Fix mojibake patterns (common double-encoded sequences)
        $mojibake_patterns = array(
            '/\xC3\xB0\xC5\x92[\x80-\xBF][\x80-\xBF]/' => '',
            '/Ã\x83Â©/' => 'é',
            '/Ã\x83Â¨/' => 'è',
            '/Ã\x83Â«/' => 'ë',
            '/Ã\x83Â¢/' => 'â',
            '/Ã\x83Â´/' => 'ô',
            '/Ã\x83Â®/' => 'î',
            '/Ã\x83Â§/' => 'ç',
            '/Ã\x83Â /' => 'à',
            '/Ã\x83Â¹/' => 'ù',
            '/Ã\x83â€°/' => 'É',
            '/Ã\x83â‚¬/' => 'À',
            '/Ã\x83Âª/' => 'ê',
            '/Ã\x83Â¯/' => 'ï',
            '/Ã\x83Â¼/' => 'ü',
            '/Ã\x83Â¶/' => 'ö',
            '/Ã\x83Â¤/' => 'ä',
        );

        foreach ($mojibake_patterns as $bad => $good) {
            $content = str_replace($bad, $good, $content);
        }

        // Decode HTML entities if present
        if (strpos($content, '&') !== false) {
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Clean up any remaining invalid UTF-8 sequences
        $content = @iconv('UTF-8', 'UTF-8//IGNORE', $content);

        // Final UTF-8 validation and cleanup
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        }

        return $content;
    }

    /**
     * Enhanced emoji to image conversion with better caching and fallbacks
     */
    private function convert_emojis_to_images($html) {
        // First fix encoding
        $html = $this->fix_encoding_comprehensive($html);

        // Enhanced emoji pattern covering more Unicode ranges
        $emoji_pattern = '/[\x{1F600}-\x{1F64F}]|[\x{1F300}-\x{1F5FF}]|[\x{1F680}-\x{1F6FF}]|[\x{1F1E0}-\x{1F1FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|[\x{1F900}-\x{1F9FF}]|[\x{1F000}-\x{1F02F}]|[\x{1F0A0}-\x{1F0FF}]|[\x{1F100}-\x{1F1FF}]|[\x{FE00}-\x{FE0F}]|[\x{1F200}-\x{1F2FF}]|[\x{E0020}-\x{E007F}]|[\x{2190}-\x{21FF}]|[\x{2000}-\x{206F}]|[\x{20A0}-\x{20CF}]|[\x{2100}-\x{214F}]|[\x{2150}-\x{218F}]|[\x{2460}-\x{24FF}]|[\x{25A0}-\x{25FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]/u';

        // Use callback to replace emojis
        $html = preg_replace_callback(
                $emoji_pattern,
                array($this, 'emoji_to_image_enhanced'),
                $html
        );

        return $html;
    }

    /**
     * Enhanced emoji to image conversion with better fallbacks and caching
     */
    private function emoji_to_image_enhanced($matches) {
        $emoji = $matches[0];

        // Try cached version first
        $cache_key = 'emoji_fallback_' . md5($emoji);
        $cached_result = wp_cache_get($cache_key);
        if ($cached_result !== false) {
            return $cached_result;
        }

        // Try to get emoji image with timeout and retry
        $image_html = $this->try_emoji_image_with_retry($emoji);
        if ($image_html) {
            wp_cache_set($cache_key, $image_html, '', 3600); // Cache for 1 hour
            return $image_html;
        }

        // Enhanced fallback system
        $fallback = $this->enhanced_emoji_fallback($emoji);
        wp_cache_set($cache_key, $fallback, '', 3600);
        return $fallback;
    }

    /**
     * Try emoji image with retry mechanism
     */
    private function try_emoji_image_with_retry($emoji) {
        $sources = array(
            'noto' => $this->get_noto_emoji_url($emoji),
            'twemoji' => $this->get_twemoji_url($emoji),
            'openmoji' => $this->get_openmoji_url($emoji)
        );

        foreach ($sources as $source_name => $url) {
            if ($url) {
                // Try up to 2 times with different timeout settings
                $image_data = $this->get_emoji_image_base64_with_retry($url, $emoji, $source_name);
                if ($image_data) {
                    return '<img src="' . $image_data . '" style="width: 1.2em; height: 1.2em; vertical-align: middle; display: inline-block;" alt="' . htmlspecialchars($emoji) . '" />';
                }
            }
        }

        return false;
    }

    /**
     * Get emoji image with retry mechanism
     */
    private function get_emoji_image_base64_with_retry($url, $emoji, $source = 'default') {
        // Try cache first
        $cache_key = 'emoji_img_' . md5($emoji . $source);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // Try with different timeout settings
        $timeout_settings = [5, 3]; // Try 5 seconds first, then 3 seconds

        foreach ($timeout_settings as $timeout) {
            $response = wp_remote_get($url, array(
                'timeout' => $timeout,
                'sslverify' => false,
                'user-agent' => 'Mozilla/5.0 (PDF Generator)',
                'headers' => array(
                    'Accept' => 'image/png,image/*,*/*;q=0.8'
                ),
                'redirection' => 3
            ));

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $image_data = wp_remote_retrieve_body($response);
                if (!empty($image_data) && strlen($image_data) > 100) {
                    $base64 = 'data:image/png;base64,' . base64_encode($image_data);

                    // Cache for 7 days
                    set_transient($cache_key, $base64, 7 * DAY_IN_SECONDS);

                    return $base64;
                }
            }
        }

        return false;
    }

    /**
     * Enhanced emoji fallback system with comprehensive mappings
     */
    private function enhanced_emoji_fallback($emoji) {
        // Comprehensive emoji fallback map
        $fallback_map = array(
            // Stars and sparkles
            '🌟' => '<span style="color: #FFD700; font-size: 1.3em; font-weight: bold;">★</span>',
            '⭐' => '<span style="color: #FFD700; font-size: 1.3em; font-weight: bold;">★</span>',
            '✨' => '<span style="color: #FFD700; font-size: 1.2em;">✦</span>',
            '💫' => '<span style="color: #87CEEB; font-size: 1.2em;">✧</span>',
            // Hearts with better styling
            '❤️' => '<span style="color: #FF0000; font-size: 1.3em; text-shadow: 0 0 2px rgba(255,0,0,0.5);">♥</span>',
            '💙' => '<span style="color: #0000FF; font-size: 1.3em; text-shadow: 0 0 2px rgba(0,0,255,0.5);">♥</span>',
            '💚' => '<span style="color: #00FF00; font-size: 1.3em; text-shadow: 0 0 2px rgba(0,255,0,0.5);">♥</span>',
            '💛' => '<span style="color: #FFD700; font-size: 1.3em; text-shadow: 0 0 2px rgba(255,215,0,0.5);">♥</span>',
            '🧡' => '<span style="color: #FF8C00; font-size: 1.3em; text-shadow: 0 0 2px rgba(255,140,0,0.5);">♥</span>',
            '💜' => '<span style="color: #8A2BE2; font-size: 1.3em; text-shadow: 0 0 2px rgba(138,43,226,0.5);">♥</span>',
            // Check marks and status indicators
            '✅' => '<span style="color: #00FF00; font-size: 1.3em; font-weight: bold; text-shadow: 0 0 2px rgba(0,255,0,0.5);">✓</span>',
            '✔️' => '<span style="color: #00FF00; font-size: 1.3em; font-weight: bold;">✓</span>',
            '❌' => '<span style="color: #FF0000; font-size: 1.3em; font-weight: bold; text-shadow: 0 0 2px rgba(255,0,0,0.5);">✗</span>',
            '❎' => '<span style="color: #FF0000; font-size: 1.2em;">⊗</span>',
            // Circles and indicators
            '🔴' => '<span style="color: #FF0000; font-size: 1.3em;">●</span>',
            '🟢' => '<span style="color: #00FF00; font-size: 1.3em;">●</span>',
            '🔵' => '<span style="color: #0000FF; font-size: 1.3em;">●</span>',
            '🟡' => '<span style="color: #FFD700; font-size: 1.3em;">●</span>',
            // Arrows with better styling
            '➡️' => '<span style="color: #000000; font-size: 1.3em; font-weight: bold;">→</span>',
            '⬅️' => '<span style="color: #000000; font-size: 1.3em; font-weight: bold;">←</span>',
            '⬆️' => '<span style="color: #000000; font-size: 1.3em; font-weight: bold;">↑</span>',
            '⬇️' => '<span style="color: #000000; font-size: 1.3em; font-weight: bold;">↓</span>',
            // Tools and objects
            '💡' => '<span style="color: #FFD700; font-size: 1.3em;">💡</span>',
            '🔧' => '<span style="color: #A0A0A0; font-size: 1.3em;">🔧</span>',
            '📌' => '<span style="color: #FF0000; font-size: 1.3em;">📌</span>',
            '📊' => '<span style="color: #4169E1; font-size: 1.3em;">📊</span>',
            // Warning and attention
            '⚠️' => '<span style="color: #FFD700; font-size: 1.3em; font-weight: bold;">⚠</span>',
            '🚨' => '<span style="color: #FF0000; font-size: 1.3em;">🚨</span>',
            '❗' => '<span style="color: #FF0000; font-size: 1.3em; font-weight: bold;">!</span>',
            '❓' => '<span style="color: #4169E1; font-size: 1.3em; font-weight: bold;">?</span>',
            // Smileys with better fallbacks
            '😊' => '<span style="color: #FFD700; font-size: 1.3em;">☺</span>',
            '😃' => '<span style="color: #FFD700; font-size: 1.3em;">☺</span>',
            '😄' => '<span style="color: #FFD700; font-size: 1.3em;">☺</span>',
            '🙂' => '<span style="color: #FFD700; font-size: 1.3em;">☺</span>',
            '😉' => '<span style="color: #FFD700; font-size: 1.3em;">😉</span>',
        );

        // Check for specific fallback
        if (isset($fallback_map[$emoji])) {
            return $fallback_map[$emoji];
        }

        // Generic fallback based on Unicode ranges
        $unicode_point = $this->get_first_unicode_point($emoji);

        if ($unicode_point) {
            if ($unicode_point >= 0x1F600 && $unicode_point <= 0x1F64F) {
                return '<span style="color: #FFD700; font-size: 1.3em;">☺</span>';
            } elseif ($unicode_point >= 0x1F400 && $unicode_point <= 0x1F4FF) {
                return '<span style="color: #228B22; font-size: 1.3em;">🐾</span>';
            } elseif ($unicode_point >= 0x2600 && $unicode_point <= 0x26FF) {
                return '<span style="color: #000000; font-size: 1.3em;">●</span>';
            }
        }

        // Final fallback with better font handling
        return '<span style="font-family: \'Apple Color Emoji\', \'Segoe UI Emoji\', \'Noto Color Emoji\', \'EmojiOne Color\', sans-serif; font-size: 1.2em;">' . $emoji . '</span>';
    }

    /**
     * Get emoji URLs (existing methods with better error handling)
     */
    private function get_noto_emoji_url($emoji) {
        $codepoints = $this->emoji_to_codepoints($emoji);
        if ($codepoints) {
            return 'https://raw.githubusercontent.com/googlefonts/noto-emoji/main/png/128/emoji_u' . $codepoints . '.png';
        }
        return false;
    }

    private function get_twemoji_url($emoji) {
        $codepoints = $this->emoji_to_codepoints($emoji, '-');
        if ($codepoints) {
            return 'https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/72x72/' . $codepoints . '.png';
        }
        return false;
    }

    private function get_openmoji_url($emoji) {
        $codepoints = $this->emoji_to_codepoints($emoji, '-');
        if ($codepoints) {
            return 'https://cdn.jsdelivr.net/npm/openmoji@latest/color/72x72/' . strtoupper($codepoints) . '.png';
        }
        return false;
    }

    /**
     * Convert emoji to Unicode codepoints
     */
    private function emoji_to_codepoints($emoji, $separator = '_') {
        $codepoints = [];
        $emoji = mb_convert_encoding($emoji, 'UTF-32', 'UTF-8');
        $length = mb_strlen($emoji, 'UTF-32');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($emoji, $i, 1, 'UTF-32');
            $codepoint = unpack('N', $char)[1];

            // Skip variation selectors and zero-width joiners
            if ($codepoint !== 0xFE0F && $codepoint !== 0xFE0E && $codepoint !== 0x200D) {
                $codepoints[] = sprintf('%04x', $codepoint);
            }
        }

        return empty($codepoints) ? false : implode($separator, $codepoints);
    }

    /**
     * Get first Unicode point
     */
    private function get_first_unicode_point($emoji) {
        $emoji = mb_convert_encoding($emoji, 'UTF-32', 'UTF-8');
        if (mb_strlen($emoji, 'UTF-32') > 0) {
            $char = mb_substr($emoji, 0, 1, 'UTF-32');
            return unpack('N', $char)[1];
        }
        return false;
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

        // Fallback: try direct mPDF inclusion
        $direct_paths = array(
            SFAIC_DIR . 'vendor/mpdf/mpdf/src/Mpdf.php',
            SFAIC_DIR . 'includes/mpdf/src/Mpdf.php',
        );

        foreach ($direct_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                if (class_exists('Mpdf\Mpdf')) {
                    return true;
                }
            }
        }

        // Add admin notice
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
                <?php _e('To use PDF generation, please install mPDF library.', 'chatgpt-fluent-connector'); ?>
                <br>
                <strong><?php _e('Installation options:', 'chatgpt-fluent-connector'); ?></strong>
            </p>
            <ol>
                <li><?php _e('Via Composer (Recommended):', 'chatgpt-fluent-connector'); ?> <code>composer require mpdf/mpdf</code></li>
                <li><a href="https://github.com/mpdf/mpdf/releases" target="_blank"><?php _e('Manual Download from GitHub', 'chatgpt-fluent-connector'); ?></a></li>
            </ol>
        </div>
        <?php
    }

    /**
     * Add meta box for PDF settings (existing method - keeping original)
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
     * Render PDF settings meta box (keeping original but with small enhancements)
     */
    public function render_pdf_settings_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('sfaic_pdf_settings_save', 'sfaic_pdf_settings_nonce');

        // Get saved values
        $generate_pdf = get_post_meta($post->ID, '_sfaic_generate_pdf', true);
        $pdf_filename = get_post_meta($post->ID, '_sfaic_pdf_filename', true);
        $pdf_attach_to_email = get_post_meta($post->ID, '_sfaic_pdf_attach_to_email', true);
        $pdf_title = get_post_meta($post->ID, '_sfaic_pdf_title', true);
        $pdf_format = get_post_meta($post->ID, '_sfaic_pdf_format', true);
        $pdf_orientation = get_post_meta($post->ID, '_sfaic_pdf_orientation', true);
        $pdf_margin = get_post_meta($post->ID, '_sfaic_pdf_margin', true);
        $pdf_template_html = get_post_meta($post->ID, '_sfaic_pdf_template_html', true);

        // Set defaults
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
        ?>

        <div class="sfaic-pdf-settings-notice" style="background: #e8f5e8; padding: 15px; margin-bottom: 20px; border-left: 4px solid #28a745; border-radius: 3px;">
            <h4 style="margin-top: 0; color: #28a745;">📄 Enhanced PDF Generation</h4>
            <p style="margin-bottom: 0;">This version includes improved emoji support, better encoding handling, and more reliable PDF generation with automatic retry mechanisms.</p>
        </div>

        <table class="form-table">
            <tr>
                <th><label for="sfaic_generate_pdf"><?php _e('Generate PDF:', 'chatgpt-fluent-connector'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sfaic_generate_pdf" id="sfaic_generate_pdf" value="1" <?php checked($generate_pdf, '1'); ?>>
                        <?php _e('Generate PDF from AI response (Enhanced Version)', 'chatgpt-fluent-connector'); ?>
                    </label>
                    <p class="description">
                        <?php _e('When enabled, the AI response will be converted to PDF using local mPDF library with improved reliability and emoji support.', 'chatgpt-fluent-connector'); ?>
                    </p>
                </td>
            </tr>

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
                        <?php _e('Enhanced HTML template for PDF generation with better emoji and formatting support.', 'chatgpt-fluent-connector'); ?><br>
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
                $('#sfaic_generate_pdf').change(function () {
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
     * Enhanced default HTML template with better emoji and styling support
     */
    private function get_enhanced_default_template() {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{title}</title>
    <style>
        @font-face {
            font-family: "DejaVu Sans";
            font-style: normal;
            font-weight: normal;
        }
        @font-face {
            font-family: "DejaVu Sans";
            font-style: normal;
            font-weight: bold;
        }
        body { 
            font-family: "DejaVu Sans", "Arial Unicode MS", Arial, sans-serif; 
            margin: 0; 
            padding: 20px; 
            line-height: 1.6;
            font-size: 14px;
            color: #333;
            background: #fff;
        }
        img {
            max-width: 100%;
            height: auto;
            vertical-align: middle;
        }
        .emoji {
            width: 1.2em;
            height: 1.2em;
            vertical-align: middle;
            display: inline-block;
        }
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px; 
            margin-bottom: 30px; 
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .title { 
            font-size: 28px; 
            font-weight: bold; 
            margin: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
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
        .content h1, .content h2, .content h3 {
            color: #667eea;
            margin-top: 25px;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .content h1 { font-size: 24px; }
        .content h2 { font-size: 20px; }
        .content h3 { font-size: 16px; }
        
        .content ul, .content ol {
            margin-left: 25px;
            margin-bottom: 15px;
        }
        .content li {
            margin-bottom: 8px;
        }
        .content p {
            margin-bottom: 15px;
            text-align: justify;
        }
        .content blockquote {
            border-left: 4px solid #667eea;
            margin: 20px 0;
            padding: 15px 20px;
            background: #f8f9ff;
            font-style: italic;
            border-radius: 0 4px 4px 0;
        }
        .content code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: "Courier New", monospace;
            font-size: 12px;
        }
        .content pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #667eea;
            overflow-x: auto;
            font-family: "Courier New", monospace;
            font-size: 12px;
            line-height: 1.4;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        th, td {
            border: 1px solid #e0e0e0;
            padding: 12px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9ff;
        }
        tr:hover {
            background-color: #f0f0ff;
        }
        .footer { 
            margin-top: 50px; 
            padding-top: 25px; 
            border-top: 2px solid #667eea; 
            font-size: 12px; 
            color: #666;
            text-align: center;
        }
        .footer .logo {
            font-weight: bold;
            color: #667eea;
        }
        /* Enhanced emoji and special character support */
        .emoji-fallback {
            font-family: "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji", "EmojiOne Color", sans-serif;
        }
        /* Status indicators with better styling */
        .status-good { 
            color: #28a745; 
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(40,167,69,0.3);
        }
        .status-warning { 
            color: #ffc107; 
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(255,193,7,0.3);
        }
        .status-error { 
            color: #dc3545; 
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(220,53,69,0.3);
        }
        /* Improved form display */
        .form-data {
            background: #f8f9ff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .form-row {
            margin-bottom: 15px;
            display: table;
            width: 100%;
        }
        .form-row .label {
            font-weight: bold;
            color: #667eea;
            display: table-cell;
            min-width: 150px;
            padding-right: 20px;
            vertical-align: top;
        }
        .form-row .value {
            display: table-cell;
            vertical-align: top;
        }
        /* Enhanced container */
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        /* Print optimizations */
        @media print {
            .header {
                background: #667eea !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
            }
            body {
                font-size: 12px;
            }
        }
        /* Improved spacing and readability */
        .content > *:first-child {
            margin-top: 0;
        }
        .content > *:last-child {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">{title}</h1>
            <div class="meta">
                📅 Generated on {date} at {time} | 📝 Entry ID: {entry_id} | 📋 Form: {form_title}
            </div>
        </div>
        
        <div class="content">
            {content}
        </div>
        
        <div class="footer">
            <div class="logo">🤖 AI-Generated Document</div>
            <p>This document was automatically generated from form submission data.</p>
            <p><strong>Form:</strong> {form_title} | <strong>Site:</strong> {site_name}</p>
            <p><strong>Generated:</strong> {datetime}</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Save PDF settings (keeping original method)
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

    /**
     * Maybe generate PDF after AI response (enhanced version)
     */
    public function maybe_generate_pdf($ai_response, $prompt_id, $entry_id, $form_data, $form) {
        // Check if PDF generation is enabled
        $generate_pdf = get_post_meta($prompt_id, '_sfaic_generate_pdf', true);
        if ($generate_pdf != '1') {
            return;
        }

        // Prepare environment for PDF generation
        do_action('sfaic_before_pdf_generation');

        // Log the start of PDF generation
        error_log("SFAIC PDF: Starting PDF generation for entry {$entry_id}, prompt {$prompt_id}");

        // Generate PDF with enhanced error handling and retry
        $pdf_result = $this->generate_pdf_with_enhanced_mpdf($ai_response, $prompt_id, $entry_id, $form_data, $form);

        if (!is_wp_error($pdf_result)) {
            // Store PDF info in entry meta
            update_post_meta($entry_id, '_sfaic_pdf_url', $pdf_result['url']);
            update_post_meta($entry_id, '_sfaic_pdf_filename', $pdf_result['filename']);
            update_post_meta($entry_id, '_sfaic_pdf_path', $pdf_result['path']);
            update_post_meta($entry_id, '_sfaic_pdf_generated_at', current_time('mysql'));
            update_post_meta($entry_id, '_sfaic_pdf_service', 'enhanced_local_mpdf');
            update_post_meta($entry_id, '_sfaic_pdf_size', $pdf_result['size']);

            error_log("SFAIC PDF: Successfully generated PDF for entry {$entry_id}: {$pdf_result['filename']} ({$pdf_result['size']} bytes)");
        } else {
            error_log("SFAIC PDF: Failed to generate PDF for entry {$entry_id}: " . $pdf_result->get_error_message());
        }
    }

    /**
     * Enhanced PDF generation with mPDF - FIXED for complete generation
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
     * Prepare content for PDF with Twemoji support
     */
    private function prepare_content_for_pdf($content) {
        // Fix encoding issues first
        $content = $this->fix_encoding_comprehensive($content);

        // Convert emojis to Twemoji images specifically for mPDF
        $content = $this->convert_emojis_to_twemoji_for_mpdf($content);

        // Clean up HTML for PDF
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
        $html = preg_replace('/<object[^>]*?>.*?<\/object>/is', '', $html);
        $html = preg_replace('/<embed[^>]*?>/is', '', $html);

        // Fix self-closing tags
        $html = preg_replace('/<(br|hr|img|input|meta|link)([^>]*?)>/i', '<$1$2 />', $html);

        // Ensure proper paragraph spacing
        $html = preg_replace('/\n\s*\n/', '</p><p>', $html);

        // Fix any unclosed tags (basic fix)
        $html = str_replace(['<p></p>', '<p> </p>', '<p>&nbsp;</p>'], '', $html);

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
            'generation_date' => date('Y-m-d H:i:s', $current_time),
            'user_agent' => 'AI API Connector PDF Generator',
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
     * Process template with Twemoji support
     */
    private function process_template_with_variables($template, $variables) {
        // First replace variables
        foreach ($variables as $key => $value) {
            // Convert emojis in variable values
            if (is_string($value)) {
                $value = $this->convert_emojis_to_twemoji_for_mpdf($value);
            }
            $template = str_replace('{' . $key . '}', $value, $template);
        }

        // Then convert any emojis in the template itself
        $template = $this->convert_emojis_to_twemoji_for_mpdf($template);

        // Remove unprocessed placeholders
        $template = preg_replace('/\{[^}]+\}/', '', $template);

        return $template;
    }

    /**
     * Pre-cache common emojis for better performance
     */
    public function precache_common_emojis() {
        $common_emojis = ['😀', '😃', '😄', '😊', '🙂', '😉', '❤️', '👍', '✅', '❌', '⭐', '🎉', '🔥', '💡', '📧', '📱', '🏠'];

        foreach ($common_emojis as $emoji) {
            $url = $this->get_twemoji_png_url($emoji);
            if ($url) {
                $this->fetch_and_encode_image($url);
            }
        }
    }

    /**
     * Create enhanced mPDF instance with better configuration
     */

    /**
     * Create enhanced mPDF instance with better Twemoji support
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
            'tempDir' => $temp_dir,
            // Important for emoji handling
            'allow_charset_conversion' => false, // Don't convert charset
            'useSubstitutions' => false, // Don't substitute fonts
            'img_dpi' => 96,
            'showImageErrors' => false,
            'allow_output_buffering' => true,
            'curlTimeout' => 10,
            'curlFollowLocation' => true,
            // Allow external images (for Twemoji CDN)
            'curlAllowUnsafeSslRequests' => true,
            // Image handling
            'imageVars' => [
                'interpolation' => false, // Faster processing
            ],
        );

        $mpdf = new \Mpdf\Mpdf($config);

        // Allow img tags with data URIs
        $mpdf->allowInlineScripts = true;

        return $mpdf;
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
            '{year}' => date('Y', $current_time),
            '{month}' => date('m', $current_time),
            '{day}' => date('d', $current_time),
            '{site_name}' => sanitize_title(get_bloginfo('name')),
        );

        // Add form field data (sanitized for filename)
        foreach ($form_data as $field_key => $field_value) {
            if (is_scalar($field_key) && is_scalar($field_value)) {
                $clean_value = sanitize_file_name(substr($field_value, 0, 20)); // Limit length
                $replacements['{' . $field_key . '}'] = $clean_value;
            }
        }

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

        // Ensure directory exists with proper permissions
        if (!file_exists($pdf_dir)) {
            if (!wp_mkdir_p($pdf_dir)) {
                return new WP_Error('directory_creation_failed', __('Failed to create PDF directory', 'chatgpt-fluent-connector'));
            }

            // Create security files
            $this->create_pdf_directory_security_files($pdf_dir);
        }

        // Ensure directory is writable
        if (!is_writable($pdf_dir)) {
            @chmod($pdf_dir, 0755);
            if (!is_writable($pdf_dir)) {
                return new WP_Error('directory_not_writable', __('PDF directory is not writable', 'chatgpt-fluent-connector'));
            }
        }

        // Add .pdf extension if not present
        if (!str_ends_with($filename, '.pdf')) {
            $filename .= '.pdf';
        }

        // Ensure unique filename
        $filename = $this->ensure_unique_filename($pdf_dir, $filename);

        $file_path = $pdf_dir . '/' . $filename;
        $file_url = $pdf_url_dir . '/' . $filename;

        // Write PDF data with atomic operation
        $temp_file = $file_path . '.tmp';
        $result = file_put_contents($temp_file, $pdf_data, LOCK_EX);

        if ($result === false) {
            return new WP_Error('file_write_failed', __('Failed to write PDF file', 'chatgpt-fluent-connector'));
        }

        // Validate written file
        if (filesize($temp_file) !== strlen($pdf_data)) {
            @unlink($temp_file);
            return new WP_Error('file_size_mismatch', __('PDF file size mismatch after writing', 'chatgpt-fluent-connector'));
        }

        // Atomic move to final location
        if (!rename($temp_file, $file_path)) {
            @unlink($temp_file);
            return new WP_Error('file_move_failed', __('Failed to finalize PDF file', 'chatgpt-fluent-connector'));
        }

        // Final validation
        if (!file_exists($file_path) || filesize($file_path) < 1000) {
            @unlink($file_path);
            return new WP_Error('final_validation_failed', __('PDF file validation failed', 'chatgpt-fluent-connector'));
        }

        // Set proper file permissions
        @chmod($file_path, 0644);

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
     * Create security files for PDF directory
     */
    private function create_pdf_directory_security_files($pdf_dir) {
        // Create .htaccess file
        $htaccess_content = "# AI PDF Directory Security\n";
        $htaccess_content .= "Options -Indexes\n";
        $htaccess_content .= "DirectoryIndex index.php index.html\n\n";
        $htaccess_content .= "<Files *.pdf>\n";
        $htaccess_content .= "    Order allow,deny\n";
        $htaccess_content .= "    Allow from all\n";
        $htaccess_content .= "</Files>\n\n";
        $htaccess_content .= "<Files ~ \"\\.(php|phtml|php3|php4|php5|pl|py|jsp|asp|sh|cgi)$\">\n";
        $htaccess_content .= "    Order deny,allow\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</Files>\n";

        @file_put_contents($pdf_dir . '/.htaccess', $htaccess_content);

        // Create index.php to prevent directory listing
        $index_content = "<?php\n";
        $index_content .= "// Silence is golden\n";
        $index_content .= "// This directory contains AI-generated PDF files\n";
        $index_content .= "// Access is controlled by the AI API Connector plugin\n";

        @file_put_contents($pdf_dir . '/index.php', $index_content);
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
     * Get PDF attachment for email with validation
     */
    public function get_pdf_attachment($entry_id) {
        $pdf_path = get_post_meta($entry_id, '_sfaic_pdf_path', true);

        if (!empty($pdf_path) && file_exists($pdf_path) && is_readable($pdf_path)) {
            // Additional validation - check if it's actually a PDF
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            if ($file_info) {
                $mime_type = finfo_file($file_info, $pdf_path);
                finfo_close($file_info);

                if ($mime_type === 'application/pdf') {
                    return $pdf_path;
                }
            }

            // Fallback validation - check PDF header
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
     * Enhanced PDF library testing
     */
    public function test_mpdf_library() {
        if (!class_exists('Mpdf\Mpdf')) {
            return new WP_Error('mpdf_not_available', __('mPDF library is not installed or not accessible', 'chatgpt-fluent-connector'));
        }

        try {
            // Prepare environment
            $this->prepare_enhanced_pdf_environment();

            // Ensure temp directory
            $temp_dir = $this->ensure_temp_directory();
            if (!$temp_dir) {
                return new WP_Error('temp_dir_failed', __('Failed to create temporary directory', 'chatgpt-fluent-connector'));
            }

            // Create test PDF with comprehensive content
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'orientation' => 'P',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 15,
                'margin_bottom' => 15,
                'tempDir' => $temp_dir,
                'default_font' => 'dejavusans'
            ]);

            // Enhanced test content
            $test_html = $this->get_comprehensive_test_html();

            $mpdf->SetTitle('AI API Connector - Enhanced PDF Test');
            $mpdf->SetAuthor('AI API Connector Plugin');
            $mpdf->WriteHTML($test_html);

            $pdf_content = $mpdf->Output('', 'S');

            // Comprehensive validation
            $validation_result = $this->validate_test_pdf($pdf_content);

            if ($validation_result === true) {
                return array(
                    'service' => 'Enhanced Local mPDF',
                    'status' => 'success',
                    'message' => 'Enhanced mPDF library is working perfectly! All tests passed.',
                    'pdf_size' => strlen($pdf_content) . ' bytes',
                    'features' => array(
                        'emoji_support' => 'Enhanced with fallbacks',
                        'encoding_support' => 'Comprehensive UTF-8',
                        'template_support' => 'Advanced HTML templates',
                        'error_handling' => 'Robust with retry mechanisms',
                        'file_validation' => 'Complete PDF validation'
                    )
                );
            } else {
                return new WP_Error('test_validation_failed', $validation_result);
            }
        } catch (Exception $e) {
            return new WP_Error('mpdf_test_error', __('Enhanced mPDF test error: ', 'chatgpt-fluent-connector') . $e->getMessage());
        } finally {
            $this->cleanup_pdf_environment();
        }
    }

    /**
     * Get comprehensive test HTML
     */
    private function get_comprehensive_test_html() {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enhanced PDF Test</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; margin: 20px; line-height: 1.6; }
        .header { background: #667eea; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .test-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .emoji-test { font-size: 1.2em; }
        .encoding-test { background: #f0f0f0; padding: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚀 Enhanced PDF Generator Test</h1>
        <p>Comprehensive testing of AI API Connector PDF capabilities</p>
    </div>
    
    <div class="test-section">
        <h2>✅ Basic Functionality Test</h2>
        <p>This PDF was generated using the enhanced mPDF integration with improved error handling and validation.</p>
    </div>
    
    <div class="test-section">
        <h2>🎨 Emoji Support Test</h2>
        <div class="emoji-test">
            <p>Emojis with fallbacks: ' . $this->convert_emojis_to_images('🌟 ⭐ ✨ ❤️ 💙 💚 ✅ ❌ 🔴 🟢 🔵 💡 🌿') . '</p>
            <p>Status indicators: ' . $this->convert_emojis_to_images('✅ Success ❌ Error ⚠️ Warning ❓ Question') . '</p>
        </div>
    </div>
    
    <div class="test-section">
        <h2>🌐 Encoding Support Test</h2>
        <div class="encoding-test">
            <p><strong>UTF-8 Characters:</strong></p>
            <p>Latin: café, naïve, résumé, piñata</p>
            <p>Accented: àáâãäåæçèéêë</p>
            <p>Symbols: ©®™°±²³€£¥</p>
            <p>Math: ∞≠≤≥±∓√∑∏∫</p>
        </div>
    </div>
    
    <div class="test-section">
        <h2>📊 Table Rendering Test</h2>
        <table>
            <thead>
                <tr>
                    <th>Feature</th>
                    <th>Status</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>PDF Generation</td>
                    <td>' . $this->convert_emojis_to_images('✅') . ' Working</td>
                    <td>Complete PDF generation with validation</td>
                </tr>
                <tr>
                    <td>Emoji Support</td>
                    <td>' . $this->convert_emojis_to_images('✅') . ' Enhanced</td>
                    <td>Emoji conversion with comprehensive fallbacks</td>
                </tr>
                <tr>
                    <td>Error Handling</td>
                    <td>' . $this->convert_emojis_to_images('✅') . ' Robust</td>
                    <td>Multiple retry mechanisms and validation</td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="test-section">
        <h2>🎯 HTML Formatting Test</h2>
        <p>This section tests various HTML elements:</p>
        <ul>
            <li><strong>Bold text</strong> and <em>italic text</em></li>
            <li><u>Underlined text</u> and <del>strikethrough</del></li>
            <li>Links and <code>inline code</code></li>
        </ul>
        <blockquote>
            This is a blockquote to test block-level element rendering.
        </blockquote>
    </div>
    
    <div style="margin-top: 40px; border-top: 2px solid #667eea; padding-top: 20px; text-align: center;">
        <p><strong>Test completed successfully!</strong></p>
        <p>Generated by AI API Connector Enhanced PDF Generator</p>
        <p>Timestamp: ' . date('Y-m-d H:i:s') . '</p>
    </div>
</body>
</html>';
    }

    /**
     * Validate test PDF content
     */
    private function validate_test_pdf($pdf_content) {
        // Check minimum size
        if (strlen($pdf_content) < 10000) {
            return 'PDF content too small (less than 10KB)';
        }

        // Check PDF header
        if (substr($pdf_content, 0, 4) !== '%PDF') {
            return 'Invalid PDF header';
        }

        // Check for PDF trailer
        if (strpos($pdf_content, '%%EOF') === false) {
            return 'PDF trailer missing';
        }

        // Check for content streams
        if (strpos($pdf_content, '/Type /Page') === false) {
            return 'No pages found in PDF';
        }

        // Check for font embedding
        if (strpos($pdf_content, '/Type /Font') === false) {
            return 'No fonts found in PDF';
        }

        return true;
    }

    /**
     * Enhanced error handling for partial PDF generation issues
     */
    public function handle_pdf_generation_error($error, $context = array()) {
        $error_data = array(
            'error' => $error,
            'context' => $context,
            'timestamp' => current_time('mysql'),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version')
        );

        // Log detailed error information
        error_log('SFAIC PDF Generation Error: ' . json_encode($error_data, JSON_PRETTY_PRINT));

        // Store error for debugging
        update_option('sfaic_last_pdf_error', $error_data, false);

        return $error;
    }

    /**
     * Get last PDF generation error for debugging
     */
    public function get_last_pdf_error() {
        return get_option('sfaic_last_pdf_error', false);
    }

    /**
     * Clear PDF error log
     */
    public function clear_pdf_error_log() {
        delete_option('sfaic_last_pdf_error');
    }

    /**
     * Enhanced Twemoji conversion optimized for mPDF
     */
    private function convert_emojis_to_twemoji_for_mpdf($html) {
        // First fix encoding
        $html = $this->fix_encoding_comprehensive($html);

        // More comprehensive emoji pattern
        $emoji_patterns = [
            // Standard emoji ranges
            '/[\x{1F600}-\x{1F64F}]/u', // Emoticons
            '/[\x{1F300}-\x{1F5FF}]/u', // Symbols & Pictographs
            '/[\x{1F680}-\x{1F6FF}]/u', // Transport & Map
            '/[\x{1F700}-\x{1F77F}]/u', // Alchemical Symbols
            '/[\x{1F780}-\x{1F7FF}]/u', // Geometric Shapes Extended
            '/[\x{1F800}-\x{1F8FF}]/u', // Supplemental Arrows-C
            '/[\x{1F900}-\x{1F9FF}]/u', // Supplemental Symbols and Pictographs
            '/[\x{1FA00}-\x{1FA6F}]/u', // Chess Symbols
            '/[\x{1FA70}-\x{1FAFF}]/u', // Symbols and Pictographs Extended-A
            '/[\x{2600}-\x{26FF}]/u', // Miscellaneous Symbols
            '/[\x{2700}-\x{27BF}]/u', // Dingbats
            '/[\x{1F1E0}-\x{1F1FF}]/u', // Flags
            '/[\x{2300}-\x{23FF}]/u', // Miscellaneous Technical
            '/[\x{2460}-\x{24FF}]/u', // Enclosed Alphanumerics
            '/[\x{25A0}-\x{25FF}]/u', // Geometric Shapes
            '/[\x{2190}-\x{21FF}]/u', // Arrows
            '/[\x{1F000}-\x{1F02F}]/u', // Mahjong Tiles
            '/[\x{1F0A0}-\x{1F0FF}]/u', // Playing Cards
        ];

        foreach ($emoji_patterns as $pattern) {
            $html = preg_replace_callback(
                    $pattern,
                    array($this, 'emoji_to_twemoji_img'),
                    $html
            );
        }

        // Also handle emoji with modifiers (skin tones, etc.)
        $html = $this->handle_emoji_sequences($html);

        return $html;
    }

    /**
     * Convert single emoji to Twemoji image tag
     */
    private function emoji_to_twemoji_img($matches) {
        $emoji = $matches[0];

        // Get Twemoji URL
        $twemoji_url = $this->get_twemoji_svg_url($emoji);

        if ($twemoji_url) {
            // Try to get the SVG content for inline embedding
            $svg_content = $this->fetch_twemoji_svg($twemoji_url);

            if ($svg_content) {
                // Use inline SVG for better mPDF compatibility
                return $this->create_inline_svg_for_mpdf($svg_content);
            } else {
                // Fallback to base64 encoded PNG
                $png_url = $this->get_twemoji_png_url($emoji);
                $image_data = $this->fetch_and_encode_image($png_url);

                if ($image_data) {
                    return '<img src="' . $image_data . '" style="width: 1em; height: 1em; vertical-align: middle;" />';
                }
            }
        }

        // Ultimate fallback
        return $this->get_emoji_html_fallback($emoji);
    }

    /**
     * Get Twemoji SVG URL (better quality for PDFs)
     */
    private function get_twemoji_svg_url($emoji) {
        $codepoints = $this->emoji_to_codepoints_array($emoji);
        if (empty($codepoints)) {
            return false;
        }

        // Remove variation selectors (FE0F) for Twemoji URLs
        $codepoints = array_filter($codepoints, function ($cp) {
            return !in_array($cp, ['fe0f', 'fe0e']);
        });

        $filename = implode('-', $codepoints);
        return 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/svg/' . $filename . '.svg';
    }

    /**
     * Get Twemoji PNG URL as fallback
     */
    private function get_twemoji_png_url($emoji) {
        $codepoints = $this->emoji_to_codepoints_array($emoji);
        if (empty($codepoints)) {
            return false;
        }

        // Remove variation selectors
        $codepoints = array_filter($codepoints, function ($cp) {
            return !in_array($cp, ['fe0f', 'fe0e']);
        });

        $filename = implode('-', $codepoints);
        return 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/' . $filename . '.png';
    }

    /**
     * Convert emoji to array of codepoints
     */
    private function emoji_to_codepoints_array($emoji) {
        $codepoints = [];
        $emoji = mb_convert_encoding($emoji, 'UTF-32', 'UTF-8');
        $length = mb_strlen($emoji, 'UTF-32');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($emoji, $i, 1, 'UTF-32');
            $codepoint = unpack('N', $char)[1];

            // Skip zero-width joiners for URL construction
            if ($codepoint !== 0x200D) {
                $codepoints[] = sprintf('%04x', $codepoint);
            }
        }

        return $codepoints;
    }

    /**
     * Fetch Twemoji SVG content
     */
    private function fetch_twemoji_svg($url) {
        // Check cache first
        $cache_key = 'twemoji_svg_' . md5($url);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (mPDF Generator)'
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $svg_content = wp_remote_retrieve_body($response);

            // Cache for 30 days
            set_transient($cache_key, $svg_content, 30 * DAY_IN_SECONDS);

            return $svg_content;
        }

        return false;
    }

    /**
     * Create inline SVG for mPDF
     */
    private function create_inline_svg_for_mpdf($svg_content) {
        // Clean and optimize SVG for mPDF
        $svg_content = preg_replace('/<\?xml[^>]*\?>/', '', $svg_content);
        $svg_content = preg_replace('/<!DOCTYPE[^>]*>/', '', $svg_content);

        // Add width and height if not present
        if (strpos($svg_content, 'width=') === false) {
            $svg_content = str_replace('<svg', '<svg width="20" height="20"', $svg_content);
        }

        // Wrap in a span for proper inline display
        return '<span style="display: inline-block; width: 1em; height: 1em; vertical-align: middle;">' . $svg_content . '</span>';
    }

    /**
     * Fetch and encode image as base64
     */
    private function fetch_and_encode_image($url) {
        // Check cache
        $cache_key = 'twemoji_base64_' . md5($url);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'sslverify' => false
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $image_data = wp_remote_retrieve_body($response);
            $base64 = 'data:image/png;base64,' . base64_encode($image_data);

            // Cache for 30 days
            set_transient($cache_key, $base64, 30 * DAY_IN_SECONDS);

            return $base64;
        }

        return false;
    }

    /**
     * Handle complex emoji sequences (with ZWJ, skin tones, etc.)
     */
    private function handle_emoji_sequences($html) {
        // Pattern for emoji sequences with Zero Width Joiner
        $zwj_pattern = '/[\x{1F3FB}-\x{1F3FF}]|[\x{200D}]/u';

        // Handle family emojis, professions with gender/skin tone, etc.
        $complex_sequences = [
            // Man/Woman + ZWJ + profession
            '/[\x{1F468}\x{1F469}][\x{200D}][\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F900}-\x{1F9FF}]/u',
            // Flags (two regional indicator symbols)
            '/[\x{1F1E6}-\x{1F1FF}][\x{1F1E6}-\x{1F1FF}]/u',
            // Emoji with skin tone modifiers
            '/[\x{1F3FB}-\x{1F3FF}]/u',
        ];

        foreach ($complex_sequences as $pattern) {
            $html = preg_replace_callback(
                    $pattern,
                    array($this, 'emoji_to_twemoji_img'),
                    $html
            );
        }

        return $html;
    }

    /**
     * Get HTML fallback for emoji
     */
    private function get_emoji_html_fallback($emoji) {
        // Map common emojis to HTML entities or text
        $fallback_map = [
            '😀' => '☺',
            '😃' => '☺',
            '😄' => '☺',
            '😁' => '☺',
            '😊' => '☺',
            '🙂' => '☺',
            '😉' => ';)',
            '❤️' => '♥',
            '💙' => '♥',
            '💚' => '♥',
            '⭐' => '★',
            '✨' => '✦',
            '✅' => '✓',
            '❌' => '✗',
            '⚠️' => '!',
            '💡' => '(i)',
            '📧' => '@',
            '📱' => '[phone]',
            '🏠' => '[home]',
            '🔍' => '[search]',
        ];

        if (isset($fallback_map[$emoji])) {
            return '<span style="font-family: Arial, sans-serif;">' . $fallback_map[$emoji] . '</span>';
        }

        // For unknown emojis, return empty or placeholder
        return '<span style="color: #999;">[emoji]</span>';
    }
}
