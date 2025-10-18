<?php
namespace Realt\PropertyScrapper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {
	public function init() {
		\add_action( 'admin_menu', [ $this, 'register_menu' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Actions
		\add_action( 'admin_post_realt_ps_run_import', [ $this, 'handle_run_import' ] );
		\add_action( 'admin_post_realt_ps_reassign', [ $this, 'handle_reassign' ] );
		\add_action( 'admin_post_realt_ps_preview_scrape', [ $this, 'handle_preview_scrape' ] );
		\add_action( 'admin_post_realt_ps_fetch_html', [ $this, 'handle_fetch_html' ] );
		\add_action( 'admin_post_realt_ps_download_csv', [ $this, 'handle_download_csv' ] );

		// Settings (register on admin_init to ensure Settings API is loaded)
		$settings = new Settings();
		\add_action( 'admin_init', [ $settings, 'register' ] );

		// When settings are saved, reschedule if needed
		\add_action( 'update_option_realt_ps_import', function( $old, $new ) {
			\Realt\PropertyScrapper\Cron\Scheduler::activate();
		}, 10, 2 );
	}

	public function register_menu() {
		\add_menu_page(
			\__( 'Property Scrapper', 'realt-ps' ),
			\__( 'Property Scrapper', 'realt-ps' ),
			'manage_options',
			'realt-ps',
			[ $this, 'render_settings' ],
			'dashicons-admin-multisite',
			58
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === \strpos( $hook, 'realt-ps' ) ) {
			return;
		}
		\wp_enqueue_style( 'realt-ps-admin', REALT_PS_URL . 'assets/admin.css', [], REALT_PS_VERSION );
	}

	public function render_settings() {
		$tab = isset( $_GET['tab'] ) ? \sanitize_key( $_GET['tab'] ) : 'import';
		$tabs = [
			'import' => __( 'Import', 'realt-ps' ),
			'geocoding' => __( 'Geocoding', 'realt-ps' ),
			'scraping' => __( 'Scraping', 'realt-ps' ),
			'logs' => __( 'Logs', 'realt-ps' ),
			'docs' => __( 'Docs', 'realt-ps' ),
			'tools' => __( 'Tools', 'realt-ps' ),
		];
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Property Scrapper', 'realt-ps' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo \esc_url( \admin_url( 'admin.php?page=realt-ps&tab=' . $key ) ); ?>"><?php echo \esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="options.php">
				<?php
			switch ( $tab ) {
					case 'import':
						\settings_fields( 'realt_ps_import' );
						\do_settings_sections( 'realt_ps_import' );
						break;
					case 'geocoding':
						\settings_fields( 'realt_ps_geocoding' );
						\do_settings_sections( 'realt_ps_geocoding' );
						break;
					case 'scraping':
						\settings_fields( 'realt_ps_scraping' );
						\do_settings_sections( 'realt_ps_scraping' );
						break;
					case 'logs':
						\settings_fields( 'realt_ps_logs' );
						\do_settings_sections( 'realt_ps_logs' );
						break;
					case 'docs':
						\settings_fields( 'realt_ps_docs' );
						\do_settings_sections( 'realt_ps_docs' );
						break;
					case 'tools':
						\settings_fields( 'realt_ps_tools' );
						\do_settings_sections( 'realt_ps_tools' );
						break;
				}
				\submit_button();
				?>
			</form>

			<?php if ( \in_array( $tab, [ 'import', 'tools' ], true ) ) : ?>
				<hr />
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
					<?php \wp_nonce_field( 'realt_ps_run_import' ); ?>
					<input type="hidden" name="action" value="realt_ps_run_import" />
					<?php \submit_button( \__( 'Run Now', 'realt-ps' ), 'secondary' ); ?>
				</form>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
					<?php \wp_nonce_field( 'realt_ps_preview_scrape' ); ?>
					<input type="hidden" name="action" value="realt_ps_preview_scrape" />
					<?php \submit_button( \__( 'Preview Scrape (list only)', 'realt-ps' ), 'secondary' ); ?>
				</form>
				<?php $latest = \get_option( 'realt_ps_latest_csv' ); if ( $latest && ! empty( $latest['url'] ) ) : ?>
					<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
						<?php \wp_nonce_field( 'realt_ps_download_csv' ); ?>
						<input type="hidden" name="action" value="realt_ps_download_csv" />
						<?php \submit_button( \__( 'Download Latest CSV', 'realt-ps' ), 'secondary' ); ?>
						<p class="description" style="margin-top:6px;"><?php echo \esc_html( sprintf( __( 'Rows: %d — Generated: %s', 'realt-ps' ), (int) ( $latest['count'] ?? 0 ), isset( $latest['time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $latest['time'] ) : '' ) ); ?></p>
					</form>
				<?php endif; ?>
				<?php $last = \get_option( 'realt_ps_last_run' ); if ( $last ) : ?>
					<p><strong><?php \esc_html_e( 'Last Run:', 'realt-ps' ); ?></strong> <?php echo \esc_html( $last ); ?></p>
				<?php endif; ?>
				<?php $log = \Realt\PropertyScrapper\Utils\AdminLogView::tail(); if ( $log ) : ?>
					<h3><?php \esc_html_e( 'Recent Log', 'realt-ps' ); ?></h3>
					<pre style="max-height:240px;overflow:auto;background:#f7f7f7;padding:10px;border:1px solid #ddd;"><?php echo \esc_html( $log ); ?></pre>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_run_import() {
		\check_admin_referer( 'realt_ps_run_import' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions', 'realt-ps' ) );
		}
		\do_action( 'realt_ps/run_now' );
		\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=import&run=1' ) );
		exit;
	}

	public function handle_reassign() {
		\check_admin_referer( 'realt_ps_reassign' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions', 'realt-ps' ) );
		}
		\do_action( 'realt_ps/reassign' );
		\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=tools&reassigned=1' ) );
		exit;
	}

	public function handle_preview_scrape() {
		\check_admin_referer( 'realt_ps_preview_scrape' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions', 'realt-ps' ) );
		}
		$result = ( new \Realt\PropertyScrapper\Scraper\CeskeRealityScraper() )->preview();
		\set_transient( 'realt_ps_preview', $result, 60 );
		\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=scraping#preview' ) );
		exit;
	}

	public function handle_fetch_html() {
		\check_admin_referer( 'realt_ps_fetch_html' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions', 'realt-ps' ) );
		}
		$url = isset( $_POST['url'] ) ? trim( (string) $_POST['url'] ) : '';
		if ( '' === $url ) {
			\set_transient( 'realt_ps_fetch_html_result', [ 'ok' => false, 'error' => 'Missing URL' ], 60 );
			\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=scraping' ) );
			exit;
		}
		$opts = \get_option( 'realt_ps_scraping', [ 'rate_limit' => 10 ] );
		$rate = (int) ( $opts['rate_limit'] ?? 10 );
		$timeout = max( 5, min( 20, (int) ( $opts['http_timeout'] ?? 12 ) ) );
		$retries = max( 0, min( 2, (int) ( $opts['http_retries'] ?? 1 ) ) );
		$limiter = new \Realt\PropertyScrapper\Utils\RateLimiter( $rate );
		$client = new \Realt\PropertyScrapper\Utils\HttpClient( $limiter, '', $timeout, $retries );
		$resp = $client->get( $url );
		$result = [ 'url' => $url ] + $resp;
		\set_transient( 'realt_ps_fetch_html_result', $result, 60 );
		\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=scraping&realt_ps_url=' . rawurlencode( $url ) . '#realt_ps_scraping_fetch' ) );
		exit;
	}

	public function handle_download_csv() {
		\check_admin_referer( 'realt_ps_download_csv' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions', 'realt-ps' ) );
		}
		$latest = \get_option( 'realt_ps_latest_csv' );
		$path = is_array( $latest ) ? ( $latest['path'] ?? '' ) : '';
		if ( ! $path || ! file_exists( $path ) ) {
			\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=import&csv=missing' ) );
			exit;
		}
		\nocache_headers();
		\header( 'Content-Type: text/csv; charset=UTF-8' );
		\header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		\header( 'Content-Length: ' . filesize( $path ) );
		@\readfile( $path );
		exit;
	}
}


