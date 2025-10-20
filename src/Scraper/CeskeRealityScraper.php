<?php
namespace Realt\PropertyScrapper\Scraper;

use Realt\PropertyScrapper\Utils\RateLimiter;
use Realt\PropertyScrapper\Utils\Logger;
use Realt\PropertyScrapper\Utils\HttpClient;
use Realt\PropertyScrapper\Utils\Html;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CeskeRealityScraper {
	private $logger;
	private $rateLimiter;

	public function __construct() {
		$this->logger = new Logger();
		$opts = \get_option( 'realt_ps_scraping', [ 'rate_limit' => 10 ] );
		$this->rateLimiter = new RateLimiter( (int) ( $opts['rate_limit'] ?? 10 ) );
	}

	public function fetch(): array {
		$this->logger->log_info( 'scrape_start', [] );
		$items = [];
		$selectors = $this->load_selectors();
		$startUrls = $this->get_start_urls();
		// Runtime controls and HTTP tuning
		$optsScrape = \get_option( 'realt_ps_scraping', [] );
		$optsImport = \get_option( 'realt_ps_import', [] );
		$maxSeconds = max( 15, (int) ( $optsScrape['max_seconds'] ?? 50 ) );
		// Prefer Import tab's max_items if set, fallback to Scraping for backward compatibility
		$maxItems = max( 1, (int) ( $optsImport['max_items'] ?? ( $optsScrape['max_items'] ?? 5 ) ) );
		$httpTimeout = max( 5, min( 30, (int) ( $optsScrape['http_timeout'] ?? 12 ) ) );
		$httpRetries = max( 0, min( 3, (int) ( $optsScrape['http_retries'] ?? 2 ) ) );
		$client = new HttpClient( $this->rateLimiter, '', $httpTimeout, $httpRetries );
		$startedAt = microtime( true );
		foreach ( $startUrls as $rawUrl ) {
			if ( ( microtime( true ) - $startedAt ) > $maxSeconds ) { $this->logger->log_info( 'time_budget_exceeded', [ 'seconds' => $maxSeconds, 'count' => count( $items ) ] ); break; }
			if ( count( $items ) >= $maxItems ) { $this->logger->log_info( 'item_cap_reached', [ 'max_items' => $maxItems ] ); break; }
			$url = $this->normalize_ceske_url( $rawUrl );
			$resp = $client->get( $url );
			if ( empty( $resp['ok'] ) ) {
				// One-shot retry with alternative normalization variants for ceskereality 404s
				$retryUrl = $this->retry_variant_url( $url );
				if ( $retryUrl && $retryUrl !== $url ) {
					$this->logger->log_info( 'retry_list_url', [ 'from' => $url, 'to' => $retryUrl ] );
					$resp = $client->get( $retryUrl );
					$url = $retryUrl;
				}
			}
			if ( empty( $resp['ok'] ) ) { $this->logger->log_warn( 'fetch_list_failed', [ 'url' => $url, 'error' => $resp['error'] ?? '' ] ); continue; }
			$listHtml = $resp['body'];
			$listNodes = $this->first_nodes( $listHtml, $this->selectors_to_array( $selectors['list']['item'] ?? '.item' ) );
			// If no list nodes found, attempt a generic anchor scan to discover detail links
			if ( empty( $listNodes ) ) {
				$allAnchors = Html::query_all( $listHtml, 'a' );
				$fakeNodes = [];
				foreach ( $allAnchors as $a ) {
					$href = Html::attr( $a, 'href' );
					if ( $this->looks_like_detail_url( $href ) ) { $fakeNodes[] = $a; }
				}
				// Wrap anchors as a homogeneous node list to reuse the loop below
				if ( $fakeNodes ) { $listNodes = $fakeNodes; }
			}
			foreach ( $listNodes as $node ) {
				if ( ( microtime( true ) - $startedAt ) > $maxSeconds ) { $this->logger->log_info( 'time_budget_exceeded', [ 'seconds' => $maxSeconds, 'count' => count( $items ) ] ); break 2; }
				if ( count( $items ) >= $maxItems ) { $this->logger->log_info( 'item_cap_reached', [ 'max_items' => $maxItems ] ); break 2; }
				$title = '';
				$link = '';
				$listPrice = '';
				// Title fallbacks
				foreach ( $this->selectors_to_array( $selectors['list']['title'] ?? '.title, h2, h3, .name' ) as $tSel ) {
					$titleNodes = Html::query_all( $node->C14N(), $tSel );
					if ( $titleNodes ) { $title = Html::text( $titleNodes[0] ); break; }
				}
				// Price fallbacks on list card (used as fallback if detail has none)
				foreach ( $this->selectors_to_array( $selectors['list']['price'] ?? '.i-estate__footer-price-value, .price, .price-value, .cena' ) as $pSel ) {
					$priceNodes = Html::query_all( $node->C14N(), $pSel );
					if ( $priceNodes ) { $listPrice = Html::text( $priceNodes[0] ); break; }
				}
				// URL fallbacks (prefer real detail links)
				foreach ( $this->selectors_to_array( $selectors['list']['url'] ?? '.title a@href, h2 a@href, h3 a@href, a@href' ) as $uSel ) {
					list( $urlCss, $urlAttr ) = $this->split_selector_attr( $uSel, 'href' );
					$linkNodes = Html::query_all( $node->C14N(), $urlCss );
					if ( $linkNodes ) { $link = Html::attr( $linkNodes[0], $urlAttr ); if ( $this->looks_like_detail_url( $link ) ) { break; } }
				}
				// If selector-based attempt failed or chose a non-detail link, scan all anchors inside the card
				if ( ! $link || ! $this->looks_like_detail_url( $link ) ) {
					$allAnchors = Html::query_all( $node->C14N(), 'a' );
					$link = '';
					foreach ( $allAnchors as $aNode ) {
						$href = Html::attr( $aNode, 'href' );
						if ( $this->looks_like_detail_url( $href ) ) { $link = $href; break; }
					}
				}
				if ( ! $link ) { continue; }
				$link = $this->resolve_url( $url, $link );
				$detail = $client->get( $link );
				if ( empty( $detail['ok'] ) ) { $this->logger->log_warn( 'fetch_detail_failed', [ 'url' => $link, 'error' => $detail['error'] ?? '' ] ); continue; }
                $item = $this->parse_detail( $detail['body'], $selectors );
                $item['source_url'] = $link;
				// Derive action/category/subcategory from URL path (e.g., /prodej/komercni-prostory/hotely/...)
				$ptype = $this->extract_type_from_url( $link );
				if ( ! empty( $ptype ) ) {
					if ( isset( $ptype['action'] ) ) { $item['action'] = $ptype['action']; }
					if ( isset( $ptype['category'] ) ) { $item['category_slug'] = $ptype['category']; }
					if ( isset( $ptype['subcategory'] ) ) { $item['subcategory_slug'] = $ptype['subcategory']; }
				}
				$item['title'] = $item['title'] ?: $title;
				// Normalize price and fallback to list-card price if detail is empty/non-numeric
				$item['price'] = $this->normalize_price( (string) ( $item['price'] ?? '' ) );
				if ( '' === $item['price'] && $listPrice ) {
					$item['price'] = $this->normalize_price( $listPrice );
				}
                // Ensure image URLs are absolute so media sideload works
                if ( ! empty( $item['images'] ) && is_array( $item['images'] ) ) {
                    $resolved = [];
                    foreach ( $item['images'] as $imgUrl ) {
                        $abs = $this->resolve_url( $link, (string) $imgUrl );
                        if ( $abs ) { $resolved[] = $abs; }
                    }
                    $item['images'] = array_values( array_unique( array_filter( $resolved ) ) );
                }
				// Persist a JSON snapshot of the scraped item for debugging
				// $this->logger->save_json_item( $item );
				// if ( isset( $item['city'] ) && '' !== trim( (string) $item['city'] ) && ! $this->is_prague( $item['city'] ) ) { continue; }
				$items[] = $item;
			}
		}
		$this->logger->log_info( 'scrape_end', [ 'count' => count( $items ) ] );
		return $items;
	}

	public function preview(): array {
		$selectors = $this->load_selectors();
		$urls = $this->get_start_urls();
		if ( empty( $urls ) ) { return [ 'ok' => false, 'error' => 'No start URL' ]; }
		$opts = \get_option( 'realt_ps_scraping', [] );
		$httpTimeout = max( 5, min( 20, (int) ( $opts['http_timeout'] ?? 8 ) ) );
		$httpRetries = max( 0, min( 1, (int) ( $opts['http_retries'] ?? 0 ) ) );
		$client = new HttpClient( $this->rateLimiter, '', $httpTimeout, $httpRetries );
		$resp = $client->get( $urls[0] );
		if ( empty( $resp['ok'] ) ) {
			return [ 'ok' => false, 'error' => $resp['error'] ?? 'Request failed' ];
		}
		$html = $resp['body'];
		$listNodes = $this->first_nodes( $html, $this->selectors_to_array( $selectors['list']['item'] ?? '.item' ) );
		$samples = [];
		foreach ( $listNodes as $node ) {
			$title = '';
			$link = '';
			foreach ( $this->selectors_to_array( $selectors['list']['title'] ?? '.title, h2, h3, .name' ) as $tSel ) {
				$tn = Html::query_all( $node->C14N(), $tSel );
				if ( $tn ) { $title = Html::text( $tn[0] ); break; }
			}
			foreach ( $this->selectors_to_array( $selectors['list']['url'] ?? '.title a@href, h2 a@href, h3 a@href, a@href' ) as $uSel ) {
				list( $uCss, $uAttr ) = $this->split_selector_attr( $uSel, 'href' );
				$ln = Html::query_all( $node->C14N(), $uCss );
				if ( $ln ) { $link = Html::attr( $ln[0], $uAttr ); break; }
			}
			if ( $title || $link ) { $samples[] = [ 'title' => $title, 'url' => $link ]; }
			if ( count( $samples ) >= 5 ) { break; }
		}
		return [ 'ok' => true, 'count' => count( $listNodes ), 'samples' => $samples ];
	}

	private function split_selector_attr( string $selector, string $defaultAttr ): array {
		$selector = trim( $selector );
		if ( false !== strpos( $selector, '@' ) ) {
			list( $sel, $attr ) = explode( '@', $selector, 2 );
			return [ trim( $sel ), trim( $attr ) ?: $defaultAttr ];
		}
		return [ $selector, $defaultAttr ];
	}

	private function load_selectors(): array {
		$path = REALT_PS_PATH . 'config/selectors.json';
		if ( \file_exists( $path ) ) {
			$json = \file_get_contents( $path );
			$data = \json_decode( $json, true );
			if ( \is_array( $data ) ) { return $data; }
		}
		return [ 'list' => [], 'detail' => [] ];
	}

	private function get_start_urls(): array {
		$cfg = \get_option( 'realt_ps_scraping', [] );
		$urlsRaw = trim( (string) ( $cfg['start_urls'] ?? '' ) );
		if ( ! $urlsRaw ) {
			return [
				'https://www.ceskereality.cz/prodej/byty/',
				'https://www.ceskereality.cz/prodej/pozemky/',
			];
		}
		$urls = array_filter( array_map( 'trim', preg_split( '/\r?\n/', $urlsRaw ) ) );
		$normalized = [];
		foreach ( $urls as $u ) {
			$normalized[] = $this->normalize_ceske_url( $u );
		}
		return $normalized ?: [
			'https://www.ceskereality.cz/prodej/byty/',
			'https://www.ceskereality.cz/prodej/pozemky/',
		];
	}

	private function parse_detail( string $html, array $selectors ): array {
		$dsel = $selectors['detail'] ?? [];
		$data = [];
		$data['external_id'] = $this->first_text( $html, $this->selectors_to_array( $dsel['external_id'] ?? '' ) );
		// Fallback: extract by label "ID nemovitosti" from info grid when selectors fail or captured junk
		if ( '' === trim( (string) $data['external_id'] ) || strlen( (string) $data['external_id'] ) > 64 ) {
			$infoBlocks = Html::query_all( $html, 'div.i-info' );
			foreach ( $infoBlocks as $blk ) {
				$titleNodes = Html::query_all( $blk->C14N(), 'span.i-info__title' );
				$valueNodes = Html::query_all( $blk->C14N(), 'span.i-info__value' );
				if ( ! $titleNodes || ! $valueNodes ) { continue; }
				$title = mb_strtolower( Html::text( $titleNodes[0] ) );
				if ( false === strpos( $title, 'id nemovitosti' ) ) { continue; }
				$val = Html::text( $valueNodes[0] );
				$val = trim( preg_replace( '/\s+/u', ' ', $val ) );
				if ( '' !== $val ) { $data['external_id'] = $val; break; }
			}
		}
		$data['title'] = $this->first_text( $html, $this->selectors_to_array( $dsel['title'] ?? 'h1' ) );
		$data['description'] = $this->first_text( $html, $this->selectors_to_array( $dsel['description'] ?? '.description' ) );
		if ( '' === trim( (string) $data['description'] ) ) {
			// JSON-LD fallback
			$scriptNodes = Html::query_all( $html, 'script' );
			foreach ( $scriptNodes as $sn ) {
				$type = strtolower( Html::attr( $sn, 'type' ) );
				if ( false === strpos( $type, 'ld+json' ) ) { continue; }
				$json = trim( Html::text( $sn ) );
				if ( '' === $json ) { continue; }
				$decoded = json_decode( $json, true );
				if ( is_array( $decoded ) && isset( $decoded['description'] ) && is_string( $decoded['description'] ) ) {
					$data['description'] = trim( $decoded['description'] );
					break;
				}
			}
			// Meta fallback
			if ( '' === trim( (string) $data['description'] ) ) {
				$metas = Html::query_all( $html, 'meta' );
				foreach ( $metas as $meta ) {
					$name = strtolower( Html::attr( $meta, 'name' ) );
					$prop = strtolower( Html::attr( $meta, 'property' ) );
					if ( in_array( $name, [ 'description', 'twitter:description' ], true ) || in_array( $prop, [ 'og:description' ], true ) ) {
						$val = Html::attr( $meta, 'content' );
						if ( '' !== trim( $val ) ) { $data['description'] = trim( $val ); break; }
					}
				}
			}
		}
		$data['price'] = $this->first_text( $html, $this->selectors_to_array( $dsel['price'] ?? '.price' ) );
		$data['price'] = $this->normalize_price( $data['price'] );
		// Currency via JSON-LD priceCurrency or by heuristics from visible text
		$currency = $this->extract_currency_from_jsonld( $html );
		if ( '' === $currency ) {
			// Simple visible-symbol fallback
			if ( preg_match( '/\bCZK\b|Kč/u', $html ) ) { $currency = 'CZK'; }
			elseif ( false !== strpos( $html, '€' ) || preg_match( '/\bEUR\b/u', $html ) ) { $currency = 'EUR'; }
			elseif ( false !== strpos( $html, '$' ) || preg_match( '/\bUSD\b/u', $html ) ) { $currency = 'USD'; }
		}
		if ( '' !== $currency ) { $data['currency'] = $currency; }
		$address = $this->first_text( $html, $this->selectors_to_array( $dsel['address'] ?? '.address' ) );
		$data['address'] = $address;
		$data['city'] = $this->extract_city( $address );
		// Area (m2): selectors then fallback to label-based scan for "Plocha užitná"
		$areaRaw = $this->first_text( $html, $this->selectors_to_array( $dsel['area_m2'] ?? '' ) );
		if ( '' === trim( (string) $areaRaw ) ) {
			$infoBlocks = Html::query_all( $html, 'div.i-info' );
			foreach ( $infoBlocks as $blk ) {
				$titleNodes = Html::query_all( $blk->C14N(), 'span.i-info__title' );
				$valueNodes = Html::query_all( $blk->C14N(), 'span.i-info__value' );
				if ( ! $titleNodes || ! $valueNodes ) { continue; }
				$title = mb_strtolower( Html::text( $titleNodes[0] ) );
				// Match common Czech labels for usable area
				if ( false !== strpos( $title, 'plocha užitná' ) || false !== strpos( $title, 'užitná plocha' ) ) {
					$areaRaw = Html::text( $valueNodes[0] );
					break;
				}
			}
		}
		if ( '' !== trim( (string) $areaRaw ) ) {
			$digits = preg_replace( '/[^0-9]/', '', $areaRaw );
			$data['area_m2'] = $digits !== '' ? $digits : '';
		}
		// Site-specific fallback: address and city from driving distance input
		$addrInput = Html::query_all( $html, '#driving_calculator_from' );
		if ( $addrInput ) {
			$inp = $addrInput[0];
			$val = Html::attr( $inp, 'value' );
			if ( '' !== trim( $val ) && '' === trim( (string) ( $data['address'] ?? '' ) ) ) {
				$data['address'] = trim( $val );
			}
			$cityMeta = Html::attr( $inp, 'data-city' );
			if ( '' !== trim( $cityMeta ) ) {
				$cityOnly = trim( preg_replace( '/\s*\([^\)]*\)\s*/u', '', $cityMeta ) );
				$data['city'] = $cityOnly ?: ( $data['city'] ?? '' );
			}
		}
		// Coordinates (latitude/longitude) from JSON-LD, meta tags or data-* attributes
		$coords = $this->extract_coordinates( $html );
		if ( $coords ) {
			$data['lat'] = $coords['lat'];
			$data['lng'] = $coords['lng'];
		}
		// If still missing, try input data attributes directly
		if ( ( $data['lat'] ?? null ) === null || ( $data['lng'] ?? null ) === null ) {
			if ( ! empty( $addrInput ) ) {
				$inp = $addrInput[0];
				$ilat = Html::attr( $inp, 'data-coord-lat' );
				$ilng = Html::attr( $inp, 'data-coord-lng' );
				if ( '' !== trim( $ilat ) && '' !== trim( $ilng ) ) {
					$data['lat'] = $this->to_float( $ilat );
					$data['lng'] = $this->to_float( $ilng );
				}
			}
		}
		// Additional attributes from info grid: condition, energy class, floor
		$infoBlocks = Html::query_all( $html, 'div.i-info' );
		if ( $infoBlocks ) {
			foreach ( $infoBlocks as $blk ) {
				$titleNodes = Html::query_all( $blk->C14N(), 'span.i-info__title' );
				$valueNodes = Html::query_all( $blk->C14N(), 'span.i-info__value' );
				if ( ! $titleNodes || ! $valueNodes ) { continue; }
				$rawTitle = Html::text( $titleNodes[0] );
				$title = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $rawTitle ) ) );
				$val = trim( preg_replace( '/\s+/u', ' ', Html::text( $valueNodes[0] ) ) );
				if ( '' === $val ) { continue; }
				// Energy performance class (e.g., "G - Mimořádně nehospodárná")
				if ( false !== strpos( $title, 'energetick' ) || false !== strpos( $title, 'energetická náročnost' ) ) {
					$data['energy_class_label'] = $val;
					// Extract leading class letter A-G if present
					if ( preg_match( '/\b([A-G])\b/u', strtoupper( $val ), $m ) ) {
						$data['energy_class'] = strtoupper( $m[1] );
					}
					continue;
				}
				// Property condition (e.g., "Bezvadný", "Po rekonstrukci")
				if ( false !== strpos( $title, 'stav nemovitosti' ) ) {
					$data['condition'] = $val;
					continue;
				}
				// Floor / storey (e.g., "3. patro", "1. podlaží", "Přízemí")
				if ( false !== strpos( $title, 'patro' ) || false !== strpos( $title, 'podlaž' ) || false !== strpos( $title, 'podlazi' ) ) {
					$data['floor_text'] = $val;
					$lower = mb_strtolower( $val );
					$floorNum = null;
					if ( false !== strpos( $lower, 'přízem' ) ) { $floorNum = 0; }
					if ( false !== strpos( $lower, 'suter' ) ) { $floorNum = -1; }
					if ( null === $floorNum && preg_match( '/-?\d+/', $val, $m ) ) { $floorNum = (int) $m[0]; }
					if ( null !== $floorNum ) { $data['floor'] = (string) $floorNum; }
					continue;
				}
			}
		}
        // Collect image URLs using selectors that may specify different attributes
        $imgSelectors = $this->selectors_to_array( $dsel['images'] ?? '.gallery img@src, .gallery img@data-src, .gallery source@srcset' );
        $imgUrls = [];
        foreach ( $imgSelectors as $imgSel ) {
            list( $cssSel, $attrName ) = $this->split_selector_attr( $imgSel, 'src' );
            $imgNodes = Html::query_all( $html, $cssSel );
            if ( empty( $imgNodes ) ) { continue; }
            foreach ( $imgNodes as $imgNode ) {
                $raw = Html::attr( $imgNode, $attrName );
                if ( '' === trim( $raw ) ) { continue; }
                if ( strtolower( $attrName ) === 'srcset' ) {
                    // Take the first candidate URL from srcset
                    $candidates = array_map( 'trim', explode( ',', $raw ) );
                    foreach ( $candidates as $cand ) {
                        $u = trim( preg_split( '/\s+/', $cand )[0] ?? '' );
                        if ( $u ) { $imgUrls[] = $u; }
                    }
                } else {
                    $imgUrls[] = $raw;
                }
            }
        }
        $data['images'] = array_values( array_unique( array_filter( $imgUrls ) ) );
		return $data;
	}

	private function extract_currency_from_jsonld( string $html ): string {
		$scriptNodes = Html::query_all( $html, 'script' );
		foreach ( $scriptNodes as $sn ) {
			$type = strtolower( Html::attr( $sn, 'type' ) );
			if ( false === strpos( $type, 'ld+json' ) ) { continue; }
			$json = trim( Html::text( $sn ) );
			if ( '' === $json ) { continue; }
			$decoded = json_decode( $json, true );
			if ( null === $decoded ) { continue; }
			$found = $this->deep_find_key_string( $decoded, 'priceCurrency' );
			if ( is_string( $found ) && '' !== trim( $found ) ) {
				$code = strtoupper( trim( $found ) );
				// Normalize common labels to ISO codes
				if ( 'KČ' === $code ) { $code = 'CZK'; }
				return $code;
			}
		}
		return '';
	}

	private function deep_find_key_string( $node, string $key ) {
		if ( is_array( $node ) ) {
			if ( array_key_exists( $key, $node ) && is_string( $node[ $key ] ) ) { return $node[ $key ]; }
			foreach ( $node as $child ) {
				$found = $this->deep_find_key_string( $child, $key );
				if ( is_string( $found ) && '' !== $found ) { return $found; }
			}
		} elseif ( is_object( $node ) ) {
			return $this->deep_find_key_string( (array) $node, $key );
		}
		return '';
	}

	private function extract_coordinates( string $html ): array {
		$lat = null; $lng = null;
		// 1) JSON-LD scripts
		$scriptNodes = Html::query_all( $html, 'script' );
		foreach ( $scriptNodes as $sn ) {
			$type = strtolower( Html::attr( $sn, 'type' ) );
			if ( false === strpos( $type, 'ld+json' ) ) { continue; }
			$json = trim( Html::text( $sn ) );
			if ( '' === $json ) { continue; }
			$decoded = json_decode( $json, true );
			if ( null === $decoded ) { continue; }
			$found = $this->deep_find_coordinates( $decoded );
			if ( $found ) { $lat = $found['lat']; $lng = $found['lng']; break; }
		}
		// 2) Meta tags like og:latitude, place:location:latitude
		if ( null === $lat || null === $lng ) {
			$metas = Html::query_all( $html, 'meta' );
			$want = [ 'og:latitude', 'og:longitude', 'place:location:latitude', 'place:location:longitude' ];
			$vals = [];
			foreach ( $metas as $meta ) {
				$name = strtolower( Html::attr( $meta, 'property' ) ?: Html::attr( $meta, 'name' ) );
				if ( in_array( $name, $want, true ) ) {
					$vals[ $name ] = Html::attr( $meta, 'content' );
				}
			}
			if ( isset( $vals['og:latitude'], $vals['og:longitude'] ) ) {
				$lat = $this->to_float( $vals['og:latitude'] );
				$lng = $this->to_float( $vals['og:longitude'] );
			}
			if ( ( null === $lat || null === $lng ) && isset( $vals['place:location:latitude'], $vals['place:location:longitude'] ) ) {
				$lat = $this->to_float( $vals['place:location:latitude'] );
				$lng = $this->to_float( $vals['place:location:longitude'] );
			}
		}
		// 3) Data attributes on likely map containers
		if ( null === $lat || null === $lng ) {
			$candidates = array_merge( Html::query_all( $html, 'div' ), Html::query_all( $html, 'section' ), Html::query_all( $html, 'span' ), Html::query_all( $html, 'input' ) );
			foreach ( $candidates as $el ) {
				$latRaw = Html::attr( $el, 'data-lat' ) ?: Html::attr( $el, 'data-latitude' ) ?: Html::attr( $el, 'data-geo-lat' );
				$lngRaw = Html::attr( $el, 'data-lng' ) ?: Html::attr( $el, 'data-long' ) ?: Html::attr( $el, 'data-longitude' ) ?: Html::attr( $el, 'data-geo-lng' ) ?: Html::attr( $el, 'data-geo-lon' );
				if ( '' === trim( (string) $latRaw ) ) { $latRaw = Html::attr( $el, 'data-coord-lat' ); }
				if ( '' === trim( (string) $lngRaw ) ) { $lngRaw = Html::attr( $el, 'data-coord-lng' ); }
				if ( '' !== trim( $latRaw ) && '' !== trim( $lngRaw ) ) {
					$lat = $this->to_float( $latRaw );
					$lng = $this->to_float( $lngRaw );
					break;
				}
			}
		}
		// 4) Links to maps with q=lat,lng
		if ( null === $lat || null === $lng ) {
			$links = Html::query_all( $html, 'a' );
			foreach ( $links as $a ) {
				$href = Html::attr( $a, 'href' );
				if ( '' === $href ) { continue; }
				if ( false === strpos( $href, 'map' ) && false === strpos( $href, 'google' ) ) { continue; }
				if ( preg_match( '/([\-\+]?\d{1,2}\.\d+)\s*,\s*([\-\+]?\d{1,3}\.\d+)/', $href, $m ) ) {
					$lat = (float) $m[1];
					$lng = (float) $m[2];
					break;
				}
			}
		}
		// 4b) Iframes with Google Maps embed URL containing q=lat,lng
		if ( null === $lat || null === $lng ) {
			$iframes = Html::query_all( $html, 'iframe' );
			foreach ( $iframes as $frame ) {
				$src = Html::attr( $frame, 'src' );
				if ( '' === $src ) { continue; }
				if ( false === strpos( $src, 'google.com/maps' ) ) { continue; }
				if ( preg_match( '/[?&]q=([\-\+]?\d{1,2}\.\d+)\s*,\s*([\-\+]?\d{1,3}\.\d+)/', $src, $m ) ) {
					$lat = (float) $m[1];
					$lng = (float) $m[2];
					break;
				}
			}
		}
		// 5) Fallback: any visible coordinates in text
		if ( null === $lat || null === $lng ) {
			if ( preg_match( '/([\-\+]?\d{1,2}\.\d{3,})[\s,]+([\-\+]?\d{1,3}\.\d{3,})/u', $html, $m ) ) {
				$lat = (float) $m[1];
				$lng = (float) $m[2];
			}
		}
		if ( null !== $lat && null !== $lng ) {
			return [ 'lat' => (float) $lat, 'lng' => (float) $lng ];
		}
		return [];
	}

	private function deep_find_coordinates( $node ) {
		if ( is_array( $node ) ) {
			// Direct GeoCoordinates
			if ( isset( $node['@type'] ) && is_string( $node['@type'] ) && false !== stripos( $node['@type'], 'GeoCoordinates' ) ) {
				$lat = $node['latitude'] ?? $node['lat'] ?? null;
				$lng = $node['longitude'] ?? $node['lng'] ?? $node['lon'] ?? null;
				if ( null !== $lat && null !== $lng ) { return [ 'lat' => $this->to_float( (string) $lat ), 'lng' => $this->to_float( (string) $lng ) ]; }
			}
			// Nested under "geo"
			if ( isset( $node['geo'] ) ) {
				$found = $this->deep_find_coordinates( $node['geo'] );
				if ( $found ) { return $found; }
			}
			// Generic keys
			if ( isset( $node['latitude'], $node['longitude'] ) ) {
				return [ 'lat' => $this->to_float( (string) $node['latitude'] ), 'lng' => $this->to_float( (string) $node['longitude'] ) ];
			}
			foreach ( $node as $child ) {
				$found = $this->deep_find_coordinates( $child );
				if ( $found ) { return $found; }
			}
		} elseif ( is_object( $node ) ) {
			return $this->deep_find_coordinates( (array) $node );
		}
		return [];
	}

	private function to_float( string $value ): float {
		$trim = trim( $value );
		$trim = str_replace( [','], ['.'], $trim );
		return (float) preg_replace( '/[^0-9\.+-]/', '', $trim );
	}

	private function first_text( string $html, $selectors ): string {
		$choices = $this->selectors_to_array( $selectors );
		foreach ( $choices as $sel ) {
			if ( ! $sel ) { continue; }
			$nodes = Html::query_all( $html, $sel );
			if ( $nodes ) { return Html::text( $nodes[0] ); }
		}
		return '';
	}

	private function extract_city( string $address ): string {
		$addr = mb_strtolower( $address );
		if ( false !== strpos( $addr, 'praha' ) ) { return 'Praha'; }
		if ( false !== strpos( $addr, 'hlavní město praha' ) ) { return 'Praha'; }
		return '';
	}

	private function normalize_price( string $raw ): string {
		$val = trim( (string) $raw );
		if ( '' === $val ) { return ''; }
		// Remove currency symbols and non-digits except spaces and separators
		$val = preg_replace( '/[\x{A0}\s]/u', '', $val ); // remove spaces incl. NBSP
		$val = str_replace( [ 'Kč', 'kc', 'CZK', 'czk' ], '', $val );
		$val = preg_replace( '/[^0-9]/', '', $val );
		if ( '' === $val ) { return ''; }
		// Return as plain digits string; keep as-is for CSV/meta; formatting is WP's job
		return $val;
	}

	private function is_prague( string $city ): bool {
		$city = mb_strtolower( trim( $city ) );
		return in_array( $city, [ 'praha', 'hlavní město praha' ], true );
	}

	private function get_base_url( string $url ): string {
		$parts = \wp_parse_url( $url );
		if ( ! $parts ) { return $url; }
		$scheme = $parts['scheme'] ?? 'https';
		$host = $parts['host'] ?? '';
		return $scheme . '://' . $host;
	}

	private function selectors_to_array( $selectors ): array {
		if ( is_array( $selectors ) ) { return array_values( array_filter( array_map( 'trim', $selectors ) ) ); }
		$sel = trim( (string) $selectors );
		if ( '' === $sel ) { return []; }
		if ( false !== strpos( $sel, ',' ) ) {
			$parts = array_map( 'trim', explode( ',', $sel ) );
			return array_values( array_filter( $parts, 'strlen' ) );
		}
		return [ $sel ];
	}

	private function first_nodes( string $html, array $selectors ): array {
		foreach ( $selectors as $sel ) {
			$nodes = Html::query_all( $html, $sel );
			if ( $nodes ) { return $nodes; }
		}
		return [];
	}

	private function normalize_ceske_url( string $url ): string {
		$u = trim( $url );
		if ( '' === $u ) { return $u; }
		$parts = \wp_parse_url( $u );
		if ( ! $parts ) { return $u; }
		$scheme = $parts['scheme'] ?? 'https';
		$host = $parts['host'] ?? '';
		$path = $parts['path'] ?? '/';
		$query = isset( $parts['query'] ) ? ('?' . $parts['query']) : '';
		$frag = isset( $parts['fragment'] ) ? ('#' . $parts['fragment']) : '';
		if ( false !== strpos( $host, 'ceskereality.cz' ) ) {
			$path = preg_replace( '#/byt(?=/)#', '/byty', $path );
			$path = preg_replace( '#/praha(?=/|$)#', '/hlavni-mesto-praha', $path );
			if ( substr( $path, -1 ) !== '/' ) { $path .= '/'; }
			$scheme = 'https';
		}
		return $scheme . '://' . $host . $path . $query . $frag;
	}

	private function retry_variant_url( string $url ): string {
		$parts = \wp_parse_url( $url );
		if ( ! $parts ) { return ''; }
		$host = $parts['host'] ?? '';
		$path = $parts['path'] ?? '/';
		if ( false === strpos( $host, 'ceskereality.cz' ) ) { return ''; }
		// Try adding trailing slash if missing
		if ( substr( $path, -1 ) !== '/' ) {
			$alt = $url . '/';
			return $alt;
		}
		// Try canonical Prague and plural segment even if missed by normalization
		$altPath = preg_replace( '#/byt(?=/)#', '/byty', $path );
		$altPath = preg_replace( '#/praha(?=/|$)#', '/hlavni-mesto-praha', $altPath );
		if ( $altPath !== $path ) {
			return $this->get_base_url( $url ) . $altPath;
		}
		return '';
	}

	private function resolve_url( string $baseUrl, string $href ): string {
		$href = trim( $href );
		if ( '' === $href ) { return ''; }
		if ( 0 === strpos( $href, 'http://' ) || 0 === strpos( $href, 'https://' ) ) { return $href; }
		$baseParts = \wp_parse_url( $baseUrl );
		if ( ! $baseParts ) { return $href; }
		$scheme = $baseParts['scheme'] ?? 'https';
		$host = $baseParts['host'] ?? '';
		$basePath = $baseParts['path'] ?? '/';
		if ( 0 === strpos( $href, '//' ) ) { return $scheme . ':' . $href; }
		if ( 0 === strpos( $href, '/' ) ) { return $scheme . '://' . $host . $href; }
		$dir = rtrim( preg_replace( '#/[^/]*$#', '/', $basePath ), '/' ) . '/';
		$path = $dir . $href;
		// Normalize ./ and ../ segments
		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		$out = [];
		foreach ( $segments as $seg ) {
			if ( $seg === '.' ) { continue; }
			if ( $seg === '..' ) { array_pop( $out ); continue; }
			$out[] = $seg;
		}
		$normalizedPath = '/' . implode( '/', $out );
		return $scheme . '://' . $host . $normalizedPath;
	}

	private function looks_like_detail_url( string $href ): bool {
		$href = trim( $href );
		if ( '' === $href ) { return false; }
		// Accept relative or absolute URLs that resemble listing detail pages
		// Common patterns on ceskereality: /prodej/.../*.html or /pronajem/.../*.html
		if ( preg_match( '#/(prodej|pronajem)/[^\s]+\.html#i', $href ) ) { return true; }
		// Also accept any URL ending with an id-like slug *.html
		if ( preg_match( '#/[^/]+\.html(?:[?#].*)?$#i', $href ) ) { return true; }
		return false;
	}

	private function extract_type_from_url( string $url ): array {
		$parts = \wp_parse_url( $url );
		if ( ! $parts ) { return []; }
		$path = trim( (string) ( $parts['path'] ?? '' ) );
		if ( '' === $path ) { return []; }
		$segs = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		$result = [];
		if ( empty( $segs ) ) { return $result; }
		// Action
		$action = strtolower( $segs[0] );
		if ( in_array( $action, [ 'prodej', 'pronajem', 'aukce', 'drazba' ], true ) ) {
			$result['action'] = $action;
		}
		// Category and subcategory (best-effort heuristics)
		// Common: /prodej/byty/... or /prodej/komercni-prostory/hotely/...
		$startIdx = isset( $result['action'] ) ? 1 : 0;
		if ( isset( $segs[ $startIdx ] ) ) {
			$cat = strtolower( $segs[ $startIdx ] );
			$result['category'] = $cat;
			if ( isset( $segs[ $startIdx + 1 ] ) ) {
				$sub = strtolower( $segs[ $startIdx + 1 ] );
				// Skip obvious locality segments like obec-*, okres-*
				if ( 0 !== strpos( $sub, 'obec-' ) && 0 !== strpos( $sub, 'okres-' ) && false === strpos( $sub, 'hlavni-mesto-praha' ) ) {
					$result['subcategory'] = $sub;
				}
			}
		}
		return $result;
	}
}


