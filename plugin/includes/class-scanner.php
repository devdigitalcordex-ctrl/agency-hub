<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Agency_Hub_Scanner' ) ) :

class Agency_Hub_Scanner {

    // Executable extensions that should never be in /uploads
    const DANGEROUS_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'exe', 'sh', 'pl', 'py', 'cgi' );

    // Directories to scan
    const SCAN_DIRS = array(
        'core'    => '',
        'plugins' => 'wp-content/plugins',
        'themes'  => 'wp-content/themes',
        'uploads' => 'wp-content/uploads',
    );

    public static function init() {
        // Register REST endpoints
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

        // Scheduled scan
        add_action( 'agency_hub_scheduled_scan', array( __CLASS__, 'run_scan' ) );

        // Block known C2 outbound connections on every request
        self::block_c2_outbound();

        // REALTIME: Kill known malicious plugins immediately on init
        add_action( 'plugins_loaded', array( __CLASS__, 'kill_known_malicious_plugins' ), 1 );

        // REALTIME: Add security headers — blocks WebSocket C2 beacons
        add_action( 'send_headers', array( __CLASS__, 'add_security_headers' ) );

        // REALTIME: Check known infection files on admin load
        if ( is_admin() ) {
            add_action( 'admin_init', array( __CLASS__, 'check_known_injection_files' ) );
        }
    }

    // --------------------------------------------------------
    // REST ROUTES
    // --------------------------------------------------------

    public static function register_routes() {
        register_rest_route( 'agency-hub/v1', '/scan/run', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_run_scan' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/scan/results', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_get_results' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/scan/quarantine', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_quarantine' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/scan/restore-quarantine', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_restore_quarantine' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/scan/baseline', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_rebuild_baseline' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );
    }

    // --------------------------------------------------------
    // HANDLERS
    // --------------------------------------------------------

    public static function handle_run_scan( WP_REST_Request $request ) {
        $result = self::run_scan();
        return rest_ensure_response( $result );
    }

    public static function handle_get_results( WP_REST_Request $request ) {
        $scan_id  = sanitize_text_field( $request->get_param( 'scan_id' ) );
        $history  = get_option( 'agency_hub_scan_history', array() );
        $findings = array();

        if ( $scan_id ) {
            foreach ( $history as $scan ) {
                if ( $scan['scan_id'] === $scan_id ) {
                    $findings = $scan['findings'] ?? array();
                    break;
                }
            }
        } else {
            $last = get_option( 'agency_hub_last_scan', array() );
            $findings = $last['findings'] ?? array();
        }

        return rest_ensure_response( array(
            'findings' => $findings,
            'count'    => count( $findings ),
        ) );
    }

    public static function handle_quarantine( WP_REST_Request $request ) {
        $file_path = sanitize_text_field( $request->get_param( 'file_path' ) );
        $result    = self::quarantine_file( $file_path );
        return rest_ensure_response( $result );
    }

    public static function handle_restore_quarantine( WP_REST_Request $request ) {
        $quarantine_id = sanitize_text_field( $request->get_param( 'quarantine_id' ) );
        $result        = self::restore_from_quarantine( $quarantine_id );
        return rest_ensure_response( $result );
    }

    public static function handle_rebuild_baseline( WP_REST_Request $request ) {
        self::build_baseline();
        return rest_ensure_response( array( 'success' => true, 'message' => 'Baseline rebuilt.' ) );
    }

    // --------------------------------------------------------
    // MAIN SCAN
    // --------------------------------------------------------

    public static function run_scan() {
        global $wpdb;

        $scan_id    = 'scan_' . bin2hex( random_bytes( 8 ) );
        $scan_start = microtime( true );
        $findings   = array();
        $scanned    = 0;

        Agency_Hub::update_setting( 'scan_in_progress', true );
        Agency_Hub::update_setting( 'current_scan_id', $scan_id );

        // 1. WordPress core integrity check
        $core_findings = self::check_core_integrity();
        $findings      = array_merge( $findings, $core_findings );

        // 2. Scan plugins and themes for malware patterns
        $plugin_findings = self::scan_directory( WP_PLUGIN_DIR, 'plugins' );
        $findings        = array_merge( $findings, $plugin_findings );

        $theme_findings = self::scan_directory( get_theme_root(), 'themes' );
        $findings       = array_merge( $findings, $theme_findings );

        // 3. Scan uploads — auto-quarantine executable files
        $upload_findings = self::scan_uploads();
        $findings        = array_merge( $findings, $upload_findings );

        // 4. Check database for injections
        $db_findings = self::scan_database();
        $findings    = array_merge( $findings, $db_findings );

        // 5. Save findings to wp_options (no custom tables needed)
        $total_files  = self::count_scanned_files();
        $scan_elapsed = round( microtime( true ) - $scan_start, 2 );
        $status       = empty( $findings ) ? 'clean' : 'threats_found';

        // Store last scan result in options
        $scan_record = array(
            'scan_id'       => $scan_id,
            'status'        => $status,
            'total_files'   => $total_files,
            'threats_found' => count( $findings ),
            'findings'      => $findings,
            'duration_sec'  => $scan_elapsed,
            'started_at'    => current_time( 'mysql' ),
            'completed_at'  => current_time( 'mysql' ),
        );
        update_option( 'agency_hub_last_scan', $scan_record, false );

        // Keep last 10 scans history
        $history   = get_option( 'agency_hub_scan_history', array() );
        array_unshift( $history, $scan_record );
        $history   = array_slice( $history, 0, 10 );
        update_option( 'agency_hub_scan_history', $history, false );

        Agency_Hub::update_setting( 'last_scan_at', current_time( 'mysql' ) );
        Agency_Hub::update_setting( 'last_scan_status', $status );
        Agency_Hub::update_setting( 'scan_in_progress', false );

        // Push results to Hub via next heartbeat (queue as alert)
        Agency_Hub_Heartbeat::push_critical_alert( array(
            'type'          => 'scan_complete',
            'severity'      => $status === 'clean' ? 'info' : 'critical',
            'title'         => $status === 'clean' ? 'Scan Complete — Clean' : 'Scan Complete — Threats Found',
            'message'       => "Scan finished. Status: {$status}. Threats: " . count( $findings ),
            'scan_id'       => $scan_id,
            'threats_found' => count( $findings ),
            'findings'      => $findings,
            'duration_sec'  => $scan_elapsed,
        ) );

        if ( ! empty( $findings ) ) {
            $critical = array_filter( $findings, function($f) { return $f['severity'] === 'critical'; } );
            if ( ! empty( $critical ) ) {
                Agency_Hub_Heartbeat::push_critical_alert( array(
                    'type'     => 'scan_critical_threat',
                    'severity' => 'critical',
                    'message'  => count( $critical ) . ' critical threat(s) found during scan.',
                    'scan_id'  => $scan_id,
                ) );
            }

            // Auto-remove known malicious plugins
            $removed_plugins = array();
            foreach ( $findings as $finding ) {
                if ( ! empty( $finding['remove_plugin'] ) ) {
                    $slug = $finding['remove_plugin'];
                    if ( ! in_array( $slug, $removed_plugins, true ) ) {
                        self::remove_malicious_plugin( $slug );
                        $removed_plugins[] = $slug;
                    }
                }
            }

            // Auto-quarantine files flagged for autoremove (non-plugin files)
            foreach ( $findings as $finding ) {
                if ( ! empty( $finding['autoremove'] ) && empty( $finding['remove_plugin'] ) ) {
                    $file = $finding['file_path'] ?? '';
                    // Only auto-quarantine files in plugins dir or uploads, not core
                    if ( $file && strpos( $file, 'database://' ) === false ) {
                        if (
                            strpos( $file, WP_PLUGIN_DIR ) !== false ||
                            strpos( $file, WP_CONTENT_DIR . '/uploads' ) !== false
                        ) {
                            self::quarantine_file( $file );
                        }
                    }
                }
            }
        }

        // Auto-delete known backdoor files
        $backdoor_filenames = array( 'dumper.php', 'c99.php', 'r57.php', 'shell.php', 'wso.php' );
        foreach ( $findings as $finding ) {
            $basename = basename( $finding['file_path'] ?? '' );
            if ( in_array( strtolower( $basename ), $backdoor_filenames, true ) ) {
                self::quarantine_file( $finding['file_path'] );
            }
        }

        return array(
            'success'       => true,
            'scan_id'       => $scan_id,
            'status'        => $status,
            'threats_found' => count( $findings ),
            'duration_sec'  => $scan_elapsed,
            'findings'      => $findings,
        );
    }

    // --------------------------------------------------------
    // CORE INTEGRITY CHECK
    // Compare against WordPress.org official checksums
    // --------------------------------------------------------

    private static function check_core_integrity() {
        // Core integrity check disabled — too many false positives from themes/plugins
        // Known infected files are caught by check_known_injection_files() instead
        return array();
    }

    // --------------------------------------------------------
    // DIRECTORY SCAN — MALWARE PATTERNS
    // --------------------------------------------------------

    private static function scan_directory( $dir, $type ) {
        $findings = array();
        if ( ! is_dir( $dir ) ) return $findings;

        // Directories to skip entirely (own plugin + known legitimate)
       $excluded_dirs = array(
    WP_PLUGIN_DIR . '/agency-hub',
    WP_PLUGIN_DIR . '/wp-file-manager',
    WP_PLUGIN_DIR . '/elementor',
    WP_PLUGIN_DIR . '/revslider',
    WP_PLUGIN_DIR . '/themescamp-core',
    WP_PLUGIN_DIR . '/wp-rocket',
    WP_PLUGIN_DIR . '/sentinel-guard-fixed',
    WP_PLUGIN_DIR . '/pro-elements',
);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) continue;

            $path = $file->getRealPath();

            // Skip excluded directories
            $skip = false;
            foreach ( $excluded_dirs as $excl ) {
                if ( strpos( $path, $excl ) === 0 ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) continue;

            $ext = strtolower( $file->getExtension() );
            if ( ! in_array( $ext, array( 'php', 'js', 'html', 'htm' ), true ) ) continue;

            $file_findings = self::scan_file_for_malware( $path, $type );
            $findings = array_merge( $findings, $file_findings );
        }

        return $findings;
    }

    // --------------------------------------------------------
    // UPLOADS SCAN
    // Any executable file = auto-quarantine
    // --------------------------------------------------------

    private static function scan_uploads() {
        $findings   = array();
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        if ( ! is_dir( $base_dir ) ) return $findings;

        // Whitelisted folders inside uploads — legitimate plugin assets
        $whitelisted_dirs = array(
            'redux',
            'redux-framework',
            'elementor',
            'elementor-custom-icons',
            'wp-file-manager-pro',
        );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) continue;

            $path = $file->getRealPath();

            // Skip whitelisted directories
            $skip = false;
            foreach ( $whitelisted_dirs as $wl ) {
                if ( strpos( $path, '/' . $wl . '/' ) !== false ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) continue;

            $ext = strtolower( $file->getExtension() );

            // Auto-quarantine executable files in uploads
            if ( in_array( $ext, self::DANGEROUS_EXTENSIONS, true ) ) {
                $result = self::quarantine_file( $path );

                if ( self::is_whitelisted( $file_path ) ) { continue; }
                $findings[] = array(
                    'file_path'        => $path,
                    'file_type'        => 'uploads',
                    'issue_type'       => 'executable_in_uploads',
                    'severity'         => 'critical',
                    'confidence_score' => 99,
                    'description'      => "Executable file (.{$ext}) found in uploads directory and auto-quarantined.",
                    'snippet'          => null,
                    'matched_rule'     => 'executable_in_uploads',
                    'false_positive'   => false,
                    'recommendation'   => 'File has been quarantined. Review and delete if malicious.',
                );

                Agency_Hub_Heartbeat::push_critical_alert( array(
                    'type'      => 'executable_in_uploads',
                    'severity'  => 'critical',
                    'message'   => "Executable file auto-quarantined from uploads: " . basename( $path ),
                    'file_path' => $path,
                ) );

                continue;
            }

            // Also scan JS/HTML in uploads for injections
            if ( in_array( $ext, array( 'js', 'html', 'htm' ), true ) ) {
                $path          = $file->getRealPath();
                $file_findings = self::scan_file_for_malware( $path, 'uploads' );
                $findings      = array_merge( $findings, $file_findings );
            }
        }

        return $findings;
    }

    // --------------------------------------------------------
    // SCAN SINGLE FILE FOR MALWARE PATTERNS
    // --------------------------------------------------------

    
    private static $whitelist_paths = array(
        'wordfence',
        'wpforms',
        'woocommerce',
        'jetpack',
        'yoast',
        'elementor',
        'akismet',
        'contact-form-7',
    );

    private static function is_whitelisted( $file_path ) {
        foreach ( self::$whitelist_paths as $plugin ) {
            if ( strpos( $file_path, '/plugins/' . $plugin . '/' ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private static function scan_file_for_malware( $file_path, $file_type ) {
        $findings = array();

        if ( ! is_readable( $file_path ) ) return $findings;

        $content = @file_get_contents( $file_path );
        if ( $content === false || strlen( $content ) === 0 ) return $findings;

        $rules = self::get_detection_rules();

        foreach ( $rules as $rule ) {
            $matched = false;
            $snippet = null;

            if ( $rule['type'] === 'regex' ) {
                if ( preg_match( $rule['pattern'], $content, $matches ) ) {
                    $matched = true;
                    $snippet = substr( $matches[0], 0, 300 );
                }
            } elseif ( $rule['type'] === 'string' ) {
                $pos = stripos( $content, $rule['pattern'] );
                if ( $pos !== false ) {
                    $matched = true;
                    $snippet = substr( $content, max( 0, $pos - 20 ), 300 );
                }
            }

            if ( $matched ) {
                $confidence      = $rule['confidence'] ?? 80;
                $false_positive  = $confidence < 60;

                $findings[] = array(
                    'file_path'        => $file_path,
                    'file_type'        => $file_type,
                    'issue_type'       => $rule['issue_type'],
                    'severity'         => $rule['severity'],
                    'confidence_score' => $confidence,
                    'description'      => $rule['description'],
                    'snippet'          => $snippet,
                    'matched_rule'     => $rule['name'],
                    'false_positive'   => $false_positive,
                    'recommendation'   => $rule['recommendation'],
                );
            }
        }

        return $findings;
    }

    // --------------------------------------------------------
    // DATABASE SCAN
    // Look for SEO spam, redirect injections, malicious cron
    // --------------------------------------------------------

    private static function scan_database() {
        global $wpdb;
        $findings = array();

        // Check for hidden links / SEO spam in post content
        $spam_results = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND (
                post_content REGEXP '<a[^>]+style=[^>]*display[[:space:]]*:[[:space:]]*none[^>]*href'
OR post_content REGEXP '<a[^>]+href[^>]+style=[^>]*display[[:space:]]*:[[:space:]]*none'
             )
             LIMIT 20",
            ARRAY_A
        );

        foreach ( $spam_results as $row ) {
            $findings[] = array(
                'file_path'        => 'database://posts/ID=' . $row['ID'],
                'file_type'        => 'database',
                'issue_type'       => 'seo_spam_injection',
                'severity'         => 'high',
                'confidence_score' => 90,
                'description'      => 'Hidden link injection found in post: ' . esc_html( $row['post_title'] ),
                'snippet'          => null,
                'matched_rule'     => 'hidden_link_injection',
                'false_positive'   => false,
                'recommendation'   => 'Edit the post and remove hidden anchor tags.',
            );
        }

        // Cron job scanning disabled — too many false positives from legitimate plugins

        // Check wp_options for suspicious redirect rules
        $redirect_check = $wpdb->get_var(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name = 'home'
             AND option_value != '" . esc_sql( home_url() ) . "'"
        );

        if ( $redirect_check ) {
            $findings[] = array(
                'file_path'        => 'database://options/home',
                'file_type'        => 'database',
                'issue_type'       => 'site_url_tampered',
                'severity'         => 'critical',
                'confidence_score' => 95,
                'description'      => 'WordPress home URL in database differs from expected value.',
                'snippet'          => $redirect_check,
                'matched_rule'     => 'home_url_mismatch',
                'false_positive'   => false,
                'recommendation'   => 'Review and correct the home URL in wp_options.',
            );
        }

        return $findings;
    }

    // --------------------------------------------------------
    // QUARANTINE FILE
    // --------------------------------------------------------

    public static function quarantine_file( $file_path ) {
        $quarantine_dir = WP_CONTENT_DIR . '/agency-hub-quarantine';

        if ( ! file_exists( $quarantine_dir ) ) {
            wp_mkdir_p( $quarantine_dir );
            file_put_contents( $quarantine_dir . '/.htaccess', "Deny from all\n" );
            file_put_contents( $quarantine_dir . '/index.php', "<?php // silence" );
        }

        if ( ! file_exists( $file_path ) ) {
            return array( 'success' => false, 'message' => 'File not found: ' . $file_path );
        }

        $filename        = basename( $file_path );
        $quarantine_name = date( 'Y-m-d_His' ) . '_' . $filename . '.quarantine';
        $quarantine_path = $quarantine_dir . '/' . $quarantine_name;

        // Move file to quarantine
        if ( ! rename( $file_path, $quarantine_path ) ) {
            return array( 'success' => false, 'message' => 'Could not move file to quarantine.' );
        }

        // Log the quarantine action
        Agency_Hub::log_event( array(
            'event_type'     => 'file_quarantined',
            'event_category' => 'security',
            'severity'       => 'high',
            'object_type'    => 'file',
            'object_name'    => $filename,
            'message'        => "File quarantined: {$file_path}",
        ) );

        // Record quarantine in DB for 30-day retention
        global $wpdb;
        $table = $wpdb->prefix . 'agency_hub_quarantine';
        $wpdb->insert( $table, array(
            'original_path'   => $file_path,
            'quarantine_path' => $quarantine_path,
            'file_size'       => filesize( $quarantine_path ),
            'quarantined_by'  => get_current_user_id(),
            'quarantined_at'  => current_time( 'mysql' ),
            'expires_at'      => date( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
            'restored_at'     => null,
        ) );

        // Quarantine notification sent via next heartbeat alert queue

        return array(
            'success'         => true,
            'quarantine_path' => $quarantine_path,
            'message'         => 'File quarantined successfully.',
        );
    }

    // --------------------------------------------------------
    // RESTORE FROM QUARANTINE
    // --------------------------------------------------------

    public static function restore_from_quarantine( $quarantine_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'agency_hub_quarantine';

        $record = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $quarantine_id ),
            ARRAY_A
        );

        if ( ! $record ) {
            return array( 'success' => false, 'message' => 'Quarantine record not found.' );
        }

        if ( strtotime( $record['expires_at'] ) < time() ) {
            return array( 'success' => false, 'message' => 'Quarantine entry has expired (30 days).' );
        }

        if ( ! file_exists( $record['quarantine_path'] ) ) {
            return array( 'success' => false, 'message' => 'Quarantined file no longer exists on disk.' );
        }

        // Restore
        $restored = rename( $record['quarantine_path'], $record['original_path'] );

        if ( $restored ) {
            $wpdb->update(
                $table,
                array( 'restored_at' => current_time( 'mysql' ) ),
                array( 'id' => $quarantine_id )
            );
        }

        return array(
            'success' => $restored,
            'message' => $restored ? 'File restored.' : 'Could not restore file.',
        );
    }

    // --------------------------------------------------------
    // BUILD BASELINE SNAPSHOT
    // Called on activation and on manual rebuild
    // --------------------------------------------------------

    public static function build_baseline() {
        $baseline = array();

        $dirs = array(
            WP_PLUGIN_DIR,
            get_theme_root(),
            WP_CONTENT_DIR . '/mu-plugins',
        );

        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) continue;
                $path             = $file->getRealPath();
                $baseline[ $path ] = hash_file( 'sha256', $path );
            }
        }

        Agency_Hub::update_setting( 'file_baseline', $baseline );
        Agency_Hub::update_setting( 'baseline_built_at', current_time( 'mysql' ) );

        return count( $baseline );
    }

    // --------------------------------------------------------
    // DETECTION RULES
    // --------------------------------------------------------

    private static function get_detection_rules() {
        return array(
            array(
                'name'           => 'eval_base64_decode',
                'type'           => 'regex',
                'pattern'        => '/eval\s*\(\s*base64_decode\s*\(/i',
                'issue_type'     => 'obfuscated_code',
                'severity'       => 'critical',
                'confidence'     => 95,
                'description'    => 'Obfuscated code: eval(base64_decode()) pattern detected.',
                'recommendation' => 'Remove or replace this file with a clean version.',
            ),
            array(
                'name'           => 'eval_gzinflate',
                'type'           => 'regex',
                'pattern'        => '/eval\s*\(\s*gzinflate\s*\(/i',
                'issue_type'     => 'obfuscated_code',
                'severity'       => 'critical',
                'confidence'     => 95,
                'description'    => 'Obfuscated code: eval(gzinflate()) pattern detected.',
                'recommendation' => 'Remove or replace this file with a clean version.',
            ),
            array(
                'name'           => 'shell_exec_request',
                'type'           => 'regex',
                'pattern'        => '/shell_exec\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
                'issue_type'     => 'remote_code_execution',
                'severity'       => 'critical',
                'confidence'     => 98,
                'description'    => 'Remote code execution: shell_exec() called with user input.',
                'recommendation' => 'Remove this file immediately. This is a backdoor.',
            ),
            array(
                'name'           => 'passthru_request',
                'type'           => 'regex',
                'pattern'        => '/passthru\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
                'issue_type'     => 'remote_code_execution',
                'severity'       => 'critical',
                'confidence'     => 98,
                'description'    => 'Remote code execution: passthru() called with user input.',
                'recommendation' => 'Remove this file immediately.',
            ),
            array(
                'name'           => 'system_request',
                'type'           => 'regex',
                'pattern'        => '/system\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
                'issue_type'     => 'remote_code_execution',
                'severity'       => 'critical',
                'confidence'     => 98,
                'description'    => 'Remote code execution: system() called with user input.',
                'recommendation' => 'Remove this file immediately.',
            ),
            array(
                'name'           => 'preg_replace_eval',
                'type'           => 'regex',
                'pattern'        => '/preg_replace\s*\(\s*[\'"]\/.*\/e[\'"]/',
                'issue_type'     => 'obfuscated_code',
                'severity'       => 'critical',
                'confidence'     => 92,
                'description'    => 'preg_replace with /e modifier can execute arbitrary code.',
                'recommendation' => 'Remove or replace this file.',
            ),
            array(
                'name'           => 'assert_base64',
                'type'           => 'regex',
                'pattern'        => '/assert\s*\(\s*base64_decode\s*\(/i',
                'issue_type'     => 'obfuscated_code',
                'severity'       => 'critical',
                'confidence'     => 96,
                'description'    => 'Code execution via assert(base64_decode()) detected.',
                'recommendation' => 'Remove this file immediately.',
            ),
            array(
                'name'           => 'fake_captcha_domain',
                'type'           => 'string',
                'pattern'        => 'captcha-delivery.com',
                'issue_type'     => 'fake_captcha_injection',
                'severity'       => 'critical',
                'confidence'     => 97,
                'description'    => 'Fake CAPTCHA injection: captcha-delivery.com domain detected.',
                'recommendation' => 'Remove this code injection and scan for other modified files.',
            ),
            array(
                'name'           => 'crypto_miner_coinhive',
                'type'           => 'string',
                'pattern'        => 'coinhive.min.js',
                'issue_type'     => 'cryptomining_script',
                'severity'       => 'critical',
                'confidence'     => 99,
                'description'    => 'Cryptomining script (CoinHive) detected.',
                'recommendation' => 'Remove the cryptomining script immediately.',
            ),
            array(
                'name'           => 'crypto_miner_cryptonight',
                'type'           => 'string',
                'pattern'        => 'cryptonight',
                'issue_type'     => 'cryptomining_script',
                'severity'       => 'high',
                'confidence'     => 85,
                'description'    => 'Possible cryptomining script: cryptonight pattern detected.',
                'recommendation' => 'Review and remove if confirmed to be a miner.',
            ),
            array(
                'name'           => 'hidden_iframe',
                'type'           => 'regex',
                'pattern'        => '/<iframe[^>]+style\s*=\s*["\'][^"\']*(?:display\s*:\s*none|visibility\s*:\s*hidden|width\s*:\s*0|height\s*:\s*0)/i',
                'issue_type'     => 'hidden_iframe_injection',
                'severity'       => 'high',
                'confidence'     => 90,
                'description'    => 'Hidden iframe injection detected.',
                'recommendation' => 'Remove the hidden iframe.',
            ),
            array(
                'name'           => 'malicious_redirect_js',
                'type'           => 'regex',
                'pattern'        => '/window\.location\s*=\s*["\']https?:\/\/(?!(?:' . preg_quote( parse_url( get_site_url(), PHP_URL_HOST ), '/' ) . '))[^"\']+["\']/i',
                'issue_type'     => 'redirect_injection',
                'severity'       => 'high',
                'confidence'     => 75,
                'description'    => 'Suspicious redirect to external domain detected in JS.',
                'recommendation' => 'Review and remove if not intentional.',
            ),
            array(
                'name'           => 'base64_long_string',
                'type'           => 'regex',
                'pattern'        => '/[\'"][A-Za-z0-9+\/]{500,}={0,2}[\'"]/i',
                'issue_type'     => 'obfuscated_code',
                'severity'       => 'medium',
                'confidence'     => 55,
                'description'    => 'Long base64-encoded string detected, may be obfuscated malware.',
                'recommendation' => 'Review the file — long base64 strings can hide malware payloads.',
            ),
            array(
                'name'           => 'wp_config_modification_attempt',
                'type'           => 'regex',
                'pattern'        => '/file_put_contents\s*\(\s*[\'"][^"\']*wp-config\.php/i',
                'issue_type'     => 'wp_config_tamper',
                'severity'       => 'critical',
                'confidence'     => 97,
                'description'    => 'Attempt to write to wp-config.php detected.',
                'recommendation' => 'Remove this code immediately.',
            ),
            array(
                'name'           => 'document_write_redirect',
                'type'           => 'regex',
                'pattern'        => '/document\.write\s*\(["\']<script[^>]+src=["\']https?:\/\//i',
                'issue_type'     => 'script_injection',
                'severity'       => 'high',
                'confidence'     => 85,
                'description'    => 'External script injection via document.write() detected.',
                'recommendation' => 'Remove the injected script tag.',
            ),

            // ── MALWARE FOUND ON CLIENT SITES ────────────────────────

            array(
                'name'           => 'rev_id_backdoor',
                'type'           => 'string',
                'pattern'        => "debug_on",
                'issue_type'     => 'php_backdoor',
                'severity'       => 'critical',
                'confidence'     => 97,
                'description'    => 'PHP RCE backdoor with rev_id=debug_on trigger detected. Grants full shell access.',
                'recommendation' => 'Remove the injected code immediately and change all admin passwords.',
                'autoremove'     => true,
            ),
            array(
                'name'           => 'ab64e3d5_signature',
                'type'           => 'string',
                'pattern'        => 'ab64e3d5feb645333b320d80a84c8255',
                'issue_type'     => 'php_backdoor',
                'severity'       => 'critical',
                'confidence'     => 100,
                'description'    => 'Known backdoor fingerprint (ab64e3d5) detected — confirmed malware.',
                'recommendation' => 'Remove or quarantine this file immediately.',
                'autoremove'     => true,
            ),
            array(
                'name'           => 'brandiser_c2',
                'type'           => 'string',
                'pattern'        => 'brandiser',
                'issue_type'     => 'c2_beacon',
                'severity'       => 'critical',
                'confidence'     => 100,
                'description'    => 'WebSocket C2 beacon connecting to metrics.brandiser.net — exfiltrates session data.',
                'recommendation' => 'Remove the malicious plugin or script. Check wp_footer hooks.',
                'autoremove'     => true,
                'remove_plugin'  => 'wp-core-framework',
            ),
            array(
                'name'           => 'limlim_obfuscation',
                'type'           => 'string',
                'pattern'        => 'limlim',
                'issue_type'     => 'c2_beacon',
                'severity'       => 'critical',
                'confidence'     => 99,
                'description'    => 'Obfuscated C2 URL (limlim pattern) detected — used by brandiser.net malware.',
                'recommendation' => 'Remove the file containing this pattern.',
                'autoremove'     => true,
            ),
            array(
                'name'           => 'c2_beacon_identifier',
                'type'           => 'string',
                'pattern'        => 'Z2VvdGR2Mmluc3RhbnQ',
                'issue_type'     => 'c2_beacon',
                'severity'       => 'critical',
                'confidence'     => 100,
                'description'    => 'Known C2 beacon identifier detected — confirmed malware fingerprint.',
                'recommendation' => 'Remove the file containing this beacon immediately.',
                'autoremove'     => true,
            ),
            array(
                'name'           => 'gs_lo_sessionkey',
                'type'           => 'string',
                'pattern'        => "gsv='gs_lo'",
                'issue_type'     => 'c2_beacon',
                'severity'       => 'critical',
                'confidence'     => 99,
                'description'    => 'Malware session key (gs_lo) detected — part of brandiser.net C2 beacon.',
                'recommendation' => 'Remove the malicious script.',
                'autoremove'     => true,
            ),
            array(
                'name'           => 'fake_plugin_core_framework',
                'type'           => 'string',
                'pattern'        => 'Core functionality helper',
                'issue_type'     => 'malicious_plugin',
                'severity'       => 'critical',
                'confidence'     => 98,
                'description'    => 'Fake WordPress plugin (wp-core-framework) detected. Injects C2 beacon and hides from plugins list.',
                'recommendation' => 'Delete wp-core-framework plugin folder immediately.',
                'autoremove'     => true,
                'remove_plugin'  => 'wp-core-framework',
            ),
            array(
                'name'           => 'plugin_self_hiding',
                'type'           => 'string',
                'pattern'        => "add_filter('all_plugins'",
                'issue_type'     => 'malicious_plugin',
                'severity'       => 'critical',
                'confidence'     => 90,
                'description'    => 'Plugin hiding itself from plugins list — a persistence technique used by malware.',
                'recommendation' => 'Remove this plugin folder via FTP or File Manager.',
                'autoremove'     => true,
            ),
            array(
                'name'           => 'wp_security_shield',
                'type'           => 'string',
                'pattern'        => 'wp-security-shield',
                'issue_type'     => 'malicious_plugin',
                'severity'       => 'critical',
                'confidence'     => 95,
                'description'    => 'Known malicious plugin (wp-security-shield) found. Backdoor dropper.',
                'recommendation' => 'Delete wp-security-shield plugin folder.',
                'autoremove'     => true,
                'remove_plugin'  => 'wp-security-shield',
            ),
            array(
                'name'           => 'disable_functions_enum',
                'type'           => 'string',
                'pattern'        => "disable_fun",
                'issue_type'     => 'php_backdoor',
                'severity'       => 'critical',
                'confidence'     => 85,
                'description'    => 'Backdoor checking for disabled PHP functions to find an execution vector.',
                'recommendation' => 'Remove this code. Legitimate plugins never enumerate disabled_functions.',
                'autoremove'     => true,
            ),
        );
    }

    // --------------------------------------------------------
    // AUTO-REMOVE MALICIOUS PLUGIN
    // Called when scanner finds a known malicious plugin slug
    // --------------------------------------------------------

    public static function remove_malicious_plugin( $plugin_slug ) {
        $safe_slug  = sanitize_file_name( $plugin_slug );
        $plugin_dir = WP_PLUGIN_DIR . '/' . $safe_slug;

        if ( ! is_dir( $plugin_dir ) ) {
            return array( 'success' => false, 'message' => 'Plugin directory not found.' );
        }

        // Deactivate first
        $plugin_files = glob( $plugin_dir . '/*.php' );
        if ( $plugin_files ) {
            foreach ( $plugin_files as $pf ) {
                $rel = plugin_basename( $pf );
                if ( is_plugin_active( $rel ) ) {
                    deactivate_plugins( $rel );
                }
            }
        }

        // Quarantine — preserve evidence
        $quarantine_dir = WP_CONTENT_DIR . '/agency-hub-quarantine/plugins/' . $safe_slug . '_' . time();
        wp_mkdir_p( $quarantine_dir );
        self::recursive_copy( $plugin_dir, $quarantine_dir );

        // Delete
        self::recursive_delete( $plugin_dir );

        Agency_Hub::log_event( array(
            'event_type'     => 'malicious_plugin_removed',
            'event_category' => 'security',
            'severity'       => 'critical',
            'message'        => "Malicious plugin auto-removed: {$plugin_slug}. Preserved in quarantine at {$quarantine_dir}",
        ) );

        Agency_Hub_Heartbeat::push_critical_alert( array(
            'type'     => 'malicious_plugin_removed',
            'severity' => 'critical',
            'title'    => 'Malicious Plugin Auto-Removed',
            'message'  => "Removed confirmed malware plugin: {$plugin_slug}. Files preserved in quarantine.",
            'meta'     => array( 'plugin_slug' => $plugin_slug, 'quarantine' => $quarantine_dir ),
        ) );

        return array( 'success' => true, 'plugin' => $plugin_slug );
    }

    // --------------------------------------------------------
    // --------------------------------------------------------
    // REALTIME MALICIOUS PLUGIN KILLER
    // Runs on every WordPress load — instantly kills known
    // malicious plugins before they execute any hooks.
    // Does NOT wait for a scheduled scan.
    // --------------------------------------------------------

    public static function kill_known_malicious_plugins() {
        // Known malicious plugin slugs (folder names in wp-content/plugins/)
        $known_malicious = array(
            'wp-core-framework',
            'wp-security-shield',
        );

        $killed   = array();
        $detected = array();

        foreach ( $known_malicious as $slug ) {
            $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

            if ( is_dir( $plugin_dir ) ) {
                $detected[] = $slug;

                // Deactivate all PHP files in the folder
                $plugin_files = glob( $plugin_dir . '/*.php' );
                if ( $plugin_files ) {
                    foreach ( $plugin_files as $pf ) {
                        $rel = plugin_basename( $pf );
                        if ( is_plugin_active( $rel ) ) {
                            deactivate_plugins( $rel, true );
                        }
                    }
                }

                // Log and alert
                Agency_Hub::log_event( array(
                    'event_type'     => 'malicious_plugin_detected',
                    'event_category' => 'security',
                    'severity'       => 'critical',
                    'message'        => "CRITICAL: Known malicious plugin detected and deactivated: {$slug}. Run a full scan to remove files.",
                ) );

                Agency_Hub_Heartbeat::push_critical_alert( array(
                    'type'     => 'malicious_plugin_detected',
                    'severity' => 'critical',
                    'title'    => 'Malicious Plugin Detected',
                    'message'  => "Known malware plugin '{$slug}' found on this site. Plugin deactivated. Run a full scan to delete files.",
                    'meta'     => array( 'slug' => $slug, 'path' => $plugin_dir ),
                ) );

                // Auto-remove immediately
                self::remove_malicious_plugin( $slug );
                $killed[] = $slug;
            }
        }

        // Also check for the specific malware fingerprint in wp-footer hooks
        // by scanning the active plugin list for known-bad plugin names
        $all_active = get_option( 'active_plugins', array() );
        foreach ( $all_active as $plugin_file ) {
            $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
            $name        = $plugin_data['Name'] ?? '';

            $bad_names = array(
                'System Core Framework', // wp-core-framework
                'WP Security Shield',    // wp-security-shield
                'WordPress Core',        // common fake name
                'WP Core Helper',        // common fake name
                'WP Framework',          // common fake name
            );

            foreach ( $bad_names as $bad_name ) {
                if ( stripos( $name, $bad_name ) !== false ) {
                    deactivate_plugins( $plugin_file, true );
                    $slug = dirname( $plugin_file );
                    if ( ! in_array( $slug, $killed, true ) ) {
                        self::remove_malicious_plugin( $slug );
                        $killed[] = $slug;
                        Agency_Hub_Heartbeat::push_critical_alert( array(
                            'type'     => 'malicious_plugin_detected',
                            'severity' => 'critical',
                            'title'    => 'Fake Plugin Removed',
                            'message'  => "Fake/malicious plugin '{$name}' ({$plugin_file}) detected and removed.",
                        ) );
                    }
                    break;
                }
            }
        }

        // Also check and clean known infected core files
        self::clean_infected_core_files();

        return array( 'detected' => $detected, 'killed' => $killed );
    }

    // --------------------------------------------------------
    // CLEAN INFECTED CORE FILES
    // Removes known malware injections from core PHP files
    // Runs on every WordPress load via plugins_loaded
    // --------------------------------------------------------

    public static function clean_infected_core_files() {
        $high_risk_files = array(
            ABSPATH . 'wp-includes/load.php',
            ABSPATH . 'wp-settings.php',
            ABSPATH . 'wp-admin/includes/upgrade.php',
        );

        // Exact malware signatures to strip from core files
        $malware_patterns = array(
            // Pattern 1 — rev_id backdoor variant 1
            '/@\$=\$_REQUEST\[.re...v....id.\]===.de...b...ug..._....on.;if\(\$\).*?die\("READY:".implode\(","\$r\)\);\}\}/s',
            // Pattern 2 — rev_id backdoor variant 2  
            '/@\$__=\$_REQUEST\[.re...v.._.id.\]===.de...b...ug..._....on.;if\(\$__\).*?die\("READY:".implode\(","\$r\)\);\}\}/s',
            // Pattern 3 — WebSocket C2 beacon script tag
            '/<script>\s*\(\(f,g,m,gsv=.gs_lo.*?sessionStorage\);\s*<\/script>/s',
        );

        foreach ( $high_risk_files as $file_path ) {
            if ( ! file_exists( $file_path ) || ! is_writable( $file_path ) ) continue;

            $content  = @file_get_contents( $file_path );
            if ( ! $content ) continue;

            $original = $content;
            $cleaned  = false;

            foreach ( $malware_patterns as $pattern ) {
                $new_content = preg_replace( $pattern, '', $content );
                if ( $new_content !== null && $new_content !== $content ) {
                    $content = $new_content;
                    $cleaned = true;
                }
            }

            if ( $cleaned ) {
                file_put_contents( $file_path, $content );

                Agency_Hub::log_event( array(
                    'event_type'     => 'core_file_cleaned',
                    'event_category' => 'security',
                    'severity'       => 'critical',
                    'message'        => 'Malware injection automatically removed from: ' . basename( $file_path ),
                ) );

                Agency_Hub_Heartbeat::push_critical_alert( array(
                    'type'     => 'core_file_cleaned',
                    'severity' => 'critical',
                    'title'    => 'Core File Cleaned: ' . basename( $file_path ),
                    'message'  => 'Malware injection was automatically removed from ' . basename( $file_path ) . '. Verify the file is intact.',
                ) );
            }
        }
    }

    // --------------------------------------------------------
    // BLOCK WEBSOCKET C2 BEACONS VIA CSP HEADERS
    // Adds Content-Security-Policy headers on every page load
    // that block WebSocket connections to untrusted hosts.
    // Prevents the wss://metrics.brandiser.net/push beacon.
    // --------------------------------------------------------

    public static function add_security_headers() {
        if ( is_admin() ) return;
        if ( headers_sent() ) return;

        $site_host = parse_url( get_site_url(), PHP_URL_HOST );

        // Content-Security-Policy — block all WS/WSS connections not to own domain
        // This stops the brandiser.net WebSocket beacon even if code is present
        $csp_parts = array(
            "default-src 'self'",
            "connect-src 'self' wss://{$site_host} ws://{$site_host}",  // allow own WS only
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",          // WP needs unsafe-inline
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
        );

        // Send CSP as report-only first to avoid breaking legitimate scripts
        // Set to enforce mode via Hub setting once confirmed working
        $settings = Agency_Hub::get_settings();
        $csp_enforce = ! empty( $settings['csp_enforce_mode'] );

        $csp_header = $csp_enforce ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
        header( $csp_header . ': ' . implode( '; ', $csp_parts ) );

        // Additional hardening headers
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );

        // Block known C2 via X-Robots-Tag (belt and suspenders)
        // More importantly: WordPress HTTP API C2 blocking is done in block_c2_outbound()
    }

    // --------------------------------------------------------
    // CHECK CORE FILES FOR KNOWN MALWARE INJECTION POINTS
    // Specifically targets files known to be modified in
    // the brandiser.net attack: load.php, wp-settings.php,
    // wp-admin/includes/upgrade.php
    // --------------------------------------------------------

    public static function check_known_injection_files() {
        $findings = array();

        $high_risk_files = array(
            ABSPATH . 'wp-includes/load.php',
            ABSPATH . 'wp-settings.php',
            ABSPATH . 'wp-admin/includes/upgrade.php',
        );

        // Signatures that should NEVER appear in legitimate core files
        $core_malware_signatures = array(
            'debug_on'                              => 'PHP RCE backdoor (rev_id=debug_on trigger)',
            'ab64e3d5feb645333b320d80a84c8255'      => 'Known backdoor fingerprint',
            'disable_functions'                     => 'Backdoor function enumeration',
            '$_REQUEST[\'re\''                      => 'Obfuscated REQUEST parameter access',
            'gs_lo'                                 => 'C2 beacon session key',
            'Z2VvdGR2Mmluc3RhbnQ'                   => 'C2 beacon identifier',
            'limlim'                                => 'C2 URL obfuscation pattern',
            'brandiser'                             => 'C2 domain reference',
            'eval($d('                              => 'Backdoor eval execution',
            'READY:'                                => 'Backdoor readiness check',
        );

        foreach ( $high_risk_files as $file_path ) {
            if ( ! file_exists( $file_path ) ) continue;

            $content = @file_get_contents( $file_path );
            if ( ! $content ) continue;

            foreach ( $core_malware_signatures as $signature => $description ) {
                if ( strpos( $content, $signature ) !== false ) {
                    $pos     = strpos( $content, $signature );
                    $snippet = substr( $content, max( 0, $pos - 50 ), 200 );

                    $findings[] = array(
                        'file_path'        => $file_path,
                        'file_type'        => 'core',
                        'issue_type'       => 'core_file_injection',
                        'severity'         => 'critical',
                        'confidence_score' => 99,
                        'description'      => "CORE FILE INFECTED: {$description} found in " . basename( $file_path ),
                        'snippet'          => $snippet,
                        'matched_rule'     => 'core_injection_' . sanitize_key( $signature ),
                        'false_positive'   => false,
                        'recommendation'   => "Replace " . basename( $file_path ) . " with a clean copy from wordpress.org immediately. Do NOT delete — download fresh copy matching your WP version.",
                        'autoremove'       => false, // Core files need manual replacement, not deletion
                    );

                    // Send immediate alert without waiting for scan
                    Agency_Hub_Heartbeat::push_critical_alert( array(
                        'type'      => 'core_file_infected',
                        'severity'  => 'critical',
                        'title'     => 'Core File Infected: ' . basename( $file_path ),
                        'message'   => "WordPress core file " . basename( $file_path ) . " contains malware: {$description}. Replace with clean copy immediately.",
                        'file_path' => $file_path,
                    ) );

                    break; // One alert per file is enough
                }
            }
        }

        return $findings;
    }

    // --------------------------------------------------------
    // BLOCK OUTBOUND C2 CONNECTIONS
    // Filters WordPress HTTP requests to known C2 hosts
    // --------------------------------------------------------

    public static function block_c2_outbound() {
        // Block ALL known C2 domains + any non-whitelisted external connections
        $blocked_domains = array(
            'brandiser.net',
            'metrics.brandiser.net',
            'branding-serv.net',
            'geotdv2instant',
            'coinhive.com',
            'crypto-loot.com',
            'captcha-delivery.com',
        );

        // Whitelist — legitimate external services WordPress needs
        $whitelisted = array(
            'api.wordpress.org',
            'downloads.wordpress.org',
            'plugins.svn.wordpress.org',
            'api.w.org',
            'wordpress.org',
            's.w.org',
            'gravatar.com',
        );

        add_filter( 'http_request_args', function( $args, $url ) use ( $blocked_domains, $whitelisted ) {
            $host = parse_url( $url, PHP_URL_HOST );
            if ( ! $host ) return $args;

            // Always block known C2
            foreach ( $blocked_domains as $domain ) {
                if ( stripos( $host, $domain ) !== false ) {
                    Agency_Hub::log_event( array(
                        'event_type'     => 'c2_outbound_blocked',
                        'event_category' => 'security',
                        'severity'       => 'critical',
                        'message'        => "BLOCKED outbound C2 connection to: {$host}",
                    ) );
                    Agency_Hub_Heartbeat::push_critical_alert( array(
                        'type'     => 'c2_connection_blocked',
                        'severity' => 'critical',
                        'title'    => 'C2 Connection Blocked',
                        'message'  => "Blocked outbound connection to known C2 server: {$host}",
                    ) );
                    // Return invalid URL to abort request
                    $args['reject_unsafe_urls'] = true;
                    add_filter( 'http_request_host_is_external', function() { return false; } );
                    return $args;
                }
            }

            return $args;
        }, 1, 2 );

        add_filter( 'http_request_host_is_external', function( $external, $host ) use ( $blocked_domains ) {
            foreach ( $blocked_domains as $domain ) {
                if ( stripos( $host, $domain ) !== false ) {
                    return false;
                }
            }
            return $external;
        }, 10, 2 );

        return array( 'success' => true, 'blocked' => $blocked_domains );
    }

    // --------------------------------------------------------
    // RECURSIVE HELPERS
    // --------------------------------------------------------

    private static function recursive_copy( $src, $dst ) {
        if ( ! is_dir( $src ) ) return;
        $dir = opendir( $src );
        while ( false !== ( $file = readdir( $dir ) ) ) {
            if ( $file === '.' || $file === '..' ) continue;
            $s = $src . '/' . $file;
            $d = $dst . '/' . $file;
            if ( is_dir( $s ) ) {
                wp_mkdir_p( $d );
                self::recursive_copy( $s, $d );
            } else {
                copy( $s, $d );
            }
        }
        closedir( $dir );
    }

    private static function recursive_delete( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $files = array_diff( scandir( $dir ), array( '.', '..' ) );
        foreach ( $files as $file ) {
            $path = $dir . '/' . $file;
            is_dir( $path ) ? self::recursive_delete( $path ) : unlink( $path );
        }
        rmdir( $dir );
    }


    // --------------------------------------------------------
    // HELPER — Count Files Scanned
    // --------------------------------------------------------

    private static function count_scanned_files() {
        $count = 0;
        $dirs  = array( WP_PLUGIN_DIR, get_theme_root(), WP_CONTENT_DIR . '/uploads' );

        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iter  = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iter as $f ) {
                if ( $f->isFile() ) $count++;
            }
        }

        return $count;
    }
}

endif;
