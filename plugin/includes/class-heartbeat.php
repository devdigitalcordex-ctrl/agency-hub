<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Agency_Hub_Heartbeat' ) ) :

class Agency_Hub_Heartbeat {

    public static function init() {
        // Heartbeat runs every 5 minutes via WP-Cron
    }

    public static function send() {
        $settings = Agency_Hub::get_settings();
        if ( empty( $settings['hub_url'] ) || empty( $settings['site_key'] ) ) return;

        $payload = self::build_payload();
        $result  = Agency_Hub_API::send_heartbeat( $payload );

        if ( $result['success'] ) {
            Agency_Hub::update_setting( 'last_heartbeat', current_time( 'mysql' ) );
            Agency_Hub::update_setting( 'connected', true );
            if ( ! empty( $result['commands'] ) ) {
                foreach ( $result['commands'] as $cmd ) {
                    self::execute_command( $cmd );
                }
            }
        } else {
            Agency_Hub::update_setting( 'connected', false );
        }
    }

    private static function build_payload() {
        global $wpdb;
        $log_table   = $wpdb->prefix . AGENCY_HUB_LOG_TABLE;
        $unsent_logs = $wpdb->get_results(
            "SELECT * FROM {$log_table} WHERE synced_at IS NULL ORDER BY occurred_at ASC LIMIT 100",
            ARRAY_A
        );
        $disk_free         = function_exists( 'disk_free_space' )  ? disk_free_space( ABSPATH )  : null;
        $disk_total        = function_exists( 'disk_total_space' ) ? disk_total_space( ABSPATH ) : null;
        $update_plugins    = get_site_transient( 'update_plugins' );
        $updates_available = ! empty( $update_plugins->response ) ? count( $update_plugins->response ) : 0;

        $payload = array(
            'site_url'       => get_site_url(),
            'plugin_version' => AGENCY_HUB_VERSION,
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => phpversion(),
            'admin_email'    => get_option( 'admin_email' ),
            'timestamp'      => time(),
            'memory_usage'   => memory_get_usage( true ),
            'memory_limit'   => ini_get( 'memory_limit' ),
            'disk_free'      => $disk_free,
            'disk_total'     => $disk_total,
            'plugin_count'   => count( get_option( 'active_plugins', array() ) ),
            'user_count'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
            'plugin_updates' => $updates_available,
            'scan_status'    => Agency_Hub::get_setting( 'last_scan_status', 'never' ),
            'last_scan_at'   => Agency_Hub::get_setting( 'last_scan_at', null ),
            'backup_status'  => Agency_Hub::get_setting( 'last_backup_status', 'never' ),
            'last_backup_at' => Agency_Hub::get_setting( 'last_backup', null ),
            'alerts'         => self::get_pending_alerts(),
            'logs'           => $unsent_logs,
        );

        if ( ! empty( $unsent_logs ) ) {
            $ids          = array_column( $unsent_logs, 'id' );
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$log_table} SET synced_at = %s WHERE id IN ({$placeholders})",
                    array_merge( array( current_time( 'mysql' ) ), $ids )
                )
            );
        }
        return $payload;
    }

    private static function get_pending_alerts() {
        $alerts = Agency_Hub::get_setting( 'pending_alerts', array() );
        if ( ! empty( $alerts ) ) {
            Agency_Hub::update_setting( 'pending_alerts', array() );
        }
        return $alerts;
    }

    public static function push_critical_alert( $alert_data ) {
        $pushed = Agency_Hub_API::push_alert( $alert_data );
        if ( ! $pushed ) {
            $pending   = Agency_Hub::get_setting( 'pending_alerts', array() );
            $pending[] = $alert_data;
            Agency_Hub::update_setting( 'pending_alerts', $pending );
        }
    }

    private static function execute_command( $cmd ) {
        $type    = $cmd['type']    ?? '';
        $payload = $cmd['payload'] ?? array();
        $cmd_id  = $cmd['id']      ?? '';
        $result  = array( 'success' => false, 'message' => 'Unknown command' );

        switch ( $type ) {
            case 'scan':
                Agency_Hub::update_setting( 'pending_scan_cmd_id', $cmd_id );
                add_action( 'shutdown', array( 'Agency_Hub_Heartbeat', 'run_scan_background' ) );
                return;

            case 'backup':
                Agency_Hub::update_setting( 'pending_backup_cmd_id', $cmd_id );
                Agency_Hub::update_setting( 'backup_progress', 0 );
                $bg_payload = $payload;
                add_action( 'shutdown', function() use ( $bg_payload ) { Agency_Hub_Heartbeat::run_backup_background( $bg_payload ); } );
                return;

            case 'block_ip':
                $result = Agency_Hub_IP_Blocker::block_ip(
                    $payload['ip']      ?? '',
                    $payload['reason']  ?? 'Blocked by Hub',
                    $payload['expires'] ?? null
                );
                break;

            case 'allowlist_ip':
                $result = Agency_Hub_IP_Blocker::add_to_allowlist(
                    $payload['ip']         ?? '',
                    $payload['label']      ?? '',
                    $payload['expires_at'] ?? null
                );
                break;

            case 'remove_ip_rule':
                if ( ( $payload['type'] ?? '' ) === 'allowlist' || ( $payload['type'] ?? '' ) === 'allowlist_cidr' ) {
                    $result = Agency_Hub_IP_Blocker::remove_from_allowlist( $payload['ip'] ?? '' );
                } else {
                    $result = Agency_Hub_IP_Blocker::unblock_ip( $payload['ip'] ?? '' );
                }
                break;

            case 'set_allowlist_mode':
                $result = Agency_Hub_IP_Blocker::set_allowlist_mode( $payload['enabled'] ?? false );
                break;

            case 'quarantine_file':
                $result = Agency_Hub_Scanner::quarantine_file( $payload['file_path'] ?? '' );
                break;

            case 'delete_file':
                $result = Agency_Hub_File_Manager::delete( $payload['file_path'] ?? '' );
                break;

            case 'disable_plugin':
                deactivate_plugins( $payload['plugin_slug'] ?? '' );
                $result = array( 'success' => true );
                break;

            case 'enable_plugin':
                $r      = activate_plugin( $payload['plugin_slug'] ?? '' );
                $result = array( 'success' => ! is_wp_error( $r ) );
                break;

            case '2fa_bypass':
                $result = Agency_Hub_2FA::create_bypass_code( $payload['user_id'] ?? 0 );
                break;

            case 'force_logout_user':
                if ( ! empty( $payload['user_id'] ) ) {
                    $sessions = WP_Session_Tokens::get_instance( $payload['user_id'] );
                    $sessions->destroy_all();
                    $result = array( 'success' => true );
                }
                break;
        }

        $pending   = Agency_Hub::get_setting( 'pending_alerts', array() );
        $pending[] = array(
            'type'       => 'command_result',
            'severity'   => 'info',
            'command_id' => $cmd_id,
            'status'     => ! empty( $result['success'] ) ? 'complete' : 'failed',
            'result'     => $result,
        );
        Agency_Hub::update_setting( 'pending_alerts', $pending );
    }

    public static function run_scan_background() {
        $cmd_id = Agency_Hub::get_setting( 'pending_scan_cmd_id', '' );
        @set_time_limit( 300 );
        $result    = Agency_Hub_Scanner::run_scan();
        $pending   = Agency_Hub::get_setting( 'pending_alerts', array() );
        $pending[] = array(
            'type'         => 'command_result',
            'severity'     => 'info',
            'command_id'   => $cmd_id,
            'status'       => 'complete',
            'result'       => $result,
            'threats_found'=> $result['threats_found'] ?? 0,
            'findings'     => $result['findings'] ?? array(),
            'total_files'  => $result['total_files'] ?? 0,
        );
        Agency_Hub::update_setting( 'pending_alerts', $pending );
        Agency_Hub::update_setting( 'pending_scan_cmd_id', '' );
        Agency_Hub::update_setting( 'last_scan_status', $result['status'] ?? 'complete' );
        Agency_Hub::update_setting( 'last_scan_at', current_time( 'mysql' ) );
    }

    public static function run_backup_background( $payload = array() ) {
        $cmd_id = Agency_Hub::get_setting( 'pending_backup_cmd_id', '' );
        @set_time_limit( 300 );
        ini_set( 'memory_limit', '512M' );
        Agency_Hub::update_setting( 'backup_progress', 10 );
        $result = Agency_Hub_Backup::run_backup( $payload );
        Agency_Hub::update_setting( 'backup_progress', 100 );
        Agency_Hub::update_setting( 'backup_status', $result['success'] ? 'complete' : 'failed' );
        Agency_Hub::update_setting( 'pending_backup_cmd_id', '' );
    }

}

endif;
