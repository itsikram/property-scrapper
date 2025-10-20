<?php
namespace Realt\PropertyScrapper\Widgets;

use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MapWidget extends WP_Widget {
	public function __construct() {
        parent::__construct( 'realt_ps_map', __( 'Mapy.cz Map (Location)', 'property-scrapper' ) );
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget'];
        $title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Map', 'property-scrapper' );
		if ( $title ) { echo $args['before_title'] . esc_html( $title ) . $args['after_title']; }
		$api = get_option( 'realt_ps_geocoding', [] )['mapycz_api_key'] ?? '';
		$lat = 50.0755; $lng = 14.4378; // default to Prague center
		$mode = 'default';
		$context = [];
		if ( is_tax( 'property_city' ) || is_tax( 'property_area' ) ) {
			$term = get_queried_object();
			if ( $term && isset( $term->term_id ) ) {
				$lat = (float) get_term_meta( (int) $term->term_id, 'gps_lat', true ) ?: $lat;
				$lng = (float) get_term_meta( (int) $term->term_id, 'gps_lng', true ) ?: $lng;
				$mode = 'tax';
				$context['taxonomy'] = (string) $term->taxonomy;
				$context['term_id'] = (int) $term->term_id;
				$context['term_name'] = isset( $term->name ) ? (string) $term->name : '';
			}
		} elseif ( is_singular( 'estate_property' ) ) {
			$post_id = (int) ( get_the_ID() ?: 0 );
			if ( $post_id > 0 ) {
				$plat_s = get_post_meta( $post_id, 'property_latitude', true );
				$plng_s = get_post_meta( $post_id, 'property_longitude', true );
				if ( $plat_s !== '' && $plng_s !== '' && is_numeric( $plat_s ) && is_numeric( $plng_s ) ) {
					$lat = (float) $plat_s;
					$lng = (float) $plng_s;
				}
				$mode = 'single';
				$context['post_id'] = $post_id;
			}
		}

		// Build markers for properties in the same city/area
		$markers = [];
		$q = null;
		if ( $mode === 'tax' ) {
			$term = get_queried_object();
			$q = new \WP_Query( [
				'post_type' => 'estate_property',
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'no_found_rows' => true,
				'fields' => 'ids',
				'tax_query' => [
					[
						'taxonomy' => (string) $term->taxonomy,
						'field' => 'term_id',
						'terms' => [ (int) $term->term_id ],
					],
				],
			] );
		} elseif ( $mode === 'single' ) {
			$post_id = (int) ( get_the_ID() ?: 0 );
			$area_terms = \wp_get_object_terms( $post_id, 'property_area', [ 'fields' => 'ids' ] );
			$city_terms = \wp_get_object_terms( $post_id, 'property_city', [ 'fields' => 'ids' ] );
			$use_taxonomy = ! \is_wp_error( $area_terms ) && ! empty( $area_terms ) ? 'property_area' : 'property_city';
			$term_ids = $use_taxonomy === 'property_area' ? ( ( ! \is_wp_error( $area_terms ) && is_array( $area_terms ) ) ? $area_terms : [] ) : ( ( ! \is_wp_error( $city_terms ) && is_array( $city_terms ) ) ? $city_terms : [] );
			$context['selected_taxonomy'] = $use_taxonomy;
			$context['selected_terms_count'] = is_array( $term_ids ) ? count( $term_ids ) : 0;
			if ( ! empty( $term_ids ) ) {
				$q = new \WP_Query( [
					'post_type' => 'estate_property',
					'post_status' => 'publish',
					'posts_per_page' => -1,
					'no_found_rows' => true,
					'fields' => 'ids',
					'post__not_in' => $post_id > 0 ? [ $post_id ] : [],
					'tax_query' => [
						[
							'taxonomy' => $use_taxonomy,
							'field' => 'term_id',
							'terms' => array_map( 'absint', $term_ids ),
						],
					],
				] );
			}
		}
		if ( $q instanceof \WP_Query ) {
			$context['queried_posts'] = (int) $q->post_count;
			if ( $q->have_posts() ) {
				foreach ( $q->posts as $pid ) {
					$lat_s = get_post_meta( (int) $pid, 'property_latitude', true );
					$lng_s = get_post_meta( (int) $pid, 'property_longitude', true );
					if ( $lat_s !== '' && $lng_s !== '' && is_numeric( $lat_s ) && is_numeric( $lng_s ) ) {
						$currencyCode = strtoupper( (string) get_post_meta( (int) $pid, 'property_currency', true ) );
						$symbol = 'Kč';
						if ( 'EUR' === $currencyCode ) { $symbol = '€'; }
						elseif ( 'USD' === $currencyCode ) { $symbol = '$'; }
						$priceRaw = get_post_meta( (int) $pid, 'property_price', true );
						$priceNum = is_numeric( $priceRaw ) ? (int) $priceRaw : 0;
						$thumb = (string) ( get_the_post_thumbnail_url( (int) $pid, 'medium' ) ?: '' );
						$markers[] = [
							'lat' => (float) $lat_s,
							'lng' => (float) $lng_s,
							'title' => (string) get_the_title( (int) $pid ),
							'url' => (string) get_permalink( (int) $pid ),
							'thumb' => $thumb,
							'price' => $priceNum,
							'currency' => $symbol,
						];
					}
				}
			}
			\wp_reset_postdata();
		}
		$context['markers_count'] = count( $markers );
		$markers_json = wp_json_encode( $markers );
        // Use a simple OpenStreetMap iframe to avoid external JS/CDN dependencies
        $bboxSize = 0.02; // approx bounding box size
        $left = $lng - $bboxSize;
        $right = $lng + $bboxSize;
        $top = $lat + $bboxSize;
        $bottom = $lat - $bboxSize;
        $mapSrc = 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode( $left ) . '%2C' . rawurlencode( $bottom ) . '%2C' . rawurlencode( $right ) . '%2C' . rawurlencode( $top ) . '&layer=mapnik&marker=' . rawurlencode( $lat ) . '%2C' . rawurlencode( $lng );
        echo '<div class="realt-ps-map-wrap" style="height:300px;position:relative;">';
        echo '<iframe title="' . esc_attr__( 'Map', 'property-scrapper' ) . '" width="100%" height="100%" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="' . esc_url( $mapSrc ) . '"></iframe>';
        echo '</div>';
		echo $args['after_widget'];
	}

	public function form( $instance ) {
		$title = $instance['title'] ?? '';
		?>
		<p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'property-scrapper' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<?php
	}
}


