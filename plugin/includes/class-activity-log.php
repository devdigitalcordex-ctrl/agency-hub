<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Agency_Hub_Activity_Log' ) ) :

class Agency_Hub_Activity_Log {

    public static function init() {
        // --- USER & AUTH EVENTS ---
        add_action( 'wp_login',               array( __CLASS__, 'on_login' ), 10, 2 );
        add_action( 'wp_logout',              array( __CLASS__, 'on_logout' ) );
        add_action( 'wp_login_failed',        array( __CLASS__, 'on_login_failed' ) );
        add_action( 'retrieve_password',      array( __CLASS__, 'on_password_reset_request' ) );
        add_action( 'after_password_reset',   array( __CLASS__, 'on_password_reset' ), 10, 2 );
        add_action( 'user_register',          array( __CLASS__, 'on_user_registered' ) );
        add_action( 'delete_user',            array( __CLASS__, 'on_user_deleted' ), 10, 2 );
        add_action( 'profile_update',         array( __CLASS__, 'on_profile_updated' ), 10, 2 );
        add_action( 'set_user_role',          array( __CLASS__, 'on_role_changed' ), 10, 3 );
        add_action( 'add_user_to_blog',       array( __CLASS__, 'on_user_added_to_site' ), 10, 3 );
        add_action( 'remove_user_from_blog',  array( __CLASS__, 'on_user_removed_from_site' ), 10, 2 );

        // --- POST / PAGE / CPT EVENTS ---
        add_action( 'save_post',              array( __CLASS__, 'on_post_saved' ), 10, 3 );
        add_action( 'delete_post',            array( __CLASS__, 'on_post_deleted' ) );
        add_action( 'trashed_post',           array( __CLASS__, 'on_post_trashed' ) );
        add_action( 'untrashed_post',         array( __CLASS__, 'on_post_untrashed' ) );
        add_action( 'post_updated',           array( __CLASS__, 'on_post_updated' ), 10, 3 );

        // --- PLUGINS & THEMES ---
        add_action( 'activated_plugin',       array( __CLASS__, 'on_plugin_activated' ) );
        add_action( 'deactivated_plugin',     array( __CLASS__, 'on_plugin_deactivated' ) );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade_complete' ), 10, 2 );
        add_action( 'deleted_plugin',         array( __CLASS__, 'on_plugin_deleted' ), 10, 2 );
        add_action( 'switch_theme',           array( __CLASS__, 'on_theme_switched' ), 10, 3 );
        add_action( 'delete_theme',           array( __CLASS__, 'on_theme_deleted' ) );

        // --- SETTINGS & OPTIONS ---
        add_action( 'updated_option',         array( __CLASS__, 'on_option_updated' ), 10, 3 );
        add_action( 'added_option',           array( __CLASS__, 'on_option_added' ), 10, 2 );

        // --- MENUS & WIDGETS ---
        add_action( 'wp_update_nav_menu',     array( __CLASS__, 'on_menu_updated' ) );
        add_action( 'wp_delete_nav_menu',     array( __CLASS__, 'on_menu_deleted' ) );

        // --- WORDPRESS CORE ---
        add_action( '_core_updated_successfully', array( __CLASS__, 'on_core_updated' ) );

        // --- TAXONOMY ---
        add_action( 'created_term',           array( __CLASS__, 'on_term_created' ), 10, 3 );
        add_action( 'edited_term',            array( __CLASS__, 'on_term_edited' ), 10, 3 );
        add_action( 'delete_term',            array( __CLASS__, 'on_term_deleted' ), 10, 3 );

        // --- FILE EDITS (built-in WP editor) ---
        add_action( 'wp_ajax_edit-theme-plugin-file', array( __CLASS__, 'on_file_edit_attempt' ), 1 );

        // --- BRUTE FORCE THRESHOLD ---
        add_action( 'agency_hub_login_failed_threshold', array( __CLASS__, 'on_brute_force_detected' ) );

        // REST API sync endpoint
        // (Already registered in main class, handled by sync_logs method below)
    }

    // --------------------------------------------------------
    // REST: SYNC LOGS (Hub fetches unsent logs)
    // --------------------------------------------------------

    public static function sync_logs( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . AGENCY_HUB_LOG_TABLE;
        $limit = intval( $request->get_param( 'limit' ) ) ?: 200;

        $logs = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE synced_at IS NULL ORDER BY occurred_at ASC LIMIT %d", $limit ),
            ARRAY_A
        );

        if ( ! empty( $logs ) ) {
            $ids          = array_column( $logs, 'id' );
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET synced_at = %s WHERE id IN ({$placeholders})",
                    array_merge( array( current_time( 'mysql' ) ), $ids )
                )
            );
        }

        return rest_ensure_response( array(
            'logs'  => $logs,
            'count' => count( $logs ),
        ) );
    }

    // --------------------------------------------------------
    // AUTH EVENTS
    // --------------------------------------------------------

    public static function on_login( $user_login, $user ) {
        self::record( array(
            'event_type'     => 'user_login',
            'event_category' => 'user',
            'severity'       => 'info',
            'user_login'     => $user_login,
            'user_role'      => implode( ', ', (array) $user->roles ),
            'object_type'    => 'user',
            'object_id'      => $user->ID,
            'object_name'    => $user_login,
            'message'        => "User '{$user_login}' logged in.",
        ) );
    }

    public static function on_logout() {
        $user = wp_get_current_user();
        if ( $user->ID ) {
            self::record( array(
                'event_type'     => 'user_logout',
                'event_category' => 'user',
                'severity'       => 'info',
                'user_login'     => $user->user_login,
                'user_role'      => implode( ', ', (array) $user->roles ),
                'object_type'    => 'user',
                'object_id'      => $user->ID,
                'message'        => "User '{$user->user_login}' logged out.",
            ) );
        }
    }

    public static function on_login_failed( $username ) {
        $ip     = Agency_Hub::get_current_ip();
        $count  = self::increment_fail_count( $ip );
        $threshold = 5;

        self::record( array(
            'event_type'     => 'login_failed',
            'event_category' => 'user',
            'severity'       => $count >= $threshold ? 'high' : 'medium',
            'user_login'     => sanitize_user( $username ),
            'user_ip'        => $ip,
            'message'        => "Failed login attempt for '{$username}'. Attempt #{$count} from {$ip}.",
            'is_flagged'     => $count >= $threshold ? 1 : 0,
        ) );

        if ( $count >= $threshold ) {
            do_action( 'agency_hub_login_failed_threshold', $ip, $username, $count );
        }
    }

    public static function on_brute_force_detected( $ip, $username, $count ) {
        Agency_Hub_Heartbeat::push_critical_alert( array(
            'alert_type'  => 'brute_force',
            'severity'    => 'high',
            'title'       => 'Brute Force Attack Detected',
            'description' => "{$count} failed login attempts from IP {$ip} targeting username '{$username}'.",
            'user_ip'     => $ip,
        ) );
    }

    public static function on_password_reset_request( $user_login ) {
        self::record( array(
            'event_type'     => 'password_reset_request',
            'event_category' => 'user',
            'severity'       => 'medium',
            'user_login'     => $user_login,
            'message'        => "Password reset requested for '{$user_login}'.",
        ) );
    }

    public static function on_password_reset( $user, $new_pass ) {
        self::record( array(
            'event_type'     => 'password_reset',
            'event_category' => 'user',
            'severity'       => 'medium',
            'user_login'     => $user->user_login,
            'object_type'    => 'user',
            'object_id'      => $user->ID,
            'message'        => "Password was reset for '{$user->user_login}'.",
        ) );
    }

    // --------------------------------------------------------
    // USER MANAGEMENT EVENTS
    // --------------------------------------------------------

    public static function on_user_registered( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $is_admin = in_array( 'administrator', (array) $user->roles );
        $severity = $is_admin ? 'critical' : 'medium';

        self::record( array(
            'event_type'     => 'user_created',
            'event_category' => 'user',
            'severity'       => $severity,
            'user_login'     => $user->user_login,
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'object_name'    => $user->display_name,
            'message'        => "New user '{$user->user_login}' registered with role: " . implode( ', ', (array) $user->roles ),
            'is_flagged'     => $is_admin ? 1 : 0,
        ) );

        if ( $is_admin ) {
            Agency_Hub_Heartbeat::push_critical_alert( array(
                'alert_type'  => 'new_admin_user',
                'severity'    => 'critical',
                'title'       => 'New Administrator Account Created',
                'description' => "A new administrator account '{$user->user_login}' was created.",
                'user_ip'     => Agency_Hub::get_current_ip(),
            ) );
        }
    }

    public static function on_user_deleted( $user_id, $reassign ) {
        $user = get_userdata( $user_id );
        self::record( array(
            'event_type'     => 'user_deleted',
            'event_category' => 'user',
            'severity'       => 'high',
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'object_name'    => $user ? $user->user_login : "ID:{$user_id}",
            'message'        => "User account deleted. ID: {$user_id}.",
        ) );
    }

    public static function on_profile_updated( $user_id, $old_user_data ) {
        $new_user = get_userdata( $user_id );
        if ( ! $new_user ) return;

        $changes = array();
        if ( $old_user_data->user_email !== $new_user->user_email ) {
            $changes[] = "email changed from '{$old_user_data->user_email}' to '{$new_user->user_email}'";
        }
        if ( $old_user_data->display_name !== $new_user->display_name ) {
            $changes[] = "display name changed";
        }

        if ( empty( $changes ) ) return;

        $email_changed = in_array( true, array_map( fn($c) => strpos($c,'email') !== false, $changes ) );

        self::record( array(
            'event_type'     => 'profile_updated',
            'event_category' => 'user',
            'severity'       => $email_changed ? 'high' : 'medium',
            'user_login'     => $new_user->user_login,
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'message'        => "Profile updated for '{$new_user->user_login}': " . implode( ', ', $changes ),
            'is_flagged'     => $email_changed ? 1 : 0,
        ) );
    }

    public static function on_role_changed( $user_id, $role, $old_roles ) {
        $user = get_userdata( $user_id );
        $old  = implode( ', ', $old_roles );
        $is_admin = ( 'administrator' === $role );

        self::record( array(
            'event_type'     => 'user_role_changed',
            'event_category' => 'user',
            'severity'       => $is_admin ? 'critical' : 'high',
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'object_name'    => $user ? $user->user_login : "ID:{$user_id}",
            'message'        => "User role changed from '{$old}' to '{$role}'.",
            'before_value'   => array( 'roles' => $old_roles ),
            'after_value'    => array( 'role' => $role ),
            'is_flagged'     => $is_admin ? 1 : 0,
        ) );

        if ( $is_admin ) {
            Agency_Hub_Heartbeat::push_critical_alert( array(
                'alert_type'  => 'privilege_escalation',
                'severity'    => 'critical',
                'title'       => 'User Promoted to Administrator',
                'description' => "User '{$user->user_login}' was promoted to administrator role.",
            ) );
        }
    }

    public static function on_user_added_to_site( $user_id, $role, $blog_id ) {
        $user = get_userdata( $user_id );
        self::record( array(
            'event_type'     => 'user_added_to_site',
            'event_category' => 'user',
            'severity'       => 'medium',
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'object_name'    => $user ? $user->user_login : "ID:{$user_id}",
            'message'        => "User added to site with role '{$role}'.",
        ) );
    }

    public static function on_user_removed_from_site( $user_id, $blog_id ) {
        $user = get_userdata( $user_id );
        self::record( array(
            'event_type'     => 'user_removed_from_site',
            'event_category' => 'user',
            'severity'       => 'medium',
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'object_name'    => $user ? $user->user_login : "ID:{$user_id}",
            'message'        => "User removed from site.",
        ) );
    }

    // --------------------------------------------------------
    // POST EVENTS
    // --------------------------------------------------------

    public static function on_post_saved( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $user = wp_get_current_user();

        self::record( array(
            'event_type'     => $update ? 'post_updated' : 'post_created',
            'event_category' => 'content',
            'severity'       => 'info',
            'user_login'     => $user->user_login,
            'user_role'      => implode( ', ', (array) $user->roles ),
            'object_type'    => $post->post_type,
            'object_id'      => $post_id,
            'object_name'    => $post->post_title,
            'message'        => ( $update ? 'Updated' : 'Created' ) . " {$post->post_type}: '{$post->post_title}' (status: {$post->post_status})",
        ) );
    }

    public static function on_post_deleted( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;
        $user = wp_get_current_user();

        self::record( array(
            'event_type'     => 'post_deleted',
            'event_category' => 'content',
            'severity'       => 'medium',
            'user_login'     => $user->user_login,
            'object_type'    => $post->post_type,
            'object_id'      => $post_id,
            'object_name'    => $post->post_title,
            'message'        => "Permanently deleted {$post->post_type}: '{$post->post_title}'.",
        ) );
    }

    public static function on_post_trashed( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;
        $user = wp_get_current_user();

        self::record( array(
            'event_type'     => 'post_trashed',
            'event_category' => 'content',
            'severity'       => 'low',
            'user_login'     => $user->user_login,
            'object_type'    => $post->post_type,
            'object_id'      => $post_id,
            'object_name'    => $post->post_title,
            'message'        => "Moved to trash: '{$post->post_title}'.",
        ) );
    }

    public static function on_post_untrashed( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;
        $user = wp_get_current_user();

        self::record( array(
            'event_type'     => 'post_untrashed',
            'event_category' => 'content',
            'severity'       => 'info',
            'user_login'     => $user->user_login,
            'object_type'    => $post->post_type,
            'object_id'      => $post_id,
            'object_name'    => $post->post_title,
            'message'        => "Restored from trash: '{$post->post_title}'.",
        ) );
    }

    public static function on_post_updated( $post_id, $post_after, $post_before ) {
        // Captured by on_post_saved — no duplicate needed
    }

    // --------------------------------------------------------
    // PLUGIN EVENTS
    // --------------------------------------------------------

    public static function on_plugin_activated( $plugin ) {
        $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
        $user = wp_get_current_user();

        self::record( array(
            'event_type'     => 'plugin_activated',
            'event_category' => 'plugin',
            'severity'       => 'medium',
            'user_login'     => $user->user_login,
            'object_type'    => 'plugin',
            'object_id'      => $plugin,
            'object_name'    => $data['Name'] ?? $plugin,
            'message'        => "Plugin activated: '{$data['Name']}' v{$data['Version']}.",
        ) );
    }

    public static function on_plugin_deactivated( $plugin ) {
        $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
        $user = wp_get_current_user();

        self::record( array(
            'event_type'     => 'plugin_deactivated',
            'event_category' => 'plugin',
            'severity'       => 'medium',
            'user_login'     => $user->user_login,
            'object_type'    => 'plugin',
            'object_id'      => $plugin,
            'object_name'    => $data['Name'] ?? $plugin,
            'message'        => "Plugin deactivated: '{$data['Name']}'.",
        ) );
    }

    public static function on_plugin_deleted( $plugin, $deleted ) {
        $user = wp_get_current_user();

        self::record( array(
            'event_type'     => 'plugin_deleted',
            'event_category' => 'plugin',
            'severity'       => 'high',
            'user_login'     => $user->user_login,
            'object_type'    => 'plugin',
            'object_id'      => $plugin,
            'object_name'    => $plugin,
            'message'        => "Plugin deleted: '{$plugin}'. Success: " . ( $deleted ? 'yes' : 'no' ),
        ) );
    }

    public static function on_upgrade_complete( $upgrader, $options ) {
        $user = wp_get_current_user();
        $type = $options['type'] ?? '';
        $action = $options['action'] ?? '';

        if ( 'update' !== $action ) return;

        $items = $options['plugins'] ?? $options['themes'] ?? array();
        foreach ( (array) $items as $item ) {
            self::record( array(
                'event_type'     => $type . '_updated',
                'event_category' => $type,
                'severity'       => 'info',
                'user_login'     => $user->user_login,
                'object_type'    => $type,
                'object_id'      => $item,
                'object_name'    => $item,
                'message'        => ucfirst( $type ) . " updated: '{$item}'.",
            ) );
        }
    }

    // --------------------------------------------------------
    // THEME EVENTS
    // --------------------------------------------------------

    public static function on_theme_switched( $new_name, $new_theme, $old_theme ) {
        $user = wp_get_current_user();
        self::record( array(
            'event_type'     => 'theme_switched',
            'event_category' => 'theme',
            'severity'       => 'high',
            'user_login'     => $user->user_login,
            'object_type'    => 'theme',
            'object_name'    => $new_name,
            'message'        => "Theme switched from '{$old_theme->get('Name')}' to '{$new_name}'.",
            'before_value'   => array( 'theme' => $old_theme->get('Name') ),
            'after_value'    => array( 'theme' => $new_name ),
        ) );
    }

    public static function on_theme_deleted( $stylesheet ) {
        $user = wp_get_current_user();
        self::record( array(
            'event_type'     => 'theme_deleted',
            'event_category' => 'theme',
            'severity'       => 'medium',
            'user_login'     => $user->user_login,
            'object_type'    => 'theme',
            'object_id'      => $stylesheet,
            'object_name'    => $stylesheet,
            'message'        => "Theme deleted: '{$stylesheet}'.",
        ) );
    }

    // --------------------------------------------------------
    // SETTINGS EVENTS
    // Options to monitor (sensitive ones flagged)
    // --------------------------------------------------------

    private static $monitored_options = array(
        'siteurl', 'blogname', 'admin_email', 'blogdescription',
        'users_can_register', 'default_role', 'permalink_structure',
        'upload_path', 'active_plugins', 'template',
        'wp_user_roles', 'auth_key', 'secure_auth_key',
    );

    public static function on_option_updated( $option, $old_value, $new_value ) {
        if ( ! in_array( $option, self::$monitored_options ) ) return;
        if ( $old_value === $new_value ) return;

        $is_sensitive = in_array( $option, array( 'siteurl', 'admin_email', 'auth_key', 'secure_auth_key', 'users_can_register' ) );
        $user = wp_get_current_user();

        self::record( array(
            'event_type'     => 'option_updated',
            'event_category' => 'settings',
            'severity'       => $is_sensitive ? 'high' : 'medium',
            'user_login'     => $user->user_login,
            'object_type'    => 'option',
            'object_name'    => $option,
            'message'        => "WordPress option '{$option}' was changed.",
            'before_value'   => is_scalar( $old_value ) ? array( 'value' => $old_value ) : null,
            'after_value'    => is_scalar( $new_value ) ? array( 'value' => $new_value ) : null,
            'is_flagged'     => $is_sensitive ? 1 : 0,
        ) );

        if ( 'siteurl' === $option ) {
            Agency_Hub_Heartbeat::push_critical_alert( array(
                'alert_type'  => 'site_url_changed',
                'severity'    => 'critical',
                'title'       => 'Site URL Changed',
                'description' => "Site URL changed from '{$old_value}' to '{$new_value}'.",
            ) );
        }
    }

    public static function on_option_added( $option, $value ) {
        // Only log known sensitive additions
    }

    // --------------------------------------------------------
    // MENU EVENTS
    // --------------------------------------------------------

    public static function on_menu_updated( $menu_id ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        $user = wp_get_current_user();
        self::record( array(
            'event_type'     => 'menu_updated',
            'event_category' => 'settings',
            'severity'       => 'info',
            'user_login'     => $user->user_login,
            'object_type'    => 'menu',
            'object_id'      => $menu_id,
            'object_name'    => $menu ? $menu->name : "ID:{$menu_id}",
            'message'        => "Navigation menu updated: '{$menu->name}'.",
        ) );
    }

    public static function on_menu_deleted( $menu_id ) {
        $user = wp_get_current_user();
        self::record( array(
            'event_type'     => 'menu_deleted',
            'event_category' => 'settings',
            'severity'       => 'medium',
            'user_login'     => $user->user_login,
            'object_type'    => 'menu',
            'object_id'      => $menu_id,
            'message'        => "Navigation menu deleted. ID: {$menu_id}.",
        ) );
    }

    // --------------------------------------------------------
    // CORE UPDATE
    // --------------------------------------------------------

    public static function on_core_updated( $wp_version ) {
        self::record( array(
            'event_type'     => 'core_updated',
            'event_category' => 'core',
            'severity'       => 'info',
            'object_type'    => 'wordpress',
            'object_name'    => 'WordPress Core',
            'message'        => "WordPress core updated to version {$wp_version}.",
        ) );
    }

    // --------------------------------------------------------
    // TAXONOMY
    // --------------------------------------------------------

    public static function on_term_created( $term_id, $tt_id, $taxonomy ) {
        $term = get_term( $term_id, $taxonomy );
        $user = wp_get_current_user();
        self::record( array(
            'event_type'     => 'term_created',
            'event_category' => 'content',
            'severity'       => 'info',
            'user_login'     => $user->user_login,
            'object_type'    => $taxonomy,
            'object_id'      => $term_id,
            'object_name'    => $term ? $term->name : "ID:{$term_id}",
            'message'        => "Term '{$term->name}' created in taxonomy '{$taxonomy}'.",
        ) );
    }

    public static function on_term_edited( $term_id, $tt_id, $taxonomy ) {
        $term = get_term( $term_id, $taxonomy );
        $user = wp_get_current_user();
        self::record( array(
            'event_type'     => 'term_edited',
            'event_category' => 'content',
            'severity'       => 'info',
            'user_login'     => $user->user_login,
            'object_type'    => $taxonomy,
            'object_id'      => $term_id,
            'object_name'    => $term ? $term->name : "ID:{$term_id}",
            'message'        => "Term '{$term->name}' edited in taxonomy '{$taxonomy}'.",
        ) );
    }

    public static function on_term_deleted( $term_id, $tt_id, $taxonomy ) {
        $user = wp_get_current_user();
        self::record( array(
            'event_type'     => 'term_deleted',
            'event_category' => 'content',
            'severity'       => 'medium',
            'user_login'     => $user->user_login,
            'object_type'    => $taxonomy,
            'object_id'      => $term_id,
            'message'        => "Term ID:{$term_id} deleted from taxonomy '{$taxonomy}'.",
        ) );
    }

    // --------------------------------------------------------
    // FILE EDIT ATTEMPT (WP built-in editor)
    // --------------------------------------------------------

    public static function on_file_edit_attempt() {
        $file = sanitize_text_field( $_POST['file'] ?? '' );
        $type = sanitize_text_field( $_POST['type'] ?? '' );
        $user = wp_get_current_user();

        self::record( array(
            'event_type'     => 'file_edit_via_wp_editor',
            'event_category' => 'file',
            'severity'       => 'high',
            'user_login'     => $user->user_login,
            'object_type'    => 'file',
            'object_name'    => $file,
            'message'        => "File edited via WordPress editor: '{$file}' (type: {$type}).",
            'is_flagged'     => 1,
        ) );
    }

    // --------------------------------------------------------
    // BRUTE FORCE COUNTER
    // --------------------------------------------------------

    private static function increment_fail_count( $ip ) {
        $key     = 'ah_fail_' . md5( $ip );
        $count   = (int) get_transient( $key );
        $count++;
        set_transient( $key, $count, 30 * MINUTE_IN_SECONDS );
        return $count;
    }

    // --------------------------------------------------------
    // CORE RECORD METHOD
    // --------------------------------------------------------

    private static function record( $data ) {
        $data['user_ip']    = $data['user_ip'] ?? Agency_Hub::get_current_ip();
        $data['user_agent'] = $data['user_agent'] ?? ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
        Agency_Hub::log_event( $data );
    }
}

endif;
