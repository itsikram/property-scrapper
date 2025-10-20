<?php
namespace Realt\PropertyScrapper\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {
	private $file;

	public function __construct() {
		$upload_dir = \wp_upload_dir();
		$dir = \trailingslashit( $upload_dir['basedir'] ) . 'property-scrapper/logs';
		if ( ! \file_exists( $dir ) ) {
			\wp_mkdir_p( $dir );
		}
		$this->file = \trailingslashit( $dir ) . 'import-' . date( 'Y-m-d' ) . '.log';
	}

	public function log_info( string $event, array $context ) { $this->write( 'INFO', $event, $context ); }
	public function log_warn( string $event, array $context ) { $this->write( 'WARN', $event, $context ); }
	public function log_error( string $event, array $context ) { $this->write( 'ERROR', $event, $context ); }

	private function write( string $level, string $event, array $context ) {
        $line = sprintf( "%s\t%s\t%s\t%s\n", date( 'c' ), $level, $event, \wp_json_encode( $context ) );
        $dir = \dirname( $this->file );
        if ( ! \file_exists( $dir ) ) {
            \wp_mkdir_p( $dir );
        }
        $ok = @\file_put_contents( $this->file, $line, FILE_APPEND );
        if ( false === $ok ) {
            // Fallback: mirror to PHP error_log so we still see logs if FS is blocked
            \error_log( '[PropertyScrapper] ' . trim( $line ) );
        }
	}

	/**
	 * Save a pretty-printed JSON snapshot of a scraped property into the logs directory.
	 * Returns the absolute file path on success, or null on failure.
	 */
	public function save_json_item( array $item ) {
		$dir = \dirname( $this->file );
		if ( ! \file_exists( $dir ) ) { \wp_mkdir_p( $dir ); }
		$timestamp = gmdate( 'Ymd-His' );
		$basis = '';
		if ( ! empty( $item['external_id'] ) ) { $basis = (string) $item['external_id']; }
		elseif ( ! empty( $item['source_url'] ) ) { $basis = (string) $item['source_url']; }
		else { $basis = (string) ( $item['title'] ?? '' ); }
		$hash = substr( md5( $basis . microtime( true ) ), 0, 10 );
		$path = \trailingslashit( $dir ) . 'item-' . $timestamp . '-' . $hash . '.json';
		$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		$payload = \wp_json_encode( $item, $jsonFlags );
		$ok = @\file_put_contents( $path, $payload );
		if ( false === $ok ) {
			\error_log( '[PropertyScrapper] Failed to write JSON item to ' . $path );
			return null;
		}
		return $path;
	}
}


