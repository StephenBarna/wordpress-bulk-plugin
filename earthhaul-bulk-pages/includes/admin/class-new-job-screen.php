<?php
/**
 * Bulk Job admin screen.
 *
 * One-stop workflow that replaces the old separate New Job, Rewrite Page,
 * and Neighborhoods screens. The user picks a Beaver Builder template,
 * uploads a cities CSV, optionally uploads a neighborhoods CSV, ticks
 * which pipelines (text / images / SEO) to run, and clicks Run.
 *
 * Flow:
 *   1. Form mode (default render): collect inputs.
 *   2. handle_submit: validate + parse both CSVs + stage a job-state
 *      transient, then redirect back here with ?ehbp_job=<id>. Dry-run
 *      short-circuits to the preview table without staging.
 *   3. Progress mode: page renders a progress bar + live log scaffold.
 *      JS polls the AJAX endpoint, processing one city per chunk.
 *   4. AJAX endpoint (ehbp_bulk_job_step): loads the job state, calls
 *      Bulk_Job_Runner::run_city for the requested index, appends the
 *      summary to job state, returns JSON for the next chunk.
 *   5. After all cities are processed (or cancelled), JS swaps in the
 *      final results table.
 *
 * Job state transient is keyed by user ID + job ID, has a 1h TTL, and is
 * cleared automatically when the user clicks "Done" (or just navigates
 * away - the cleanup runs on the next render of the form mode if the
 * job is fully processed).
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

use EarthHaul\BulkPages\Services\Bulk_Job_Runner;
use EarthHaul\BulkPages\Services\CSV_Importer;
use EarthHaul\BulkPages\Services\Job_Storage;
use EarthHaul\BulkPages\Services\Neighborhoods_CSV_Importer;
use EarthHaul\BulkPages\Services\Page_Cloner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class New_Job_Screen {

	public const PAGE_SLUG            = 'ehbp-new-job';
	public const RUN_ACTION           = 'ehbp_run_clone_job';
	public const RUN_NONCE            = 'ehbp_run_clone_job';
	public const STEP_AJAX_ACTION     = 'ehbp_bulk_job_step';
	public const TEMPLATE_DL_ACTION   = 'ehbp_download_csv_template';
	public const DETECT_AJAX_ACTION   = 'ehbp_detect_template_mode';
	public const RESULT_KEY           = 'ehbp_last_job_result';
	public const JOB_STATE_TTL        = HOUR_IN_SECONDS;

	public const DEFAULT_TITLE_FORMAT = 'Roll-Off Dumpster Rentals in {{city}}, {{state}}';

	public static function register(): void {
		add_action( 'admin_post_' . self::RUN_ACTION, array( self::class, 'handle_submit' ) );
		add_action( 'admin_post_' . self::TEMPLATE_DL_ACTION, array( self::class, 'handle_template_download' ) );
		add_action( 'wp_ajax_' . self::STEP_AJAX_ACTION, array( self::class, 'handle_ajax_step' ) );
		add_action( 'wp_ajax_' . self::DETECT_AJAX_ACTION, array( self::class, 'handle_ajax_detect_mode' ) );
	}

	/**
	 * AJAX endpoint backing the form's live "auto-detected mode" banner.
	 *
	 * Given a template post ID, returns whether the runner will treat
	 * this as a service sub-page job (and which location template +
	 * slug it'll use) so the user can confirm the right thing will
	 * happen BEFORE submitting. Same detection rule as the runner —
	 * see {@see Bulk_Job_Runner::auto_detect_service_mode()}.
	 */
	public static function handle_ajax_detect_mode(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::DETECT_AJAX_ACTION, 'nonce' );

		$template_id = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
		if ( $template_id <= 0 ) {
			wp_send_json_success( array( 'service_mode' => false ) );
		}

		$detected = \EarthHaul\BulkPages\Services\Bulk_Job_Runner::auto_detect_service_mode( $template_id );
		wp_send_json_success( $detected );
	}

	public static function render(): void {
		$bb_active = class_exists( '\FLBuilderModel' );
		$job_id    = isset( $_GET['ehbp_job'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ehbp_job'] ) ) : '';
		$state     = '' !== $job_id ? self::load_job_state( $job_id ) : null;

		// Last dry-run preview: shown once then cleared.
		$last_result = get_transient( self::RESULT_KEY );
		if ( $last_result ) {
			delete_transient( self::RESULT_KEY );
		}
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Bulk Pages - New Job', 'earthhaul-bulk-pages' ); ?>
				<?php if ( defined( 'EHBP_VERSION' ) ) : ?>
					<span style="font-size:13px;font-weight:normal;color:#646970;margin-left:8px;">
						<?php echo esc_html( sprintf( __( 'plugin v%s', 'earthhaul-bulk-pages' ), EHBP_VERSION ) ); ?>
					</span>
				<?php endif; ?>
			</h1>

			<?php if ( ! $bb_active ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Beaver Builder is not active. Activate it before running a job.', 'earthhaul-bulk-pages' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php settings_errors( 'ehbp_new_job' ); ?>

			<?php if ( is_array( $state ) ) : ?>
				<?php self::render_progress( $state ); ?>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( $last_result ) : ?>
				<?php self::render_dry_run_preview( $last_result ); ?>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Pick a Beaver Builder template, upload a CSV of cities, optionally upload a neighborhoods CSV and choose which AI pipelines to run, then click Run. Each row produces (or reuses) one cloned draft and is fully localized in one shot.', 'earthhaul-bulk-pages' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RUN_ACTION ); ?>">
				<?php wp_nonce_field( self::RUN_NONCE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ehbp_template"><?php esc_html_e( 'Template page', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<?php self::render_template_dropdown(); ?>
							<p class="description"><?php esc_html_e( 'Only pages with Beaver Builder enabled are listed. Default suggestion is the Orlando location page if it exists. The "City, State" pair is parsed from this page\'s title and used as the source label for the AI pipelines.', 'earthhaul-bulk-pages' ); ?></p>
							<div id="ehbp-mode-banner" style="display:none;margin-top:10px;padding:10px 12px;border-left:4px solid #2271b1;background:#f0f6fc;"></div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ehbp_parent"><?php esc_html_e( 'Parent page', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'             => 'ehbp_parent',
									'id'               => 'ehbp_parent',
									'show_option_none' => __( '(top-level / no parent)', 'earthhaul-bulk-pages' ),
									'option_none_value'=> '0',
									'selected'         => self::default_parent_id(),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'New pages are placed under this parent. Defaults to the parent of the chosen template.', 'earthhaul-bulk-pages' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ehbp_title_format"><?php esc_html_e( 'Title format', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<input type="text" name="ehbp_title_format" id="ehbp_title_format" class="large-text" value="<?php echo esc_attr( self::DEFAULT_TITLE_FORMAT ); ?>">
							<p class="description"><?php echo wp_kses_post( __( 'Tokens: <code>{{city}}</code>, <code>{{state}}</code>, <code>{{slug}}</code>, plus any extra columns from your CSV.', 'earthhaul-bulk-pages' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ehbp_csv"><?php esc_html_e( 'Cities CSV', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<input type="file" name="ehbp_csv" id="ehbp_csv" accept=".csv,text/csv" required>
							<p class="description">
								<a href="<?php echo esc_url( self::template_download_url( 'cities' ) ); ?>"><?php esc_html_e( 'Download cities template CSV', 'earthhaul-bulk-pages' ); ?></a>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ehbp_neighborhoods_csv"><?php esc_html_e( 'Neighborhoods CSV', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<input type="file" name="ehbp_neighborhoods_csv" id="ehbp_neighborhoods_csv" accept=".csv,text/csv">
							<p class="description">
								<?php esc_html_e( 'Optional. Modules tagged with the ehbp-neighborhoods CSS class on the cloned page get rebuilt from this list.', 'earthhaul-bulk-pages' ); ?>
								<br>
								<a href="<?php echo esc_url( self::template_download_url( 'neighborhoods' ) ); ?>"><?php esc_html_e( 'Download neighborhoods template CSV', 'earthhaul-bulk-pages' ); ?></a>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pipelines', 'earthhaul-bulk-pages' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;">
								<input type="checkbox" name="ehbp_pipelines[text]" value="1" checked>
								<?php esc_html_e( 'Text - rewrite headings, paragraphs, FAQs, button text', 'earthhaul-bulk-pages' ); ?>
							</label>
							<label style="display:block;margin-bottom:6px;">
								<input type="checkbox" name="ehbp_pipelines[images]" value="1" checked>
								<?php esc_html_e( 'Images - rename + sideload + AI alt text (skips brand assets)', 'earthhaul-bulk-pages' ); ?>
							</label>
							<label style="display:block;margin-bottom:6px;">
								<input type="checkbox" name="ehbp_pipelines[meta]" value="1" checked>
								<?php esc_html_e( 'SEO - Yoast title / meta description / focus keyphrase', 'earthhaul-bulk-pages' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Smart-skip is built into every pipeline: fields that already mention the target city (and not the source) are skipped without an API call, so re-running a job is cheap.', 'earthhaul-bulk-pages' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'earthhaul-bulk-pages' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;">
								<input type="checkbox" name="ehbp_skip_existing" value="1" checked>
								<?php esc_html_e( 'Reuse existing cloned drafts instead of creating duplicates (matches by slug + parent + plugin marker)', 'earthhaul-bulk-pages' ); ?>
							</label>
							<label style="display:block;">
								<input type="checkbox" name="ehbp_dry_run" value="1">
								<?php esc_html_e( 'Dry run (parse + validate the CSV but do NOT create or modify pages)', 'earthhaul-bulk-pages' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Service / sub-page mode', 'earthhaul-bulk-pages' ); ?></th>
						<td>
							<p class="description" style="margin-top:0;">
								<?php esc_html_e( 'Auto-detected from the Template page. If the template lives under a city you\'ve already cloned out (e.g. /locations/orlando-fl/concrete-disposal-dumpster-rentals/), the runner clones it once per city, parents each clone under that city\'s existing location page, and reuses the template\'s slug for every clone. The blue banner above the form confirms exactly what will happen.', 'earthhaul-bulk-pages' ); ?>
							</p>
							<details style="margin-top:10px;">
								<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'Manual override (only needed if auto-detect is wrong)', 'earthhaul-bulk-pages' ); ?></summary>
								<p style="margin-top:12px;">
									<label for="ehbp_page_slug_override" style="display:inline-block;width:200px;"><?php esc_html_e( 'Page slug override:', 'earthhaul-bulk-pages' ); ?></label>
									<input type="text" name="ehbp_page_slug_override" id="ehbp_page_slug_override" class="regular-text" placeholder="<?php esc_attr_e( '(auto)', 'earthhaul-bulk-pages' ); ?>" value="">
								</p>
								<p class="description" style="margin-left:200px;margin-top:0;">
									<?php esc_html_e( 'Leave blank to use the template\'s own slug. Set only to force a different slug for every clone.', 'earthhaul-bulk-pages' ); ?>
								</p>
								<p style="margin-top:12px;">
									<label>
										<input type="checkbox" name="ehbp_parent_lookup_by_city" value="1">
										<?php esc_html_e( "Force dynamic parent lookup (auto-on when service template is detected)", 'earthhaul-bulk-pages' ); ?>
									</label>
								</p>
								<p style="margin-top:8px;">
									<label for="ehbp_location_template_id" style="display:inline-block;width:200px;"><?php esc_html_e( 'Location template:', 'earthhaul-bulk-pages' ); ?></label>
									<?php self::render_location_template_dropdown(); ?>
								</p>
								<p class="description" style="margin-left:200px;margin-top:0;">
									<?php esc_html_e( 'Leave at "(not used unless dynamic parent is checked)" for auto-detect. Override only if the source template\'s parent is not the right location template.', 'earthhaul-bulk-pages' ); ?>
								</p>
							</details>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Run', 'earthhaul-bulk-pages' ) ); ?>
			</form>
			<?php self::render_mode_detector_js(); ?>
		</div>
		<?php
	}

	/**
	 * Hidden helper that wires up the live "auto-detected mode" banner.
	 * On page load and on every change to the Template dropdown, fires a
	 * lightweight AJAX call that runs Bulk_Job_Runner::auto_detect_service_mode
	 * and renders a plain-English summary so the user can confirm what
	 * the run will do without reading the docblock.
	 */
	private static function render_mode_detector_js(): void {
		$nonce    = wp_create_nonce( self::DETECT_AJAX_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<script>
		(function () {
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var dd      = document.getElementById('ehbp_template');
			var banner  = document.getElementById('ehbp-mode-banner');
			if (!dd || !banner) { return; }

			function detect() {
				var id = parseInt(dd.value || '0', 10);
				if (!id) {
					banner.style.display = 'none';
					banner.innerHTML = '';
					return;
				}
				var fd = new FormData();
				fd.append('action', <?php echo wp_json_encode( self::DETECT_AJAX_ACTION ); ?>);
				fd.append('nonce', nonce);
				fd.append('template_id', String(id));
				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (!json || !json.success) { return; }
						var d = json.data || {};
						banner.style.display = 'block';
						if (d.service_mode) {
							banner.innerHTML =
								'<strong>Detected: Service sub-page mode.</strong> ' +
								'Each cloned page will be parented under the matching city\'s existing ' +
								'<em>' + escapeHtml(d.location_template_title || '(unknown)') + '</em>-style location page, ' +
								'and will use the slug <code>' + escapeHtml(d.page_slug_override || '') + '</code>. ' +
								'No further config needed below.';
						} else {
							banner.innerHTML =
								'<strong>Detected: Standard location-page mode.</strong> ' +
								'Each cloned page will use its CSV row\'s slug as the URL slug and the "Parent page" you picked above as its parent.';
						}
					})
					.catch(function () { /* leave banner hidden on error */ });
			}
			function escapeHtml(s) {
				return String(s).replace(/[&<>"']/g, function (c) {
					return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
				});
			}
			dd.addEventListener('change', detect);
			detect();
		})();
		</script>
		<?php
	}

	public static function default_parent_id(): int {
		$orlando = self::find_template_default();
		if ( $orlando ) {
			$post = get_post( $orlando );
			if ( $post && (int) $post->post_parent > 0 ) {
				return (int) $post->post_parent;
			}
		}
		return 0;
	}

	private static function find_template_default(): int {
		$candidates = array(
			get_page_by_path( 'locations/orlando-fl' ),
			get_page_by_path( 'orlando-fl' ),
			get_page_by_path( 'orlando' ),
		);
		foreach ( $candidates as $page ) {
			if ( $page && get_post_meta( $page->ID, '_fl_builder_enabled', true ) ) {
				return (int) $page->ID;
			}
		}
		return 0;
	}

	private static function render_template_dropdown(): void {
		global $wpdb;

		$results = $wpdb->get_results(
			"
			SELECT p.ID, p.post_title, p.post_status
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_fl_builder_enabled' AND pm.meta_value = '1'
			WHERE p.post_type = 'page' AND p.post_status IN ('publish','draft','private')
			ORDER BY p.post_title ASC
			LIMIT 500
			"
		);

		$default = self::find_template_default();

		echo '<select name="ehbp_template" id="ehbp_template" required class="regular-text" style="max-width: 520px; width: 100%;">';
		echo '<option value="">' . esc_html__( '-- choose a template --', 'earthhaul-bulk-pages' ) . '</option>';
		foreach ( $results as $row ) {
			printf(
				'<option value="%1$d" %2$s>%3$s (#%1$d, %4$s)</option>',
				(int) $row->ID,
				selected( $default, (int) $row->ID, false ),
				esc_html( $row->post_title ),
				esc_html( $row->post_status )
			);
		}
		echo '</select>';
	}

	/**
	 * Manual-override-only dropdown that lists every BB-enabled page so
	 * the user can force a different "location template" if the auto-
	 * detect (template->post_parent) isn't right. Defaults to value 0,
	 * meaning "leave to auto-detect"; the runner only honors the form
	 * value if the user also checked the "Force dynamic parent lookup"
	 * box, otherwise auto-detect wins.
	 */
	private static function render_location_template_dropdown(): void {
		global $wpdb;

		$results = $wpdb->get_results(
			"
			SELECT p.ID, p.post_title, p.post_status
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_fl_builder_enabled' AND pm.meta_value = '1'
			WHERE p.post_type = 'page' AND p.post_status IN ('publish','draft','private')
			ORDER BY p.post_title ASC
			LIMIT 500
			"
		);

		echo '<select name="ehbp_location_template_id" id="ehbp_location_template_id" class="regular-text" style="max-width: 520px;">';
		echo '<option value="0" selected>' . esc_html__( '-- (auto-detect from template parent) --', 'earthhaul-bulk-pages' ) . '</option>';
		foreach ( $results as $row ) {
			printf(
				'<option value="%1$d">%2$s (#%1$d, %3$s)</option>',
				(int) $row->ID,
				esc_html( $row->post_title ),
				esc_html( $row->post_status )
			);
		}
		echo '</select>';
	}

	/* -------------------------------------------------------------------- */
	/* Submit                                                                */
	/* -------------------------------------------------------------------- */

	public static function handle_submit(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::RUN_NONCE );

		$template_id = isset( $_POST['ehbp_template'] ) ? (int) $_POST['ehbp_template'] : 0;
		$parent_id   = isset( $_POST['ehbp_parent'] ) ? (int) $_POST['ehbp_parent'] : 0;
		$dry_run     = ! empty( $_POST['ehbp_dry_run'] );
		$skip_exist  = ! empty( $_POST['ehbp_skip_existing'] );
		$title_fmt   = isset( $_POST['ehbp_title_format'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['ehbp_title_format'] ) )
			: self::DEFAULT_TITLE_FORMAT;

		// Service / sub-page mode flags.
		$page_slug_override    = isset( $_POST['ehbp_page_slug_override'] )
			? sanitize_title( wp_unslash( (string) $_POST['ehbp_page_slug_override'] ) )
			: '';
		$parent_lookup_by_city = ! empty( $_POST['ehbp_parent_lookup_by_city'] );
		$location_template_id  = isset( $_POST['ehbp_location_template_id'] )
			? (int) $_POST['ehbp_location_template_id']
			: 0;

		$pipelines_in = isset( $_POST['ehbp_pipelines'] ) && is_array( $_POST['ehbp_pipelines'] )
			? wp_unslash( (array) $_POST['ehbp_pipelines'] )
			: array();
		$pipelines = array(
			'text'   => ! empty( $pipelines_in['text'] ),
			'images' => ! empty( $pipelines_in['images'] ),
			'meta'   => ! empty( $pipelines_in['meta'] ),
		);

		$errors = array();

		if ( $template_id <= 0 ) {
			$errors[] = __( 'Choose a template page.', 'earthhaul-bulk-pages' );
		}
		if ( ! isset( $_FILES['ehbp_csv'] ) || empty( $_FILES['ehbp_csv']['tmp_name'] ) ) {
			$errors[] = __( 'Upload a Cities CSV file.', 'earthhaul-bulk-pages' );
		}
		// Manual dynamic-parent override without a manual Location
		// template is fine now: Bulk_Job_Runner::auto_detect_service_mode
		// fills it in from the source template's post_parent. We only
		// flag the case where neither auto-detect nor the user supplied
		// a usable parent template, which surfaces at runtime as a
		// clear per-row "could not find an existing location page"
		// error rather than a silent misroute.

		if ( ! empty( $errors ) ) {
			self::flash_errors( $errors );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		// Cities CSV is required.
		$csv_path = (string) $_FILES['ehbp_csv']['tmp_name'];
		$parsed   = CSV_Importer::parse_file( $csv_path );

		if ( is_wp_error( $parsed ) ) {
			self::flash_errors( array( $parsed->get_error_message() ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		// Neighborhoods CSV is optional.
		$neighborhoods         = array();
		$has_neighborhoods_csv = false;
		if ( isset( $_FILES['ehbp_neighborhoods_csv'] ) && ! empty( $_FILES['ehbp_neighborhoods_csv']['tmp_name'] ) ) {
			$has_neighborhoods_csv = true;
			$nb = Neighborhoods_CSV_Importer::parse_file( (string) $_FILES['ehbp_neighborhoods_csv']['tmp_name'] );
			if ( is_wp_error( $nb ) ) {
				self::flash_errors( array( $nb->get_error_message() ) );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
				exit;
			}
			$neighborhoods = (array) ( $nb['groups'] ?? array() );
		}

		$warnings = self::compute_csv_warnings( $parsed['rows'], $neighborhoods, $has_neighborhoods_csv );

		// Dry run -> short-circuit to the preview table.
		if ( $dry_run ) {
			$preview = array_map(
				static function ( $row ) use ( $title_fmt, $neighborhoods ) {
					$slug = (string) $row['slug'];
					return array(
						'slug'              => $slug,
						'city'              => (string) $row['city'],
						'state'             => (string) $row['state'],
						'title'             => Page_Cloner::format_title( $title_fmt, $row ),
						'neighborhoods'     => isset( $neighborhoods[ $slug ] ) ? count( (array) $neighborhoods[ $slug ] ) : 0,
					);
				},
				$parsed['rows']
			);
			set_transient(
				self::RESULT_KEY,
				array(
					'dry_run'    => true,
					'rows_total' => count( $parsed['rows'] ),
					'preview'    => $preview,
					'warnings'   => $warnings,
				),
				5 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		// Real run: stage the job state and redirect into progress mode.
		$job_id = 'ehbp_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 4, false, false );

		$source_label = self::derive_template_label( $template_id );

		// Persist bulky inputs to disk. The transient-backed state below
		// keeps only metadata + progress + results; on hosts with a 1MB
		// object-cache value cap (Memcached/Redis defaults), stuffing
		// the full cities + neighborhoods arrays into the transient
		// silently fails the SET on big runs and the progress page
		// renders with no state.
		$cities_rows = array_values( $parsed['rows'] );
		if ( ! Job_Storage::write_cities( $job_id, $cities_rows ) ) {
			self::flash_errors( array(
				__( 'Could not write cities.json to the uploads directory. Check that wp-content/uploads is writable.', 'earthhaul-bulk-pages' ),
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
		if ( ! Job_Storage::write_neighborhoods( $job_id, $neighborhoods ) ) {
			Job_Storage::delete( $job_id );
			self::flash_errors( array(
				__( 'Could not write neighborhoods.json to the uploads directory.', 'earthhaul-bulk-pages' ),
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		$state = array(
			'job_id'                => $job_id,
			'template_id'           => $template_id,
			'parent_id'             => $parent_id,
			'title_fmt'             => $title_fmt,
			'source_label'          => $source_label,
			'source_token'          => self::derive_template_token( $template_id, $source_label ),
			'pipelines'             => $pipelines,
			'skip_existing'         => $skip_exist,
			'page_slug_override'    => $page_slug_override,
			'parent_lookup_by_city' => $parent_lookup_by_city,
			'location_template_id'  => $location_template_id,
			'warnings'              => $warnings,
			'progress'      => array(
				'index'     => 0,
				'total'     => count( $cities_rows ),
				'cancelled' => false,
				'started'   => time(),
			),
			'results'       => array(),
		);

		if ( ! self::save_job_state( $job_id, $state ) ) {
			Job_Storage::delete( $job_id );
			self::flash_errors( array(
				__( 'Could not save job state. The transient write failed (object-cache value-size limit?). Try splitting the cities CSV into smaller batches (e.g. 50 cities each) or contact your host about Memcached/Redis value limits.', 'earthhaul-bulk-pages' ),
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ehbp_job=' . rawurlencode( $job_id ) ) );
		exit;
	}

	/**
	 * Pull a "City, State" pair out of a post's title and return a single
	 * "City, State" string. Falls back to the raw title if the parser
	 * can't make sense of it.
	 *
	 * Strategy: split on commas, take the last segment as state and the one
	 * before it as the candidate city. If the candidate starts with
	 * boilerplate like "Roll-Off Dumpster Rentals in", strip everything up
	 * to and including the preposition.
	 */
	private static function derive_template_label( int $template_id ): string {
		// Strategy: try every signal in order until one gives us a clean
		// "City, State" pair. The page title is the most-readable signal
		// but also the most user-mutable, so we layer fallbacks that go
		// (a) the parent's title (for service sub-pages), (b) the parent's
		// SLUG (most reliable — slugs follow the city-state-abbrev
		// convention almost universally), and finally (c) the template's
		// own slug.
		$direct = self::label_from_title( (string) get_the_title( $template_id ) );
		if ( '' !== $direct ) {
			return $direct;
		}

		$auto = \EarthHaul\BulkPages\Services\Bulk_Job_Runner::auto_detect_service_mode( $template_id );
		if ( ! empty( $auto['service_mode'] ) ) {
			$parent_id = (int) $auto['location_template_id'];

			$from_parent_title = self::label_from_title( (string) get_the_title( $parent_id ) );
			if ( '' !== $from_parent_title ) {
				return $from_parent_title;
			}

			$from_parent_slug = self::label_from_slug( $parent_id );
			if ( '' !== $from_parent_slug ) {
				return $from_parent_slug;
			}
		}

		$from_own_slug = self::label_from_slug( $template_id );
		if ( '' !== $from_own_slug ) {
			return $from_own_slug;
		}

		return (string) get_the_title( $template_id );
	}

	private static function derive_template_token( int $template_id, string $source_label ): string {
		// First preference: explicit per-page meta stamped by the plugin
		// when it cloned the page itself.
		$meta = (string) get_post_meta( $template_id, Page_Cloner::META_CITY_NAME, true );
		if ( '' !== $meta ) {
			return sanitize_title( $meta );
		}

		$auto = \EarthHaul\BulkPages\Services\Bulk_Job_Runner::auto_detect_service_mode( $template_id );
		if ( ! empty( $auto['service_mode'] ) ) {
			$parent_id   = (int) $auto['location_template_id'];

			$parent_meta = (string) get_post_meta( $parent_id, Page_Cloner::META_CITY_NAME, true );
			if ( '' !== $parent_meta ) {
				return sanitize_title( $parent_meta );
			}

			$parent_pair = self::parse_city_state_from_title( (string) get_the_title( $parent_id ) );
			if ( '' !== $parent_pair['city'] ) {
				return sanitize_title( $parent_pair['city'] );
			}

			// Slug fallback. The "Orlando, FL" page may literally be
			// titled "Orlando" with no comma, but its slug is reliably
			// "orlando-fl". Strip the trailing 2-letter state code and
			// the rest is the city token we want.
			$parent_post = get_post( $parent_id );
			if ( $parent_post ) {
				$city_slug = self::city_slug_from_post_slug( (string) $parent_post->post_name );
				if ( '' !== $city_slug ) {
					return $city_slug;
				}
			}
		}

		// Final fallback: parse the source_label string we were given.
		$parts = explode( ',', $source_label );
		$city  = trim( $parts[0] ?? '' );
		return '' !== $city ? sanitize_title( $city ) : '';
	}

	/**
	 * Try to derive a "City, State" label string from a post title, which
	 * may be in any of these shapes:
	 *   "Orlando, FL"                            -> "Orlando, FL"
	 *   "Orlando, Florida"                       -> "Orlando, Florida"
	 *   "Roll-Off Dumpster Rentals in Orlando, FL" -> "Orlando, FL"
	 * Returns '' when none of those parse.
	 */
	private static function label_from_title( string $title ): string {
		$pair = self::parse_city_state_from_title( $title );
		if ( '' !== $pair['city'] && '' !== $pair['state'] ) {
			return $pair['city'] . ', ' . $pair['state'];
		}
		return '';
	}

	/**
	 * Derive a "City, ST" label from a post slug like "orlando-fl" or
	 * "winter-haven-fl". The trailing two-letter state code is the
	 * convention every city slug on EarthHaul follows; everything before
	 * it is the city, with hyphens turned back into spaces.
	 */
	private static function label_from_slug( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$city_slug = self::city_slug_from_post_slug( (string) $post->post_name );
		$state     = self::state_from_post_slug( (string) $post->post_name );
		if ( '' === $city_slug || '' === $state ) {
			return '';
		}
		// "winter-haven" -> "Winter Haven".
		$city = ucwords( str_replace( '-', ' ', $city_slug ) );
		return $city . ', ' . strtoupper( $state );
	}

	private static function city_slug_from_post_slug( string $post_slug ): string {
		$post_slug = sanitize_title( $post_slug );
		if ( '' === $post_slug ) {
			return '';
		}
		$parts = explode( '-', $post_slug );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$tail = (string) end( $parts );
		// State code must be exactly 2 alpha chars, and there must be
		// at least one slug segment before it for the city.
		if ( 2 === strlen( $tail ) && preg_match( '/^[a-z]{2}$/i', $tail ) && count( $parts ) > 1 ) {
			array_pop( $parts );
			return implode( '-', $parts );
		}
		return '';
	}

	private static function state_from_post_slug( string $post_slug ): string {
		$post_slug = sanitize_title( $post_slug );
		if ( '' === $post_slug ) {
			return '';
		}
		$parts = explode( '-', $post_slug );
		$tail  = (string) end( $parts );
		if ( 2 === strlen( $tail ) && preg_match( '/^[a-z]{2}$/i', $tail ) ) {
			return strtolower( $tail );
		}
		return '';
	}

	/**
	 * @return array{city: string, state: string}
	 */
	public static function parse_city_state_from_title( string $title ): array {
		$title = trim( $title );
		if ( '' === $title || ! str_contains( $title, ',' ) ) {
			return array( 'city' => '', 'state' => '' );
		}

		$parts = array_map( 'trim', explode( ',', $title ) );
		if ( count( $parts ) < 2 ) {
			return array( 'city' => '', 'state' => '' );
		}

		$state = (string) array_pop( $parts );
		$city  = (string) end( $parts );

		if ( preg_match( '/\b(?:in|at|for|near)\s+(.+)$/i', $city, $m ) ) {
			$city = trim( $m[1] );
		}

		if ( strlen( $state ) > 25 || ! preg_match( '/^[A-Za-z\s.\'-]+$/', $state ) ) {
			return array( 'city' => '', 'state' => '' );
		}

		return array(
			'city'  => $city,
			'state' => $state,
		);
	}

	/* -------------------------------------------------------------------- */
	/* Progress mode                                                         */
	/* -------------------------------------------------------------------- */

	private static function render_progress( array $state ): void {
		$total      = (int) ( $state['progress']['total'] ?? 0 );
		$index      = (int) ( $state['progress']['index'] ?? 0 );
		$cancelled  = ! empty( $state['progress']['cancelled'] );
		$is_done    = $index >= $total;
		$results    = (array) ( $state['results'] ?? array() );
		$job_id     = (string) ( $state['job_id'] ?? '' );
		$nonce      = wp_create_nonce( self::STEP_AJAX_ACTION );
		$start_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div id="ehbp-bulk-progress"
			data-job-id="<?php echo esc_attr( $job_id ); ?>"
			data-total="<?php echo esc_attr( (string) $total ); ?>"
			data-start-index="<?php echo esc_attr( (string) $index ); ?>"
			data-done="<?php echo esc_attr( $is_done ? '1' : '0' ); ?>"
			data-cancelled="<?php echo esc_attr( $cancelled ? '1' : '0' ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
			data-action="<?php echo esc_attr( self::STEP_AJAX_ACTION ); ?>">

			<h2><?php esc_html_e( 'Running bulk job', 'earthhaul-bulk-pages' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Job ID:', 'earthhaul-bulk-pages' ); ?></strong> <code><?php echo esc_html( $job_id ); ?></code><br>
				<strong><?php esc_html_e( 'Source:', 'earthhaul-bulk-pages' ); ?></strong> <?php echo esc_html( (string) ( $state['source_label'] ?? '' ) ); ?> &middot;
				<strong><?php esc_html_e( 'Pipelines:', 'earthhaul-bulk-pages' ); ?></strong>
				<?php echo esc_html( self::format_pipelines( (array) ( $state['pipelines'] ?? array() ) ) ); ?>
			</p>

			<?php
			if ( ! empty( $state['warnings'] ) && is_array( $state['warnings'] ) ) {
				self::render_warnings( $state['warnings'] );
			}
			?>

			<div style="margin: 12px 0;">
				<div style="background:#f0f0f1;border:1px solid #dcdcde;border-radius:3px;height:24px;overflow:hidden;position:relative;">
					<div id="ehbp-bulk-bar" style="background:#2271b1;height:100%;width:<?php echo esc_attr( (string) self::pct( $index, $total ) ); ?>%;transition:width .3s ease;"></div>
				</div>
				<p style="margin-top:6px;">
					<span id="ehbp-bulk-counter"><?php echo esc_html( sprintf( '%d / %d', $index, $total ) ); ?></span> <?php esc_html_e( 'cities processed', 'earthhaul-bulk-pages' ); ?>
					<span id="ehbp-bulk-status" style="margin-left:12px;color:#646970;"><?php echo $is_done ? esc_html__( 'Complete.', 'earthhaul-bulk-pages' ) : esc_html__( 'Starting...', 'earthhaul-bulk-pages' ); ?></span>
				</p>
			</div>

			<p>
				<button type="button" class="button" id="ehbp-bulk-cancel" <?php echo $is_done ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Cancel', 'earthhaul-bulk-pages' ); ?>
				</button>
				<a class="button" id="ehbp-bulk-back" href="<?php echo esc_url( $start_url ); ?>" style="<?php echo $is_done ? '' : 'display:none;'; ?>">
					<?php esc_html_e( 'Start another job', 'earthhaul-bulk-pages' ); ?>
				</a>
			</p>

			<h3><?php esc_html_e( 'Live log', 'earthhaul-bulk-pages' ); ?></h3>
			<div id="ehbp-bulk-log" style="background:#fff;border:1px solid #dcdcde;border-radius:3px;padding:8px 12px;max-height:380px;overflow:auto;font-family:monospace;font-size:12px;">
				<?php foreach ( $results as $r ) : ?>
					<?php echo self::render_log_entry( $r ); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
				<?php endforeach; ?>
			</div>

			<h3 style="margin-top:24px;"><?php esc_html_e( 'Results', 'earthhaul-bulk-pages' ); ?></h3>
			<div id="ehbp-bulk-results">
				<?php if ( ! empty( $results ) ) : ?>
					<?php self::render_results_table( $results ); ?>
				<?php else : ?>
					<p style="color:#646970;"><em><?php esc_html_e( 'Results appear here as each city completes.', 'earthhaul-bulk-pages' ); ?></em></p>
				<?php endif; ?>
			</div>
		</div>

		<script>
		(function () {
			var root = document.getElementById('ehbp-bulk-progress');
			if (!root) return;

			var jobId = root.dataset.jobId;
			var total = parseInt(root.dataset.total, 10) || 0;
			var index = parseInt(root.dataset.startIndex, 10) || 0;
			var done = root.dataset.done === '1';
			var cancelled = root.dataset.cancelled === '1';
			var nonce = root.dataset.nonce;
			var ajaxUrl = root.dataset.ajaxUrl;
			var action = root.dataset.action;

			var bar = document.getElementById('ehbp-bulk-bar');
			var counter = document.getElementById('ehbp-bulk-counter');
			var status = document.getElementById('ehbp-bulk-status');
			var log = document.getElementById('ehbp-bulk-log');
			var resultsEl = document.getElementById('ehbp-bulk-results');
			var cancelBtn = document.getElementById('ehbp-bulk-cancel');
			var backBtn = document.getElementById('ehbp-bulk-back');

			function pct(i, n) { return n > 0 ? Math.round((i / n) * 100) : 0; }

			function renderProgress(i) {
				if (counter) counter.textContent = i + ' / ' + total;
				if (bar) bar.style.width = pct(i, total) + '%';
			}

			function appendLog(html) {
				if (!log) return;
				var holder = document.createElement('div');
				holder.innerHTML = html;
				while (holder.firstChild) log.appendChild(holder.firstChild);
				log.scrollTop = log.scrollHeight;
			}

			function setResultsTable(html) {
				if (resultsEl) resultsEl.innerHTML = html;
			}

			function finish(label) {
				if (status) status.textContent = label;
				if (cancelBtn) cancelBtn.disabled = true;
				if (backBtn) backBtn.style.display = '';
			}

			function step() {
				if (cancelled || done) return;
				if (status) status.textContent = 'Processing city ' + (index + 1) + ' of ' + total + '...';

				var body = new URLSearchParams();
				body.set('action', action);
				body.set('nonce', nonce);
				body.set('job_id', jobId);
				body.set('index', String(index));
				body.set('mode', 'step');

				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (!json || !json.success) {
							finish('Error: ' + ((json && json.data && json.data.message) || 'unknown'));
							return;
						}
						var data = json.data || {};
						if (data.log_html) appendLog(data.log_html);
						if (data.results_html) setResultsTable(data.results_html);
						index = data.next_index || (index + 1);
						renderProgress(index);

						if (data.cancelled) {
							cancelled = true;
							finish('Cancelled.');
							return;
						}
						if (data.done) {
							done = true;
							finish('Complete.');
							return;
						}
						setTimeout(step, 50);
					})
					.catch(function (err) {
						finish('Network error: ' + err.message);
					});
			}

			if (cancelBtn) {
				cancelBtn.addEventListener('click', function () {
					cancelBtn.disabled = true;
					if (status) status.textContent = 'Cancelling after the current city finishes...';
					var body = new URLSearchParams();
					body.set('action', action);
					body.set('nonce', nonce);
					body.set('job_id', jobId);
					body.set('mode', 'cancel');
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function (json) {
							cancelled = true;
							if (json && json.data && json.data.message) {
								if (status) status.textContent = json.data.message;
							}
						});
				});
			}

			if (!done && !cancelled) {
				renderProgress(index);
				step();
			} else {
				finish(done ? 'Complete.' : 'Cancelled.');
			}
		})();
		</script>
		<?php
	}

	private static function render_log_entry( array $r ): string {
		$lines   = array();
		$elapsed = isset( $r['elapsed_s'] ) ? (float) $r['elapsed_s'] : 0.0;
		// Color the runtime red if it's >= 80% of a likely 60s shared-host
		// timeout, yellow if >= 50%, otherwise muted gray. Visual signal
		// without spamming the log.
		if ( $elapsed >= 48.0 ) {
			$elapsed_color = '#c62828';
		} elseif ( $elapsed >= 30.0 ) {
			$elapsed_color = '#b06000';
		} else {
			$elapsed_color = '#646970';
		}
		$elapsed_html = $elapsed > 0
			? sprintf(
				' <span style="color:%s;font-weight:normal;">(%ss)</span>',
				esc_attr( $elapsed_color ),
				esc_html( number_format( $elapsed, 1 ) )
			)
			: '';
		$lines[] = sprintf(
			'<strong>%s</strong>%s',
			esc_html( ( $r['city'] ?? '' ) . ', ' . ( $r['state'] ?? '' ) ),
			$elapsed_html
		);
		if ( ! empty( $r['post_id'] ) ) {
			$lines[] = sprintf(
				'  - %s draft <a href="%s" target="_blank">#%d</a>',
				esc_html( $r['action'] ?? 'resolved' ),
				esc_url( (string) ( $r['edit_url'] ?? '' ) ),
				(int) $r['post_id']
			);
		}
		// Surface the resolved source/target identity so it's obvious from
		// the live log whether image renaming + AI rewrites have the
		// right city signals to work with.
		if ( ! empty( $r['debug'] ) && is_array( $r['debug'] ) ) {
			$src_label  = (string) ( $r['debug']['source_label'] ?? '' );
			$src_token  = (string) ( $r['debug']['source_token'] ?? '' );
			$tgt_token  = (string) ( $r['debug']['target_token'] ?? '' );
			if ( '' !== $src_label || '' !== $src_token || '' !== $tgt_token ) {
				$lines[] = sprintf(
					'  - <span style="color:#646970;">source: <code>%s</code> (token <code>%s</code>) &rarr; target token <code>%s</code></span>',
					esc_html( $src_label ),
					esc_html( $src_token ),
					esc_html( $tgt_token )
				);
			}
		}
		if ( ! empty( $r['neighborhoods']['attempted'] ) ) {
			$inline = (int) ( $r['neighborhoods']['inline_fields'] ?? 0 );
			$lines[] = sprintf(
				'  - neighborhoods: %s (%d entries -> %d module(s)%s)',
				$r['neighborhoods']['ok'] ? 'applied' : 'skipped',
				(int) $r['neighborhoods']['count'],
				(int) $r['neighborhoods']['modules'],
				$inline > 0 ? sprintf( ', %d inline field(s)', $inline ) : ''
			);
		}
		if ( ! empty( $r['text']['attempted'] ) ) {
			$lines[] = sprintf(
				'  - text: %d applied, %d skipped, %d failed',
				(int) $r['text']['applied'],
				(int) $r['text']['skipped'],
				(int) $r['text']['failed']
			);
		}
		if ( ! empty( $r['images']['attempted'] ) ) {
			$candidates = (int) ( $r['images']['candidates'] ?? 0 );
			$lines[] = sprintf(
				'  - images: %d applied, %d failed (%d candidate%s found)',
				(int) $r['images']['applied'],
				(int) $r['images']['failed'],
				$candidates,
				1 === $candidates ? '' : 's'
			);
			if ( 0 === $candidates ) {
				$lines[] = '  - <span style="color:#b06000;">no image candidates detected. Check that the source token above matches your filename pattern (e.g. token <code>orlando</code> for files named <code>...-orlando-fl.jpg</code>).</span>';
			}
		}
		if ( ! empty( $r['meta']['attempted'] ) ) {
			$lines[] = sprintf(
				'  - meta: %d applied, %d failed',
				(int) $r['meta']['applied'],
				(int) $r['meta']['failed']
			);
		}
		if ( ! empty( $r['errors'] ) ) {
			foreach ( $r['errors'] as $err ) {
				$lines[] = '  - <span style="color:#c62828;">error: ' . esc_html( $err ) . '</span>';
			}
		}
		return '<div style="margin-bottom:6px;">' . implode( '<br>', $lines ) . '</div>';
	}

	private static function render_results_table( array $results ): void {
		if ( empty( $results ) ) {
			echo '<p style="color:#646970;"><em>' . esc_html__( 'No cities processed yet.', 'earthhaul-bulk-pages' ) . '</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'City', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'Neighborhoods', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'Text', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'Images', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'SEO', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'Time', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'Open', 'earthhaul-bulk-pages' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $results as $r ) {
			$post_id  = (int) ( $r['post_id'] ?? 0 );
			$edit_url = (string) ( $r['edit_url'] ?? '' );
			$prev_url = (string) ( $r['preview_url'] ?? '' );
			$elapsed  = isset( $r['elapsed_s'] ) ? (float) $r['elapsed_s'] : 0.0;
			if ( $elapsed >= 48.0 ) {
				$time_color = '#c62828';
			} elseif ( $elapsed >= 30.0 ) {
				$time_color = '#b06000';
			} else {
				$time_color = '#646970';
			}
			$time_cell = $elapsed > 0
				? sprintf( '<span style="color:%s;">%ss</span>', esc_attr( $time_color ), esc_html( number_format( $elapsed, 1 ) ) )
				: '&mdash;';
			printf(
				'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( ( $r['city'] ?? '' ) . ', ' . ( $r['state'] ?? '' ) ),
				esc_html( (string) ( $r['slug'] ?? '' ) ),
				esc_html( (string) ( $r['action'] ?? '' ) ),
				! empty( $r['neighborhoods']['attempted'] )
					? esc_html( sprintf(
						'%d mod / %d inline / %d',
						(int) $r['neighborhoods']['modules'],
						(int) ( $r['neighborhoods']['inline_fields'] ?? 0 ),
						(int) $r['neighborhoods']['count']
					) )
					: '&mdash;',
				! empty( $r['text']['attempted'] )
					? esc_html( sprintf( '%d ok / %d skip / %d fail', (int) $r['text']['applied'], (int) $r['text']['skipped'], (int) $r['text']['failed'] ) )
					: '&mdash;',
				! empty( $r['images']['attempted'] )
					? esc_html( sprintf( '%d ok / %d fail', (int) $r['images']['applied'], (int) $r['images']['failed'] ) )
					: '&mdash;',
				! empty( $r['meta']['attempted'] )
					? esc_html( sprintf( '%d ok / %d fail', (int) $r['meta']['applied'], (int) $r['meta']['failed'] ) )
					: '&mdash;',
				$time_cell, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$post_id > 0
					? sprintf(
						'<a href="%s">edit</a> | <a href="%s" target="_blank">preview</a>',
						esc_url( $edit_url ),
						esc_url( $prev_url )
					)
					: '&mdash;'
			);
		}
		echo '</tbody></table>';
	}

	private static function format_pipelines( array $pipelines ): string {
		$on = array();
		if ( ! empty( $pipelines['text'] ) )   { $on[] = 'text'; }
		if ( ! empty( $pipelines['images'] ) ) { $on[] = 'images'; }
		if ( ! empty( $pipelines['meta'] ) )   { $on[] = 'meta'; }
		return empty( $on ) ? '(none)' : implode( ' + ', $on );
	}

	private static function pct( int $index, int $total ): int {
		return $total > 0 ? (int) min( 100, round( ( $index / $total ) * 100 ) ) : 0;
	}

	/* -------------------------------------------------------------------- */
	/* Dry-run preview (re-rendered after redirect)                         */
	/* -------------------------------------------------------------------- */

	private static function render_dry_run_preview( array $result ): void {
		echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Dry run complete.', 'earthhaul-bulk-pages' ) . '</strong></p></div>';

		if ( ! empty( $result['warnings'] ) && is_array( $result['warnings'] ) ) {
			self::render_warnings( $result['warnings'] );
		}

		if ( empty( $result['preview'] ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Preview (would create these pages)', 'earthhaul-bulk-pages' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Slug', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'City', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'earthhaul-bulk-pages' ) . '</th>';
		echo '<th>' . esc_html__( 'Neighborhoods', 'earthhaul-bulk-pages' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( (array) $result['preview'] as $row ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['slug'] ),
				esc_html( (string) $row['city'] ),
				esc_html( (string) $row['state'] ),
				esc_html( (string) $row['title'] ),
				(int) ( $row['neighborhoods'] ?? 0 ) > 0
					? esc_html( (string) (int) $row['neighborhoods'] )
					: '&mdash;'
			);
		}
		echo '</tbody></table>';
	}

	/* -------------------------------------------------------------------- */
	/* AJAX endpoint                                                         */
	/* -------------------------------------------------------------------- */

	public static function handle_ajax_step(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		if ( ! check_ajax_referer( self::STEP_AJAX_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'bad_nonce' ), 400 );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['job_id'] ) ) : '';
		$mode   = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mode'] ) ) : 'step';
		$state  = self::load_job_state( $job_id );

		if ( ! is_array( $state ) ) {
			wp_send_json_error( array( 'message' => 'job_not_found_or_expired' ), 404 );
		}

		// Cancel: flip the flag and bail.
		if ( 'cancel' === $mode ) {
			$state['progress']['cancelled'] = true;
			self::save_job_state( $job_id, $state );
			Job_Storage::delete( $job_id );
			wp_send_json_success( array(
				'cancelled' => true,
				'message'   => 'Cancelled. The current city (if any) finishes first.',
			) );
		}

		if ( ! empty( $state['progress']['cancelled'] ) ) {
			wp_send_json_success( array(
				'cancelled'  => true,
				'done'       => false,
				'next_index' => (int) $state['progress']['index'],
				'total'      => (int) $state['progress']['total'],
			) );
		}

		$index = (int) $state['progress']['index'];
		$total = (int) $state['progress']['total'];

		if ( $index >= $total ) {
			wp_send_json_success( array(
				'done'       => true,
				'next_index' => $index,
				'total'      => $total,
			) );
		}

		// Cities + neighborhoods now live on disk (see Job_Storage). Load
		// only the row we need for this chunk; the JSON read is cheap and
		// memoized per-request anyway.
		$cities_rows       = Job_Storage::read_cities( $job_id );
		$row               = (array) ( $cities_rows[ $index ] ?? array() );
		$slug              = (string) ( $row['slug'] ?? '' );
		$all_neighborhoods = Job_Storage::read_neighborhoods( $job_id );
		$neighborhoods     = isset( $all_neighborhoods[ $slug ] )
			? (array) $all_neighborhoods[ $slug ]
			: array();

		// Long-running by design: each chunk does ~30-90s of OpenAI work.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Time the runner end-to-end so the live log can surface
		// per-chunk wall-clock. On hosts with strict PHP timeouts (e.g.
		// SiteGround shared at 60s), seeing "47s" creep up across cities
		// is the early warning that we're about to start failing chunks.
		$started_at = microtime( true );

		$summary = Bulk_Job_Runner::run_city(
			(int) $state['template_id'],
			$row,
			array(
				'parent_id'             => (int) $state['parent_id'],
				'title_fmt'             => (string) $state['title_fmt'],
				'job_id'                => (string) $state['job_id'],
				'source_label'          => (string) $state['source_label'],
				'source_token'          => (string) $state['source_token'],
				'skip_existing'         => ! empty( $state['skip_existing'] ),
				'pipelines'             => (array) $state['pipelines'],
				'page_slug_override'    => (string) ( $state['page_slug_override'] ?? '' ),
				'parent_lookup_by_city' => ! empty( $state['parent_lookup_by_city'] ),
				'location_template_id'  => (int) ( $state['location_template_id'] ?? 0 ),
			),
			$neighborhoods
		);

		$summary['elapsed_s'] = round( microtime( true ) - $started_at, 1 );

		// Re-load state before writing, so any flags set by a concurrent
		// AJAX request (most notably the cancel button) aren't clobbered
		// by our long-in-flight chunk. The original $state still owns the
		// immutable inputs (template, cities, neighborhoods, etc.) but
		// progress + results live on whichever copy is freshest.
		$fresh = self::load_job_state( $job_id );
		if ( is_array( $fresh ) ) {
			$state = $fresh;
		}

		$state['results'][]           = $summary;
		$state['progress']['index']   = $index + 1;
		$state['progress']['updated'] = time();
		self::save_job_state( $job_id, $state );

		ob_start();
		self::render_results_table( $state['results'] );
		$results_html = (string) ob_get_clean();

		$is_done = $state['progress']['index'] >= $total;
		if ( $is_done ) {
			// Job finished cleanly; the on-disk JSON files are no longer
			// needed. Transient still holds metadata + results so the
			// final screen still renders if the user reloads.
			Job_Storage::delete( $job_id );
		}

		wp_send_json_success( array(
			'done'         => $is_done,
			'next_index'   => (int) $state['progress']['index'],
			'total'        => $total,
			'log_html'     => self::render_log_entry( $summary ),
			'results_html' => $results_html,
		) );
	}

	/* -------------------------------------------------------------------- */
	/* State helpers                                                         */
	/* -------------------------------------------------------------------- */

	private static function job_state_key( string $job_id ): string {
		return 'ehbp_job_state_' . get_current_user_id() . '_' . $job_id;
	}

	/**
	 * @return array|null
	 */
	private static function load_job_state( string $job_id ) {
		if ( '' === $job_id ) {
			return null;
		}
		$state = get_transient( self::job_state_key( $job_id ) );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Persist the job state into a transient. Returns true on a
	 * successful write so the form-submit handler can surface a clear
	 * error if the object cache silently rejects an oversized SET (1 MB
	 * Memcached default, common on managed hosts).
	 */
	private static function save_job_state( string $job_id, array $state ): bool {
		return (bool) set_transient( self::job_state_key( $job_id ), $state, self::JOB_STATE_TTL );
	}

	private static function flash_errors( array $errors ): void {
		foreach ( $errors as $msg ) {
			add_settings_error( 'ehbp_new_job', 'ehbp_new_job_error', $msg, 'error' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	/* -------------------------------------------------------------------- */
	/* CSV mismatch warnings                                                 */
	/* -------------------------------------------------------------------- */

	/**
	 * Cross-check the cities CSV against the neighborhoods CSV and surface
	 * the silent footguns that the importers don't reject:
	 *
	 *   - "orphan_slugs": city_slug values in the neighborhoods CSV that
	 *     don't appear in the cities CSV. These rows are silently ignored
	 *     by the runner; almost always indicates a typo.
	 *   - "cities_without_neighborhoods": cities in the cities CSV that
	 *     have no rows in the neighborhoods CSV. The cloned page will
	 *     keep whatever neighborhoods the template page had. Often
	 *     intentional, so reported as informational only.
	 *
	 * @param array $rows                  Cities CSV rows.
	 * @param array $neighborhoods         city_slug => [names] map.
	 * @param bool  $has_neighborhoods_csv Whether the user uploaded one.
	 * @return array{orphan_slugs: array<int,string>, cities_without_neighborhoods: array<int,string>}
	 */
	private static function compute_csv_warnings( array $rows, array $neighborhoods, bool $has_neighborhoods_csv ): array {
		$city_slugs = array();
		foreach ( $rows as $row ) {
			$slug = (string) ( $row['slug'] ?? '' );
			if ( '' !== $slug ) {
				$city_slugs[ $slug ] = true;
			}
		}

		$nb_slugs                     = array_keys( $neighborhoods );
		$orphan_slugs                 = array_values( array_diff( $nb_slugs, array_keys( $city_slugs ) ) );
		$cities_without_neighborhoods = $has_neighborhoods_csv
			? array_values( array_diff( array_keys( $city_slugs ), $nb_slugs ) )
			: array();

		sort( $orphan_slugs );
		sort( $cities_without_neighborhoods );

		return array(
			'orphan_slugs'                 => $orphan_slugs,
			'cities_without_neighborhoods' => $cities_without_neighborhoods,
		);
	}

	/**
	 * Render the warning notices for a given warning bundle. Safe to call
	 * with an empty/missing bundle (renders nothing).
	 */
	private static function render_warnings( array $warnings ): void {
		$orphans  = (array) ( $warnings['orphan_slugs'] ?? array() );
		$missing  = (array) ( $warnings['cities_without_neighborhoods'] ?? array() );

		if ( ! empty( $orphans ) ) {
			echo '<div class="notice notice-warning"><p><strong>'
				. esc_html__( 'Neighborhoods CSV has rows for slugs that are not in the Cities CSV. Those rows will be ignored:', 'earthhaul-bulk-pages' )
				. '</strong></p><p>'
				. self::format_slug_list( $orphans ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. '</p><p>'
				. esc_html__( 'If that\'s a typo (e.g. "lake-nona-florida" vs "lake-nona-fl"), fix the CSV and re-upload before running.', 'earthhaul-bulk-pages' )
				. '</p></div>';
		}

		if ( ! empty( $missing ) ) {
			echo '<div class="notice notice-info"><p>'
				. esc_html__( 'Heads up: these cities have no rows in the Neighborhoods CSV. Their cloned pages will keep the template page\'s neighborhoods list.', 'earthhaul-bulk-pages' )
				. '</p><p>'
				. self::format_slug_list( $missing ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. '</p></div>';
		}
	}

	/**
	 * Render a list of slugs as comma-separated `<code>` spans, with each
	 * slug HTML-escaped individually so the outer markup stays intact.
	 */
	private static function format_slug_list( array $slugs ): string {
		$parts = array();
		foreach ( $slugs as $slug ) {
			$parts[] = '<code>' . esc_html( (string) $slug ) . '</code>';
		}
		return implode( ', ', $parts );
	}

	/* -------------------------------------------------------------------- */
	/* CSV template download                                                 */
	/* -------------------------------------------------------------------- */

	/**
	 * Build a nonced admin-post URL that streams a starter CSV with the
	 * exact column headers the upload handlers expect, plus a couple of
	 * example rows the user can edit. Saves the user from looking up the
	 * column names by hand.
	 *
	 * @param string $which 'cities' or 'neighborhoods'.
	 */
	private static function template_download_url( string $which ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::TEMPLATE_DL_ACTION,
					'which'  => $which,
				),
				admin_url( 'admin-post.php' )
			),
			self::TEMPLATE_DL_ACTION
		);
	}

	/**
	 * Stream a starter CSV. Header row matches the columns that
	 * CSV_Importer / Neighborhoods_CSV_Importer require, and 3 example
	 * rows give the user something concrete to edit.
	 */
	public static function handle_template_download(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::TEMPLATE_DL_ACTION );

		$which = isset( $_GET['which'] ) ? sanitize_key( wp_unslash( (string) $_GET['which'] ) ) : '';

		if ( 'cities' === $which ) {
			$filename = 'ehbp-cities-template.csv';
			$rows = array(
				array( 'slug', 'city', 'state' ),
				array( 'apopka-fl', 'Apopka', 'FL' ),
				array( 'kissimmee-fl', 'Kissimmee', 'FL' ),
				array( 'oviedo-fl', 'Oviedo', 'FL' ),
			);
		} elseif ( 'neighborhoods' === $which ) {
			$filename = 'ehbp-neighborhoods-template.csv';
			$rows = array(
				array( 'city_slug', 'neighborhood' ),
				array( 'apopka-fl', 'Wekiva Springs' ),
				array( 'apopka-fl', 'Errol Estate' ),
				array( 'oviedo-fl', 'Alafaya Woods' ),
			);
		} else {
			wp_die( esc_html__( 'Unknown template requested.', 'earthhaul-bulk-pages' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open output stream.', 'earthhaul-bulk-pages' ) );
		}
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}
}
