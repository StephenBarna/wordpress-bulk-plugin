<?php
/**
 * "Inspect Page" admin screen.
 *
 * Phase 1b.i: read-only inventory of every Beaver Builder node + field on
 * a chosen page. Used to verify the layout walker correctly identifies
 * global vs local nodes BEFORE any AI rewrites or image swaps run.
 *
 * No AI, no writes, no costs - this exists purely so the user can sanity
 * check what the bulk processing would touch on a real page.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

use EarthHaul\BulkPages\Services\Layout_Walker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Inspect_Screen {

	public const PAGE_SLUG = 'ehbp-inspect';

	/**
	 * Action name for the "Download Layout JSON" admin-post handler. Lets the
	 * user dump the raw BB layout + walker output to a file so it can be
	 * inspected offline or shared for debugging.
	 */
	public const DUMP_ACTION = 'ehbp_dump_layout_json';

	public static function register(): void {
		add_action( 'admin_post_' . self::DUMP_ACTION, array( __CLASS__, 'handle_dump' ) );
	}

	public static function render(): void {
		$bb_active = class_exists( '\FLBuilderModel' );
		$post_id   = isset( $_GET['ehbp_post_id'] ) ? (int) $_GET['ehbp_post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Pages - Inspect Page', 'earthhaul-bulk-pages' ); ?></h1>
			<p><?php esc_html_e( 'Walks any page\'s Beaver Builder layout and lists every module, field, and global section. Use this to verify which parts of the page would be rewritten by the AI in Phase 1b.ii. Read-only, no costs.', 'earthhaul-bulk-pages' ); ?></p>

			<?php if ( ! $bb_active ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Beaver Builder is not active.', 'earthhaul-bulk-pages' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<form method="get" action="">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ehbp_post_id"><?php esc_html_e( 'Page to inspect', 'earthhaul-bulk-pages' ); ?></label></th>
						<td>
							<?php self::render_page_dropdown( $post_id ); ?>
							<?php submit_button( __( 'Inspect', 'earthhaul-bulk-pages' ), 'primary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Lists every Beaver Builder page. Pick one to see its module breakdown.', 'earthhaul-bulk-pages' ); ?></p>
						</td>
					</tr>
				</table>
			</form>

			<?php if ( $post_id > 0 ) : ?>
				<?php self::render_inspection( $post_id ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_page_dropdown( int $selected_id ): void {
		global $wpdb;

		$results = $wpdb->get_results(
			"
			SELECT p.ID, p.post_title, p.post_status, p.post_type
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_fl_builder_enabled' AND pm.meta_value = '1'
			WHERE p.post_type IN ('page','post') AND p.post_status IN ('publish','draft','private','future')
			ORDER BY p.post_title ASC
			LIMIT 1000
			"
		);

		echo '<select name="ehbp_post_id" id="ehbp_post_id" class="regular-text" style="max-width: 520px; width: 100%;">';
		echo '<option value="0">' . esc_html__( '-- choose a page --', 'earthhaul-bulk-pages' ) . '</option>';
		foreach ( $results as $row ) {
			printf(
				'<option value="%1$d" %2$s>%3$s (#%1$d, %4$s)</option>',
				(int) $row->ID,
				selected( $selected_id, (int) $row->ID, false ),
				esc_html( $row->post_title ),
				esc_html( $row->post_status )
			);
		}
		echo '</select>';
	}

	private static function render_inspection( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Post not found.', 'earthhaul-bulk-pages' ) . '</p></div>';
			return;
		}

		$inventory = Layout_Walker::inspect( $post_id );
		$rows      = self::group_by_row( $inventory['nodes'] );
		$summary   = $inventory['summary'];

		$dump_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'        => self::DUMP_ACTION,
					'ehbp_post_id'  => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::DUMP_ACTION,
			'ehbp_nonce'
		);
		$dump_url_full = add_query_arg( 'include_raw', '1', $dump_url );
		?>
		<h2 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<?php echo esc_html( sprintf( /* translators: 1: post title, 2: post ID */ __( 'Inspection: %1$s (#%2$d)', 'earthhaul-bulk-pages' ), $post->post_title, $post_id ) ); ?>
			<a class="button" href="<?php echo esc_url( $dump_url ); ?>"><?php esc_html_e( 'Download Walker JSON', 'earthhaul-bulk-pages' ); ?></a>
			<a class="button button-link" href="<?php echo esc_url( $dump_url_full ); ?>"><?php esc_html_e( '+ raw layout', 'earthhaul-bulk-pages' ); ?></a>
		</h2>
		<p class="description" style="margin-top:-6px;"><?php esc_html_e( 'Default download = walker output + unknown-module list (small, easy to share). Add the raw layout only if we need to debug something the walker missed.', 'earthhaul-bulk-pages' ); ?></p>

		<div class="ehbp-summary" style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-bottom:16px;display:flex;gap:24px;flex-wrap:wrap;">
			<div><strong><?php esc_html_e( 'Rows', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $summary['rows']; ?></div>
			<div><strong><?php esc_html_e( 'Columns', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $summary['columns']; ?></div>
			<div><strong><?php esc_html_e( 'Modules', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $summary['modules']; ?></div>
			<div><strong style="color:#b26500;"><?php esc_html_e( 'Globals (skipped)', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $summary['globals']; ?></div>
			<div><strong style="color:#2e7d32;"><?php esc_html_e( 'Text fields', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $summary['text_fields']; ?></div>
			<div><strong><?php esc_html_e( 'HTML fields', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $summary['html_fields']; ?></div>
			<div><strong><?php esc_html_e( 'Image fields', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $summary['image_fields']; ?></div>
			<div><strong><?php esc_html_e( 'URL fields', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) ( $summary['url_fields'] ?? 0 ); ?></div>
			<?php if ( ! empty( $summary['unknown_modules'] ) ) : ?>
				<div><strong style="color:#b71c1c;"><?php esc_html_e( 'Unknown module types', 'earthhaul-bulk-pages' ); ?></strong>: <?php echo (int) $summary['unknown_modules']; ?></div>
			<?php endif; ?>
		</div>

		<?php if ( empty( $rows ) ) : ?>
			<p><em><?php esc_html_e( 'No Beaver Builder layout data found on this page.', 'earthhaul-bulk-pages' ); ?></em></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:140px;"><?php esc_html_e( 'Section / Module', 'earthhaul-bulk-pages' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Type', 'earthhaul-bulk-pages' ); ?></th>
					<th style="width:130px;"><?php esc_html_e( 'Field path', 'earthhaul-bulk-pages' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Kind', 'earthhaul-bulk-pages' ); ?></th>
					<th><?php esc_html_e( 'Value preview', 'earthhaul-bulk-pages' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Status', 'earthhaul-bulk-pages' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row_id => $row_entries ) : ?>
				<?php $row_label = self::row_label( $row_entries ); ?>
				<tr style="background:#eef4ff;">
					<td colspan="6"><strong><?php echo esc_html( $row_label ); ?></strong> <code style="color:#666;font-size:11px;"><?php echo esc_html( (string) $row_id ); ?></code></td>
				</tr>
				<?php foreach ( $row_entries as $entry ) : ?>
					<?php
					$is_global   = ! empty( $entry['is_global'] );
					$module_name = $entry['module_slug'] ?: $entry['type'];
					$bg          = $is_global ? '#fff8e1' : '';
					?>
					<?php if ( empty( $entry['fields'] ) ) : ?>
						<?php
						$schema_label = $entry['schema'] ?? 'n/a';
						$is_unknown   = 'unknown' === $schema_label;
						$empty_note   = $is_global
							? __( '(global - subtree skipped)', 'earthhaul-bulk-pages' )
							: ( $is_unknown
								? __( '(unknown module type - not in schema)', 'earthhaul-bulk-pages' )
								: __( '(no editable fields detected)', 'earthhaul-bulk-pages' ) );
						$status_html = $is_global
							? '<span style="color:#b26500;font-weight:600;">GLOBAL</span>'
							: ( $is_unknown ? '<span style="color:#b71c1c;font-weight:600;">unknown</span>' : '<span style="color:#666;">local</span>' );
						$row_bg      = $is_unknown && ! $is_global ? '#fff0f0' : $bg;
						?>
						<tr style="background:<?php echo esc_attr( $row_bg ); ?>;">
							<td><?php echo esc_html( $entry['label'] ); ?></td>
							<td><?php echo esc_html( $module_name ); ?></td>
							<td>-</td>
							<td>-</td>
							<td><em><?php echo esc_html( $empty_note ); ?></em></td>
							<td><?php echo $status_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $entry['fields'] as $i => $field ) : ?>
							<tr style="background:<?php echo esc_attr( $bg ); ?>;">
								<td><?php echo $i === 0 ? esc_html( $entry['label'] ) : ''; ?></td>
								<td><?php echo $i === 0 ? esc_html( $module_name ) : ''; ?></td>
								<td><code style="font-size:11px;"><?php echo esc_html( $field['path'] ); ?></code></td>
								<td><?php echo esc_html( $field['kind'] ); ?></td>
								<td><?php echo esc_html( self::preview_value( $field['value'] ) ); ?></td>
								<td><?php echo $is_global ? '<span style="color:#b26500;font-weight:600;">GLOBAL</span>' : '<span style="color:#2e7d32;">to rewrite</span>'; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function group_by_row( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			$row_id = (string) ( $node['row_id'] ?? $node['node_id'] ?? '' );
			if ( ! isset( $out[ $row_id ] ) ) {
				$out[ $row_id ] = array();
			}
			$out[ $row_id ][] = $node;
		}
		return $out;
	}

	private static function row_label( array $row_entries ): string {
		// Prefer the row node itself if present, else use the first module label.
		foreach ( $row_entries as $entry ) {
			if ( 'row' === ( $entry['type'] ?? '' ) ) {
				return ! empty( $entry['is_global'] )
					? __( 'GLOBAL ROW', 'earthhaul-bulk-pages' )
					: ( $entry['label'] ?: __( 'Row', 'earthhaul-bulk-pages' ) );
			}
		}
		foreach ( $row_entries as $entry ) {
			if ( ! empty( $entry['label'] ) ) {
				return $entry['label'];
			}
		}
		return __( 'Section', 'earthhaul-bulk-pages' );
	}

	private static function preview_value( string $value ): string {
		$plain = trim( wp_strip_all_tags( $value ) );
		if ( strlen( $plain ) > 140 ) {
			return mb_substr( $plain, 0, 137 ) . '...';
		}
		return $plain;
	}

	/**
	 * admin-post handler: return the layout dump as a JSON download.
	 *
	 * Output bundles three things so reviewers can cross-check them:
	 *   - post           : basic post metadata
	 *   - raw_layout     : the unserialized FLBuilder layout (every node + settings)
	 *   - walker_output  : what Layout_Walker exposes (the fields we'd touch)
	 *   - unknown_modules: any module slugs not in MODULE_SCHEMAS (need adding)
	 */
	public static function handle_dump(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DUMP_ACTION, 'ehbp_nonce' );

		$post_id = isset( $_GET['ehbp_post_id'] ) ? (int) $_GET['ehbp_post_id'] : 0;
		if ( $post_id <= 0 ) {
			wp_die( esc_html__( 'Missing post ID.', 'earthhaul-bulk-pages' ), '', array( 'response' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Post not found.', 'earthhaul-bulk-pages' ), '', array( 'response' => 404 ) );
		}

		if ( ! class_exists( '\FLBuilderModel' ) ) {
			wp_die( esc_html__( 'Beaver Builder is not active.', 'earthhaul-bulk-pages' ), '', array( 'response' => 500 ) );
		}

		$include_raw = isset( $_GET['include_raw'] ) && '1' === (string) $_GET['include_raw']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$raw_layout = \FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( empty( $raw_layout ) ) {
			$raw_layout = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		}

		$walker_output = Layout_Walker::inspect( $post_id );

		$known          = array_flip( Layout_Walker::known_module_types() );
		$unknown_counts = array();
		$unknown_examples = array();
		if ( is_array( $raw_layout ) ) {
			foreach ( $raw_layout as $node ) {
				if ( ! is_object( $node ) || ( $node->type ?? '' ) !== 'module' ) {
					continue;
				}
				$slug = $node->settings->type ?? ( $node->settings->module ?? '' );
				if ( $slug === '' ) {
					continue;
				}
				if ( ! isset( $known[ $slug ] ) ) {
					$unknown_counts[ $slug ] = ( $unknown_counts[ $slug ] ?? 0 ) + 1;
					if ( ! isset( $unknown_examples[ $slug ] ) ) {
						$unknown_examples[ $slug ] = $node->settings ?? null;
					}
				}
			}
		}

		$payload = array(
			'generated_at'    => gmdate( 'c' ),
			'site_url'        => home_url(),
			'plugin_version'  => defined( 'EHBP_VERSION' ) ? EHBP_VERSION : 'unknown',
			'post'            => array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title,
				'slug'      => $post->post_name,
				'type'      => $post->post_type,
				'status'    => $post->post_status,
				'permalink' => get_permalink( $post ),
			),
			'unknown_modules' => array(
				'counts'   => $unknown_counts,
				'examples' => $unknown_examples,
			),
			'walker_output'   => $walker_output,
		);

		if ( $include_raw ) {
			$payload['raw_layout'] = $raw_layout;
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ehbp-layout-' . (int) $post_id . '-' . gmdate( 'YmdHis' ) . '.json"' );

		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		echo $json !== false ? $json : '{"error":"json_encode failed"}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
