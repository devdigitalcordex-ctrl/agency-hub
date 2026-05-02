<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Agency_Hub_Updater {

    private $plugin_slug = 'agency-hub/agency-hub.php';
    private $hub_url;

    public function __construct() {
        $this->hub_url = Agency_Hub::get_setting( 'hub_url', '' );
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
    }

    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;
        $remote = $this->get_remote_info();
        if ( $remote && version_compare( AGENCY_HUB_VERSION, $remote->version, '<' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) array(
                'slug'        => 'agency-hub',
                'plugin'      => $this->plugin_slug,
                'new_version' => $remote->version,
                'url'         => $this->hub_url,
                'package'     => $remote->download_url ?? $this->hub_url . '/api/plugin/download',
            );
        }
        return $transient;
    }

    public function plugin_info( $res, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $res;
        if ( $args->slug !== 'agency-hub' ) return $res;
        $remote = $this->get_remote_info();
        if ( ! $remote ) return $res;
        return (object) array(
            'name'          => 'Agency Hub',
            'slug'          => 'agency-hub',
            'version'       => $remote->version,
            'download_link' => $this->hub_url . '/api/plugin/download',
        );
    }

    private function get_remote_info() {
        if ( empty( $this->hub_url ) ) return false;
        $cache_key = 'agency_hub_remote_version';
        $cached = get_transient( $cache_key );
        if ( $cached ) return $cached;
        $response = wp_remote_get( rtrim($this->hub_url, '/') . '/api/plugin/version', array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) ) return false;
        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $body->version ) ) return false;
        set_transient( $cache_key, $body, HOUR_IN_SECONDS );
        return $body;
    }
}
