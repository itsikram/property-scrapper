<?php
namespace Realt\PropertyScrapper\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Registry {
	public function init() {
		\add_action( 'init', [ $this, 'register_shortcodes' ] );
	}

	public function register_shortcodes() {
		\add_shortcode( 'realt_ps_properties', [ PropertiesShortcode::class, 'render' ] );
	}
}

?>


