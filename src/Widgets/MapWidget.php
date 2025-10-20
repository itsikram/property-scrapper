<?php
namespace Realt\PropertyScrapper\Widgets;

use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MapWidget extends WP_Widget {
	public function __construct() {
		parent::__construct( 'realt_ps_map', __( 'Mapy.cz Map (Location)', 'realt-ps' ) );
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget'];
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Map', 'realt-ps' );
		if ( $title ) { echo $args['before_title'] . esc_html( $title ) . $args['after_title']; }
		$api = get_option( 'realt_ps_geocoding', [] )['mapycz_api_key'] ?? '';
		$lat = 50.0755; $lng = 14.4378; // default to Prague center
		if ( is_tax( 'property_city' ) || is_tax( 'property_area' ) ) {
			$term = get_queried_object();
			if ( $term && isset( $term->term_id ) ) {
				$lat = (float) get_term_meta( (int) $term->term_id, 'gps_lat', true ) ?: $lat;
				$lng = (float) get_term_meta( (int) $term->term_id, 'gps_lng', true ) ?: $lng;
			}
		}
		echo '<div id="realt-ps-map" style="height:300px"></div>';
		echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />';
		echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){try{var m=L.map("realt-ps-map").setView(['.esc_js($lat).','.esc_js($lng).'],13);L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",{maxZoom:19,attribution:"&copy; OpenStreetMap"}).addTo(m);L.marker(['.esc_js($lat).','.esc_js($lng).']).addTo(m);}catch(e){console&&console.warn&&console.warn(e);}});</script>';
		echo $args['after_widget'];
	}

	public function form( $instance ) {
		$title = $instance['title'] ?? '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'realt-ps' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<?php
	}
}


