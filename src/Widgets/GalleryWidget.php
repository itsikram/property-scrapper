<?php
namespace Realt\PropertyScrapper\Widgets;

use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GalleryWidget extends WP_Widget {
	public function __construct() {
        parent::__construct( 'realt_ps_gallery', __( 'Area Gallery', 'property-scrapper' ) );
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget'];
        $title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Gallery', 'property-scrapper' );
		if ( $title ) { echo $args['before_title'] . esc_html( $title ) . $args['after_title']; }

        // Use built-in Thickbox to avoid external CDNs
        add_thickbox();
        \wp_enqueue_style( 'realt-ps-gallery', \trailingslashit( \constant( 'REALT_PS_URL' ) ) . 'assets/css/gallery.css', [], \constant( 'REALT_PS_VERSION' ) );
		$ids = [];
		$post_id = get_queried_object_id() ?: get_the_ID();
		if ( $post_id ) {
			$meta = get_post_meta( $post_id, 'property_image_gallery', true );
			if ( is_string( $meta ) && $meta !== '' ) {
				$ids = array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', $meta ) ) );
			} elseif ( is_array( $meta ) ) {
				$ids = array_filter( array_map( 'absint', $meta ) );
			}
		}
		if ( ! $ids ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $instance['attachment_ids'] ?? '' ) ) );
		}
		if ( $ids ) {
            $gallery_id = 'realt-ps-gallery-' . esc_attr( $this->id );
            echo '<div class="realt-ps-gallery" data-gallery-id="' . $gallery_id . '">';
			foreach ( $ids as $id ) {
				$thumb = wp_get_attachment_image( $id, 'medium_large', false, [ 'loading' => 'lazy' ] );
				$full  = wp_get_attachment_image_url( $id, 'full' );
				if ( $thumb && $full ) {
                    $tb_id = $gallery_id . '-' . (int) $id;
                    echo '<a class="realt-ps-gallery__item thickbox" href="' . esc_url( $full ) . '?TB_iframe=false&width=1200&height=800" data-gallery="' . esc_attr( $gallery_id ) . '" title="" aria-label="' . esc_attr__( 'View image', 'property-scrapper' ) . '">' . $thumb . '</a>';
				}
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
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'property-scrapper' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'attachment_ids' ) ); ?>"><?php esc_html_e( 'Attachment IDs (comma-separated)', 'property-scrapper' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'attachment_ids' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'attachment_ids' ) ); ?>" type="text" value="<?php echo esc_attr( $attachment_ids ); ?>">
		</p>
		<?php
	}
}


