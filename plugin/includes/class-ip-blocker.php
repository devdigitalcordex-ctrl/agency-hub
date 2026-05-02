<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Agency_Hub_IP_Blocker' ) ) :

class Agency_Hub_IP_Blocker {

    const BLOCKLIST_OPTION   = 'agency_hub_blocklist';
    const ALLOWLIST_OPTION   = 'agency_hub_allowlist';
    const WHITELIST_OPTION   = 'agency_hub_whitelist';
    const BLOCK_PAGE_OPTION  = 'agency_hub_block_page_html';
    const FAILED_LOGIN_KEY   = 'agency_hub_failed_logins';

    const FAILED_LOGIN_LIMIT  = 5;
    const FAILED_LOGIN_WINDOW = 600;
    const FLOOD_404_LIMIT     = 20;
    const FLOOD_404_WINDOW    = 300;

    // --------------------------------------------------------
    // INIT
    // --------------------------------------------------------

    public static function init() {
        add_action( 'init', array( __CLASS__, 'check_and_block' ), 1 );
        add_action( 'wp_login_failed', array( __CLASS__, 'handle_failed_login' ) );
        add_action( 'wp_login',        array( __CLASS__, 'handle_successful_login' ), 10, 2 );
        add_action( 'wp',              array( __CLASS__, 'check_404_flood' ) );
    }

    // --------------------------------------------------------
    // MAIN CHECK
    //
    // ALLOWLIST MODE ON:
    //   Only IPs in the include list pass through.
    //   Everything else gets a 403 before WordPress loads.
    //
    // ALLOWLIST MODE OFF (default):
    //   Normal blocklist — specific bad IPs are blocked,
    //   everything else is allowed through.
    // --------------------------------------------------------

    public static function check_and_block() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) return;

        $ip         = self::get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // ── ALLOWLIST MODE ──────────────────────────────────────────
        $allowlist_mode = (bool) Agency_Hub::get_setting( 'allowlist_mode', false );

        if ( $allowlist_mode ) {
            if ( self::is_in_allowlist( $ip ) ) {
                return; // IP is in the include list — let through
            }
            self::serve_block_response( $ip, array(
                'type'   => 'allowlist_mode',
                'value'  => $ip,
                'reason' => 'Access is restricted to authorised IP addresses only.',
            ) );
        }

        // ── BLOCKLIST MODE (default) ────────────────────────────────
        // Bypass whitelist — these IPs skip all checks, always
        if ( self::is_bypass_whitelisted( $ip ) ) return;

        self::prune_expired();

        $blocklist = self::get_blocklist();

        foreach ( $blocklist as $entry ) {
            if ( ! empty( $entry['expires_at'] ) && strtotime( $entry['expires_at'] ) < time() ) {
                continue;
            }

            $blocked = false;

            switch ( $entry['type'] ) {
                case 'ip':
                    $blocked = ( $entry['value'] === $ip );
                    break;
                case 'range':
                    $blocked = self::ip_in_cidr( $ip, $entry['value'] );
                    break;
                case 'country':
                    $blocked = ( strtoupper( self::get_country( $ip ) ) === strtoupper( $entry['value'] ) );
                    break;
                case 'user_agent':
                    $blocked = ( stripos( $user_agent, $entry['value'] ) !== false );
                    break;
            }

            if ( $blocked ) {
                self::serve_block_response( $ip, $entry );
            }
        }
    }

    // --------------------------------------------------------
    // BLOCK RESPONSE — 403 page
    // --------------------------------------------------------

    private static function serve_block_response( $ip, $entry ) {
        $settings     = Agency_Hub::get_settings();
        $custom_msg   = $settings['block_page_message'] ?? '';
        $contact_info = $settings['block_page_contact']  ?? '';
        $custom_html  = get_option( self::BLOCK_PAGE_OPTION );

        status_header( 403 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        if ( $custom_html ) {
            echo $custom_html;
        } else {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access Denied</title>';
            echo '<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600&display=swap" rel="stylesheet">';
            echo '<style>*{box-sizing:border-box;margin:0;padding:0}body{background:#0A0A12;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Outfit,sans-serif;color:#e2e8f0;padding:20px}.card{background:#111120;border:1px solid rgba(81,133,200,.18);border-radius:12px;padding:40px 32px;max-width:480px;width:100%;text-align:center}h1{font-size:26px;font-weight:600;margin-bottom:12px}p{color:#8892a4;font-size:15px;line-height:1.6}.contact{margin-top:20px;padding-top:20px;border-top:1px solid rgba(81,133,200,.12);font-size:13px;color:#8892a4}.code{margin-top:16px;font-size:12px;color:#555e72;font-family:monospace}</style>';
            echo '</head><body><div class="card">';
            echo '<h1>Access Denied</h1>';
            echo '<p>' . esc_html( $custom_msg ?: 'Your access to this website has been restricted.' ) . '</p>';
            if ( $contact_info ) {
                echo '<div class="contact">If you believe this is a mistake, please contact: ' . esc_html( $contact_info ) . '</div>';
            }
            echo '<div class="code">403 &mdash; ' . esc_html( get_bloginfo( 'name' ) ) . '</div>';
            echo '</div></body></html>';
        }

        Agency_Hub::log_event( array(
            'event_type'     => 'request_blocked',
            'event_category' => 'security',
            'severity'       => 'medium',
            'message'        => "Blocked: {$ip} — {$entry['type']}: {$entry['value']}",
            'user_ip'        => $ip,
        ) );

        exit;
    }

    // --------------------------------------------------------
    // ALLOWLIST — include list
    // --------------------------------------------------------

    public static function is_in_allowlist( $ip ) {
        $list = self::get_allowlist();
        foreach ( $list as $entry ) {
            if ( strpos( $entry['value'], '/' ) !== false ) {
                if ( self::ip_in_cidr( $ip, $entry['value'] ) ) return true;
            } else {
                if ( $entry['value'] === $ip ) return true;
            }
        }
        return false;
    }

    public static function allowlist_add( $ip, $label = '', $expires_at = null ) {
        $ip = sanitize_text_field( $ip );
        if ( empty( $ip ) ) return array( 'success' => false, 'message' => 'IP is required.' );

        $list = self::get_allowlist();
        foreach ( $list as $entry ) {
            if ( $entry['value'] === $ip ) {
                return array( 'success' => true, 'message' => 'Already in allowlist.' );
            }
        }

        $list[] = array(
            'id'         => uniqid( 'allow_' ),
            'value'      => $ip,
            'label'      => sanitize_text_field( $label ),
            'added_at'   => current_time( 'mysql' ),
            'expires_at' => $expires_at,
        );

        update_option( self::ALLOWLIST_OPTION, $list );

        Agency_Hub::log_event( array(
            'event_type'     => 'allowlist_ip_added',
            'event_category' => 'security',
            'severity'       => 'info',
            'message'        => "Added to allowlist: {$ip}" . ( $label ? " ({$label})" : '' ),
        ) );

        return array( 'success' => true, 'ip' => $ip );
    }

    public static function allowlist_remove( $id_or_ip ) {
        $list     = self::get_allowlist();
        $original = count( $list );

        $list = array_filter( $list, function( $e ) use ( $id_or_ip ) {
            return $e['id'] !== $id_or_ip && $e['value'] !== $id_or_ip;
        } );

        update_option( self::ALLOWLIST_OPTION, array_values( $list ) );

        Agency_Hub::log_event( array(
            'event_type'     => 'allowlist_ip_removed',
            'event_category' => 'security',
            'severity'       => 'info',
            'message'        => "Removed from allowlist: {$id_or_ip}",
        ) );

        return array( 'success' => count( $list ) < $original );
    }

    public static function set_allowlist_mode( $enabled ) {
        $enabled = (bool) $enabled;
        Agency_Hub::update_setting( 'allowlist_mode', $enabled );

        Agency_Hub::log_event( array(
            'event_type'     => $enabled ? 'allowlist_mode_on' : 'allowlist_mode_off',
            'event_category' => 'security',
            'severity'       => 'medium',
            'message'        => $enabled
                ? 'Allowlist mode ON — only included IPs can access. All others blocked.'
                : 'Allowlist mode OFF — reverted to standard blocklist mode.',
        ) );

        return array( 'success' => true, 'allowlist_mode' => $enabled );
    }

    public static function get_all_lists() {
        self::prune_expired();
        return array(
            'allowlist_mode' => (bool) Agency_Hub::get_setting( 'allowlist_mode', false ),
            'allowlist'      => self::get_allowlist(),
            'blocklist'      => self::get_blocklist(),
            'bypass_list'    => array_values( self::get_bypass_whitelist() ),
        );
    }

    // --------------------------------------------------------
    // BLOCKLIST — exclude list
    // --------------------------------------------------------

    public static function block_ip( $ip, $reason = '', $expires = null ) {
        if ( self::is_bypass_whitelisted( $ip ) ) {
            return array( 'success' => false, 'message' => 'IP is on the permanent bypass list.' );
        }

        $blocklist = self::get_blocklist();
        foreach ( $blocklist as $entry ) {
            if ( $entry['type'] === 'ip' && $entry['value'] === $ip ) {
                return array( 'success' => true, 'message' => 'Already in blocklist.' );
            }
        }

        $entry = array(
            'id'         => uniqid( 'block_' ),
            'type'       => 'ip',
            'value'      => sanitize_text_field( $ip ),
            'reason'     => sanitize_text_field( $reason ),
            'created_at' => current_time( 'mysql' ),
            'expires_at' => $expires ? date( 'Y-m-d H:i:s', strtotime( $expires ) ) : null,
        );

        $blocklist[] = $entry;
        update_option( self::BLOCKLIST_OPTION, $blocklist );

        Agency_Hub::log_event( array(
            'event_type'     => 'ip_blocked',
            'event_category' => 'security',
            'severity'       => 'medium',
            'message'        => "IP blocked: {$ip}. Reason: {$reason}",
            'user_ip'        => $ip,
        ) );

        return array( 'success' => true, 'entry' => $entry );
    }

    public static function unblock_ip( $id_or_ip ) {
        $blocklist = self::get_blocklist();
        $original  = count( $blocklist );

        $blocklist = array_filter( $blocklist, function( $e ) use ( $id_or_ip ) {
            return $e['id'] !== $id_or_ip && $e['value'] !== $id_or_ip;
        } );

        update_option( self::BLOCKLIST_OPTION, array_values( $blocklist ) );
        return array( 'success' => count( $blocklist ) < $original );
    }

    // --------------------------------------------------------
    // BYPASS WHITELIST
    // IPs that skip everything — both allowlist and blocklist.
    // Use for your own agency IPs so you never lock yourself out.
    // --------------------------------------------------------

    public static function bypass_whitelist_add( $ip, $label = '' ) {
        $ip = sanitize_text_field( $ip );
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return array( 'success' => false, 'message' => 'Invalid IP address.' );
        }

        $list        = self::get_bypass_whitelist();
        $list[ $ip ] = array(
            'ip'       => $ip,
            'label'    => sanitize_text_field( $label ),
            'added_at' => current_time( 'mysql' ),
        );

        update_option( self::WHITELIST_OPTION, $list );
        self::unblock_ip( $ip );

        Agency_Hub::log_event( array(
            'event_type'     => 'bypass_added',
            'event_category' => 'security',
            'severity'       => 'info',
            'message'        => "IP added to bypass whitelist: {$ip}" . ( $label ? " ({$label})" : '' ),
        ) );

        return array( 'success' => true );
    }

    public static function bypass_whitelist_remove( $ip ) {
        $list = self::get_bypass_whitelist();
        unset( $list[ $ip ] );
        update_option( self::WHITELIST_OPTION, $list );
        return array( 'success' => true );
    }

    public static function is_bypass_whitelisted( $ip ) {
        return isset( self::get_bypass_whitelist()[ $ip ] );
    }

    // --------------------------------------------------------
    // FAILED LOGIN AUTO-BLOCK
    // --------------------------------------------------------

    public static function handle_failed_login( $username ) {
        $ip   = self::get_client_ip();
        $key  = self::FAILED_LOGIN_KEY . '_' . md5( $ip );
        $data = get_transient( $key ) ?: array( 'count' => 0, 'first' => time() );

        $data['count']++;
        set_transient( $key, $data, self::FAILED_LOGIN_WINDOW );

        Agency_Hub::log_event( array(
            'event_type'     => 'login_failed',
            'event_category' => 'user',
            'severity'       => 'medium',
            'message'        => "Failed login for '{$username}'. Attempt {$data['count']} from {$ip}",
            'user_ip'        => $ip,
            'object_type'    => 'user',
            'object_name'    => $username,
        ) );

        if ( $data['count'] >= self::FAILED_LOGIN_LIMIT ) {
            self::block_ip( $ip, 'Auto-blocked: ' . self::FAILED_LOGIN_LIMIT . ' failed logins', '+1 hour' );
            Agency_Hub_Heartbeat::push_critical_alert( array(
                'type'     => 'brute_force_detected',
                'severity' => 'high',
                'message'  => "IP {$ip} auto-blocked after {$data['count']} failed login attempts for '{$username}'.",
                'user_ip'  => $ip,
            ) );
            delete_transient( $key );
        }
    }

    public static function handle_successful_login( $user_login, $user ) {
        delete_transient( self::FAILED_LOGIN_KEY . '_' . md5( self::get_client_ip() ) );
    }

    // --------------------------------------------------------
    // 404 FLOOD DETECTION
    // --------------------------------------------------------

    public static function check_404_flood() {
        if ( ! is_404() ) return;
        $ip    = self::get_client_ip();
        $key   = 'agency_hub_404_' . md5( $ip );
        $count = (int) get_transient( $key ) + 1;
        set_transient( $key, $count, self::FLOOD_404_WINDOW );

        if ( $count >= self::FLOOD_404_LIMIT ) {
            self::block_ip( $ip, "Auto-blocked: {$count} 404s in " . ( self::FLOOD_404_WINDOW / 60 ) . ' min', '+30 minutes' );
            delete_transient( $key );
        }
    }

    // --------------------------------------------------------
    // REST HANDLERS
    // --------------------------------------------------------

    public static function handle_get_lists( WP_REST_Request $r ) {
        return rest_ensure_response( self::get_all_lists() );
    }

    public static function handle_allowlist_add( WP_REST_Request $r ) {
        return rest_ensure_response( self::allowlist_add(
            sanitize_text_field( $r->get_param( 'ip' ) ?? '' ),
            sanitize_text_field( $r->get_param( 'label' ) ?? '' ),
            $r->get_param( 'expires_at' ) ?? null
        ) );
    }

    public static function handle_allowlist_remove( WP_REST_Request $r ) {
        $val = sanitize_text_field( $r->get_param( 'id' ) ?? $r->get_param( 'ip' ) ?? '' );
        return rest_ensure_response( self::allowlist_remove( $val ) );
    }

    public static function handle_set_mode( WP_REST_Request $r ) {
        return rest_ensure_response( self::set_allowlist_mode( (bool) $r->get_param( 'allowlist_mode' ) ) );
    }

    public static function handle_block( WP_REST_Request $r ) {
        $type   = sanitize_text_field( $r->get_param( 'type' )   ?? 'ip' );
        $value  = sanitize_text_field( $r->get_param( 'value' )  ?? '' );
        $reason = sanitize_text_field( $r->get_param( 'reason' ) ?? 'Blocked by Hub' );
        $exp    = $r->get_param( 'expires' ) ?? null;

        if ( empty( $value ) ) {
            return new WP_Error( 'missing_value', 'value is required.', array( 'status' => 400 ) );
        }

        if ( $type === 'ip' ) return rest_ensure_response( self::block_ip( $value, $reason, $exp ) );

        $blocklist   = self::get_blocklist();
        $blocklist[] = array(
            'id'         => uniqid( 'block_' ),
            'type'       => $type,
            'value'      => $value,
            'reason'     => $reason,
            'created_at' => current_time( 'mysql' ),
            'expires_at' => $exp ? date( 'Y-m-d H:i:s', strtotime( $exp ) ) : null,
        );
        update_option( self::BLOCKLIST_OPTION, $blocklist );

        return rest_ensure_response( array( 'success' => true ) );
    }

    public static function handle_unblock( WP_REST_Request $r ) {
        $val = sanitize_text_field( $r->get_param( 'id' ) ?? $r->get_param( 'ip' ) ?? '' );
        return rest_ensure_response( self::unblock_ip( $val ) );
    }

    public static function handle_bypass_add( WP_REST_Request $r ) {
        return rest_ensure_response( self::bypass_whitelist_add(
            sanitize_text_field( $r->get_param( 'ip' )    ?? '' ),
            sanitize_text_field( $r->get_param( 'label' ) ?? '' )
        ) );
    }

    public static function handle_bypass_remove( WP_REST_Request $r ) {
        return rest_ensure_response( self::bypass_whitelist_remove(
            sanitize_text_field( $r->get_param( 'ip' ) ?? '' )
        ) );
    }

    public static function update_blocklist( WP_REST_Request $r, $rules = null ) {
        $rules = $rules ?? $r->get_param( 'rules' );
        if ( ! is_array( $rules ) ) {
            return new WP_Error( 'invalid_rules', 'rules must be an array.', array( 'status' => 400 ) );
        }
        update_option( self::BLOCKLIST_OPTION, $rules );
        return rest_ensure_response( array( 'success' => true, 'count' => count( $rules ) ) );
    }

    // --------------------------------------------------------
    // STORAGE
    // --------------------------------------------------------

    public static function get_allowlist()       { return get_option( self::ALLOWLIST_OPTION, array() ); }
    public static function get_blocklist()       { return get_option( self::BLOCKLIST_OPTION, array() ); }
    public static function get_bypass_whitelist(){ return get_option( self::WHITELIST_OPTION, array() ); }

    // --------------------------------------------------------
    // PRUNE EXPIRED
    // --------------------------------------------------------

    private static function prune_expired() {
        foreach ( array( self::BLOCKLIST_OPTION, self::ALLOWLIST_OPTION ) as $option ) {
            $list   = get_option( $option, array() );
            $pruned = array_filter( $list, fn($e) => empty( $e['expires_at'] ) || strtotime( $e['expires_at'] ) > time() );
            if ( count( $pruned ) < count( $list ) ) {
                update_option( $option, array_values( $pruned ) );
            }
        }
    }

    // --------------------------------------------------------
    // CIDR RANGE CHECK
    // --------------------------------------------------------

    private static function ip_in_cidr( $ip, $cidr ) {
        if ( strpos( $cidr, '/' ) === false ) return $ip === $cidr;
        list( $subnet, $mask ) = explode( '/', $cidr );
        $mask        = intval( $mask );
        $ip_long     = ip2long( $ip );
        $subnet_long = ip2long( $subnet );
        if ( $ip_long === false || $subnet_long === false ) return false;
        $mask_long = $mask === 0 ? 0 : ( ~0 << ( 32 - $mask ) );
        return ( $ip_long & $mask_long ) === ( $subnet_long & $mask_long );
    }

    // --------------------------------------------------------
    // COUNTRY LOOKUP
    // --------------------------------------------------------

    private static function get_country( $ip ) {
        $cached = get_transient( 'ah_geo_' . md5( $ip ) );
        if ( $cached ) return $cached;

        $response = wp_remote_get( "http://ip-api.com/json/{$ip}?fields=countryCode", array( 'timeout' => 3 ) );
        if ( ! is_wp_error( $response ) ) {
            $data    = json_decode( wp_remote_retrieve_body( $response ), true );
            $country = $data['countryCode'] ?? '';
            if ( $country ) set_transient( 'ah_geo_' . md5( $ip ), $country, HOUR_IN_SECONDS );
            return $country;
        }
        return '';
    }

    // --------------------------------------------------------
    // GET REAL CLIENT IP
    // --------------------------------------------------------

    public static function get_client_ip() {
        $headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $h ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

endif;
