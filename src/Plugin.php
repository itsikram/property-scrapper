<?php
namespace Realt\PropertyScrapper;

use Realt\PropertyScrapper\Admin\Admin;
use Realt\PropertyScrapper\Cron\Scheduler;
use Realt\PropertyScrapper\Locations\Locations;
use Realt\PropertyScrapper\Widgets\Registry as WidgetsRegistry;
use Realt\PropertyScrapper\Shortcodes\Registry as ShortcodesRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private static $instance;
	private $admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		// Core modules
		$this->admin = new Admin();
		$this->admin->init();

		// Scheduler hooks
		Scheduler::init();

		// Locations (tax meta, seeding tools)
		( new Locations() )->init();

		// Widgets registry
		( new WidgetsRegistry() )->init();

		// Shortcodes registry
		( new ShortcodesRegistry() )->init();
	}
}


