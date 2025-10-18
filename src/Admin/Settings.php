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
		\add_settings_field( 'mode', \__( 'Mode', 'realt-ps' ), [ $this, 'field_mode' ], 'realt_ps_import', 'realt_ps_import_main' );

		// Geocoding Tab
		\register_setting( 'realt_ps_geocoding', 'realt_ps_geocoding', [ $this, 'sanitize_geocoding' ] );
		\add_settings_section( 'realt_ps_geocoding_main', \__( 'Geocoding', 'realt-ps' ), '__return_false', 'realt_ps_geocoding' );
		\add_settings_field( 'mapycz_api_key', \__( 'Mapy.cz API Key', 'realt-ps' ), [ $this, 'field_mapycz_api_key' ], 'realt_ps_geocoding', 'realt_ps_geocoding_main' );

		// Scraping Tab
		\register_setting( 'realt_ps_scraping', 'realt_ps_scraping', [ $this, 'sanitize_scraping' ] );
		\add_settings_section( 'realt_ps_scraping_main', \__( 'Scraping', 'realt-ps' ), '__return_false', 'realt_ps_scraping' );
		\add_settings_field( 'rate_limit', \__( 'Rate Limit (req/min)', 'realt-ps' ), [ $this, 'field_rate_limit' ], 'realt_ps_scraping', 'realt_ps_scraping_main' );
		\add_settings_field( 'selectors', \__( 'Selectors Config', 'realt-ps' ), [ $this, 'field_selectors' ], 'realt_ps_scraping', 'realt_ps_scraping_main' );
		\add_settings_field( 'start_urls', \__( 'Start URLs (one per line)', 'realt-ps' ), [ $this, 'field_start_urls' ], 'realt_ps_scraping', 'realt_ps_scraping_main' );
		\add_settings_section( 'realt_ps_scraping_preview', \__( 'Preview', 'realt-ps' ), [ $this, 'section_preview' ], 'realt_ps_scraping' );
		\add_settings_section( 'realt_ps_scraping_fetch', \__( 'Fetch HTML (debug)', 'realt-ps' ), [ $this, 'section_fetch_html' ], 'realt_ps_scraping' );

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

	public function section_fetch_html() {
		$defaultUrl = 'https://www.ceskereality.cz/prodej/byty/hlavni-mesto-praha/';
		$last = \get_transient( 'realt_ps_fetch_html_result' );
		?>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
			<?php \wp_nonce_field( 'realt_ps_fetch_html' ); ?>
			<input type="hidden" name="action" value="realt_ps_fetch_html" />
			<p>
				<input type="url" name="url" class="regular-text code" style="width:100%;max-width:800px;" value="<?php echo \esc_attr( isset( $_GET['realt_ps_url'] ) ? (string) $_GET['realt_ps_url'] : $defaultUrl ); ?>" placeholder="https://example.com/" required />
			</p>
			<?php \submit_button( \__( 'Fetch HTML', 'realt-ps' ), 'secondary' ); ?>
		</form>
		<?php if ( $last ) : ?>
			<div style="margin-top:10px;">
				<p><strong><?php echo \esc_html( $last['ok'] ? 'Status: OK' : 'Status: FAIL' ); ?></strong> <?php echo isset( $last['status'] ) ? '(' . (int) $last['status'] . ')' : ''; ?> — <?php echo \esc_html( $last['url'] ?? '' ); ?></p>
				<?php if ( ! empty( $last['error'] ) ) : ?>
					<p style="color:#b00;"><?php echo \esc_html( $last['error'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $last['body'] ) ) : ?>
					<textarea readonly rows="12" style="width:100%;max-width:1000px;font-family:monospace;white-space:pre;"><?php echo \esc_textarea( $last['body'] ); ?></textarea>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	public function sanitize_import( $input ) {
		$clean = [];
		$clean['auto_enabled'] = isset( $input['auto_enabled'] ) ? 1 : 0;
		$clean['cron_interval'] = max( 2, min( 24, isset( $input['cron_interval'] ) ? (int) $input['cron_interval'] : 4 ) );
		$clean['mode'] = in_array( $input['mode'] ?? 'scraping', [ 'feed', 'scraping' ], true ) ? $input['mode'] : 'scraping';
		$clean['max_runtime'] = max( 30, min( 900, (int) ( $input['max_runtime'] ?? 300 ) ) );
		$clean['image_timeout'] = max( 5, min( 120, (int) ( $input['image_timeout'] ?? 25 ) ) );
		$clean['max_images'] = max( 1, min( 15, (int) ( $input['max_images'] ?? 6 ) ) );
		return $clean;
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
		return $clean;
	}

	public function sanitize_scraping( $input ) {
		$clean = [];
		$clean['rate_limit'] = max( 1, min( 60, isset( $input['rate_limit'] ) ? (int) $input['rate_limit'] : 10 ) );
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

	public function field_rate_limit() {
		$opts = \get_option( 'realt_ps_scraping', [ 'rate_limit' => 10 ] );
		?>
		<input type="number" min="1" max="60" name="realt_ps_scraping[rate_limit]" value="<?php echo \esc_attr( (int) ( $opts['rate_limit'] ?? 10 ) ); ?>" />
		<p class="description"><?php \esc_html_e( 'Requests per minute per domain.', 'realt-ps' ); ?></p>
		<?php
	}

	public function field_start_urls() {
		$opts = \get_option( 'realt_ps_scraping', [] );
		$val = (string) ( $opts['start_urls'] ?? '' );
		?>
		<textarea name="realt_ps_scraping[start_urls]" rows="5" class="large-text code" placeholder="https://www.ceskereality.cz/prodej/byty/hlavni-mesto-praha/\nhttps://www.ceskereality.cz/pronajem/byty/hlavni-mesto-praha/\n">
<?php echo \esc_textarea( $val ); ?></textarea>
		<p class="description"><?php \esc_html_e( 'Seed listing pages to crawl (Prague-only). One per line.', 'realt-ps' ); ?></p>
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


