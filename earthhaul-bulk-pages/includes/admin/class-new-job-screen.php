<?php
/**
 * "New Job" admin screen.
 *
 * Phase 1a scope: pick a Beaver Builder template, upload a CSV of cities,
 * optionally pick a parent page + title format, click Run. Synchronously
 * clones the template once per CSV row, sets title/slug/parent, leaves
 * everything as a draft, then shows a results table with edit/preview links.
 *
 * Future phases will add AI text rewrites, image rename + sideload, Yoast
 * meta writing, and an Action Scheduler queue. The architecture leaves
 * hooks for those without restructuring.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

use EarthHaul\BulkPages\Services\CSV_Importer;
use EarthHaul\BulkPages\Services\Page_Cloner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class New_Job_Screen {

	public const PAGE_SLUG    = 'ehbp-new-job';
	public const RUN_ACTION   = 'ehbp_run_clone_job';
	public const RUN_NONCE    = 'ehbp_run_clone_job';
	public const RESULT_KEY   = 'ehbp_last_job_result';

	public const DEFAULT_TITLE_FORMAT = 'Roll-Off Dumpster Rentals in {{city}}, {{state}}';

	public static function register(): void {
		add_action( 'admin_post_' . self::RUN_ACTION, array( self::class, 'handle_submit' ) );
	}

	public static function render(): void {
		$bb_active   = class_exists( '\FLBuilderModel' );
		$last_result = get_transient( self::RESULT_KEY );
		if ( $last_result ) {
			delete_transient( self::RESULT_KEY );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Pages - New Job', 'earthhaul-bulk-pages' ); ?></h1>
			<p><?php esc_html_e( 'Pick a Beaver Builder template, upload a CSV of cities, click Run. Each row produces one new draft page that is an exact clone of the template, with its title, slug, and parent set per row.', 'earthhaul-bulk-pages' ); ?></p>

			<?php if ( ! $bb_active ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Beaver Builder is not active. Activate it before running a job.', 'earthhaul-bulk-pages' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( $last_result ) : ?>
				<?php self::render_results( $last_result ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Run a clone job', 'earthhaul-bulk-pages' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RUN_ACTION ); ?>">
				<?php wp_nonce_field( self::RUN_NONCE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ehbp_template"><?php esc_html_e( 'Template page', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<?php self::render_template_dropdown(); ?>
							<p class="description"><?php esc_html_e( 'Only pages with Beaver Builder enabled are listed. The default suggestion is the Orlando location page if it exists.', 'earthhaul-bulk-pages' ); ?></p>
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
							<p class="description"><?php esc_html_e( 'New pages will be placed under this parent. Defaults to the parent of the chosen template.', 'earthhaul-bulk-pages' ); ?></p>
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
							<p class="description"><?php echo wp_kses_post( __( 'Required columns: <code>slug</code>, <code>city</code>, <code>state</code>. Extra columns are kept as token data. Example:', 'earthhaul-bulk-pages' ) ); ?></p>
							<pre style="background:#f6f7f7;padding:8px;border:1px solid #dcdcde;max-width:520px;">slug,city,state
apopka-fl,Apopka,Florida
kissimmee-fl,Kissimmee,Florida
oviedo-fl,Oviedo,Florida</pre>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'earthhaul-bulk-pages' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ehbp_dry_run" value="1" checked>
								<?php esc_html_e( 'Dry run (parse + validate the CSV but do NOT create pages)', 'earthhaul-bulk-pages' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Recommended for the first run on a new CSV. Uncheck to actually create draft pages.', 'earthhaul-bulk-pages' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Run', 'earthhaul-bulk-pages' ) ); ?>
			</form>
		</div>
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

	public static function handle_submit(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::RUN_NONCE );

		$template_id = isset( $_POST['ehbp_template'] ) ? (int) $_POST['ehbp_template'] : 0;
		$parent_id   = isset( $_POST['ehbp_parent'] ) ? (int) $_POST['ehbp_parent'] : 0;
		$dry_run     = ! empty( $_POST['ehbp_dry_run'] );
		$title_fmt   = isset( $_POST['ehbp_title_format'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['ehbp_title_format'] ) )
			: self::DEFAULT_TITLE_FORMAT;

		$result = array(
			'dry_run'     => $dry_run,
			'template_id' => $template_id,
			'parent_id'   => $parent_id,
			'title_fmt'   => $title_fmt,
			'created'     => array(),
			'errors'      => array(),
			'job_id'      => '',
		);

		if ( $template_id <= 0 ) {
			$result['errors'][] = __( 'Choose a template page.', 'earthhaul-bulk-pages' );
		}

		if ( ! isset( $_FILES['ehbp_csv'] ) || empty( $_FILES['ehbp_csv']['tmp_name'] ) ) {
			$result['errors'][] = __( 'Upload a CSV file.', 'earthhaul-bulk-pages' );
		}

		if ( ! empty( $result['errors'] ) ) {
			self::redirect_with_result( $result );
			return;
		}

		$csv_path = (string) $_FILES['ehbp_csv']['tmp_name'];
		$parsed   = CSV_Importer::parse_file( $csv_path );

		if ( is_wp_error( $parsed ) ) {
			$result['errors'][] = $parsed->get_error_message();
			self::redirect_with_result( $result );
			return;
		}

		$result['rows_total'] = count( $parsed['rows'] );
		$result['headers']    = $parsed['headers'];

		if ( $dry_run ) {
			$result['preview'] = array_map(
				static function ( $row ) use ( $title_fmt ) {
					return array(
						'slug'  => $row['slug'],
						'city'  => $row['city'],
						'state' => $row['state'],
						'title' => Page_Cloner::format_title( $title_fmt, $row ),
					);
				},
				$parsed['rows']
			);
			self::redirect_with_result( $result );
			return;
		}

		$job_id         = 'ehbp_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 4, false, false );
		$result['job_id'] = $job_id;

		foreach ( $parsed['rows'] as $row ) {
			$new_id = Page_Cloner::clone_for_row( $template_id, $row, $title_fmt, $parent_id, $job_id );

			if ( is_wp_error( $new_id ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: city slug, 2: error message */
					__( '%1$s: %2$s', 'earthhaul-bulk-pages' ),
					$row['slug'],
					$new_id->get_error_message()
				);
				continue;
			}

			$result['created'][] = array(
				'post_id' => $new_id,
				'slug'    => $row['slug'],
				'city'    => $row['city'],
				'title'   => get_the_title( $new_id ),
			);
		}

		self::redirect_with_result( $result );
	}

	private static function redirect_with_result( array $result ): void {
		set_transient( self::RESULT_KEY, $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	private static function render_results( array $result ): void {
		$dry  = ! empty( $result['dry_run'] );
		$errs = $result['errors'] ?? array();

		echo '<div class="notice ' . ( ! empty( $errs ) ? 'notice-error' : 'notice-success' ) . '"><p><strong>';
		if ( $dry ) {
			echo esc_html__( 'Dry run complete.', 'earthhaul-bulk-pages' );
		} else {
			echo esc_html__( 'Job complete.', 'earthhaul-bulk-pages' );
			if ( ! empty( $result['job_id'] ) ) {
				echo ' ' . esc_html( sprintf( /* translators: %s: job ID */ __( 'Job ID: %s', 'earthhaul-bulk-pages' ), $result['job_id'] ) );
			}
		}
		echo '</strong></p></div>';

		if ( ! empty( $errs ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Errors:', 'earthhaul-bulk-pages' ) . '</strong></p><ul>';
			foreach ( $errs as $err ) {
				echo '<li>' . esc_html( $err ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( $dry && ! empty( $result['preview'] ) ) {
			echo '<h3>' . esc_html__( 'Preview (would create these pages)', 'earthhaul-bulk-pages' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th>Slug</th><th>City</th><th>State</th><th>Title</th></tr></thead><tbody>';
			foreach ( $result['preview'] as $row ) {
				printf(
					'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $row['slug'] ),
					esc_html( $row['city'] ),
					esc_html( $row['state'] ),
					esc_html( $row['title'] )
				);
			}
			echo '</tbody></table>';
		}

		if ( ! $dry && ! empty( $result['created'] ) ) {
			echo '<h3>' . esc_html__( 'Created pages (drafts)', 'earthhaul-bulk-pages' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th>City</th><th>Slug</th><th>Title</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $result['created'] as $created ) {
				$edit    = get_edit_post_link( (int) $created['post_id'] );
				$preview = get_preview_post_link( (int) $created['post_id'] );
				printf(
					'<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td><td><a href="%4$s">%5$s</a> | <a href="%6$s" target="_blank">%7$s</a></td></tr>',
					esc_html( $created['city'] ),
					esc_html( $created['slug'] ),
					esc_html( $created['title'] ),
					esc_url( $edit ),
					esc_html__( 'Edit', 'earthhaul-bulk-pages' ),
					esc_url( $preview ),
					esc_html__( 'Preview', 'earthhaul-bulk-pages' )
				);
			}
			echo '</tbody></table>';
		}
	}
}
