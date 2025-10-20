<?php
namespace Realt\PropertyScrapper\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Geocoder {
    public function geocode_address( string $address ): array {
        $address = trim( $address );
        if ( '' === $address ) { return []; }
        $opts = \get_option( 'realt_ps_geocoding', [] );
        $key = (string) ( $opts['mapycz_api_key'] ?? '' );
        if ( '' === $key ) { return []; }
        $url = 'https://api.mapy.cz/geocode?query=' . rawurlencode( $address ) . '&apikey=' . rawurlencode( $key );
        $resp = \wp_remote_get( $url, [ 'timeout' => 10 ] );
        if ( \is_wp_error( $resp ) ) { return []; }
        $code = (int) \wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) { return []; }
        $json = (string) \wp_remote_retrieve_body( $resp );
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) { return []; }
        // Expected structure is not standardized; try common patterns
        // Prefer first result
        $lat = null; $lng = null; $citySlug = '';
        $walk = function( $node ) use ( &$walk, &$lat, &$lng, &$citySlug ) {
            if ( is_array( $node ) ) {
                if ( isset( $node['lat'], $node['lon'] ) && null === $lat && null === $lng ) {
                    $lat = (float) $node['lat'];
                    $lng = (float) ( $node['lng'] ?? $node['lon'] );
                }
                if ( isset( $node['city'] ) && '' === $citySlug ) {
                    $citySlug = sanitize_title( (string) $node['city'] );
                }
                foreach ( $node as $child ) { $walk( $child ); }
            }
        };
        $walk( $data );
        if ( null === $lat || null === $lng ) { return []; }
        return [ 'lat' => (float) $lat, 'lng' => (float) $lng, 'city_slug' => (string) $citySlug ];
    }
}

?>


