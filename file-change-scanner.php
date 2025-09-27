<?php
/**
 * Plugin Name:       File Change Scanner
 * Description:       An automated tool to detect file changes with Telegram alert capabilities.
 * Version:           1.2
 * Author:            iraj zahedi
 * License:           GPL v2 or later
 * Text Domain:       file-change-scanner
 * Author URI:        https://blueserver.ir
 */

if (!defined('ABSPATH')) exit;

// --- Cron Job Management ---
register_deactivation_hook(__FILE__, 'fcs_clear_cronjobs');
function fcs_clear_cronjobs() { wp_clear_scheduled_hook('fcs_scan_hook'); }
add_action('fcs_scan_hook', 'fcs_perform_scheduled_scan');

// 1. Add Menus and Assets
add_action('admin_menu', 'fcs_add_admin_menu');
function fcs_add_admin_menu() {
    add_menu_page('File Scanner', 'File Scanner', 'manage_options', 'file-change-scanner', 'fcs_scanner_page_html', 'dashicons-shield-alt', 80);
    add_submenu_page('file-change-scanner', 'Settings', 'Settings', 'manage_options', 'fcs-settings', 'fcs_settings_page_html');
}

add_action('admin_enqueue_scripts', 'fcs_enqueue_admin_assets');
function fcs_enqueue_admin_assets($hook) {
    if (strpos($hook, 'fcs-') === false && strpos($hook, 'file-change-scanner') === false) return;
    wp_enqueue_style('fcs-admin-style', plugin_dir_url(__FILE__) . 'admin-style.css', [], '1.2');
    if (strpos($hook, 'fcs-settings') !== false) {
        wp_enqueue_script('fcs-admin-js', plugin_dir_url(__FILE__) . 'admin-script.js', ['jquery'], '1.2', true);
        wp_localize_script('fcs-admin-js', 'fcs_ajax', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('fcs_security_nonce')]);
    }
}

// 2. Main Plugin Page
function fcs_scanner_page_html() {
    $options = get_option('fcs_settings');
    $cron_schedule = $options['cron_schedule'] ?? 'disabled';
    ?>
    <div class="wrap fcs-dashboard-wrap">
        <h1>File Change Scanner</h1>
        <p>Detect recently modified files on your website.</p>
        <div class="fcs-card">
            <form method="post" action="">
                <?php wp_nonce_field('fcs_run_scan_action', 'fcs_nonce'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="time_frame">Scan Timeframe:</label></th>
                        <td>
                            <select name="time_frame" id="time_frame">
                                <option value="24">Last 24 Hours</option>
                                <option value="168" selected>Last 7 Days</option>
                                <option value="720">Last 30 Days</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p class="submit" style="padding: 0 20px 10px;"> <input type="submit" name="start_scan" class="button button-primary" value="Start Manual Scan"> </p>
            </form>
        </div>
        <div class="fcs-card" style="margin-top: 20px;">
            <h3>Automated Scan Status</h3>
            <?php if ($cron_schedule !== 'disabled' && !empty($options['enable_telegram'])) : ?>
                <p style="color: green;">✔ Automated scan and Telegram notifications are active (Frequency: <?php echo esc_html(wp_get_schedules()[$cron_schedule]['display']); ?>).</p>
            <?php elseif ($cron_schedule !== 'disabled') : ?>
                <p style="color: orange;">⚠ Automated scan is active, but to receive alerts, please <a href="<?php echo admin_url('admin.php?page=fcs-settings'); ?>">enable Telegram notifications</a> and complete the setup.</p>
            <?php else: ?>
                 <p style="color: red;">❌ Automated scan is disabled. Go to the <a href="<?php echo admin_url('admin.php?page=fcs-settings'); ?>">Settings page</a> to enable it.</p>
            <?php endif; ?>
        </div>
        <div class="fcs-results-area">
            <?php
            if (isset($_POST['start_scan']) && isset($_POST['fcs_nonce']) && wp_verify_nonce($_POST['fcs_nonce'], 'fcs_run_scan_action')) {
                $time_frame = intval($_POST['time_frame']);
                $results = fcs_run_scan($time_frame);
                fcs_display_results_in_card('List of files modified in the last ' . $time_frame . ' hours', $results['modified']);
            }
            ?>
        </div>
    </div>
    <?php
}

// 3. Settings Page
function fcs_settings_page_html() { ?>
    <div class="wrap">
        <h1>Settings</h1>
        <form action="options.php" method="post">
            <?php settings_fields('fcs_settings_group'); do_settings_sections('fcs-settings'); submit_button('Save Settings'); ?>
        </form>
    </div>
<?php }

// 4. Register Settings & Exclusion File Handling
add_action('admin_init', 'fcs_register_settings');
function fcs_register_settings() {
    register_setting('fcs_settings_group', 'fcs_settings', 'fcs_sanitize_and_manage_cron');
    
    // Scan Settings Section
    add_settings_section('fcs_scan_settings_section', 'Scan Settings', null, 'fcs-settings');
    add_settings_field('fcs_exclusions_list', 'Exclusion List (Paths to Ignore)', 'fcs_exclusions_list_callback', 'fcs-settings', 'fcs_scan_settings_section');

    // Automated Scan Section
    add_settings_section('fcs_cron_section', 'Automated Scan Settings', null, 'fcs-settings');
    add_settings_field('fcs_cron_schedule', 'Scan Frequency', 'fcs_cron_schedule_callback', 'fcs-settings', 'fcs_cron_section');
    
    // Telegram Section
    add_settings_section('fcs_telegram_section', 'Telegram Alert Settings', null, 'fcs-settings');
    add_settings_field('fcs_enable_telegram', 'Enable Telegram Alerts', 'fcs_enable_telegram_callback', 'fcs-settings', 'fcs_telegram_section');
    add_settings_field('fcs_bot_token', 'Bot Token', 'fcs_bot_token_callback', 'fcs-settings', 'fcs_telegram_section');
    add_settings_field('fcs_chat_id', 'Chat ID', 'fcs_chat_id_callback', 'fcs-settings', 'fcs_telegram_section');
    add_settings_field('fcs_test_actions', 'Test Message', 'fcs_test_actions_callback', 'fcs-settings', 'fcs_telegram_section');
}

// Save exclusions to file when settings are saved
add_action('admin_init', 'fcs_save_exclusions_to_file');
function fcs_save_exclusions_to_file() {
    if (isset($_POST['option_page']) && $_POST['option_page'] == 'fcs_settings_group' && current_user_can('manage_options')) {
        if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'fcs_settings_group-options')) {
            $file_path = fcs_get_exclusions_file_path();
            $exclusions = '';
            if (isset($_POST['fcs_settings']['exclusions_list'])) {
                $exclusions = sanitize_textarea_field($_POST['fcs_settings']['exclusions_list']);
            }
            @file_put_contents($file_path, $exclusions);
        }
    }
}

function fcs_sanitize_and_manage_cron($input) {
    $new_options = [];
    $new_schedule = $input['cron_schedule'] ?? 'disabled';
    wp_clear_scheduled_hook('fcs_scan_hook');
    if ($new_schedule !== 'disabled') { wp_schedule_event(time(), $new_schedule, 'fcs_scan_hook'); }
    $new_options['cron_schedule'] = $new_schedule;
    $new_options['enable_telegram'] = !empty($input['enable_telegram']);
    $new_options['bot_token'] = sanitize_text_field($input['bot_token'] ?? '');
    $new_options['chat_id'] = sanitize_text_field($input['chat_id'] ?? '');
    return $new_options;
}

// 5. Settings Fields Callbacks & File Helpers
function fcs_get_exclusions_file_path() {
    $upload_dir = wp_upload_dir();
    $fcs_dir = $upload_dir['basedir'] . '/file-change-scanner';
    if (!file_exists($fcs_dir)) { wp_mkdir_p($fcs_dir); }
    if (!file_exists($fcs_dir . '/index.php')) { @file_put_contents($fcs_dir . '/index.php', '<?php // Silence is golden.'); }
    return $fcs_dir . '/exclusions.txt';
}

function fcs_exclusions_list_callback() {
    $file_path = fcs_get_exclusions_file_path();
    $exclusions = file_exists($file_path) ? esc_textarea(file_get_contents($file_path)) : '';
    echo '<textarea name="fcs_settings[exclusions_list]" rows="8" class="large-text code">' . $exclusions . '</textarea>';
    echo '<p class="description">Enter full paths to directories or files you want to exclude, one per line. Example: <code>' . WP_PLUGIN_DIR . '/another-plugin</code></p>';
}

function fcs_cron_schedule_callback() { $options = get_option('fcs_settings'); $current_schedule = $options['cron_schedule'] ?? 'disabled'; $schedules = ['disabled' => 'Disabled', 'hourly' => 'Hourly', 'twicedaily' => 'Twice Daily', 'daily' => 'Daily']; echo '<select name="fcs_settings[cron_schedule]">'; foreach ($schedules as $value => $label) { echo '<option value="' . esc_attr($value) . '" ' . selected($current_schedule, $value, false) . '>' . esc_html($label) . '</option>'; } echo '</select>'; }
function fcs_enable_telegram_callback() { $options = get_option('fcs_settings'); echo '<label><input type="checkbox" name="fcs_settings[enable_telegram]" value="1" ' . checked(1, !empty($options['enable_telegram']), false) . '> Send automated scan alerts to Telegram</label>'; }
function fcs_bot_token_callback() { $options = get_option('fcs_settings'); echo '<input type="text" class="regular-text" name="fcs_settings[bot_token]" value="' . esc_attr($options['bot_token'] ?? '') . '" size="50">'; }
function fcs_chat_id_callback() { $options = get_option('fcs_settings'); echo '<input type="text" class="regular-text" name="fcs_settings[chat_id]" value="' . esc_attr($options['chat_id'] ?? '') . '" size="50">'; }
function fcs_test_actions_callback() { echo '<div class="fcs-test-button-wrapper"><button type="button" class="button" id="fcs-send-test-telegram">Send Test Message</button></div><span id="fcs-test-telegram-result"></span>'; }

// 6. Main Scan Function
function fcs_run_scan($time_frame_hours) {
    $modified_files = fcs_scan_for_modified_files(ABSPATH, $time_frame_hours * 3600);
    return ['modified' => $modified_files];
}

// Cron Job Function
function fcs_perform_scheduled_scan() {
    $options = get_option('fcs_settings');
    if (empty($options['enable_telegram'])) return;
    $schedule_map = ['hourly' => 1, 'twicedaily' => 12, 'daily' => 24];
    $time_frame = $schedule_map[$options['cron_schedule']] ?? 24;
    $results = fcs_run_scan($time_frame);
    if (!empty($results['modified'])) {
        fcs_send_telegram_notification($results['modified'], false);
    }
}

// 7. Scan Function (Updated with File-based Exclusions)
function fcs_scan_for_modified_files($dir, $time_limit) {
    $results = [];

    // Default hardcoded exclusions
    $default_excluded_dirs = [
        WP_CONTENT_DIR . '/cache',
        WP_CONTENT_DIR . '/upgrade',
    ];

    // Read user-defined exclusions from file
    $user_excluded_paths = [];
    $exclusions_file = fcs_get_exclusions_file_path();
    if (file_exists($exclusions_file)) {
        $raw_paths = file_get_contents($exclusions_file);
        $user_excluded_paths = array_filter(array_map('trim', explode("\n", $raw_paths)));
    }

    // Merge default and user exclusions
    $excluded_paths = array_merge($default_excluded_dirs, $user_excluded_paths);
    $excluded_paths_normalized = array_map(function($path) { return str_replace('\\', '/', $path); }, $excluded_paths);
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS)
        );
        $current_time = time();

        foreach ($iterator as $file) {
            $current_path = str_replace('\\', '/', $file->getPathname());
            if ($current_path === str_replace('\\', '/', __FILE__)) continue;

            $is_excluded = false;
            foreach ($excluded_paths_normalized as $excluded_path) {
                if (empty($excluded_path)) continue;
                if (strpos($current_path, $excluded_path) === 0) {
                    $is_excluded = true;
                    break;
                }
            }
            if ($is_excluded) continue;

            if ($file->isFile() && ($current_time - $file->getMTime() < $time_limit)) {
                $results[] = ['path' => $file->getPathname(), 'modified' => $file->getMTime()];
            }
        }
    } catch (Exception $e) {
        $results[] = ['error' => 'Scan Error: ' . $e->getMessage()];
    }

    usort($results, function($a, $b) { return ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0); });
    return $results;
}

// 8. Send Telegram Notification Function
function fcs_send_telegram_notification($modified_files, $is_test = false) {
    $options = get_option('fcs_settings');
    $token = $options['bot_token'] ?? '';
    $chat_id = $options['chat_id'] ?? '';
    if (empty($token) || empty($chat_id)) return ['success' => false, 'message' => 'Bot Token or Chat ID is not set.'];
    
    $site_name = get_bloginfo('name');
    if (!defined('FCS_MAX_FILES_IN_MESSAGE')) { define('FCS_MAX_FILES_IN_MESSAGE', 30); }

    if ($is_test) {
        $message = "✅ This is a test message from the File Change Scanner on *" . esc_html($site_name) . "*! Settings are correct!";
    } else {
        $message = "📝 *File Change Alert for site: " . esc_html($site_name) . "* 📝\n\n";
        if (!empty($modified_files)) {
            $message .= "*Modified Files:*\n";
            $files_to_list = array_slice($modified_files, 0, FCS_MAX_FILES_IN_MESSAGE);
            foreach ($files_to_list as $file) { $message .= "• `" . esc_html($file['path']) . "`\n\n"; }
            if (count($modified_files) > count($files_to_list)) { $message .= "... and " . (count($modified_files) - count($files_to_list)) . " other files were modified.\n\n"; }
        }
        $message .= "Please visit your site to see the full list and investigate.";
    }

    $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/sendMessage', ['body' => ['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'Markdown']]);
    if (is_wp_error($response)) return ['success' => false, 'message' => $response->get_error_message()];
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($body['ok']) return ['success' => true];
    return ['success' => false, 'message' => $body['description']];
}

// 9. AJAX Handlers
add_action('wp_ajax_fcs_send_test_message', 'fcs_test_message_ajax_handler');
function fcs_test_message_ajax_handler() {
    check_ajax_referer('fcs_security_nonce'); if (!current_user_can('manage_options')) wp_send_json_error('Access Denied.');
    $result = fcs_send_telegram_notification([], true);
    if ($result['success']) wp_send_json_success('Message sent successfully!'); else wp_send_json_error($result['message'] ?? 'Unknown error.');
}

// 10. Display Function
function fcs_display_results_in_card($title, $data) { echo "<h2>" . esc_html($title) . "</h2>"; if (empty($data)) { echo "<div class='fcs-no-results'><p>No new or modified files were found in the selected timeframe.</p><span class='dashicons dashicons-yes-alt'></span></div>"; return; } echo '<div class="fcs-results-card"><table><thead><tr><th>Last Modified</th><th>File Path</th></tr></thead><tbody>'; foreach ($data as $item) { if (isset($item['error'])) { echo '<tr><td colspan="2" style="color:red;">' . esc_html($item['error']) . '</td></tr>'; continue; } echo '<tr><td>' . wp_date('Y-m-d H:i:s', $item['modified']) . '</td><td><code>' . esc_html($item['path']) . '</code></td></tr>'; } echo '</tbody></table></div>'; }