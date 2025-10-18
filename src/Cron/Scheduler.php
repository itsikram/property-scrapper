<?php
namespace Realt\PropertyScrapper\Cron;

use Realt\PropertyScrapper\Import\Importer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scheduler {
	const HOOK = 'realt_ps/sync';

	public static function init() {
		\add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
		\add_action( self::HOOK, [ __CLASS__, 'run_scheduled' ] );
		\add_action( 'realt_ps/run_now', [ __CLASS__, 'run_now' ] );
	}

	public static function activate() {
		self::init();
		self::schedule_event();
	}

	public static function deactivate() {
		\wp_clear_scheduled_hook( self::HOOK );
	}

	public static function add_schedule( $schedules ) {
		$opts = \get_option( 'realt_ps_import', [ 'cron_interval' => 4 ] );
		$hours = max( 2, min( 24, (int) ( $opts['cron_interval'] ?? 4 ) ) );
		$interval = $hours * HOUR_IN_SECONDS;
		$schedules['realt_ps_interval'] = [
			'interval' => $interval,
			'display' => sprintf( __( 'Every %d hours (Property Scrapper)', 'realt-ps' ), $hours ),
		];
		return $schedules;
	}

	private static function schedule_event() {
		$opts = \get_option( 'realt_ps_import', [ 'auto_enabled' => 1 ] );
		$enabled = (int) ( $opts['auto_enabled'] ?? 1 );
		$scheduled = (bool) \wp_next_scheduled( self::HOOK );
		if ( $enabled ) {
			if ( ! $scheduled ) {
				\wp_schedule_event( time() + 60, 'realt_ps_interval', self::HOOK );
			}
		} else {
			if ( $scheduled ) {
				\wp_clear_scheduled_hook( self::HOOK );
			}
		}
	}

	public static function run_scheduled() {
		$opts = \get_option( 'realt_ps_import', [ 'mode' => 'scraping' ] );
		$mode = $opts['mode'] ?? 'scraping';
		( new Importer() )->run( $mode );
		\update_option( 'realt_ps_last_run', \current_time( 'mysql' ) );
	}

	public static function run_now() {
		self::run_scheduled();
	}
}


