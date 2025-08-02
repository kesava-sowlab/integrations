<?php
    /*
Plugin Name: Integrations
Description: Seamlessly manage the integration between Teachable and Circle to create course-based space groups. Full support for automated member syncing and real-time updates — all within your WordPress dashboard.
*/

    defined('ABSPATH') || exit;

    // === Config Constants ===
    define('TEACHABLE_API_URL', 'https://developers.teachable.com/v1/courses');

    // === Table Helpers ===
    function igm_get_circle_space_by_course_id($course_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'teachable_circle_mapping';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE course_id = %d", $course_id), ARRAY_A);
    }

    function igm_save_circle_space($course_id, $space_id, $course_name, $slug)
    {
        global $wpdb;
        $table  = $wpdb->prefix . 'teachable_circle_mapping';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE course_id = %d", $course_id));
        if ($exists) {
            return $wpdb->update($table, [
                'space_id'    => $space_id,
                'course_name' => $course_name,
                'slug'        => $slug,
            ], ['course_id' => $course_id]);
        } else {
            return $wpdb->insert($table, [
                'course_id'   => $course_id,
                'space_id'    => $space_id,
                'course_name' => $course_name,
                'slug'        => $slug,
            ]);
        }
    }
    function igm_log_action($action, $message)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'teachable_circle_logs';
        $wpdb->insert($table, [
            'action'  => sanitize_text_field($action),
            'message' => sanitize_textarea_field($message),
        ]);
    }
    // === Table Creation on Activation ===
    register_activation_hook(__FILE__, function () {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'teachable_circle_mapping';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id BIGINT UNSIGNED NOT NULL UNIQUE,
        course_name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        space_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $table_log = $wpdb->prefix . 'teachable_circle_logs';
        $log_sql   = "CREATE TABLE IF NOT EXISTS $table_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(255) NOT NULL,
        message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";
        dbDelta($log_sql);

        // Dynamic cron setup
        $delete_interval = get_option('igm_delete_cron_interval', 'daily');

        if (! wp_next_scheduled('igm_cron_delete_removed_courses')) {
            wp_schedule_event(time(), $delete_interval, 'igm_cron_delete_removed_courses');
        }
    });

    add_action('igm_cron_delete_removed_courses', 'igm_delete_spaces_for_removed_courses');

    // === Deactivation Cleanup ===
    register_deactivation_hook(__FILE__, function () {
        wp_clear_scheduled_hook('igm_daily_teachable_sync');
    });

    // === Cron Interval & Hook ===
    add_filter('cron_schedules', function ($schedules) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => __('Every Minute'),
        ];
        return $schedules;
    });

    add_action('igm_daily_teachable_sync', function () {
        igm_delete_spaces_for_removed_courses();
    });
    function handle_teachable_enrollment($request)
    {
        $body = $request->get_json_params();
        error_log("new_teachable_enrollment");

        if (
            ! is_array($body) ||
            empty($body['object']['course']['id']) ||
            empty($body['object']['course']['name']) ||
            empty($body['object']['user']['email'])
        ) {
            return new WP_REST_Response(['error' => 'Invalid payload structure'], 400);
        }

        $course_id   = sanitize_text_field($body['object']['course']['id']);
        $course_name = sanitize_text_field($body['object']['course']['name']);
        $user_name   = sanitize_text_field($body['object']['user']['name']);
        $slug        = sanitize_title($course_name);
        $email       = sanitize_email($body['object']['user']['email']);

        $stored_space_data = igm_get_circle_space_by_course_id($course_id);
        $space_id          = $stored_space_data['space_id'] ?? null;

        $community_id    = get_option('igm_circle_community_id');
        $circle_token_v1 = get_option('igm_circle_api_token_v1');
        $circle_token_v2 = get_option('igm_circle_api_token_v2');
        $space_group_id  = get_option('igm_circle_space_group_id');

        if (empty($community_id)) {
            error_log("Circle Community ID Missing");
            return;
        }
        if (empty($circle_token_v1)) {
            error_log("Circle v1 Token Missing");
            return;
        }

        if (empty($circle_token_v2)) {
            error_log("Circle  v2Token Missing");
            return;
        }
        // Step 1: Create space if not exists
        if (! $space_id) {
            error_log("Circle API Error:  before request");
            // v2
            $circle_response = wp_remote_post('https://app.circle.so/api/admin/v2/spaces', [
                'headers' => [
                    'Authorization' => 'Token ' . $circle_token_v2,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => json_encode([
                    'community_id'               => $community_id,
                    'name'                       => $course_name,
                    'slug'                       => $slug,
                    'is_private'                 => true,
                    'is_hidden_from_non_members' => true,
                    'is_hidden'                  => false,
                    'space_group_id'             => $space_group_id,
                    'topics'                     => [1],
                    'space_type'                 => 'course',
                ]),
            ]);

            if (is_wp_error($circle_response)) {
                error_log("Circle API Error: " . $circle_response->get_error_message());
                return new WP_REST_Response(['error' => 'Circle API request failed'], 500);
            }

            $response_body = json_decode(wp_remote_retrieve_body($circle_response), true);
            $space_id = $response_body['space']['id'] ?? null;

            if ($space_id) {
                igm_log_action('space_created', "Created Circle space {$space_id} for course {$course_id} ({$course_name})");
                igm_save_circle_space($course_id, $space_id, $course_name, $slug);
            } else {
                return new WP_REST_Response(['error' => 'Space creation failed'], 500);
            }
        }

        // Step 2: Invite user to community and assign space group
        $query_params = http_build_query([
            'email'        => $email,
            'community_id' => $community_id,
            'space_id'     => $space_id,
        ]);
        $url = 'https://app.circle.so/api/v1/space_members' .
            '?email=' . $email .
            '&community_id=' . $community_id .
            '&space_id=' . $space_id;

        $invite_response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Token ' . $circle_token_v1,
                'Content-Type'  => 'application/json',
            ],
        ]);

        $response_code = wp_remote_retrieve_response_code($invite_response);
        $response_body = wp_remote_retrieve_body($invite_response);
        error_log("Circle Invite response ($response_code): " . $response_body);

        error_log("Circle Invite response  url ($response_code): " . $url);

        if (is_wp_error($invite_response) || $response_code >= 400) {
            $error_message = is_wp_error($invite_response) ? $invite_response->get_error_message() : $response_body;
            error_log("Circle Invite Error: " . $error_message);
            return new WP_REST_Response(['error' => 'Failed to invite user'], 500);
        }
        igm_log_action('user_invited', "Invited {$email} to Circle space {$space_id}");

        return new WP_REST_Response([
            'message'  => 'User invited successfully',
            'space_id' => $space_id,
        ], 200);
    }

    // === REST API Webhook Endpoint ===
    add_action('rest_api_init', function () {
        register_rest_route('teachable/v1', '/enrollment', [
            'methods'             => 'POST',
            'callback'            => 'handle_teachable_enrollment',
            'permission_callback' => '__return_true',
        ]);
    });

    // === Webhook Handler ===

    // === Cron: Delete Circle Groups for Removed Courses ===
    function igm_delete_spaces_for_removed_courses()
    {
        global $wpdb;
        $table           = $wpdb->prefix . 'teachable_circle_mapping';
        $teachable_key   = get_option('igm_teachable_api_key');
        $circle_token_v1 = get_option('igm_circle_api_token_v1');

        if (empty($teachable_key)) {
            error_log("Teachable API Key Missing");
            return;
        }

        if (empty($circle_token_v1)) {
            error_log("Circle Token Missing");
            return;
        }

        $response = wp_remote_get(TEACHABLE_API_URL, [
            'headers' => ['apiKey' => $teachable_key],
        ]);

        if (is_wp_error($response)) {
            error_log("Teachable API Error (Delete Polling): " . $response->get_error_message());
            return;
        }
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 401) {
            error_log('Teachable API Error 401: Unauthorized - Invalid API key.');
            return;
        } elseif ($status_code === 404) {
            error_log('Teachable API Error 404: Not Found - The endpoint might be incorrect.');
            return;
        } elseif ($status_code !== 200) {
            error_log("Teachable API Error {$status_code}: ");
            return;
        } elseif ($status_code === 200) {
            $data         = json_decode(wp_remote_retrieve_body($response), true);
            $existing_ids = array_map(fn($course) => (string) $course['id'], $data['courses'] ?? []);

            $mappings = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);

            foreach ($mappings as $row) {
                if (! in_array((string) $row['course_id'], $existing_ids)) {
                    $space_id        = $row['space_id'];
                    $delete_response = wp_remote_request("https://app.circle.so/api/v1/spaces/{$space_id}", [
                        'method'  => 'DELETE',
                        'headers' => [
                            'Authorization' => 'Token ' . $circle_token_v1,
                            'Content-Type'  => 'application/json',
                        ],
                    ]);

                    $code = wp_remote_retrieve_response_code($delete_response);
                    if ($code === 204 || $code === 200) {
                        igm_log_action('space_deleted', "Deleted Circle space {$space_id} for removed course {$row['course_id']}");
                        $wpdb->delete($table, ['course_id' => $row['course_id']]);
                        error_log("Deleted Circle space $space_id for removed course {$row['course_id']}");
                    } else {
                        error_log("Failed to delete space $space_id: HTTP $code");
                    }
                }
            }
        }
    }

    add_action('admin_menu', function () {
        add_menu_page(
            'Teachable-Circle Mappings',
            'Integrations',
            'manage_options',
            'teachable-circle-mappings',
            'igm_render_mappings_page',
            'dashicons-groups',
            25
        );

        add_submenu_page(
            'teachable-circle-mappings',
            'Home',
            'Home',
            'manage_options',
            'teachable-circle-mappings',
            'igm_render_mappings_page'
        );

    });

    if (! class_exists('WP_List_Table')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    }

    class IGM_Mapping_List_Table extends WP_List_Table
    {

        public function __construct()
        {
            parent::__construct([
                'singular' => 'Mapping',
                'plural'   => 'Mappings',
                'ajax'     => false,
            ]);
        }

        public function get_columns()
        {
            return [
                'course_id'   => 'Teachable Course ID',
                'space_id'    => 'Circle Space ID',
                'course_name' => 'Course Name',
                'created_at'  => 'Created',
            ];
        }

        public function get_sortable_columns()
        {
            return [
                'course_id'   => ['course_id', false],
                'course_name' => ['course_name', false],
                'space_id'    => ['space_id', false],
                'created_at'  => ['created_at', true], // ✅ default sort
            ];
        }

        public function prepare_items()
        {
            global $wpdb;
            $table = $wpdb->prefix . 'teachable_circle_mapping';

            $columns               = $this->get_columns();
            $hidden                = [];
            $sortable              = $this->get_sortable_columns();
            $this->_column_headers = [$columns, $hidden, $sortable];

            $per_page     = $this->get_items_per_page('mappings_per_page', 25);
            $current_page = $this->get_pagenum();
            $offset       = ($current_page - 1) * $per_page;

            // Get orderby/order safely
            $orderby = $_GET['orderby'] ?? 'created_at';
            $order   = strtolower($_GET['order'] ?? 'desc');

            // Validate and sanitize
            $allowed_orderby = ['course_id', 'course_name', 'space_id', 'created_at'];
            if (! in_array($orderby, $allowed_orderby, true)) {
                $orderby = 'created_at';
            }
            $order = ($order === 'asc') ? 'ASC' : 'DESC';

            $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");

            $query       = $wpdb->prepare("SELECT * FROM $table ORDER BY $orderby $order LIMIT %d OFFSET %d", $per_page, $offset);
            $this->items = $wpdb->get_results($query, ARRAY_A);

            $this->set_pagination_args([
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => ceil($total_items / $per_page),
            ]);
        }

        public function column_default($item, $column_name)
        {
            return esc_html($item[$column_name]);
        }
    }
    // <h2 class="wp-heading-inline">Teachable Course ↔ Circle Group Mapping</h2>
    function igm_render_mappings_page()
    {
        echo '<div class="wrap">
    <h1>Home</h1>';

        $table = new IGM_Mapping_List_Table();
        $table->prepare_items();

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="teachable-circle-mappings" />';
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    // Allow saving screen option value
    add_filter('set-screen-option', function ($status, $option, $value) {
        return ($option === 'mappings_per_page') ? (int) $value : $status;
    }, 10, 3);

    // Register the screen option
    add_action('admin_head', function () {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'toplevel_page_teachable-circle-mappings') {
            add_screen_option('per_page', [
                'label'   => 'Number of items per page: ',
                'default' => 25,
                'option'  => 'mappings_per_page',
            ]);
        }
    });


    // === Admin UI: Settings Page ===
    add_action('admin_menu', function () {
        add_submenu_page(
            'teachable-circle-mappings',
            'I Got Mind Settings',
            'Settings',
            'manage_options',
            'teachable-circle-settings',
            'igm_render_settings_page'
        );
    });

    function igm_render_settings_page() {
        echo '<div class="wrap"><h1>Integration Settings</h1>';
        igm_render_schedule_settings_form();
        igm_render_teachable_circle_settings_form();
        igm_render_community_settings_form();
        igm_render_space_group_settings_form();
        echo '</div>';
    }

    function igm_render_schedule_settings_form() {


        if (isset($_POST['save_schedule']) && check_admin_referer('igm_save_settings', 'igm_settings_nonce')) {
            update_option('igm_delete_cron_interval', sanitize_text_field($_POST['igm_delete_cron_interval']));
            echo '<div class="updated"><p>Schedule settings saved.</p></div>';

            wp_clear_scheduled_hook('igm_cron_delete_removed_courses');
            $delete_interval = get_option('igm_delete_cron_interval', '');
            if ($delete_interval !== 'disabled') {
                wp_schedule_event(time(), $delete_interval, 'igm_cron_delete_removed_courses');
            }

        }
        $delete_interval = get_option('igm_delete_cron_interval', '');



        ?>
        <form method="post">
            <?php wp_nonce_field('igm_save_settings', 'igm_settings_nonce'); ?>
            <h2>Schedule Settings</h2>
            <table class="form-table">
                <tr>
                    <th>Delete Interval</th>
                    <td>
                        <select name="igm_delete_cron_interval">
                            <?php foreach (igm_get_cron_options(true) as $val => $label): ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected($delete_interval, $val); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Schedule', 'primary', 'save_schedule'); ?>
        </form>
        <?php
    }
    // Helper to mask token, showing only last 4 characters
    function mask_token($token) {
        $len = strlen($token);
        return $len > 4 ? str_repeat('*', $len - 4) . substr($token, -4) : $token;
    }

    function igm_render_teachable_circle_settings_form() {
        $circle_v1 = get_option('igm_circle_api_token_v1', '');
        $circle_v2 = get_option('igm_circle_api_token_v2', '');
        $teachable_api = get_option('igm_teachable_api_key', '');

        // Handle form submit
        if (isset($_POST['save_api_tokens']) && check_admin_referer('igm_save_settings', 'igm_settings_nonce')) {
            $new_circle_v1 = sanitize_text_field($_POST['igm_circle_api_v1']);
            $new_circle_v2 = sanitize_text_field($_POST['igm_circle_api_v2']);
            $new_teachable_api = sanitize_text_field($_POST['igm_teachable_api_token']);

            // Update only if not still masked
            if ($new_circle_v1 !== mask_token($circle_v1)) {
                update_option('igm_circle_api_token_v1', $new_circle_v1);
                $circle_v1 = $new_circle_v1;
            }

            if ($new_circle_v2 !== mask_token($circle_v2)) {
                update_option('igm_circle_api_token_v2', $new_circle_v2);
                $circle_v2 = $new_circle_v2;
            }

            if ($new_teachable_api !== mask_token($teachable_api)) {
                update_option('igm_teachable_api_key', $new_teachable_api);
                $teachable_api = $new_teachable_api;
            }

            echo '<div class="updated"><p>API tokens saved.</p></div>';
        }

        ?>
        <form method="post">
            <?php wp_nonce_field('igm_save_settings', 'igm_settings_nonce'); ?>
            <h2>API Keys *</h2>
            <table class="form-table">
                <tr>
                    <th>Circle API V1 *</th>
                    <td>
                        <input type="text" name="igm_circle_api_v1" 
                            value="<?php echo esc_attr(mask_token($circle_v1)); ?>" 
                            class="regular-text"
                            onfocus="clearIfMasked(this)" />
                    </td>
                </tr>
                <tr>
                    <th>Circle API V2 *</th>
                    <td>
                        <input type="text" name="igm_circle_api_v2" 
                            value="<?php echo esc_attr(mask_token($circle_v2)); ?>" 
                            class="regular-text"
                            onfocus="clearIfMasked(this)" />
                    </td>
                </tr>
                <tr>
                    <th>Teachable API Token *</th>
                    <td>
                        <input type="text" name="igm_teachable_api_token" 
                            value="<?php echo esc_attr(mask_token($teachable_api)); ?>" 
                            class="regular-text"
                            onfocus="clearIfMasked(this)" />
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Tokens', 'primary', 'save_api_tokens'); ?>
        </form>

        <script>
            function clearIfMasked(input) {
                if (input.value.includes('*')) {
                    input.value = '';
                }
            }
        </script>
        <?php
    }
    function igm_render_community_settings_form() {

        if (isset($_POST['save_community']) && check_admin_referer('igm_save_settings', 'igm_settings_nonce')) {
            update_option('igm_circle_community_id', sanitize_text_field($_POST['igm_circle_community_id']));
            echo '<div class="updated"><p>Community saved.</p></div>';
        }
        $selected = get_option('igm_circle_community_id', '');
        $communities = igm_fetch_circle_communities();
        ?>
        <form method="post">
            <?php wp_nonce_field('igm_save_settings', 'igm_settings_nonce'); ?>
            <h2>Circle Community</h2>
            <table class="form-table">
                <tr>
                    <th>Select Community</th>
                    <td>
                        <select name="igm_circle_community_id">
                            <option value="">— Select a Community —</option>
                            <?php foreach ($communities as $id => $name): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($selected, $id); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Community', 'primary', 'save_community'); ?>
        </form>
        <?php
    }

    function igm_fetch_circle_communities() {
        $token = get_option('igm_circle_api_token_v1');
        if (!$token) return [];

        $response = wp_remote_get('https://app.circle.so/api/v1/communities', [
            'headers' => ['Authorization' => 'Token ' . $token],
        ]);

        if (is_wp_error($response)) return [];

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($data)) return [];

        $communities = [];
        foreach ($data as $community) {
            $communities[$community['id']] = $community['name'];
        }

        return $communities;
    }
    function igm_render_space_group_settings_form() {
    

        if (isset($_POST['save_space_group']) && check_admin_referer('igm_save_settings', 'igm_settings_nonce')) {
            update_option('igm_circle_space_group_id', sanitize_text_field($_POST['igm_circle_space_group_id']));
            echo '<div class="updated"><p>Space group saved.</p></div>';
        }
         $selected = get_option('igm_circle_space_group_id', '');
        $community_id = get_option('igm_circle_community_id');
        $space_groups = igm_fetch_space_groups($community_id);
        ?>
        <form method="post">
            <?php wp_nonce_field('igm_save_settings', 'igm_settings_nonce'); ?>
            <h2>Circle Space Group</h2>
            <table class="form-table">
                <tr>
                    <th>Select Space Group</th>
                    <td>
                        <select name="igm_circle_space_group_id">
                            <option value="">— Select a Group —</option>
                            <?php foreach ($space_groups as $id => $name): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($selected, $id); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Space Group', 'primary', 'save_space_group'); ?>
        </form>
        <?php
    }

    function igm_fetch_space_groups($community_id) {
        $token = get_option('igm_circle_api_token_v1');
        if (!$token || !$community_id) return [];
        $response = wp_remote_get("https://app.circle.so/api/v1/space_groups?community_id={$community_id}", [
            'headers' => ['Authorization' => 'Token ' . $token],
        ]);

        if (is_wp_error($response)) return [];

        $data = json_decode(wp_remote_retrieve_body($response), true);


        if (!isset($data)) return [];

        $groups = [];
        foreach ($data as $group) {
            $groups[$group['id']] = $group['name'];
        }

        return $groups;
    }

    function igm_get_cron_options($include_disabled = false) {
        $options = [
            'every_minute' => 'Every Minute',
            'hourly'     => 'Hourly',
            'twicedaily' => 'Twice Daily',
            'daily'      => 'Daily',
        ];
        if ($include_disabled) {
            return ['disabled' => 'Disabled'] + $options;
        }
        return $options;
    }



    /**
     * Mask API keys – keeps only last 4 characters.
     */
    function mask_api_key($key)
    {
        $len = strlen($key);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 4) . substr($key, -4);
    }
    if (! class_exists('WP_List_Table')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    }
    class IGM_Logs_List_Table extends WP_List_Table
    {
        public function __construct()
        {
            parent::__construct([
                'singular' => 'Log',
                'plural'   => 'Logs',
                'ajax'     => false,
            ]);
        }
        public function get_columns()
        {
            return [
                'action'     => 'Action',
                'message'    => 'Message',
                'created_at' => 'Time',
            ];
        }
        public function get_sortable_columns()
        {
            return [
                'created_at' => ['created_at', true],
            ];
        }
        public function column_default($item, $column_name)
        {
            return esc_html($item[$column_name]);
        }
        public function prepare_items()
        {
            global $wpdb;
            $table = $wpdb->prefix . 'teachable_circle_logs';
            // Set columns
            $columns               = $this->get_columns();
                $hidden                = [];
                $sortable              = $this->get_sortable_columns();
                $this->_column_headers = [$columns, $hidden, $sortable];

                // Pagination
                $per_page     = $this->get_items_per_page('logs_per_page', 25);
                $current_page = $this->get_pagenum();
                $offset       = ($current_page - 1) * $per_page;

                // Sorting
                $orderby = isset($_GET['orderby']) && array_key_exists($_GET['orderby'], $sortable) ? $_GET['orderby'] : 'created_at';
            $order   = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';
            // Count total
            $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
            // Fetch paginated results
            $query       = $wpdb->prepare("SELECT * FROM $table ORDER BY $orderby $order LIMIT %d OFFSET %d", $per_page, $offset);
            $this->items = $wpdb->get_results($query, ARRAY_A);
            // Set pagination args
            $this->set_pagination_args([
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => ceil($total_items / $per_page),
            ]);
        }
    }
    function igm_render_logs_page()
    {
        echo '<div class="wrap"><h1 class="wp-heading-inline">Integration Logs</h1>';
        $table = new IGM_Logs_List_Table();
        $table->prepare_items();
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="teachable-circle-logs" />';
        $table->display();
        echo '</form>';
        echo '</div>';
    }
    add_filter('set-screen-option', function ($status, $option, $value) {
            if ($option === 'logs_per_page') {
                return (int) $value;
            }

            return $status;
    }, 10, 3);
    add_action('admin_menu', function () {
        $hook = add_submenu_page(
            'teachable-circle-mappings',
            'Integration Logs',
            'Logs',
            'manage_options',
            'teachable-circle-logs',
            'igm_render_logs_page'
        );
        add_action("load-$hook", function () {
            add_screen_option('per_page', [
                'label'   => 'Number of items per page: ',
                'default' => 25,
                'option'  => 'logs_per_page',
            ]);
        });
    });
