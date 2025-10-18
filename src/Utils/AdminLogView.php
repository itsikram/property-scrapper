<?php
namespace Realt\PropertyScrapper\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminLogView {
	public static function tail( int $lines = 50 ): string {
		$upload_dir = \wp_upload_dir();
		$path = \trailingslashit( $upload_dir['basedir'] ) . 'property-scrapper/logs/import-' . date( 'Y-m-d' ) . '.log';
		if ( ! \file_exists( $path ) ) { return ''; }
		$fp = \fopen( $path, 'r' );
		if ( ! $fp ) { return ''; }
		$buffer = '';
		$pos = -1; $lineCount = 0;
		$fstat = \fstat( $fp );
		$size = $fstat['size'] ?? 0;
		if ( 0 === $size ) { \fclose( $fp ); return ''; }
		for ( $pos = -1; $lineCount < $lines && -$pos <= $size; $pos-- ) {
			\fseek( $fp, $pos, SEEK_END );
			$char = \fgetc( $fp );
			$buffer = $char . $buffer;
			if ( "\n" === $char ) { $lineCount++; }
		}
		\fclose( $fp );
		return $buffer;
	}
}


