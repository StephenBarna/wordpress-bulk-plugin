<?php
/**
 * "Rewrite Page" admin screen.
 *
 * Phase 1b.ii flow:
 *   Step 1 (this file): pick a page, confirm source city + target city,
 *                       click Generate Rewrites. Plugin walks the layout,
 *                       calls OpenAI per field, stores suggestions in a
 *                       transient keyed to (post_id, target_city), and
 *                       redirects to step 2.
 *   Step 2: shows side-by-side diff (original vs suggestion) with a
 *           checkbox per field. User unchecks anything they don't want.
 *   Step 3: Apply Selected writes the approved values back to the BB
 *           layout via FLBuilderModel::update_layout_data and
 *           FLBuilderModel::save_layout for the draft revision.
 *
 * Globals are never touched - the walker already excludes them.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

use EarthHaul\BulkPages\Services\Layout_Walker;
use EarthHaul\BulkPages\Services\Page_Cloner;
use EarthHaul\BulkPages\Services\Rewrite_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rewrite_Screen {

	public const PAGE_SLUG       = 'ehbp-rewrite';
	public const GENERATE_ACTION = 'ehbp_rewrite_generate';
	public const APPLY_ACTION    = 'ehbp_rewrite_apply';
	public const TRANSIENT_TTL   = 3600; // Suggestions live 1h after generation.

	public static function register(): void {
		add_action( 'admin_post_' . self::GENERATE_ACTION, array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_' . self::APPLY_ACTION, array( __CLASS__, 'handle_apply' ) );
	}

	public static function render(): void {
		$post_id = isset( $_GET['ehbp_post_id'] ) ? (int) $_GET['ehbp_post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step    = ( $post_id > 0 && false !== get_transient( self::transient_key( $post_id ) ) ) ? 'review' : 'pick';

		// One-shot: backfill _ehbp_city_name / _ehbp_state on cloned drafts
		// that pre-date that metadata being captured. Cheap after first run.
		self::backfill_city_meta();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Pages - Rewrite Page', 'earthhaul-bulk-pages' ); ?></h1>
			<?php settings_errors( 'ehbp_rewrite' ); ?>

			<?php if ( ! class_exists( '\FLBuilderModel' ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Beaver Builder is not active.', 'earthhaul-bulk-pages' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( ! Settings_Page::has_api_key() ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: settings link */
						wp_kses( __( 'You need an OpenAI API key configured first. <a href="%s">Open Settings</a>.', 'earthhaul-bulk-pages' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( admin_url( 'admin.php?page=' . Admin_Menu::SETTINGS_PAGE_SLUG ) )
					);
					?>
				</p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( 'review' === $step ) : ?>
				<?php self::render_review( $post_id ); ?>
			<?php else : ?>
				<?php self::render_picker( $post_id ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* -------------------------------------------------------------------- */
	/* Step 1: pick a page + target city                                    */
	/* -------------------------------------------------------------------- */

	private static function render_picker( int $post_id ): void {
		$post   = $post_id > 0 ? get_post( $post_id ) : null;
		$city   = $post ? (string) get_post_meta( $post_id, Page_Cloner::META_CITY_NAME, true ) : '';
		$state  = $post ? (string) get_post_meta( $post_id, Page_Cloner::META_STATE, true ) : '';
		$target_label = trim( $city . ( $state !== '' ? ', ' . $state : '' ) );
		?>
		<p><?php esc_html_e( 'Step 1: choose a draft page, confirm the source / target city, and generate AI rewrites. We only call the API for fields that contain a localizable reference; static button labels and similar are left alone automatically.', 'earthhaul-bulk-pages' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::GENERATE_ACTION ); ?>">
			<?php wp_nonce_field( self::GENERATE_ACTION ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ehbp_post_id"><?php esc_html_e( 'Page to rewrite', 'earthhaul-bulk-pages' ); ?></label></th>
					<td>
						<?php self::render_page_dropdown( $post_id ); ?>
						<p class="description"><?php esc_html_e( 'Pick one of your cloned drafts. The rewrite writes back to its draft layout - the published version is never modified.', 'earthhaul-bulk-pages' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ehbp_source_label"><?php esc_html_e( 'Source city / state', 'earthhaul-bulk-pages' ); ?></label></th>
					<td>
						<input type="text" name="source_label" id="ehbp_source_label" class="regular-text" value="<?php echo esc_attr( 'Orlando, FL' ); ?>">
						<p class="description"><?php esc_html_e( 'The city/state currently mentioned in the original copy. Defaults to "Orlando, FL" since that is your template page.', 'earthhaul-bulk-pages' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ehbp_target_label"><?php esc_html_e( 'Target city / state', 'earthhaul-bulk-pages' ); ?></label></th>
					<td>
						<input type="text" name="target_label" id="ehbp_target_label" class="regular-text" value="<?php echo esc_attr( $target_label ); ?>" placeholder="Tampa, FL">
						<p class="description"><?php esc_html_e( 'Pre-filled from the draft\'s metadata if it was created via a bulk job.', 'earthhaul-bulk-pages' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Generate Rewrites', 'earthhaul-bulk-pages' ) ); ?>
			<p class="description"><em><?php esc_html_e( 'This may take 30-90 seconds depending on the page size. Do not close the tab until it finishes.', 'earthhaul-bulk-pages' ); ?></em></p>
		</form>
		<?php
	}

	private static function render_page_dropdown( int $selected_id ): void {
		global $wpdb;

		// Pull page metadata along with the saved city/state so the picker can
		// auto-populate the Target field client-side without a round-trip.
		$results = $wpdb->get_results(
			"
			SELECT p.ID, p.post_title, p.post_status,
			       MAX(CASE WHEN m.meta_key = '_ehbp_city_name' THEN m.meta_value END) AS ehbp_city,
			       MAX(CASE WHEN m.meta_key = '_ehbp_state' THEN m.meta_value END) AS ehbp_state
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_fl_builder_enabled' AND pm.meta_value = '1'
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_ehbp_city_name','_ehbp_state')
			WHERE p.post_type IN ('page','post') AND p.post_status IN ('publish','draft','private','future')
			GROUP BY p.ID, p.post_title, p.post_status
			ORDER BY p.post_status DESC, p.post_title ASC
			LIMIT 1000
			"
		);

		echo '<select name="ehbp_post_id" id="ehbp_post_id" class="regular-text" style="max-width: 520px; width: 100%;">';
		echo '<option value="0" data-city="" data-state="">' . esc_html__( '-- choose a page --', 'earthhaul-bulk-pages' ) . '</option>';
		foreach ( $results as $row ) {
			printf(
				'<option value="%1$d" data-city="%5$s" data-state="%6$s" %2$s>%3$s (#%1$d, %4$s)</option>',
				(int) $row->ID,
				selected( $selected_id, (int) $row->ID, false ),
				esc_html( $row->post_title ),
				esc_html( $row->post_status ),
				esc_attr( (string) ( $row->ehbp_city ?? '' ) ),
				esc_attr( (string) ( $row->ehbp_state ?? '' ) )
			);
		}
		echo '</select>';

		// Inline updater: when the user changes the page, prefill the Target
		// field. Wrapped in DOMContentLoaded because this script tag is
		// emitted *before* the Target input exists in the DOM (we render
		// the dropdown row first and the target row second).
		?>
		<script>
		(function(){
			function init(){
				var sel = document.getElementById('ehbp_post_id');
				var target = document.getElementById('ehbp_target_label');
				if (!sel || !target) return;
				var lastAuto = target.value.trim();

				function parseLabel(label) {
					if (!label) return '';
					var stripped = label.replace(/\s*\(#\d+,\s*[a-z]+\)\s*$/i, '').trim();
					if (stripped.indexOf(',') === -1) return '';
					var parts = stripped.split(',').map(function(p){ return p.trim(); });
					if (parts.length < 2) return '';
					var state = parts.pop();
					var city = parts[parts.length - 1];
					var m = city.match(/\b(?:in|at|for|near)\s+(.+)$/i);
					if (m) city = m[1].trim();
					if (state.length > 25 || !/^[A-Za-z\s.'-]+$/.test(state)) return '';
					return city + ', ' + state;
				}

				function deriveLabel(opt) {
					if (!opt) return '';
					var city = (opt.getAttribute('data-city') || '').trim();
					var state = (opt.getAttribute('data-state') || '').trim();
					if (city) return city + (state ? ', ' + state : '');
					return parseLabel(opt.textContent || opt.innerText || '');
				}

				sel.addEventListener('change', function(){
					var opt = sel.options[sel.selectedIndex];
					var label = deriveLabel(opt);
					if (target.value.trim() === '' || target.value.trim() === lastAuto) {
						target.value = label;
						lastAuto = label;
					}
				});

				// If the dropdown already has a non-zero selection on initial
				// load (e.g. user navigated here with ?ehbp_post_id=NNN) and
				// the Target field is empty, prefill it once.
				if (sel.value && sel.value !== '0' && target.value.trim() === '') {
					var initLabel = deriveLabel(sel.options[sel.selectedIndex]);
					if (initLabel) {
						target.value = initLabel;
						lastAuto = initLabel;
					}
				}
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		</script>
		<?php
	}

	/**
	 * Backfill `_ehbp_city_name` / `_ehbp_state` post meta on cloned drafts
	 * that don't have it yet, by parsing their post title. Older drafts
	 * created before Page_Cloner started writing those meta keys would
	 * otherwise have nothing for the dropdown to auto-populate from.
	 *
	 * Conservative: only touches posts tagged with `_ehbp_source_post_id`
	 * (i.e. created via this plugin), and only when the city meta is missing.
	 */
	private static function backfill_city_meta(): void {
		global $wpdb;
		$rows = $wpdb->get_results(
			"
			SELECT p.ID, p.post_title
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_ehbp_source_post_id'
			LEFT JOIN {$wpdb->postmeta} city ON city.post_id = p.ID AND city.meta_key = '_ehbp_city_name'
			WHERE city.meta_id IS NULL
			LIMIT 500
			"
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$parsed = self::parse_city_state_from_title( (string) $row->post_title );
			if ( '' === $parsed['city'] ) {
				continue;
			}
			update_post_meta( (int) $row->ID, Page_Cloner::META_CITY_NAME, $parsed['city'] );
			if ( '' !== $parsed['state'] ) {
				update_post_meta( (int) $row->ID, Page_Cloner::META_STATE, $parsed['state'] );
			}
		}
	}

	/**
	 * Pull a "City, State" pair out of a post title.
	 *
	 * Strategy: split on commas, take the last segment as state and the one
	 * before it as the candidate city. If the candidate starts with
	 * boilerplate like "Roll-Off Dumpster Rentals in " or "Concrete Dumpster
	 * Rental in", strip everything up to and including the preposition.
	 *
	 * Handles all of these correctly:
	 *   "Celebration, Florida"
	 *   "Roll-Off Dumpster Rentals in Celebration, Florida"
	 *   "Concrete Dumpster Rental in Fort Lauderdale, FL"
	 *   "St. Augustine, FL"
	 *
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

		// Defensive: if state is implausible (longer than 25 chars or has
		// non-letter chars beyond hyphen), bail rather than persist garbage.
		if ( strlen( $state ) > 25 || ! preg_match( '/^[A-Za-z\s.\'-]+$/', $state ) ) {
			return array( 'city' => '', 'state' => '' );
		}

		return array(
			'city'  => $city,
			'state' => $state,
		);
	}

	/* -------------------------------------------------------------------- */
	/* Generate handler: walk + call OpenAI + store transient + redirect    */
	/* -------------------------------------------------------------------- */

	public static function handle_generate(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::GENERATE_ACTION );

		$post_id      = isset( $_POST['ehbp_post_id'] ) ? (int) $_POST['ehbp_post_id'] : 0;
		$source_label = isset( $_POST['source_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source_label'] ) ) : '';
		$target_label = isset( $_POST['target_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_label'] ) ) : '';

		if ( $post_id <= 0 || '' === $target_label ) {
			self::add_error( __( 'Please choose a page and provide a target city.', 'earthhaul-bulk-pages' ) );
			self::redirect_back( 0 );
		}

		$walker = Layout_Walker::inspect( $post_id );
		if ( empty( $walker['nodes'] ) ) {
			self::add_error( __( 'No Beaver Builder layout found on that page.', 'earthhaul-bulk-pages' ) );
			self::redirect_back( $post_id );
		}

		$candidates = Rewrite_Engine::collect_candidates( $walker, $source_label );
		if ( empty( $candidates ) ) {
			self::add_error( __( 'No rewriteable text/html fields found.', 'earthhaul-bulk-pages' ) );
			self::redirect_back( $post_id );
		}

		$results = Rewrite_Engine::run( $candidates, $source_label, $target_label );

		set_transient(
			self::transient_key( $post_id ),
			array(
				'generated_at' => time(),
				'source_label' => $source_label,
				'target_label' => $target_label,
				'results'      => $results,
			),
			self::TRANSIENT_TTL
		);

		self::redirect_back( $post_id );
	}

	/* -------------------------------------------------------------------- */
	/* Step 2: review diff                                                  */
	/* -------------------------------------------------------------------- */

	private static function render_review( int $post_id ): void {
		$post   = get_post( $post_id );
		$bundle = get_transient( self::transient_key( $post_id ) );
		if ( ! $post || ! is_array( $bundle ) ) {
			echo '<p>' . esc_html__( 'Suggestions expired. Generate again.', 'earthhaul-bulk-pages' ) . '</p>';
			return;
		}

		$results       = $bundle['results'] ?? array();
		$source_label  = (string) ( $bundle['source_label'] ?? '' );
		$target_label  = (string) ( $bundle['target_label'] ?? '' );
		$grouped       = self::group_by_row( $results );

		$counts = array(
			'total'    => count( $results ),
			'rewrite'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'changed'  => 0,
			'lint'     => 0,
		);
		foreach ( $results as $r ) {
			if ( ! empty( $r['error'] ) ) {
				$counts['errors']++;
			} elseif ( ! empty( $r['skipped'] ) ) {
				$counts['skipped']++;
			} else {
				$counts['rewrite']++;
				if ( trim( (string) $r['suggestion'] ) !== trim( (string) $r['original'] ) ) {
					$counts['changed']++;
				}
				if ( ! empty( $r['lint'] ) ) {
					$counts['lint']++;
				}
			}
		}

		$reset_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'       => self::GENERATE_ACTION,
					'ehbp_post_id' => $post_id,
					'reset'        => '1',
				),
				admin_url( 'admin-post.php' )
			),
			self::GENERATE_ACTION
		);
		?>
		<h2 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<?php echo esc_html( sprintf( __( 'Review: %1$s -> %2$s', 'earthhaul-bulk-pages' ), $source_label, $target_label ) ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( '← Pick a different page', 'earthhaul-bulk-pages' ); ?></a>
		</h2>
		<p>
			<strong><?php echo esc_html( $post->post_title ); ?></strong>
			(#<?php echo (int) $post_id; ?>, <?php echo esc_html( $post->post_status ); ?>)
		</p>

		<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-bottom:16px;display:flex;gap:24px;flex-wrap:wrap;">
			<div><strong><?php esc_html_e( 'Total fields', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $counts['total']; ?></div>
			<div><strong style="color:#2e7d32;"><?php esc_html_e( 'Rewritten', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $counts['rewrite']; ?> (<?php echo (int) $counts['changed']; ?> <?php esc_html_e( 'changed', 'earthhaul-bulk-pages' ); ?>)</div>
			<div><strong><?php esc_html_e( 'Skipped (static)', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $counts['skipped']; ?></div>
			<?php if ( $counts['lint'] > 0 ) : ?>
				<div><strong style="color:#b26500;"><?php esc_html_e( 'Lint warnings', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $counts['lint']; ?></div>
			<?php endif; ?>
			<?php if ( $counts['errors'] > 0 ) : ?>
				<div><strong style="color:#b71c1c;"><?php esc_html_e( 'Errors', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $counts['errors']; ?></div>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::APPLY_ACTION ); ?>">
			<input type="hidden" name="ehbp_post_id" value="<?php echo (int) $post_id; ?>">
			<?php wp_nonce_field( self::APPLY_ACTION ); ?>

			<p style="margin-top:8px;">
				<label><input type="checkbox" id="ehbp-toggle-all" checked> <?php esc_html_e( 'Toggle all', 'earthhaul-bulk-pages' ); ?></label>
				&nbsp; &nbsp;
				<label><input type="checkbox" id="ehbp-toggle-changed-only"> <?php esc_html_e( 'Show only changed', 'earthhaul-bulk-pages' ); ?></label>
			</p>

			<table class="widefat striped" id="ehbp-diff-table">
				<thead>
					<tr>
						<th style="width:36px;"></th>
						<th style="width:160px;"><?php esc_html_e( 'Module / Field', 'earthhaul-bulk-pages' ); ?></th>
						<th><?php esc_html_e( 'Original', 'earthhaul-bulk-pages' ); ?></th>
						<th><?php esc_html_e( 'Suggested', 'earthhaul-bulk-pages' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $grouped as $row_id => $row_results ) : ?>
					<tr style="background:#eef4ff;"><td colspan="4"><strong><?php esc_html_e( 'Section', 'earthhaul-bulk-pages' ); ?></strong> <code style="font-size:11px;color:#666;"><?php echo esc_html( (string) $row_id ); ?></code></td></tr>
					<?php foreach ( $row_results as $idx => $r ) : ?>
						<?php
						$idx_str   = (string) array_search( $r, $results, true );
						$same      = trim( (string) ( $r['suggestion'] ?? '' ) ) === trim( (string) ( $r['original'] ?? '' ) );
						$has_error = ! empty( $r['error'] );
						$skipped   = ! empty( $r['skipped'] );
						$lint      = (array) ( $r['lint'] ?? array() );
						$bg        = $has_error ? '#fff0f0' : ( $skipped ? '#fafafa' : ( $same ? '' : '#f3fff3' ) );
						$apply_id  = self::field_id( $r );
						?>
						<tr data-changed="<?php echo $same ? '0' : '1'; ?>" style="background:<?php echo esc_attr( $bg ); ?>;vertical-align:top;">
							<td>
								<?php if ( $has_error ) : ?>
									<span style="color:#b71c1c;font-weight:600;" title="<?php echo esc_attr( (string) $r['error'] ); ?>">!</span>
								<?php else : ?>
									<input type="checkbox" class="ehbp-row-check" name="apply[]" value="<?php echo esc_attr( $apply_id ); ?>" <?php checked( ! $skipped && ! $same ); ?> <?php disabled( $skipped || $same ); ?>>
								<?php endif; ?>
							</td>
							<td style="font-size:12px;">
								<div><strong><?php echo esc_html( (string) $r['module_slug'] ); ?></strong></div>
								<div style="color:#666;"><code style="font-size:11px;"><?php echo esc_html( (string) $r['path'] ); ?></code></div>
								<div style="color:#888;font-size:11px;">role: <?php echo esc_html( (string) $r['role'] ); ?></div>
								<?php if ( $skipped ) : ?>
									<div style="color:#888;font-style:italic;font-size:11px;"><?php esc_html_e( 'static (skipped)', 'earthhaul-bulk-pages' ); ?></div>
								<?php endif; ?>
								<?php foreach ( $lint as $issue ) : ?>
									<div style="color:#b26500;font-size:11px;">⚠ <?php echo esc_html( $issue ); ?></div>
								<?php endforeach; ?>
							</td>
							<td style="font-size:13px;width:42%;"><pre style="white-space:pre-wrap;margin:0;font-family:inherit;background:transparent;border:none;padding:0;"><?php echo esc_html( (string) $r['original'] ); ?></pre></td>
							<td style="font-size:13px;width:42%;">
								<?php if ( $has_error ) : ?>
									<em style="color:#b71c1c;"><?php echo esc_html( (string) $r['error'] ); ?></em>
								<?php else : ?>
									<pre style="white-space:pre-wrap;margin:0;font-family:inherit;background:transparent;border:none;padding:0;"><?php echo esc_html( (string) $r['suggestion'] ); ?></pre>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:16px;">
				<?php submit_button( __( 'Apply Selected to Draft', 'earthhaul-bulk-pages' ), 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( $reset_url ); ?>" onclick="return confirm('Discard these suggestions and start over?');"><?php esc_html_e( 'Discard suggestions', 'earthhaul-bulk-pages' ); ?></a>
			</p>
		</form>

		<script>
		(function(){
			var toggleAll = document.getElementById('ehbp-toggle-all');
			var changedOnly = document.getElementById('ehbp-toggle-changed-only');
			var table = document.getElementById('ehbp-diff-table');
			if (!table) return;
			toggleAll && toggleAll.addEventListener('change', function(){
				table.querySelectorAll('input.ehbp-row-check:not(:disabled)').forEach(function(cb){ cb.checked = toggleAll.checked; });
			});
			changedOnly && changedOnly.addEventListener('change', function(){
				table.querySelectorAll('tbody tr[data-changed]').forEach(function(tr){
					tr.style.display = (changedOnly.checked && tr.getAttribute('data-changed') === '0') ? 'none' : '';
				});
			});
		})();
		</script>
		<?php
	}

	/* -------------------------------------------------------------------- */
	/* Apply handler: write approved values back into BB draft layout       */
	/* -------------------------------------------------------------------- */

	public static function handle_apply(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::APPLY_ACTION );

		$post_id  = isset( $_POST['ehbp_post_id'] ) ? (int) $_POST['ehbp_post_id'] : 0;
		$selected = isset( $_POST['apply'] ) && is_array( $_POST['apply'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['apply'] ) )
			: array();

		if ( $post_id <= 0 || empty( $selected ) ) {
			self::add_error( __( 'Nothing to apply.', 'earthhaul-bulk-pages' ) );
			self::redirect_back( $post_id );
		}

		$bundle = get_transient( self::transient_key( $post_id ) );
		if ( ! is_array( $bundle ) || empty( $bundle['results'] ) ) {
			self::add_error( __( 'Suggestions have expired. Generate again.', 'earthhaul-bulk-pages' ) );
			self::redirect_back( $post_id );
		}

		$selected_set = array_flip( $selected );

		// Use the published layout as the source of truth - that's what the
		// live URL renders. Falling back to draft only if published is empty
		// (rare; means the page was never published in BB).
		$layout = \FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( empty( $layout ) ) {
			$layout = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		}
		if ( empty( $layout ) || ! is_array( $layout ) ) {
			self::add_error( __( 'Could not load BB layout.', 'earthhaul-bulk-pages' ) );
			self::redirect_back( $post_id );
		}

		$applied = 0;
		$failed  = 0;

		foreach ( $bundle['results'] as $r ) {
			$id = self::field_id( $r );
			if ( ! isset( $selected_set[ $id ] ) ) {
				continue;
			}
			if ( ! empty( $r['error'] ) || ! empty( $r['skipped'] ) ) {
				continue;
			}
			$node_id = (string) $r['node_id'];
			$path    = (string) $r['path'];
			$value   = (string) $r['suggestion'];

			if ( ! isset( $layout[ $node_id ] ) || ! is_object( $layout[ $node_id ] ) || ! isset( $layout[ $node_id ]->settings ) ) {
				$failed++;
				continue;
			}
			if ( self::set_at_path( $layout[ $node_id ]->settings, $path, $value ) ) {
				$applied++;
			} else {
				$failed++;
			}
		}

		// Write the same modified layout to BOTH BB slots so:
		//   - draft slot     -> what the BB editor shows (so it matches live)
		//   - published slot -> what the live URL serves
		// This avoids the "I have to open the BB editor and click Publish on
		// every page" round-trip after applying.
		\FLBuilderModel::update_layout_data( $layout, 'draft', $post_id );
		\FLBuilderModel::update_layout_data( $layout, 'published', $post_id );

		// Bust BB's per-post asset cache so the next page load regenerates
		// the CSS/JS files instead of serving the stale ones.
		if ( method_exists( '\FLBuilderModel', 'delete_asset_cache' ) ) {
			\FLBuilderModel::delete_asset_cache( $post_id );
		}
		if ( method_exists( '\FLBuilderModel', 'delete_asset_cache_for_all_posts' ) ) {
			// no-op locally if not needed; defensive in case BB caches globally.
		}

		delete_transient( self::transient_key( $post_id ) );

		self::add_notice(
			sprintf(
				/* translators: 1: applied count, 2: failed count, 3: post permalink */
				__( 'Applied %1$d rewrites to both draft and published layouts. %2$d failed. The live page at %3$s should reflect the changes immediately.', 'earthhaul-bulk-pages' ),
				$applied,
				$failed,
				get_permalink( $post_id )
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ehbp_post_id=' . $post_id . '&settings-updated=true' ) );
		exit;
	}

	/* -------------------------------------------------------------------- */
	/* Helpers                                                              */
	/* -------------------------------------------------------------------- */

	/**
	 * Walk a settings object/array along a dot-path like
	 * `faq_items.0.faq_question` and write the value at the leaf. Uses
	 * by-reference traversal because BB nests plain arrays inside settings
	 * objects (e.g. faq_items, list_items, button-group items) and PHP
	 * does not propagate writes through copied subarrays.
	 *
	 * @param mixed  $target Reference into a node's settings object/array.
	 * @return bool          True if the leaf was written successfully.
	 */
	private static function set_at_path( &$target, string $path, string $value ): bool {
		if ( $target === null ) {
			return false;
		}
		$parts = explode( '.', $path );
		$last  = array_pop( $parts );

		$cursor = &$target;
		foreach ( $parts as $key ) {
			if ( is_object( $cursor ) ) {
				if ( ! isset( $cursor->$key ) ) {
					return false;
				}
				$cursor = &$cursor->$key;
				continue;
			}
			if ( is_array( $cursor ) ) {
				if ( ! array_key_exists( $key, $cursor ) ) {
					return false;
				}
				$cursor = &$cursor[ $key ];
				continue;
			}
			return false;
		}
		if ( is_object( $cursor ) ) {
			$cursor->$last = $value;
			return true;
		}
		if ( is_array( $cursor ) ) {
			$cursor[ $last ] = $value;
			return true;
		}
		return false;
	}

	/**
	 * Stable opaque ID for a candidate field. Used as the value for the
	 * Apply checkboxes so we can re-look-up the candidate after submit.
	 */
	private static function field_id( array $candidate ): string {
		return (string) $candidate['node_id'] . '|' . (string) $candidate['path'];
	}

	private static function group_by_row( array $results ): array {
		$out = array();
		foreach ( $results as $r ) {
			$row = (string) ( $r['row_id'] ?? '' );
			if ( ! isset( $out[ $row ] ) ) {
				$out[ $row ] = array();
			}
			$out[ $row ][] = $r;
		}
		return $out;
	}

	private static function transient_key( int $post_id ): string {
		return 'ehbp_rewrite_' . $post_id;
	}

	private static function add_error( string $message ): void {
		add_settings_error( 'ehbp_rewrite', 'ehbp_rewrite_error', $message, 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	private static function add_notice( string $message ): void {
		add_settings_error( 'ehbp_rewrite', 'ehbp_rewrite_notice', $message, 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	private static function redirect_back( int $post_id ): void {
		// `settings-updated=true` is required for WP's settings_errors() to
		// pick up our flash messages from the transient after the redirect.
		$args = array( 'page' => self::PAGE_SLUG, 'settings-updated' => 'true' );
		if ( $post_id > 0 ) {
			$args['ehbp_post_id'] = $post_id;
		}
		wp_safe_redirect( admin_url( 'admin.php?' . http_build_query( $args ) ) );
		exit;
	}
}
