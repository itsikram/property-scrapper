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
	}
}


