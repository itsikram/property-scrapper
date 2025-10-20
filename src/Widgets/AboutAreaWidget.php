<?php
namespace Realt\PropertyScrapper\Widgets;

use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AboutAreaWidget extends WP_Widget {
	public function __construct() {
        parent::__construct( 'realt_ps_about_area', __( 'About the Area', 'property-scrapper' ) );
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget'];
        $title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'About the Area', 'property-scrapper' );
		if ( $title ) { echo $args['before_title'] . esc_html( $title ) . $args['after_title']; }
		$content = $instance['content'] ?? '';
		echo wpautop( wp_kses_post( $content ) );
		\wp_enqueue_style( 'realt-ps-areas', \trailingslashit( \constant( 'REALT_PS_URL' ) ) . 'assets/css/areas.css', [], \constant( 'REALT_PS_VERSION' ) );
		$terms = get_terms( [ 'taxonomy' => 'property_area', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$activeIds = [];
			if ( \is_tax( 'property_area' ) ) {
				$qo = \get_queried_object();
				if ( $qo && isset( $qo->term_id ) ) { $activeIds[] = (int) $qo->term_id; }
			} elseif ( \is_singular( 'estate_property' ) ) {
				$post_id = (int) ( \get_the_ID() ?: 0 );
				if ( $post_id > 0 ) {
					$assigned = \wp_get_object_terms( $post_id, 'property_area', [ 'fields' => 'ids' ] );
					if ( ! \is_wp_error( $assigned ) ) { $activeIds = array_map( 'intval', (array) $assigned ); }
				}
			}
            echo '<nav class="realt-areas" aria-label="' . esc_attr__( 'Areas', 'property-scrapper' ) . '">';
			echo '<ul class="realt-areas-list">';
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( is_wp_error( $url ) ) { continue; }
				$isActive = in_array( (int) $term->term_id, $activeIds, true );
				$cls = $isActive ? ' class="is-active"' : '';
				$count = isset( $term->count ) ? (int) $term->count : 0;
				echo '<li><a' . $cls . ' href="' . esc_url( $url ) . '"><span class="realt-areas-name">' . esc_html( $term->name ) . '</span><span class="realt-areas-count">' . esc_html( number_format_i18n( $count ) ) . '</span></a></li>';
			}
			echo '</ul>';
			echo '</nav>';
		}
		echo $args['after_widget'];
	}

	public function form( $instance ) {
		$title = $instance['title'] ?? '';
		$content = $instance['content'] ?? '';
		?>
		<p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'property-scrapper' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'content' ) ); ?>"><?php esc_html_e( 'Content:', 'property-scrapper' ); ?></label>
			<textarea class="widefat" rows="6" id="<?php echo esc_attr( $this->get_field_id( 'content' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'content' ) ); ?>"><?php echo esc_textarea( $content ); ?></textarea>
		</p>
		<?php
	}
}


