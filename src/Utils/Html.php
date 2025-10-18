<?php
namespace Realt\PropertyScrapper\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Html {
	public static function query_all( string $html, string $selector ): array {
		if ( '' === $html ) { return []; }
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $dom );
		$xpathSelector = self::to_xpath( $selector );
		$nodes = @$xpath->query( $xpathSelector );
		$result = [];
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$result[] = $node;
			}
		}
		return $result;
	}

	public static function text( \DOMNode $node ): string {
		return trim( $node->textContent ?? '' );
	}

	public static function attr( \DOMNode $node, string $name ): string {
		if ( ! $node instanceof \DOMElement ) { return ''; }
		return trim( (string) $node->getAttribute( $name ) );
	}

	// Very small CSS to XPath converter (supports simple selectors and @attr syntax)
	public static function to_xpath( string $selector ): string {
		// @attr extraction like ".link@href"
		$attr = '';
		if ( false !== strpos( $selector, '@' ) ) {
			list( $selector, $attr ) = explode( '@', $selector, 2 );
		}
		$selector = trim( $selector );
		if ( '' === $selector ) { return '//*'; }
		$parts = preg_split( '/\s+>\s+|\s+/', $selector );
		$xpath = './/';
		$first = true;
		foreach ( $parts as $part ) {
			if ( '' === $part ) { continue; }
			$axis = $first ? '' : '//';
			$first = false;
			$tag = '*';
			$predicates = [];
			if ( preg_match( '/^([a-zA-Z0-9_-]+)?(#[a-zA-Z0-9_-]+)?((\.[a-zA-Z0-9_-]+)*)$/', $part, $m ) ) {
				if ( ! empty( $m[1] ) ) { $tag = $m[1]; }
				if ( ! empty( $m[2] ) ) { $predicates[] = sprintf( "@id='%s'", substr( $m[2], 1 ) ); }
				if ( ! empty( $m[3] ) ) {
					$classes = array_filter( array_map( function( $c ){ return trim( $c, '.' ); }, explode( '.', $m[3] ) ) );
					foreach ( $classes as $class ) {
						$predicates[] = sprintf( "contains(concat(' ', normalize-space(@class), ' '), ' %s ')", $class );
					}
				}
			}
			$xpath .= $axis . $tag;
			if ( $predicates ) {
				$xpath .= '[' . implode( ' and ', $predicates ) . ']';
			}
		}
		if ( $attr ) {
			// Select element nodes; attribute will be handled by caller via Html::attr
		}
		return $xpath;
	}
}


