<?php
/**
 * Registers the top-level "Bulk Pages" admin menu and its submenu items.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Menu {

	public const MENU_SLUG            = 'ehbp-bulk-pages';
	public const SETTINGS_PAGE_SLUG   = 'ehbp-settings';
	public const NEW_JOB_PAGE_SLUG    = 'ehbp-new-job';
	public const INSPECT_PAGE_SLUG    = 'ehbp-inspect';
	public const CAPABILITY           = 'manage_options';
	public const RESET_ACTION         = 'ehbp_reset_drafts';
	public const PUBLISH_ACTION       = 'ehbp_publish_drafts';
	public const BULK_ACTION          = 'ehbp_bulk_clones';
	public const DASH_AJAX_STEP       = 'ehbp_dash_step';
	private const DASH_JOB_TRANSIENT  = 'ehbp_dash_job_';
	private const DASH_JOB_QUERY_PARAM = 'ehbp_dash_job';

	/**
	 * How many posts each AJAX chunk processes before returning to the
	 * browser. Keep small enough that web-server / WAF timeouts cannot
	 * fire mid-chunk - each iteration triggers Yoast post-save hooks,
	 * sitemap rebuilds, and (for delete) cascade attachment removal,
	 * any of which can be slow on a busy host.
	 */
	private const DASH_CHUNK_SIZE     = 8;

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( self::class, 'handle_reset' ) );
		add_action( 'admin_post_' . self::PUBLISH_ACTION, array( self::class, 'handle_publish_drafts' ) );
		add_action( 'admin_post_' . self::BULK_ACTION, array( self::class, 'handle_bulk_action' ) );
		add_action( 'wp_ajax_' . self::DASH_AJAX_STEP, array( self::class, 'handle_ajax_step' ) );
	}

	public static function add_menu(): void {
		add_menu_page(
			__( 'Bulk Pages', 'earthhaul-bulk-pages' ),
			__( 'Bulk Pages', 'earthhaul-bulk-pages' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( self::class, 'render_dashboard' ),
			'dashicons-screenoptions',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'earthhaul-bulk-pages' ),
			__( 'Dashboard', 'earthhaul-bulk-pages' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( self::class, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'New Job', 'earthhaul-bulk-pages' ),
			__( 'New Job', 'earthhaul-bulk-pages' ),
			self::CAPABILITY,
			self::NEW_JOB_PAGE_SLUG,
			array( New_Job_Screen::class, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Inspect Page', 'earthhaul-bulk-pages' ),
			__( 'Inspect Page', 'earthhaul-bulk-pages' ),
			self::CAPABILITY,
			self::INSPECT_PAGE_SLUG,
			array( Inspect_Screen::class, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Patch Pages', 'earthhaul-bulk-pages' ),
			__( 'Patch Pages', 'earthhaul-bulk-pages' ),
			self::CAPABILITY,
			Patch_Screen::PAGE_SLUG,
			array( Patch_Screen::class, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Find Leaks', 'earthhaul-bulk-pages' ),
			__( 'Find Leaks', 'earthhaul-bulk-pages' ),
			self::CAPABILITY,
			Leak_Scanner_Screen::PAGE_SLUG,
			array( Leak_Scanner_Screen::class, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'earthhaul-bulk-pages' ),
			__( 'Settings', 'earthhaul-bulk-pages' ),
			self::CAPABILITY,
			self::SETTINGS_PAGE_SLUG,
			array( Settings_Page::class, 'render' )
		);
	}

	public static function render_dashboard(): void {
		// Dashboard-level chunked job in flight (publish / draft / delete /
		// reset). Hand off rendering to the progress UI so the user can
		// watch the chunked AJAX runner finish before the regular dashboard
		// re-renders with updated counts.
		$active_job_id = isset( $_GET[ self::DASH_JOB_QUERY_PARAM ] )
			? sanitize_key( wp_unslash( (string) $_GET[ self::DASH_JOB_QUERY_PARAM ] ) )
			: '';
		if ( '' !== $active_job_id ) {
			$state = self::load_job_state( $active_job_id );
			if ( ! empty( $state ) ) {
				self::render_progress( $active_job_id, $state );
				return;
			}
		}

		$settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG );
		$new_job_url  = admin_url( 'admin.php?page=' . self::NEW_JOB_PAGE_SLUG );

		$cloned_count        = self::count_cloned_drafts();
		$draft_count         = self::count_cloned_by_status( 'draft' );
		$published_count     = self::count_cloned_by_status( 'publish' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Pages', 'earthhaul-bulk-pages' ); ?></h1>
			<?php settings_errors( 'ehbp_dashboard' ); ?>
			<p><?php esc_html_e( 'Bulk-clone Beaver Builder pages with AI-generated content.', 'earthhaul-bulk-pages' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $new_job_url ); ?>">
					<?php esc_html_e( 'Start a new job', 'earthhaul-bulk-pages' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Settings', 'earthhaul-bulk-pages' ); ?>
				</a>
			</p>
			<hr>
			<h2><?php esc_html_e( 'Status', 'earthhaul-bulk-pages' ); ?></h2>
			<ul>
				<li>
					<strong><?php esc_html_e( 'OpenAI API key:', 'earthhaul-bulk-pages' ); ?></strong>
					<?php echo Settings_Page::has_api_key() ? '<span style="color:#2e7d32;">' . esc_html__( 'configured', 'earthhaul-bulk-pages' ) . '</span>' : '<span style="color:#c62828;">' . esc_html__( 'not set', 'earthhaul-bulk-pages' ) . '</span>'; ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Beaver Builder:', 'earthhaul-bulk-pages' ); ?></strong>
					<?php echo class_exists( 'FLBuilderModel' ) ? '<span style="color:#2e7d32;">' . esc_html__( 'detected', 'earthhaul-bulk-pages' ) . '</span>' : '<span style="color:#c62828;">' . esc_html__( 'not detected (install + activate Beaver Builder)', 'earthhaul-bulk-pages' ) . '</span>'; ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Yoast SEO:', 'earthhaul-bulk-pages' ); ?></strong>
					<?php echo defined( 'WPSEO_VERSION' ) ? '<span style="color:#2e7d32;">' . esc_html__( 'detected', 'earthhaul-bulk-pages' ) . '</span>' : '<span style="color:#999;">' . esc_html__( 'not detected (optional, for meta descriptions)', 'earthhaul-bulk-pages' ) . '</span>'; ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Cloned pages:', 'earthhaul-bulk-pages' ); ?></strong>
					<?php
					printf(
						/* translators: 1: total cloned, 2: drafts, 3: published */
						esc_html__( '%1$d total (%2$d draft, %3$d published)', 'earthhaul-bulk-pages' ),
						(int) $cloned_count,
						(int) $draft_count,
						(int) $published_count
					);
					?>
				</li>
			</ul>

			<hr>
			<h2><?php esc_html_e( 'Publish drafts', 'earthhaul-bulk-pages' ); ?></h2>
			<p><?php esc_html_e( 'Flips every cloned draft to published in one click. Drafts are invisible to Google - only published pages get crawled and indexed. Run this once you have spot-checked the AI output and you are ready for the new pages to go live.', 'earthhaul-bulk-pages' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Publish <?php echo (int) $draft_count; ?> cloned drafts? They will go live immediately.');">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PUBLISH_ACTION ); ?>">
				<?php wp_nonce_field( self::PUBLISH_ACTION ); ?>
				<button type="submit" class="button button-primary" <?php disabled( $draft_count === 0 ); ?>>
					<?php
					printf(
						/* translators: %d: count */
						esc_html__( 'Publish all %d cloned drafts', 'earthhaul-bulk-pages' ),
						(int) $draft_count
					);
					?>
				</button>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Manage cloned pages', 'earthhaul-bulk-pages' ); ?></h2>
			<p><?php esc_html_e( 'Pick individual cloned pages and apply a bulk action. Useful when a partial run goes sideways and you want to delete just the bad pages and re-run those specific cities, instead of nuking everything.', 'earthhaul-bulk-pages' ); ?></p>
			<?php self::render_clones_table(); ?>

			<hr>
			<h2><?php esc_html_e( 'Reset (delete everything)', 'earthhaul-bulk-pages' ); ?></h2>
			<p><?php esc_html_e( 'Force-deletes every page this plugin has cloned (anything tagged with _ehbp_source_post_id), whether it is still a draft or has been published. Sideloaded image copies are removed with their parent page. The original Orlando template page and any non-plugin pages are NOT touched. Useful when you want to wipe and start over with a clean slate.', 'earthhaul-bulk-pages' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Permanently delete <?php echo (int) $cloned_count; ?> cloned pages (draft or published)? This cannot be undone.');">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>">
				<?php wp_nonce_field( self::RESET_ACTION ); ?>
				<button type="submit" class="button button-secondary" <?php disabled( $cloned_count === 0 ); ?> style="color:#b71c1c;border-color:#b71c1c;">
					<?php
					printf(
						/* translators: %d: count */
						esc_html__( 'Delete all %d cloned pages', 'earthhaul-bulk-pages' ),
						(int) $cloned_count
					);
					?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a checkbox-driven table of every cloned page with a bulk
	 * action selector at the top. Each row shows status, slug, modified
	 * date, plus inline edit / view links so the user can spot-check
	 * before bulk-deleting or bulk-publishing.
	 *
	 * Posts are listed newest-first. We don't paginate yet because the
	 * realistic max for a single client (a few hundred cities) renders
	 * fine in one table; if/when that becomes a problem, swap in
	 * WP_List_Table for pagination + sortable columns.
	 */
	private static function render_clones_table(): void {
		$rows = self::query_cloned_posts();
		if ( empty( $rows ) ) {
			echo '<p style="color:#646970;"><em>' . esc_html__( 'No cloned pages yet. Run a job to create some.', 'earthhaul-bulk-pages' ) . '</em></p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			id="ehbp-clones-form"
			onsubmit="return ehbpConfirmBulk(this);">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::BULK_ACTION ); ?>">
			<?php wp_nonce_field( self::BULK_ACTION ); ?>

			<div class="tablenav top" style="margin:8px 0;">
				<label for="ehbp_bulk_op" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'earthhaul-bulk-pages' ); ?></label>
				<select name="bulk_op" id="ehbp_bulk_op">
					<option value=""><?php esc_html_e( 'Bulk action…', 'earthhaul-bulk-pages' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete (permanent)', 'earthhaul-bulk-pages' ); ?></option>
					<option value="publish"><?php esc_html_e( 'Publish', 'earthhaul-bulk-pages' ); ?></option>
					<option value="draft"><?php esc_html_e( 'Move to draft (unpublish)', 'earthhaul-bulk-pages' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Apply to selected', 'earthhaul-bulk-pages' ); ?></button>
				<span style="color:#646970;margin-left:8px;">
					<?php
					printf(
						/* translators: %d: total cloned page count */
						esc_html__( '%d cloned page(s) total', 'earthhaul-bulk-pages' ),
						count( $rows )
					);
					?>
				</span>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<td class="check-column" style="width:2em;">
							<input type="checkbox" id="ehbp-clones-select-all"
								onclick="var b=this.checked;document.querySelectorAll('#ehbp-clones-form input[name=\'post_ids[]\']').forEach(function(c){c.checked=b;});">
						</td>
						<th><?php esc_html_e( 'Title', 'earthhaul-bulk-pages' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'earthhaul-bulk-pages' ); ?></th>
						<th><?php esc_html_e( 'Status', 'earthhaul-bulk-pages' ); ?></th>
						<th><?php esc_html_e( 'City', 'earthhaul-bulk-pages' ); ?></th>
						<th><?php esc_html_e( 'Modified', 'earthhaul-bulk-pages' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'earthhaul-bulk-pages' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$post_id     = (int) $row['ID'];
					$title       = (string) $row['post_title'];
					$slug        = (string) $row['post_name'];
					$status      = (string) $row['post_status'];
					$city_label  = trim( ( (string) $row['city_name'] ) . ( '' !== (string) $row['state'] ? ', ' . $row['state'] : '' ), ', ' );
					$modified    = (string) $row['post_modified'];
					$edit_url    = (string) get_edit_post_link( $post_id, 'raw' );
					$view_url    = 'publish' === $status
						? (string) get_permalink( $post_id )
						: (string) get_preview_post_link( $post_id );
					$status_html = self::format_status_badge( $status );
					?>
					<tr>
						<th class="check-column" scope="row">
							<input type="checkbox" name="post_ids[]" value="<?php echo (int) $post_id; ?>">
						</th>
						<td>
							<strong>
								<?php if ( '' !== $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $title ); ?>
								<?php endif; ?>
							</strong>
							<div style="color:#646970;font-size:12px;">#<?php echo (int) $post_id; ?></div>
						</td>
						<td><code><?php echo esc_html( $slug ); ?></code></td>
						<td><?php echo $status_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-built safe HTML. ?></td>
						<td><?php echo '' !== $city_label ? esc_html( $city_label ) : '<span style="color:#999;">&mdash;</span>'; ?></td>
						<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $modified ) ); ?></td>
						<td>
							<?php if ( '' !== $view_url ) : ?>
								<a href="<?php echo esc_url( $view_url ); ?>" target="_blank">
									<?php echo 'publish' === $status ? esc_html__( 'View', 'earthhaul-bulk-pages' ) : esc_html__( 'Preview', 'earthhaul-bulk-pages' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( '' !== $edit_url ) : ?>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'earthhaul-bulk-pages' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<script>
		function ehbpConfirmBulk(form) {
			var op = form.bulk_op.value;
			var checked = form.querySelectorAll('input[name="post_ids[]"]:checked').length;
			if (!op) { alert('Pick a bulk action first.'); return false; }
			if (!checked) { alert('Select at least one page.'); return false; }
			if (op === 'delete') {
				return confirm('Permanently delete ' + checked + ' cloned page(s)? This cannot be undone.');
			}
			return confirm('Apply "' + op + '" to ' + checked + ' page(s)?');
		}
		</script>
		<?php
	}

	/**
	 * Pull every cloned page (any status) plus the city/state meta we
	 * stamp at clone time, ordered newest-first.
	 *
	 * Schema:
	 *   ID, post_title, post_name, post_status, post_modified,
	 *   city_name, state.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function query_cloned_posts(): array {
		global $wpdb;
		$sql = $wpdb->prepare(
			"
			SELECT
				p.ID,
				p.post_title,
				p.post_name,
				p.post_status,
				p.post_modified,
				COALESCE(mc.meta_value, '') AS city_name,
				COALESCE(ms.meta_value, '') AS state
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} mc ON mc.post_id = p.ID AND mc.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = %s
			WHERE p.post_status IN ('publish','draft','private','pending','future')
			ORDER BY p.post_modified DESC
			",
			'_ehbp_source_post_id',
			'_ehbp_city_name',
			'_ehbp_state'
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private static function format_status_badge( string $status ): string {
		switch ( $status ) {
			case 'publish':
				return '<span style="color:#2e7d32;font-weight:600;">' . esc_html__( 'published', 'earthhaul-bulk-pages' ) . '</span>';
			case 'draft':
				return '<span style="color:#b06000;font-weight:600;">' . esc_html__( 'draft', 'earthhaul-bulk-pages' ) . '</span>';
			default:
				return '<span style="color:#646970;">' . esc_html( $status ) . '</span>';
		}
	}

	private static function count_cloned_drafts(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT post_id)
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_ehbp_source_post_id'
			"
		);
	}

	/**
	 * Count cloned posts in a given post_status. Used for the dashboard
	 * to show draft vs published splits, and to gate the Publish button.
	 */
	private static function count_cloned_by_status( string $status ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT pm.post_id)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_ehbp_source_post_id'
				  AND p.post_status = %s
				",
				$status
			)
		);
	}

	/**
	 * Stager for the "Publish all cloned drafts" button. Collects every
	 * cloned-draft post ID, persists a job record in a transient, then
	 * redirects back to the dashboard with the job ID in the query
	 * string so render_dashboard() hands off to render_progress() which
	 * drives a chunked AJAX loop until done.
	 *
	 * Handling 100+ wp_update_post calls in a single PHP request blew
	 * out the SiteGround 120s proxy timeout (and Yoast / sitemap hooks
	 * push memory toward the 256 MB cap). Chunking moves the work into
	 * batches the browser drives.
	 */
	public static function handle_publish_drafts(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::PUBLISH_ACTION );

		global $wpdb;
		$ids = (array) $wpdb->get_col(
			"
			SELECT DISTINCT pm.post_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_ehbp_source_post_id'
			  AND p.post_status = 'draft'
			"
		);
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) );

		if ( empty( $ids ) ) {
			add_settings_error(
				'ehbp_dashboard',
				'ehbp_publish_noop',
				__( 'No cloned drafts to publish.', 'earthhaul-bulk-pages' ),
				'info'
			);
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&settings-updated=true' ) );
			exit;
		}

		$job_id = self::stage_job( 'publish', $ids );
		wp_safe_redirect( self::dashboard_url_for_job( $job_id ) );
		exit;
	}

	/**
	 * Stager for the table-driven Manage cloned pages bulk action
	 * (delete / publish / draft). Validates input, gates the IDs to our
	 * cloned posts only, persists a job record, then hands off to the
	 * chunked AJAX runner.
	 *
	 * Hard-gated to IDs that carry our `_ehbp_source_post_id` meta
	 * marker so a tampered form can't be used to delete unrelated pages.
	 */
	public static function handle_bulk_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::BULK_ACTION );

		$op  = isset( $_POST['bulk_op'] ) ? sanitize_key( wp_unslash( $_POST['bulk_op'] ) ) : '';
		$ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] )
			? array_map( 'intval', wp_unslash( $_POST['post_ids'] ) )
			: array();
		$ids = array_values( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) );

		if ( empty( $ids ) || ! in_array( $op, array( 'delete', 'publish', 'draft' ), true ) ) {
			add_settings_error(
				'ehbp_dashboard',
				'ehbp_bulk_noop',
				__( 'Pick at least one page and a valid bulk action.', 'earthhaul-bulk-pages' ),
				'error'
			);
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&settings-updated=true' ) );
			exit;
		}

		$ids = self::filter_to_cloned_only( $ids );
		if ( empty( $ids ) ) {
			add_settings_error(
				'ehbp_dashboard',
				'ehbp_bulk_noop',
				__( 'None of the selected post IDs correspond to a plugin-cloned page. Aborting.', 'earthhaul-bulk-pages' ),
				'error'
			);
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&settings-updated=true' ) );
			exit;
		}

		$job_id = self::stage_job( $op, $ids );
		wp_safe_redirect( self::dashboard_url_for_job( $job_id ) );
		exit;
	}

	/**
	 * Cascade-delete every attachment parented to the given cloned page.
	 *
	 * `wp_delete_post` does NOT auto-delete child attachments by default,
	 * so before deleting a cloned page we sweep its attachments to keep
	 * the media library and uploads dir in sync with the cloned-pages
	 * lifecycle.
	 *
	 * Safety: we ONLY operate on posts that carry our
	 * `_ehbp_source_post_id` marker (i.e. plugin-cloned pages). Cloned
	 * pages exist only to host plugin-generated content; every
	 * attachment parented to one was placed there by Image_Pipeline.
	 *
	 * @return int Count of attachments deleted.
	 */
	private static function delete_cloned_attachments_for_post( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}
		if ( ! get_post_meta( $post_id, '_ehbp_source_post_id', true ) ) {
			return 0;
		}

		$attachment_ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_parent' => $post_id,
				'numberposts' => -1,
				'fields'      => 'ids',
				'post_status' => 'any',
			)
		);

		$deleted = 0;
		foreach ( (array) $attachment_ids as $att_id ) {
			$result = wp_delete_attachment( (int) $att_id, true );
			if ( $result ) {
				$deleted++;
			}
		}
		return $deleted;
	}

	/**
	 * Drop any IDs from the input list that are NOT cloned pages produced
	 * by this plugin. Defense in depth so a tampered form payload can't
	 * reach the delete/update path for unrelated posts.
	 *
	 * @param array<int, int> $ids
	 * @return array<int, int>
	 */
	private static function filter_to_cloned_only( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( '_ehbp_source_post_id' ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders are %d, count matches.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($placeholders)",
			$params
		);
		$valid = (array) $wpdb->get_col( $sql );
		return array_map( 'intval', $valid );
	}

	public static function handle_reset(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::RESET_ACTION );

		global $wpdb;
		$ids = (array) $wpdb->get_col(
			"
			SELECT DISTINCT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_ehbp_source_post_id'
			"
		);
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) );

		if ( empty( $ids ) ) {
			add_settings_error(
				'ehbp_dashboard',
				'ehbp_reset_noop',
				__( 'No cloned pages to delete.', 'earthhaul-bulk-pages' ),
				'info'
			);
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&settings-updated=true' ) );
			exit;
		}

		$job_id = self::stage_job( 'delete', $ids );
		wp_safe_redirect( self::dashboard_url_for_job( $job_id ) );
		exit;
	}

	/* -------------------------------------------------------------------- */
	/* Chunked AJAX runner for dashboard bulk operations.                   */
	/* -------------------------------------------------------------------- */

	/**
	 * Persist a new dashboard job and return its job_id. Job state lives
	 * in a transient (small-ish: a list of post IDs + counters), keyed
	 * by `ehbp_dash_job_<id>`. The runner consumes IDs from the
	 * `remaining` list one chunk at a time until empty.
	 *
	 * @param string         $op  publish | draft | delete
	 * @param array<int,int> $ids Post IDs (already filtered to cloned).
	 */
	private static function stage_job( string $op, array $ids ): string {
		$job_id = 'dj_' . wp_generate_password( 8, false, false );
		$state  = array(
			'job_id'              => $job_id,
			'op'                  => $op,
			'total'               => count( $ids ),
			'remaining'           => array_values( $ids ),
			'ok'                  => 0,
			'failed'              => 0,
			'attachments_deleted' => 0,
			'errors'              => array(),
			'created_at'          => time(),
		);
		self::save_job_state( $job_id, $state );
		return $job_id;
	}

	private static function save_job_state( string $job_id, array $state ): void {
		set_transient( self::DASH_JOB_TRANSIENT . $job_id, $state, HOUR_IN_SECONDS );
	}

	private static function load_job_state( string $job_id ): array {
		$state = get_transient( self::DASH_JOB_TRANSIENT . $job_id );
		return is_array( $state ) ? $state : array();
	}

	private static function clear_job_state( string $job_id ): void {
		delete_transient( self::DASH_JOB_TRANSIENT . $job_id );
	}

	private static function dashboard_url_for_job( string $job_id ): string {
		return add_query_arg(
			array(
				'page'                       => self::MENU_SLUG,
				self::DASH_JOB_QUERY_PARAM   => $job_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * AJAX endpoint that processes one chunk (DASH_CHUNK_SIZE post IDs)
	 * of the staged job and returns the updated state. The browser-side
	 * loop calls this until `done` is true.
	 */
	public static function handle_ajax_step(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'earthhaul-bulk-pages' ) ), 403 );
		}
		check_ajax_referer( self::DASH_AJAX_STEP, 'nonce' );

		$job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['job_id'] ) ) : '';
		$state  = self::load_job_state( $job_id );
		if ( empty( $state ) || empty( $state['op'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Job not found or already completed.', 'earthhaul-bulk-pages' ) ), 404 );
		}

		// Process up to DASH_CHUNK_SIZE IDs, then return so the browser
		// keeps the connection short and within proxy timeout windows.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$op    = (string) $state['op'];
		$batch = array_splice( $state['remaining'], 0, self::DASH_CHUNK_SIZE );

		foreach ( $batch as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$ok = false;
			try {
				if ( 'delete' === $op ) {
					$state['attachments_deleted'] += self::delete_cloned_attachments_for_post( $id );
					$ok = (bool) wp_delete_post( $id, true );
				} elseif ( 'publish' === $op ) {
					$updated = wp_update_post(
						array( 'ID' => $id, 'post_status' => 'publish' ),
						true
					);
					$ok = ! is_wp_error( $updated ) && (int) $updated > 0;
					if ( is_wp_error( $updated ) ) {
						$state['errors'][] = sprintf( '#%d: %s', $id, $updated->get_error_message() );
					}
				} elseif ( 'draft' === $op ) {
					$updated = wp_update_post(
						array( 'ID' => $id, 'post_status' => 'draft' ),
						true
					);
					$ok = ! is_wp_error( $updated ) && (int) $updated > 0;
					if ( is_wp_error( $updated ) ) {
						$state['errors'][] = sprintf( '#%d: %s', $id, $updated->get_error_message() );
					}
				}
			} catch ( \Throwable $e ) {
				$state['errors'][] = sprintf( '#%d: %s', $id, $e->getMessage() );
				$ok = false;
			}
			$ok ? $state['ok']++ : $state['failed']++;
		}

		$done = empty( $state['remaining'] );
		if ( $done ) {
			// Drop a settings_errors notice for after-redirect display
			// so the user sees the final summary on the regular dashboard.
			$verb = array(
				'delete'  => __( 'Deleted', 'earthhaul-bulk-pages' ),
				'publish' => __( 'Published', 'earthhaul-bulk-pages' ),
				'draft'   => __( 'Reverted to draft', 'earthhaul-bulk-pages' ),
			)[ $op ] ?? __( 'Updated', 'earthhaul-bulk-pages' );

			$att_note = ( 'delete' === $op && $state['attachments_deleted'] > 0 )
				? ' ' . sprintf(
					/* translators: %d: attachment count */
					__( 'Also removed %d sideloaded image(s).', 'earthhaul-bulk-pages' ),
					(int) $state['attachments_deleted']
				)
				: '';

			add_settings_error(
				'ehbp_dashboard',
				'ehbp_dash_job_done',
				sprintf(
					/* translators: 1: action verb, 2: ok count, 3: failed count, 4: optional attachment note */
					__( '%1$s %2$d cloned page(s). %3$d failed.%4$s', 'earthhaul-bulk-pages' ),
					$verb,
					(int) $state['ok'],
					(int) $state['failed'],
					$att_note
				),
				'success'
			);
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			self::clear_job_state( $job_id );
		} else {
			self::save_job_state( $job_id, $state );
		}

		$processed = (int) $state['ok'] + (int) $state['failed'];
		wp_send_json_success(
			array(
				'job_id'              => $job_id,
				'op'                  => $op,
				'done'                => $done,
				'total'               => (int) $state['total'],
				'processed'           => $processed,
				'ok'                  => (int) $state['ok'],
				'failed'              => (int) $state['failed'],
				'attachments_deleted' => (int) $state['attachments_deleted'],
				'errors'              => array_slice( (array) $state['errors'], -10 ),
				'percent'             => (int) $state['total'] > 0 ? (int) round( ( $processed / (int) $state['total'] ) * 100 ) : 100,
			)
		);
	}

	/**
	 * Render the chunked-job progress UI: a heading, a progress bar, a
	 * counter, a Cancel button, and a JS loop that drives the AJAX
	 * runner until the job reports done. On done we redirect back to
	 * the regular dashboard so the user sees the success notice.
	 */
	private static function render_progress( string $job_id, array $state ): void {
		$op = (string) ( $state['op'] ?? '' );
		$verb = array(
			'delete'  => __( 'Deleting cloned pages', 'earthhaul-bulk-pages' ),
			'publish' => __( 'Publishing cloned drafts', 'earthhaul-bulk-pages' ),
			'draft'   => __( 'Reverting pages to draft', 'earthhaul-bulk-pages' ),
		)[ $op ] ?? __( 'Processing cloned pages', 'earthhaul-bulk-pages' );

		$processed = (int) $state['ok'] + (int) $state['failed'];
		$total     = (int) $state['total'];
		$percent   = $total > 0 ? (int) round( ( $processed / $total ) * 100 ) : 0;
		$nonce     = wp_create_nonce( self::DASH_AJAX_STEP );
		$dash_url  = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $verb ); ?></h1>
			<p>
				<strong><?php esc_html_e( 'Job:', 'earthhaul-bulk-pages' ); ?></strong>
				<code><?php echo esc_html( $job_id ); ?></code>
				&nbsp;&middot;&nbsp;
				<?php
				printf(
					/* translators: %d: total post count */
					esc_html__( '%d page(s) total', 'earthhaul-bulk-pages' ),
					$total
				);
				?>
			</p>

			<div id="ehbp-dash-bar" style="background:#e5e7eb;height:14px;border-radius:4px;overflow:hidden;margin:8px 0;">
				<div id="ehbp-dash-bar-fill"
					style="background:#2271b1;height:100%;width:<?php echo (int) $percent; ?>%;transition:width .25s ease-out;"></div>
			</div>
			<div id="ehbp-dash-counter" style="font-family:Menlo,monospace;color:#1f2328;">
				<?php
				printf(
					/* translators: 1: processed, 2: total, 3: ok, 4: failed */
					esc_html__( '%1$d / %2$d processed (%3$d ok, %4$d failed)', 'earthhaul-bulk-pages' ),
					$processed,
					$total,
					(int) $state['ok'],
					(int) $state['failed']
				);
				?>
			</div>

			<p style="margin-top:16px;">
				<a href="<?php echo esc_url( $dash_url ); ?>" class="button" id="ehbp-dash-cancel"><?php esc_html_e( 'Cancel and return to dashboard', 'earthhaul-bulk-pages' ); ?></a>
			</p>

			<p style="color:#646970;font-size:13px;">
				<?php esc_html_e( 'Keep this tab open and the laptop awake while the job runs. Each chunk processes a small batch so the request completes well within hosting timeouts; the progress bar updates after every chunk.', 'earthhaul-bulk-pages' ); ?>
			</p>

			<div id="ehbp-dash-errors" style="margin-top:12px;color:#b06000;font-family:Menlo,monospace;font-size:12px;"></div>
		</div>

		<script>
		(function () {
			var jobId    = <?php echo wp_json_encode( $job_id ); ?>;
			var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
			var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var dashUrl  = <?php echo wp_json_encode( $dash_url . '&settings-updated=true' ); ?>;
			var bar      = document.getElementById('ehbp-dash-bar-fill');
			var counter  = document.getElementById('ehbp-dash-counter');
			var errBox   = document.getElementById('ehbp-dash-errors');
			var cancel   = document.getElementById('ehbp-dash-cancel');
			var stopped  = false;

			cancel.addEventListener('click', function () { stopped = true; });

			function step() {
				if (stopped) { return; }
				var body = new FormData();
				body.append('action', '<?php echo esc_js( self::DASH_AJAX_STEP ); ?>');
				body.append('nonce', nonce);
				body.append('job_id', jobId);

				fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (!json || !json.success) {
							errBox.textContent = (json && json.data && json.data.message) ? json.data.message : 'Step failed.';
							return;
						}
						var d = json.data;
						bar.style.width = d.percent + '%';
						counter.textContent = d.processed + ' / ' + d.total + ' processed (' + d.ok + ' ok, ' + d.failed + ' failed)';
						if (d.errors && d.errors.length) {
							errBox.innerHTML = d.errors.slice(-5).map(function (e) {
								return e.replace(/[&<>]/g, function (c) {
									return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
								});
							}).join('<br>');
						}
						if (d.done) {
							window.location.href = dashUrl;
							return;
						}
						setTimeout(step, 200);
					})
					.catch(function (err) {
						errBox.textContent = 'Network error: ' + err.message + '. Refresh this page to resume.';
					});
			}

			step();
		})();
		</script>
		<?php
	}
}
