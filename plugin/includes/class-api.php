<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Agency_Hub_API' ) ) :

class Agency_Hub_API {

    // --------------------------------------------------------
    // SEND HEARTBEAT TO HUB
    // POSTs to {hub_url}/api/webhook/heartbeat
    // Hub returns { success, commands: [...] }
    // --------------------------------------------------------

    public static function send_heartbeat( $payload ) {
        $settings = Agency_Hub::get_settings();
        $hub_url  = rtrim( $settings['hub_url']  ?? '', '/' );
        $site_key = $settings['site_key'] ?? '';

        if ( empty( $hub_url ) || empty( $site_key ) ) {
            return array( 'success' => false, 'commands' => array() );
        }

        $body = wp_json_encode( array(
            'site_key' => $site_key,
            'status'   => 'online',
            'data'     => $payload,
        ) );

        $response = wp_remote_post( $hub_url . '/api/webhook/heartbeat', array(
            'timeout'   => 15,
            'blocking'  => true,
            'sslverify' => true,
            'headers'   => array(
                'Content-Type'     => 'application/json',
                'X-Plugin-Version' => AGENCY_HUB_VERSION,
            ),
            'body' => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Agency Hub heartbeat failed: ' . $response->get_error_message() );
            return array( 'success' => false, 'commands' => array() );
        }

        $code   = wp_remote_retrieve_response_code( $response );
        $result = json_decode( wp_remote_retrieve_body( $response ), true );

        return array(
            'success'  => ( $code >= 200 && $code < 300 ),
            'commands' => $result['commands'] ?? array(),
        );
    }

    // --------------------------------------------------------
    // SEND CRITICAL ALERT IMMEDIATELY
    // --------------------------------------------------------

    public static function push_alert( $alert_data ) {
        $settings = Agency_Hub::get_settings();
        $hub_url  = rtrim( $settings['hub_url']  ?? '', '/' );
        $site_key = $settings['site_key'] ?? '';

        if ( empty( $hub_url ) || empty( $site_key ) ) return false;

        $alert_data['site_url']  = get_site_url();
        $alert_data['timestamp'] = time();

        $response = wp_remote_post( $hub_url . '/api/webhook/heartbeat', array(
            'timeout'   => 10,
            'blocking'  => false,
            'sslverify' => true,
            'headers'   => array( 'Content-Type' => 'application/json' ),
            'body'      => wp_json_encode( array(
                'site_key' => $site_key,
                'status'   => 'online',
                'data'     => array( 'alerts' => array( $alert_data ) ),
            ) ),
        ) );

        return ! is_wp_error( $response );
    }

    // --------------------------------------------------------
    // VERIFY INCOMING REQUEST (REST endpoints)
    // --------------------------------------------------------

    public static function verify_request( WP_REST_Request $request ) {
        $settings = Agency_Hub::get_settings();
        $site_key = $settings['site_key'] ?? '';

        if ( empty( $site_key ) ) {
            return new WP_Error( 'not_configured', 'Plugin not configured.', array( 'status' => 401 ) );
        }

        $provided = $request->get_header( 'x-site-key' )
            ?? $request->get_param( 'site_key' )
            ?? '';

        if ( ! hash_equals( $site_key, $provided ) ) {
            return new WP_Error( 'invalid_key', 'Invalid site key.', array( 'status' => 401 ) );
        }

        return true;
    }

    // --------------------------------------------------------
    // VERIFY DOWNLOAD TOKEN
    // --------------------------------------------------------

    public static function verify_download_token( WP_REST_Request $request ) {
        $token   = sanitize_text_field( $request->get_param( 'token' ) );
        $stored  = Agency_Hub::get_setting( 'download_token' );
        $expires = Agency_Hub::get_setting( 'download_token_expires' );

        if ( empty( $token ) || empty( $stored ) ) {
            return new WP_Error( 'missing_token', 'No download token.', array( 'status' => 401 ) );
        }
        if ( time() > intval( $expires ) ) {
            return new WP_Error( 'expired_token', 'Token expired.', array( 'status' => 401 ) );
        }
        if ( ! hash_equals( $stored, hash( 'sha256', $token ) ) ) {
            return new WP_Error( 'invalid_token', 'Invalid token.', array( 'status' => 401 ) );
        }
        return true;
    }
}

endif;
