<?php

/**
 * Direct Process Script for ChatGPT & Gemini Fluent Forms Connector
 * 
 * This is a completely standalone script to directly process form entries.
 * Just put this in your plugin's root directory and access it via browser.
 */
// Find the WordPress installation
function find_wordpress_root() {
    $dir = dirname(__FILE__);
    $max_iterations = 10; // prevent infinite loop
    $iterations = 0;

    while (!file_exists($dir . '/wp-config.php') && $iterations < $max_iterations) {
        $dir = dirname($dir);
        $iterations++;
    }

    if (file_exists($dir . '/wp-config.php')) {
        return $dir;
    }

    return false;
}

// Find WordPress and load it
$wp_root = find_wordpress_root();
if ($wp_root) {
    require_once($wp_root . '/wp-load.php');
} else {
    die("WordPress installation not found. Please manually specify the path to wp-load.php.");
}

// Security check
if (!current_user_can('manage_options')) {
    die("You don't have permission to access this page.");
}

/**
 * Process a form submission asynchronously
 * 
 * @param int $prompt_id The prompt ID
 * @param array $form_data The form data
 * @param int $entry_id The entry ID
 * @param int $form_id The form ID
 */
function process_entry($prompt_id, $entry_id, $form_id) {
    global $wpdb;

    echo "<h2>Starting to process entry {$entry_id} with prompt {$prompt_id}</h2>";

    // Enable debug mode temporarily
    update_option('cgptfc_debug_mode', '1');

    // Get the entry data
    if (function_exists('wpFluent')) {
        $entry = wpFluent()->table('fluentform_submissions')
                ->where('id', $entry_id)
                ->first();

        if ($entry && !empty($entry->response)) {
            $form_data = json_decode($entry->response, true);

            // Get form object
            $form = wpFluent()->table('fluentform_forms')
                    ->where('id', $form_id)
                    ->first();

            if ($form) {
                echo "<p>Found form and entry data. Starting AI processing...</p>";

                // Get the API instance
                $main = cgptfc_main();
                $api = $main->get_active_api();

                if (!$api) {
                    echo "<p style='color:red'>Error: API instance not available</p>";
                    return;
                }

                // Get prompt settings
                $system_prompt = get_post_meta($prompt_id, '_cgptfc_system_prompt', true);
                $user_prompt_template = get_post_meta($prompt_id, '_cgptfc_user_prompt_template', true);
                $prompt_type = get_post_meta($prompt_id, '_cgptfc_prompt_type', true);

                // Set default prompt type if not set
                if (empty($prompt_type)) {
                    $prompt_type = 'template';
                }

                // Prepare user prompt
                $user_prompt = '';

                // Create format_all_form_data function with proper field labels
                if ($prompt_type === 'all_form_data') {
                    $user_prompt = "Here is the submitted form data:\n\n";

                    // Get field labels - this is the key addition
                    $field_labels = get_form_field_labels($prompt_id, $form_id);

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

                        // Add to output with label instead of field key
                        $user_prompt .= $label . ': ' . $field_value . "\n";
                    }

                    $user_prompt .= "\nPlease analyze this information and provide a response. You can use HTML formatting in your response for better presentation.";
                } else {
                    // Use custom template
                    if (empty($user_prompt_template)) {
                        echo "<p style='color:red'>Error: No user prompt template configured</p>";
                        return;
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

                // Build complete prompt
                $complete_prompt = '';
                if (!empty($system_prompt)) {
                    $complete_prompt .= $system_prompt . "\n";
                }
                $complete_prompt .= $user_prompt;

                echo "<p>Generated prompt:</p>";
                echo "<pre style='background:#f0f0f0; padding:10px; border:1px solid #ccc; max-height:300px; overflow:auto;'>" . htmlspecialchars($complete_prompt) . "</pre>";

                // Process the form with the prompt
                echo "<p>Sending request to AI API...</p>";
                $ai_response = $api->process_form_with_prompt($prompt_id, $form_data);

                // Check if we got a valid response or an error
                if (is_wp_error($ai_response)) {
                    echo "<p style='color:red'>Error: " . $ai_response->get_error_message() . "</p>";
                    $status = 'error';
                    $error_message = $ai_response->get_error_message();
                    $response_content = '';
                } else {
                    echo "<p style='color:green'>Success! Received response from API.</p>";
                    echo "<div style='background:#f0f0f0; padding:20px; border:1px solid #ccc; margin:20px 0;'>";
                    echo "<h3>AI Response:</h3>";
                    echo $ai_response;
                    echo "</div>";

                    $status = 'success';
                    $error_message = '';
                    $response_content = $ai_response;
                }

                // Log the response
                if (isset($main->response_logger)) {
                    // Get provider and model
                    $provider = get_option('cgptfc_api_provider', 'openai');
                    $model = '';
                    switch ($provider) {
                        case 'gemini':
                            $model = get_option('cgptfc_gemini_model', 'gemini-2.5-pro-preview-05-06');
                            break;
                        case 'claude':
                            $model = get_option('cgptfc_claude_model', 'claude-opus-4-20250514');
                            break;
                        default:
                            $model = get_option('cgptfc_model', 'gpt-3.5-turbo');
                            break;
                    }

                    echo "<p>Logging response to database...</p>";

                    $result = $main->response_logger->log_response(
                            $prompt_id,
                            $entry_id,
                            $form_id,
                            $complete_prompt,
                            $response_content,
                            $provider,
                            $model,
                            0,
                            $status,
                            $error_message
                    );

                    if ($result) {
                        echo "<p style='color:green'>Response logged successfully, ID: {$result}</p>";
                    } else {
                        echo "<p style='color:red'>Failed to log response</p>";
                    }
                } else {
                    echo "<p style='color:red'>Response logger not available</p>";
                }

                echo "<p>Processing completed.</p>";
            } else {
                echo "<p style='color:red'>Form not found!</p>";
            }
        } else {
            echo "<p style='color:red'>Entry not found or has no data!</p>";
        }
    } else {
        echo "<p style='color:red'>wpFluent not available! This is required for database access.</p>";
    }

    // Reset debug mode
    update_option('cgptfc_debug_mode', '0');
}

/**
 * Get form field labels from a form ID
 * 
 * @param int $prompt_id The prompt ID
 * @param int $form_id The form ID
 * @return array Associative array of field keys and labels
 */
function get_form_field_labels($prompt_id, $form_id) {
    $field_labels = array();

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
                    
                    // Priority for getting the label:
                    // 1. First check settings->label (the admin-defined label)
                    // 2. Then try settings->admin_field_label if available
                    // 3. Finally fall back to the field name as last resort
                    
                    if (!empty($field['settings']['label'])) {
                        $field_label = $field['settings']['label'];
                    } elseif (!empty($field['settings']['admin_field_label'])) {
                        $field_label = $field['settings']['admin_field_label'];
                    } else {
                        $field_label = $field_name;
                    }
                    
                    $field_labels[$field_name] = $field_label;
                }
            }
        }
    }

    return $field_labels;
}

// Check if we're processing an entry
if (isset($_GET['process']) && $_GET['process'] === 'entry') {
    $prompt_id = isset($_GET['prompt_id']) ? intval($_GET['prompt_id']) : 0;
    $entry_id = isset($_GET['entry_id']) ? intval($_GET['entry_id']) : 0;
    $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

    if ($prompt_id && $entry_id && $form_id) {
        process_entry($prompt_id, $entry_id, $form_id);
        echo "<hr>";
        echo "<p><a href='?'>Back to form</a></p>";
        die();
    }
}

// Process form submissions
if (isset($_POST['bulk_process'])) {
    $prompt_id = isset($_POST['prompt_id']) ? intval($_POST['prompt_id']) : 0;
    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    $count = isset($_POST['entry_count']) ? intval($_POST['entry_count']) : 5;

    if ($prompt_id && $form_id) {
        // Get recent entries
        if (function_exists('wpFluent')) {
            $entries = wpFluent()->table('fluentform_submissions')
                    ->where('form_id', $form_id)
                    ->orderBy('id', 'DESC')
                    ->limit($count)
                    ->get();

            echo "<h2>Processing {$count} recent entries from form {$form_id} with prompt {$prompt_id}</h2>";

            if (!empty($entries)) {
                foreach ($entries as $entry) {
                    echo "<h3>Processing entry {$entry->id}</h3>";
                    process_entry($prompt_id, $entry->id, $form_id);
                    echo "<hr>";
                }
            } else {
                echo "<p>No entries found for the selected form.</p>";
            }
        } else {
            echo "<p style='color:red'>wpFluent not available!</p>";
        }
    }
}

// Get prompts for form selection
$prompts = get_posts(array(
    'post_type' => 'cgptfc_prompt',
    'posts_per_page' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
        ));

// Get forms for selection
$forms = array();
if (function_exists('wpFluent')) {
    $forms = wpFluent()->table('fluentform_forms')
            ->select(array('id', 'title'))
            ->orderBy('id', 'DESC')
            ->get();
}

// Get recent entries
$recent_entries = array();
if (function_exists('wpFluent')) {
    $recent_entries = wpFluent()->table('fluentform_submissions')
            ->select(array('id', 'form_id', 'created_at'))
            ->orderBy('id', 'DESC')
            ->limit(20)
            ->get();
}

// HTML Output
?>
<!DOCTYPE html>
<html>
    <head>
        <title>ChatGPT & Gemini Fluent Forms Connector - Direct Process Tool</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                padding: 20px;
                max-width: 1200px;
                margin: 0 auto;
            }
            h1, h2, h3 {
                color: #333;
            }
            .box {
                background: #fff;
                border: 1px solid #ddd;
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 5px;
            }
            .header {
                background: #f5f5f5;
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 5px;
            }
            .form-group {
                margin-bottom: 15px;
            }
            label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            select, input {
                padding: 8px;
                width: 100%;
                box-sizing: border-box;
                margin-bottom: 10px;
            }
            button, .button {
                background: #0073aa;
                color: white;
                border: none;
                padding: 10px 15px;
                cursor: pointer;
                border-radius: 5px;
            }
            button:hover, .button:hover {
                background: #005b87;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            table th, table td {
                padding: 8px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            table th {
                background: #f5f5f5;
            }
            .tabs {
                display: flex;
                margin-bottom: 20px;
            }
            .tab {
                padding: 10px 20px;
                background: #f0f0f0;
                cursor: pointer;
                margin-right: 5px;
                border-radius: 5px 5px 0 0;
            }
            .tab.active {
                background: #fff;
                border: 1px solid #ddd;
                border-bottom: none;
            }
            .tab-content {
                display: none;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 0 5px 5px 5px;
            }
            .tab-content.active {
                display: block;
            }
            .footer {
                text-align: center;
                padding: 20px;
                margin-top: 30px;
                font-size: 12px;
                color: #777;
            }
            .system-info {
                margin-bottom: 20px;
            }
            .system-info table {
                width: 100%;
            }
            .system-info th {
                width: 200px;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>ChatGPT & Gemini Fluent Forms Connector - Direct Process Tool</h1>
            <p>This tool allows you to directly process form entries with the AI API, without using the plugin's integration code.</p>
        </div>

        <div class="tabs">
            <div class="tab active" data-tab="single-process">Single Entry Processing</div>
            <div class="tab" data-tab="bulk-process">Bulk Processing</div>
            <div class="tab" data-tab="recent-entries">Recent Entries</div>
            <div class="tab" data-tab="system-info">System Info</div>
        </div>

        <div id="single-process" class="tab-content active box">
            <h2>Process Single Entry</h2>
            <p>Select a prompt, form, and entry ID to process a single form submission.</p>

            <form method="get" action="">
                <input type="hidden" name="process" value="entry">

                <div class="form-group">
                    <label for="prompt_id">Select Prompt:</label>
                    <select name="prompt_id" id="prompt_id" required>
                        <option value="">-- Select a prompt --</option>
                        <?php foreach ($prompts as $prompt) : ?>
                            <option value="<?php echo $prompt->ID; ?>"><?php echo $prompt->post_title; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="form_id">Select Form:</label>
                    <select name="form_id" id="form_id" required>
                        <option value="">-- Select a form --</option>
                        <?php foreach ($forms as $form) : ?>
                            <option value="<?php echo $form->id; ?>"><?php echo $form->title; ?> (ID: <?php echo $form->id; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="entry_id">Entry ID:</label>
                    <input type="number" name="entry_id" id="entry_id" required>
                    <small>Enter the form submission entry ID.</small>
                </div>

                <button type="submit">Process Entry</button>
            </form>
        </div>

        <div id="bulk-process" class="tab-content box">
            <h2>Bulk Process Recent Entries</h2>
            <p>Process multiple recent entries from a specific form in bulk.</p>

            <form method="post" action="">
                <div class="form-group">
                    <label for="bulk_prompt_id">Select Prompt:</label>
                    <select name="prompt_id" id="bulk_prompt_id" required>
                        <option value="">-- Select a prompt --</option>
                        <?php foreach ($prompts as $prompt) : ?>
                            <option value="<?php echo $prompt->ID; ?>"><?php echo $prompt->post_title; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="bulk_form_id">Select Form:</label>
                    <select name="form_id" id="bulk_form_id" required>
                        <option value="">-- Select a form --</option>
                        <?php foreach ($forms as $form) : ?>
                            <option value="<?php echo $form->id; ?>"><?php echo $form->title; ?> (ID: <?php echo $form->id; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="entry_count">Number of Recent Entries to Process:</label>
                    <input type="number" name="entry_count" id="entry_count" value="5" min="1" max="50" required>
                    <small>The most recent entries will be processed first.</small>
                </div>

                <button type="submit" name="bulk_process" value="1">Process Entries</button>
            </form>
        </div>

        <div id="recent-entries" class="tab-content box">
            <h2>Recent Form Entries</h2>
            <p>Here are the 20 most recent form submissions across all forms. Click on "Process" to process an entry.</p>

            <table>
                <thead>
                    <tr>
                        <th>Entry ID</th>
                        <th>Form ID</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_entries)) : ?>
                        <tr>
                            <td colspan="4">No recent entries found.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($recent_entries as $entry) : ?>
                            <tr>
                                <td><?php echo $entry->id; ?></td>
                                <td><?php echo $entry->form_id; ?></td>
                                <td><?php echo $entry->created_at; ?></td>
                                <td>
                                    <?php if (!empty($prompts)) : ?>
                                        <a href="?process=entry&prompt_id=<?php echo $prompts[0]->ID; ?>&form_id=<?php echo $entry->form_id; ?>&entry_id=<?php echo $entry->id; ?>" class="button">Process Entry</a>
                                    <?php else : ?>
                                        <span>No prompts available</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="system-info" class="tab-content box">
            <h2>System Information</h2>

            <div class="system-info">
                <table>
                    <tr>
                        <th>Plugin Version</th>
                        <td><?php echo defined('CGPTFC_VERSION') ? CGPTFC_VERSION : 'Unknown'; ?></td>
                    </tr>
                    <tr>
                        <th>WordPress Version</th>
                        <td><?php echo get_bloginfo('version'); ?></td>
                    </tr>
                    <tr>
                        <th>PHP Version</th>
                        <td><?php echo phpversion(); ?></td>
                    </tr>
                    <tr>
                        <th>Active Provider</th>
                        <td><?php echo get_option('cgptfc_api_provider', 'openai'); ?></td>
                    </tr>
                    <tr>
                        <th>Debug Mode</th>
                        <td><?php echo get_option('cgptfc_debug_mode', '0') === '1' ? 'Enabled' : 'Disabled'; ?></td>
                    </tr>
                    <tr>
                        <th>Database Table Status</th>
                        <td>
                            <?php
                            global $wpdb;
                            $table_name = $wpdb->prefix . 'cgptfc_response_logs';
                            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
                            $log_count = 0;

                            if ($table_exists) {
                                $log_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
                                echo "Exists ({$log_count} entries)";
                            } else {
                                echo "Missing";
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Forms Count</th>
                        <td><?php echo count($forms); ?></td>
                    </tr>
                    <tr>
                        <th>Prompts Count</th>
                        <td><?php echo count($prompts); ?></td>
                    </tr>
                </table>
            </div>

            <h3>Debug Tools</h3>
            <p>Use these tools with caution:</p>

            <div class="form-group">
                <a href="?debug_action=enable_debug" class="button">Enable Debug Mode</a>
                <a href="?debug_action=disable_debug" class="button">Disable Debug Mode</a>
            </div>
        </div>

        <div class="footer">
            <p>Direct Process Tool for ChatGPT & Gemini Fluent Forms Connector plugin.</p>
        </div>

        <script>
            // Simple tab handling
            document.addEventListener('DOMContentLoaded', function () {
                var tabs = document.querySelectorAll('.tab');

                tabs.forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        // Hide all tab contents
                        document.querySelectorAll('.tab-content').forEach(function (content) {
                            content.classList.remove('active');
                        });

                        // Deactivate all tabs
                        document.querySelectorAll('.tab').forEach(function (t) {
                            t.classList.remove('active');
                        });

                        // Activate the clicked tab and its content
                        this.classList.add('active');
                        document.getElementById(this.getAttribute('data-tab')).classList.add('active');
                    });
                });
            });
        </script>
    </body>
</html>
<?php
// Handle debug actions
if (isset($_GET['debug_action'])) {
    $action = $_GET['debug_action'];

    if ($action === 'enable_debug') {
        update_option('cgptfc_debug_mode', '1');
        echo "<script>alert('Debug mode enabled. Reloading page...'); window.location.href = '?tab=system-info';</script>";
    } elseif ($action === 'disable_debug') {
        update_option('cgptfc_debug_mode', '0');
        echo "<script>alert('Debug mode disabled. Reloading page...'); window.location.href = '?tab=system-info';</script>";
    }
}