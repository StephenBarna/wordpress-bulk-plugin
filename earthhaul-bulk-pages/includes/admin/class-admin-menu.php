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

	public const MENU_SLUG          = 'ehbp-bulk-pages';
	public const SETTINGS_PAGE_SLUG = 'ehbp-settings';
	public const NEW_JOB_PAGE_SLUG  = 'ehbp-new-job';
	public const INSPECT_PAGE_SLUG  = 'ehbp-inspect';
	public const CAPABILITY         = 'manage_options';
	public const RESET_ACTION       = 'ehbp_reset_drafts';
	public const PUBLISH_ACTION     = 'ehbp_publish_drafts';
	public const BULK_ACTION        = 'ehbp_bulk_clones';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( self::class, 'handle_reset' ) );
		add_action( 'admin_post_' . self::PUBLISH_ACTION, array( self::class, 'handle_publish_drafts' ) );
		add_action( 'admin_post_' . self::BULK_ACTION, array( self::class, 'handle_bulk_action' ) );
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
			__( 'Settings', 'earthhaul-bulk-pages' ),
			__( 'Settings', 'earthhaul-bulk-pages' ),
			self::CAPABILITY,
			self::SETTINGS_PAGE_SLUG,
			array( Settings_Page::class, 'render' )
		);
	}

	public static function render_dashboard(): void {
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
	 * Bulk-publish every cloned draft. Drafts only - already-published
	 * pages stay published. Uses wp_update_post so post_modified updates
	 * and Yoast / sitemap pickups happen normally.
	 */
	public static function handle_publish_drafts(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::PUBLISH_ACTION );

		global $wpdb;
		$ids = $wpdb->get_col(
			"
			SELECT DISTINCT pm.post_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_ehbp_source_post_id'
			  AND p.post_status = 'draft'
			"
		);

		// Long batches are possible (~hundreds of posts). Drop the time
		// limit so wp_update_post + Yoast post-save hooks have room.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$published = 0;
		$failed    = 0;
		foreach ( $ids as $id ) {
			$result = wp_update_post(
				array(
					'ID'          => (int) $id,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $result ) || 0 === (int) $result ) {
				$failed++;
				continue;
			}
			$published++;
		}

		add_settings_error(
			'ehbp_dashboard',
			'ehbp_publish_done',
			sprintf(
				/* translators: 1: published count, 2: failed count */
				__( 'Publish complete. Published %1$d cloned drafts. %2$d failed.', 'earthhaul-bulk-pages' ),
				$published,
				$failed
			),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&settings-updated=true' ) );
		exit;
	}

	/**
	 * Apply a bulk operation (delete / publish / draft) to the post IDs
	 * the user ticked in the Manage cloned pages table.
	 *
	 * Hard-gated to IDs that actually carry our `_ehbp_source_post_id`
	 * meta marker so a tampered form can't be used to delete unrelated
	 * pages.
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

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$ok       = 0;
		$failed   = 0;
		$att_done = 0;

		foreach ( $ids as $id ) {
			$result = false;
			if ( 'delete' === $op ) {
				$att_done += self::delete_cloned_attachments_for_post( $id );
				$result   = (bool) wp_delete_post( $id, true );
			} elseif ( 'publish' === $op ) {
				$updated = wp_update_post(
					array( 'ID' => $id, 'post_status' => 'publish' ),
					true
				);
				$result = ! is_wp_error( $updated ) && (int) $updated > 0;
			} elseif ( 'draft' === $op ) {
				$updated = wp_update_post(
					array( 'ID' => $id, 'post_status' => 'draft' ),
					true
				);
				$result = ! is_wp_error( $updated ) && (int) $updated > 0;
			}
			$result ? $ok++ : $failed++;
		}

		$verb = array(
			'delete'  => __( 'Deleted', 'earthhaul-bulk-pages' ),
			'publish' => __( 'Published', 'earthhaul-bulk-pages' ),
			'draft'   => __( 'Reverted to draft', 'earthhaul-bulk-pages' ),
		)[ $op ];

		$att_note = ( 'delete' === $op && $att_done > 0 )
			? ' ' . sprintf(
				/* translators: %d: attachment count */
				__( 'Also removed %d sideloaded image(s).', 'earthhaul-bulk-pages' ),
				$att_done
			)
			: '';

		add_settings_error(
			'ehbp_dashboard',
			'ehbp_bulk_done',
			sprintf(
				/* translators: 1: action verb, 2: ok count, 3: failed count, 4: optional attachment note */
				__( '%1$s %2$d cloned page(s). %3$d failed.%4$s', 'earthhaul-bulk-pages' ),
				$verb,
				$ok,
				$failed,
				$att_note
			),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&settings-updated=true' ) );
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
		$ids = $wpdb->get_col(
			"
			SELECT DISTINCT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_ehbp_source_post_id'
			"
		);

		$deleted  = 0;
		$failed   = 0;
		$att_done = 0;
		foreach ( $ids as $id ) {
			$att_done += self::delete_cloned_attachments_for_post( (int) $id );
			$result   = wp_delete_post( (int) $id, true );
			if ( $result ) {
				$deleted++;
			} else {
				$failed++;
			}
		}

		add_settings_error(
			'ehbp_dashboard',
			'ehbp_reset_done',
			sprintf(
				/* translators: 1: deleted count, 2: failed count, 3: attachment count */
				__( 'Reset complete. Deleted %1$d cloned pages and %3$d sideloaded image(s). %2$d failed.', 'earthhaul-bulk-pages' ),
				$deleted,
				$failed,
				$att_done
			),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&settings-updated=true' ) );
		exit;
	}
}
