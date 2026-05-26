<?php
/**
 * Single-city orchestrator for the unified Bulk Job flow.
 *
 * One call to {@see Bulk_Job_Runner::run_city} performs the complete end-
 * to-end pipeline for one CSV row:
 *   1. Resolve target post (clone or reuse existing slug).
 *   2. Apply neighborhoods (Neighborhoods_Applier writes BB layout itself).
 *   3. Reload layout, run text rewrite engine, mutate layout in-memory.
 *   4. Run image pipeline (sideload + alt + layout reference rewrite).
 *   5. Run Yoast meta engine (post_meta only).
 *   6. Persist layout to draft + published slots, bust asset cache.
 *
 * The runner is deliberately stateless. The Bulk Job admin screen feeds
 * it one config + one CSV row at a time via an AJAX chunked loop, which
 * is what keeps the per-request runtime well under any web server limit.
 *
 * Returns a per-city result summary the caller persists into the job
 * state and renders into the progress UI.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bulk_Job_Runner {

	/**
	 * Process one city through the full pipeline.
	 *
	 * @param int    $template_id    Beaver Builder template post ID (e.g. Orlando).
	 * @param array  $row            Validated cities-CSV row: [slug, city, state, ...extras].
	 * @param array  $config {
	 *     Run configuration.
	 *
	 *     @type int    $parent_id              Parent page ID for new clones (0 = top-level).
	 *     @type string $title_fmt              Title template (Page_Cloner::format_title tokens).
	 *     @type string $job_id                 Job ID stamped on cloned drafts.
	 *     @type string $source_label           "Orlando, Florida" - parsed from template title.
	 *     @type string $source_token           "orlando" - city slug used for filename swaps.
	 *     @type bool   $skip_existing          Reuse existing cloned draft for this slug if found.
	 *     @type array  $pipelines              ['text'=>bool, 'images'=>bool, 'meta'=>bool].
	 *     @type string $page_slug_override     When non-empty, every cloned page gets THIS slug
	 *                                          (e.g. "concrete-dumpster-rentals") instead of the
	 *                                          row's `slug` column. The CSV slug is still used
	 *                                          for parent lookup + neighborhoods keys.
	 *     @type bool   $parent_lookup_by_city  When true, ignore $parent_id and look up the
	 *                                          parent post per-row by finding the cloned page
	 *                                          whose `_ehbp_city_slug` matches the row's slug
	 *                                          AND whose `_ehbp_source_post_id` equals the
	 *                                          configured location-template ID. Used for
	 *                                          service sub-pages that need to live as children
	 *                                          of each city's existing location page.
	 *     @type int    $location_template_id   Source-post-id filter for the dynamic parent
	 *                                          lookup. Required when parent_lookup_by_city is on.
	 * }
	 * @param array  $neighborhoods  Plain-text neighborhood names for this city slug. Empty = skip step.
	 * @return array Result summary - see implementation for shape.
	 */
	public static function run_city( int $template_id, array $row, array $config, array $neighborhoods ): array {
		$slug         = (string) ( $row['slug'] ?? '' );
		$city         = (string) ( $row['city'] ?? '' );
		$state        = (string) ( $row['state'] ?? '' );
		$target_label = trim( $city . ( '' !== $state ? ', ' . $state : '' ), ', ' );

		$result = array(
			'slug'           => $slug,
			'city'           => $city,
			'state'          => $state,
			'target_label'   => $target_label,
			'post_id'        => 0,
			'action'         => '',         // 'cloned' | 'reused' | ''
			'edit_url'       => '',
			'preview_url'    => '',
			'title'          => '',
			'neighborhoods'  => array( 'attempted' => false, 'ok' => false, 'modules' => 0, 'count' => 0, 'inline_fields' => 0, 'message' => '' ),
			'text'           => array( 'attempted' => false, 'applied' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => array() ),
			'images'         => array( 'attempted' => false, 'applied' => 0, 'failed' => 0, 'candidates' => 0, 'errors' => array() ),
			'meta'           => array( 'attempted' => false, 'applied' => 0, 'failed' => 0 ),
			'debug'          => array( 'source_label' => '', 'source_token' => '', 'target_token' => '' ),
			'errors'         => array(),
		);

		// 1. Resolve target post.
		$post_id = self::resolve_post( $template_id, $row, $config );
		if ( is_wp_error( $post_id ) ) {
			$result['errors'][] = $post_id->get_error_message();
			return $result;
		}
		$result['post_id']  = (int) $post_id;
		$result['action']   = (string) get_post_meta( $post_id, '_ehbp_bulk_resolution', true );
		// _ehbp_bulk_resolution is a transient marker we set in resolve_post
		// just to communicate clone/reuse back here. Clear it so it doesn't
		// linger as post meta on the saved draft.
		delete_post_meta( $post_id, '_ehbp_bulk_resolution' );
		$result['title']       = (string) get_the_title( $post_id );
		$result['edit_url']    = (string) get_edit_post_link( $post_id, 'raw' );
		$result['preview_url'] = (string) get_preview_post_link( $post_id );

		// 2. Neighborhoods (independent of the layout we're about to load
		// because the applier persists immediately on its own; we re-load
		// after this so subsequent text/image edits see the updated layout).
		if ( ! empty( $neighborhoods ) ) {
			$result['neighborhoods']['attempted'] = true;
			$result['neighborhoods']['count']     = count( $neighborhoods );
			$applied = Neighborhoods_Applier::apply( (int) $post_id, $neighborhoods );
			$result['neighborhoods']['ok']      = (bool) ( $applied['ok'] ?? false );
			$result['neighborhoods']['modules'] = is_array( $applied['modules'] ?? null ) ? count( $applied['modules'] ) : 0;
			$result['neighborhoods']['message'] = (string) ( $applied['message'] ?? '' );
		}

		// 3. Load the now-current layout for in-memory text + image edits.
		$layout = Layout_Mutator::load_layout( (int) $post_id );
		if ( empty( $layout ) ) {
			$result['errors'][] = 'Could not load Beaver Builder layout for post ' . $post_id . '.';
			return $result;
		}

		$pipelines    = (array) ( $config['pipelines'] ?? array() );
		$source_label = (string) ( $config['source_label'] ?? '' );
		$source_token = (string) ( $config['source_token'] ?? '' );
		$target_token = '' !== $city ? sanitize_title( $city ) : '';

		$result['debug']['source_label'] = $source_label;
		$result['debug']['source_token'] = $source_token;
		$result['debug']['target_token'] = $target_token;

		// 3a. Inline-list neighborhood substitution. Replaces content
		// between <!--ehbp-neighborhoods[:N]-->...<!--/ehbp-neighborhoods-->
		// markers anywhere in the layout's text/html settings. Runs
		// BEFORE the text rewrite so the surrounding sentence (e.g.
		// "throughout Orlando, FL") gets localized in the same pass.
		$inline_fields = 0;
		if ( ! empty( $neighborhoods ) ) {
			$inline_fields = Neighborhoods_Inline_Substituter::apply_to_layout( $layout, $neighborhoods );
			if ( $inline_fields > 0 ) {
				$result['neighborhoods']['attempted']     = true;
				$result['neighborhoods']['inline_fields'] = $inline_fields;
				$result['neighborhoods']['count']         = max(
					(int) $result['neighborhoods']['count'],
					count( $neighborhoods )
				);
				// If Neighborhoods_Applier didn't fire (no class-tagged
				// module on the page) but inline markers did, surface
				// that as a successful neighborhoods step in the UI.
				if ( ! $result['neighborhoods']['ok'] ) {
					$result['neighborhoods']['ok']      = true;
					$result['neighborhoods']['message'] = sprintf(
						__( 'Substituted neighborhoods inline in %d field(s).', 'earthhaul-bulk-pages' ),
						$inline_fields
					);
				}
			}
		}

		// 4. Text rewrite pipeline.
		if ( ! empty( $pipelines['text'] ) ) {
			$result['text']['attempted'] = true;
			$walker     = Layout_Walker::inspect( (int) $post_id );
			$candidates = ! empty( $walker['nodes'] )
				? Rewrite_Engine::collect_candidates( $walker, $source_label, $target_label )
				: array();
			$results    = ! empty( $candidates )
				? Rewrite_Engine::run( $candidates, $source_label, $target_label )
				: array();

			foreach ( $results as $r ) {
				if ( ! empty( $r['error'] ) ) {
					$result['text']['failed']++;
					$result['text']['errors'][] = (string) $r['error'];
					continue;
				}
				if ( ! empty( $r['skipped'] ) ) {
					$result['text']['skipped']++;
					continue;
				}
				$node_id = (string) ( $r['node_id'] ?? '' );
				$path    = (string) ( $r['path'] ?? '' );
				$value   = (string) ( $r['suggestion'] ?? '' );

				if ( '' === $node_id || '' === $path || '' === $value ) {
					$result['text']['failed']++;
					continue;
				}
				if ( ! isset( $layout[ $node_id ] ) || ! is_object( $layout[ $node_id ] ) || ! isset( $layout[ $node_id ]->settings ) ) {
					$result['text']['failed']++;
					continue;
				}

				if ( Layout_Mutator::set_at_path( $layout[ $node_id ]->settings, $path, $value ) ) {
					$result['text']['applied']++;
				} else {
					$result['text']['failed']++;
				}
			}
		}

		// 5. Image pipeline. Walks rows/columns/modules + featured image.
		if ( ! empty( $pipelines['images'] ) ) {
			$result['images']['attempted'] = true;
			// Re-walk for image candidates so we see the post-text-edit
			// layout. (The walker's image collection is class/key-driven
			// and unaffected by the text edits, but re-walking keeps
			// things consistent and is cheap.)
			$walker     = Layout_Walker::inspect( (int) $post_id );
			$candidates = ! empty( $walker['nodes'] )
				? Image_Pipeline::collect_candidates( $walker, (int) $post_id, $source_token )
				: array();
			$result['images']['candidates'] = count( $candidates );
			$results    = ! empty( $candidates )
				? Image_Pipeline::run( $candidates, $source_label, $target_label, $source_token, $target_token )
				: array();

			$to_apply = array();
			foreach ( $results as $r ) {
				if ( ! empty( $r['error'] ) ) {
					$result['images']['errors'][] = (string) $r['error'];
					continue;
				}
				$to_apply[] = $r;
			}
			if ( ! empty( $to_apply ) ) {
				$summary = Image_Pipeline::apply( $layout, (int) $post_id, $to_apply );
				$result['images']['applied'] = (int) ( $summary['applied'] ?? 0 );
				$result['images']['failed']  = (int) ( $summary['failed'] ?? 0 );
				if ( ! empty( $summary['errors'] ) ) {
					$result['images']['errors'] = array_merge( $result['images']['errors'], (array) $summary['errors'] );
				}
			}
		}

		// 6. Yoast meta. Pure post_meta updates.
		if ( ! empty( $pipelines['meta'] ) ) {
			$result['meta']['attempted'] = true;
			// Pass the source template post so we always re-substitute
			// from the original Yoast meta, never from a previously-
			// substituted (and possibly truncated) value on a reused
			// clone. _ehbp_source_post_id is stamped by Page_Cloner.
			$source_post_id = (int) get_post_meta( (int) $post_id, Page_Cloner::META_SOURCE_POST_ID, true );
			if ( $source_post_id <= 0 ) {
				$source_post_id = (int) $template_id;
			}
			$candidates = Yoast_Meta_Engine::collect_candidates( (int) $post_id, $source_label, $target_label, $source_post_id );
			$results    = ! empty( $candidates )
				? Yoast_Meta_Engine::run( $candidates, $source_label, $target_label )
				: array();

			$to_apply = array();
			foreach ( $results as $r ) {
				if ( ! empty( $r['error'] ) ) {
					continue;
				}
				$to_apply[] = $r;
			}
			if ( ! empty( $to_apply ) ) {
				$summary = Yoast_Meta_Engine::apply( (int) $post_id, $to_apply );
				$result['meta']['applied'] = (int) ( $summary['applied'] ?? 0 );
				$result['meta']['failed']  = (int) ( $summary['failed'] ?? 0 );
			}
		}

		// 7. Persist layout (covers text + image edits AND any inline
		// neighborhoods substitution that fired in step 3a).
		if ( ! empty( $pipelines['text'] ) || ! empty( $pipelines['images'] ) || $inline_fields > 0 ) {
			Layout_Mutator::persist_layout( (int) $post_id, $layout );
		}

		return $result;
	}

	/**
	 * Either reuse an existing cloned draft for this slug (if skip_existing
	 * is set and one exists under the configured parent) or clone the
	 * template. We mark the chosen path on _ehbp_bulk_resolution so the
	 * caller can surface "cloned" vs "reused" in the UI.
	 *
	 * @return int|WP_Error
	 */
	private static function resolve_post( int $template_id, array $row, array $config ) {
		$row_slug      = sanitize_title( (string) ( $row['slug'] ?? '' ) );
		$skip_existing = ! empty( $config['skip_existing'] );

		if ( '' === $row_slug ) {
			return new WP_Error( 'ehbp_bad_slug', __( 'Empty slug in CSV row.', 'earthhaul-bulk-pages' ) );
		}

		// Auto-detect "service sub-page mode": if the source template
		// page lives nested under a location page (depth >= 2 in the
		// WP page tree), we know the user wants service-mode and can
		// fill every config field from the page tree without asking.
		//
		// The manual override fields on the form are only consulted
		// when the user explicitly ticks "Force dynamic parent lookup".
		// That keeps the priority simple: ticked = trust me, do
		// exactly what the form says; unticked = auto-detect wins.
		$auto = self::auto_detect_service_mode( $template_id );
		if ( ! empty( $auto['service_mode'] ) && empty( $config['parent_lookup_by_city'] ) ) {
			$config['parent_lookup_by_city'] = true;
			$config['location_template_id']  = (int) $auto['location_template_id'];
			if ( empty( $config['page_slug_override'] ) ) {
				$config['page_slug_override'] = (string) $auto['page_slug_override'];
			}
		}

		// Resolve the parent for this row. Dynamic mode (used for service
		// sub-pages) looks up the city's existing location page so the
		// new clone lands as its child; static mode just uses the
		// configured parent ID for every row.
		if ( ! empty( $config['parent_lookup_by_city'] ) ) {
			$location_template_id = (int) ( $config['location_template_id'] ?? 0 );
			if ( $location_template_id <= 0 ) {
				return new WP_Error(
					'ehbp_no_location_template',
					__( 'Dynamic parent lookup requires a location_template_id (the original Orlando-shaped template the city pages were cloned from).', 'earthhaul-bulk-pages' )
				);
			}
			$parent_id = self::find_location_page_for_city( $row_slug, $location_template_id );
			if ( $parent_id <= 0 ) {
				return new WP_Error(
					'ehbp_no_location_parent',
					sprintf(
						/* translators: %s: city slug */
						__( 'Could not find an existing location page for slug "%s". Run the location job for this city first.', 'earthhaul-bulk-pages' ),
						$row_slug
					)
				);
			}
		} else {
			$parent_id = (int) ( $config['parent_id'] ?? 0 );
		}

		// Decide the post slug. Service / sub-page jobs override with a
		// fixed slug ("concrete-dumpster-rentals") so all cloned pages
		// share it; cities are differentiated by the parent path. Default
		// behavior (no override) keeps using the CSV's per-row slug.
		$page_slug_override = (string) ( $config['page_slug_override'] ?? '' );
		$post_slug          = '' !== $page_slug_override
			? sanitize_title( $page_slug_override )
			: $row_slug;

		if ( $skip_existing ) {
			$existing = self::find_existing_clone( $post_slug, $parent_id );
			if ( $existing > 0 ) {
				update_post_meta( $existing, '_ehbp_bulk_resolution', 'reused' );
				return $existing;
			}
		}

		// Page_Cloner reads $row['slug'] for the post slug. When the user
		// asked for a slug override we swap it in BEFORE handing off so
		// the cloner doesn't have to learn about override semantics.
		$cloner_row = $row;
		if ( '' !== $page_slug_override ) {
			$cloner_row['slug'] = $post_slug;
		}

		$new_id = Page_Cloner::clone_for_row(
			$template_id,
			$cloner_row,
			(string) ( $config['title_fmt'] ?? Page_Cloner::format_title( '{{city}}, {{state}}', $row ) ),
			$parent_id,
			(string) ( $config['job_id'] ?? '' )
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Stamp the ORIGINAL row slug as _ehbp_city_slug so neighborhoods
		// + future parent lookups still work even when the post slug got
		// overridden to "concrete-dumpster-rentals" or similar.
		update_post_meta( (int) $new_id, Page_Cloner::META_CITY_SLUG, $row_slug );
		update_post_meta( (int) $new_id, '_ehbp_bulk_resolution', 'cloned' );
		return (int) $new_id;
	}

	/**
	 * Decide whether a chosen template is a "service sub-page" — a page
	 * authored under a city/location page rather than at the city level
	 * itself. If so, return the inferred config so the runner can wire
	 * dynamic-parent-lookup + slug-override automatically without
	 * making the user re-type information that's already on the page.
	 *
	 * Detection rule (tree-based, source-of-truth = WP page hierarchy):
	 *   - Template has a non-empty post_parent, AND
	 *   - That parent itself has a non-empty post_parent.
	 *
	 * Why depth-2: a normal location template like "Orlando, FL" sits
	 * directly under a top-level "Locations" directory page, so its
	 * grandparent is 0 → standard location-cloning mode. A service
	 * sub-page like "Orlando Concrete Disposal Dumpster Rentals" sits
	 * under "Orlando, FL", which itself sits under "Locations" → depth
	 * 2 → service-page mode. This works for BOTH plugin-cloned city
	 * pages (with our meta stamps) and manually-authored city pages
	 * (no meta stamps), because we don't read postmeta for detection.
	 *
	 * Per-row parent resolution still tries the strict meta-based
	 * lookup first, then falls back to a tree-based lookup; see
	 * {@see find_location_page_for_city()}.
	 *
	 * @return array{
	 *   service_mode: bool,
	 *   location_template_id?: int,
	 *   page_slug_override?: string,
	 *   location_template_title?: string,
	 * }
	 */
	public static function auto_detect_service_mode( int $template_id ): array {
		$tpl = get_post( $template_id );
		if ( ! $tpl || (int) $tpl->post_parent <= 0 ) {
			return array( 'service_mode' => false );
		}

		$parent = get_post( (int) $tpl->post_parent );
		if ( ! $parent || (int) $parent->post_parent <= 0 ) {
			// Template's parent is a top-level page (typical
			// "Locations" directory). Looks like a normal
			// city-cloning template, not a service sub-page.
			return array( 'service_mode' => false );
		}

		return array(
			'service_mode'            => true,
			'location_template_id'    => (int) $tpl->post_parent,
			'page_slug_override'      => (string) $tpl->post_name,
			'location_template_title' => (string) get_the_title( (int) $tpl->post_parent ),
		);
	}

	/**
	 * Find the existing location-page post for a given city slug.
	 *
	 * Two-stage lookup so this works for BOTH plugin-cloned city pages
	 * and pages that the user authored by hand:
	 *
	 *   Stage 1 (strict, plugin-cloned cities):
	 *     The page (a) carries our `_ehbp_city_slug` meta matching the
	 *     slug, AND (b) has been cloned from the configured location
	 *     template. This is the cleanest match because it can't be
	 *     fooled by an unrelated page that happens to share a slug.
	 *
	 *   Stage 2 (tree-based, manually-authored cities):
	 *     A page whose post_name matches the city slug AND whose
	 *     post_parent is the same as the location template's
	 *     post_parent (i.e., a sibling of the location template under
	 *     the shared "Locations" directory). This catches the manual-
	 *     authoring case, where the user typed a "Winter Haven, FL"
	 *     page next to "Orlando, FL" without ever using the bulk job.
	 *
	 * Returns 0 if neither stage finds a match.
	 */
	private static function find_location_page_for_city( string $city_slug, int $location_template_id ): int {
		global $wpdb;

		// Stage 1: strict meta-based lookup.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_slug ON pm_slug.post_id = p.ID AND pm_slug.meta_key = %s AND pm_slug.meta_value = %s
				INNER JOIN {$wpdb->postmeta} pm_src  ON pm_src.post_id  = p.ID AND pm_src.meta_key  = %s AND pm_src.meta_value = %d
				WHERE p.post_type = 'page'
				  AND p.post_status IN ('publish','draft','private','pending','future')
				ORDER BY p.post_modified DESC
				LIMIT 1
				",
				Page_Cloner::META_CITY_SLUG,
				$city_slug,
				Page_Cloner::META_SOURCE_POST_ID,
				$location_template_id
			)
		);
		if ( $post_id > 0 ) {
			return $post_id;
		}

		// Stage 2: tree-based fallback. Find a page that lives at the
		// same depth as the location template (i.e., shares its
		// post_parent) and whose slug matches the CSV row.
		$tpl = get_post( $location_template_id );
		if ( ! $tpl ) {
			return 0;
		}
		$shared_parent = (int) $tpl->post_parent;

		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = 'page'
				  AND post_name = %s
				  AND post_parent = %d
				  AND post_status IN ('publish','draft','private','pending','future')
				ORDER BY post_modified DESC
				LIMIT 1
				",
				$city_slug,
				$shared_parent
			)
		);

		return $post_id;
	}

	/**
	 * Find an existing cloned draft for this slug under the given parent.
	 * Requires the page to carry our META_SOURCE_POST_ID marker so we don't
	 * accidentally adopt unrelated pages that happen to share a slug.
	 *
	 * Returns 0 if no match.
	 */
	private static function find_existing_clone( string $slug, int $parent_id ): int {
		global $wpdb;

		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = 'page'
				  AND p.post_name = %s
				  AND p.post_parent = %d
				  AND p.post_status IN ('publish','draft','private','pending')
				LIMIT 1
				",
				Page_Cloner::META_SOURCE_POST_ID,
				$slug,
				$parent_id
			)
		);

		return $post_id;
	}
}
