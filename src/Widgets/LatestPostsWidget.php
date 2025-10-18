<?php
namespace Realt\PropertyScrapper\Widgets;

use WP_Query;
use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LatestPostsWidget extends WP_Widget {
	public function __construct() {
		parent::__construct( 'realt_ps_latest_posts', __( 'Latest Posts (Area)', 'realt-ps' ) );
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget'];
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Latest Posts', 'realt-ps' );
		if ( $title ) { echo $args['before_title'] . esc_html( $title ) . $args['after_title']; }
		$q = new WP_Query( [ 'posts_per_page' => (int) ( $instance['count'] ?? 5 ) ] );
		if ( $q->have_posts() ) {
			echo '<ul class="realt-ps-latest">';
			while ( $q->have_posts() ) { $q->the_post();
				echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
			}
			echo '</ul>';
			wp_reset_postdata();
		}
		echo $args['after_widget'];
	}

	public function form( $instance ) {
		$title = $instance['title'] ?? '';
		$count = (int) ( $instance['count'] ?? 5 );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'realt-ps' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php esc_html_e( 'Count:', 'realt-ps' ); ?></label>
			<input class="small-text" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>" type="number" value="<?php echo esc_attr( $count ); ?>">
		</p>
		<?php
	}
}


