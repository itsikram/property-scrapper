<?php
/**
 * Plugin Name: Property Scrapper for WP Residence
 * Description: Automated import, scraping fallback, and location management for WP Residence (Prague listings).
 * Version: 0.1.0
 * Author: Realt.cz
 * License: GPLv2 or later
 * Text Domain: realt-ps
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REALT_PS_VERSION', '0.1.0' );
define( 'REALT_PS_SLUG', 'realt-ps' );
define( 'REALT_PS_FILE', __FILE__ );
define( 'REALT_PS_PATH', plugin_dir_path( __FILE__ ) );
define( 'REALT_PS_URL', plugin_dir_url( __FILE__ ) );

// Simple PSR-4 style autoloader (composer-less)
require_once REALT_PS_PATH . 'src/Autoloader.php';
\Realt\PropertyScrapper\Autoloader::register( 'Realt\\PropertyScrapper', REALT_PS_PATH . 'src/' );

register_activation_hook( __FILE__, function () {
	// Ensure logs/upload dirs exist
	$upload_dir = wp_upload_dir();
	$base_dir = trailingslashit( $upload_dir['basedir'] ) . 'property-scrapper';
	if ( ! file_exists( $base_dir ) ) {
		wp_mkdir_p( $base_dir );
	}
	$logs_dir = trailingslashit( $base_dir ) . 'logs';
	if ( ! file_exists( $logs_dir ) ) {
		wp_mkdir_p( $logs_dir );
	}

	// Ensure exports dir exists for CSVs
	$exports_dir = trailingslashit( $base_dir ) . 'exports';
	if ( ! file_exists( $exports_dir ) ) {
		wp_mkdir_p( $exports_dir );
	}

	// Schedule cron if not set
	\Realt\PropertyScrapper\Cron\Scheduler::activate();
} );

register_deactivation_hook( __FILE__, function () {
	\Realt\PropertyScrapper\Cron\Scheduler::deactivate();
} );

add_action( 'plugins_loaded', function () {
	\Realt\PropertyScrapper\Plugin::instance()->init();
} );


