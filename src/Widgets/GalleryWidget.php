<?php
namespace Realt\PropertyScrapper\Widgets;

use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GalleryWidget extends WP_Widget {
	public function __construct() {
		parent::__construct( 'realt_ps_gallery', __( 'Area Gallery', 'realt-ps' ) );
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget'];
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Gallery', 'realt-ps' );
		if ( $title ) { echo $args['before_title'] . esc_html( $title ) . $args['after_title']; }
		$ids = array_filter( array_map( 'absint', explode( ',', $instance['attachment_ids'] ?? '' ) ) );
		if ( $ids ) {
			echo '<div class="realt-ps-gallery">';
			foreach ( $ids as $id ) {
				$img = wp_get_attachment_image( $id, 'medium_large', false, [ 'loading' => 'lazy' ] );
				if ( $img ) { echo '<div class="realt-ps-gallery__item">' . $img . '</div>'; }
			}
			echo '</div>';
		}
		echo $args['after_widget'];
	}

	public function form( $instance ) {
		$title = $instance['title'] ?? '';
		$attachment_ids = $instance['attachment_ids'] ?? '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'realt-ps' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'attachment_ids' ) ); ?>"><?php esc_html_e( 'Attachment IDs (comma-separated)', 'realt-ps' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'attachment_ids' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'attachment_ids' ) ); ?>" type="text" value="<?php echo esc_attr( $attachment_ids ); ?>">
		</p>
		<?php
	}
}


