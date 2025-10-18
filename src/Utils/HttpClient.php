<?php
namespace Realt\PropertyScrapper\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HttpClient {
	private $rateLimiter;
	private $userAgent;
	private $timeout;
	private $retries;

	public function __construct( RateLimiter $rateLimiter, string $userAgent = '' , int $timeout = 20, int $retries = 2 ) {
		$this->rateLimiter = $rateLimiter;
		$this->userAgent = $userAgent ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36';
		$this->timeout = $timeout;
		$this->retries = $retries;
	}

	public function get( string $url ): array {
		$attempt = 0;
		$last_error = '';
		while ( $attempt <= $this->retries ) {
			$this->rateLimiter->wait();
			$response = \wp_remote_get( $url, [
				'timeout' => $this->timeout,
				'user-agent' => $this->userAgent,
				'headers' => [
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9,cs;q=0.8',
				],
			] );
			if ( ! \is_wp_error( $response ) ) {
				$status = (int) \wp_remote_retrieve_response_code( $response );
				if ( $status >= 200 && $status < 300 ) {
					$body = (string) \wp_remote_retrieve_body( $response );
					return [ 'ok' => true, 'status' => $status, 'body' => $body ];
				}
				$last_error = 'HTTP ' . $status;
			} else {
				$last_error = $response->get_error_message();
			}
			$attempt++;
			// Exponential backoff
			\usleep( (int) ( ( 250000 ) * pow( 2, $attempt ) ) );
		}
		return [ 'ok' => false, 'status' => 0, 'error' => $last_error ];
	}
}


