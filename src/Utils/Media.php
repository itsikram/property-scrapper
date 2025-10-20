<?php
namespace Realt\PropertyScrapper\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media {
	public static function download_and_attach_images( int $post_id, array $imageUrls ): array {
		$importOpts = \get_option( 'realt_ps_import', [] );
		$maxImages = max( 1, min( 15, (int) ( $importOpts['max_images'] ?? 6 ) ) );
		$imageTimeout = max( 5, min( 120, (int) ( $importOpts['image_timeout'] ?? 25 ) ) );
		// Ensure required WP includes are present
		if ( ! function_exists( 'download_url' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
		if ( ! function_exists( 'media_handle_sideload' ) ) { require_once ABSPATH . 'wp-admin/includes/media.php'; }
		if ( ! function_exists( 'wp_read_image_metadata' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
		$attachmentIds = [];
		$seen = [];
		$index = 0;
		foreach ( $imageUrls as $url ) {
			if ( count( $attachmentIds ) >= $maxImages ) { break; }
			$url = trim( (string) $url );
			if ( '' === $url ) { continue; }
			if ( isset( $seen[ $url ] ) ) { continue; }
			$seen[ $url ] = true;
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) { continue; }
			// Try to find existing attachment for this post by our source URL meta
			$existing = get_posts( [
				'post_type' => 'attachment',
				'posts_per_page' => 1,
				'post_parent' => $post_id,
				'fields' => 'ids',
				'meta_query' => [ [ 'key' => '_realt_ps_source_url', 'value' => $url, 'compare' => '=' ] ],
			] );
			if ( $existing ) {
				$attachmentIds[] = (int) $existing[0];
				continue;
			}
			$tmp = download_url( $url, $imageTimeout );
			if ( is_wp_error( $tmp ) ) { continue; }
			$filename = basename( parse_url( $url, PHP_URL_PATH ) );
			if ( ! $filename ) { $filename = 'image-' . wp_generate_password( 6, false ) . '.jpg'; }
			$file_array = [ 'name' => $filename, 'tmp_name' => $tmp ];
			$attachment_id = media_handle_sideload( $file_array, $post_id, null );
			if ( is_wp_error( $attachment_id ) ) {
				@unlink( $tmp );
				continue;
			}
			// Ensure attachment metadata and sizes are generated
			$attached_file = get_attached_file( (int) $attachment_id );
			if ( $attached_file ) {
				$meta = wp_generate_attachment_metadata( (int) $attachment_id, $attached_file );
				if ( ! is_wp_error( $meta ) && ! empty( $meta ) ) {
					wp_update_attachment_metadata( (int) $attachment_id, $meta );
				}
			}
			// Keep gallery order consistent with source
			wp_update_post( [ 'ID' => (int) $attachment_id, 'menu_order' => $index ] );
			update_post_meta( $attachment_id, '_realt_ps_source_url', esc_url_raw( $url ) );
			$attachmentIds[] = (int) $attachment_id;
			$index++;
		}
		return $attachmentIds;
	}

	public static function set_featured_if_missing( int $post_id, array $attachmentIds ): void {
		if ( empty( $attachmentIds ) ) { return; }
		if ( has_post_thumbnail( $post_id ) ) { return; }
		set_post_thumbnail( $post_id, (int) $attachmentIds[0] );
	}
}

?>


