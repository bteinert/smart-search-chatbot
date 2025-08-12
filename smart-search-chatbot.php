<?php
/**
 * Plugin Name: Smart Search GraphQL Chatbot
 * Description: A chatbot that uses Smart Search AI Vector DB (GraphQL) for context and OpenAI API for LLM responses.
 * Version: 1.0
 * Author: Your Name
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register settings
 */
function ssgc_register_settings() {
    add_option('ssgc_smart_search_url', '');
    add_option('ssgc_smart_search_token', '');
    add_option('ssgc_openai_api_key', '');

    register_setting('ssgc_settings_group', 'ssgc_smart_search_url');
    register_setting('ssgc_settings_group', 'ssgc_smart_search_token');
    register_setting('ssgc_settings_group', 'ssgc_openai_api_key');
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
                    <td><input type="text" name="ssgc_smart_search_url" value="<?php echo esc_attr(get_option('ssgc_smart_search_url')); ?>" style="width: 100%;"></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Smart Search Access Token</th>
                    <td><input type="text" name="ssgc_smart_search_token" value="<?php echo esc_attr(get_option('ssgc_smart_search_token')); ?>" style="width: 100%;"></td>
                </tr>
                <tr valign="top">
                    <th scope="row">OpenAI API Key</th>
                    <td><input type="text" name="ssgc_openai_api_key" value="<?php echo esc_attr(get_option('ssgc_openai_api_key')); ?>" style="width: 100%;"></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Enqueue chatbot assets
 */
function ssgc_enqueue_scripts() {
    wp_enqueue_script('jquery');
    wp_enqueue_script('ssgc-chatbot', plugin_dir_url(__FILE__) . 'chatbot.js', ['jquery'], '1.0', true);
    wp_localize_script('ssgc-chatbot', 'ssgc_ajax', ['ajax_url' => admin_url('admin-ajax.php')]);
    wp_enqueue_style('ssgc-chatbot-style', plugin_dir_url(__FILE__) . 'chatbot.css');
}
add_action('wp_enqueue_scripts', 'ssgc_enqueue_scripts');

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
 * Handle chatbot AJAX request
 */
function ssgc_handle_chat_request() {
    $message = sanitize_text_field($_POST['message']);
    $smart_search_url   = get_option('ssgc_smart_search_url');
    $smart_search_token = get_option('ssgc_smart_search_token');
    $openai_api_key     = get_option('ssgc_openai_api_key');

    // Step 1: Query Smart Search via GraphQL
    $query = <<<GRAPHQL
query GetContext(\$message: String!, \$field: String!) {
  similarity(
    input: {
      nearest: {
        text: \$message,
        field: \$field
      }
    }
  ) {
    total
    docs {
      id
      data
      score
    }
  }
}
GRAPHQL;

    $variables = [
        "message" => $message,
        "field"   => "post_content"
    ];

    $graphql_body = json_encode([
        'query'     => $query,
        'variables' => $variables
    ]);

    $search_response = wp_remote_post($smart_search_url, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $smart_search_token
        ],
        'body' => $graphql_body
    ]);

    if (is_wp_error($search_response)) {
        wp_send_json(['reply' => 'Error contacting Smart Search service.']);
    }

    $search_body = wp_remote_retrieve_body($search_response);
    $search_data = json_decode($search_body, true);

    error_log('Raw Smart Search response body: ' . $search_body);
    error_log('Decoded Smart Search response: ' . print_r($search_data, true));

    $docs = $search_data['data']['similarity']['docs'] ?? [];

    if (empty($docs)) {
        wp_send_json(['reply' => "Sorry, I couldn't find an answer."]);
    }

    // Combine content into context
    $context = '';
    foreach ($docs as $doc) {
        $context .= $doc['data']['post_title'] . ": " . wp_strip_all_tags($doc['data']['post_content']) . "\n\n";
        error_log('Result snippet: ' . print_r($doc, true));
    }

    // Step 2: Send context + question to OpenAI
    $prompt = "You are a helpful assistant. Use the following context to answer the question.\n\nContext:\n$context\n\nQuestion: $message\nAnswer:";

    $openai_response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $openai_api_key
        ],
        'body' => json_encode([
            'model'    => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7
        ])
    ]);

    if (is_wp_error($openai_response)) {
        wp_send_json(['reply' => 'Error contacting OpenAI API.']);
    }

    $openai_body = wp_remote_retrieve_body($openai_response);
    $openai_data = json_decode($openai_body, true);
    $reply = $openai_data['choices'][0]['message']['content'] ?? "No answer generated.";

    wp_send_json(['reply' => $reply]);
}
add_action('wp_ajax_ssgc_chat', 'ssgc_handle_chat_request');
add_action('wp_ajax_nopriv_ssgc_chat', 'ssgc_handle_chat_request');
