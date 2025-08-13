<?php
/**
 * Plugin Name: Smart Search GraphQL Chatbot
 * Description: A chatbot that uses Smart Search AI Vector DB (GraphQL) for context and OpenAI API for LLM responses.
 * Version: 1.1
 * Author: Brandon T.
 */

if (!defined('ABSPATH')) exit;

// Include chat logs class
require_once plugin_dir_path(__FILE__) . 'chat-logs/chat-logs.php';
global $ssc_chat_logs;
$ssc_chat_logs = new SSC_Chat_Logs();

/**
 * Register settings
 */
function ssgc_register_settings() {
    add_option('ssgc_smart_search_url', '');
    add_option('ssgc_smart_search_token', '');
    add_option('ssgc_openai_api_key', '');

    register_setting('ssgc_settings_group', 'ssgc_smart_search_url');

    // Prevent overwriting tokens with placeholder
    register_setting('ssgc_settings_group', 'ssgc_smart_search_token', [
        'sanitize_callback' => function($value) {
            return (!empty($value) && $value !== '********')
                ? sanitize_text_field($value)
                : get_option('ssgc_smart_search_token');
        }
    ]);

    register_setting('ssgc_settings_group', 'ssgc_openai_api_key', [
        'sanitize_callback' => function($value) {
            return (!empty($value) && $value !== '********')
                ? sanitize_text_field($value)
                : get_option('ssgc_openai_api_key');
        }
    ]);
}
add_action('admin_init', 'ssgc_register_settings');

/**
 * Add settings page
 */
function ssgc_register_options_page() {
    add_options_page(
        'Smart Search Chatbot Settings',
        'Smart Search Chatbot',
        'manage_options',
        'ssgc-settings',
        'ssgc_options_page_html'
    );
}
add_action('admin_menu', 'ssgc_register_options_page');

/**
 * Render settings page HTML
 */
function ssgc_options_page_html() {
    ?>
    <div class="wrap">
        <h1>Smart Search Chatbot Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('ssgc_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Smart Search GraphQL URL</th>
                    <td>
                        <input type="text" name="ssgc_smart_search_url"
                               value="<?php echo esc_attr(get_option('ssgc_smart_search_url')); ?>"
                               style="width: 100%;">
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Smart Search Access Token</th>
                    <td style="display:flex; gap:5px; align-items:center;">
                        <input type="password" id="ssgc_smart_search_token"
                               name="ssgc_smart_search_token"
                               placeholder="<?php echo get_option('ssgc_smart_search_token') ? '********' : ''; ?>"
                               style="flex:1;">
                        <button type="button" class="button" data-target="ssgc_smart_search_token">Show</button>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">OpenAI API Key</th>
                    <td style="display:flex; gap:5px; align-items:center;">
                        <input type="password" id="ssgc_openai_api_key"
                               name="ssgc_openai_api_key"
                               placeholder="<?php echo get_option('ssgc_openai_api_key') ? '********' : ''; ?>"
                               style="flex:1;">
                        <button type="button" class="button" data-target="ssgc_openai_api_key">Show</button>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Enqueue assets
 */
function ssgc_enqueue_assets($hook) {
    // Enqueue frontend assets
    if (!is_admin()) {
        wp_enqueue_script('jquery');
        wp_enqueue_script('ssgc-chatbot', plugin_dir_url(__FILE__) . 'chatbot.js', ['jquery'], '1.1', true);
        wp_localize_script('ssgc-chatbot', 'ssgc_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ssgc_chat_nonce')
        ]);
        wp_enqueue_style('ssgc-chatbot-style', plugin_dir_url(__FILE__) . 'chatbot.css');
    }

    // Enqueue admin assets
    if ('settings_page_ssgc-settings' === $hook) {
        wp_enqueue_script('ssgc-admin', plugin_dir_url(__FILE__) . 'admin.js', [], '1.0', true);
    }
}
add_action('wp_enqueue_scripts', 'ssgc_enqueue_assets');
add_action('admin_enqueue_scripts', 'ssgc_enqueue_assets');

/**
 * Shortcode to display chatbot
 */
function ssgc_display_chatbot() {
    ob_start();
    ?>
    <div id="ssgc-chatbot">
        <div id="ssgc-chat-log" style="border:1px solid #ccc;height:300px;overflow:auto;padding:5px;margin-bottom:10px;"></div>
        <input type="text" id="ssgc-chat-input" placeholder="Ask me something..." style="width:80%;">
        <button id="ssgc-chat-send">Send</button>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('smart_search_chatbot', 'ssgc_display_chatbot');

/**
 * Queries the Smart Search GraphQL API.
 */
function ssgc_query_smart_search($message, $url, $token) {
    $query = <<<GRAPHQL
query GetContext(\$message: String!, \$field: String!) {
  similarity(input: { nearest: { text: \$message, field: \$field }}) {
    docs { data }
  }
}
GRAPHQL;
    $variables = ["message" => $message, "field" => "post_content"];
    $body = json_encode(['query' => $query, 'variables' => $variables]);

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token
        ],
        'body' => $body
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('smart_search_error', 'Error contacting Smart Search service.');
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return $data['data']['similarity']['docs'] ?? [];
}

/**
 * Queries the OpenAI API.
 */
function ssgc_query_openai($context, $message, $api_key) {
    $prompt = "Use the following context to answer the question.\n\nContext:\n$context\n\nQuestion: $message\nAnswer:";
    $body = json_encode([
        'model'    => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.7
    ]);

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => $body
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('openai_error', 'Error contacting OpenAI API.');
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return $data['choices'][0]['message']['content'] ?? "No answer generated.";
}

/**
 * Handle chatbot AJAX request
 */
function ssgc_handle_chat_request() {
    check_ajax_referer('ssgc_chat_nonce', 'nonce');

    global $ssc_chat_logs;
    $message = sanitize_text_field($_POST['message']);

    // Fetch API credentials
    $smart_search_url   = get_option('ssgc_smart_search_url');
    $smart_search_token = get_option('ssgc_smart_search_token');
    $openai_api_key     = get_option('ssgc_openai_api_key');

    // Get context from Smart Search
    $docs = ssgc_query_smart_search($message, $smart_search_url, $smart_search_token);
    if (is_wp_error($docs)) {
        wp_send_json_error(['reply' => $docs->get_error_message()]);
    }
    if (empty($docs)) {
        wp_send_json_success(['reply' => "Sorry, I couldn't find an answer."]);
    }

    $context = implode("\n\n", array_map(function($doc) {
        return $doc['data']['post_title'] . ": " . wp_strip_all_tags($doc['data']['post_content']);
    }, $docs));

    // Get reply from OpenAI
    $reply = ssgc_query_openai($context, $message, $openai_api_key);
    if (is_wp_error($reply)) {
        wp_send_json_error(['reply' => $reply->get_error_message()]);
    }

    // Log and send reply
    if (isset($ssc_chat_logs)) {
        $ssc_chat_logs->log_chat($message, $reply);
    }
    wp_send_json_success(['reply' => $reply]);
}
add_action('wp_ajax_ssgc_chat', 'ssgc_handle_chat_request');
add_action('wp_ajax_nopriv_ssgc_chat', 'ssgc_handle_chat_request');
