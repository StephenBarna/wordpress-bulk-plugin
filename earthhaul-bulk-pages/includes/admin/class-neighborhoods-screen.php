<?php
/**
 * "Neighborhoods" admin screen.
 *
 * Two-step flow:
 *   Step 1: upload a CSV with `city_slug, neighborhood` rows. Plugin
 *           validates the CSV, matches each city_slug to a cloned page
 *           (by `_ehbp_city_slug` meta), looks for any module on that
 *           page tagged with the `ehbp-neighborhoods` class, and shows
 *           a preview: per city, how many neighborhoods will be written
 *           and which page they hit.
 *   Step 2: Apply All button writes the new content to BOTH BB slots
 *           (draft + published) on every matched page and busts BB's
 *           per-post asset cache so the live URL reflects the change
 *           on the next request.
 *
 * The CSV is held in a transient between the upload and the apply step,
 * so a page reload doesn't lose the parsed data.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

use EarthHaul\BulkPages\Services\Neighborhoods_Applier;
use EarthHaul\BulkPages\Services\Neighborhoods_CSV_Importer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Neighborhoods_Screen {

	public const PAGE_SLUG     = 'ehbp-neighborhoods';
	public const UPLOAD_ACTION = 'ehbp_neighborhoods_upload';
	public const APPLY_ACTION  = 'ehbp_neighborhoods_apply';
	public const TRANSIENT_TTL = 3600;

	public static function register(): void {
		add_action( 'admin_post_' . self::UPLOAD_ACTION, array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_' . self::APPLY_ACTION, array( __CLASS__, 'handle_apply' ) );
	}

	public static function render(): void {
		$bundle = get_transient( self::transient_key() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Pages - Neighborhoods', 'earthhaul-bulk-pages' ); ?></h1>
			<?php settings_errors( 'ehbp_neighborhoods' ); ?>

			<?php if ( ! class_exists( '\FLBuilderModel' ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Beaver Builder is not active.', 'earthhaul-bulk-pages' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( is_array( $bundle ) && ! empty( $bundle['preview'] ) ) : ?>
				<?php self::render_preview( $bundle ); ?>
			<?php else : ?>
				<?php self::render_upload(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* -------------------------------------------------------------------- */
	/* Step 1: upload                                                       */
	/* -------------------------------------------------------------------- */

	private static function render_upload(): void {
		$class = Neighborhoods_Applier::TARGET_CLASS;
		?>
		<p>
			<?php
			printf(
				/* translators: %s: the CSS class to add */
				wp_kses(
					__( 'Upload a 2-column CSV (<code>city_slug, neighborhood</code>) with one row per neighborhood. The plugin matches each <code>city_slug</code> to a cloned page and rewrites the module tagged with the <code>%s</code> CSS class on that page.', 'earthhaul-bulk-pages' ),
					array( 'code' => array() )
				),
				esc_html( $class )
			);
			?>
		</p>
		<details style="margin:12px 0 18px;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Sample CSV', 'earthhaul-bulk-pages' ); ?></summary>
			<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;margin-top:8px;font-size:12px;">city_slug,neighborhood
celebration-fl,Town Center
celebration-fl,North Village
celebration-fl,Lake Evalyn
davenport-fl,Town Center
davenport-fl,Loma Linda</pre>
			<p class="description">
				<?php esc_html_e( 'State abbreviations are auto-derived from the city slug suffix (e.g. "celebration-fl" -> "FL"). Don\'t include the state in the neighborhood column - the plugin appends it.', 'earthhaul-bulk-pages' ); ?>
			</p>
		</details>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::UPLOAD_ACTION ); ?>">
			<?php wp_nonce_field( self::UPLOAD_ACTION ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ehbp_neighborhoods_csv"><?php esc_html_e( 'Neighborhoods CSV', 'earthhaul-bulk-pages' ); ?></label></th>
					<td>
						<input type="file" name="neighborhoods_csv" id="ehbp_neighborhoods_csv" accept=".csv,text/csv" required>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Upload + Preview', 'earthhaul-bulk-pages' ) ); ?>
		</form>
		<?php
	}

	public static function handle_upload(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::UPLOAD_ACTION );

		if ( empty( $_FILES['neighborhoods_csv']['tmp_name'] ) ) {
			self::add_error( __( 'No file uploaded.', 'earthhaul-bulk-pages' ) );
			self::redirect();
		}

		$tmp_path = (string) $_FILES['neighborhoods_csv']['tmp_name'];
		$parsed   = Neighborhoods_CSV_Importer::parse_file( $tmp_path );

		if ( is_wp_error( $parsed ) ) {
			self::add_error( $parsed->get_error_message() );
			self::redirect();
		}

		$preview = self::build_preview( $parsed['groups'] );

		set_transient(
			self::transient_key(),
			array(
				'uploaded_at' => time(),
				'groups'      => $parsed['groups'],
				'total'       => $parsed['total'],
				'preview'     => $preview,
			),
			self::TRANSIENT_TTL
		);

		self::redirect();
	}

	/* -------------------------------------------------------------------- */
	/* Step 2: preview + apply                                              */
	/* -------------------------------------------------------------------- */

	/**
	 * Build a per-row preview without mutating any layout. Looks up the
	 * matching post for each slug and counts how many ehbp-neighborhoods
	 * modules are present on it (so the user can see "this slug doesn't
	 * resolve to a page" or "no class on the page yet").
	 *
	 * @return array<int, array{slug: string, count: int, post_id: int, post_title: string, modules_found: int, status: string, sample: string}>
	 */
	private static function build_preview( array $groups ): array {
		$preview = array();
		foreach ( $groups as $slug => $list ) {
			$post_id = Neighborhoods_Applier::find_post_by_slug( $slug );
			$row     = array(
				'slug'          => $slug,
				'count'         => count( $list ),
				'post_id'       => $post_id,
				'post_title'    => '',
				'modules_found' => 0,
				'status'        => 'pending',
				'sample'        => implode( ', ', array_slice( $list, 0, 5 ) ) . ( count( $list ) > 5 ? '...' : '' ),
			);
			if ( 0 === $post_id ) {
				$row['status'] = 'no_post';
				$preview[]     = $row;
				continue;
			}
			$post              = get_post( $post_id );
			$row['post_title'] = $post ? (string) $post->post_title : '';

			$modules = Neighborhoods_Applier::locate_modules( $post_id );
			$row['modules_found'] = count( $modules );
			$row['status']        = empty( $modules ) ? 'no_class' : 'ready';
			$preview[]            = $row;
		}
		return $preview;
	}

	private static function render_preview( array $bundle ): void {
		$preview     = (array) $bundle['preview'];
		$total_rows  = count( $preview );
		$ready_rows  = 0;
		$no_post     = 0;
		$no_class    = 0;
		foreach ( $preview as $r ) {
			if ( 'ready' === $r['status'] ) {
				$ready_rows++;
			} elseif ( 'no_post' === $r['status'] ) {
				$no_post++;
			} elseif ( 'no_class' === $r['status'] ) {
				$no_class++;
			}
		}

		$reset_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => self::UPLOAD_ACTION, 'reset' => '1' ),
				admin_url( 'admin-post.php' )
			),
			self::UPLOAD_ACTION
		);
		?>
		<h2><?php esc_html_e( 'Preview', 'earthhaul-bulk-pages' ); ?></h2>

		<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-bottom:16px;display:flex;gap:24px;flex-wrap:wrap;">
			<div><strong><?php esc_html_e( 'Cities in CSV', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $total_rows; ?></div>
			<div><strong><?php esc_html_e( 'Total neighborhoods', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $bundle['total']; ?></div>
			<div><strong style="color:#2e7d32;"><?php esc_html_e( 'Ready', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $ready_rows; ?></div>
			<?php if ( $no_class > 0 ) : ?>
				<div><strong style="color:#b26500;"><?php esc_html_e( 'Missing class', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $no_class; ?></div>
			<?php endif; ?>
			<?php if ( $no_post > 0 ) : ?>
				<div><strong style="color:#b71c1c;"><?php esc_html_e( 'No matching page', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $no_post; ?></div>
			<?php endif; ?>
		</div>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'city_slug', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( 'Page', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( 'Modules', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( '# of items', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( 'Sample (first 5)', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( 'Status', 'earthhaul-bulk-pages' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview as $r ) : ?>
				<?php
				$status_color = '#2e7d32';
				$status_label = __( 'Ready', 'earthhaul-bulk-pages' );
				if ( 'no_post' === $r['status'] ) {
					$status_color = '#b71c1c';
					$status_label = __( 'No matching page', 'earthhaul-bulk-pages' );
				} elseif ( 'no_class' === $r['status'] ) {
					$status_color = '#b26500';
					$status_label = __( 'No ehbp-neighborhoods class', 'earthhaul-bulk-pages' );
				}
				?>
				<tr>
					<td><code><?php echo esc_html( $r['slug'] ); ?></code></td>
					<td>
						<?php if ( $r['post_id'] > 0 ) : ?>
							<?php echo esc_html( $r['post_title'] ); ?>
							<span style="color:#888;">(#<?php echo (int) $r['post_id']; ?>)</span>
						<?php else : ?>
							<em style="color:#888;"><?php esc_html_e( 'not found', 'earthhaul-bulk-pages' ); ?></em>
						<?php endif; ?>
					</td>
					<td><?php echo (int) $r['modules_found']; ?></td>
					<td><?php echo (int) $r['count']; ?></td>
					<td style="font-size:12px;color:#444;"><?php echo esc_html( $r['sample'] ); ?></td>
					<td style="color:<?php echo esc_attr( $status_color ); ?>;font-weight:600;"><?php echo esc_html( $status_label ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::APPLY_ACTION ); ?>">
			<?php wp_nonce_field( self::APPLY_ACTION ); ?>
			<?php submit_button( sprintf( __( 'Apply All (%d ready)', 'earthhaul-bulk-pages' ), $ready_rows ), 'primary', 'submit', false, $ready_rows === 0 ? array( 'disabled' => 'disabled' ) : array() ); ?>
			<a class="button" href="<?php echo esc_url( $reset_url ); ?>" onclick="return confirm('Discard the uploaded CSV?');"><?php esc_html_e( 'Discard upload', 'earthhaul-bulk-pages' ); ?></a>
		</form>
		<?php
	}

	public static function handle_apply(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::APPLY_ACTION );

		$bundle = get_transient( self::transient_key() );
		if ( ! is_array( $bundle ) || empty( $bundle['groups'] ) ) {
			self::add_error( __( 'Upload expired. Re-upload the CSV.', 'earthhaul-bulk-pages' ) );
			self::redirect();
		}

		set_time_limit( 0 );

		$applied = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( $bundle['groups'] as $slug => $list ) {
			$post_id = Neighborhoods_Applier::find_post_by_slug( (string) $slug );
			if ( 0 === $post_id ) {
				$skipped++;
				continue;
			}
			$result = Neighborhoods_Applier::apply( $post_id, (array) $list );
			if ( $result['ok'] ) {
				$applied++;
			} else {
				$skipped++;
				if ( ! empty( $result['message'] ) ) {
					$errors[] = $result['message'];
				}
			}
		}

		delete_transient( self::transient_key() );

		$msg = sprintf(
			/* translators: 1: applied count, 2: skipped count */
			__( 'Neighborhoods applied to %1$d page(s). %2$d skipped.', 'earthhaul-bulk-pages' ),
			$applied,
			$skipped
		);
		if ( ! empty( $errors ) ) {
			$msg .= ' ' . __( 'Issues:', 'earthhaul-bulk-pages' ) . ' ' . implode( ' | ', array_slice( $errors, 0, 5 ) );
		}
		self::add_notice( $msg );
		self::redirect();
	}

	/* -------------------------------------------------------------------- */
	/* Helpers                                                              */
	/* -------------------------------------------------------------------- */

	private static function transient_key(): string {
		return 'ehbp_neighborhoods_' . get_current_user_id();
	}

	private static function add_error( string $message ): void {
		add_settings_error( 'ehbp_neighborhoods', 'ehbp_neighborhoods_error', $message, 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	private static function add_notice( string $message ): void {
		add_settings_error( 'ehbp_neighborhoods', 'ehbp_neighborhoods_notice', $message, 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	private static function redirect(): void {
		// Reset link comes through admin-post.php -> handle_upload, but we
		// also want it to wipe the transient and bounce home. Special-case
		// it here so we don't re-parse the upload.
		if ( isset( $_GET['reset'] ) && '1' === (string) $_GET['reset'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( self::transient_key() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&settings-updated=true' ) );
		exit;
	}
}
