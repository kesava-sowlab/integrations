<?php
/*
Plugin Name: Integrations
Description: Seamlessly manage the integration between Teachable and Circle to create course-based space groups. Full support for automated member syncing and real-time updates — all within your WordPress dashboard.
*/

defined('ABSPATH') || exit;

// === Config Constants ===
define('TEACHABLE_API_URL', 'https://developers.teachable.com/v1/courses');

// === Table Helpers ===
function igm_get_circle_space_by_course_id($course_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'teachable_circle_mapping';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE course_id = %d", $course_id), ARRAY_A);
}

function igm_save_circle_space($course_id, $space_id, $course_name, $slug) {
    global $wpdb;
    $table = $wpdb->prefix . 'teachable_circle_mapping';
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE course_id = %d", $course_id));
    if ($exists) {
        return $wpdb->update($table, [
            'space_id' => $space_id,
            'course_name' => $course_name,
            'slug' => $slug,
        ], ['course_id' => $course_id]);
    } else {
        return $wpdb->insert($table, [
            'course_id' => $course_id,
            'space_id' => $space_id,
            'course_name' => $course_name,
            'slug' => $slug,
        ]);
    }
}
function igm_log_action($action, $message) {
    global $wpdb;
    $table = $wpdb->prefix . 'teachable_circle_logs';
    $wpdb->insert($table, [
        'action' => sanitize_text_field($action),
        'message' => sanitize_textarea_field($message),
    ]);
}
// === Table Creation on Activation ===
register_activation_hook(__FILE__, function () {
    global $wpdb;

    $table_name = $wpdb->prefix . 'teachable_circle_mapping';
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
$log_sql = "CREATE TABLE IF NOT EXISTS $table_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(255) NOT NULL,
    message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) $charset_collate;";
dbDelta($log_sql);

    // Dynamic cron setup
    $update_interval = get_option('igm_update_cron_interval', 'daily');
    $delete_interval = get_option('igm_delete_cron_interval', 'daily');

    if (!wp_next_scheduled('igm_cron_update_course_names')) {
        wp_schedule_event(time(), $update_interval, 'igm_cron_update_course_names');
    }

    if (!wp_next_scheduled('igm_cron_delete_removed_courses')) {
        wp_schedule_event(time(), $delete_interval, 'igm_cron_delete_removed_courses');
    }
});

add_action('igm_cron_update_course_names', 'igm_check_and_update_course_names');
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
    igm_check_and_update_course_names();
    igm_delete_spaces_for_removed_courses();
});
function handle_teachable_enrollment($request) {
    $body = $request->get_json_params();
    error_log("handle_teachable_enrollment " . print_r($body, true));

    if (
        !is_array($body) ||
        empty($body['object']['course']['id']) ||
        empty($body['object']['course']['name']) ||
        empty($body['object']['user']['email'])
    ) {
        return new WP_REST_Response(['error' => 'Invalid payload structure'], 400);
    }

    $course_id = sanitize_text_field($body['object']['course']['id']);
    $course_name = sanitize_text_field($body['object']['course']['name']);
    $user_name = sanitize_text_field($body['object']['user']['name']);
    $slug = sanitize_title($course_name);
    $email = sanitize_email($body['object']['user']['email']);

    $stored_space_data = igm_get_circle_space_by_course_id($course_id);
    $space_id = $stored_space_data['space_id'] ?? null;

    $community_id    = get_option('igm_circle_community_id');
    $circle_token_v1    = get_option('igm_circle_api_token_v1');
    $circle_token_v2    = get_option('igm_circle_api_token_v2');
    $space_group_id    = get_option('igm_circle_space_group_id');

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
    if (!$space_id) {
                    error_log("Circle API Error:  before request");
// v2
        $circle_response = wp_remote_post('https://app.circle.so/api/admin/v2/spaces', [
            'headers' => [
                'Authorization' => 'Token ' . $circle_token_v2,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'community_id' => $community_id,
                'name' => $course_name,
                'slug' => $slug,
                'is_private'=> true,
                'is_hidden_from_non_members' => true,
                'is_hidden'=> false,
                'space_group_id'=> $space_group_id,
                'topics'=> [1],
                'space_type'=> 'course'
            ]),
        ]);
            error_log("Circle API Error: " . print_r($response_body, true));

        if (is_wp_error($circle_response)) {
            error_log("Circle API Error: " . $circle_response->get_error_message());
            return new WP_REST_Response(['error' => 'Circle API request failed'], 500);
        }

        $response_body = json_decode(wp_remote_retrieve_body($circle_response), true);
        error_log("Circle Response: " . print_r($response_body, true));
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
    'email' => $email,
    'community_id' => $community_id,
    'space_id' => $space_id,
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
        'message' => 'User invited successfully',
        'space_id' => $space_id
    ], 200);
}

// === REST API Webhook Endpoint ===
add_action('rest_api_init', function () {
    register_rest_route('teachable/v1', '/enrollment', [
        'methods' => 'POST',
        'callback' => 'handle_teachable_enrollment',
        'permission_callback' => '__return_true',
    ]);
});

// === Webhook Handler ===


// === Cron: Sync Course Name Updates ===
function igm_check_and_update_course_names() {
        $update_interval = get_option('igm_update_cron_interval', 'daily');
    error_log("Checking Teachable courses,$update_interval");
    global $wpdb;
    $table = $wpdb->prefix . 'teachable_circle_mapping';
    $teachable_key   = get_option('igm_teachable_api_key');
    $circle_token_v1    = get_option('igm_circle_api_token_v1');

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
}elseif ($status_code === 200){
 error_log("Teachable Status {$status_code}: ");
// Log the response safely

    if (is_wp_error($response)) {
        error_log("Teachable API Error: " . $response->get_error_message());
        return;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $courses = $data['courses'] ?? [];

    foreach ($courses as $course) {
        $course_id = sanitize_text_field($course['id']);
        $current_name = sanitize_text_field($course['name']);
        $current_slug = sanitize_title($current_name);

        $stored = igm_get_circle_space_by_course_id($course_id);
        if (!$stored || !isset($stored['space_id'])) continue;

        if ($stored['course_name'] !== $current_name) {
            $update = wp_remote_request("https://app.circle.so/api/v1/spaces/{$stored['space_id']}", [
                'method'  => 'PUT',
                'headers' => [
                    'Authorization' => 'Token ' . $circle_token_v1,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode([
                    'name' => $current_name,
                    'slug' => $current_slug
                ]),
            ]);

            $update_code = wp_remote_retrieve_response_code($update);
            if ($update_code === 200 || $update_code === 201) {
                igm_log_action('space_updated', "Updated Circle space {$stored['space_id']} to '{$current_name}'");

                igm_save_circle_space($course_id, $stored['space_id'], $current_name, $current_slug);
                error_log("Circle space updated: $course_id → $current_name");
            } else {
                error_log("Circle update failed with code $update_code for course $course_id");
            }
        }
    }
}
}

// === Cron: Delete Circle Groups for Removed Courses ===
function igm_delete_spaces_for_removed_courses() {
    global $wpdb;
    $table = $wpdb->prefix . 'teachable_circle_mapping';
    $teachable_key   = get_option('igm_teachable_api_key');
    $circle_token_v1    = get_option('igm_circle_api_token_v1');

    
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
}elseif ($status_code === 200){
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $existing_ids = array_map(fn($course) => (string)$course['id'], $data['courses'] ?? []);

    $mappings = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);

    foreach ($mappings as $row) {
        if (!in_array((string)$row['course_id'], $existing_ids)) {
            $space_id = $row['space_id'];
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
//     add_filter('set-screen-option', function($status, $option, $value) {
//     return ($option === 'mappings_per_page') ? (int) $value : $status;
// }, 10, 3);

// add_action('admin_head', function () {
//     $screen = get_current_screen();
//     if ($screen && $screen->id === 'toplevel_page_teachable-circle-mappings') {
//         add_screen_option('per_page', [
//             'label'   => 'Mappings per page',
//             'default' => 25,
//             'option'  => 'mappings_per_page'
//         ]);
//     }
// });

});

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class IGM_Mapping_List_Table extends WP_List_Table {
    private $data;

    public function __construct() {
        parent::__construct([
            'singular' => 'Mapping',
            'plural'   => 'Mappings',
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        return [
            'course_id'   => 'Teachable Course ID',
            'space_id'    => 'Circle Space ID',
            'course_name' => 'Course Name',
            'created_at'  => 'Created',
        ];
    }

    public function get_sortable_columns() {
        return [
            'course_id'   => ['course_id', false],
            'course_name' => ['course_name', false],
            'space_id'    => ['space_id', false],
            'created_at'  => ['created_at', true], // ✅ default sort
        ];
    }

    public function prepare_items() {
        global $wpdb;
        $table = $wpdb->prefix . 'teachable_circle_mapping';

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $per_page     = $this->get_items_per_page('mappings_per_page', 25);
        $current_page = $this->get_pagenum();
        $offset       = ($current_page - 1) * $per_page;

        // Get orderby/order safely
        $orderby = $_GET['orderby'] ?? 'created_at';
        $order   = strtolower($_GET['order'] ?? 'desc');

        // Validate and sanitize
        $allowed_orderby = ['course_id', 'course_name', 'space_id', 'created_at'];
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'created_at';
        }
        $order = ($order === 'asc') ? 'ASC' : 'DESC';

        $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");

        $query = $wpdb->prepare("SELECT * FROM $table ORDER BY $orderby $order LIMIT %d OFFSET %d", $per_page, $offset);
        $this->items = $wpdb->get_results($query, ARRAY_A);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }

    public function column_default($item, $column_name) {
        return esc_html($item[$column_name]);
    }
}
// <h2 class="wp-heading-inline">Teachable Course ↔ Circle Group Mapping</h2>
function igm_render_mappings_page() {
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
add_filter('set-screen-option', function($status, $option, $value) {
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
// if (!class_exists('WP_List_Table')) {
//     require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
// }

// class IGM_Mapping_Dummy_Table extends WP_List_Table {
//     private $dummy_rows = [];

//     public function __construct() {
//         parent::__construct([
//             'singular' => 'Mapping',
//             'plural'   => 'Mappings',
//             'ajax'     => false,
//         ]);
//     }

//     public function get_columns() {
//         return [
//             'course_id'   => 'Course ID',
//             'course_name' => 'Course Name',
//             'group_id'    => 'Group ID',
//             'created_at'  => 'Created',
//         ];
//     }

//     public function get_sortable_columns() {
//     return [
//         'course_id'   => ['course_id', false],
//         'course_name' => ['course_name', false],
//         'group_id'    => ['group_id', false],
//         'created_at'  => ['created_at', true],  // true = default sort
//     ];
// }
//     public function prepare_items() {
//     $per_page     = $this->get_items_per_page('mappings_per_page', 25);
//     $current_page = $this->get_pagenum();
//     $offset       = ($current_page - 1) * $per_page;

//     // Generate dummy data
//     $total = 105;
//     $this->dummy_rows = [];
//     for ($i = 1; $i <= $total; $i++) {
//         $this->dummy_rows[] = [
//             'course_id'   => 1000 + $i,
//             'course_name' => 'Course #' . $i,
//             'group_id'    => 2000 + $i,
//             'created_at'  => date('Y-m-d H:i:s', strtotime("-$i hours")),
//         ];
//     }

//     // Handle sorting
//     $orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'created_at';
// $order   = isset($_GET['order']) ? strtolower($_GET['order']) : 'desc'; // default is DESC


//     if (in_array($orderby, ['course_id', 'course_name', 'group_id', 'created_at'])) {
//         usort($this->dummy_rows, function ($a, $b) use ($orderby, $order) {
//             $a_val = $a[$orderby];
//             $b_val = $b[$orderby];

//             if (is_numeric($a_val) && is_numeric($b_val)) {
//                 return $order === 'asc' ? $a_val - $b_val : $b_val - $a_val;
//             } else {
//                 return $order === 'asc' ? strcmp($a_val, $b_val) : strcmp($b_val, $a_val);
//             }
//         });
//     }

//     // Paginate
//     $paged_data   = array_slice($this->dummy_rows, $offset, $per_page);
//     $this->items  = $paged_data;

//     // Columns
//     $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
//     $this->set_pagination_args([
//         'total_items' => $total,
//         'per_page'    => $per_page,
//         'total_pages' => ceil($total / $per_page),
//     ]);
// }


//     public function column_default($item, $column_name) {
//         return esc_html($item[$column_name]);
//     }
// }
// function igm_render_mappings_page() {
//     echo '<div class="wrap"><h1 class="wp-heading-inline">Teachable ↔ Circle Mapping</h1>';

//     $table = new IGM_Mapping_Dummy_Table();
//     $table->prepare_items();

//     echo '<form method="get">';
//     echo '<input type="hidden" name="page" value="teachable-circle-mappings">';
//     $table->display();
//     echo '</form>';

//     echo '</div>';
// }

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
    $messages = [];

    if (isset($_POST['igm_settings_nonce']) && wp_verify_nonce($_POST['igm_settings_nonce'], 'igm_save_settings')) {

        // =============== Save Schedule ====================
        if (isset($_POST['save_schedule'])) {
            $update_interval = sanitize_text_field($_POST['igm_update_cron_interval']);
            $delete_interval = sanitize_text_field($_POST['igm_delete_cron_interval']);

            update_option('igm_update_cron_interval', $update_interval);
            update_option('igm_delete_cron_interval', $delete_interval);

            wp_clear_scheduled_hook('igm_cron_update_course_names');
            wp_clear_scheduled_hook('igm_cron_delete_removed_courses');

            if ($update_interval !== 'disabled') {
                wp_schedule_event(time(), $update_interval, 'igm_cron_update_course_names');
            }
            if ($delete_interval !== 'disabled') {
                wp_schedule_event(time(), $delete_interval, 'igm_cron_delete_removed_courses');
            }

            $messages[] = 'Schedule settings saved.';
        }

        // =============== Save API Keys ====================
        if (isset($_POST['save_keys'])) {
            $submitted_circle_token_v1 = trim(sanitize_text_field($_POST['igm_circle_api_token_v1']));
            $submitted_circle_token_v2 = trim(sanitize_text_field($_POST['igm_circle_api_token_v2']));
            $submitted_teachable_key   = trim(sanitize_text_field($_POST['igm_teachable_api_key']));

            $saved_circle_token_v1 = get_option('igm_circle_api_token_v1', '');
            $saved_circle_token_v2 = get_option('igm_circle_api_token_v2', '');
            $saved_teachable_key   = get_option('igm_teachable_api_key', '');

            $circle_token_v1 = ($submitted_circle_token_v1 === mask_api_key($saved_circle_token_v1)) ? $saved_circle_token_v1 : $submitted_circle_token_v1;
            $circle_token_v2 = ($submitted_circle_token_v2 === mask_api_key($saved_circle_token_v2)) ? $saved_circle_token_v2 : $submitted_circle_token_v2;
            $teachable_key   = ($submitted_teachable_key === mask_api_key($saved_teachable_key)) ? $saved_teachable_key : $submitted_teachable_key;

            if (empty($circle_token_v1) || empty($circle_token_v2) || empty($teachable_key)) {
                echo '<div class="error"><p>All API fields are required.</p></div>';
            } else {
                update_option('igm_circle_api_token_v1', $circle_token_v1);
                update_option('igm_circle_api_token_v2', $circle_token_v2);
                update_option('igm_teachable_api_key', $teachable_key);
                $messages[] = 'API keys saved.';
            }
        }

        // =============== Save Community ID ================
        if (isset($_POST['save_community'])) {
            $selected_community_id = trim(sanitize_text_field($_POST['igm_circle_community_id']));
            if ($selected_community_id !== '') {
                update_option('igm_circle_community_id', $selected_community_id);
                $messages[] = 'Community ID saved.';
            } else {
                echo '<div class="error"><p>Please select a community.</p></div>';
            }
        }

        // =============== Save Group ID ====================
        if (isset($_POST['save_group'])) {
            $group_id = sanitize_text_field($_POST['igm_circle_space_group_id']);
            if (!empty($group_id)) {
                update_option('igm_circle_space_group_id', $group_id);
                $messages[] = 'Default Space Group saved.';
            } else {
                echo '<div class="error"><p>Please select a space group.</p></div>';
            }
        }

        if (!empty($messages)) {
            echo '<div class="updated"><p>' . implode('<br>', array_map('esc_html', $messages)) . '</p></div>';
        }
    }

    // Load saved values
    $update_interval = get_option('igm_update_cron_interval', 'disabled');
    $delete_interval = get_option('igm_delete_cron_interval', 'disabled');
    $circle_token_v1 = get_option('igm_circle_api_token_v1', '');
    $circle_token_v2 = get_option('igm_circle_api_token_v2', '');
    $teachable_key   = get_option('igm_teachable_api_key', '');
    $community_id    = get_option('igm_circle_community_id', '');
    $saved_space_group_id = get_option('igm_circle_space_group_id', '');

    $masked_token_v1 = $circle_token_v1 ? mask_api_key($circle_token_v1) : '';
    $masked_token_v2 = $circle_token_v2 ? mask_api_key($circle_token_v2) : '';
    $masked_key      = $teachable_key ? mask_api_key($teachable_key) : '';

    // Fetch communities
    $community_options = [];

    if (!empty($circle_token_v1)) {
        $response = wp_remote_get('https://app.circle.so/api/v1/communities', [
            'headers' => [
                'Authorization' => 'Token ' . $circle_token_v1,
                'Content-Type'  => 'application/json',
            ]
        ]);

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_wp_error($response)) {
            if (!empty($data) && is_array($data)) {
                foreach ($data as $community) {
                    if (isset($community['id']) && isset($community['name'])) {
                        $community_options[] = [
                            'id' => $community['id'],
                            'name' => $community['name'],
                        ];
                    }
                }
            } elseif (isset($data['status']) && $data['status'] === 'unauthorized') {
                error_log('Unauthorized Circle v1 token.');
                $community_options[] = [
                    'id' => '',
                    'name' => 'Failed to fetch communities. Please check the Circle v1 API token.',
                ];
            }
        } else {
            error_log('Community fetch error: ' . $response->get_error_message());
        }
    } else {
        $community_options[] = [
            'id' => '',
            'name' => 'Please save the Circle API token to fetch communities.',
        ];
    }

    // Fetch space groups (uses v1)
    $space_group_options = [];
    if (!empty($circle_token_v1) && !empty($community_id)) {
        $response = wp_remote_get("https://app.circle.so/api/v1/space_groups?community_id={$community_id}", [
            'headers' => [
                'Authorization' => 'Token ' . $circle_token_v1,
                'Content-Type'  => 'application/json',
            ]
        ]);

        if (!is_wp_error($response)) {
            $groups_data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($groups_data)) {
                foreach ($groups_data as $group) {
                    $space_group_options[] = [
                        'id' => $group['id'],
                        'name' => $group['name'],
                    ];
                }
            }
        }
    }

    ?>
    <div class="wrap">
        <h1>Integration Settings</h1>

        <!-- SCHEDULE FORM -->
        <form method="post">
            <?php wp_nonce_field('igm_save_settings', 'igm_settings_nonce'); ?>
            <h2>Schedule Settings</h2>
            <table class="form-table">
                <tr>
                    <th>Update Interval</th>
                    <td>
                        <select name="igm_update_cron_interval">
                            <?php foreach (igm_get_cron_options(true) as $val => $label): ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected($update_interval, $val); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
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

        <!-- API KEYS FORM -->
        <form method="post" style="margin-top: 30px;">
            <?php wp_nonce_field('igm_save_settings', 'igm_settings_nonce'); ?>
            <h2>API Credentials</h2>
            <table class="form-table">
                <tr>
                    <th>Circle API Token (v1)</th>
                    <td><input type="text" name="igm_circle_api_token_v1" value="<?php echo esc_attr($masked_token_v1); ?>" class="regular-text" onfocus="clearIfMasked(this)" /></td>
                </tr>
                <tr>
                    <th>Circle API Token (v2)</th>
                    <td><input type="text" name="igm_circle_api_token_v2" value="<?php echo esc_attr($masked_token_v2); ?>" class="regular-text" onfocus="clearIfMasked(this)" /></td>
                </tr>
                <tr>
                    <th>Teachable API Key</th>
                    <td><input type="text" name="igm_teachable_api_key" value="<?php echo esc_attr($masked_key); ?>" class="regular-text" onfocus="clearIfMasked(this)" /></td>
                </tr>
            </table>
            <?php submit_button('Save Keys', 'secondary', 'save_keys'); ?>
        </form>

        <!-- COMMUNITY SELECT FORM -->
        <form method="post" style="margin-top: 30px;">
            <?php wp_nonce_field('igm_save_settings', 'igm_settings_nonce'); ?>
            <h2>Select Circle Community</h2>
            <table class="form-table">
                <tr>
                    <th>Community</th>
                    <td>
                        <select name="igm_circle_community_id">
                            <?php foreach ($community_options as $comm): ?>
                                <option value="<?php echo esc_attr($comm['id']); ?>" <?php selected($community_id, $comm['id']); ?>>
                                    <?php echo esc_html($comm['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Community', 'secondary', 'save_community'); ?>
        </form>

        <!-- GROUP SELECT FORM -->
        <form method="post" style="margin-top: 30px;">
            <?php wp_nonce_field('igm_save_settings', 'igm_settings_nonce'); ?>
            <h2>Select Default Space Group</h2>
            <table class="form-table">
                <tr>
                    <th>Space Group</th>
                    <td>
                        <select name="igm_circle_space_group_id">
                            <option value="">— Select a Group —</option>
                            <?php foreach ($space_group_options as $group): ?>
                                <option value="<?php echo esc_attr($group['id']); ?>" <?php selected($saved_space_group_id, $group['id']); ?>>
                                    <?php echo esc_html($group['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($space_group_options)): ?>
                            <p style="color: #a00;">No groups found. Ensure API token and community are saved.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Group', 'secondary', 'save_group'); ?>
        </form>

        <script>
        function clearIfMasked(input) {
            if (input.value.includes('*')) {
                input.value = '';
            }
        }
        </script>
    </div>
    <?php
}



/**
 * Mask API keys – keeps only last 4 characters.
 */
function mask_api_key($key) {
    $len = strlen($key);
    if ($len <= 4) return str_repeat('*', $len);
    return str_repeat('*', $len - 4) . substr($key, -4);
}



// === Define Available Cron Options ===
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




if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class IGM_Logs_List_Table extends WP_List_Table {
    private $data;

    public function __construct() {
        parent::__construct([
            'singular' => 'Log',
            'plural'   => 'Logs',
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        return [
            'action'     => 'Action',
            'message'    => 'Message',
            'created_at' => 'Time',
        ];
    }

    public function get_sortable_columns() {
        return [
            'created_at' => ['created_at', true],
        ];
    }

    public function column_default($item, $column_name) {
        return esc_html($item[$column_name]);
    }

    public function prepare_items() {
        global $wpdb;

        $table = $wpdb->prefix . 'teachable_circle_logs';

        // Set columns
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
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
        $query = $wpdb->prepare("SELECT * FROM $table ORDER BY $orderby $order LIMIT %d OFFSET %d", $per_page, $offset);
        $this->items = $wpdb->get_results($query, ARRAY_A);

        // Set pagination args
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }
}
function igm_render_logs_page() {
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
    if ($option === 'logs_per_page') return (int) $value;
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
            'option'  => 'logs_per_page'
        ]);
    });
});
