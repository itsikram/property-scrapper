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
				// Enforce per-run item cap from scraping settings as an extra safety net
				$scrapeOpts = \get_option( 'realt_ps_scraping', [] );
				$maxItems = max( 1, (int) ( $scrapeOpts['max_items'] ?? 20 ) );
				if ( count( $items ) > $maxItems ) {
					$logger->log_info( 'cap_items_applied', [ 'before' => count( $items ), 'after' => $maxItems ] );
					$items = array_slice( $items, 0, $maxItems );
				}
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
			// Always set first image as featured thumbnail
			$featuredId = (int) $attachmentIds[0];
			if ( $featuredId > 0 ) {
				\set_post_thumbnail( $post_id, $featuredId );
				// Ensure both common keys exist for compatibility
				\update_post_meta( $post_id, '_thumbnail_id', $featuredId );
				\update_post_meta( $post_id, 'post_thumbnail_id', $featuredId );

				// if ( \post_type_supports( 'estate_property', 'thumbnail' ) ) {
				// } else {
				// 	\update_post_meta( $post_id, '_thumbnail_id', $featuredId );
				// }
			}
			// Store gallery IDs for theme/plugins; common meta keys used by WP Residence
			$idsInt = array_map( 'intval', $attachmentIds );
			$idsStr = array_map( 'strval', $attachmentIds );
			\update_post_meta( $post_id, 'property_images', $idsInt );
			// WP Residence uses both a CSV string and a serialized array in different contexts
			\update_post_meta( $post_id, 'property_image_gallery', implode( ',', $idsInt ) );
			\update_post_meta( $post_id, 'wpestate_property_gallery', $idsStr ); // array so WP serializes it
			\update_post_meta( $post_id, 'image_to_attach', implode( ',', $idsInt ) . ',' ); // mirrors editor save
			// Also keep a CSV version for convenience/export
			\update_post_meta( $post_id, '_realt_ps_gallery_ids', implode( ',', $idsInt ) );
			// Re-save post to trigger theme hooks (e.g., WP Residence) that run on save_post
			// and expect gallery meta to already be present. This mirrors the manual save fix.
			\clean_post_cache( $post_id );
			\do_action( 'save_post_estate_property', $post_id, \get_post( $post_id ), true );
			\do_action( 'save_post', $post_id, \get_post( $post_id ), true );
			\wp_update_post( [ 'ID' => $post_id ] );
			\clean_post_cache( $post_id );
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
		// Currency if provided by scraper
		if ( isset( $item['currency'] ) && '' !== trim( (string) $item['currency'] ) ) {
			$curr = strtoupper( preg_replace( '/[^A-Z]/', '', (string) $item['currency'] ) );
			if ( in_array( $curr, [ 'CZK', 'EUR', 'USD' ], true ) ) {
				\update_post_meta( $post_id, 'property_currency', $curr );
			}
		}
		// Ensure labels exist but empty unless you have values
		\update_post_meta( $post_id, 'property_price_before_label', '' );
		\update_post_meta( $post_id, 'property_label_before', '' );
		\update_post_meta( $post_id, 'property_price_after_label', '' );
		if ( $address ) { \update_post_meta( $post_id, 'property_address', $address ); }
		// Coordinates if available
		if ( isset( $item['lat'], $item['lng'] ) && is_numeric( $item['lat'] ) && is_numeric( $item['lng'] ) ) {
			\update_post_meta( $post_id, 'property_latitude', (string) $item['lat'] );
			\update_post_meta( $post_id, 'property_longitude', (string) $item['lng'] );
		}
		// Property type/action taxonomies (WP Residence): property_action_category, property_category
		$actionSlug = (string) ( $item['action'] ?? '' );
		$categorySlug = (string) ( $item['category_slug'] ?? '' );
		$subcategorySlug = (string) ( $item['subcategory_slug'] ?? '' );
		if ( '' !== $actionSlug ) {
			$this->ensure_term_and_assign( $post_id, 'property_action_category', ucfirst( str_replace( '-', ' ', $actionSlug ) ), $actionSlug );
		}
		if ( '' !== $subcategorySlug ) {
			$this->ensure_term_and_assign( $post_id, 'property_category', ucfirst( str_replace( '-', ' ', $subcategorySlug ) ), $subcategorySlug );
		}
		if ( '' !== $categorySlug ) {
			$this->ensure_term_and_assign( $post_id, 'property_category', ucfirst( str_replace( '-', ' ', $categorySlug ) ), $categorySlug );
		}
		// Taxonomies: property_city and property_area via assignment pipeline
		$lat = isset( $item['lat'] ) && is_numeric( $item['lat'] ) ? (float) $item['lat'] : null;
		$lng = isset( $item['lng'] ) && is_numeric( $item['lng'] ) ? (float) $item['lng'] : null;
		$assign = ( new \Realt\PropertyScrapper\Locations\Assigner() )->assign( $address, $city, $lat, $lng );
		$citySlug = (string) ( $assign['city_slug'] ?? '' );
		$areaSlug = (string) ( $assign['area_slug'] ?? '' );
		if ( '' === $citySlug && '' === $areaSlug ) {
			// Fallback to simple heuristics
			$citySlug = $this->derive_city_slug( $city, $address );
			$areaSlug = $this->derive_area_slug( $address );
		}
		$cityName = $city ? $city : ( $citySlug ? ucfirst( str_replace( '-', ' ', $citySlug ) ) : '' );
		$areaName = $areaSlug ? ucfirst( str_replace( '-', ' ', $areaSlug ) ) : '';
		if ( $citySlug ) { $this->ensure_term_and_assign( $post_id, 'property_city', $cityName ?: 'Praha', $citySlug ); }
		if ( $areaSlug ) { $this->ensure_term_and_assign( $post_id, 'property_area', $areaName, $areaSlug ); }

		// Populate additional defaults matching WP Residence admin save so frontend renders immediately
		$defaults = [
			'local_show_hide_price' => 'global',
			'property_label' => '',
			'property_second_price' => '',
			'property_label_before_second_price' => '',
			'property_second_price_label' => '',
			'property_year_tax' => '0',
			'property_hoa' => '0',
			'property_size' => '0',
			'property_lot_size' => '0',
			'property_rooms' => '0',
			'property_bedrooms' => '0',
			'property_bathrooms' => '0',
			'owner_notes' => '',
			'property_internal_id' => '',
			'prop_featured' => '0',
			'property_theme_slider' => '0',
			'embed_video_type' => 'vimeo',
			'embed_video_id' => '',
			'property_custom_video' => '',
			'embed_virtual_tour' => '',
			'mls' => '',
			'property-garage' => '',
			'property-year' => '',
			'property-garage-size' => '',
			'property-date' => '',
			'property-basement' => '',
			'property-external-construction' => '',
			'property-roofing' => '',
			'exterior-material' => '',
			'structure-type' => 'Not Available',
			'stories-number' => 'Not Available',
			'property_zip' => '',
			'property_country' => 'Czech Republic',
			'page_custom_zoom' => '16',
			'google_camera_angle' => '0',
			'property_google_view' => '',
			'property_hide_map_marker' => '',
			'energy_index' => '',
			'energy_class' => '',
			'co2_index' => '',
			'co2_class' => '',
			'renew_energy_index' => '',
			'building_energy_index' => '',
			'epc_current_rating' => '',
			'epc_potential_rating' => '',
			'property_agent' => '',
			'property_user' => '',
			'use_floor_plans' => '0',
			'property_has_subunits' => '',
			'property_subunits_list_manual' => '',
			'sidebar_agent_option' => 'global',
			'local_pgpr_slider_type' => 'global',
			'local_pgpr_content_type' => 'global',
			'property_page_desing_local' => '',
			'header_transparent' => 'global',
			'topbar_transparent' => 'global',
			'topbar_border_transparent' => 'global',
			'page_show_adv_search' => 'global',
			'page_use_float_search' => 'global',
			'page_wp_estate_float_form_top' => '',
			'sidebar_option' => 'global',
			'sidebar_select' => '',
			'header_type' => '0',
			'min_height' => '0',
			'max_height' => '0',
			'keep_min' => '',
			'keep_max' => '',
			'page_custom_image' => '',
			'page_header_image_full_screen' => 'no',
			'page_header_image_back_type' => 'cover',
			'page_header_title_over_image' => '',
			'page_header_subtitle_over_image' => '',
			'page_header_image_height' => '',
			'page_header_overlay_color' => '',
			'page_header_overlay_val' => '',
			'rev_slider' => '',
			'page_custom_video' => '',
			'page_custom_video_webbm' => '',
			'page_custom_video_ogv' => '',
			'page_custom_video_cover_image' => '',
			'page_header_video_full_screen' => 'no',
			'page_header_title_over_video' => '',
			'page_header_subtitle_over_video' => '',
			'page_header_video_height' => '',
			'page_header_overlay_color_video' => '',
			'page_header_overlay_val_video' => '',
		];
		foreach ( $defaults as $meta_key => $meta_value ) {
			\update_post_meta( $post_id, $meta_key, $meta_value );
		}
		// Map scraped fields: condition, energy class, and floor
		// Also map usable area (m2) to theme's expected meta key
		$areaM2 = (string) ( $item['area_m2'] ?? '' );
		if ( '' !== $areaM2 ) {
			$areaDigits = preg_replace( '/[^0-9]/', '', $areaM2 );
			if ( '' !== $areaDigits ) { \update_post_meta( $post_id, 'property_size', $areaDigits ); }
		}
		$condition = (string) ( $item['condition'] ?? '' );
		if ( '' !== $condition ) {
			\update_post_meta( $post_id, 'property_condition', $condition );
		}
		$energyClass = (string) ( $item['energy_class'] ?? '' );
		$energyLabel = (string) ( $item['energy_class_label'] ?? '' );
		if ( '' !== $energyClass ) { \update_post_meta( $post_id, 'energy_class', strtoupper( $energyClass ) ); }
		if ( '' !== $energyLabel ) { \update_post_meta( $post_id, '_realt_ps_energy_label', $energyLabel ); }
		$floorText = (string) ( $item['floor_text'] ?? '' );
		$floorNum = (string) ( $item['floor'] ?? '' );
		if ( '' !== $floorText ) { \update_post_meta( $post_id, '_realt_ps_floor_text', $floorText ); }
		if ( '' !== $floorNum ) {
			// Common WP Residence floor meta key
			\update_post_meta( $post_id, 'property_on_floor', $floorNum );
		}
		// Provide a basic hidden address similar to theme's computed field
		if ( $address || $city ) {
			$hidden = trim( $address );
			if ( $city ) { $hidden .= ( $hidden ? ', ' : '' ) . $city; }
			\update_post_meta( $post_id, 'hidden_address', $hidden );
		}
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
		$headers = [
			'unique_id',
			'post_title',
			'post_content',
			'post_status',
			'post_type',
			'property_price',
			'prop_price',
			'property_currency',
			'property_address',
			'city',
			'city_slug',
			'area_slug',
			'property_latitude',
			'property_longitude',
			'property_size',
			'property_condition',
			'energy_class',
			'_realt_ps_energy_label',
			'property_on_floor',
			'_realt_ps_floor_text',
			'hidden_address',
			'action',
			'category_slug',
			'subcategory_slug',
			'source_url',
			'images',
		];
		$fh = \fopen( $filepath, 'w' );
		if ( false === $fh ) { throw new \RuntimeException( 'Cannot open CSV for writing' ); }
		// BOM for Excel compatibility
		\fwrite( $fh, "\xEF\xBB\xBF" );
		\fputcsv( $fh, $headers );
		foreach ( $items as $item ) {
			$address = (string) ( $item['address'] ?? '' );
			$city = (string) ( $item['city'] ?? '' );
			$price = (string) ( $item['price'] ?? '' );
			$priceDigits = $price !== '' ? preg_replace( '/[^0-9]/', '', $price ) : '';
			$currency = '';
			if ( isset( $item['currency'] ) && '' !== trim( (string) $item['currency'] ) ) {
				$curr = strtoupper( preg_replace( '/[^A-Z]/', '', (string) $item['currency'] ) );
				if ( in_array( $curr, [ 'CZK', 'EUR', 'USD' ], true ) ) { $currency = $curr; }
			}
			$areaM2 = (string) ( $item['area_m2'] ?? '' );
			$areaDigits = '';
			if ( '' !== $areaM2 ) { $areaDigits = preg_replace( '/[^0-9]/', '', $areaM2 ); }
			$condition = (string) ( $item['condition'] ?? '' );
			$energyClass = (string) ( $item['energy_class'] ?? '' );
			$energyLabel = (string) ( $item['energy_class_label'] ?? '' );
			$floorText = (string) ( $item['floor_text'] ?? '' );
			$floorNum = (string) ( $item['floor'] ?? '' );
			$citySlug = (string) ( $item['city_slug'] ?? '' );
			$areaSlug = (string) ( $item['area_slug'] ?? '' );
			if ( '' === $citySlug && '' === $areaSlug ) {
				$citySlug = $this->derive_city_slug( $city, $address );
				$areaSlug = $this->derive_area_slug( $address );
			}
			$hidden = '';
			if ( $address || $city ) {
				$hidden = trim( $address );
				if ( $city ) { $hidden .= ( $hidden ? ', ' : '' ) . $city; }
			}
			$action = (string) ( $item['action'] ?? '' );
			$categorySlug = (string) ( $item['category_slug'] ?? '' );
			$subcategorySlug = (string) ( $item['subcategory_slug'] ?? '' );
			$row = [
				(string) ( $item['external_id'] ?? '' ),
				(string) ( $item['title'] ?? '' ),
				(string) ( $item['description'] ?? '' ),
				'publish',
				'estate_property',
				$priceDigits,
				$priceDigits,
				$currency,
				$address,
				$city,
				$citySlug,
				$areaSlug,
				isset( $item['lat'] ) ? (string) $item['lat'] : '',
				isset( $item['lng'] ) ? (string) $item['lng'] : '',
				$areaDigits,
				$condition,
				$energyClass !== '' ? strtoupper( $energyClass ) : '',
				$energyLabel,
				$floorNum,
				$floorText,
				$hidden,
				$action,
				$categorySlug,
				$subcategorySlug,
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
		// Remove apostrophes/backticks added in transliteration (e.g., "Dub'a" -> "duba")
		$v = str_replace( ["'", "’", "`"], '', $v );
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


