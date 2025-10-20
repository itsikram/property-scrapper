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
		\add_action( 'admin_post_realt_ps_seed_locations', [ $this, 'handle_seed_locations' ] );
		\add_action( 'admin_post_realt_ps_generate_pages', [ $this, 'handle_generate_pages' ] );
		\add_action( 'admin_post_realt_ps_preview_scrape', [ $this, 'handle_preview_scrape' ] );
		// removed: fetch html debug handler
		\add_action( 'admin_post_realt_ps_download_csv', [ $this, 'handle_download_csv' ] );
		\add_action( 'admin_post_realt_ps_delete_terms', [ $this, 'handle_delete_terms' ] );

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
			'areas' => __( 'Areas', 'realt-ps' ),
		];
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Property Scrapper', 'realt-ps' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo \esc_url( \admin_url( 'admin.php?page=realt-ps&tab=' . $key ) ); ?>"><?php echo \esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'areas' === $tab ) : ?>
				<h2><?php echo \esc_html__( 'Areas', 'realt-ps' ); ?></h2>
				<?php
				$terms = \get_terms( [ 'taxonomy' => 'property_area', 'hide_empty' => false ] );
				if ( \is_wp_error( $terms ) ) {
					echo '<p class="description">' . \esc_html( $terms->get_error_message() ) . '</p>';
				} elseif ( empty( $terms ) ) {
					?><p class="description"><?php echo \esc_html__( 'No areas found.', 'realt-ps' ); ?></p><?php
				} else {
					?>
					<table class="widefat striped" style="max-width:900px;">
						<thead>
							<tr>
								<th style="width:50%;"><?php echo \esc_html__( 'Name', 'realt-ps' ); ?></th>
								<th style="width:40%;"><?php echo \esc_html__( 'Slug', 'realt-ps' ); ?></th>
								<th style="width:10%;text-align:right;">#</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $terms as $t ) : ?>
								<tr>
									<td><?php echo \esc_html( (string) $t->name ); ?></td>
									<td><code><?php echo \esc_html( (string) $t->slug ); ?></code></td>
									<td style="text-align:right;"><?php echo (int) $t->count; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description" style="margin-top:10px;">
						<?php echo \esc_html( sprintf( __( 'Total: %d areas', 'realt-ps' ), count( $terms ) ) ); ?>
					</p>
					<?php
				}
				?>
			</div>
			<?php return; endif; ?>

			<form method="post" action="options.php" enctype="multipart/form-data">
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
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
					<?php \wp_nonce_field( 'realt_ps_reassign' ); ?>
					<input type="hidden" name="action" value="realt_ps_reassign" />
					<?php \submit_button( \__( 'Reassign City/Area', 'realt-ps' ), 'secondary' ); ?>
				</form>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
					<?php \wp_nonce_field( 'realt_ps_seed_locations' ); ?>
					<input type="hidden" name="action" value="realt_ps_seed_locations" />
					<?php \submit_button( \__( 'Seed Sample Locations (10)', 'realt-ps' ), 'secondary' ); ?>
				</form>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
					<?php \wp_nonce_field( 'realt_ps_generate_pages' ); ?>
					<input type="hidden" name="action" value="realt_ps_generate_pages" />
					<?php \submit_button( \__( 'Generate Listing Pages for Locations', 'realt-ps' ), 'secondary' ); ?>
				</form>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
					<?php \wp_nonce_field( 'realt_ps_delete_terms' ); ?>
					<input type="hidden" name="action" value="realt_ps_delete_terms" />
					<?php \submit_button( \__( 'Delete All Property Terms (cities, areas, etc.)', 'realt-ps' ), 'delete' ); ?>
					<p class="description" style="margin-top:6px;">
						<?php echo \esc_html__( 'This will delete all terms for property-related taxonomies (cities, areas, country, status, label, categories, actions, features). This cannot be undone.', 'realt-ps' ); ?>
					</p>
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
		// Trigger a full reassignment via Assigner
		( new \Realt\PropertyScrapper\Locations\Assigner() )->reassign_all( 100 );
		\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=tools&reassigned=1' ) );
		exit;
	}

	public function handle_seed_locations() {
		\check_admin_referer( 'realt_ps_seed_locations' );
		if ( ! \current_user_can( 'manage_options' ) ) { \wp_die( \esc_html__( 'Insufficient permissions', 'realt-ps' ) ); }
		$locations = [
			[ 'city' => 'Praha 4', 'area' => 'Podolí' ],
			[ 'city' => 'Praha 4', 'area' => 'Braník' ],
			[ 'city' => 'Praha 4', 'area' => 'Nusle' ],
			[ 'city' => 'Praha 4', 'area' => 'Krč' ],
			[ 'city' => 'Praha 4', 'area' => 'Michle' ],
			[ 'city' => 'Praha 5', 'area' => 'Smíchov' ],
			[ 'city' => 'Praha 5', 'area' => 'Košíře' ],
			[ 'city' => 'Praha 6', 'area' => 'Dejvice' ],
			[ 'city' => 'Praha 7', 'area' => 'Holešovice' ],
			[ 'city' => 'Praha 8', 'area' => 'Karlín' ],
		];
		foreach ( $locations as $loc ) {
			$citySlug = sanitize_title( $loc['city'] );
			$areaSlug = sanitize_title( $loc['area'] );
			self::ensure_term( 'property_city', $loc['city'], $citySlug );
			self::ensure_term( 'property_area', $loc['area'], $areaSlug );
		}
		\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=tools&seeded=1' ) );
		exit;
	}

	public function handle_generate_pages() {
		\check_admin_referer( 'realt_ps_generate_pages' );
		if ( ! \current_user_can( 'manage_options' ) ) { \wp_die( \esc_html__( 'Insufficient permissions', 'realt-ps' ) ); }
		$areas = get_terms( [ 'taxonomy' => 'property_area', 'hide_empty' => false ] );
		if ( ! \is_wp_error( $areas ) && is_array( $areas ) ) {
			foreach ( $areas as $area ) {
				$title = sprintf( '%s – %s', __( 'Properties in', 'realt-ps' ), $area->name );
				$existing = get_page_by_title( $title );
				if ( $existing ) { continue; }
				$content = sprintf( '[realt_ps_properties title="%s" area="%s" per_page="12"]', esc_attr( $title ), esc_attr( $area->slug ) );
				$pid = wp_insert_post( [ 'post_title' => $title, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content ] );
			}
		}
		\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=tools&pages=1' ) );
		exit;
	}

	private static function ensure_term( string $taxonomy, string $name, string $slug ): void {
		$term = \get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term || \is_wp_error( $term ) ) {
			\wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
		}
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

	public function handle_delete_terms() {
		\check_admin_referer( 'realt_ps_delete_terms' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions', 'realt-ps' ) );
		}
		$taxonomies = [
			'property_city',
			'property_area',
			'property_country',
			'property_status',
			'property_label',
			'property_category',
			'property_action_category',
			'property_features',
			'property_state',
			'property_county_state',
		];
		$deleted = 0;
		foreach ( $taxonomies as $tx ) {
			if ( ! \taxonomy_exists( $tx ) ) { continue; }
			$terms = \get_terms( [ 'taxonomy' => $tx, 'hide_empty' => false ] );
			if ( \is_wp_error( $terms ) || empty( $terms ) ) { continue; }
			foreach ( $terms as $term ) {
				$ok = \wp_delete_term( (int) $term->term_id, $tx );
				if ( ! \is_wp_error( $ok ) ) { $deleted++; }
			}
		}
		\wp_safe_redirect( \admin_url( 'admin.php?page=realt-ps&tab=tools&deleted_terms=' . (int) $deleted ) );
		exit;
	}
}


