<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Agency_Hub_Backup' ) ) :

class Agency_Hub_Backup {

    const BACKUP_DIR    = AGENCY_HUB_BACKUP_DIR;
    const MAX_BACKUPS   = 5;   // Keep last 5 on server
    const LINK_TTL      = 86400; // Download link valid 24 hours
    const CHUNK_SIZE    = 104857600; // 100MB chunks

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'agency_hub_scheduled_backup', array( __CLASS__, 'run_backup' ) );
    }

    // --------------------------------------------------------
    // REST ROUTES
    // --------------------------------------------------------

    public static function register_routes() {
        register_rest_route( 'agency-hub/v1', '/backup/run', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_run_backup' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/backup/download', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_download' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_download_token' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/backup/list', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_list' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/backup/delete', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_delete' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/backup/restore', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_restore' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( 'agency-hub/v1', '/backup/refresh-link', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_refresh_link' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );
    }

    // --------------------------------------------------------
    // HANDLERS
    // --------------------------------------------------------

    public static function handle_run_backup( WP_REST_Request $request ) {
        $options = $request->get_json_params() ?: array();
        $result  = self::run_backup( $options );
        return rest_ensure_response( $result );
    }

    public static function handle_download( WP_REST_Request $request ) {
        $file = Agency_Hub::get_setting( 'pending_download_file' );
        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'file_not_found', 'Backup file not found.', array( 'status' => 404 ) );
        }

        $filename = basename( $file );
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $file ) );
        header( 'Pragma: no-cache' );
        header( 'Cache-Control: must-revalidate' );

        // Invalidate token after download
        Agency_Hub::update_setting( 'download_token', null );
        Agency_Hub::update_setting( 'download_token_expires', null );

        readfile( $file );
        exit;
    }

    public static function handle_list( WP_REST_Request $request ) {
        $backups = self::get_backup_list();
        return rest_ensure_response( array( 'backups' => $backups ) );
    }

    public static function handle_delete( WP_REST_Request $request ) {
        $filename = sanitize_file_name( $request->get_param( 'filename' ) );
        $path     = self::BACKUP_DIR . '/' . $filename;

        if ( ! file_exists( $path ) || strpos( realpath( $path ), realpath( self::BACKUP_DIR ) ) !== 0 ) {
            return new WP_Error( 'not_found', 'Backup file not found.', array( 'status' => 404 ) );
        }

        unlink( $path );
        return rest_ensure_response( array( 'success' => true ) );
    }

    public static function handle_restore( WP_REST_Request $request ) {
        $filename = sanitize_file_name( $request->get_param( 'filename' ) );
        $result   = self::restore_from_file( $filename );
        return rest_ensure_response( $result );
    }

    public static function handle_refresh_link( WP_REST_Request $request ) {
        $filename = sanitize_file_name( $request->get_param( 'filename' ) );
        $path     = self::BACKUP_DIR . '/' . $filename;

        if ( ! file_exists( $path ) ) {
            return new WP_Error( 'not_found', 'Backup file not found.', array( 'status' => 404 ) );
        }

        $link = self::generate_download_link( $path );
        return rest_ensure_response( array( 'success' => true, 'download_link' => $link ) );
    }

    // --------------------------------------------------------
    // RUN BACKUP
    // Creates DB dump + files zip, stores locally, returns link
    // --------------------------------------------------------

    public static function run_backup( $options = array() ) {
        global $wpdb;

        $backup_id = 'backup_' . date( 'Y-m-d_His' );
        $tmp_dir   = self::BACKUP_DIR . '/tmp_' . $backup_id;

        // Ensure dirs exist and are protected
        self::ensure_backup_dir();
        wp_mkdir_p( $tmp_dir );

        $start_time = microtime( true );
        $components = array();

        Agency_Hub_API::push_to_hub( 'backup/progress', array(
            'backup_id' => $backup_id,
            'step'      => 'started',
            'progress'  => 0,
        ) );

        // ── 1. Database dump ──────────────────────────────────
        $db_file = $tmp_dir . '/database.sql';
        $db_result = self::dump_database( $db_file );

        if ( ! $db_result['success'] ) {
            self::cleanup_tmp( $tmp_dir );
            return array( 'success' => false, 'message' => 'Database dump failed: ' . $db_result['message'] );
        }

        $components['database'] = $db_file;

        Agency_Hub_API::push_to_hub( 'backup/progress', array(
            'backup_id' => $backup_id,
            'step'      => 'database_done',
            'progress'  => 25,
        ) );

        // ── 2. WordPress files zip ────────────────────────────
        $files_zip   = $tmp_dir . '/files.zip';
        $files_result = self::zip_directory( ABSPATH, $files_zip, array(
            WP_CONTENT_DIR . '/agency-hub-backups',
            WP_CONTENT_DIR . '/agency-hub-quarantine',
            WP_CONTENT_DIR . '/cache',
        ) );

        if ( $files_result ) {
            $components['files'] = $files_zip;
        }

        Agency_Hub_API::push_to_hub( 'backup/progress', array(
            'backup_id' => $backup_id,
            'step'      => 'files_done',
            'progress'  => 75,
        ) );

        // ── 3. Create final archive ───────────────────────────
        $final_zip = self::BACKUP_DIR . '/' . $backup_id . '.zip';
        $archive   = new ZipArchive();

        if ( $archive->open( $final_zip, ZipArchive::CREATE ) !== true ) {
            self::cleanup_tmp( $tmp_dir );
            return array( 'success' => false, 'message' => 'Could not create final archive.' );
        }

        // Add manifest
        $manifest = array(
            'backup_id'  => $backup_id,
            'site_url'   => get_site_url(),
            'wp_version' => get_bloginfo( 'version' ),
            'created_at' => current_time( 'mysql' ),
            'components' => array_keys( $components ),
        );
        $archive->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

        foreach ( $components as $name => $path ) {
            if ( file_exists( $path ) ) {
                $archive->addFile( $path, $name . ( strpos( $path, '.sql' ) !== false ? '.sql' : '.zip' ) );
            }
        }

        $archive->close();
        self::cleanup_tmp( $tmp_dir );

        $file_size = filesize( $final_zip );
        $duration  = round( microtime( true ) - $start_time, 2 );

        // ── 4. Generate signed download link ─────────────────
        $download_link = self::generate_download_link( $final_zip );

        // ── 5. Prune old backups ──────────────────────────────
        self::prune_old_backups();

        // ── 6. Save metadata ──────────────────────────────────
        Agency_Hub::update_setting( 'last_backup', current_time( 'mysql' ) );
        Agency_Hub::update_setting( 'last_backup_status', 'success' );

        $result = array(
            'success'       => true,
            'backup_id'     => $backup_id,
            'filename'      => basename( $final_zip ),
            'file_size'     => $file_size,
            'duration_sec'  => $duration,
            'download_link' => $download_link,
            'expires_at'    => date( 'Y-m-d H:i:s', time() + self::LINK_TTL ),
            'components'    => array_keys( $components ),
        );

        // Notify Hub
        Agency_Hub_API::push_to_hub( 'backup/complete', $result );

        Agency_Hub::log_event( array(
            'event_type'     => 'backup_created',
            'event_category' => 'backup',
            'severity'       => 'info',
            'message'        => "Backup created: {$backup_id} ({$file_size} bytes)",
        ) );

        return $result;
    }

    // --------------------------------------------------------
    // DATABASE DUMP (pure PHP, no exec/shell_exec required)
    // --------------------------------------------------------

    private static function dump_database( $output_file ) {
        global $wpdb;

        $handle = @fopen( $output_file, 'w' );
        if ( ! $handle ) {
            return array( 'success' => false, 'message' => 'Cannot write to: ' . $output_file );
        }

        fwrite( $handle, "-- Agency Hub Backup\n" );
        fwrite( $handle, "-- Site: " . get_site_url() . "\n" );
        fwrite( $handle, "-- Date: " . current_time( 'mysql' ) . "\n\n" );
        fwrite( $handle, "SET NAMES utf8mb4;\n" );
        fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n" );

        $tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );

        foreach ( $tables as $table_row ) {
            $table = $table_row[0];

            // Create table statement
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            fwrite( $handle, "-- Table: {$table}\n" );
            fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
            fwrite( $handle, $create[1] . ";\n\n" );

            // Dump rows in batches
            $offset     = 0;
            $batch_size = 500;

            do {
                $rows = $wpdb->get_results(
                    "SELECT * FROM `{$table}` LIMIT {$batch_size} OFFSET {$offset}",
                    ARRAY_N
                );

                foreach ( $rows as $row ) {
                    $values = array_map( function( $v ) use ( $wpdb ) {
                        return is_null( $v ) ? 'NULL' : "'" . $wpdb->_real_escape( $v ) . "'";
                    }, $row );
                    fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" );
                }

                $offset += $batch_size;
            } while ( count( $rows ) === $batch_size );

            fwrite( $handle, "\n" );
        }

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 1;\n" );
        fclose( $handle );

        return array( 'success' => true, 'rows' => $offset );
    }

    // --------------------------------------------------------
    // ZIP DIRECTORY (pure PHP ZipArchive)
    // --------------------------------------------------------

    private static function zip_directory( $source_dir, $output_file, $exclude_paths = array() ) {
        if ( ! class_exists( 'ZipArchive' ) ) return false;

        $source_dir = realpath( $source_dir );
        $zip        = new ZipArchive();

        if ( $zip->open( $output_file, ZipArchive::CREATE ) !== true ) return false;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) continue;

            $file_path = $file->getRealPath();

            // Skip excluded paths
            $skip = false;
            foreach ( $exclude_paths as $excluded ) {
                if ( strpos( $file_path, $excluded ) === 0 ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) continue;

            // Skip very large files (>2GB — ZipArchive limit)
            if ( filesize( $file_path ) > 2 * 1024 * 1024 * 1024 ) continue;

            $relative = ltrim( str_replace( $source_dir, '', $file_path ), '/' . DIRECTORY_SEPARATOR );
            $zip->addFile( $file_path, $relative );
        }

        $zip->close();
        return true;
    }

    // --------------------------------------------------------
    // RESTORE FROM LOCAL FILE
    // --------------------------------------------------------

    private static function restore_from_file( $filename ) {
        global $wpdb;

        $path = self::BACKUP_DIR . '/' . $filename;
        if ( ! file_exists( $path ) || strpos( realpath( $path ), realpath( self::BACKUP_DIR ) ) !== 0 ) {
            return array( 'success' => false, 'message' => 'Backup file not found.' );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return array( 'success' => false, 'message' => 'Could not open backup archive.' );
        }

        $tmp_dir = self::BACKUP_DIR . '/restore_' . time();
        wp_mkdir_p( $tmp_dir );
        $zip->extractTo( $tmp_dir );
        $zip->close();

        // Restore database
        $db_file = $tmp_dir . '/database.sql';
        if ( file_exists( $db_file ) ) {
            $sql     = file_get_contents( $db_file );
            $queries = array_filter( array_map( 'trim', explode( ";\n", $sql ) ) );
            foreach ( $queries as $query ) {
                if ( ! empty( $query ) ) {
                    $wpdb->query( $query );
                }
            }
        }

        // Restore files
        $files_zip = $tmp_dir . '/files.zip';
        if ( file_exists( $files_zip ) ) {
            $files_archive = new ZipArchive();
            if ( $files_archive->open( $files_zip ) === true ) {
                $files_archive->extractTo( ABSPATH );
                $files_archive->close();
            }
        }

        self::cleanup_tmp( $tmp_dir );

        Agency_Hub::log_event( array(
            'event_type'     => 'backup_restored',
            'event_category' => 'backup',
            'severity'       => 'high',
            'message'        => "Site restored from backup: {$filename}",
        ) );

        return array( 'success' => true, 'message' => 'Site restored successfully.' );
    }

    // --------------------------------------------------------
    // SIGNED DOWNLOAD LINK
    // --------------------------------------------------------

    private static function generate_download_link( $file_path ) {
        $token   = bin2hex( random_bytes( 32 ) );
        $expires = time() + self::LINK_TTL;

        // Store hashed token so we can verify without exposing it
        Agency_Hub::update_setting( 'download_token',         hash( 'sha256', $token ) );
        Agency_Hub::update_setting( 'download_token_expires', $expires );
        Agency_Hub::update_setting( 'pending_download_file',  $file_path );

        return rest_url( 'agency-hub/v1/backup/download' ) . '?token=' . urlencode( $token ) . '&expires=' . $expires;
    }

    // --------------------------------------------------------
    // LIST BACKUPS
    // --------------------------------------------------------

    private static function get_backup_list() {
        if ( ! is_dir( self::BACKUP_DIR ) ) return array();

        $files   = glob( self::BACKUP_DIR . '/backup_*.zip' );
        $backups = array();

        if ( ! $files ) return array();

        foreach ( $files as $file ) {
            $backups[] = array(
                'filename'   => basename( $file ),
                'size'       => filesize( $file ),
                'created_at' => date( 'Y-m-d H:i:s', filemtime( $file ) ),
            );
        }

        usort( $backups, fn($a, $b) => strtotime( $b['created_at'] ) - strtotime( $a['created_at'] ) );

        return $backups;
    }

    // --------------------------------------------------------
    // PRUNE OLD BACKUPS — Keep last N
    // --------------------------------------------------------

    private static function prune_old_backups() {
        $files = glob( self::BACKUP_DIR . '/backup_*.zip' );
        if ( ! $files || count( $files ) <= self::MAX_BACKUPS ) return;

        usort( $files, fn($a, $b) => filemtime( $a ) - filemtime( $b ) );
        $to_delete = array_slice( $files, 0, count( $files ) - self::MAX_BACKUPS );

        foreach ( $to_delete as $file ) {
            @unlink( $file );
        }
    }

    // --------------------------------------------------------
    // ENSURE BACKUP DIR IS PROTECTED
    // --------------------------------------------------------

    private static function ensure_backup_dir() {
        if ( ! is_dir( self::BACKUP_DIR ) ) {
            wp_mkdir_p( self::BACKUP_DIR );
        }

        $htaccess = self::BACKUP_DIR . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\nOptions -Indexes\n" );
        }

        $index = self::BACKUP_DIR . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // silence is golden" );
        }
    }

    // --------------------------------------------------------
    // CLEANUP TEMP DIR
    // --------------------------------------------------------

    private static function cleanup_tmp( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $files as $fileinfo ) {
            $fileinfo->isDir() ? rmdir( $fileinfo->getRealPath() ) : unlink( $fileinfo->getRealPath() );
        }
        rmdir( $dir );
    }
}

endif;
