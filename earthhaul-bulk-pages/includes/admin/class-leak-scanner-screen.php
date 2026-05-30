<?php
/**
 * Leak Scanner: walks every cloned page and finds any module/field still
 * containing a source-city token (default "Orlando") that should have been
 * swapped to the city's localized name. Surfaces leaks so the user can
 * fix them manually instead of digging through hundreds of pages by hand.
 *
 * Scope per cloned page:
 *   1. post_title
 *   2. Yoast meta (title, description, focus keyphrase)
 *   3. Every Beaver Builder text / html field (via Layout_Walker)
 *
 * Excluded from the scan: any page whose city_slug begins with the search
 * needle (so the actual Orlando templates are not flagged as leaks).
 *
 * Architecture mirrors the bulk-job runner: a transient holds the
 * job state, a chunked AJAX loop processes ~10 pages per call so a 300-
 * page scan can't blow a hosting proxy timeout.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

use EarthHaul\BulkPages\Services\Layout_Mutator;
use EarthHaul\BulkPages\Services\Layout_Walker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leak_Scanner_Screen {

	public const PAGE_SLUG       = 'ehbp-leak-scanner';
	public const SUBMIT_ACTION   = 'ehbp_leak_scan_submit';
	public const AJAX_ACTION     = 'ehbp_leak_scan_step';
	public const FIX_AJAX_ACTION = 'ehbp_leak_fix_page';
	public const CAPABILITY      = 'manage_options';

	private const TRANSIENT_PREFIX = 'ehbp_leak_scan_';
	private const QUERY_PARAM      = 'ehbp_scan';
	private const CHUNK_SIZE       = 10;
	private const EXCERPT_RADIUS   = 60;

	private const YOAST_META_KEYS = array(
		'_yoast_wpseo_title'    => 'Yoast SEO title',
		'_yoast_wpseo_metadesc' => 'Yoast meta description',
		'_yoast_wpseo_focuskw'  => 'Yoast focus keyphrase',
	);

	public static function register(): void {
		add_action( 'admin_post_' . self::SUBMIT_ACTION, array( self::class, 'handle_submit' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle_ajax_step' ) );
		add_action( 'wp_ajax_' . self::FIX_AJAX_ACTION, array( self::class, 'handle_ajax_fix' ) );
	}

	/* -------------------------------------------------------------------- */
	/* Render                                                               */
	/* -------------------------------------------------------------------- */

	public static function render(): void {
		// In-flight job: hand off to the progress UI which polls the AJAX
		// endpoint and renders results in place once done.
		$job_id = isset( $_GET[ self::QUERY_PARAM ] )
			? sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_PARAM ] ) )
			: '';
		if ( '' !== $job_id ) {
			$state = self::load_state( $job_id );
			if ( ! empty( $state ) ) {
				self::render_progress_or_results( $job_id, $state );
				return;
			}
		}

		self::render_form();
	}

	private static function render_form( string $prev_needle = 'Orlando' ): void {
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Find Leaks', 'earthhaul-bulk-pages' ); ?>
				<?php if ( defined( 'EHBP_VERSION' ) ) : ?>
					<span style="font-size:13px;font-weight:normal;color:#646970;margin-left:8px;">
						<?php echo esc_html( sprintf( __( 'plugin v%s', 'earthhaul-bulk-pages' ), EHBP_VERSION ) ); ?>
					</span>
				<?php endif; ?>
			</h1>

			<?php settings_errors( 'ehbp_leak_scanner' ); ?>

			<p style="max-width:760px;">
				<?php esc_html_e( 'Scan every cloned page for any word that should have been replaced during localization (e.g. "Orlando" still showing up on a Tampa page). Results include page title, where the leak appears, and an edit link so you can fix it manually.', 'earthhaul-bulk-pages' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SUBMIT_ACTION ); ?>">
				<?php wp_nonce_field( self::SUBMIT_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ehbp_leak_needle"><?php esc_html_e( 'Search for word', 'earthhaul-bulk-pages' ); ?></label>
						</th>
						<td>
							<input type="text" id="ehbp_leak_needle" name="needle"
								value="<?php echo esc_attr( $prev_needle ); ?>"
								class="regular-text" required>
							<p class="description">
								<?php esc_html_e( 'Whole-word, case-insensitive. Pages whose city slug begins with this word are skipped (so the Orlando source templates don\'t get flagged).', 'earthhaul-bulk-pages' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Scan all cloned pages', 'earthhaul-bulk-pages' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	private static function render_progress_or_results( string $job_id, array $state ): void {
		$total       = (int) ( $state['total'] ?? 0 );
		$processed   = (int) ( $state['processed'] ?? 0 );
		$matches     = (array) ( $state['matches'] ?? array() );
		$needle      = (string) ( $state['needle'] ?? '' );
		$pages_with  = (int) ( $state['pages_with_matches'] ?? 0 );
		$is_done     = ! empty( $state['done'] );
		$percent     = $total > 0 ? (int) round( ( $processed / $total ) * 100 ) : 0;
		$nonce       = wp_create_nonce( self::AJAX_ACTION );
		$home_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Find Leaks', 'earthhaul-bulk-pages' ); ?>
				<span style="font-size:14px;font-weight:normal;color:#646970;margin-left:8px;">
					<?php
					printf(
						/* translators: %s: search term */
						esc_html__( 'searching for "%s"', 'earthhaul-bulk-pages' ),
						esc_html( $needle )
					);
					?>
				</span>
			</h1>

			<?php settings_errors( 'ehbp_leak_scanner' ); ?>

			<div id="ehbp-leak-progress" style="<?php echo $is_done ? 'display:none;' : ''; ?>">
				<div style="background:#e5e7eb;height:14px;border-radius:4px;overflow:hidden;margin:8px 0;">
					<div id="ehbp-leak-bar"
						style="background:#2271b1;height:100%;width:<?php echo (int) $percent; ?>%;transition:width .2s ease-out;"></div>
				</div>
				<p id="ehbp-leak-counter" style="font-family:Menlo,monospace;color:#1f2328;">
					<?php
					printf(
						/* translators: 1: processed, 2: total, 3: matches */
						esc_html__( '%1$d / %2$d pages scanned (%3$d page(s) with leaks so far)', 'earthhaul-bulk-pages' ),
						$processed,
						$total,
						$pages_with
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( $home_url ); ?>" class="button" id="ehbp-leak-cancel">
						<?php esc_html_e( 'Cancel', 'earthhaul-bulk-pages' ); ?>
					</a>
				</p>
			</div>

			<div id="ehbp-leak-results" style="<?php echo $is_done ? '' : 'display:none;'; ?>">
				<?php self::render_results( $needle, $total, $processed, $matches, $home_url ); ?>
			</div>
		</div>

		<?php if ( ! $is_done ) : ?>
		<script>
		(function () {
			var jobId   = <?php echo wp_json_encode( $job_id ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var bar     = document.getElementById('ehbp-leak-bar');
			var counter = document.getElementById('ehbp-leak-counter');
			var prog    = document.getElementById('ehbp-leak-progress');
			var results = document.getElementById('ehbp-leak-results');
			var cancel  = document.getElementById('ehbp-leak-cancel');
			var stopped = false;

			cancel.addEventListener('click', function () { stopped = true; });

			function step() {
				if (stopped) { return; }
				var body = new FormData();
				body.append('action', '<?php echo esc_js( self::AJAX_ACTION ); ?>');
				body.append('nonce', nonce);
				body.append('job_id', jobId);

				fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (!json || !json.success) {
							counter.textContent = (json && json.data && json.data.message) ? json.data.message : 'Step failed.';
							return;
						}
						var d = json.data;
						bar.style.width = d.percent + '%';
						counter.textContent = d.processed + ' / ' + d.total + ' pages scanned (' +
							d.pages_with_matches + ' page(s) with leaks so far)';

						if (d.done) {
							// Reload to render the full results table from PHP. Cheaper
							// and less error-prone than building it in JS, since the
							// excerpts and links are already escaped server-side.
							window.location.reload();
							return;
						}
						setTimeout(step, 150);
					})
					.catch(function (err) {
						counter.textContent = 'Network error: ' + err.message + '. Refresh this page to resume.';
					});
			}

			step();
		})();
		</script>
		<?php endif; ?>
		<?php
	}

	private static function render_results( string $needle, int $total, int $processed, array $matches, string $home_url ): void {
		$pages_with = count( $matches );
		$total_hits = 0;
		foreach ( $matches as $m ) {
			$total_hits += count( (array) ( $m['hits'] ?? array() ) );
		}
		?>
		<h2><?php esc_html_e( 'Scan complete', 'earthhaul-bulk-pages' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: pages with leaks, 2: total leaks, 3: pages scanned, 4: needle */
				esc_html__( '%1$d page(s) with %2$d leak(s) found across %3$d pages scanned for "%4$s".', 'earthhaul-bulk-pages' ),
				$pages_with,
				$total_hits,
				$processed,
				esc_html( $needle )
			);
			?>
		</p>

		<p>
			<a href="<?php echo esc_url( $home_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Run another scan', 'earthhaul-bulk-pages' ); ?>
			</a>
		</p>

		<?php if ( empty( $matches ) ) : ?>
			<p style="color:#2e7d32;"><strong><?php esc_html_e( 'No leaks found. All cloned pages are clean.', 'earthhaul-bulk-pages' ); ?></strong></p>
			<?php return; ?>
		<?php endif; ?>

		<?php
		$fix_nonce = wp_create_nonce( self::FIX_AJAX_ACTION );
		$ajax_url  = admin_url( 'admin-ajax.php' );
		$needle_js = wp_json_encode( $needle );
		?>
		<table class="widefat striped" id="ehbp-leak-results-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Page', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( 'Leaks', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( 'Where', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( 'Excerpt', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'earthhaul-bulk-pages' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $matches as $m ) :
				$post_id  = (int) ( $m['post_id'] ?? 0 );
				$title    = (string) ( $m['title'] ?? '' );
				$status   = (string) ( $m['status'] ?? '' );
				$edit_url = (string) ( $m['edit_url'] ?? '' );
				$view_url = (string) ( $m['view_url'] ?? '' );
				$hits     = (array) ( $m['hits'] ?? array() );
				$first    = true;
				foreach ( $hits as $hit ) : ?>
					<tr data-post-id="<?php echo (int) $post_id; ?>">
						<?php if ( $first ) : ?>
							<td rowspan="<?php echo (int) count( $hits ); ?>">
								<strong>
									<?php if ( '' !== $edit_url ) : ?>
										<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $title ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $title ); ?>
									<?php endif; ?>
								</strong>
								<div style="color:#646970;font-size:12px;">
									#<?php echo (int) $post_id; ?> &middot; <?php echo esc_html( $status ); ?>
								</div>
							</td>
							<td rowspan="<?php echo (int) count( $hits ); ?>">
								<?php echo (int) count( $hits ); ?>
							</td>
						<?php endif; ?>
						<td style="vertical-align:top;">
							<code style="white-space:nowrap;"><?php echo esc_html( (string) ( $hit['where'] ?? '' ) ); ?></code>
							<?php if ( ! empty( $hit['label'] ) ) : ?>
								<div style="color:#646970;font-size:12px;"><?php echo esc_html( (string) $hit['label'] ); ?></div>
							<?php endif; ?>
						</td>
						<td style="vertical-align:top;max-width:520px;">
							<?php
							echo wp_kses(
								(string) ( $hit['excerpt_html'] ?? '' ),
								array(
									'mark' => array(),
									'br'   => array(),
									'em'   => array(),
									'span' => array( 'style' => true ),
								)
							);
							?>
						</td>
						<?php if ( $first ) : ?>
							<td rowspan="<?php echo (int) count( $hits ); ?>" style="white-space:nowrap;">
								<button type="button"
									class="button button-primary button-small ehbp-leak-fix"
									data-post-id="<?php echo (int) $post_id; ?>"
									title="<?php esc_attr_e( 'Replace the search word with this page\'s actual city name across the title, Yoast meta, and every Beaver Builder text field.', 'earthhaul-bulk-pages' ); ?>">
									<?php esc_html_e( 'Fix', 'earthhaul-bulk-pages' ); ?>
								</button>
								<?php if ( '' !== $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small" style="margin-left:4px;">
										<?php esc_html_e( 'Edit', 'earthhaul-bulk-pages' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( '' !== $view_url ) : ?>
									<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" class="button button-small" style="margin-left:4px;">
										<?php echo 'publish' === $status ? esc_html__( 'View', 'earthhaul-bulk-pages' ) : esc_html__( 'Preview', 'earthhaul-bulk-pages' ); ?>
									</a>
								<?php endif; ?>
							</td>
						<?php endif; $first = false; ?>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
			</tbody>
		</table>

		<script>
		(function () {
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce   = <?php echo wp_json_encode( $fix_nonce ); ?>;
			var needle  = <?php echo $needle_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

			document.querySelectorAll('.ehbp-leak-fix').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var postId = btn.getAttribute('data-post-id');
					if (!postId) { return; }
					if (btn.disabled) { return; }

					btn.disabled    = true;
					btn.textContent = '…';

					var body = new FormData();
					body.append('action', '<?php echo esc_js( self::FIX_AJAX_ACTION ); ?>');
					body.append('nonce', nonce);
					body.append('post_id', postId);
					body.append('needle', needle);

					fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (json) {
							if (!json || !json.success) {
								var msg = (json && json.data && json.data.message) ? json.data.message : 'Fix failed.';
								alert(msg);
								btn.disabled = false;
								btn.textContent = 'Fix';
								return;
							}
							// Remove every row for this post from the table.
							document
								.querySelectorAll('tr[data-post-id="' + postId + '"]')
								.forEach(function (tr) { tr.remove(); });

							// If the table body is empty, show a success line.
							var tbl = document.getElementById('ehbp-leak-results-table');
							if (tbl && !tbl.querySelector('tbody tr')) {
								var note = document.createElement('p');
								note.style.color = '#2e7d32';
								note.style.fontWeight = '600';
								note.style.marginTop = '12px';
								note.textContent = 'All leaks fixed. Re-run a scan to confirm.';
								tbl.parentNode.insertBefore(note, tbl.nextSibling);
								tbl.remove();
							}
						})
						.catch(function (err) {
							alert('Network error: ' + err.message);
							btn.disabled = false;
							btn.textContent = 'Fix';
						});
				});
			});
		})();
		</script>
		<?php
	}

	/* -------------------------------------------------------------------- */
	/* Stage / step / state                                                 */
	/* -------------------------------------------------------------------- */

	public static function handle_submit(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::SUBMIT_ACTION );

		$needle = isset( $_POST['needle'] ) ? trim( wp_unslash( (string) $_POST['needle'] ) ) : '';
		if ( '' === $needle ) {
			add_settings_error(
				'ehbp_leak_scanner',
				'ehbp_leak_no_needle',
				__( 'Enter a word to search for.', 'earthhaul-bulk-pages' ),
				'error'
			);
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&settings-updated=true' ) );
			exit;
		}

		$ids = self::collect_target_post_ids( $needle );
		if ( empty( $ids ) ) {
			add_settings_error(
				'ehbp_leak_scanner',
				'ehbp_leak_no_targets',
				__( 'No cloned pages to scan (other than ones whose city slug begins with the search word, which are skipped).', 'earthhaul-bulk-pages' ),
				'info'
			);
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&settings-updated=true' ) );
			exit;
		}

		$job_id = 'ls_' . wp_generate_password( 8, false, false );
		$state  = array(
			'job_id'             => $job_id,
			'needle'             => $needle,
			'remaining'          => array_values( $ids ),
			'total'              => count( $ids ),
			'processed'          => 0,
			'pages_with_matches' => 0,
			'matches'            => array(),
			'done'               => false,
			'created_at'         => time(),
		);
		self::save_state( $job_id, $state );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE_SLUG,
					self::QUERY_PARAM   => $job_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_ajax_step(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'earthhaul-bulk-pages' ) ), 403 );
		}
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['job_id'] ) ) : '';
		$state  = self::load_state( $job_id );
		if ( empty( $state ) ) {
			wp_send_json_error( array( 'message' => __( 'Scan job not found.', 'earthhaul-bulk-pages' ) ), 404 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$needle = (string) $state['needle'];
		$batch  = array_splice( $state['remaining'], 0, self::CHUNK_SIZE );

		foreach ( $batch as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$page_match = self::scan_post( $id, $needle );
			if ( ! empty( $page_match['hits'] ) ) {
				$state['matches'][]            = $page_match;
				$state['pages_with_matches']   = (int) $state['pages_with_matches'] + 1;
			}
			$state['processed'] = (int) $state['processed'] + 1;
		}

		$state['done'] = empty( $state['remaining'] );
		self::save_state( $job_id, $state );

		wp_send_json_success(
			array(
				'job_id'             => $job_id,
				'total'              => (int) $state['total'],
				'processed'          => (int) $state['processed'],
				'pages_with_matches' => (int) $state['pages_with_matches'],
				'percent'            => (int) $state['total'] > 0 ? (int) round( ( (int) $state['processed'] / (int) $state['total'] ) * 100 ) : 100,
				'done'               => (bool) $state['done'],
			)
		);
	}

	/* -------------------------------------------------------------------- */
	/* Fix one page (whole-word swap of needle -> page's actual city)        */
	/* -------------------------------------------------------------------- */

	/**
	 * AJAX endpoint for the per-row "Fix" button. Reads the page's
	 * `_ehbp_city_name` meta to get the target city, then sweeps through:
	 *   1. post_title
	 *   2. Yoast meta (3 keys)
	 *   3. Every BB text/html field in the layout
	 * doing a whole-word, case-insensitive replacement of $needle with
	 * the page's actual city name, with light case mirroring (UPPER stays
	 * UPPER, lower stays lower, otherwise proper case).
	 *
	 * Side effects:
	 *   - wp_update_post on title change
	 *   - update_post_meta for any Yoast field changes
	 *   - Layout_Mutator::persist_layout for any BB field changes
	 *
	 * Returns a JSON success payload with counts so the JS can confirm
	 * something actually changed before removing the row.
	 */
	public static function handle_ajax_fix(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'earthhaul-bulk-pages' ) ), 403 );
		}
		check_ajax_referer( self::FIX_AJAX_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$needle  = isset( $_POST['needle'] ) ? trim( wp_unslash( (string) $_POST['needle'] ) ) : '';
		if ( $post_id <= 0 || '' === $needle ) {
			wp_send_json_error( array( 'message' => __( 'Missing post_id or needle.', 'earthhaul-bulk-pages' ) ), 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'earthhaul-bulk-pages' ) ), 404 );
		}

		// Defense in depth: only fix posts the plugin cloned. Don't let
		// this endpoint mass-edit unrelated pages.
		if ( '' === (string) get_post_meta( $post_id, '_ehbp_source_post_id', true ) ) {
			wp_send_json_error( array( 'message' => __( 'This post is not a plugin-cloned page; refusing to edit.', 'earthhaul-bulk-pages' ) ), 400 );
		}

		$city = trim( (string) get_post_meta( $post_id, '_ehbp_city_name', true ) );
		if ( '' === $city ) {
			wp_send_json_error( array( 'message' => __( 'This page has no _ehbp_city_name meta. Cannot determine target city.', 'earthhaul-bulk-pages' ) ), 400 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$counts = array(
			'title' => 0,
			'meta'  => 0,
			'bb'    => 0,
		);

		// 1. Post title.
		$old_title = (string) $post->post_title;
		$new_title = self::case_preserving_replace( $old_title, $needle, $city );
		if ( $new_title !== $old_title ) {
			$updated = wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $new_title,
				),
				true
			);
			if ( ! is_wp_error( $updated ) && (int) $updated > 0 ) {
				$counts['title']++;
			}
		}

		// 2. Yoast meta keys.
		foreach ( self::YOAST_META_KEYS as $key => $label ) {
			$old_val = (string) get_post_meta( $post_id, $key, true );
			if ( '' === $old_val ) {
				continue;
			}
			$new_val = self::case_preserving_replace( $old_val, $needle, $city );
			if ( $new_val !== $old_val ) {
				update_post_meta( $post_id, $key, $new_val );
				$counts['meta']++;
			}
		}

		// 3. Beaver Builder layout text/html fields.
		$layout = Layout_Mutator::load_layout( $post_id );
		$walker = Layout_Walker::inspect( $post_id );
		$bb_changes = 0;
		if ( ! empty( $layout ) && ! empty( $walker['nodes'] ) ) {
			foreach ( $walker['nodes'] as $node ) {
				if ( ! empty( $node['is_global'] ) ) {
					continue;
				}
				if ( 'module' !== ( $node['type'] ?? '' ) ) {
					continue;
				}
				$nid = (string) ( $node['node_id'] ?? '' );
				if ( '' === $nid || ! isset( $layout[ $nid ] ) || ! is_object( $layout[ $nid ] ) ) {
					continue;
				}
				if ( empty( $layout[ $nid ]->settings ) ) {
					continue;
				}
				foreach ( (array) ( $node['fields'] ?? array() ) as $field ) {
					$kind = (string) ( $field['kind'] ?? '' );
					if ( 'text' !== $kind && 'html' !== $kind ) {
						continue;
					}
					$old_value = (string) ( $field['value'] ?? '' );
					if ( '' === trim( $old_value ) ) {
						continue;
					}
					$new_value = self::case_preserving_replace( $old_value, $needle, $city );
					if ( $new_value === $old_value ) {
						continue;
					}
					$path = (string) ( $field['path'] ?? '' );
					if ( Layout_Mutator::set_at_path( $layout[ $nid ]->settings, $path, $new_value ) ) {
						$bb_changes++;
					}
				}
			}
		}
		if ( $bb_changes > 0 ) {
			Layout_Mutator::persist_layout( $post_id, $layout );
			$counts['bb'] = $bb_changes;
		}

		$total_changes = (int) $counts['title'] + (int) $counts['meta'] + (int) $counts['bb'];
		if ( 0 === $total_changes ) {
			wp_send_json_error(
				array(
					'message' => __( 'No leaks were actually replaced. The page may have been edited since the scan; rescan to refresh.', 'earthhaul-bulk-pages' ),
				),
				200
			);
		}

		wp_send_json_success(
			array(
				'post_id' => $post_id,
				'city'    => $city,
				'counts'  => $counts,
				'total'   => $total_changes,
			)
		);
	}

	/**
	 * Whole-word, case-insensitive replacement that tries to mirror the
	 * casing of the original match: ALL CAPS stays ALL CAPS, all-lower
	 * stays all-lower, anything else returns $replacement unchanged
	 * (which is typically already proper-cased like "Oak Ridge").
	 */
	private static function case_preserving_replace( string $haystack, string $needle, string $replacement ): string {
		if ( '' === $haystack || '' === $needle ) {
			return $haystack;
		}
		$pattern = '/\b' . preg_quote( $needle, '/' ) . '\b/i';
		$result  = preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $replacement ): string {
				$hit = $m[0];
				// Strip non-letter chars (handles needles like "St. Pete" if ever used).
				$letters_only = preg_replace( '/[^A-Za-z]/', '', $hit );
				if ( '' === $letters_only ) {
					return $replacement;
				}
				if ( ctype_upper( $letters_only ) ) {
					return strtoupper( $replacement );
				}
				if ( ctype_lower( $letters_only ) ) {
					return strtolower( $replacement );
				}
				return $replacement;
			},
			$haystack
		);
		return is_string( $result ) ? $result : $haystack;
	}

	/* -------------------------------------------------------------------- */
	/* Scan one post                                                        */
	/* -------------------------------------------------------------------- */

	/**
	 * Scan post_title + Yoast meta + every BB text/html field for whole-
	 * word case-insensitive matches of $needle. Returns a row shaped for
	 * the results renderer.
	 */
	private static function scan_post( int $post_id, string $needle ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'post_id' => $post_id, 'hits' => array() );
		}

		$hits = array();
		$pattern = '/\b' . preg_quote( $needle, '/' ) . '\b/i';

		// 1. Post title.
		$title = (string) $post->post_title;
		if ( '' !== $title && preg_match( $pattern, $title ) ) {
			$hits[] = array(
				'where'        => 'post_title',
				'label'        => __( 'Page title', 'earthhaul-bulk-pages' ),
				'excerpt_html' => self::build_excerpt( $title, $needle ),
			);
		}

		// 2. Yoast meta keys.
		foreach ( self::YOAST_META_KEYS as $key => $label ) {
			$value = (string) get_post_meta( $post_id, $key, true );
			if ( '' === $value ) {
				continue;
			}
			if ( preg_match( $pattern, $value ) ) {
				$hits[] = array(
					'where'        => $key,
					'label'        => $label,
					'excerpt_html' => self::build_excerpt( $value, $needle ),
				);
			}
		}

		// 3. Beaver Builder text + html fields.
		$walker = Layout_Walker::inspect( $post_id );
		foreach ( (array) ( $walker['nodes'] ?? array() ) as $node ) {
			if ( ! empty( $node['is_global'] ) ) {
				continue;
			}
			if ( 'module' !== ( $node['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $node['fields'] ?? array() ) as $field ) {
				$kind = (string) ( $field['kind'] ?? '' );
				if ( 'text' !== $kind && 'html' !== $kind ) {
					continue;
				}
				$value = (string) ( $field['value'] ?? '' );
				if ( '' === trim( $value ) ) {
					continue;
				}
				$plain = trim( wp_strip_all_tags( $value ) );
				if ( '' === $plain ) {
					continue;
				}
				if ( ! preg_match( $pattern, $plain ) ) {
					continue;
				}
				$slug = (string) ( $node['module_slug'] ?? '' );
				$path = (string) ( $field['path'] ?? '' );
				$node_id = (string) ( $node['node_id'] ?? '' );
				$hits[] = array(
					'where'        => sprintf( 'module:%s:%s', $slug, $path ),
					'label'        => self::field_label( $slug, $path, (string) ( $node['label'] ?? '' ), $node_id ),
					'excerpt_html' => self::build_excerpt( $plain, $needle ),
				);
			}
		}

		$status   = (string) $post->post_status;
		$edit_url = (string) get_edit_post_link( $post_id, 'raw' );
		$view_url = 'publish' === $status
			? (string) get_permalink( $post_id )
			: (string) get_preview_post_link( $post_id );

		return array(
			'post_id'  => $post_id,
			'title'    => $title,
			'status'   => $status,
			'edit_url' => $edit_url,
			'view_url' => $view_url,
			'hits'     => $hits,
		);
	}

	/**
	 * Pull every cloned-post ID we should scan: every post tagged with
	 * `_ehbp_source_post_id` whose city_slug does NOT begin with the
	 * search needle. The slug check skips the actual Orlando templates
	 * (city_slug = "orlando") so they aren't reported as "leaks".
	 *
	 * @return int[]
	 */
	private static function collect_target_post_ids( string $needle ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			"
			SELECT DISTINCT pm.post_id, COALESCE(slug.meta_value, '') AS city_slug
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			LEFT JOIN {$wpdb->postmeta} slug ON slug.post_id = pm.post_id AND slug.meta_key = '_ehbp_city_slug'
			WHERE pm.meta_key = '_ehbp_source_post_id'
			  AND p.post_status IN ('publish','draft','private','pending','future')
			ORDER BY pm.post_id ASC
			",
			ARRAY_A
		);

		$skip_prefix = sanitize_title( $needle );
		$out         = array();
		foreach ( $rows as $row ) {
			$id   = (int) ( $row['post_id'] ?? 0 );
			$slug = (string) ( $row['city_slug'] ?? '' );
			if ( $id <= 0 ) {
				continue;
			}
			// Skip the source-city pages so they don't pollute results.
			if ( '' !== $skip_prefix && '' !== $slug && 0 === stripos( $slug, $skip_prefix ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/* -------------------------------------------------------------------- */
	/* Helpers                                                              */
	/* -------------------------------------------------------------------- */

	/**
	 * Build a short, human-readable excerpt that highlights every match
	 * of $needle inside $text with <mark> tags. Truncates around the
	 * first match so we don't dump 5KB of HTML per row.
	 */
	private static function build_excerpt( string $text, string $needle ): string {
		$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) ?? '' );
		if ( '' === $plain ) {
			return '';
		}
		$pos = stripos( $plain, $needle );
		if ( false === $pos ) {
			$snippet = mb_substr( $plain, 0, self::EXCERPT_RADIUS * 3 );
			return esc_html( $snippet );
		}
		$start  = max( 0, $pos - self::EXCERPT_RADIUS );
		$length = ( self::EXCERPT_RADIUS * 2 ) + mb_strlen( $needle );
		$snippet = mb_substr( $plain, $start, $length );
		if ( $start > 0 ) {
			$snippet = '…' . $snippet;
		}
		if ( $start + $length < mb_strlen( $plain ) ) {
			$snippet .= '…';
		}
		// Highlight every whole-word match.
		$escaped = esc_html( $snippet );
		$escaped = preg_replace_callback(
			'/\b' . preg_quote( $needle, '/' ) . '\b/i',
			static fn ( array $m ): string => '<mark style="background:#fef3c7;color:#92400e;padding:0 2px;">' . $m[0] . '</mark>',
			$escaped
		) ?? $escaped;
		return $escaped;
	}

	private static function field_label( string $module_slug, string $path, string $node_label, string $node_id ): string {
		$pretty = '' !== $node_label ? $node_label : $module_slug;
		$where  = '' !== $path ? $path : '(root)';
		$tail   = '' !== $node_id ? ' (#' . $node_id . ')' : '';
		return sprintf( '%s — %s%s', $pretty, $where, $tail );
	}

	private static function save_state( string $job_id, array $state ): void {
		set_transient( self::TRANSIENT_PREFIX . $job_id, $state, 2 * HOUR_IN_SECONDS );
	}

	private static function load_state( string $job_id ): array {
		$state = get_transient( self::TRANSIENT_PREFIX . $job_id );
		return is_array( $state ) ? $state : array();
	}
}
