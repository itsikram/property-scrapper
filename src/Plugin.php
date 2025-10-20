<?php
namespace Realt\PropertyScrapper;

use Realt\PropertyScrapper\Admin\Admin;
use Realt\PropertyScrapper\Cron\Scheduler;
use Realt\PropertyScrapper\Locations\Locations;
use Realt\PropertyScrapper\Widgets\Registry as WidgetsRegistry;
use Realt\PropertyScrapper\Shortcodes\Registry as ShortcodesRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private static $instance;
	private $admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		// Core modules
		$this->admin = new Admin();
		$this->admin->init();

		// Ensure estate_property supports featured images
		\add_action( 'init', function () {
			if ( \post_type_exists( 'estate_property' ) ) {
				\add_post_type_support( 'estate_property', 'thumbnail' );
			}
		}, 20 );

		// Scheduler hooks
		Scheduler::init();

		// Locations (tax meta, seeding tools)
		( new Locations() )->init();

		// Widgets registry
		( new WidgetsRegistry() )->init();

		// Shortcodes registry
		( new ShortcodesRegistry() )->init();

		// Frontend: append basic specs (condition, area m2, floor) on single property pages
		add_filter('plugin_row_meta', function($links, $file) {
			if (plugin_basename(__FILE__) === $file) {
				echo '<style>
					tr[data-slug="property-scrapper-for-wp-residence"] .plugin-icon img {
						content: url(' . plugin_dir_url(__FILE__) . 'assets/icon-128x128.png);
					}
				</style>';
			}
			return $links;
		}, 10, 2);

        \add_filter( 'the_content', function ( $content ) {
			if ( \is_admin() ) { return $content; }
			if ( ! \is_singular( 'estate_property' ) ) { return $content; }
			$post_id = (int) ( \get_the_ID() ?: 0 );
			if ( $post_id <= 0 ) { return $content; }
			$condition = trim( (string) \get_post_meta( $post_id, 'property_condition', true ) );
			$areaSize = trim( (string) \get_post_meta( $post_id, 'property_size', true ) );
			$floorText = trim( (string) \get_post_meta( $post_id, '_realt_ps_floor_text', true ) );
			$floorNum  = trim( (string) \get_post_meta( $post_id, 'property_on_floor', true ) );
			$price     = trim( (string) \get_post_meta( $post_id, 'property_price', true ) );
			$currency  = strtoupper( (string) \get_post_meta( $post_id, 'property_currency', true ) );
			$parts = [];
			if ( $condition !== '' ) {
                $parts[] = '<li class="realt-ps-specs__item"><strong>' . \esc_html__( 'Condition', 'property-scrapper' ) . ':</strong> ' . \esc_html( $condition ) . '</li>';
			}
			if ( $areaSize !== '' && is_numeric( $areaSize ) && (int) $areaSize > 0 ) {
                $parts[] = '<li class="realt-ps-specs__item"><strong>' . \esc_html__( 'Area', 'property-scrapper' ) . ':</strong> ' . \esc_html( number_format_i18n( (int) $areaSize ) ) . ' m²</li>';
			}
			$floorLabel = '';
			if ( $floorText !== '' ) { $floorLabel = $floorText; }
			elseif ( $floorNum !== '' ) { $floorLabel = $floorNum . '.'; }
			if ( $floorLabel !== '' ) {
                $parts[] = '<li class="realt-ps-specs__item"><strong>' . \esc_html__( 'Floor', 'property-scrapper' ) . ':</strong> ' . \esc_html( $floorLabel ) . '</li>';
			}
			if ( is_numeric( $price ) && (int) $price > 0 ) {
				$symbol = 'Kč';
				if ( 'EUR' === $currency ) { $symbol = '€'; }
				elseif ( 'USD' === $currency ) { $symbol = '$'; }
                $parts[] = '<li class="realt-ps-specs__item"><strong>' . \esc_html__( 'Price', 'property-scrapper' ) . ':</strong> ' . \esc_html( number_format_i18n( (int) $price ) . ' ' . $symbol ) . '</li>';
			}
			if ( empty( $parts ) ) { return $content; }
			$block = '<div class="realt-ps-specs" style="margin-top:16px"><ul class="realt-ps-specs__list" style="list-style:none;padding:0;display:flex;flex-wrap:wrap;gap:12px 24px;margin:0">' . implode( '', $parts ) . '</ul></div>';
			return $content . $block;
		}, 20 );

		// Frontend debug: show property data on single estate_property when requested by admin
		\add_filter( 'the_content', function ( $content ) {
			if ( \is_admin() ) { return $content; }
			if ( ! \is_singular( 'estate_property' ) ) { return $content; }
			if ( ! isset( $_GET['realt_ps_debug'] ) ) { return $content; }
			if ( ! \current_user_can( 'manage_options' ) ) { return $content; }
			global $post;
			if ( ! $post || ! isset( $post->ID ) ) { return $content; }
			$post_id = (int) $post->ID;
			// Collect post core fields
			$p = \get_post( $post_id );
			$core = $p ? [
				'ID' => $p->ID,
				'post_title' => (string) $p->post_title,
				'post_status' => (string) $p->post_status,
				'post_type' => (string) $p->post_type,
				'post_date_gmt' => (string) $p->post_date_gmt,
			] : [];
			// All meta (raw)
			$meta = \get_post_meta( $post_id );
			// Taxonomy terms
			$taxes = [ 'property_city', 'property_area' ];
			$terms = [];
			foreach ( $taxes as $tx ) {
				$t = \wp_get_object_terms( $post_id, $tx, [ 'fields' => 'all' ] );
				if ( \is_wp_error( $t ) ) { $terms[ $tx ] = [ 'error' => $t->get_error_message() ]; }
				else {
					$terms[ $tx ] = array_map( function ( $term ) {
						return [ 'term_id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug ];
					}, is_array( $t ) ? $t : [] );
				}
			}
			$debug = [ 'core' => $core, 'meta' => $meta, 'terms' => $terms ];
			$json = \wp_json_encode( $debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			$block = '<div class="realt-ps-debug" style="margin-top:20px;border:1px solid #ddd;padding:10px;">'
				. '<strong>Property Debug</strong>'
				. '<pre style="white-space:pre-wrap;overflow:auto;max-height:500px;">' . \esc_html( (string) $json ) . '</pre>'
				. '</div>';
			return $content . $block;
		}, 99 );

		// Frontend: append property taxonomies' slugs on single property pages
		// \add_filter( 'the_content', function ( $content ) {
		// 	if ( \is_admin() ) { return $content; }
		// 	if ( ! \is_singular( 'estate_property' ) ) { return $content; }
		// 	$post_id = (int) ( \get_the_ID() ?: 0 );
		// 	if ( $post_id <= 0 ) { return $content; }

		// 	$taxonomies = [ 'property_city', 'property_area' ];
		// 	$parts = [];
		// 	foreach ( $taxonomies as $taxonomy ) {
		// 		$terms = \wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'all' ] );
		// 		if ( \is_wp_error( $terms ) || empty( $terms ) ) { continue; }
		// 		$slugs = array_map( function ( $t ) { return (string) $t->slug; }, $terms );
		// 		$tx = \get_taxonomy( $taxonomy );
		// 		$label = $tx && isset( $tx->labels->singular_name ) ? (string) $tx->labels->singular_name : $taxonomy;
		// 		$parts[] = '<span class="realt-ps-tax realt-ps-tax--' . \esc_attr( $taxonomy ) . '">' . \esc_html( $label ) . ': ' . \esc_html( implode( ', ', $slugs ) ) . '</span>';
		// 	}

		// 	if ( empty( $parts ) ) { return $content; }
		// 	$block = '<div class="realt-ps-tax-slugs" style="margin-top:16px;display:flex;flex-wrap:wrap;gap:8px;opacity:.85;font-size:14px;">' . implode( ' ', $parts ) . '</div>';
		// 	return $content . $block;
		// }, 20 );
	}
}


