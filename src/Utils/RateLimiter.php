<?php
namespace Realt\PropertyScrapper\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RateLimiter {
	private $requestsPerMinute;
	private $lastTick;
	private $allowance;

	public function __construct( int $requestsPerMinute ) {
		$this->requestsPerMinute = max( 1, $requestsPerMinute );
		$this->lastTick = microtime( true );
		$this->allowance = $this->requestsPerMinute;
	}

	public function wait() {
		$now = microtime( true );
		$time_passed = $now - $this->lastTick;
		$this->lastTick = $now;
		$this->allowance += $time_passed * ($this->requestsPerMinute / 60.0);
		if ( $this->allowance > $this->requestsPerMinute ) {
			$this->allowance = $this->requestsPerMinute;
		}
		if ( $this->allowance < 1.0 ) {
			$wait = (1.0 - $this->allowance) * (60.0 / $this->requestsPerMinute);
			usleep( (int) max( 0, $wait * 1e6 ) );
			$this->allowance = 0.0;
		} else {
			$this->allowance -= 1.0;
		}
	}
}


