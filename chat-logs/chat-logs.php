<?php
// chat-logs/chat-logs.php
defined('ABSPATH') or die('No script kiddies please!');

class SSC_Chat_Logs {
    private $table_name;

    public function __construct() {
        global $wpdb;
	$this->table_name = $wpdb->prefix . 'ssc_chat_logs';

        // Create table on plugin activation
        register_activation_hook(plugin_dir_path(__DIR__) . 'smart-search-chatbot.php', [$this, 'create_table']);

        // Add admin menu for logs page
        add_action('admin_menu', [$this, 'add_admin_menu']);

	// Add settings for chat-log pruning
	add_action('admin_init', [$this, 'register_settings']);

	add_action('ssc_prune_chat_logs', [$this, 'prune_logs']);
	register_activation_hook(plugin_dir_path(__DIR__) . 'smart-search-chatbot.php', [$this, 'schedule_pruning']);
	register_deactivation_hook(plugin_dir_path(__DIR__) . 'smart-search-chatbot.php', [$this, 'clear_pruning_schedule']);

        // Register ajax handler to delete logs
        add_action('wp_ajax_ssc_delete_chat_log', [$this, 'handle_delete_log']);
    }

    //Log Pruning Logic
    public function schedule_pruning() {
        if (!wp_next_scheduled('ssc_prune_chat_logs')) {
            wp_schedule_event(time(), 'daily', 'ssc_prune_chat_logs');
        }
    }

    public function clear_pruning_schedule() {
        wp_clear_scheduled_hook('ssc_prune_chat_logs');
    }

    public function prune_logs() {
        $enabled = get_option('ssc_enable_pruning', 0);
        $days    = intval(get_option('ssc_prune_days', 90));

        if (!$enabled || $days <= 0) {
            return; // Feature disabled or invalid days
        }

        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE created_at < (NOW() - INTERVAL %d DAY)",
                $days
            )
        );
    }

    public function register_settings() {
    register_setting('ssc_chat_logs_settings', 'ssc_enable_pruning');
    register_setting('ssc_chat_logs_settings', 'ssc_prune_days');

    add_settings_section(
        'ssc_chat_logs_section',
        'Chatbot Logs Settings',
        null,
        'ssc-chat-logs-settings'
    );

    add_settings_field(
        'ssc_enable_pruning',
        'Enable Automatic Pruning',
        function() {
            $value = get_option('ssc_enable_pruning', 0);
            echo '<input type="checkbox" name="ssc_enable_pruning" value="1" ' . checked(1, $value, false) . '>';
        },
        'ssc-chat-logs-settings',
        'ssc_chat_logs_section'
    );

    add_settings_field(
        'ssc_prune_days',
        'Delete Logs Older Than (days)',
        function() {
            $value = intval(get_option('ssc_prune_days', 90));
            echo '<input type="number" name="ssc_prune_days" value="' . esc_attr($value) . '" min="1">';
        },
        'ssc-chat-logs-settings',
        'ssc_chat_logs_section'
    );
    }


    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function log_chat($question, $answer) {
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            [
                'question'   => $question,
                'answer'     => $answer,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s']
        );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'options-general.php',
	    'Chatbot Logs',
	    'Chatbot Logs',
            'manage_options',
            'ssc-chatbot-logs',
            [$this, 'render_admin_page']
    );
    	add_submenu_page(
            'options-general.php',
            'Chatbot Logs Settings',
            'Chatbot Logs Settings',
            'manage_options',
            'ssc-chat-logs-settings',
            [$this, 'render_settings_page']
    );	

	//Scripts & Styles for logs page
        add_action('admin_enqueue_scripts', function($hook) {
            if ($hook === 'settings_page_ssc-chatbot-logs') {
                wp_enqueue_style('ssc-chat-logs-style', plugin_dir_url(__DIR__) . 'chat-logs/admin-style.css');
                wp_enqueue_script('ssc-chat-logs-script', plugin_dir_url(__DIR__) . 'chat-logs/admin-page.js', ['jquery'], null, true);
                wp_localize_script('ssc-chat-logs-script', 'sscChatLogs', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('ssc_delete_log_nonce'),
                ]);
            }
        });
    }




    public function render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Chatbot Logs Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ssc_chat_logs_settings');
            do_settings_sections('ssc-chat-logs-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
    }

    public function render_admin_page() {
        global $wpdb;
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        $total_logs = $wpdb->get_var("SELECT COUNT(id) FROM {$this->table_name}");
        $total_pages = ceil($total_logs / $per_page);

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        ?>
        <div class="wrap">
            <h1>Chatbot Logs</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="10%">ID</th>
                        <th width="35%">Question</th>
                        <th width="35%">Answer</th>
                        <th width="15%">Timestamp</th>
                        <th width="5%">Delete</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($logs): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr id="log-<?php echo esc_attr($log->id); ?>">
                            <td><?php echo esc_html($log->id); ?></td>
                            <td><?php echo esc_html($log->question); ?></td>
                            <td><?php echo esc_html($log->answer); ?></td>
                            <td><?php echo esc_html($log->created_at); ?></td>
                            <td><button class="ssc-delete-log" data-id="<?php echo esc_attr($log->id); ?>">Delete</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5">No logs found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo $total_logs; ?> items</span>
                        <span class="pagination-links">
                            <?php
                            echo paginate_links([
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => __('&laquo;'),
                                'next_text' => __('&raquo;'),
                                'total' => $total_pages,
                                'current' => $current_page,
                            ]);
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_delete_log() {
        check_ajax_referer('ssc_delete_log_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('Invalid ID');
        }

        global $wpdb;
        $deleted = $wpdb->delete($this->table_name, ['id' => $id], ['%d']);

        if ($deleted) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete');
        }
    }
}
