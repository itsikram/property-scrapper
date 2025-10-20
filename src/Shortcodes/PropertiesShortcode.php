<?php
namespace Realt\PropertyScrapper\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PropertiesShortcode {
	public static function render( $atts = [] ): string {
		// Load frontend assets only when shortcode is rendered
		\wp_enqueue_style( 'realt-ps-frontend', \REALT_PS_URL . 'assets/frontend.css', [], \REALT_PS_VERSION );
		\wp_enqueue_style( 'dashicons' );
		$atts = shortcode_atts(
			[
				'per_page' => 30,
				'limit' => -1, // deprecated; kept for compatibility
				'title' => '',
				'city' => '',
				'area' => '',
				'orderby' => 'date',
				'order' => 'DESC',
			],
			$atts,
			'realt_ps_properties'
		);

		$per_page = (int) $atts['per_page'];
		if ( isset( $atts['limit'] ) && (int) $atts['limit'] > 0 ) { $per_page = (int) $atts['limit']; }
		$per_page = $per_page > 0 ? $per_page : 30;
		$paged = isset( $_GET['realt_ps_page'] ) ? max( 1, absint( $_GET['realt_ps_page'] ) ) : max( 1, (int) get_query_var( 'paged' ) ?: (int) get_query_var( 'page' ) ?: 1 );

		$args = [
			'post_type' => 'estate_property',
			'post_status' => 'publish',
			'posts_per_page' => $per_page,
			'paged' => $paged,
			'orderby' => sanitize_key( $atts['orderby'] ),
			'order' => ( strtoupper( (string) $atts['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
		];

		$tax_query = [];
		$city = sanitize_title( (string) $atts['city'] );
		$area = sanitize_title( (string) $atts['area'] );
		if ( $city ) {
			$tax_query[] = [ 'taxonomy' => 'property_city', 'field' => 'slug', 'terms' => $city ];
		}
		if ( $area ) {
			$tax_query[] = [ 'taxonomy' => 'property_area', 'field' => 'slug', 'terms' => $area ];
		}
		if ( $tax_query ) {
			$args['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax_query );
		}

		$q = new \WP_Query( $args );

		ob_start();
		?>
		<div class="realt-ps-properties">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h2 class="realt-ps-section-title"><?php echo esc_html( (string) $atts['title'] ); ?></h2>
			<?php endif; ?>
			<?php if ( $q->have_posts() ) : ?>
				<div class="realt-ps-grid">
					<?php while ( $q->have_posts() ) : $q->the_post(); ?>
						<?php
						$price = get_post_meta( get_the_ID(), 'property_price', true );
						$address = get_post_meta( get_the_ID(), 'property_address', true );
						$gallery_ids = get_post_meta( get_the_ID(), 'property_images', true );
						if ( ! is_array( $gallery_ids ) || empty( $gallery_ids ) ) {
							$csv = (string) get_post_meta( get_the_ID(), '_realt_ps_gallery_ids', true );
							$gallery_ids = $csv ? array_filter( array_map( 'intval', explode( ',', $csv ) ) ) : [];
						}
						$gallery_count = is_array( $gallery_ids ) ? count( $gallery_ids ) : 0;
						$statuses = get_the_terms( get_the_ID(), 'property_status' );
						$labels = get_the_terms( get_the_ID(), 'property_label' );
						?>
						<article class="realt-ps-card">
							<a href="<?php echo esc_url( get_permalink() ); ?>" class="realt-ps-card__link" aria-label="<?php echo esc_attr( get_the_title() ); ?>"></a>
							<div class="realt-ps-card__media">
								<div class="realt-ps-badges">
									<?php if ( is_array( $statuses ) && ! empty( $statuses ) ) : ?>
										<span class="realt-ps-badge realt-ps-badge--accent"><?php echo esc_html( $statuses[0]->name ); ?></span>
									<?php endif; ?>
									<?php if ( is_array( $labels ) && ! empty( $labels ) ) : ?>
										<span class="realt-ps-badge realt-ps-badge--featured"><?php echo esc_html( $labels[0]->name ); ?></span>
									<?php endif; ?>
								</div>
								<?php echo get_the_post_thumbnail( get_the_ID(), 'large', [ 'loading' => 'lazy' ] ); ?>
								<?php if ( $gallery_count > 0 ) : ?>
									<div class="realt-ps-card__mediaMeta"><span class="dashicons dashicons-format-gallery"></span><?php echo (int) $gallery_count; ?></div>
								<?php endif; ?>
							</div>
							<div class="realt-ps-card__body">
								<h3 class="realt-ps-card__title"><?php echo esc_html( get_the_title() ); ?></h3>
								<?php if ( has_excerpt() || get_the_content() ) : ?>
									<p class="realt-ps-card__desc"><?php echo esc_html( self::excerpt_for_card( get_the_ID(), 120 ) ); ?></p>
								<?php endif; ?>
								<div class="realt-ps-card__meta">
									<?php if ( is_numeric( $price ) && $price > 0 ) : ?>
										<span class="realt-ps-card__price"><?php echo esc_html( number_format_i18n( (int) $price ) ); ?></span>
									<?php endif; ?>
									<?php if ( $address ) : ?>
										<span class="realt-ps-card__address"><span class="dashicons dashicons-location"></span> <?php echo esc_html( $address ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						</article>
					<?php endwhile; ?>
				</div>
				<?php
				$pagination_links = paginate_links( [
					'base' => add_query_arg( 'realt_ps_page', '%#%' ),
					'format' => '',
					'current' => $paged,
					'total' => max( 1, (int) $q->max_num_pages ),
					'type' => 'array',
					'prev_text' => '«',
					'next_text' => '»',
				] );
				if ( is_array( $pagination_links ) && ! empty( $pagination_links ) ) : ?>
					<nav class="realt-ps-pagination" aria-label="<?php echo esc_attr__( 'Properties pagination', 'realt-ps' ); ?>">
						<ul>
							<?php foreach ( $pagination_links as $link ) : ?>
								<li><?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
							<?php endforeach; ?>
						</ul>
					</nav>
				<?php endif; ?>
			<?php else : ?>
				<p class="realt-ps-properties__empty"><?php echo esc_html__( 'No properties found.', 'realt-ps' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		wp_reset_postdata();
		return (string) ob_get_clean();
	}

	private static function excerpt_for_card( int $post_id, int $max_chars ): string {
		$excerpt = get_the_excerpt( $post_id );
		if ( '' === trim( $excerpt ) ) {
			$content = get_post_field( 'post_content', $post_id );
			$excerpt = wp_strip_all_tags( (string) $content );
		}
		$excerpt = trim( preg_replace( '/\s+/', ' ', $excerpt ) );
		if ( mb_strlen( $excerpt ) > $max_chars ) {
			$excerpt = rtrim( mb_substr( $excerpt, 0, $max_chars - 1 ) ) . '…';
		}
		return $excerpt;
	}
}

?>


