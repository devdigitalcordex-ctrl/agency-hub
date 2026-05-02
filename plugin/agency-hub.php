<?php
/**
 * Plugin Name: Agency Hub
 * Plugin URI:  https://digitalcordex.com
 * Description: Connects this WordPress site to the Agency Hub dashboard for monitoring, security, backups, and management.
 * Version:     1.3.0
 * Author:      Digital Cordex
 * Author URI:  https://digitalcordex.com
 * License:     GPL-2.0+
 * Text Domain: agency-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// CONSTANTS
// ============================================================

define( 'AGENCY_HUB_VERSION',     '1.3.0' );
define( 'AGENCY_HUB_FILE',        __FILE__ );
define( 'AGENCY_HUB_DIR',         plugin_dir_path( __FILE__ ) );
define( 'AGENCY_HUB_URL',         plugin_dir_url( __FILE__ ) );
define( 'AGENCY_HUB_OPTION_KEY',  'agency_hub_settings' );
define( 'AGENCY_HUB_LOG_TABLE',   'agency_hub_logs' );
define( 'AGENCY_HUB_BACKUP_DIR',  WP_CONTENT_DIR . '/agency-hub-backups' );

// ============================================================
// LOAD FILES
// ============================================================

require_once AGENCY_HUB_DIR . 'includes/class-api.php';
require_once AGENCY_HUB_DIR . 'includes/class-updater.php';
add_action( "init", function() { new Agency_Hub_Updater(); } );
require_once AGENCY_HUB_DIR . 'includes/class-heartbeat.php';
require_once AGENCY_HUB_DIR . 'includes/class-activity-log.php';
require_once AGENCY_HUB_DIR . 'includes/class-scanner.php';
require_once AGENCY_HUB_DIR . 'includes/class-2fa.php';
require_once AGENCY_HUB_DIR . 'includes/class-backup.php';
require_once AGENCY_HUB_DIR . 'includes/class-file-manager.php';
require_once AGENCY_HUB_DIR . 'includes/class-ip-blocker.php';

// ============================================================
// MAIN CLASS
// ============================================================

if ( ! class_exists( 'Agency_Hub' ) ) :

class Agency_Hub {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {

        // Admin menu and settings page
        add_action( 'admin_menu',            array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Plugin activation / deactivation
        register_activation_hook(   AGENCY_HUB_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( AGENCY_HUB_FILE, array( $this, 'deactivate' ) );

        // Boot all modules
        Agency_Hub_IP_Blocker::init();
        Agency_Hub_Activity_Log::init();
        Agency_Hub_Scanner::init();
        Agency_Hub_2FA::init();
        Agency_Hub_Heartbeat::init();
        Agency_Hub_Backup::init();
        Agency_Hub_File_Manager::init();

        // REST API routes for Hub communication
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Cron events
        add_action( 'agency_hub_scheduled_scan',      array( 'Agency_Hub_Scanner', 'run_scan' ) );
        add_action( 'agency_hub_scheduled_heartbeat', array( 'Agency_Hub_Heartbeat', 'send' ) );
		add_action( 'agency_hub_run_scan_bg', array( 'Agency_Hub_Heartbeat', 'run_scan_background' ) );
                add_action( 'agency_hub_run_backup_bg', array( 'Agency_Hub_Heartbeat', 'run_backup_background' ) );
             add_action( 'agency_hub_run_backup_bg', array( 'Agency_Hub_Heartbeat', 'run_backup_background' ) );
    }

    // --------------------------------------------------------
    // ACTIVATION
    // --------------------------------------------------------

    public function activate() {
        $this->create_db_tables();
        $this->generate_api_credentials();
        $this->create_backup_directory();
        $this->schedule_cron_events();
        flush_rewrite_rules();
    }

    private function create_db_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Activity log table
        $log_table = $wpdb->prefix . AGENCY_HUB_LOG_TABLE;
        $sql = "CREATE TABLE IF NOT EXISTS {$log_table} (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type  VARCHAR(100) NOT NULL,
            event_category VARCHAR(50),
            severity    VARCHAR(50) DEFAULT 'info',
            user_login  VARCHAR(255),
            user_role   VARCHAR(100),
            user_ip     VARCHAR(45),
            user_agent  TEXT,
            object_type VARCHAR(100),
            object_id   VARCHAR(255),
            object_name VARCHAR(255),
            message     TEXT,
            before_value LONGTEXT,
            after_value  LONGTEXT,
            is_flagged  TINYINT(1) DEFAULT 0,
            occurred_at DATETIME NOT NULL,
            synced_at   DATETIME,
            INDEX idx_event_type (event_type),
            INDEX idx_occurred_at (occurred_at),
            INDEX idx_user_ip (user_ip),
            INDEX idx_severity (severity)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Pending commands table (commands from Hub waiting to execute)
        $cmd_table = $wpdb->prefix . 'agency_hub_commands';
        $sql2 = "CREATE TABLE IF NOT EXISTS {$cmd_table} (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            command_id   VARCHAR(64) UNIQUE NOT NULL,
            command_type VARCHAR(100) NOT NULL,
            payload      LONGTEXT,
            status       VARCHAR(50) DEFAULT 'pending',
            received_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            executed_at  DATETIME,
            result       LONGTEXT
        ) {$charset};";

        dbDelta( $sql2 );

        // Quarantine log table
        $quarantine_table = $wpdb->prefix . 'agency_hub_quarantine';
        $sql3 = "CREATE TABLE IF NOT EXISTS {$quarantine_table} (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            original_path   TEXT NOT NULL,
            quarantine_path TEXT NOT NULL,
            file_hash       VARCHAR(64),
            threat_name     VARCHAR(255),
            quarantined_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            restore_deadline DATETIME,
            restored_at     DATETIME,
            deleted_at      DATETIME
        ) {$charset};";

        dbDelta( $sql3 );

        // 2FA table
        $twofa_table = $wpdb->prefix . 'agency_hub_2fa';
        $sql4 = "CREATE TABLE IF NOT EXISTS {$twofa_table} (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id         BIGINT UNSIGNED NOT NULL,
            secret          VARCHAR(255) NOT NULL,
            recovery_codes  LONGTEXT,
            bypass_code     VARCHAR(255),
            bypass_expires  DATETIME,
            enabled_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY user_id (user_id)
        ) {$charset};";

        dbDelta( $sql4 );

        // Blocklist table
        $block_table = $wpdb->prefix . 'agency_hub_blocklist';
        $sql5 = "CREATE TABLE IF NOT EXISTS {$block_table} (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            block_type  VARCHAR(50) NOT NULL,
            value       VARCHAR(255) NOT NULL,
            reason      TEXT,
            expires_at  DATETIME,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_value (value),
            INDEX idx_block_type (block_type)
        ) {$charset};";

        dbDelta( $sql5 );
    }

    private function generate_api_credentials() {
        $settings = get_option( AGENCY_HUB_OPTION_KEY, array() );

        // Initialize with empty settings if first activation
        if ( ! isset( $settings['site_key'] ) ) {
            $settings['site_key']    = '';
            $settings['hub_url']     = '';
            $settings['connected']   = false;
            update_option( AGENCY_HUB_OPTION_KEY, $settings );
        }
    }

    private function create_backup_directory() {
        if ( ! file_exists( AGENCY_HUB_BACKUP_DIR ) ) {
            wp_mkdir_p( AGENCY_HUB_BACKUP_DIR );
        }

        // Protect directory from public access
        $htaccess = AGENCY_HUB_BACKUP_DIR . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" );
        }

        // Add index.php for extra protection
        $index = AGENCY_HUB_BACKUP_DIR . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden\n" );
        }
    }

    private function schedule_cron_events() {
        if ( ! wp_next_scheduled( 'agency_hub_scheduled_heartbeat' ) ) {
            wp_schedule_event( time(), 'five_minutes', 'agency_hub_scheduled_heartbeat' );
        }
    }

    // --------------------------------------------------------
    // DEACTIVATION
    // --------------------------------------------------------

    public function deactivate() {
        wp_clear_scheduled_hook( 'agency_hub_scheduled_scan' );
        wp_clear_scheduled_hook( 'agency_hub_scheduled_heartbeat' );
        flush_rewrite_rules();
    }

    // --------------------------------------------------------
    // ADMIN MENU
    // --------------------------------------------------------

    public function register_admin_menu() {
        add_menu_page(
            'Agency Hub',
            'Agency Hub',
            'manage_options',
            'agency-hub',
            array( $this, 'render_admin_page' ),
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#5185C8"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>' ),
            30
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_agency-hub' !== $hook ) {
            return;
        }

        // Outfit font from Google
        wp_enqueue_style(
            'agency-hub-font',
            'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
            array(),
            null
        );

        wp_enqueue_style(
            'agency-hub-admin',
            AGENCY_HUB_URL . 'assets/css/admin.css',
            array( 'agency-hub-font' ),
            AGENCY_HUB_VERSION
        );

        wp_enqueue_script(
            'agency-hub-admin',
            AGENCY_HUB_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            AGENCY_HUB_VERSION,
            true
        );

        wp_localize_script( 'agency-hub-admin', 'agencyHub', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'agency_hub_nonce' ),
            'siteUrl' => get_site_url(),
        ) );
    }

    // --------------------------------------------------------
    // ADMIN PAGE RENDER
    // --------------------------------------------------------

    public function render_admin_page() {
        $settings = get_option( AGENCY_HUB_OPTION_KEY, array() );
        require_once AGENCY_HUB_DIR . 'admin/admin-page.php';
    }

    // --------------------------------------------------------
    // REST API ROUTES
    // --------------------------------------------------------

    public function register_rest_routes() {
        $namespace = 'agency-hub/v1';

        // Heartbeat / status
        register_rest_route( $namespace, '/heartbeat', array(
            'methods'             => 'POST',
            'callback'            => array( 'Agency_Hub_Heartbeat', 'handle_request' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // Commands poll
        register_rest_route( $namespace, '/commands/pending', array(
            'methods'             => 'GET',
            'callback'            => array( 'Agency_Hub_API', 'get_pending_commands' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // Command result
        register_rest_route( $namespace, '/commands/result', array(
            'methods'             => 'POST',
            'callback'            => array( 'Agency_Hub_API', 'receive_command_result' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // Scan trigger
        register_rest_route( $namespace, '/scan/run', array(
            'methods'             => 'POST',
            'callback'            => array( 'Agency_Hub_Scanner', 'handle_scan_request' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // Backup trigger
        register_rest_route( $namespace, '/backup/run', array(
            'methods'             => 'POST',
            'callback'            => array( 'Agency_Hub_Backup', 'handle_backup_request' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // Backup download
        register_rest_route( $namespace, '/backup/download', array(
            'methods'             => 'GET',
            'callback'            => array( 'Agency_Hub_Backup', 'handle_download_request' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_download_token' ),
        ) );

        // File manager browse
        register_rest_route( $namespace, '/files/browse', array(
            'methods'             => 'POST',
            'callback'            => array( 'Agency_Hub_File_Manager', 'browse' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // File manager read
        register_rest_route( $namespace, '/files/read', array(
            'methods'             => 'POST',
            'callback'            => array( 'Agency_Hub_File_Manager', 'read_file' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // File manager write
        register_rest_route( $namespace, '/files/write', array(
            'methods'             => 'POST',
            'callback'            => array( 'Agency_Hub_File_Manager', 'write_file' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // File manager delete
        register_rest_route( $namespace, '/files/delete', array(
            'methods'             => 'POST',
            'callback'            => array( 'Agency_Hub_File_Manager', 'delete_file' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // Activity logs sync
        register_rest_route( $namespace, '/logs/sync', array(
            'methods'             => 'GET',
            'callback'            => array( 'Agency_Hub_Activity_Log', 'sync_logs' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // IP blocklist update
        register_rest_route( $namespace, '/blocklist/update', array(
            'methods'             => 'POST',
            'callback'            => array( 'Agency_Hub_IP_Blocker', 'update_blocklist' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        // Site info
        register_rest_route( $namespace, '/site/info', array(
            'methods'             => 'GET',
            'callback'            => array( 'Agency_Hub_API', 'get_site_info' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );
    }

    // --------------------------------------------------------
    // HELPER: GET SETTINGS
    // --------------------------------------------------------

    public static function get_settings() {
        return get_option( AGENCY_HUB_OPTION_KEY, array() );
    }

    public static function get_setting( $key, $default = null ) {
        $settings = self::get_settings();
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }

    public static function update_setting( $key, $value ) {
        $settings         = self::get_settings();
        $settings[ $key ] = $value;
        update_option( AGENCY_HUB_OPTION_KEY, $settings );
    }

    // --------------------------------------------------------
    // HELPER: CURRENT USER IP
    // --------------------------------------------------------

    public static function get_current_ip() {
        $headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $header ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    // --------------------------------------------------------
    // HELPER: LOG EVENT LOCALLY
    // --------------------------------------------------------

    public static function log_event( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . AGENCY_HUB_LOG_TABLE;

        $wpdb->insert( $table, array(
            'event_type'     => sanitize_text_field( $data['event_type'] ?? '' ),
            'event_category' => sanitize_text_field( $data['event_category'] ?? '' ),
            'severity'       => sanitize_text_field( $data['severity'] ?? 'info' ),
            'user_login'     => sanitize_text_field( $data['user_login'] ?? '' ),
            'user_role'      => sanitize_text_field( $data['user_role'] ?? '' ),
            'user_ip'        => sanitize_text_field( $data['user_ip'] ?? self::get_current_ip() ),
            'user_agent'     => sanitize_text_field( $data['user_agent'] ?? ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
            'object_type'    => sanitize_text_field( $data['object_type'] ?? '' ),
            'object_id'      => sanitize_text_field( $data['object_id'] ?? '' ),
            'object_name'    => sanitize_text_field( $data['object_name'] ?? '' ),
            'message'        => sanitize_textarea_field( $data['message'] ?? '' ),
            'before_value'   => isset( $data['before_value'] ) ? wp_json_encode( $data['before_value'] ) : null,
            'after_value'    => isset( $data['after_value'] ) ? wp_json_encode( $data['after_value'] ) : null,
            'is_flagged'     => intval( $data['is_flagged'] ?? 0 ),
            'occurred_at'    => current_time( 'mysql' ),
        ) );
    }

}

endif;

// ============================================================
// CUSTOM CRON INTERVAL (5 minutes)
// ============================================================

add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['five_minutes'] ) ) {
        $schedules['five_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes', 'agency-hub' ),
        );
    }
    return $schedules;
} );

// ============================================================
// BOOT
// ============================================================

function agency_hub() {
    return Agency_Hub::instance();
}

agency_hub();
