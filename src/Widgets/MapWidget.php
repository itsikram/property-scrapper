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
		echo '<div id="realt-ps-map" style="height:300px"></div>';
		echo '<script>/* Mapy.cz init placeholder - key provided via settings */</script>';
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


