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

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( self::class, 'handle_reset' ) );
		add_action( 'admin_post_' . self::PUBLISH_ACTION, array( self::class, 'handle_publish_drafts' ) );
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
			<h2><?php esc_html_e( 'Reset', 'earthhaul-bulk-pages' ); ?></h2>
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

		$deleted = 0;
		$failed  = 0;
		foreach ( $ids as $id ) {
			$result = wp_delete_post( (int) $id, true );
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
				/* translators: 1: deleted count, 2: failed count */
				__( 'Reset complete. Deleted %1$d cloned pages. %2$d failed.', 'earthhaul-bulk-pages' ),
				$deleted,
				$failed
			),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&settings-updated=true' ) );
		exit;
	}
}
