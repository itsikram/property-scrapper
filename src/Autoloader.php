<?php
namespace Realt\PropertyScrapper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {
	private static $prefixes = [];

	public static function register( $prefix, $base_dir ) {
		$prefix = trim( $prefix, '\\' ) . '\\';
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';
		self::$prefixes[ $prefix ] = $base_dir;
		spl_autoload_register( [ __CLASS__, 'load' ] );
	}

	public static function load( $class ) {
		foreach ( self::$prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );
			if ( 0 !== strncmp( $prefix, $class, $len ) ) {
				continue;
			}
			$relative_class = substr( $class, $len );
			$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
			if ( file_exists( $file ) ) {
				require $file;
				return true;
			}
		}
		return false;
	}
}


