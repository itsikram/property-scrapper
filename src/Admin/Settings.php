<?php
namespace Realt\PropertyScrapper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	public function register() {
		// Import Tab
		\register_setting( 'realt_ps_import', 'realt_ps_import', [ $this, 'sanitize_import' ] );
		\add_settings_section( 'realt_ps_import_main', \__( 'Import Settings', 'realt-ps' ), '__return_false', 'realt_ps_import' );
		\add_settings_field( 'auto_enabled', \__( 'Enable Auto Sync (WP-Cron)', 'realt-ps' ), [ $this, 'field_auto_enabled' ], 'realt_ps_import', 'realt_ps_import_main' );
		\add_settings_field( 'cron_interval', \__( 'Cron Interval (hours)', 'realt-ps' ), [ $this, 'field_cron_interval' ], 'realt_ps_import', 'realt_ps_import_main' );
		\add_settings_field( 'max_runtime', \__( 'Max Run Time (seconds)', 'realt-ps' ), [ $this, 'field_max_runtime' ], 'realt_ps_import', 'realt_ps_import_main' );
		\add_settings_field( 'image_timeout', \__( 'Image Timeout (seconds)', 'realt-ps' ), [ $this, 'field_image_timeout' ], 'realt_ps_import', 'realt_ps_import_main' );
		\add_settings_field( 'max_images', \__( 'Max Images Per Post', 'realt-ps' ), [ $this, 'field_max_images' ], 'realt_ps_import', 'realt_ps_import_main' );
		\add_settings_field( 'max_items', \__( 'Max Properties per Run', 'realt-ps' ), [ $this, 'field_max_items' ], 'realt_ps_import', 'realt_ps_import_main' );
		\add_settings_field( 'mode', \__( 'Mode', 'realt-ps' ), [ $this, 'field_mode' ], 'realt_ps_import', 'realt_ps_import_main' );
		\add_settings_field( 'debug_mode', \__( 'Debug mode (skip location matching)', 'realt-ps' ), [ $this, 'field_debug_mode' ], 'realt_ps_import', 'realt_ps_import_main' );

		// Geocoding Tab
		\register_setting( 'realt_ps_geocoding', 'realt_ps_geocoding', [ $this, 'sanitize_geocoding' ] );
		\add_settings_section( 'realt_ps_geocoding_main', \__( 'Geocoding', 'realt-ps' ), '__return_false', 'realt_ps_geocoding' );
		\add_settings_field( 'mapycz_api_key', \__( 'Mapy.cz API Key', 'realt-ps' ), [ $this, 'field_mapycz_api_key' ], 'realt_ps_geocoding', 'realt_ps_geocoding_main' );
		\add_settings_field( 'areas_geojson', \__( 'Areas GeoJSON', 'realt-ps' ), [ $this, 'field_areas_geojson' ], 'realt_ps_geocoding', 'realt_ps_geocoding_main' );
		\add_settings_field( 'street_map_csv', \__( 'Street→Area CSV', 'realt-ps' ), [ $this, 'field_street_map_csv' ], 'realt_ps_geocoding', 'realt_ps_geocoding_main' );

		// Scraping Tab
		\register_setting( 'realt_ps_scraping', 'realt_ps_scraping', [ $this, 'sanitize_scraping' ] );
		\add_settings_section( 'realt_ps_scraping_main', \__( 'Scraping', 'realt-ps' ), '__return_false', 'realt_ps_scraping' );
		\add_settings_field( 'rate_limit', \__( 'Rate Limit (req/min)', 'realt-ps' ), [ $this, 'field_rate_limit' ], 'realt_ps_scraping', 'realt_ps_scraping_main' );
		\add_settings_field( 'http_timeout', \__( 'HTTP Timeout (seconds)', 'realt-ps' ), [ $this, 'field_http_timeout' ], 'realt_ps_scraping', 'realt_ps_scraping_main' );
		\add_settings_field( 'http_retries', \__( 'HTTP Retries', 'realt-ps' ), [ $this, 'field_http_retries' ], 'realt_ps_scraping', 'realt_ps_scraping_main' );
		\add_settings_field( 'selectors', \__( 'Selectors Config', 'realt-ps' ), [ $this, 'field_selectors' ], 'realt_ps_scraping', 'realt_ps_scraping_main' );
		\add_settings_field( 'start_urls', \__( 'Start URLs (one per line)', 'realt-ps' ), [ $this, 'field_start_urls' ], 'realt_ps_scraping', 'realt_ps_scraping_main' );
		\add_settings_section( 'realt_ps_scraping_preview', \__( 'Preview', 'realt-ps' ), [ $this, 'section_preview' ], 'realt_ps_scraping' );


		// Logs Tab
		\register_setting( 'realt_ps_logs', 'realt_ps_logs', '__return_false' );
		\add_settings_section( 'realt_ps_logs_main', \__( 'Logs', 'realt-ps' ), [ $this, 'section_logs' ], 'realt_ps_logs' );

		// Docs Tab
		\register_setting( 'realt_ps_docs', 'realt_ps_docs', '__return_false' );
		\add_settings_section( 'realt_ps_docs_main', \__( 'Documentation', 'realt-ps' ), [ $this, 'section_docs' ], 'realt_ps_docs' );

		// Tools Tab
		\register_setting( 'realt_ps_tools', 'realt_ps_tools', '__return_false' );
		\add_settings_section( 'realt_ps_tools_main', \__( 'Tools', 'realt-ps' ), [ $this, 'section_tools' ], 'realt_ps_tools' );
	}

	public function section_preview() {
		$res = \get_transient( 'realt_ps_preview' );
		if ( ! $res ) { echo '<p>' . \esc_html__( 'Use Preview Scrape to test selectors.', 'realt-ps' ) . '</p>'; return; }
		if ( empty( $res['ok'] ) ) {
			echo '<p style="color:#b00">' . \esc_html( $res['error'] ?? 'Unknown error' ) . '</p>';
			return;
		}
		echo '<p>' . \esc_html( sprintf( __( 'Found %d list items. Showing up to 5 samples:', 'realt-ps' ), (int) ( $res['count'] ?? 0 ) ) ) . '</p>';
		echo '<ol id="preview">';
		foreach ( (array) ( $res['samples'] ?? [] ) as $s ) {
			echo '<li>' . \esc_html( ($s['title'] ?? '') . ' — ' . ($s['url'] ?? '') ) . '</li>';
		}
		echo '</ol>';
	}



	public function sanitize_import( $input ) {
		$clean = [];
		$clean['auto_enabled'] = isset( $input['auto_enabled'] ) ? 1 : 0;
		$clean['cron_interval'] = max( 2, min( 24, isset( $input['cron_interval'] ) ? (int) $input['cron_interval'] : 4 ) );
		$clean['mode'] = in_array( $input['mode'] ?? 'scraping', [ 'feed', 'scraping' ], true ) ? $input['mode'] : 'scraping';
		$clean['max_runtime'] = max( 30, min( 900, (int) ( $input['max_runtime'] ?? 300 ) ) );
		$clean['image_timeout'] = max( 5, min( 120, (int) ( $input['image_timeout'] ?? 25 ) ) );
		$clean['max_images'] = max( 1, min( 15, (int) ( $input['max_images'] ?? 6 ) ) );
		$clean['max_items'] = max( 1, min( 200, isset( $input['max_items'] ) ? (int) $input['max_items'] : 5 ) );
		$clean['debug_mode'] = isset( $input['debug_mode'] ) ? 1 : 0;
		return $clean;
	}
	public function field_debug_mode() {
		$opts = \get_option( 'realt_ps_import', [ 'debug_mode' => 0 ] );
		$val = (int) ( $opts['debug_mode'] ?? 0 );
		?>
		<label>
			<input type="checkbox" name="realt_ps_import[debug_mode]" value="1" <?php checked( $val, 1 ); ?> />
			<?php \esc_html_e( 'When enabled, do not match/validate areas via Areas GeoJSON or Street→Area CSV during assignment.', 'realt-ps' ); ?>
		</label>
		<?php
	}
	public function field_auto_enabled() {
		$opts = \get_option( 'realt_ps_import', [ 'auto_enabled' => 1 ] );
		$val = (int) ( $opts['auto_enabled'] ?? 1 );
		?>
		<label>
			<input type="checkbox" name="realt_ps_import[auto_enabled]" value="1" <?php checked( $val, 1 ); ?> />
			<?php \esc_html_e( 'Automatically run sync on schedule', 'realt-ps' ); ?>
		</label>
		<?php
	}


	public function sanitize_geocoding( $input ) {
		$clean = [];
		$clean['mapycz_api_key'] = sanitize_text_field( $input['mapycz_api_key'] ?? '' );

		// Handle file uploads during the actual save request (options.php POST)
		$upload_dir = \wp_upload_dir();
		$base = \trailingslashit( $upload_dir['basedir'] ) . 'property-scrapper/geo/';
		if ( ! \file_exists( $base ) ) { \wp_mkdir_p( $base ); }

		// Areas GeoJSON
		if ( isset( $_FILES['realt_ps_areas_geojson'] ) && ! empty( $_FILES['realt_ps_areas_geojson']['tmp_name'] ) ) {
			$this->save_uploaded_file( $_FILES['realt_ps_areas_geojson'], $base . 'areas.geojson' );
		}
		// Street→Area CSV
		if ( isset( $_FILES['realt_ps_street_map_csv'] ) && ! empty( $_FILES['realt_ps_street_map_csv']['tmp_name'] ) ) {
			$this->save_uploaded_file( $_FILES['realt_ps_street_map_csv'], $base . 'street_map.csv' );
		}

		return $clean;
	}

	public function sanitize_scraping( $input ) {
		$clean = [];
		$clean['rate_limit'] = max( 1, min( 60, isset( $input['rate_limit'] ) ? (int) $input['rate_limit'] : 10 ) );
		$clean['http_timeout'] = max( 5, min( 30, isset( $input['http_timeout'] ) ? (int) $input['http_timeout'] : 12 ) );
		$clean['http_retries'] = max( 0, min( 3, isset( $input['http_retries'] ) ? (int) $input['http_retries'] : 2 ) );
		$clean['selectors'] = wp_kses_post( $input['selectors'] ?? '' );
		// Normalize and sanitize Start URLs (handle outdated ceskereality paths)
		$raw = (string) ( $input['start_urls'] ?? '' );
		$raw = sanitize_textarea_field( $raw );
		$lines = preg_split( '/\r?\n/', $raw );
		$normalized = [];
		foreach ( (array) $lines as $line ) {
			$u = trim( (string) $line );
			if ( ! $u ) { continue; }
			// If targeting ceskereality.cz, fix common legacy paths:
			if ( false !== strpos( $u, 'ceskereality.cz' ) ) {
				// Use plural segment and canonical Prague slug
				$u = preg_replace( '#/byt/#', '/byty/', $u );
				$u = preg_replace( '#/praha/#', '/hlavni-mesto-praha/', $u );
			}
			$normalized[] = $u;
		}
		$clean['start_urls'] = implode( "\n", $normalized );
		return $clean;
	}

	public function field_cron_interval() {
		$opts = \get_option( 'realt_ps_import', [ 'cron_interval' => 4 ] );
		?>
		<input type="number" min="2" max="24" name="realt_ps_import[cron_interval]" value="<?php echo \esc_attr( (int) ( $opts['cron_interval'] ?? 4 ) ); ?>" />
		<p class="description"><?php \esc_html_e( 'How often to run auto-sync (hours).', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_max_runtime() {
		$opts = \get_option( 'realt_ps_import', [ 'max_runtime' => 300 ] );
		?>
		<input type="number" min="30" max="900" name="realt_ps_import[max_runtime]" value="<?php echo \esc_attr( (int) ( $opts['max_runtime'] ?? 300 ) ); ?>" />
		<p class="description"><?php \esc_html_e( 'Hard cap for a single run to avoid timeouts.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_image_timeout() {
		$opts = \get_option( 'realt_ps_import', [ 'image_timeout' => 25 ] );
		?>
		<input type="number" min="5" max="120" name="realt_ps_import[image_timeout]" value="<?php echo \esc_attr( (int) ( $opts['image_timeout'] ?? 25 ) ); ?>" />
		<p class="description"><?php \esc_html_e( 'Per-image download timeout.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_max_images() {
		$opts = \get_option( 'realt_ps_import', [ 'max_images' => 6 ] );
		?>
		<input type="number" min="1" max="15" name="realt_ps_import[max_images]" value="<?php echo \esc_attr( (int) ( $opts['max_images'] ?? 6 ) ); ?>" />
		<p class="description"><?php \esc_html_e( 'Upper limit of images to sideload per post.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_mode() {
		$opts = \get_option( 'realt_ps_import', [ 'mode' => 'scraping' ] );
		$mode = $opts['mode'] ?? 'scraping';
		?>
		<select name="realt_ps_import[mode]">
			<option value="scraping" <?php \selected( $mode, 'scraping' ); ?>><?php \esc_html_e( 'Scraping', 'realt-ps' ); ?></option>
			<option value="feed" <?php \selected( $mode, 'feed' ); ?>><?php \esc_html_e( 'Feed (if available)', 'realt-ps' ); ?></option>
		</select>
		<?php
	}

	public function field_mapycz_api_key() {
		$opts = \get_option( 'realt_ps_geocoding', [] );
		?>
		<input type="text" class="regular-text" name="realt_ps_geocoding[mapycz_api_key]" value="<?php echo \esc_attr( $opts['mapycz_api_key'] ?? '' ); ?>" />
		<p class="description"><?php \esc_html_e( 'Enter your Mapy.cz API key.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_areas_geojson() {
		$upload_dir = \wp_upload_dir();
		$dest = \trailingslashit( $upload_dir['basedir'] ) . 'property-scrapper/geo/areas.geojson';
		$current = \file_exists( $dest ) ? $dest : ( REALT_PS_PATH . 'config/areas.geojson' );
		$url = \file_exists( $dest ) ? ( \trailingslashit( $upload_dir['baseurl'] ) . 'property-scrapper/geo/areas.geojson' ) : '';
		?>
		<p>
			<input type="file" name="realt_ps_areas_geojson" accept=".geojson,application/json" />
		</p>
		<?php if ( $url ) : ?>
			<p class="description"><?php echo \esc_html( $dest ); ?> — <a href="<?php echo \esc_url( $url ); ?>" target="_blank"><?php \esc_html_e( 'Download current', 'realt-ps' ); ?></a></p>
		<?php else : ?>
			<p class="description"><?php echo \esc_html( $current ); ?></p>
		<?php endif; ?>
		<?php
		// Handle upload when settings saved
		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] && isset( $_FILES['realt_ps_areas_geojson'] ) && ! empty( $_FILES['realt_ps_areas_geojson']['tmp_name'] ) ) {
			$this->save_uploaded_file( $_FILES['realt_ps_areas_geojson'], $dest );
		}
	}

	public function field_street_map_csv() {
		$upload_dir = \wp_upload_dir();
		$dest = \trailingslashit( $upload_dir['basedir'] ) . 'property-scrapper/geo/street_map.csv';
		$current = \file_exists( $dest ) ? $dest : ( REALT_PS_PATH . 'config/street_map.csv' );
		$url = \file_exists( $dest ) ? ( \trailingslashit( $upload_dir['baseurl'] ) . 'property-scrapper/geo/street_map.csv' ) : '';
		?>
		<p>
			<input type="file" name="realt_ps_street_map_csv" accept=".csv,text/csv" />
		</p>
		<?php if ( $url ) : ?>
			<p class="description"><?php echo \esc_html( $dest ); ?> — <a href="<?php echo \esc_url( $url ); ?>" target="_blank"><?php \esc_html_e( 'Download current', 'realt-ps' ); ?></a></p>
		<?php else : ?>
			<p class="description"><?php echo \esc_html( $current ); ?></p>
		<?php endif; ?>
		<?php
		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] && isset( $_FILES['realt_ps_street_map_csv'] ) && ! empty( $_FILES['realt_ps_street_map_csv']['tmp_name'] ) ) {
			$this->save_uploaded_file( $_FILES['realt_ps_street_map_csv'], $dest );
		}
	}

	private function save_uploaded_file( array $file, string $dest ): void {
		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return; }
		$dir = \dirname( $dest );
		if ( ! \file_exists( $dir ) ) { \wp_mkdir_p( $dir ); }
		@\move_uploaded_file( $file['tmp_name'], $dest );
	}

	public function field_rate_limit() {
		$opts = \get_option( 'realt_ps_scraping', [ 'rate_limit' => 10 ] );
		?>
		<input type="number" min="1" max="60" name="realt_ps_scraping[rate_limit]" value="<?php echo \esc_attr( (int) ( $opts['rate_limit'] ?? 10 ) ); ?>" />
		<p class="description"><?php \esc_html_e( 'Requests per minute per domain.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_http_timeout() {
		$opts = \get_option( 'realt_ps_scraping', [ 'http_timeout' => 12 ] );
		?>
		<input type="number" min="5" max="30" name="realt_ps_scraping[http_timeout]" value="<?php echo \esc_attr( (int) ( $opts['http_timeout'] ?? 12 ) ); ?>" />
		<p class="description"><?php \esc_html_e( 'Per-request timeout for list/detail pages.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_http_retries() {
		$opts = \get_option( 'realt_ps_scraping', [ 'http_retries' => 2 ] );
		?>
		<input type="number" min="0" max="3" name="realt_ps_scraping[http_retries]" value="<?php echo \esc_attr( (int) ( $opts['http_retries'] ?? 2 ) ); ?>" />
		<p class="description"><?php \esc_html_e( 'Number of retry attempts on failure.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_max_items() {
		$opts = \get_option( 'realt_ps_import', [ 'max_items' => 20 ] );
		?>
		<input type="number" min="1" max="200" name="realt_ps_import[max_items]" value="<?php echo \esc_attr( (int) ( $opts['max_items'] ?? 20 ) ); ?>" />
		<p class="description"><?php \esc_html_e( 'Upper cap of properties to fetch per run.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_start_urls() {
		$opts = \get_option( 'realt_ps_scraping', [] );
		$val = (string) ( $opts['start_urls'] ?? '' );
		?>
		<textarea name="realt_ps_scraping[start_urls]" rows="5" class="large-text code" placeholder="https://www.ceskereality.cz/prodej/byty/hlavni-mesto-praha/\nhttps://www.ceskereality.cz/pronajem/byty/hlavni-mesto-praha/\n">
<?php echo \esc_textarea( $val ); ?></textarea>
		<p class="description"><?php \esc_html_e( 'Seed listing pages to crawl. One per line.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_selectors() {
		$path = REALT_PS_PATH . 'config/selectors.json';
		$contents = \file_exists( $path ) ? \file_get_contents( $path ) : '';
		?>
		<textarea name="realt_ps_scraping[selectors]" rows="8" class="large-text code" placeholder="{\n  \"list\": {...}\n}"><?php echo \esc_textarea( $contents ); ?></textarea>
		<p class="description"><?php \esc_html_e( 'CSS/XPath selectors JSON. Saved to plugin config when you save.', 'realt-ps' ); ?></p>
		<?php
		// Save selectors.json when settings saved
		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] && isset( $_POST['realt_ps_scraping']['selectors'] ) ) {
			$raw = \wp_unslash( $_POST['realt_ps_scraping']['selectors'] );
			if ( $raw ) {
				\file_put_contents( $path, $raw );
			}
		}
	}

	public function section_logs() {
		$upload_dir = \wp_upload_dir();
		$logs_dir = \trailingslashit( $upload_dir['basedir'] ) . 'property-scrapper/logs';
		echo '<p>' . \esc_html( $logs_dir ) . '</p>';
	}

	public function section_docs() {
		$readme = REALT_PS_PATH . 'README.md';
		if ( \file_exists( $readme ) ) {
			echo '<div style="max-width:900px;">' . \wp_kses_post( \wpautop( \esc_html( \file_get_contents( $readme ) ) ) ) . '</div>';
		} else {
			echo '<p>' . \esc_html__( 'Documentation coming soon.', 'realt-ps' ) . '</p>';
		}
	}

	public function section_tools() {
		?>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
			<?php \wp_nonce_field( 'realt_ps_reassign' ); ?>
			<input type="hidden" name="action" value="realt_ps_reassign" />
			<?php \submit_button( \__( 'Reassign City/Area', 'realt-ps' ), 'secondary' ); ?>
		</form>
		<?php
	}
}


