<?php
namespace Realt\PropertyScrapper\Widgets;

use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AboutAreaWidget extends WP_Widget {
	public function __construct() {
		parent::__construct( 'realt_ps_about_area', __( 'About the Area', 'realt-ps' ) );
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget'];
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'About the Area', 'realt-ps' );
		if ( $title ) { echo $args['before_title'] . esc_html( $title ) . $args['after_title']; }
		$content = $instance['content'] ?? '';
		echo wpautop( wp_kses_post( $content ) );
		echo $args['after_widget'];
	}

	public function form( $instance ) {
		$title = $instance['title'] ?? '';
		$content = $instance['content'] ?? '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'realt-ps' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'content' ) ); ?>"><?php esc_html_e( 'Content:', 'realt-ps' ); ?></label>
			<textarea class="widefat" rows="6" id="<?php echo esc_attr( $this->get_field_id( 'content' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'content' ) ); ?>"><?php echo esc_textarea( $content ); ?></textarea>
		</p>
		<?php
	}
}


