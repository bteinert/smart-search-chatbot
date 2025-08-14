<?php
/**
 * Plugin Name: Smart Search GraphQL Chatbot
 * Description: A chatbot that uses Smart Search AI Vector DB (GraphQL) for context and OpenAI API for LLM responses.
 * Version: 1.3
 * Author: Brandon T.
 * GitHub Plugin URI: bteinert/smart-search-chatbot
 * GitHub Plugin URI: https://github.com/bteinert/smart-search-chatbot
 * Primary Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'SSGC_VERSION', '1.2' );
define( 'SSGC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSGC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Security and performance constants
define( 'SSGC_MAX_MESSAGE_LENGTH', 500 );
define( 'SSGC_RATE_LIMIT_REQUESTS', 10 );
define( 'SSGC_RATE_LIMIT_WINDOW', 60 ); // seconds
define( 'SSGC_CACHE_TTL_SEARCH', 24 * HOUR_IN_SECONDS );
define( 'SSGC_CACHE_TTL_OPENAI', HOUR_IN_SECONDS );

// Include chat logs class
require_once plugin_dir_path( __FILE__ ) . 'chat-logs/chat-logs.php';
global $ssc_chat_logs;
$ssc_chat_logs = new SSC_Chat_Logs();

/**
 * Security Functions
 */

/**
 * Check rate limiting for user requests
 */
function ssgc_check_rate_limit() {
	$user_ip = ssgc_get_user_ip();
	$rate_key = 'ssgc_rate_limit_' . md5( $user_ip );
	$requests = get_transient( $rate_key );
	
	if ( false === $requests ) {
		$requests = 0;
	}
	
	if ( $requests >= SSGC_RATE_LIMIT_REQUESTS ) {
		return new WP_Error( 'rate_limit_exceeded', 'Too many requests. Please wait before trying again.' );
	}
	
	set_transient( $rate_key, $requests + 1, SSGC_RATE_LIMIT_WINDOW );
	return true;
}

/**
 * Get user IP address safely
 */
function ssgc_get_user_ip() {
	$ip_keys = [ 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' ];
	
	foreach ( $ip_keys as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			// Handle comma-separated IPs (from proxies)
			if ( strpos( $ip, ',' ) !== false ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $ip;
			}
		}
	}
	
	return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Validate and sanitize chat message
 */
function ssgc_validate_message( $message ) {
	// Check message length
	if ( strlen( $message ) > SSGC_MAX_MESSAGE_LENGTH ) {
		return new WP_Error( 'message_too_long', 'Message is too long. Maximum ' . SSGC_MAX_MESSAGE_LENGTH . ' characters allowed.' );
	}
	
	// Check for empty message
	if ( empty( trim( $message ) ) ) {
		return new WP_Error( 'empty_message', 'Message cannot be empty.' );
	}
	
	// Basic content filtering
	$blocked_patterns = [
		'/\b(script|javascript|vbscript)\b/i',
		'/<[^>]*>/i', // HTML tags
		'/\b(eval|exec|system|shell_exec)\b/i',
	];
	
	foreach ( $blocked_patterns as $pattern ) {
		if ( preg_match( $pattern, $message ) ) {
			return new WP_Error( 'invalid_content', 'Message contains invalid content.' );
		}
	}
	
	return sanitize_textarea_field( $message );
}

/**
 * Encrypt sensitive data
 */
function ssgc_encrypt_data( $data ) {
	if ( empty( $data ) ) {
		return $data;
	}
	
	$key = wp_salt( 'auth' );
	$iv = openssl_random_pseudo_bytes( 16 );
	$encrypted = openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );
	
	return base64_encode( $iv . $encrypted );
}

/**
 * Decrypt sensitive data
 */
function ssgc_decrypt_data( $encrypted_data ) {
	if ( empty( $encrypted_data ) ) {
		return $encrypted_data;
	}
	
	$key = wp_salt( 'auth' );
	$data = base64_decode( $encrypted_data );
	$iv = substr( $data, 0, 16 );
	$encrypted = substr( $data, 16 );
	
	return openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
}

/**
 * Performance Functions
 */

/**
 * Generate cache key for API responses
 */
function ssgc_generate_cache_key( $prefix, $data ) {
	return $prefix . '_' . md5( serialize( $data ) );
}

/**
 * Get cached API response
 */
function ssgc_get_cached_response( $cache_key ) {
	return get_transient( $cache_key );
}

/**
 * Set cached API response
 */
function ssgc_set_cached_response( $cache_key, $data, $ttl ) {
	return set_transient( $cache_key, $data, $ttl );
}

/**
 * Log performance metrics
 */
function ssgc_log_performance( $operation, $start_time, $end_time = null ) {
	if ( ! $end_time ) {
		$end_time = microtime( true );
	}
	
	$duration = round( ( $end_time - $start_time ) * 1000, 2 ); // Convert to milliseconds
	
	error_log( sprintf( 
		'SSGC Performance: %s took %sms', 
		$operation, 
		$duration 
	) );
	
	return $duration;
}

/**
 * Register settings
 */
function ssgc_register_settings() {
	$url          = '';
	$access_token = '';

	if ( function_exists( '\AtlasSearch\Support\WordPress\get_option' ) ) {
		$opts         = \AtlasSearch\Support\WordPress\get_option(
			\Wpe_Content_Engine\WPSettings::WPE_CONTENT_ENGINE_OPTION_NAME
		);
		$url          = $opts['url'] ?? '';
		$access_token = $opts['access_token'] ?? '';
	}

	add_option( 'ssgc_use_smart_search_settings', false );
	add_option( 'ssgc_smart_search_url', $url );
	add_option( 'ssgc_smart_search_token', $access_token );
	add_option( 'ssgc_openai_api_key', '' );

	register_setting( 'ssgc_settings_group', 'ssgc_use_smart_search_settings' );
	register_setting( 'ssgc_settings_group', 'ssgc_smart_search_url' );

	// Prevent overwriting tokens with placeholder
	register_setting('ssgc_settings_group', 'ssgc_smart_search_token', [
		'sanitize_callback' => static fn ($value) => ( ! empty( $value ) && '********' !== $value )
				? sanitize_text_field( $value )
				: get_option( 'ssgc_smart_search_token' ),
	]);

	register_setting('ssgc_settings_group', 'ssgc_openai_api_key', [
		'sanitize_callback' => static fn ($value) => ( ! empty( $value ) && '********' !== $value )
				? sanitize_text_field( $value )
				: get_option( 'ssgc_openai_api_key' ),
	]);
}

add_action( 'admin_init', 'ssgc_register_settings' );

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

add_action( 'admin_menu', 'ssgc_register_options_page' );

/**
 * Render settings page HTML
 */
function ssgc_options_page_html() {
	$use_smart_search_settings = get_option( 'ssgc_use_smart_search_settings' );
	$smart_search_available    = function_exists( '\AtlasSearch\Support\WordPress\get_option' );
	?>
	<div class="wrap">
		<h1>Smart Search Chatbot Settings</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'ssgc_settings_group' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row">Use existing settings?</th>
					<td>
						<label style="<?php echo ! $smart_search_available ? 'opacity: 0.5;' : ''; ?>">
							<input type="checkbox" name="ssgc_use_smart_search_settings" value="1" 
								<?php checked( $use_smart_search_settings ); ?>
								<?php disabled( ! $smart_search_available ); ?>
								onchange="toggleSmartSearchFields(this.checked)">
							Use settings from installed Smart Search plugin
						</label>
						<?php

						if ( ! $smart_search_available ) :
							?>
							<p class="description" style="color: #d63638; font-style: italic;">
								Smart Search plugin is not installed and activated
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr valign="top" class="smart-search-field">
					<th scope="row">Smart Search GraphQL URL</th>
					<td>
						<input type="text" name="ssgc_smart_search_url"
								value="<?php echo esc_attr( get_option( 'ssgc_smart_search_url' ) ); ?>"
								style="width: 100%;"
								<?php disabled( $use_smart_search_settings ); ?>>
					</td>
				</tr>
				<tr valign="top" class="smart-search-field">
					<th scope="row">Smart Search Access Token</th>
					<td style="display:flex; gap:5px; align-items:center;">
						<input type="password" id="ssgc_smart_search_token"
								name="ssgc_smart_search_token"
								placeholder="<?php echo get_option( 'ssgc_smart_search_token' ) ? '********' : ''; ?>"
								style="flex:1;"
								<?php disabled( $use_smart_search_settings ); ?>>
						<button type="button" class="button" data-target="ssgc_smart_search_token">Show</button>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">OpenAI API Key</th>
					<td style="display:flex; gap:5px; align-items:center;">
						<input type="password" id="ssgc_openai_api_key"
								name="ssgc_openai_api_key"
								placeholder="<?php echo get_option( 'ssgc_openai_api_key' ) ? '********' : ''; ?>"
								style="flex:1;">
						<button type="button" class="button" data-target="ssgc_openai_api_key">Show</button>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		
		<script>
		function toggleSmartSearchFields(useSettings) {
			const fields = document.querySelectorAll('.smart-search-field input');
			fields.forEach(field => {
				field.disabled = useSettings;
				if (useSettings) {
					field.style.opacity = '0.5';
				} else {
					field.style.opacity = '1';
				}
			});
		}
		
		// Initialize on page load
		document.addEventListener('DOMContentLoaded', function() {
			const checkbox = document.querySelector('input[name="ssgc_use_smart_search_settings"]');
			const smartSearchAvailable = <?php echo $smart_search_available ? 'true' : 'false'; ?>;
			
			if (smartSearchAvailable) {
				toggleSmartSearchFields(checkbox.checked);
			} else {
				// If Smart Search is not available, force manual settings
				checkbox.checked = false;
				toggleSmartSearchFields(false);
			}
		});
		</script>
	</div>
	<?php
}

/**
 * Track if chatbot shortcode is used on current page
 */
$ssgc_shortcode_used = false;

/**
 * Enqueue assets conditionally
 */
function ssgc_enqueue_assets($hook) {
	global $ssgc_shortcode_used;
	
	// Enqueue frontend assets only when shortcode is used
	if ( ! is_admin() && $ssgc_shortcode_used ) {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'ssgc-chatbot', SSGC_PLUGIN_URL . 'chatbot.js', [ 'jquery' ], SSGC_VERSION, true );
		wp_localize_script('ssgc-chatbot', 'ssgc_ajax', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ssgc_chat_nonce' ),
			'max_length' => SSGC_MAX_MESSAGE_LENGTH,
		]);
		wp_enqueue_style( 'ssgc-chatbot-style', SSGC_PLUGIN_URL . 'chatbot.css', [], SSGC_VERSION );
	}

	// Enqueue admin assets
	if ( 'settings_page_ssgc-settings' === $hook ) {
		wp_enqueue_script( 'ssgc-admin', SSGC_PLUGIN_URL . 'admin.js', [], SSGC_VERSION, true );
	}
}

add_action( 'wp_enqueue_scripts', 'ssgc_enqueue_assets' );
add_action( 'admin_enqueue_scripts', 'ssgc_enqueue_assets' );

/**
 * Check if shortcode is used in content
 */
function ssgc_check_shortcode_usage() {
	global $post, $ssgc_shortcode_used;
	
	if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'smart_search_chatbot' ) ) {
		$ssgc_shortcode_used = true;
	}
}

add_action( 'wp', 'ssgc_check_shortcode_usage' );

/**
 * Shortcode to display chatbot
 */
function ssgc_display_chatbot() {
	ob_start();
	?>
	<div id="ssgc-chatbot" role="region" aria-label="Smart Search Chatbot">
		<div id="ssgc-chat-log" role="log" aria-live="polite" aria-label="Chat conversation"></div>
		<div class="input-container">
			<input type="text" 
				   id="ssgc-chat-input" 
				   placeholder="Ask me something..." 
				   aria-label="Type your message here"
				   autocomplete="off"
				   spellcheck="true">
			<button id="ssgc-chat-send" 
					type="button"
					aria-label="Send message">
				Send
			</button>
		</div>
	</div>
	<?php

	return ob_get_clean();
}

add_shortcode( 'smart_search_chatbot', 'ssgc_display_chatbot' );

/**
 * Queries the Smart Search GraphQL API with caching.
 */
function ssgc_query_smart_search($message, $url, $token) {
	$start_time = microtime( true );
	
	// Check cache first
	$cache_key = ssgc_generate_cache_key( 'ssgc_search', [ $message, $url ] );
	$cached_result = ssgc_get_cached_response( $cache_key );
	
	if ( false !== $cached_result ) {
		ssgc_log_performance( 'Smart Search (cached)', $start_time );
		return $cached_result;
	}
	
	$query     = <<<GRAPHQL
query GetContext(\$message: String!, \$field: String!) {
  similarity(input: { nearest: { text: \$message, field: \$field }}) {
    docs { data }
  }
}
GRAPHQL;
	$variables = [
		'message' => sanitize_text_field( $message ),
		'field'   => 'post_content',
	];
	$body      = json_encode( [
		'query'     => $query,
		'variables' => $variables,
	] );

	$response = wp_remote_post($url, [
		'headers' => [
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . sanitize_text_field( $token ),
		],
		'body'    => $body,
		'timeout' => 30,
	]);

	if ( is_wp_error( $response ) ) {
		ssgc_log_performance( 'Smart Search (error)', $start_time );
		return new WP_Error( 'smart_search_error', 'Error contacting Smart Search service.' );
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		ssgc_log_performance( 'Smart Search (error)', $start_time );
		return new WP_Error( 'smart_search_error', 'Smart Search service returned error: ' . $response_code );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	$result = $data['data']['similarity']['docs'] ?? [];
	
	// Cache the result
	ssgc_set_cached_response( $cache_key, $result, SSGC_CACHE_TTL_SEARCH );
	
	ssgc_log_performance( 'Smart Search (API)', $start_time );
	return $result;
}

/**
 * Queries the OpenAI API with caching.
 */
function ssgc_query_openai($context, $message, $api_key) {
	$start_time = microtime( true );
	
	// Check cache first
	$cache_key = ssgc_generate_cache_key( 'ssgc_openai', [ $context, $message ] );
	$cached_result = ssgc_get_cached_response( $cache_key );
	
	if ( false !== $cached_result ) {
		ssgc_log_performance( 'OpenAI (cached)', $start_time );
		return $cached_result;
	}
	
	$prompt = "Use the following context to answer the question.\n\nContext:\n$context\n\nQuestion: $message\nAnswer:";
	$body   = json_encode([
		'model'       => 'gpt-4o-mini',
		'messages'    => [
			[
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			],
			[
				'role'    => 'user',
				'content' => sanitize_textarea_field( $prompt ),
			],
		],
		'temperature' => 0.7,
		'max_tokens'  => 500,
	]);

	$response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
		'headers' => [
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . sanitize_text_field( $api_key ),
		],
		'body'    => $body,
		'timeout' => 30,
	]);

	if ( is_wp_error( $response ) ) {
		ssgc_log_performance( 'OpenAI (error)', $start_time );
		return new WP_Error( 'openai_error', 'Error contacting OpenAI API.' );
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		ssgc_log_performance( 'OpenAI (error)', $start_time );
		return new WP_Error( 'openai_error', 'OpenAI API returned error: ' . $response_code );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	$result = $data['choices'][0]['message']['content'] ?? 'No answer generated.';
	
	// Cache the result
	ssgc_set_cached_response( $cache_key, $result, SSGC_CACHE_TTL_OPENAI );
	
	ssgc_log_performance( 'OpenAI (API)', $start_time );
	return $result;
}

/**
 * Handle chatbot AJAX request with security checks
 */
function ssgc_handle_chat_request() {
	$start_time = microtime( true );
	
	// Security checks
	check_ajax_referer( 'ssgc_chat_nonce', 'nonce' );
	
	// Check rate limiting
	$rate_check = ssgc_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( [ 'reply' => $rate_check->get_error_message() ] );
	}
	
	// Validate and sanitize message
	$raw_message = $_POST['message'] ?? '';
	$message = ssgc_validate_message( $raw_message );
	
	if ( is_wp_error( $message ) ) {
		wp_send_json_error( [ 'reply' => $message->get_error_message() ] );
	}

	global $ssc_chat_logs;

	// Fetch API credentials
	$use_smart_search_settings = get_option( 'ssgc_use_smart_search_settings' );
	
	if ( $use_smart_search_settings && function_exists( '\AtlasSearch\Support\WordPress\get_option' ) ) {
		$opts               = \AtlasSearch\Support\WordPress\get_option(
			\Wpe_Content_Engine\WPSettings::WPE_CONTENT_ENGINE_OPTION_NAME
		);
		$smart_search_url   = $opts['url'] ?? '';
		$smart_search_token = $opts['access_token'] ?? '';
	} else {
		$smart_search_url   = get_option( 'ssgc_smart_search_url' );
		$smart_search_token = get_option( 'ssgc_smart_search_token' );
	}
	
	$openai_api_key = get_option( 'ssgc_openai_api_key' );
	
	// Validate API credentials
	if ( empty( $smart_search_url ) || empty( $smart_search_token ) || empty( $openai_api_key ) ) {
		wp_send_json_error( [ 'reply' => 'Chatbot is not properly configured. Please contact the administrator.' ] );
	}

	// Get context from Smart Search
	$docs = ssgc_query_smart_search( $message, $smart_search_url, $smart_search_token );

	if ( is_wp_error( $docs ) ) {
		wp_send_json_error( [ 'reply' => $docs->get_error_message() ] );
	}

	if ( empty( $docs ) ) {
		wp_send_json_success( [ 'reply' => "Sorry, I couldn't find an answer to your question." ] );
	}

	$context = implode(
		"\n\n",
		array_map(
			static fn ($doc) => $doc['data']['post_title'] . ': ' . wp_strip_all_tags( $doc['data']['post_content'] ),
			$docs
		)
	);

	// Get reply from OpenAI
	$reply = ssgc_query_openai( $context, $message, $openai_api_key );

	if ( is_wp_error( $reply ) ) {
		wp_send_json_error( [ 'reply' => $reply->get_error_message() ] );
	}

	// Log chat interaction
	if ( isset( $ssc_chat_logs ) ) {
		$ssc_chat_logs->log_chat( $message, $reply );
	}
	
	// Log total request performance
	ssgc_log_performance( 'Total chat request', $start_time );

	wp_send_json_success( [ 'reply' => sanitize_text_field( $reply ) ] );
}

add_action( 'wp_ajax_ssgc_chat', 'ssgc_handle_chat_request' );
add_action( 'wp_ajax_nopriv_ssgc_chat', 'ssgc_handle_chat_request' );
