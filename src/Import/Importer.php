<?php
namespace Realt\PropertyScrapper\Import;

use Realt\PropertyScrapper\Scraper\CeskeRealityScraper;
use Realt\PropertyScrapper\Utils\Logger;
use Realt\PropertyScrapper\Utils\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Importer {
	public function run( $mode = 'scraping' ) {
		$logger = new Logger();
		$logger->log_info( 'import_start', [ 'mode' => $mode ] );
		try {
			$opts = \get_option( 'realt_ps_import', [] );
			$maxSeconds = max( 30, min( 900, (int) ( $opts['max_runtime'] ?? 300 ) ) );
			$startedAt = microtime( true );
			$items = [];
			if ( 'scraping' === $mode ) {
				$items = ( new CeskeRealityScraper() )->fetch();
			}
			// Enforce global runtime budget before heavy work like media downloads
			if ( ( microtime( true ) - $startedAt ) > $maxSeconds ) {
				$logger->log_warn( 'import_time_budget_exceeded', [ 'seconds' => $maxSeconds, 'items' => count( $items ) ] );
				$items = array_slice( $items, 0, 1 );
			}
			$this->sync_items( $items, $logger );
			// After syncing, export to CSV for download
			try {
				$this->export_csv( $items );
			} catch ( \Throwable $e ) {
				$logger->log_error( 'csv_export_failed', [ 'message' => $e->getMessage() ] );
			}
			$logger->log_info( 'import_end', [ 'count' => count( $items ) ] );
		} catch ( \Throwable $e ) {
			$logger->log_error( 'import_error', [ 'message' => $e->getMessage() ] );
		}
	}

	private function sync_items( array $items, Logger $logger ) {
		foreach ( $items as $item ) {
			$external_id = $item['external_id'] ?? '';
			if ( ! $external_id ) {
				$logger->log_warn( 'skip_no_external_id', [] );
				continue;
			}
			$post_id = $this->find_existing( $external_id );
			if ( $post_id ) {
				$this->update_post( $post_id, $item, $logger );
			} else {
				$this->create_post( $item, $logger );
			}
		}
	}

	private function find_existing( string $external_id ) {
		$posts = \get_posts( [
			'post_type' => 'estate_property',
			'posts_per_page' => 1,
			'meta_key' => '_realt_ps_external_id',
			'meta_value' => $external_id,
			'fields' => 'ids',
		] );
		return $posts ? (int) $posts[0] : 0;
	}

	private function create_post( array $item, Logger $logger ) {
		$postarr = [
			'post_type' => 'estate_property',
			'post_status' => 'publish',
			'post_title' => \wp_strip_all_tags( $item['title'] ?? '' ),
			'post_content' => \wp_kses_post( $item['description'] ?? '' ),
		];
		$post_id = \wp_insert_post( $postarr, true );
		if ( \is_wp_error( $post_id ) ) {
			$logger->log_error( 'create_failed', [ 'error' => $post_id->get_error_message() ] );
			return;
		}
		\update_post_meta( $post_id, '_realt_ps_external_id', \sanitize_text_field( $item['external_id'] ) );
		$this->handle_images( $post_id, $item, $logger );
		$this->handle_wpresidence_fields( $post_id, $item );
		$logger->log_info( 'created', [ 'post_id' => $post_id, 'external_id' => $item['external_id'] ] );
	}

	private function update_post( int $post_id, array $item, Logger $logger ) {
		$postarr = [
			'ID' => $post_id,
			'post_title' => \wp_strip_all_tags( $item['title'] ?? '' ),
			'post_content' => \wp_kses_post( $item['description'] ?? '' ),
		];
		$result = \wp_update_post( $postarr, true );
		if ( \is_wp_error( $result ) ) {
			$logger->log_error( 'update_failed', [ 'post_id' => $post_id, 'error' => $result->get_error_message() ] );
			return;
		}
		$this->handle_images( $post_id, $item, $logger );
		$this->handle_wpresidence_fields( $post_id, $item );
		$logger->log_info( 'updated', [ 'post_id' => $post_id ] );
	}

	private function handle_images( int $post_id, array $item, Logger $logger ): void {
		$images = $item['images'] ?? [];
		if ( ! is_array( $images ) || empty( $images ) ) { return; }
		$attachmentIds = Media::download_and_attach_images( $post_id, $images );
		if ( $attachmentIds ) {
			Media::set_featured_if_missing( $post_id, $attachmentIds );
			// Store gallery IDs for theme/plugins; common meta keys used by WP Residence
			\update_post_meta( $post_id, 'property_images', array_map( 'intval', $attachmentIds ) );
			// Also keep a CSV version for convenience/export
			\update_post_meta( $post_id, '_realt_ps_gallery_ids', implode( ',', array_map( 'intval', $attachmentIds ) ) );
		}
	}

	private function handle_wpresidence_fields( int $post_id, array $item ): void {
		$address = (string) ( $item['address'] ?? '' );
		$city = (string) ( $item['city'] ?? '' );
		$price = (string) ( $item['price'] ?? '' );
		$priceDigits = $price !== '' ? preg_replace( '/[^0-9]/', '', $price ) : '';
		// Meta fields expected by WP Residence
		if ( $priceDigits !== '' ) {
			\update_post_meta( $post_id, 'property_price', $priceDigits );
			// Some installs/themes read this alias as well
			\update_post_meta( $post_id, 'prop_price', $priceDigits );
		}
		// Ensure labels exist but empty unless you have values
		\update_post_meta( $post_id, 'property_price_before_label', '' );
		\update_post_meta( $post_id, 'property_price_after_label', '' );
		if ( $address ) { \update_post_meta( $post_id, 'property_address', $address ); }
		// Coordinates if available
		if ( isset( $item['lat'], $item['lng'] ) && is_numeric( $item['lat'] ) && is_numeric( $item['lng'] ) ) {
			\update_post_meta( $post_id, 'property_latitude', (string) $item['lat'] );
			\update_post_meta( $post_id, 'property_longitude', (string) $item['lng'] );
		}
		// Taxonomies: property_city and property_area
		$citySlug = $this->derive_city_slug( $city, $address );
		$areaSlug = $this->derive_area_slug( $address );
		$cityName = $city ? $city : ( $citySlug ? ucfirst( str_replace( '-', ' ', $citySlug ) ) : '' );
		$areaName = $areaSlug ? ucfirst( str_replace( '-', ' ', $areaSlug ) ) : '';
		if ( $citySlug ) { $this->ensure_term_and_assign( $post_id, 'property_city', $cityName ?: 'Praha', $citySlug ); }
		if ( $areaSlug ) { $this->ensure_term_and_assign( $post_id, 'property_area', $areaName, $areaSlug ); }
	}

	private function ensure_term_and_assign( int $post_id, string $taxonomy, string $name, string $slug ): void {
		if ( '' === $slug ) { return; }
		$term = \get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term || \is_wp_error( $term ) ) {
			$created = \wp_insert_term( $name ?: $slug, $taxonomy, [ 'slug' => $slug ] );
			if ( \is_wp_error( $created ) ) { return; }
			$term_id = (int) $created['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}
		\wp_set_object_terms( $post_id, [ $term_id ], $taxonomy, false );
	}

	private function export_csv( array $items ) {
		$upload_dir = \wp_upload_dir();
		$base_dir = trailingslashit( $upload_dir['basedir'] ) . 'property-scrapper';
		$exports_dir = trailingslashit( $base_dir ) . 'exports';
		if ( ! file_exists( $exports_dir ) ) { \wp_mkdir_p( $exports_dir ); }
		$timestamp = gmdate( 'Ymd_His' );
		$filename = 'scrape_' . $timestamp . '.csv';
		$filepath = trailingslashit( $exports_dir ) . $filename;
		$headers = [ 'unique_id', 'post_title', 'post_content', 'post_status', 'post_type', 'price', 'address', 'city', 'lat', 'lng', 'source_url', 'images' ];
		$fh = \fopen( $filepath, 'w' );
		if ( false === $fh ) { throw new \RuntimeException( 'Cannot open CSV for writing' ); }
		// BOM for Excel compatibility
		\fwrite( $fh, "\xEF\xBB\xBF" );
		\fputcsv( $fh, $headers );
		foreach ( $items as $item ) {
			$address = (string) ( $item['address'] ?? '' );
			$city = (string) ( $item['city'] ?? '' );
			$row = [
				(string) ( $item['external_id'] ?? '' ),
				(string) ( $item['title'] ?? '' ),
				(string) ( $item['description'] ?? '' ),
				'publish',
				'estate_property',
				(string) ( $item['price'] ?? '' ),
				(string) ( $item['address'] ?? '' ),
				(string) ( $item['city'] ?? '' ),
				isset( $item['lat'] ) ? (string) $item['lat'] : '',
				isset( $item['lng'] ) ? (string) $item['lng'] : '',
				(string) ( $item['source_url'] ?? '' ),
				is_array( $item['images'] ?? null ) ? implode( '|', $item['images'] ) : (string) ( $item['images'] ?? '' ),
			];
			\fputcsv( $fh, $row );
		}
		\fclose( $fh );
		// Save the latest filename for download
		\update_option( 'realt_ps_latest_csv', [ 'path' => $filepath, 'url' => trailingslashit( $upload_dir['baseurl'] ) . 'property-scrapper/exports/' . $filename, 'time' => time(), 'count' => count( $items ) ], false );
	}
	private function to_ascii( string $value ): string {
		$trans = @\iconv( 'UTF-8', 'ASCII//TRANSLIT', $value );
		if ( false === $trans || null === $trans ) { $trans = $value; }
		return $trans;
	}

	private function slugify( string $value ): string {
		$v = strtolower( $this->to_ascii( $value ) );
		$v = preg_replace( '/[^a-z0-9]+/', '-', $v );
		$v = trim( $v, '-' );
		return $v;
	}

	private function derive_city_slug( string $city, string $address ): string {
		$in = $city . ' ' . $address;
		if ( preg_match( '/praha\s*(\d{1,2})/iu', $in, $m ) ) {
			return 'praha-' . (int) $m[1];
		}
		if ( preg_match( '/hlav(n|ní)\s*m(ě|e)sto\s*praha/iu', $in ) || preg_match( '/praha/iu', $in ) ) {
			return 'praha';
		}
		return $this->slugify( $city );
	}

	private function derive_area_slug( string $address ): string {
		// Expect formats like: "Praha 4 - Podolí" or ", Podolí"
		if ( preg_match( '/-\s*([^,]+)$/u', $address, $m ) ) {
			return $this->slugify( trim( $m[1] ) );
		}
		if ( preg_match( '/,\s*([^,]+)$/u', $address, $m ) ) {
			return $this->slugify( trim( $m[1] ) );
		}
		return '';
	}

	private function derive_street_normalized( string $address ): string {
		// Take the part before the first comma as street, strip numbers
		$street = $address;
		$pos = strpos( $address, ',' );
		if ( false !== $pos ) { $street = substr( $address, 0, $pos ); }
		$street = preg_replace( '/\d+.*/u', '', $street );
		$street = trim( $street );
		$ascii = strtolower( $this->to_ascii( $street ) );
		$ascii = preg_replace( '/[^a-z]/', '', $ascii );
		return $ascii;
	}
}


