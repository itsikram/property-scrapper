<?php
// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clear scheduled events
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'realt_ps/sync' );
}

// Delete plugin options (single and sitewide for multisite)
$options = [
	'realt_ps_import',
	'realt_ps_geocoding',
	'realt_ps_scraping',
	'realt_ps_latest_csv',
	'realt_ps_last_run',
];
foreach ( $options as $opt ) {
	if ( function_exists( 'delete_option' ) ) { delete_option( $opt ); }
	if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'delete_site_option' ) ) { delete_site_option( $opt ); }
}

// Delete transients
if ( function_exists( 'delete_transient' ) ) { delete_transient( 'realt_ps_preview' ); }
if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'delete_site_transient' ) ) { delete_site_transient( 'realt_ps_preview' ); }

// Remove plugin-generated upload directory (logs, exports, geo uploads)
if ( function_exists( 'wp_upload_dir' ) ) {
	$upload = wp_upload_dir();
	$base_dir = trailingslashit( $upload['basedir'] ) . 'property-scrapper';
	if ( is_string( $base_dir ) && $base_dir !== '' && strpos( $base_dir, 'property-scrapper' ) !== false && file_exists( $base_dir ) ) {
		$delete_recursive = function( $path ) use ( &$delete_recursive ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				@chmod( $path, 0644 );
				@unlink( $path );
				return;
			}
			if ( is_dir( $path ) ) {
				$items = @scandir( $path );
				if ( is_array( $items ) ) {
					foreach ( $items as $item ) {
						if ( $item === '.' || $item === '..' ) { continue; }
						$delete_recursive( $path . DIRECTORY_SEPARATOR . $item );
					}
				}
				@rmdir( $path );
			}
		};
		$delete_recursive( $base_dir );
	}
}


