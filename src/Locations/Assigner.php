<?php
namespace Realt\PropertyScrapper\Locations;

use Realt\PropertyScrapper\Utils\Logger;
use Realt\PropertyScrapper\Utils\Geocoder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assigner {
    private $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    public function assign( string $address, string $city = '', $lat = null, $lng = null ): array {
        $result = [ 'city_slug' => '', 'area_slug' => '', 'via' => '', 'confidence' => 0.0 ];

        $citySlug = $this->derive_city_slug( $city, $address );
        $streetNorm = $this->normalize_street_from_address( $address );

        // Debug mode: skip CSV and GeoJSON matching entirely
        $importOpts = \get_option( 'realt_ps_import', [] );
        $debugMode = (int) ( $importOpts['debug_mode'] ?? 0 ) === 1;
        if ( $debugMode ) {
            // Only use heuristic/city derivation; no street map or polygon/geocode
            $areaSlugHeu = $this->derive_area_slug_from_address( $address );
            if ( $areaSlugHeu ) {
                $result['city_slug'] = $citySlug ?: $result['city_slug'];
                $result['area_slug'] = $areaSlugHeu;
                $result['via'] = 'heuristic_debug';
                $result['confidence'] = 0.5;
            } else {
                $result['city_slug'] = $citySlug ?: $result['city_slug'];
                $result['via'] = 'debug_city_only';
                $result['confidence'] = $result['city_slug'] ? 0.4 : 0.0;
            }
            return $result;
        }

        // 1) Street -> Area CSV mapping (primary)
        $map = $this->load_street_map();
        if ( $streetNorm && $map ) {
            if ( isset( $map[ $streetNorm ] ) ) {
                // If multiple candidates, prefer same city when available
                $candidates = $map[ $streetNorm ]; // [ [city_slug, area_slug], ... ]
                $chosen = $candidates[0];
                if ( $citySlug ) {
                    foreach ( $candidates as $cand ) {
                        if ( $cand[0] === $citySlug ) { $chosen = $cand; break; }
                    }
                }
                $result['city_slug'] = $chosen[0] ?: $citySlug;
                $result['area_slug'] = $chosen[1];
                $result['via'] = 'street_map';
                $result['confidence'] = 0.95;
                return $result;
            }
        }

        // 2) GeoJSON Point-in-Polygon if we have coordinates
        if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
            $polyHit = $this->match_polygon( (float) $lat, (float) $lng );
            if ( $polyHit ) {
                $result['city_slug'] = $polyHit['city_slug'] ?: $citySlug;
                $result['area_slug'] = $polyHit['area_slug'];
                $result['via'] = 'polygon';
                $result['confidence'] = 0.9;
                return $result;
            }
        }

        // 3) Geocoding fallback to get coordinates, then polygon
        if ( ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) && '' !== trim( $address ) ) {
            try {
                $geo = ( new Geocoder() )->geocode_address( $address );
                if ( $geo && isset( $geo['lat'], $geo['lng'] ) ) {
                    $polyHit = $this->match_polygon( (float) $geo['lat'], (float) $geo['lng'] );
                    if ( $polyHit ) {
                        $result['city_slug'] = $polyHit['city_slug'] ?: $citySlug ?: ( $geo['city_slug'] ?? '' );
                        $result['area_slug'] = $polyHit['area_slug'];
                        $result['via'] = 'geocode_polygon';
                        $result['confidence'] = 0.85;
                        return $result;
                    }
                    // If no polygon, still adopt city if confident
                    if ( empty( $result['city_slug'] ) && ! empty( $geo['city_slug'] ) ) {
                        $result['city_slug'] = $geo['city_slug'];
                        $result['via'] = 'geocode_city';
                        $result['confidence'] = 0.6;
                    }
                }
            } catch ( \Throwable $e ) {
                $this->logger->log_warn( 'geocode_failed', [ 'error' => $e->getMessage() ] );
            }
        }

        // 4) Heuristic: area name present as last token after comma or hyphen
        $areaSlug = $this->derive_area_slug_from_address( $address );
        if ( $areaSlug ) {
            $result['city_slug'] = $citySlug ?: $result['city_slug'];
            $result['area_slug'] = $areaSlug;
            $result['via'] = 'heuristic';
            $result['confidence'] = 0.5;
        } else {
            $result['city_slug'] = $citySlug ?: $result['city_slug'];
        }
        return $result;
    }

    public function reassign_all( int $batchSize = 50 ): void {
        $paged = 1;
        do {
            $q = new \WP_Query( [
                'post_type' => 'estate_property',
                'post_status' => [ 'publish', 'draft', 'pending' ],
                'posts_per_page' => $batchSize,
                'paged' => $paged,
                'fields' => 'ids',
            ] );
            if ( ! $q->have_posts() ) { break; }
            foreach ( $q->posts as $post_id ) {
                $address = (string) get_post_meta( (int) $post_id, 'property_address', true );
                $cityMeta = '';
                $lat = get_post_meta( (int) $post_id, 'property_latitude', true );
                $lng = get_post_meta( (int) $post_id, 'property_longitude', true );
                $assign = $this->assign( $address, $cityMeta, is_numeric( $lat ) ? (float) $lat : null, is_numeric( $lng ) ? (float) $lng : null );
                $this->apply_terms( (int) $post_id, $assign );
            }
            $paged++;
            wp_reset_postdata();
        } while ( true );
    }

    public function apply_terms( int $post_id, array $assign ): void {
        $citySlug = (string) ( $assign['city_slug'] ?? '' );
        $areaSlug = (string) ( $assign['area_slug'] ?? '' );
        if ( $citySlug ) {
            $this->ensure_term_and_assign( $post_id, 'property_city', $this->humanize_slug( $citySlug ), $citySlug );
        }
        if ( $areaSlug ) {
            $this->ensure_term_and_assign( $post_id, 'property_area', $this->humanize_slug( $areaSlug ), $areaSlug );
        }
    }

    private function ensure_term_and_assign( int $post_id, string $taxonomy, string $name, string $slug ): void {
        $term = \get_term_by( 'slug', $slug, $taxonomy );
        if ( ! $term || \is_wp_error( $term ) ) {
            $created = \wp_insert_term( $name ?: $slug, $taxonomy, [ 'slug' => $slug ] );
            if ( \is_wp_error( $created ) ) { return; }
            $term_id = (int) $created['term_id'];
        } else {
            $term_id = (int) $term->term_id;
        }
        \wp_set_object_terms( $post_id, [ $term_id ], $taxonomy, false );
    }

    private function humanize_slug( string $slug ): string {
        $name = str_replace( '-', ' ', $slug );
        return ucfirst( $name );
    }

    private function normalize_street_from_address( string $address ): string {
        $street = $address;
        $pos = strpos( $address, ',' );
        if ( false !== $pos ) { $street = substr( $address, 0, $pos ); }
        $street = preg_replace( '/\d+.*/u', '', $street );
        $street = trim( $street );
        $ascii = $this->to_ascii( $street );
        $ascii = strtolower( preg_replace( '/[^a-z]/', '', $ascii ) );
        return $ascii;
    }

    private function derive_city_slug( string $city, string $address ): string {
        $in = $city . ' ' . $address;
        if ( preg_match( '/praha\s*(\d{1,2})/iu', $in, $m ) ) { return 'praha-' . (int) $m[1]; }
        if ( preg_match( '/hlav(n|ní)\s*m(ě|e)sto\s*praha/iu', $in ) || preg_match( '/praha/iu', $in ) ) { return 'praha'; }
        return $this->slugify( $city );
    }

    private function derive_area_slug_from_address( string $address ): string {
        if ( preg_match( '/-\s*([^,]+)$/u', $address, $m ) ) { return $this->slugify( trim( $m[1] ) ); }
        if ( preg_match( '/,\s*([^,]+)$/u', $address, $m ) ) { return $this->slugify( trim( $m[1] ) ); }
        return '';
    }

    private function load_street_map(): array {
        $paths = $this->get_geo_paths();
        $csv = '';
        if ( file_exists( $paths['street'] ) ) { $csv = (string) file_get_contents( $paths['street'] ); }
        elseif ( file_exists( REALT_PS_PATH . 'config/street_map.csv' ) ) { $csv = (string) file_get_contents( REALT_PS_PATH . 'config/street_map.csv' ); }
        if ( '' === $csv ) { return []; }
        $rows = preg_split( '/\r?\n/', $csv );
        $map = [];
        foreach ( $rows as $i => $row ) {
            if ( $i === 0 ) { continue; }
            $cols = str_getcsv( $row );
            if ( count( $cols ) < 3 ) { continue; }
            $citySlug = sanitize_title( (string) $cols[0] );
            $areaSlug = sanitize_title( (string) $cols[1] );
            $streetNorm = strtolower( preg_replace( '/[^a-z]/', '', $this->to_ascii( (string) $cols[2] ) ) );
            if ( '' === $streetNorm || '' === $areaSlug ) { continue; }
            if ( ! isset( $map[ $streetNorm ] ) ) { $map[ $streetNorm ] = []; }
            $map[ $streetNorm ][] = [ $citySlug, $areaSlug ];
        }
        return $map;
    }

    private function match_polygon( float $lat, float $lng ): array {
        $geo = $this->load_geojson();
        foreach ( $geo as $feature ) {
            if ( ! isset( $feature['geometry'], $feature['bbox'] ) ) { continue; }
            $bbox = $feature['bbox'];
            if ( $lng < $bbox[0] || $lng > $bbox[2] || $lat < $bbox[1] || $lat > $bbox[3] ) { continue; }
            if ( $this->point_in_geometry( $lat, $lng, $feature['geometry'] ) ) {
                return [
                    'city_slug' => (string) ( $feature['properties']['city_slug'] ?? '' ),
                    'area_slug' => (string) ( $feature['properties']['area_slug'] ?? ( $feature['properties']['slug'] ?? '' ) ),
                ];
            }
        }
        return [];
    }

    private function load_geojson(): array {
        $paths = $this->get_geo_paths();
        $path = file_exists( $paths['areas'] ) ? $paths['areas'] : ( REALT_PS_PATH . 'config/areas.geojson' );
        if ( ! file_exists( $path ) ) { return []; }
        $json = (string) file_get_contents( $path );
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || empty( $data['features'] ) ) { return []; }
        $features = [];
        foreach ( (array) $data['features'] as $f ) {
            if ( ! isset( $f['geometry'] ) ) { continue; }
            $geom = $f['geometry'];
            $props = is_array( $f['properties'] ?? null ) ? $f['properties'] : [];
            $bbox = $this->compute_bbox( $geom );
            $features[] = [ 'geometry' => $geom, 'properties' => $props, 'bbox' => $bbox ];
        }
        return $features;
    }

    private function compute_bbox( array $geometry ): array {
        $minX = 999; $minY = 999; $maxX = -999; $maxY = -999;
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        $scan = function( $pt ) use ( &$minX, &$minY, &$maxX, &$maxY ) {
            if ( ! is_array( $pt ) || count( $pt ) < 2 ) { return; }
            $x = (float) $pt[0]; $y = (float) $pt[1];
            $minX = min( $minX, $x ); $maxX = max( $maxX, $x );
            $minY = min( $minY, $y ); $maxY = max( $maxY, $y );
        };
        $walk = function( $node ) use ( &$walk, $scan ) {
            if ( is_array( $node ) && isset( $node[0] ) && is_array( $node[0] ) && isset( $node[0][0] ) && ! is_array( $node[0][0] ) ) {
                foreach ( $node as $p ) { $scan( $p ); }
            } else {
                foreach ( (array) $node as $child ) { $walk( $child ); }
            }
        };
        $walk( $coords );
        return [ $minX, $minY, $maxX, $maxY ];
    }

    private function point_in_geometry( float $lat, float $lng, array $geometry ): bool {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        if ( 'Polygon' === $type ) { return $this->point_in_polygon( $lng, $lat, $coords ); }
        if ( 'MultiPolygon' === $type ) {
            foreach ( $coords as $poly ) { if ( $this->point_in_polygon( $lng, $lat, $poly ) ) { return true; } }
            return false;
        }
        return false;
    }

    // Ray casting; $polygon = [ ring1(points), ring2(hole), ... ];
    private function point_in_polygon( float $x, float $y, array $polygon ): bool {
        $inside = false;
        // Only consider outer ring (index 0) for inclusion, and holes flip state
        foreach ( $polygon as $ringIndex => $ring ) {
            $n = count( $ring );
            for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
                $xi = (float) $ring[$i][0]; $yi = (float) $ring[$i][1];
                $xj = (float) $ring[$j][0]; $yj = (float) $ring[$j][1];
                $intersect = (( $yi > $y ) !== ( $yj > $y )) && ( $x < ( $xj - $xi ) * ( $y - $yi ) / ( ( $yj - $yi ) ?: 1e-9 ) + $xi );
                if ( $intersect ) { $inside = ! $inside; }
            }
        }
        return $inside;
    }

    private function get_geo_paths(): array {
        $upload_dir = \wp_upload_dir();
        $base = \trailingslashit( $upload_dir['basedir'] ) . 'property-scrapper/geo';
        if ( ! \file_exists( $base ) ) { \wp_mkdir_p( $base ); }
        return [
            'areas' => \trailingslashit( $base ) . 'areas.geojson',
            'street' => \trailingslashit( $base ) . 'street_map.csv',
        ];
    }

    private function to_ascii( string $value ): string {
        $trans = @\iconv( 'UTF-8', 'ASCII//TRANSLIT', $value );
        if ( false === $trans || null === $trans ) { $trans = $value; }
        return $trans;
    }

    private function slugify( string $value ): string {
        $v = strtolower( $this->to_ascii( $value ) );
        // Remove apostrophes/backticks that some transliterators insert for diacritics (e.g., Dub'a -> duba)
        $v = str_replace( ["'", "’", "`"], '', $v );
        $v = preg_replace( '/[^a-z0-9]+/', '-', $v );
        $v = trim( $v, '-' );
        return $v;
    }
}

?>


