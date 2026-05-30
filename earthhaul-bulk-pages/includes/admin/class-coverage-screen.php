<?php
/**
 * Coverage Audit screen: per-location matrix showing which service
 * sub-pages exist and which are missing.
 *
 * Use case: before running the Patch Pages job to add "Dumpsters for
 * every job" cards (with links to the 3 service sub-pages on each
 * location) you want to confirm that every location actually HAS those
 * 3 sub-pages. If any are missing, the patched buttons would 404 on the
 * affected pages.
 *
 * Detection rules:
 *   - A "location" is any page whose slug matches a `*-fl` style city
 *     pattern AND has its parent set to the Locations parent page (or
 *     the page slug starts with `locations/`). We additionally include
 *     plugin-cloned pages stamped with `_ehbp_page_type = location` if
 *     that meta is present.
 *   - A "service sub-page" is any page whose post_parent is a location
 *     page AND whose slug starts with one of the configured service
 *     slugs (concrete, landscaping, moving). Slugs are configurable on
 *     the screen so non-default service slugs (e.g.
 *     `concrete-disposal-dumpster-rentals`) are matched correctly.
 *
 * Output: a table with columns:
 *   City | Concrete | Landscaping | Moving | Quick Actions
 * Each cell shows OK (green check) or MISSING (red X with edit link
 * to the location page). A summary above the table tallies coverage.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coverage_Screen {

	public const PAGE_SLUG = 'ehbp-coverage';

	/**
	 * Default service-slug fragments. We do prefix matching against the
	 * child page's `post_name`, so a stored slug of
	 * `concrete-disposal-dumpster-rentals` is matched by the fragment
	 * `concrete`. The screen lets the user override these per-run.
	 *
	 * Order = display order in the matrix.
	 */
	private const DEFAULT_SERVICE_SLUGS = array(
		'concrete'    => 'concrete',
		'landscaping' => 'landscaping',
		'moving'      => 'moving',
	);

	public static function register(): void {
		// No write actions yet; render-only screen.
	}

	public static function render(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'earthhaul-bulk-pages' ) );
		}

		$service_filters = self::resolve_service_filters();
		$locations       = self::collect_locations();
		$matrix          = self::build_matrix( $locations, $service_filters );

		$total       = count( $matrix );
		$fully       = 0;
		$partial     = 0;
		$missing_all = 0;
		$missing_per = array_fill_keys( array_keys( $service_filters ), 0 );
		foreach ( $matrix as $row ) {
			$present = 0;
			foreach ( $service_filters as $key => $_frag ) {
				if ( ! empty( $row['services'][ $key ] ) ) {
					$present++;
				} else {
					$missing_per[ $key ]++;
				}
			}
			if ( $present === count( $service_filters ) ) {
				$fully++;
			} elseif ( 0 === $present ) {
				$missing_all++;
			} else {
				$partial++;
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Pages - Coverage Audit', 'earthhaul-bulk-pages' ); ?></h1>
			<p>
				<?php esc_html_e( 'Per-location matrix of which service sub-pages exist. Use this before running Patch Pages to confirm every location has the 3 service pages so the patched buttons do not 404.', 'earthhaul-bulk-pages' ); ?>
			</p>

			<form method="get" style="margin-bottom:14px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<table class="form-table" role="presentation" style="max-width:760px;">
					<tr>
						<th scope="row" style="width:200px;"><?php esc_html_e( 'Concrete slug fragment', 'earthhaul-bulk-pages' ); ?></th>
						<td>
							<input type="text" name="concrete" class="regular-text" value="<?php echo esc_attr( $service_filters['concrete'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Landscaping slug fragment', 'earthhaul-bulk-pages' ); ?></th>
						<td>
							<input type="text" name="landscaping" class="regular-text" value="<?php echo esc_attr( $service_filters['landscaping'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Moving / Home slug fragment', 'earthhaul-bulk-pages' ); ?></th>
						<td>
							<input type="text" name="moving" class="regular-text" value="<?php echo esc_attr( $service_filters['moving'] ); ?>">
							<p class="description"><?php esc_html_e( 'Slug fragments are matched against each child page\'s post_name with a starts-with check, e.g. "concrete" matches "concrete-disposal-dumpster-rentals".', 'earthhaul-bulk-pages' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Re-check', 'earthhaul-bulk-pages' ), 'primary', '', false ); ?>
			</form>

			<div style="display:flex;gap:14px;margin:14px 0;flex-wrap:wrap;">
				<?php
				self::render_stat_card( __( 'Total locations', 'earthhaul-bulk-pages' ), (string) $total, '#2271b1' );
				self::render_stat_card( __( 'All 3 services present', 'earthhaul-bulk-pages' ), (string) $fully, '#1b873e' );
				self::render_stat_card( __( 'Partially covered', 'earthhaul-bulk-pages' ), (string) $partial, '#dba617' );
				self::render_stat_card( __( 'Missing all 3 services', 'earthhaul-bulk-pages' ), (string) $missing_all, '#c62828' );
				?>
			</div>

			<?php if ( $total > 0 ) : ?>
				<p>
					<strong><?php esc_html_e( 'Missing per service:', 'earthhaul-bulk-pages' ); ?></strong>
					<?php
					$bits = array();
					foreach ( $missing_per as $key => $n ) {
						$bits[] = sprintf( '%s: <strong>%d</strong>', esc_html( ucfirst( $key ) ), (int) $n );
					}
					echo wp_kses_post( implode( ' &middot; ', $bits ) );
					?>
				</p>
			<?php endif; ?>

			<?php if ( empty( $matrix ) ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'No location pages found. Locations are detected by post_parent under the "Locations" page or by slugs ending in "-{state}". Run a New Job first if you have not yet cloned any.', 'earthhaul-bulk-pages' ); ?>
				</p></div>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:10px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Location', 'earthhaul-bulk-pages' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'earthhaul-bulk-pages' ); ?></th>
							<?php foreach ( array_keys( $service_filters ) as $key ) : ?>
								<th style="text-align:center;"><?php echo esc_html( ucfirst( $key ) ); ?></th>
							<?php endforeach; ?>
							<th><?php esc_html_e( 'Quick links', 'earthhaul-bulk-pages' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $matrix as $row ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $row['title'] ); ?></strong>
								<?php if ( '' !== $row['city'] ) : ?>
									<div style="color:#646970;font-size:12px;"><?php echo esc_html( $row['city'] ); ?></div>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $row['slug'] ); ?></code></td>
							<?php foreach ( $service_filters as $key => $_frag ) : ?>
								<td style="text-align:center;">
									<?php
									$svc = $row['services'][ $key ] ?? null;
									if ( $svc ) {
										printf(
											'<span title="%s" style="color:#1b873e;font-weight:600;">&#10003;</span>',
											esc_attr( $svc['title'] . ' [' . $svc['status'] . ']' )
										);
									} else {
										echo '<span style="color:#c62828;font-weight:600;">&#10007;</span>';
									}
									?>
								</td>
							<?php endforeach; ?>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'earthhaul-bulk-pages' ); ?></a>
								&middot;
								<a href="<?php echo esc_url( get_permalink( (int) $row['id'] ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'earthhaul-bulk-pages' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Read service-slug fragment overrides from the query string, falling
	 * back to defaults. Trims and lowers each value; empties revert to
	 * the default to avoid an empty fragment matching every page.
	 *
	 * @return array<string, string> service key => slug fragment
	 */
	private static function resolve_service_filters(): array {
		$out = self::DEFAULT_SERVICE_SLUGS;
		foreach ( $out as $key => $default ) {
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}
			$raw = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
			$raw = strtolower( trim( $raw ) );
			if ( '' === $raw ) {
				continue;
			}
			$out[ $key ] = $raw;
		}
		return $out;
	}

	/**
	 * Discover every location page on the site. Three signals, in
	 * priority order:
	 *
	 *   1. `_ehbp_page_type = location` meta — most reliable since
	 *      plugin-cloned location pages always carry this. Concrete /
	 *      landscaping / moving service pages have
	 *      `_ehbp_page_type = service` so they're excluded.
	 *   2. Pages whose post_parent's slug is `locations` — covers
	 *      non-cloned legacy location pages authored by hand.
	 *   3. Plugin-cloned pages (have `_ehbp_source_post_id`) that are
	 *      direct children of the Locations parent — fallback for
	 *      historical clones missing `_ehbp_page_type` meta.
	 *
	 * Returns one entry per detected location, deduplicated by ID and
	 * sorted alphabetically by title.
	 *
	 * @return array<int, array{id:int, title:string, slug:string, status:string, city:string}>
	 */
	private static function collect_locations(): array {
		global $wpdb;

		$locations_parent_id = self::find_locations_parent_id();

		// 1) Pages flagged as location type by the plugin.
		$by_meta = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT p.ID, p.post_title, p.post_name, p.post_status,
					COALESCE(mc.meta_value, '') AS city_name,
					COALESCE(ms.meta_value, '') AS state
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pt ON pt.post_id = p.ID AND pt.meta_key = %s AND pt.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} mc ON mc.post_id = p.ID AND mc.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = %s
				WHERE p.post_type = 'page'
				  AND p.post_status IN ('publish','draft','private','pending','future')
				",
				'_ehbp_page_type',
				'location',
				'_ehbp_city_name',
				'_ehbp_state'
			),
			ARRAY_A
		);

		// 2) Children of the Locations parent (regardless of meta).
		$by_parent = array();
		if ( $locations_parent_id > 0 ) {
			$by_parent = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT p.ID, p.post_title, p.post_name, p.post_status,
						COALESCE(mc.meta_value, '') AS city_name,
						COALESCE(ms.meta_value, '') AS state
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} mc ON mc.post_id = p.ID AND mc.meta_key = %s
					LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = %s
					WHERE p.post_type = 'page'
					  AND p.post_status IN ('publish','draft','private','pending','future')
					  AND p.post_parent = %d
					",
					'_ehbp_city_name',
					'_ehbp_state',
					$locations_parent_id
				),
				ARRAY_A
			);
		}

		$merged = array();
		foreach ( array( $by_meta, $by_parent ) as $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}
			foreach ( $set as $r ) {
				$id = (int) $r['ID'];
				if ( $id <= 0 || isset( $merged[ $id ] ) ) {
					continue;
				}
				$merged[ $id ] = array(
					'id'     => $id,
					'title'  => (string) $r['post_title'],
					'slug'   => (string) $r['post_name'],
					'status' => (string) $r['post_status'],
					'city'   => trim(
						(string) ( $r['city_name'] ?? '' ) . (
							'' !== (string) ( $r['state'] ?? '' )
								? ', ' . (string) $r['state']
								: ''
						),
						', '
					),
				);
			}
		}

		// Sort alphabetically by title for the table view.
		uasort(
			$merged,
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) $a['title'], (string) $b['title'] );
			}
		);

		return array_values( $merged );
	}

	/**
	 * For each location, look up child pages whose slug starts with the
	 * configured service-slug fragment and stamp the matrix row with
	 * the matching child (if any).
	 *
	 * @param array<int, array<string, mixed>> $locations  Output of collect_locations().
	 * @param array<string, string>            $filters    service key => slug fragment
	 * @return array<int, array<string, mixed>> Same shape with `services` key added.
	 */
	private static function build_matrix( array $locations, array $filters ): array {
		if ( empty( $locations ) ) {
			return array();
		}

		global $wpdb;

		$ids = array_map(
			static fn ( array $loc ): int => (int) $loc['id'],
			$locations
		);
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// Pull every child page in one query, then bucket per location.
		$children = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_status, post_parent
				 FROM {$wpdb->posts}
				 WHERE post_type = 'page'
				   AND post_status IN ('publish','draft','private','pending','future')
				   AND post_parent IN ($placeholders)",
				...$ids
			),
			ARRAY_A
		);

		$by_parent = array();
		foreach ( (array) $children as $c ) {
			$pid = (int) $c['post_parent'];
			$by_parent[ $pid ][] = $c;
		}

		foreach ( $locations as &$loc ) {
			$loc['services'] = array();
			$kids            = $by_parent[ (int) $loc['id'] ] ?? array();
			foreach ( $filters as $key => $fragment ) {
				$loc['services'][ $key ] = self::find_first_matching_child( $kids, $fragment );
			}
		}
		unset( $loc );

		return $locations;
	}

	/**
	 * Return the first child whose post_name starts with $fragment, or
	 * null if none match.
	 *
	 * @param array<int, array<string, mixed>> $kids
	 * @return array<string, mixed>|null
	 */
	private static function find_first_matching_child( array $kids, string $fragment ): ?array {
		if ( '' === $fragment ) {
			return null;
		}
		$frag = strtolower( $fragment );
		foreach ( $kids as $c ) {
			$slug = strtolower( (string) ( $c['post_name'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}
			if ( 0 === strpos( $slug, $frag ) ) {
				return array(
					'id'     => (int) $c['ID'],
					'title'  => (string) $c['post_title'],
					'slug'   => (string) $c['post_name'],
					'status' => (string) $c['post_status'],
				);
			}
		}
		return null;
	}

	/**
	 * Find the ID of the top-level "Locations" page, used to detect
	 * location children that lack `_ehbp_page_type` meta.
	 */
	private static function find_locations_parent_id(): int {
		$candidates = array(
			get_page_by_path( 'locations' ),
		);
		foreach ( $candidates as $page ) {
			if ( $page instanceof \WP_Post ) {
				return (int) $page->ID;
			}
		}
		return 0;
	}

	private static function render_stat_card( string $label, string $value, string $color ): void {
		printf(
			'<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 18px;border-radius:6px;min-width:160px;">
				<div style="color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.04em;">%s</div>
				<div style="font-size:28px;font-weight:600;color:%s;line-height:1.1;margin-top:4px;">%s</div>
			</div>',
			esc_html( $label ),
			esc_attr( $color ),
			esc_html( $value )
		);
	}
}
