<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Agency_Hub_2FA' ) ) :

class Agency_Hub_2FA {

    const TOTP_DIGITS  = 6;
    const TOTP_PERIOD  = 30;
    const TOTP_WINDOW  = 1;   // Accept 1 step before/after current
    const META_SECRET  = '_ah_2fa_secret';
    const META_ENABLED = '_ah_2fa_enabled';
    const META_BYPASS  = '_ah_2fa_bypass_codes';
    const META_TRUSTED = '_ah_2fa_trusted_devices';
    const SESSION_KEY  = 'ah_2fa_verified';
    const RECOVERY_COUNT = 10;

    public static function init() {
        add_action( 'rest_api_init',       array( __CLASS__, 'register_routes' ) );
        add_action( 'login_form',          array( __CLASS__, 'maybe_show_2fa_form' ) );
        add_action( 'wp_authenticate_user', array( __CLASS__, 'intercept_login' ), 10, 2 );
        add_action( 'wp_login',            array( __CLASS__, 'post_login_2fa_check' ), 10, 2 );
    }

    // --------------------------------------------------------
    // REST ROUTES
    // --------------------------------------------------------

    public static function register_routes() {
        register_rest_route( 'agency-hub/v1', '/2fa/setup', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_setup' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/2fa/verify', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_verify' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'agency-hub/v1', '/2fa/disable', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_disable' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/2fa/reissue', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_reissue' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/2fa/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_status' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/2fa/generate-bypass', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_generate_bypass' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );
    }

    // --------------------------------------------------------
    // HANDLERS
    // --------------------------------------------------------

    public static function handle_setup( WP_REST_Request $request ) {
        $user_id = intval( $request->get_param( 'user_id' ) );
        if ( ! $user_id ) $user_id = get_current_user_id();

        $result = self::setup_for_user( $user_id );
        return rest_ensure_response( $result );
    }

    public static function handle_verify( WP_REST_Request $request ) {
        $user_id = intval( $request->get_param( 'user_id' ) );
        $code    = sanitize_text_field( $request->get_param( 'code' ) );

        if ( ! $user_id || ! $code ) {
            return new WP_Error( 'missing_params', 'user_id and code are required.', array( 'status' => 400 ) );
        }

        $result = self::verify_code( $user_id, $code );
        return rest_ensure_response( $result );
    }

    public static function handle_disable( WP_REST_Request $request ) {
        $user_id = intval( $request->get_param( 'user_id' ) );
        if ( ! $user_id ) {
            return new WP_Error( 'missing_user_id', 'user_id is required.', array( 'status' => 400 ) );
        }

        delete_user_meta( $user_id, self::META_SECRET );
        delete_user_meta( $user_id, self::META_ENABLED );
        delete_user_meta( $user_id, self::META_BYPASS );

        Agency_Hub::log_event( array(
            'event_type'     => '2fa_disabled',
            'event_category' => 'user',
            'severity'       => 'medium',
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'message'        => '2FA disabled for user ID ' . $user_id . ' via Hub.',
        ) );

        return rest_ensure_response( array( 'success' => true ) );
    }

    public static function handle_reissue( WP_REST_Request $request ) {
        $user_id = intval( $request->get_param( 'user_id' ) );
        if ( ! $user_id ) {
            return new WP_Error( 'missing_user_id', 'user_id is required.', array( 'status' => 400 ) );
        }

        return rest_ensure_response( self::reissue_for_user( $user_id ) );
    }

    public static function handle_status( WP_REST_Request $request ) {
        $users = get_users( array( 'fields' => array( 'ID', 'user_login', 'user_email' ) ) );
        $status = array();

        foreach ( $users as $user ) {
            $status[] = array(
                'user_id'    => $user->ID,
                'login'      => $user->user_login,
                'email'      => $user->user_email,
                '2fa_active' => (bool) get_user_meta( $user->ID, self::META_ENABLED, true ),
            );
        }

        return rest_ensure_response( array( 'users' => $status ) );
    }

    public static function handle_generate_bypass( WP_REST_Request $request ) {
        $user_id = intval( $request->get_param( 'user_id' ) );
        if ( ! $user_id ) {
            return new WP_Error( 'missing_user_id', 'user_id is required.', array( 'status' => 400 ) );
        }

        $code   = strtoupper( bin2hex( random_bytes( 8 ) ) );
        $hashed = hash( 'sha256', $code );

        // Store as one-time bypass (replaces any existing one-time bypass)
        update_user_meta( $user_id, '_ah_2fa_onetime_bypass', $hashed );

        Agency_Hub::log_event( array(
            'event_type'     => '2fa_bypass_generated',
            'event_category' => 'user',
            'severity'       => 'medium',
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'message'        => 'One-time 2FA bypass code generated for user ID ' . $user_id . ' via Hub.',
        ) );

        return rest_ensure_response( array(
            'success'     => true,
            'bypass_code' => $code,
            'note'        => 'This code is valid for one use only.',
        ) );
    }

    // --------------------------------------------------------
    // SETUP 2FA FOR A USER
    // Generates secret, QR URL, recovery codes
    // --------------------------------------------------------

    public static function setup_for_user( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return array( 'success' => false, 'message' => 'User not found.' );
        }

        $secret         = self::generate_secret();
        $recovery_codes = self::generate_recovery_codes();
        $hashed_codes   = array_map( fn($c) => hash( 'sha256', $c ), $recovery_codes );

        update_user_meta( $user_id, self::META_SECRET,  $secret );
        update_user_meta( $user_id, self::META_ENABLED, false );  // Enabled after first successful verify
        update_user_meta( $user_id, self::META_BYPASS,  $hashed_codes );

        $qr_url = self::get_qr_url( $user->user_email, $secret );

        Agency_Hub::log_event( array(
            'event_type'     => '2fa_setup_started',
            'event_category' => 'user',
            'severity'       => 'low',
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'message'        => '2FA setup initiated for ' . $user->user_login,
        ) );

        return array(
            'success'        => true,
            'secret'         => $secret,
            'qr_url'         => $qr_url,
            'manual_key'     => $secret,
            'recovery_codes' => $recovery_codes,
            'note'           => 'Scan the QR code with your authenticator app then verify a code to activate 2FA.',
        );
    }

    // --------------------------------------------------------
    // REISSUE 2FA (invalidate old, generate new QR)
    // --------------------------------------------------------

    public static function reissue_for_user( $user_id ) {
        delete_user_meta( $user_id, self::META_SECRET );
        delete_user_meta( $user_id, self::META_ENABLED );
        delete_user_meta( $user_id, self::META_BYPASS );

        Agency_Hub::log_event( array(
            'event_type'     => '2fa_reissued',
            'event_category' => 'security',
            'severity'       => 'medium',
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'message'        => '2FA secret reissued for user ID ' . $user_id . '. Old TOTP invalidated.',
        ) );

        return self::setup_for_user( $user_id );
    }

    // --------------------------------------------------------
    // VERIFY CODE
    // Checks TOTP, recovery codes, and one-time bypass codes
    // --------------------------------------------------------

    public static function verify_code( $user_id, $code ) {
        $code = preg_replace( '/\s+/', '', $code );

        // Check one-time bypass first
        $stored_bypass = get_user_meta( $user_id, '_ah_2fa_onetime_bypass', true );
        if ( $stored_bypass && hash_equals( $stored_bypass, hash( 'sha256', $code ) ) ) {
            delete_user_meta( $user_id, '_ah_2fa_onetime_bypass' );
            Agency_Hub::log_event( array(
                'event_type'     => '2fa_bypass_used',
                'event_category' => 'user',
                'severity'       => 'high',
                'object_type'    => 'user',
                'object_id'      => $user_id,
                'message'        => '2FA bypass code used for user ID ' . $user_id,
                'user_ip'        => Agency_Hub::get_current_ip(),
            ) );
            return array( 'success' => true, 'method' => 'bypass_code' );
        }

        // Check recovery codes
        $stored_codes = get_user_meta( $user_id, self::META_BYPASS, true ) ?: array();
        foreach ( $stored_codes as $index => $hashed ) {
            if ( hash_equals( $hashed, hash( 'sha256', $code ) ) ) {
                // Remove used recovery code
                unset( $stored_codes[ $index ] );
                update_user_meta( $user_id, self::META_BYPASS, array_values( $stored_codes ) );

                Agency_Hub::log_event( array(
                    'event_type'     => '2fa_recovery_code_used',
                    'event_category' => 'user',
                    'severity'       => 'medium',
                    'object_type'    => 'user',
                    'object_id'      => $user_id,
                    'message'        => 'Recovery code used for user ID ' . $user_id,
                    'user_ip'        => Agency_Hub::get_current_ip(),
                ) );

                return array( 'success' => true, 'method' => 'recovery_code', 'remaining' => count( $stored_codes ) );
            }
        }

        // Check TOTP
        $secret = get_user_meta( $user_id, self::META_SECRET, true );
        if ( empty( $secret ) ) {
            return array( 'success' => false, 'message' => '2FA not configured for this user.' );
        }

        $valid = self::verify_totp( $secret, $code );

        if ( $valid ) {
            // If this is the first successful verify after setup, enable 2FA
            if ( ! get_user_meta( $user_id, self::META_ENABLED, true ) ) {
                update_user_meta( $user_id, self::META_ENABLED, true );
            }

            Agency_Hub::log_event( array(
                'event_type'     => '2fa_verified',
                'event_category' => 'user',
                'severity'       => 'info',
                'object_type'    => 'user',
                'object_id'      => $user_id,
                'message'        => '2FA verified successfully for user ID ' . $user_id,
                'user_ip'        => Agency_Hub::get_current_ip(),
            ) );

            return array( 'success' => true, 'method' => 'totp' );
        }

        Agency_Hub::log_event( array(
            'event_type'     => '2fa_failed',
            'event_category' => 'security',
            'severity'       => 'medium',
            'object_type'    => 'user',
            'object_id'      => $user_id,
            'message'        => '2FA verification failed for user ID ' . $user_id,
            'user_ip'        => Agency_Hub::get_current_ip(),
        ) );

        return array( 'success' => false, 'message' => 'Invalid authentication code.' );
    }

    // --------------------------------------------------------
    // LOGIN INTERCEPT
    // Check if user has 2FA enabled and verify during login
    // --------------------------------------------------------

    public static function intercept_login( $user, $password ) {
        if ( is_wp_error( $user ) ) return $user;

        $settings = Agency_Hub::get_settings();
        if ( empty( $settings['2fa_enforcement'] ) ) return $user;

        $has_2fa = (bool) get_user_meta( $user->ID, self::META_ENABLED, true );
        if ( ! $has_2fa ) return $user;

        // Check trusted device cookie
        if ( self::is_device_trusted( $user->ID ) ) {
            return $user;
        }

        // Store user ID in session to complete 2FA flow
        if ( ! session_id() ) @session_start();
        $_SESSION['ah_2fa_pending_user'] = $user->ID;

        // Return error to interrupt login — 2FA page will complete it
        return new WP_Error( 'ah_2fa_required', '2FA_REQUIRED' );
    }

    public static function post_login_2fa_check( $user_login, $user ) {
        // After successful login, mark 2FA verified in session
        if ( ! session_id() ) @session_start();
        $_SESSION[ self::SESSION_KEY ] = $user->ID;
    }

    public static function maybe_show_2fa_form() {
        if ( ! session_id() ) @session_start();
        if ( ! empty( $_SESSION['ah_2fa_pending_user'] ) ) {
            // Output 2FA code input field in login form
            echo '<p><label for="ah_2fa_code">Authentication Code<br>';
            echo '<input type="text" name="ah_2fa_code" id="ah_2fa_code" class="input" value="" size="20" autocomplete="one-time-code" inputmode="numeric" maxlength="8" placeholder="Enter 6-digit code"></label></p>';
        }
    }

    // --------------------------------------------------------
    // TRUSTED DEVICE
    // --------------------------------------------------------

    private static function is_device_trusted( $user_id ) {
        $settings = Agency_Hub::get_settings();
        if ( empty( $settings['2fa_trusted_devices_allowed'] ) ) return false;

        $cookie_name = 'ah_trusted_' . md5( get_site_url() . $user_id );
        if ( empty( $_COOKIE[ $cookie_name ] ) ) return false;

        $stored  = get_user_meta( $user_id, self::META_TRUSTED, true ) ?: array();
        $token   = sanitize_text_field( $_COOKIE[ $cookie_name ] );
        $hashed  = hash( 'sha256', $token );

        foreach ( $stored as $entry ) {
            if ( hash_equals( $entry['token'], $hashed ) && $entry['expires'] > time() ) {
                return true;
            }
        }

        return false;
    }

    public static function mark_device_trusted( $user_id, $days = 30 ) {
        $settings = Agency_Hub::get_settings();
        $days     = intval( $settings['2fa_trusted_days'] ?? $days );
        $token    = bin2hex( random_bytes( 32 ) );
        $expires  = time() + ( $days * DAY_IN_SECONDS );

        $stored   = get_user_meta( $user_id, self::META_TRUSTED, true ) ?: array();
        $stored[] = array( 'token' => hash( 'sha256', $token ), 'expires' => $expires );

        // Prune expired entries
        $stored = array_filter( $stored, fn($e) => $e['expires'] > time() );

        update_user_meta( $user_id, self::META_TRUSTED, array_values( $stored ) );

        $cookie_name = 'ah_trusted_' . md5( get_site_url() . $user_id );
        setcookie( $cookie_name, $token, $expires, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    }

    // --------------------------------------------------------
    // TOTP IMPLEMENTATION
    // RFC 6238 compliant — works with Google Authenticator, Authy, etc.
    // --------------------------------------------------------

    private static function verify_totp( $secret, $code ) {
        $timestamp = floor( time() / self::TOTP_PERIOD );

        for ( $i = -self::TOTP_WINDOW; $i <= self::TOTP_WINDOW; $i++ ) {
            $expected = self::calculate_totp( $secret, $timestamp + $i );
            if ( hash_equals( str_pad( $expected, self::TOTP_DIGITS, '0', STR_PAD_LEFT ), $code ) ) {
                return true;
            }
        }

        return false;
    }

    private static function calculate_totp( $secret, $counter ) {
        $raw_secret = self::base32_decode( $secret );
        $time_bytes = pack( 'N*', 0 ) . pack( 'N*', $counter );
        $hash       = hash_hmac( 'sha1', $time_bytes, $raw_secret, true );
        $offset     = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0F;
        $code       = (
            ( ord( $hash[ $offset ] )     & 0x7F ) << 24 |
            ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 |
            ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8  |
            ( ord( $hash[ $offset + 3 ] ) & 0xFF )
        ) % pow( 10, self::TOTP_DIGITS );

        return $code;
    }

    // --------------------------------------------------------
    // SECRET GENERATION
    // --------------------------------------------------------

    private static function generate_secret( $length = 32 ) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret   = '';
        $bytes    = random_bytes( $length );
        for ( $i = 0; $i < $length; $i++ ) {
            $secret .= $alphabet[ ord( $bytes[ $i ] ) % 32 ];
        }
        return $secret;
    }

    // --------------------------------------------------------
    // RECOVERY CODES
    // --------------------------------------------------------

    private static function generate_recovery_codes( $count = self::RECOVERY_COUNT ) {
        $codes = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $codes[] = strtoupper( implode( '-', str_split( bin2hex( random_bytes( 5 ) ), 5 ) ) );
        }
        return $codes;
    }

    // --------------------------------------------------------
    // QR CODE URL
    // Uses Google Charts API (free, no API key needed)
    // --------------------------------------------------------

    private static function get_qr_url( $email, $secret ) {
        $issuer  = urlencode( get_bloginfo( 'name' ) );
        $account = urlencode( $email );
        $otpauth = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&digits=" . self::TOTP_DIGITS . "&period=" . self::TOTP_PERIOD;
        $encoded = urlencode( $otpauth );

        // Primary: Google Charts
        $qr_url = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl={$encoded}&choe=UTF-8";

        return array(
            'qr_image_url' => $qr_url,
            'otpauth_uri'  => $otpauth,
        );
    }

    // --------------------------------------------------------
    // BASE32 DECODE (for TOTP)
    // --------------------------------------------------------

    private static function base32_decode( $input ) {
        $map     = array_flip( str_split( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' ) );
        $input   = strtoupper( $input );
        $output  = '';
        $buffer  = 0;
        $bits    = 0;

        for ( $i = 0; $i < strlen( $input ); $i++ ) {
            if ( ! isset( $map[ $input[ $i ] ] ) ) continue;
            $buffer = ( $buffer << 5 ) | $map[ $input[ $i ] ];
            $bits  += 5;
            if ( $bits >= 8 ) {
                $output .= chr( ( $buffer >> ( $bits - 8 ) ) & 0xFF );
                $bits   -= 8;
            }
        }

        return $output;
    }
}

endif;
