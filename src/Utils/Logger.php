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
}


