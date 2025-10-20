<?php
namespace Realt\PropertyScrapper\Locations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Locations {
	public function init() {
		add_action( 'init', [ $this, 'register_term_meta' ] );
	}

	public function register_term_meta() {
		$taxes = [ 'property_city', 'property_area' ];
		foreach ( $taxes as $tax ) {
			register_term_meta( $tax, 'gps_lat', [ 'type' => 'number', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'floatval' ] );
			register_term_meta( $tax, 'gps_lng', [ 'type' => 'number', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'floatval' ] );
			register_term_meta( $tax, 'hero_image_id', [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'absint' ] );
			register_term_meta( $tax, 'gallery_ids', [ 'type' => 'array', 'single' => true, 'show_in_rest' => true ] );
			register_term_meta( $tax, 'short_description', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'wp_kses_post' ] );
			register_term_meta( $tax, 'city_slug', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_title' ] );
		}
	}
}


