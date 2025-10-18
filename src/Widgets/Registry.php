<?php
namespace Realt\PropertyScrapper\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Registry {
	public function init() {
		add_action( 'widgets_init', [ $this, 'register_widgets' ] );
	}

	public function register_widgets() {
		register_widget( MapWidget::class );
		register_widget( AboutAreaWidget::class );
		register_widget( GalleryWidget::class );
		register_widget( LatestPostsWidget::class );
	}
}


