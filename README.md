# Smart Search GraphQL Chatbot

A WordPress plugin that provides an intelligent chatbot interface using WP Engine's Smart Search AI Vector Database (GraphQL) for context retrieval and OpenAI's API for generating responses.

## Features

- **AI-Powered Responses**: Uses OpenAI's GPT-4o-mini model for intelligent conversation
- **Context-Aware**: Leverages Smart Search vector database for relevant content retrieval
- **Security & Performance**: Built-in rate limiting, input validation, and response caching
- **Chat Logging**: Complete conversation history with admin management interface
- **Responsive Design**: Mobile-friendly chatbot interface
- **Easy Integration**: Simple shortcode implementation
- **Flexible Configuration**: Works with existing Smart Search plugin settings or standalone

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- OpenAI API key
- Smart Search GraphQL endpoint and access token

## Installation

1. Upload the plugin files to `/wp-content/plugins/smart-search-chatbot/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin settings (see Configuration section below)

## Configuration

### Step 1: Access Plugin Settings

Navigate to **WordPress Admin → Settings → Smart Search Chatbot**

### Step 2: Configure API Credentials

You have two options for configuration:

#### Option A: Use Existing Smart Search Plugin Settings (Recommended)
If you have the Smart Search plugin installed and activated:
1. Check "Use settings from installed Smart Search plugin"
2. The URL and access token will be automatically populated

#### Option B: Manual Configuration
1. **Smart Search GraphQL URL**: Enter your Smart Search endpoint URL
2. **Smart Search Access Token**: Enter your access token
3. **OpenAI API Key**: Enter your OpenAI API key

### Step 3: Save Settings

Click "Save Changes" to store your configuration.

## Usage

### Adding the Chatbot to Pages/Posts

Use the shortcode `[smart_search_chatbot]` in any page, post, or widget where you want the chatbot to appear.

**Example:**
```
[smart_search_chatbot]
```

### Chatbot Interface

The chatbot provides:
- Clean, responsive chat interface
- Real-time message exchange
- Typing indicators and loading states
- Accessibility features (ARIA labels, keyboard navigation)
- Mobile-optimized design

## Chat Logs Management

### Viewing Chat Logs

1. Navigate to **WordPress Admin → Settings → Chatbot Logs**
2. View all chat conversations with timestamps
3. Search and filter conversations
4. Paginated display for large datasets

### Chat Logs Settings

1. Navigate to **WordPress Admin → Settings → Chatbot Logs Settings**
2. Configure automatic log pruning:
   - **Enable Automatic Pruning**: Toggle automatic deletion of old logs
   - **Delete Logs Older Than**: Set retention period in days (default: 90 days)

### Manual Log Management

- Delete individual chat entries from the logs interface
- Bulk operations for log management
- Export capabilities for data analysis

## Security Features

### Rate Limiting
- **10 requests per minute** per IP address
- Automatic blocking of excessive requests
- Configurable rate limits via constants

### Input Validation
- **500 character limit** per message
- HTML tag filtering
- Script injection prevention
- Content sanitization

### Data Protection
- Encrypted storage of sensitive API keys
- Secure AJAX nonce verification
- IP address logging for security auditing

## Performance Optimization

### Caching System
- **Smart Search responses**: 24-hour cache
- **OpenAI responses**: 1-hour cache
- Automatic cache invalidation
- Reduced API calls and faster responses

### Conditional Asset Loading
- Scripts and styles only load when shortcode is present
- Optimized for page speed
- Minimal resource footprint

### Database Optimization
- Indexed chat logs table
- Efficient query patterns
- Automatic log pruning

## Customization

### Styling
The chatbot interface can be customized via CSS. Key classes:
- `.ssgc-chatbot`: Main container
- `.ssgc-chat-log`: Message history area
- `.ssgc-message`: Individual messages
- `.ssgc-user-message`: User messages
- `.ssgc-bot-message`: Bot responses

### Configuration Constants
You can override default settings in your `wp-config.php`:

```php
// Message length limit (default: 500)
define('SSGC_MAX_MESSAGE_LENGTH', 1000);

// Rate limiting (default: 10 requests per 60 seconds)
define('SSGC_RATE_LIMIT_REQUESTS', 20);
define('SSGC_RATE_LIMIT_WINDOW', 60);

// Cache TTL (default: 24 hours for search, 1 hour for OpenAI)
define('SSGC_CACHE_TTL_SEARCH', 48 * HOUR_IN_SECONDS);
define('SSGC_CACHE_TTL_OPENAI', 2 * HOUR_IN_SECONDS);
```

## API Integration

### Smart Search GraphQL Query
The plugin uses the following GraphQL query structure:
```graphql
query GetContext($message: String!, $field: String!) {
  similarity(input: { nearest: { text: $message, field: $field }}) {
    docs { data }
  }
}
```

### OpenAI Chat Completions
- Model: `gpt-4o-mini`
- Temperature: 0.7
- Max tokens: 500
- System prompt optimization for context-aware responses

## Troubleshooting

### Common Issues

**Chatbot not responding:**
1. Verify API credentials in settings
2. Check WordPress error logs
3. Ensure Smart Search endpoint is accessible
4. Validate OpenAI API key

**Rate limiting errors:**
1. Wait for rate limit window to reset
2. Adjust rate limiting constants if needed
3. Check for multiple users hitting limits

**Performance issues:**
1. Enable caching if disabled
2. Check database indexes
3. Monitor API response times
4. Consider increasing cache TTL

### Debug Mode
Enable WordPress debug mode to see detailed error messages:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Hooks and Filters

### Available Actions
- `ssgc_before_chat_response`: Fired before generating chat response
- `ssgc_after_chat_response`: Fired after generating chat response
- `ssgc_chat_logged`: Fired after logging chat interaction

### Available Filters
- `ssgc_openai_model`: Filter OpenAI model selection
- `ssgc_max_context_length`: Filter context length for Smart Search
- `ssgc_cache_ttl`: Filter cache time-to-live values

## Version History

### Version 1.3
- Enhanced security features
- Performance optimizations
- Improved chat logging
- Better error handling
- Mobile responsiveness improvements

### Version 1.2
- Added chat logs functionality
- Implemented caching system
- Security enhancements
- Rate limiting features

### Version 1.1
- Initial release
- Basic chatbot functionality
- Smart Search integration
- OpenAI API integration

## Support

For support and feature requests, please visit the [GitHub repository](https://github.com/bteinert/smart-search-chatbot).

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by Brandon T. for WP Engine Smart Search integration.
