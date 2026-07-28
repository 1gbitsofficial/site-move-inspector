<?php
/**
 * Administrative screen and report downloads.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the only user interface added by the plugin.
 */
final class OGSMI_Admin_Page {

	const PAGE_SLUG = '1gbits-site-move-inspector';

	/**
	 * Job storage.
	 *
	 * @var OGSMI_Job_Store
	 */
	private $job_store;

	/**
	 * Registered page hooks.
	 *
	 * @var string[]
	 */
	private $page_hooks = array();

	/**
	 * Constructor.
	 *
	 * @param OGSMI_Job_Store $job_store Job storage.
	 */
	public function __construct( OGSMI_Job_Store $job_store ) {
		$this->job_store = $job_store;
	}

	/**
	 * Add the single-site Tools page.
	 */
	public function register_site_menu() {
		if ( is_multisite() ) {
			return;
		}

		$hook = add_management_page(
			__( '1Gbits Site Move Inspector', '1gbits-site-move-inspector' ),
			__( 'Site Move Inspector', '1gbits-site-move-inspector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( is_string( $hook ) ) {
			$this->page_hooks[] = $hook;
		}
	}

	/**
	 * Add the multisite Network Settings page.
	 */
	public function register_network_menu() {
		if ( ! is_multisite() ) {
			return;
		}

		$hook = add_submenu_page(
			'settings.php',
			__( '1Gbits Site Move Inspector', '1gbits-site-move-inspector' ),
			__( 'Site Move Inspector', '1gbits-site-move-inspector' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( is_string( $hook ) ) {
			$this->page_hooks[] = $hook;
		}
	}

	/**
	 * Load local assets only on this plugin's page.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'ogsmi-admin',
			OGSMI_URL . 'assets/admin.css',
			array(),
			OGSMI_VERSION
		);
		wp_enqueue_script(
			'ogsmi-admin',
			OGSMI_URL . 'assets/admin.js',
			array(),
			OGSMI_VERSION,
			true
		);

		wp_localize_script(
			'ogsmi-admin',
			'ogsmiAdmin',
			array(
				'restRoot'  => esc_url_raw( rest_url( OGSMI_REST_Controller::REST_NAMESPACE ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'activeJob' => $this->job_store->get_active_id( get_current_user_id() ),
				'strings'   => array(
					'starting'     => __( 'Preparing the inspection…', '1gbits-site-move-inspector' ),
					'scanning'     => __( 'Scanning filesystem metadata…', '1gbits-site-move-inspector' ),
					'complete'     => __( 'Inspection complete. Loading the report…', '1gbits-site-move-inspector' ),
					'canceling'    => __( 'Canceling the inspection…', '1gbits-site-move-inspector' ),
					'canceled'     => __( 'Inspection canceled. No site data was changed.', '1gbits-site-move-inspector' ),
					'genericError' => __( 'The inspection could not continue. No site data was changed.', '1gbits-site-move-inspector' ),
					/* translators: %s: number of files scanned. */
					'filesScanned' => __( 'Files scanned: %s', '1gbits-site-move-inspector' ),
					/* translators: %s: human-readable number of bytes scanned. */
					'bytesScanned' => __( '%s scanned', '1gbits-site-move-inspector' ),
				),
			)
		);
	}

	/**
	 * Render the scan form and latest short-lived report.
	 */
	public function render_page() {
		if ( ! $this->current_user_can_scan() ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', '1gbits-site-move-inspector' ) );
		}

		$user_id    = get_current_user_id();
		$active_job = $this->job_store->get_active_id( $user_id );
		$report     = $this->job_store->get_latest_report( $user_id );
		?>
		<div class="wrap ogsmi-wrap">
			<div class="ogsmi-heading">
				<div>
					<h1><?php esc_html_e( '1Gbits Site Move Inspector', '1gbits-site-move-inspector' ); ?></h1>
					<p class="description">
						<?php esc_html_e( 'Run a private, read-only preflight before moving this WordPress installation.', '1gbits-site-move-inspector' ); ?>
					</p>
				</div>
				<span class="ogsmi-brand"><?php esc_html_e( 'Built by 1Gbits', '1gbits-site-move-inspector' ); ?></span>
			</div>

			<div class="notice notice-info inline ogsmi-privacy-notice">
				<p>
					<strong><?php esc_html_e( 'Private by design:', '1gbits-site-move-inspector' ); ?></strong>
					<?php esc_html_e( 'the scan reads metadata only. It does not change site files, content, configuration, cron jobs, or existing caches, and it sends nothing to 1Gbits. The cursor and report are stored temporarily in WordPress and expire automatically.', '1gbits-site-move-inspector' ); ?>
				</p>
			</div>

			<section class="ogsmi-panel" aria-labelledby="ogsmi-run-title">
				<h2 id="ogsmi-run-title"><?php esc_html_e( 'Run migration preflight', '1gbits-site-move-inspector' ); ?></h2>
				<p>
					<?php esc_html_e( 'Destination details are optional and are retained only in the temporary report. Leave them blank to inspect the source site alone.', '1gbits-site-move-inspector' ); ?>
				</p>

				<form id="ogsmi-scan-form">
					<fieldset <?php disabled( '' !== $active_job ); ?>>
						<legend class="screen-reader-text"><?php esc_html_e( 'Optional destination profile', '1gbits-site-move-inspector' ); ?></legend>
						<div class="ogsmi-form-grid">
							<p>
								<label for="ogsmi-target-php"><?php esc_html_e( 'Destination PHP version', '1gbits-site-move-inspector' ); ?></label>
								<input id="ogsmi-target-php" name="target_php" type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]+){0,3}" placeholder="8.3">
							</p>
							<p>
								<label for="ogsmi-target-database-engine"><?php esc_html_e( 'Destination database engine', '1gbits-site-move-inspector' ); ?></label>
								<select id="ogsmi-target-database-engine" name="target_database_engine">
									<option value=""><?php esc_html_e( 'Not specified', '1gbits-site-move-inspector' ); ?></option>
									<option value="mysql"><?php esc_html_e( 'MySQL', '1gbits-site-move-inspector' ); ?></option>
									<option value="mariadb"><?php esc_html_e( 'MariaDB', '1gbits-site-move-inspector' ); ?></option>
								</select>
							</p>
							<p>
								<label for="ogsmi-target-database-version"><?php esc_html_e( 'Destination database version', '1gbits-site-move-inspector' ); ?></label>
								<input id="ogsmi-target-database-version" name="target_database_version" type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]+){0,3}" placeholder="8.0">
							</p>
							<p>
								<label for="ogsmi-target-disk"><?php esc_html_e( 'Destination free disk space (GB)', '1gbits-site-move-inspector' ); ?></label>
								<input id="ogsmi-target-disk" name="target_disk_gb" type="number" min="0" max="9999999" step="0.1" placeholder="20">
							</p>
							<p>
								<label for="ogsmi-target-multisite"><?php esc_html_e( 'Destination supports multisite', '1gbits-site-move-inspector' ); ?></label>
								<select id="ogsmi-target-multisite" name="target_multisite">
									<option value="unknown"><?php esc_html_e( 'Unknown', '1gbits-site-move-inspector' ); ?></option>
									<option value="yes"><?php esc_html_e( 'Yes', '1gbits-site-move-inspector' ); ?></option>
									<option value="no"><?php esc_html_e( 'No', '1gbits-site-move-inspector' ); ?></option>
								</select>
							</p>
						</div>

						<p class="ogsmi-self-test">
							<label>
								<input name="self_test" type="checkbox" value="1" checked>
								<?php esc_html_e( 'Test this site’s own public and REST URLs. No third-party URL is contacted.', '1gbits-site-move-inspector' ); ?>
							</label>
						</p>
					</fieldset>

					<p class="submit">
						<button id="ogsmi-start" class="button button-primary" type="submit" <?php disabled( '' !== $active_job ); ?>>
							<?php esc_html_e( 'Start inspection', '1gbits-site-move-inspector' ); ?>
						</button>
						<button id="ogsmi-cancel" class="button" type="button" <?php echo '' === $active_job ? 'hidden' : ''; ?>>
							<?php esc_html_e( 'Cancel', '1gbits-site-move-inspector' ); ?>
						</button>
					</p>
				</form>

				<div
					id="ogsmi-progress"
					class="ogsmi-progress"
					role="status"
					aria-live="polite"
					<?php echo '' === $active_job ? 'hidden' : ''; ?>
				>
					<div class="ogsmi-progress-track" role="progressbar" aria-valuetext="<?php esc_attr_e( 'Inspection in progress', '1gbits-site-move-inspector' ); ?>">
						<span></span>
					</div>
					<p id="ogsmi-progress-message">
						<?php esc_html_e( 'Resuming the inspection…', '1gbits-site-move-inspector' ); ?>
					</p>
					<p id="ogsmi-progress-detail" class="description"></p>
				</div>

				<div id="ogsmi-error" class="notice notice-error inline" role="alert" hidden><p></p></div>
			</section>

			<?php if ( is_array( $report ) ) : ?>
				<?php $this->render_report( $report ); ?>
			<?php else : ?>
				<section class="ogsmi-empty" aria-label="<?php esc_attr_e( 'No report', '1gbits-site-move-inspector' ); ?>">
					<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
					<p><?php esc_html_e( 'Run an inspection to create a temporary report.', '1gbits-site-move-inspector' ); ?></p>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Download the user's latest redacted report.
	 */
	public function export_report() {
		if ( ! $this->current_user_can_scan() ) {
			wp_die(
				esc_html__( 'You are not allowed to export this report.', '1gbits-site-move-inspector' ),
				esc_html__( 'Export denied', '1gbits-site-move-inspector' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'ogsmi_export_report' );

		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '';
		if ( ! in_array( $format, array( 'json', 'txt' ), true ) ) {
			wp_die(
				esc_html__( 'The requested export format is not supported.', '1gbits-site-move-inspector' ),
				esc_html__( 'Invalid export', '1gbits-site-move-inspector' ),
				array( 'response' => 400 )
			);
		}

		$report = $this->job_store->get_latest_report( get_current_user_id() );
		if ( ! is_array( $report ) ) {
			wp_die(
				esc_html__( 'The temporary report expired. Run a new inspection.', '1gbits-site-move-inspector' ),
				esc_html__( 'Report expired', '1gbits-site-move-inspector' ),
				array( 'response' => 404 )
			);
		}

		if ( 'json' === $format ) {
			$content_type = 'application/json; charset=utf-8';
			$content      = OGSMI_Exporter::to_json( $report );
		} else {
			$content_type = 'text/plain; charset=utf-8';
			$content      = OGSMI_Exporter::to_text( $report );
		}

		$filename = sprintf( '1gbits-site-move-report-%s.%s', gmdate( 'Ymd-His' ), $format );

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $content ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Purpose-built plain text or JSON download.
		echo $content;
		exit;
	}

	/**
	 * Render the report summary, checks, and admin-only inventory.
	 *
	 * @param array $report Internal report.
	 */
	private function render_report( array $report ) {
		$summary = $report['summary'] ?? OGSMI_Report_Builder::summarize( $report );
		$overall = $summary['overall'] ?? 'review_recommended';
		$status  = $this->overall_label( $overall );
		$time    = strtotime( $report['generated_at'] ?? '' );
		?>
		<section class="ogsmi-report" aria-labelledby="ogsmi-report-title">
			<div class="ogsmi-report-header">
				<div>
					<h2 id="ogsmi-report-title"><?php esc_html_e( 'Latest inspection report', '1gbits-site-move-inspector' ); ?></h2>
					<?php if ( false !== $time ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: localized report date and time. */
								esc_html__( 'Generated %s. The report expires automatically after one hour.', '1gbits-site-move-inspector' ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
				<span class="ogsmi-overall ogsmi-overall-<?php echo esc_attr( $overall ); ?>">
					<?php echo esc_html( $status ); ?>
				</span>
			</div>

			<div class="ogsmi-summary-grid">
				<?php
				foreach (
					array(
						'critical' => __( 'Critical', '1gbits-site-move-inspector' ),
						'warning'  => __( 'Warnings', '1gbits-site-move-inspector' ),
						'unknown'  => __( 'Unknown', '1gbits-site-move-inspector' ),
						'pass'     => __( 'Passed', '1gbits-site-move-inspector' ),
					) as $key => $label
				) :
					?>
					<div class="ogsmi-summary-card ogsmi-summary-<?php echo esc_attr( $key ); ?>">
						<strong><?php echo esc_html( number_format_i18n( absint( $summary['counts'][ $key ] ?? 0 ) ) ); ?></strong>
						<span><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $report['partial'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'This report is incomplete.', '1gbits-site-move-inspector' ); ?></strong></p>
					<ul>
						<?php foreach ( $report['partial_reasons'] ?? array() as $reason ) : ?>
							<li><?php echo esc_html( $reason ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<p class="ogsmi-guidance">
				<?php esc_html_e( 'This result is planning guidance, not a guarantee that a migration will succeed.', '1gbits-site-move-inspector' ); ?>
			</p>

			<div class="ogsmi-sections">
				<?php foreach ( $report['sections'] ?? array() as $section ) : ?>
					<?php $this->render_section( $section ); ?>
				<?php endforeach; ?>
			</div>

			<?php $this->render_inventory( $report['inventory'] ?? array() ); ?>
			<?php $this->render_exports(); ?>
		</section>
		<?php
	}

	/**
	 * Render one group of checks.
	 *
	 * @param array $section Section.
	 */
	private function render_section( array $section ) {
		?>
		<section class="ogsmi-result-section">
			<h3><?php echo esc_html( $section['title'] ?? '' ); ?></h3>
			<div class="ogsmi-check-list">
				<?php foreach ( $section['checks'] ?? array() as $check ) : ?>
					<?php
					$status = sanitize_key( $check['status'] ?? 'unknown' );
					?>
					<article class="ogsmi-check ogsmi-check-<?php echo esc_attr( $status ); ?>">
						<div class="ogsmi-check-heading">
							<span class="ogsmi-status"><?php echo esc_html( $this->check_status_label( $status ) ); ?></span>
							<strong><?php echo esc_html( $check['label'] ?? '' ); ?></strong>
							<span class="ogsmi-check-value"><?php echo esc_html( $check['value'] ?? '' ); ?></span>
						</div>
						<?php if ( ! empty( $check['message'] ) ) : ?>
							<p><?php echo esc_html( $check['message'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $check['recommendation'] ) ) : ?>
							<p class="ogsmi-action">
								<strong><?php esc_html_e( 'Recommended action:', '1gbits-site-move-inspector' ); ?></strong>
								<?php echo esc_html( $check['recommendation'] ); ?>
							</p>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render metadata that remains visible only to the administrator.
	 *
	 * @param array $inventory Inventory.
	 */
	private function render_inventory( array $inventory ) {
		$files           = $inventory['files'] ?? array();
		$database        = $inventory['database'] ?? array();
		$software        = $inventory['software'] ?? array();
		$category_labels = array();
		foreach ( $files['categories'] ?? array() as $category ) {
			$category_labels[ sanitize_key( $category['id'] ?? '' ) ] = (string) ( $category['label'] ?? '' );
		}
		?>
		<section class="ogsmi-inventory" aria-labelledby="ogsmi-inventory-title">
			<h3 id="ogsmi-inventory-title"><?php esc_html_e( 'Detailed inventory', '1gbits-site-move-inspector' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Relative paths and table names below are visible only to you. Privacy-safe exports mask them.', '1gbits-site-move-inspector' ); ?>
			</p>

			<details>
				<summary><?php esc_html_e( 'File categories and largest files', '1gbits-site-move-inspector' ); ?></summary>
				<div class="ogsmi-table-wrap">
					<table class="widefat striped">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Category', '1gbits-site-move-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Files', '1gbits-site-move-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Size', '1gbits-site-move-inspector' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $files['categories'] ?? array() as $category ) : ?>
								<tr>
									<td><?php echo esc_html( $category['label'] ?? '' ); ?></td>
									<td><?php echo esc_html( number_format_i18n( absint( $category['file_count'] ?? 0 ) ) ); ?></td>
									<td><?php echo esc_html( OGSMI_Utils::format_bytes( $category['bytes'] ?? 0 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( ! empty( $files['top_files'] ) ) : ?>
					<h4><?php esc_html_e( 'Largest files', '1gbits-site-move-inspector' ); ?></h4>
					<div class="ogsmi-table-wrap">
						<table class="widefat striped">
							<thead><tr>
								<th scope="col"><?php esc_html_e( 'Relative path', '1gbits-site-move-inspector' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Category', '1gbits-site-move-inspector' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Size', '1gbits-site-move-inspector' ); ?></th>
							</tr></thead>
							<tbody>
								<?php foreach ( $files['top_files'] as $file ) : ?>
									<tr>
										<td><code><?php echo esc_html( $file['path'] ?? '' ); ?></code></td>
										<td>
											<?php
											$category_id = sanitize_key( $file['category'] ?? '' );
											echo esc_html( $category_labels[ $category_id ] ?? __( 'Other files', '1gbits-site-move-inspector' ) );
											?>
										</td>
										<td><?php echo esc_html( OGSMI_Utils::format_bytes( $file['bytes'] ?? 0 ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</details>

			<details>
				<summary><?php esc_html_e( 'Largest database tables', '1gbits-site-move-inspector' ); ?></summary>
				<div class="ogsmi-table-wrap">
					<table class="widefat striped">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Table', '1gbits-site-move-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Rows (estimate)', '1gbits-site-move-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Engine', '1gbits-site-move-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Size', '1gbits-site-move-inspector' ); ?></th>
						</tr></thead>
						<tbody>
							<?php if ( empty( $database['top_tables'] ) ) : ?>
								<tr><td colspan="4"><?php esc_html_e( 'Database table metadata is unavailable.', '1gbits-site-move-inspector' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $database['top_tables'] as $table ) : ?>
									<tr>
										<td><code><?php echo esc_html( $table['name'] ?? '' ); ?></code></td>
										<td><?php echo esc_html( number_format_i18n( absint( $table['rows'] ?? 0 ) ) ); ?></td>
										<td><?php echo esc_html( $table['engine'] ?? '' ); ?></td>
										<td><?php echo esc_html( OGSMI_Utils::format_bytes( $table['bytes'] ?? 0 ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</details>

			<details>
				<summary>
					<?php
					$plugin_count = count( $software['active_plugins'] ?? array() );
					/* translators: %s: number of active plugins. */
					$software_label = _n(
						'Active software (%s plugin)',
						'Active software (%s plugins)',
						$plugin_count,
						'1gbits-site-move-inspector'
					);
					printf(
						esc_html( $software_label ),
						esc_html( number_format_i18n( $plugin_count ) )
					);
					?>
				</summary>
				<div class="ogsmi-table-wrap">
					<table class="widefat striped">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Name', '1gbits-site-move-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Version', '1gbits-site-move-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Requires PHP', '1gbits-site-move-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Requires WordPress', '1gbits-site-move-inspector' ); ?></th>
						</tr></thead>
						<tbody>
							<tr>
								<td>
									<?php
									printf(
										/* translators: %s: active theme name. */
										esc_html__( 'Theme: %s', '1gbits-site-move-inspector' ),
										esc_html( $software['active_theme']['name'] ?? '' )
									);
									?>
								</td>
								<td><?php echo esc_html( $software['active_theme']['version'] ?? '' ); ?></td>
								<td><?php echo esc_html( $software['active_theme']['requires_php'] ?? '' ); ?></td>
								<td><?php echo esc_html( $software['active_theme']['requires_wp'] ?? '' ); ?></td>
							</tr>
							<?php foreach ( $software['active_plugins'] ?? array() as $plugin ) : ?>
								<tr>
									<td><?php echo esc_html( $plugin['name'] ?? '' ); ?></td>
									<td><?php echo esc_html( $plugin['version'] ?? '' ); ?></td>
									<td><?php echo esc_html( $plugin['requires_php'] ?? '' ); ?></td>
									<td><?php echo esc_html( $plugin['requires_wp'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</details>
		</section>
		<?php
	}

	/**
	 * Render privacy-safe direct-download forms.
	 */
	private function render_exports() {
		?>
		<section class="ogsmi-exports" aria-labelledby="ogsmi-export-title">
			<h3 id="ogsmi-export-title"><?php esc_html_e( 'Export privacy-safe report', '1gbits-site-move-inspector' ); ?></h3>
			<p>
				<?php esc_html_e( 'Exports mask table names and individual file paths and omit domains, IP addresses, emails, credentials, content, option values, logs, and request data.', '1gbits-site-move-inspector' ); ?>
			</p>
			<div class="ogsmi-export-actions">
				<?php
				foreach ( array(
					'txt'  => __( 'Download TXT', '1gbits-site-move-inspector' ),
					'json' => __( 'Download JSON', '1gbits-site-move-inspector' ),
				) as $format => $label ) :
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ogsmi_export_report">
						<input type="hidden" name="format" value="<?php echo esc_attr( $format ); ?>">
						<?php wp_nonce_field( 'ogsmi_export_report' ); ?>
						<button class="button" type="submit"><?php echo esc_html( $label ); ?></button>
					</form>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Return a translatable overall label.
	 *
	 * @param string $overall Overall key.
	 * @return string
	 */
	private function overall_label( $overall ) {
		$labels = array(
			'high_risk'          => __( 'High risk', '1gbits-site-move-inspector' ),
			'review_recommended' => __( 'Review recommended', '1gbits-site-move-inspector' ),
			'no_blockers'        => __( 'No blockers detected', '1gbits-site-move-inspector' ),
		);

		return $labels[ $overall ] ?? $labels['review_recommended'];
	}

	/**
	 * Return a translatable check status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function check_status_label( $status ) {
		$labels = array(
			'pass'           => __( 'Pass', '1gbits-site-move-inspector' ),
			'warning'        => __( 'Warning', '1gbits-site-move-inspector' ),
			'critical'       => __( 'Critical', '1gbits-site-move-inspector' ),
			'unknown'        => __( 'Unknown', '1gbits-site-move-inspector' ),
			'not_applicable' => __( 'Not applicable', '1gbits-site-move-inspector' ),
		);

		return $labels[ $status ] ?? $labels['unknown'];
	}

	/**
	 * Apply site/network authorization consistently.
	 *
	 * @return bool
	 */
	private function current_user_can_scan() {
		if ( is_multisite() ) {
			return is_super_admin() && current_user_can( 'manage_network_options' );
		}

		return current_user_can( 'manage_options' );
	}
}
