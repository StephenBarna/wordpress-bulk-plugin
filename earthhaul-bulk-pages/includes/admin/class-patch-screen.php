<?php
/**
 * "Patch Pages" admin screen: copy a marker-tagged Beaver Builder row
 * from a source page onto N target pages, with per-target token
 * substitution.
 *
 * Lives at Bulk Pages -> Patch Pages. Designed for the "I added a new
 * 'Dumpsters for every job' row to the Orlando template, now propagate
 * it to all 80 cloned cities" workflow.
 *
 * Architecture mirrors {@see New_Job_Screen}:
 *
 *   1. Form mode: pick source, marker class, insertion rule, target pages.
 *   2. handle_submit: validate + stash a small job-state transient,
 *      redirect into progress mode.
 *   3. Progress mode: progress bar + AJAX chunked runner that calls
 *      Patch_Engine::apply once per target post.
 *
 * Job state stays trivially small here (no CSV, no neighborhoods, no
 * results-per-target heavyweights), so we keep it all in the transient
 * and don't bother with file-backed storage.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

use EarthHaul\BulkPages\Services\Patch_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Patch_Screen {

	public const PAGE_SLUG          = 'ehbp-patch-pages';
	public const RUN_ACTION         = 'ehbp_run_patch_job';
	public const RUN_NONCE          = 'ehbp_run_patch_job';
	public const STEP_AJAX_ACTION   = 'ehbp_patch_job_step';
	public const DEBUG_LOG_ACTION   = 'ehbp_patch_debug_log';
	public const JOB_STATE_TTL      = HOUR_IN_SECONDS;

	public static function register(): void {
		add_action( 'admin_post_' . self::RUN_ACTION, array( self::class, 'handle_submit' ) );
		add_action( 'wp_ajax_' . self::STEP_AJAX_ACTION, array( self::class, 'handle_ajax_step' ) );
		add_action( 'admin_post_' . self::DEBUG_LOG_ACTION, array( self::class, 'handle_debug_log' ) );
	}

	/* -------------------------------------------------------------------- */
	/* Render                                                                */
	/* -------------------------------------------------------------------- */

	public static function render(): void {
		$bb_active = class_exists( '\FLBuilderModel' );
		$job_id    = isset( $_GET['ehbp_patch_job'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ehbp_patch_job'] ) ) : '';
		$state     = '' !== $job_id ? self::load_job_state( $job_id ) : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Pages - Patch Pages', 'earthhaul-bulk-pages' ); ?></h1>

			<?php if ( ! $bb_active ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Beaver Builder is not active. Activate it before patching pages.', 'earthhaul-bulk-pages' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php settings_errors( 'ehbp_patch' ); ?>

			<?php if ( is_array( $state ) ) : ?>
				<?php self::render_progress( $state ); ?>
				<?php return; ?>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Copy a marker-tagged Beaver Builder row (or rows) from a source page onto N selected target pages. Useful for adding a new section to all cloned location pages without re-running the AI text or image pipelines.', 'earthhaul-bulk-pages' ); ?>
			</p>

			<?php
			$log_url    = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DEBUG_LOG_ACTION ), self::DEBUG_LOG_ACTION );
			$log_exists = self::debug_log_exists();
			?>
			<p>
				<?php if ( $log_exists ) : ?>
					<a class="button" href="<?php echo esc_url( $log_url ); ?>" target="_blank">
						<?php esc_html_e( 'View patch debug log', 'earthhaul-bulk-pages' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DEBUG_LOG_ACTION . '&clear=1' ), self::DEBUG_LOG_ACTION ) ); ?>">
						<?php esc_html_e( 'Clear log', 'earthhaul-bulk-pages' ); ?>
					</a>
					<span class="description" style="margin-left:8px;color:#646970;">
						<?php esc_html_e( 'Records every string the engine touched on the most recent run, with before/after values. Useful when output looks wrong.', 'earthhaul-bulk-pages' ); ?>
					</span>
				<?php else : ?>
					<span class="description" style="color:#646970;">
						<?php esc_html_e( 'No patch debug log yet. Run a patch and a "View debug log" link will appear here.', 'earthhaul-bulk-pages' ); ?>
					</span>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RUN_ACTION ); ?>">
				<?php wp_nonce_field( self::RUN_NONCE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ehbp_patch_source"><?php esc_html_e( 'Source page', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<?php self::render_source_dropdown(); ?>
							<p class="description"><?php esc_html_e( 'The page that holds the marker-tagged row(s) to copy. Defaults to the Orlando location template.', 'earthhaul-bulk-pages' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ehbp_patch_marker_class"><?php esc_html_e( 'Marker class', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<input type="text" name="ehbp_patch_marker_class" id="ehbp_patch_marker_class" class="regular-text" value="<?php echo esc_attr( Patch_Engine::DEFAULT_MARKER_CLASS ); ?>">
							<p class="description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: literal CSS class name */
										__( 'CSS class on the source row(s) to copy (Row Settings -> Advanced -> Class). Default: <code>%s</code>.', 'earthhaul-bulk-pages' ),
										esc_html( Patch_Engine::DEFAULT_MARKER_CLASS )
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Insertion rule', 'earthhaul-bulk-pages' ); ?></th>
						<td>
							<select name="ehbp_patch_insertion_rule" id="ehbp_patch_insertion_rule">
								<option value="append" selected><?php esc_html_e( 'Append at end', 'earthhaul-bulk-pages' ); ?></option>
								<option value="before_anchor"><?php esc_html_e( 'Insert BEFORE anchor row', 'earthhaul-bulk-pages' ); ?></option>
								<option value="after_anchor"><?php esc_html_e( 'Insert AFTER anchor row', 'earthhaul-bulk-pages' ); ?></option>
								<option value="replace_existing"><?php esc_html_e( 'Replace existing patched row (re-run safely)', 'earthhaul-bulk-pages' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Use Replace existing for re-runs (drops any prior copy of the patched row before inserting fresh). Use anchor modes when authoring; pair with a CSS class on a stable target row.', 'earthhaul-bulk-pages' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ehbp_patch_anchor_class"><?php esc_html_e( 'Anchor class', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<input type="text" name="ehbp_patch_anchor_class" id="ehbp_patch_anchor_class" class="regular-text" placeholder="ehbp-neighborhoods">
							<p class="description"><?php esc_html_e( 'Required for the before/after-anchor rules. CSS class on a row, column, or module that already exists on every target page; the new row goes immediately before or after the row that contains it. You can reuse classes you applied during the bulk-clone run (e.g. "ehbp-neighborhoods" on the neighborhoods text module).', 'earthhaul-bulk-pages' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Target pages', 'earthhaul-bulk-pages' ); ?></th>
						<td>
							<?php self::render_target_picker(); ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Patch selected pages', 'earthhaul-bulk-pages' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Source-page dropdown. Same query the New Job screen uses for its
	 * primary template picker; defaults to the Orlando location template.
	 */
	private static function render_source_dropdown(): void {
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

		$default = self::find_orlando_location_id();

		echo '<select name="ehbp_patch_source" id="ehbp_patch_source" required class="regular-text" style="max-width: 520px; width: 100%;">';
		echo '<option value="">' . esc_html__( '-- choose a source page --', 'earthhaul-bulk-pages' ) . '</option>';
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
	 * Checkbox list of every plugin-cloned page (any status), with a
	 * select-all toggle. Patches are usually applied to every cloned
	 * page, but we let the user uncheck individuals for partial runs.
	 */
	private static function render_target_picker(): void {
		$rows = self::query_cloned_targets();
		if ( empty( $rows ) ) {
			echo '<p style="color:#646970;"><em>' . esc_html__( 'No cloned pages yet. Run a New Job first.', 'earthhaul-bulk-pages' ) . '</em></p>';
			return;
		}
		?>
		<div style="margin-bottom:8px;">
			<label>
				<input type="checkbox" id="ehbp-patch-select-all"
					onclick="var b=this.checked;document.querySelectorAll('input[name=\'ehbp_patch_targets[]\']').forEach(function(c){c.checked=b;});" checked>
				<?php
				printf(
					/* translators: %d: count */
					esc_html__( 'Select all %d cloned pages', 'earthhaul-bulk-pages' ),
					count( $rows )
				);
				?>
			</label>
		</div>
		<div style="max-height:320px;overflow:auto;border:1px solid #c3c4c7;padding:10px;background:#fff;">
			<?php foreach ( $rows as $row ) :
				$post_id = (int) $row['ID'];
				$title   = (string) $row['post_title'];
				$slug    = (string) $row['post_name'];
				$status  = (string) $row['post_status'];
				$city    = trim( (string) $row['city_name'] . ( '' !== (string) $row['state'] ? ', ' . $row['state'] : '' ), ', ' );
				?>
				<label style="display:block;margin:2px 0;">
					<input type="checkbox" name="ehbp_patch_targets[]" value="<?php echo (int) $post_id; ?>" checked>
					<strong><?php echo esc_html( $title ); ?></strong>
					<span style="color:#646970;">
						(#<?php echo (int) $post_id; ?>,
						<code><?php echo esc_html( $slug ); ?></code>,
						<?php echo esc_html( $status ); ?><?php
						if ( '' !== $city ) {
							echo ' &middot; ' . esc_html( $city );
						}
						?>)
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Pull cloned pages with their city/state meta for the picker. Same
	 * shape as the Manage Cloned Pages table.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function query_cloned_targets(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					p.ID,
					p.post_title,
					p.post_name,
					p.post_status,
					COALESCE(mc.meta_value, '') AS city_name,
					COALESCE(ms.meta_value, '') AS state
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} mc ON mc.post_id = p.ID AND mc.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = %s
				WHERE p.post_status IN ('publish','draft','private','pending','future')
				ORDER BY p.post_title ASC
				",
				'_ehbp_source_post_id',
				'_ehbp_city_name',
				'_ehbp_state'
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Best-effort default for the source-page dropdown.
	 */
	private static function find_orlando_location_id(): int {
		$candidates = array(
			get_page_by_path( 'locations/orlando-fl' ),
			get_page_by_path( 'orlando-fl' ),
		);
		foreach ( $candidates as $page ) {
			if ( $page && get_post_meta( $page->ID, '_fl_builder_enabled', true ) ) {
				return (int) $page->ID;
			}
		}
		return 0;
	}

	/* -------------------------------------------------------------------- */
	/* Submit                                                                */
	/* -------------------------------------------------------------------- */

	public static function handle_submit(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::RUN_NONCE );

		$source_id     = isset( $_POST['ehbp_patch_source'] ) ? (int) $_POST['ehbp_patch_source'] : 0;
		$marker_class  = isset( $_POST['ehbp_patch_marker_class'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['ehbp_patch_marker_class'] ) )
			: Patch_Engine::DEFAULT_MARKER_CLASS;
		$rule          = isset( $_POST['ehbp_patch_insertion_rule'] )
			? sanitize_key( wp_unslash( (string) $_POST['ehbp_patch_insertion_rule'] ) )
			: 'append';
		$anchor_class  = isset( $_POST['ehbp_patch_anchor_class'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['ehbp_patch_anchor_class'] ) )
			: '';
		$targets_raw   = isset( $_POST['ehbp_patch_targets'] ) && is_array( $_POST['ehbp_patch_targets'] )
			? wp_unslash( (array) $_POST['ehbp_patch_targets'] )
			: array();
		$targets       = array_values( array_filter( array_map( 'intval', $targets_raw ), static fn ( int $id ): bool => $id > 0 ) );

		$errors = array();
		if ( $source_id <= 0 ) {
			$errors[] = __( 'Pick a source page.', 'earthhaul-bulk-pages' );
		}
		if ( '' === $marker_class ) {
			$errors[] = __( 'Marker class is required.', 'earthhaul-bulk-pages' );
		}
		if ( in_array( $rule, array( 'before_anchor', 'after_anchor' ), true ) && '' === $anchor_class ) {
			$errors[] = __( 'Anchor class is required for the chosen insertion rule.', 'earthhaul-bulk-pages' );
		}
		if ( empty( $targets ) ) {
			$errors[] = __( 'Pick at least one target page.', 'earthhaul-bulk-pages' );
		}

		if ( ! empty( $errors ) ) {
			self::flash_errors( $errors );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		$job_id = 'ehbp_patch_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 4, false, false );
		$state  = array(
			'job_id'         => $job_id,
			'source_id'      => $source_id,
			'marker_class'   => $marker_class,
			'insertion_rule' => $rule,
			'anchor_class'   => $anchor_class,
			'targets'        => $targets,
			'progress'       => array(
				'index'     => 0,
				'total'     => count( $targets ),
				'cancelled' => false,
				'started'   => time(),
			),
			'results'        => array(),
		);

		if ( ! self::save_job_state( $job_id, $state ) ) {
			self::flash_errors( array(
				__( 'Could not save patch job state. Try a smaller batch.', 'earthhaul-bulk-pages' ),
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ehbp_patch_job=' . rawurlencode( $job_id ) ) );
		exit;
	}

	/* -------------------------------------------------------------------- */
	/* Progress + AJAX                                                       */
	/* -------------------------------------------------------------------- */

	private static function render_progress( array $state ): void {
		$job_id   = (string) ( $state['job_id'] ?? '' );
		$total    = (int) ( $state['progress']['total'] ?? 0 );
		$index    = (int) ( $state['progress']['index'] ?? 0 );
		$nonce    = wp_create_nonce( self::STEP_AJAX_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<h2><?php esc_html_e( 'Running patch job', 'earthhaul-bulk-pages' ); ?></h2>
		<p>
			<strong><?php esc_html_e( 'Job ID:', 'earthhaul-bulk-pages' ); ?></strong> <code><?php echo esc_html( $job_id ); ?></code><br>
			<strong><?php esc_html_e( 'Source:', 'earthhaul-bulk-pages' ); ?></strong> <?php echo esc_html( get_the_title( (int) ( $state['source_id'] ?? 0 ) ) ); ?>
			&middot; <strong><?php esc_html_e( 'Rule:', 'earthhaul-bulk-pages' ); ?></strong> <?php echo esc_html( (string) $state['insertion_rule'] ); ?>
			&middot; <strong><?php esc_html_e( 'Marker:', 'earthhaul-bulk-pages' ); ?></strong> <code><?php echo esc_html( (string) $state['marker_class'] ); ?></code>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
				&larr; <?php esc_html_e( 'Back to form', 'earthhaul-bulk-pages' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DEBUG_LOG_ACTION ), self::DEBUG_LOG_ACTION ) ); ?>" target="_blank">
				<?php esc_html_e( 'View patch debug log', 'earthhaul-bulk-pages' ); ?>
			</a>
		</p>
		<div id="ehbp-patch-progress" style="background:#e5e5e5;height:24px;border-radius:4px;overflow:hidden;margin:8px 0;">
			<div id="ehbp-patch-progress-bar" style="width:0%;height:100%;background:#2271b1;transition:width .3s;"></div>
		</div>
		<p>
			<span id="ehbp-patch-progress-text"><?php
				printf(
					/* translators: 1: done, 2: total */
					esc_html__( '%1$d / %2$d pages patched', 'earthhaul-bulk-pages' ),
					$index,
					$total
				);
				?></span>
		</p>

		<h3><?php esc_html_e( 'Live log', 'earthhaul-bulk-pages' ); ?></h3>
		<div id="ehbp-patch-log" style="font-family:Menlo,Consolas,monospace;font-size:12px;background:#f6f7f7;border:1px solid #c3c4c7;padding:10px;max-height:320px;overflow:auto;"></div>

		<script>
		(function () {
			var jobId   = <?php echo wp_json_encode( $job_id ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var total   = <?php echo (int) $total; ?>;
			var bar     = document.getElementById('ehbp-patch-progress-bar');
			var text    = document.getElementById('ehbp-patch-progress-text');
			var log     = document.getElementById('ehbp-patch-log');

			function step() {
				var fd = new FormData();
				fd.append('action', <?php echo wp_json_encode( self::STEP_AJAX_ACTION ); ?>);
				fd.append('nonce', nonce);
				fd.append('job_id', jobId);
				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (!json || !json.success) {
							log.insertAdjacentHTML('beforeend', '<div style="color:#c62828;">Error: ' + (json && json.data && json.data.message ? json.data.message : 'unknown') + '</div>');
							return;
						}
						var data = json.data || {};
						if (data.log_html) {
							log.insertAdjacentHTML('beforeend', data.log_html);
							log.scrollTop = log.scrollHeight;
						}
						var done = (data.next_index || 0);
						bar.style.width = (total > 0 ? (done / total * 100).toFixed(1) : 0) + '%';
						text.textContent = done + ' / ' + total + ' pages patched';
						if (data.done) {
							log.insertAdjacentHTML('beforeend', '<div style="color:#2e7d32;font-weight:600;margin-top:8px;">Patch job complete.</div>');
							return;
						}
						setTimeout(step, 100);
					})
					.catch(function (err) {
						log.insertAdjacentHTML('beforeend', '<div style="color:#c62828;">Network error: ' + err + '</div>');
					});
			}
			step();
		})();
		</script>
		<?php
	}

	public static function handle_ajax_step(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		if ( ! check_ajax_referer( self::STEP_AJAX_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'bad_nonce' ), 400 );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['job_id'] ) ) : '';
		$state  = self::load_job_state( $job_id );

		if ( ! is_array( $state ) ) {
			wp_send_json_error( array( 'message' => 'job_not_found_or_expired' ), 404 );
		}

		$index = (int) $state['progress']['index'];
		$total = (int) $state['progress']['total'];
		if ( $index >= $total ) {
			wp_send_json_success( array( 'done' => true, 'next_index' => $index, 'total' => $total ) );
		}

		$target_id = (int) ( $state['targets'][ $index ] ?? 0 );

		$started = microtime( true );
		$summary = Patch_Engine::apply(
			(int) $state['source_id'],
			$target_id,
			array(
				'marker_class'   => (string) $state['marker_class'],
				'insertion_rule' => (string) $state['insertion_rule'],
				'anchor_class'   => (string) $state['anchor_class'],
			)
		);
		$elapsed = round( microtime( true ) - $started, 2 );

		$state['results'][]           = array(
			'target_id' => $target_id,
			'title'     => (string) get_the_title( $target_id ),
			'ok'        => (bool) ( $summary['ok'] ?? false ),
			'count'     => (int) ( $summary['count'] ?? 0 ),
			'message'   => (string) ( $summary['message'] ?? '' ),
			'elapsed_s' => $elapsed,
		);
		$state['progress']['index']   = $index + 1;
		$state['progress']['updated'] = time();
		self::save_job_state( $job_id, $state );

		$line = sprintf(
			'<div>%s <strong>%s</strong> (#%d) &middot; %s &middot; %ss</div>',
			$summary['ok'] ? '<span style="color:#2e7d32;">OK</span>' : '<span style="color:#c62828;">FAIL</span>',
			esc_html( (string) get_the_title( $target_id ) ),
			$target_id,
			esc_html( (string) $summary['message'] ),
			esc_html( number_format( $elapsed, 2 ) )
		);

		wp_send_json_success( array(
			'done'       => $state['progress']['index'] >= $total,
			'next_index' => (int) $state['progress']['index'],
			'total'      => $total,
			'log_html'   => $line,
		) );
	}

	/* -------------------------------------------------------------------- */
	/* State helpers                                                         */
	/* -------------------------------------------------------------------- */

	private static function job_state_key( string $job_id ): string {
		return 'ehbp_patch_state_' . get_current_user_id() . '_' . $job_id;
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

	private static function save_job_state( string $job_id, array $state ): bool {
		return (bool) set_transient( self::job_state_key( $job_id ), $state, self::JOB_STATE_TTL );
	}

	private static function flash_errors( array $errors ): void {
		foreach ( $errors as $msg ) {
			add_settings_error( 'ehbp_patch', 'ehbp_patch_err', (string) $msg, 'error' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	/* -------------------------------------------------------------------- */
	/* Debug log                                                             */
	/* -------------------------------------------------------------------- */

	private static function debug_log_path(): string {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) {
			return '';
		}
		return trailingslashit( $uploads['basedir'] ) . 'ehbp-patch-debug.log';
	}

	private static function debug_log_exists(): bool {
		$path = self::debug_log_path();
		return '' !== $path && file_exists( $path );
	}

	/**
	 * Stream the patch debug log as plain text in the browser. The log
	 * lists every string field the engine touched on the most recent
	 * apply() calls, before/after each substitution phase, so the user
	 * can pinpoint where unexpected output came from.
	 *
	 * Also supports clearing via ?clear=1.
	 */
	public static function handle_debug_log(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::DEBUG_LOG_ACTION );

		$path = self::debug_log_path();
		if ( '' === $path ) {
			wp_die( esc_html__( 'Uploads directory is unavailable.', 'earthhaul-bulk-pages' ) );
		}

		if ( isset( $_GET['clear'] ) ) {
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'No patch debug log on disk yet.', 'earthhaul-bulk-pages' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}
}
