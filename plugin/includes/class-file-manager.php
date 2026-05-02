<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Agency_Hub_File_Manager' ) ) :

class Agency_Hub_File_Manager {

    // Root allowed path — never go above this
    const ROOT = ABSPATH;

    // Version history dir
    const VERSION_DIR = AGENCY_HUB_BACKUP_DIR . '/file-versions';

    // Editable text extensions
    const EDITABLE_EXT = array(
        'php', 'js', 'css', 'html', 'htm', 'txt', 'md',
        'json', 'xml', 'svg', 'htaccess', 'conf', 'ini',
        'sql', 'sh', 'env', 'log', 'ts', 'jsx', 'tsx',
    );

    // Downloadable extensions (images, docs, etc.)
    const ALLOWED_DOWNLOAD = array(
        'php', 'js', 'css', 'html', 'htm', 'txt', 'md',
        'json', 'xml', 'svg', 'jpg', 'jpeg', 'png', 'gif',
        'webp', 'zip', 'tar', 'gz', 'sql', 'csv', 'pdf',
        'woff', 'woff2', 'ttf', 'eot', 'ico',
    );

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    // --------------------------------------------------------
    // REST ROUTES
    // --------------------------------------------------------

    public static function register_routes() {
        $base = 'agency-hub/v1/files';

        register_rest_route( $base, '/list', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_list' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/read', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_read' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/write', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_write' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/delete', array(
            'methods'             => 'DELETE',
            'callback'            => array( __CLASS__, 'handle_delete' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/rename', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_rename' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/move', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_move' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/mkdir', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_mkdir' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/chmod', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_chmod' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/search', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_search' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/download', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_download' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/versions', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_versions' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );

        register_rest_route( $base, '/restore-version', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_restore_version' ),
            'permission_callback' => array( 'Agency_Hub_API', 'verify_request' ),
        ) );
    }

    // --------------------------------------------------------
    // HANDLERS
    // --------------------------------------------------------

    public static function handle_list( WP_REST_Request $request ) {
        $path = self::resolve( $request->get_param( 'path' ) ?: '/' );
        if ( is_wp_error( $path ) ) return $path;

        if ( ! is_dir( $path ) ) {
            return new WP_Error( 'not_a_directory', 'Path is not a directory.', array( 'status' => 400 ) );
        }

        $items   = array();
        $entries = @scandir( $path );
        if ( ! $entries ) {
            return rest_ensure_response( array( 'items' => array(), 'path' => self::relative( $path ) ) );
        }

        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) continue;

            $full    = $path . DIRECTORY_SEPARATOR . $entry;
            $is_dir  = is_dir( $full );
            $ext     = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
            $perms   = @fileperms( $full );

            $items[] = array(
                'name'        => $entry,
                'path'        => self::relative( $full ),
                'type'        => $is_dir ? 'directory' : 'file',
                'size'        => $is_dir ? null : @filesize( $full ),
                'extension'   => $is_dir ? null : $ext,
                'permissions' => $perms ? substr( sprintf( '%o', $perms ), -4 ) : null,
                'modified'    => @filemtime( $full ),
                'readable'    => is_readable( $full ),
                'writable'    => is_writable( $full ),
                'editable'    => ! $is_dir && in_array( $ext, self::EDITABLE_EXT, true ),
            );
        }

        // Dirs first, then files, both alphabetically
        usort( $items, function( $a, $b ) {
            if ( $a['type'] !== $b['type'] ) return $a['type'] === 'directory' ? -1 : 1;
            return strcasecmp( $a['name'], $b['name'] );
        } );

        self::log_action( 'file_browsed', $path );

        return rest_ensure_response( array(
            'items' => $items,
            'path'  => self::relative( $path ),
        ) );
    }

    public static function handle_read( WP_REST_Request $request ) {
        $path = self::resolve( $request->get_param( 'path' ) );
        if ( is_wp_error( $path ) ) return $path;

        if ( ! is_file( $path ) ) {
            return new WP_Error( 'not_a_file', 'Path is not a file.', array( 'status' => 400 ) );
        }

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, self::EDITABLE_EXT, true ) ) {
            return new WP_Error( 'not_editable', 'This file type cannot be viewed.', array( 'status' => 403 ) );
        }

        $size = filesize( $path );
        if ( $size > 5 * 1024 * 1024 ) {
            return new WP_Error( 'file_too_large', 'File is too large to display (>5MB).', array( 'status' => 413 ) );
        }

        $content = @file_get_contents( $path );
        if ( $content === false ) {
            return new WP_Error( 'read_error', 'Cannot read file.', array( 'status' => 500 ) );
        }

        self::log_action( 'file_viewed', $path );

        return rest_ensure_response( array(
            'path'     => self::relative( $path ),
            'content'  => $content,
            'size'     => $size,
            'ext'      => $ext,
            'encoding' => mb_detect_encoding( $content ) ?: 'UTF-8',
            'hash'     => md5( $content ),
        ) );
    }

    public static function handle_write( WP_REST_Request $request ) {
        $path    = self::resolve( $request->get_param( 'path' ) );
        $content = $request->get_param( 'content' );

        if ( is_wp_error( $path ) ) return $path;

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, self::EDITABLE_EXT, true ) ) {
            return new WP_Error( 'not_editable', 'This file type cannot be edited.', array( 'status' => 403 ) );
        }

        // Save version backup before overwriting
        if ( file_exists( $path ) ) {
            self::save_version( $path );
        }

        $result = @file_put_contents( $path, $content );
        if ( $result === false ) {
            return new WP_Error( 'write_error', 'Cannot write to file. Check permissions.', array( 'status' => 500 ) );
        }

        self::log_action( 'file_edited', $path );

        return rest_ensure_response( array(
            'success'  => true,
            'path'     => self::relative( $path ),
            'size'     => $result,
            'hash'     => md5( $content ),
        ) );
    }

    public static function handle_delete( WP_REST_Request $request ) {
        $path = self::resolve( $request->get_param( 'path' ) );
        if ( is_wp_error( $path ) ) return $path;

        if ( is_dir( $path ) ) {
            $deleted = self::delete_directory( $path );
        } else {
            $deleted = @unlink( $path );
        }

        if ( $deleted ) {
            self::log_action( 'file_deleted', $path );
        }

        return rest_ensure_response( array( 'success' => $deleted ) );
    }

    public static function handle_rename( WP_REST_Request $request ) {
        $path    = self::resolve( $request->get_param( 'path' ) );
        $newname = sanitize_file_name( $request->get_param( 'new_name' ) );

        if ( is_wp_error( $path ) ) return $path;

        $parent   = dirname( $path );
        $new_path = $parent . DIRECTORY_SEPARATOR . $newname;

        if ( file_exists( $new_path ) ) {
            return new WP_Error( 'already_exists', 'A file with that name already exists.', array( 'status' => 409 ) );
        }

        $result = @rename( $path, $new_path );
        if ( $result ) self::log_action( 'file_renamed', $path, array( 'new_name' => $newname ) );

        return rest_ensure_response( array(
            'success'  => $result,
            'new_path' => self::relative( $new_path ),
        ) );
    }

    public static function handle_move( WP_REST_Request $request ) {
        $source      = self::resolve( $request->get_param( 'source' ) );
        $destination = self::resolve( $request->get_param( 'destination' ) );

        if ( is_wp_error( $source ) )      return $source;
        if ( is_wp_error( $destination ) ) return $destination;

        $dest_file = is_dir( $destination )
            ? $destination . DIRECTORY_SEPARATOR . basename( $source )
            : $destination;

        $result = @rename( $source, $dest_file );
        if ( $result ) self::log_action( 'file_moved', $source, array( 'destination' => self::relative( $dest_file ) ) );

        return rest_ensure_response( array(
            'success'  => $result,
            'new_path' => self::relative( $dest_file ),
        ) );
    }

    public static function handle_mkdir( WP_REST_Request $request ) {
        $path = self::resolve( $request->get_param( 'path' ) );
        if ( is_wp_error( $path ) ) return $path;

        if ( file_exists( $path ) ) {
            return new WP_Error( 'already_exists', 'Path already exists.', array( 'status' => 409 ) );
        }

        $result = wp_mkdir_p( $path );
        if ( $result ) self::log_action( 'directory_created', $path );

        return rest_ensure_response( array( 'success' => $result ) );
    }

    public static function handle_chmod( WP_REST_Request $request ) {
        $path  = self::resolve( $request->get_param( 'path' ) );
        $mode  = octdec( $request->get_param( 'mode' ) );

        if ( is_wp_error( $path ) ) return $path;

        // Only allow sensible modes
        if ( $mode < octdec( '400' ) || $mode > octdec( '777' ) ) {
            return new WP_Error( 'invalid_mode', 'Mode must be between 0400 and 0777.', array( 'status' => 400 ) );
        }

        $result = @chmod( $path, $mode );
        if ( $result ) self::log_action( 'permissions_changed', $path, array( 'mode' => decoct( $mode ) ) );

        return rest_ensure_response( array( 'success' => $result ) );
    }

    public static function handle_search( WP_REST_Request $request ) {
        $query    = sanitize_text_field( $request->get_param( 'q' ) );
        $search_in = sanitize_text_field( $request->get_param( 'in' ) ?: 'name' ); // name | content
        $scope    = self::resolve( $request->get_param( 'path' ) ?: '/' );

        if ( is_wp_error( $scope ) ) return $scope;
        if ( empty( $query ) ) return new WP_Error( 'empty_query', 'Search query is required.', array( 'status' => 400 ) );

        $results = array();
        $limit   = 100;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $scope, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( count( $results ) >= $limit ) break;
            if ( ! $file->isFile() ) continue;

            $path = $file->getRealPath();
            $name = $file->getFilename();

            if ( $search_in === 'name' ) {
                if ( stripos( $name, $query ) !== false ) {
                    $results[] = array(
                        'path'      => self::relative( $path ),
                        'name'      => $name,
                        'match_in'  => 'filename',
                        'context'   => null,
                    );
                }
            } elseif ( $search_in === 'content' ) {
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, self::EDITABLE_EXT, true ) ) continue;
                if ( filesize( $path ) > 2 * 1024 * 1024 ) continue; // Skip files >2MB for content search

                $content = @file_get_contents( $path );
                if ( $content === false ) continue;

                $pos = stripos( $content, $query );
                if ( $pos !== false ) {
                    $context = substr( $content, max( 0, $pos - 50 ), strlen( $query ) + 100 );
                    $results[] = array(
                        'path'      => self::relative( $path ),
                        'name'      => $name,
                        'match_in'  => 'content',
                        'context'   => $context,
                        'line'      => substr_count( substr( $content, 0, $pos ), "\n" ) + 1,
                    );
                }
            }
        }

        return rest_ensure_response( array(
            'results' => $results,
            'count'   => count( $results ),
            'query'   => $query,
            'truncated' => count( $results ) >= $limit,
        ) );
    }

    public static function handle_download( WP_REST_Request $request ) {
        $path = self::resolve( $request->get_param( 'path' ) );
        if ( is_wp_error( $path ) ) return $path;

        if ( ! is_file( $path ) ) {
            return new WP_Error( 'not_a_file', 'Path is not a file.', array( 'status' => 400 ) );
        }

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, self::ALLOWED_DOWNLOAD, true ) ) {
            return new WP_Error( 'not_allowed', 'This file type cannot be downloaded.', array( 'status' => 403 ) );
        }

        self::log_action( 'file_downloaded', $path );

        $filename = basename( $path );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Cache-Control: no-store' );
        readfile( $path );
        exit;
    }

    public static function handle_versions( WP_REST_Request $request ) {
        $path     = self::resolve( $request->get_param( 'path' ) );
        if ( is_wp_error( $path ) ) return $path;

        $versions = self::get_versions( $path );
        return rest_ensure_response( array( 'versions' => $versions ) );
    }

    public static function handle_restore_version( WP_REST_Request $request ) {
        $original_path  = self::resolve( $request->get_param( 'path' ) );
        $version_index  = intval( $request->get_param( 'version_index' ) );

        if ( is_wp_error( $original_path ) ) return $original_path;

        $versions = self::get_versions( $original_path );
        if ( ! isset( $versions[ $version_index ] ) ) {
            return new WP_Error( 'version_not_found', 'Version not found.', array( 'status' => 404 ) );
        }

        $version_file = $versions[ $version_index ]['version_path'];
        if ( ! file_exists( $version_file ) ) {
            return new WP_Error( 'version_file_missing', 'Version file no longer exists on disk.', array( 'status' => 404 ) );
        }

        // Before restoring, save current as a version too
        if ( file_exists( $original_path ) ) {
            self::save_version( $original_path );
        }

        $result = @copy( $version_file, $original_path );
        if ( $result ) self::log_action( 'file_version_restored', $original_path, array( 'version_index' => $version_index ) );

        return rest_ensure_response( array( 'success' => $result ) );
    }

    // --------------------------------------------------------
    // VERSION HISTORY
    // --------------------------------------------------------

    private static function save_version( $file_path ) {
        if ( ! is_dir( self::VERSION_DIR ) ) wp_mkdir_p( self::VERSION_DIR );

        $key      = md5( $file_path );
        $ext      = pathinfo( $file_path, PATHINFO_EXTENSION );
        $ver_file = self::VERSION_DIR . '/' . $key . '_' . date( 'YmdHis' ) . '.' . $ext;

        @copy( $file_path, $ver_file );

        // Keep max 10 versions per file
        $existing = glob( self::VERSION_DIR . '/' . $key . '_*' );
        if ( $existing && count( $existing ) > 10 ) {
            usort( $existing, fn($a, $b) => filemtime( $a ) - filemtime( $b ) );
            array_splice( $existing, 9 );
            foreach ( $existing as $old ) @unlink( $old );
        }
    }

    private static function get_versions( $file_path ) {
        $key      = md5( $file_path );
        $versions = glob( self::VERSION_DIR . '/' . $key . '_*' );

        if ( ! $versions ) return array();

        usort( $versions, fn($a, $b) => filemtime( $b ) - filemtime( $a ) );

        return array_values( array_map( function( $v, $i ) use ( $file_path ) {
            return array(
                'version_index' => $i,
                'version_path'  => $v,
                'saved_at'      => date( 'Y-m-d H:i:s', filemtime( $v ) ),
                'size'          => filesize( $v ),
            );
        }, $versions, array_keys( $versions ) ) );
    }

    // --------------------------------------------------------
    // PATH RESOLUTION & SECURITY
    // All paths resolved here — never outside ABSPATH
    // --------------------------------------------------------

    private static function resolve( $relative_path ) {
        if ( empty( $relative_path ) ) $relative_path = '/';

        $root   = realpath( self::ROOT );
        $joined = $root . DIRECTORY_SEPARATOR . ltrim( $relative_path, '/' . DIRECTORY_SEPARATOR );

        // Resolve without requiring file to exist
        $real = realpath( $joined );

        // For non-existent paths (new files/dirs), normalize manually
        if ( $real === false ) {
            $real = $joined;
        }

        // Ensure path is within ABSPATH
        if ( strpos( $real, $root ) !== 0 ) {
            return new WP_Error( 'path_traversal', 'Access denied: path is outside web root.', array( 'status' => 403 ) );
        }

        return $real;
    }

    private static function relative( $absolute_path ) {
        $root = realpath( self::ROOT );
        return str_replace( $root, '', $absolute_path );
    }

    // --------------------------------------------------------
    // DELETE DIRECTORY RECURSIVELY
    // --------------------------------------------------------

    private static function delete_directory( $dir ) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $files as $fileinfo ) {
            $fileinfo->isDir() ? rmdir( $fileinfo->getRealPath() ) : unlink( $fileinfo->getRealPath() );
        }
        return rmdir( $dir );
    }

    // --------------------------------------------------------
    // LOG FILE MANAGER ACTION
    // --------------------------------------------------------

    private static function log_action( $action, $path, $extra = array() ) {
        Agency_Hub::log_event( array_merge( array(
            'event_type'     => $action,
            'event_category' => 'file_manager',
            'severity'       => in_array( $action, array( 'file_deleted', 'file_edited', 'permissions_changed' ), true ) ? 'medium' : 'info',
            'object_type'    => 'file',
            'object_name'    => basename( $path ),
            'message'        => ucwords( str_replace( '_', ' ', $action ) ) . ': ' . self::relative( $path ),
        ), $extra ) );
    }

    // --------------------------------------------------------
    // PUBLIC: delete helper used by scanner quarantine flow
    // --------------------------------------------------------

    public static function delete( $path ) {
        $resolved = self::resolve( $path );
        if ( is_wp_error( $resolved ) ) return array( 'success' => false, 'message' => 'Path error.' );

        if ( is_dir( $resolved ) ) {
            $ok = self::delete_directory( $resolved );
        } else {
            $ok = @unlink( $resolved );
        }

        return array( 'success' => $ok );
    }
}

endif;
