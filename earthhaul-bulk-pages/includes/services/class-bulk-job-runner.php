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
	 *     @type int    $parent_id      Parent page ID for new clones (0 = top-level).
	 *     @type string $title_fmt      Title template (Page_Cloner::format_title tokens).
	 *     @type string $job_id         Job ID stamped on cloned drafts.
	 *     @type string $source_label   "Orlando, Florida" - parsed from template title.
	 *     @type string $source_token   "orlando" - city slug used for filename swaps.
	 *     @type bool   $skip_existing  Reuse existing cloned draft for this slug if found.
	 *     @type array  $pipelines      ['text'=>bool, 'images'=>bool, 'meta'=>bool].
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
			'neighborhoods'  => array( 'attempted' => false, 'ok' => false, 'modules' => 0, 'count' => 0, 'message' => '' ),
			'text'           => array( 'attempted' => false, 'applied' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => array() ),
			'images'         => array( 'attempted' => false, 'applied' => 0, 'failed' => 0, 'errors' => array() ),
			'meta'           => array( 'attempted' => false, 'applied' => 0, 'failed' => 0 ),
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
			$candidates = Yoast_Meta_Engine::collect_candidates( (int) $post_id, $source_label, $target_label );
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

		// 7. Persist layout (covers both text + image edits made above).
		if ( ! empty( $pipelines['text'] ) || ! empty( $pipelines['images'] ) ) {
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
		$slug          = sanitize_title( (string) ( $row['slug'] ?? '' ) );
		$parent_id     = (int) ( $config['parent_id'] ?? 0 );
		$skip_existing = ! empty( $config['skip_existing'] );

		if ( '' === $slug ) {
			return new WP_Error( 'ehbp_bad_slug', __( 'Empty slug in CSV row.', 'earthhaul-bulk-pages' ) );
		}

		if ( $skip_existing ) {
			$existing = self::find_existing_clone( $slug, $parent_id );
			if ( $existing > 0 ) {
				update_post_meta( $existing, '_ehbp_bulk_resolution', 'reused' );
				return $existing;
			}
		}

		$new_id = Page_Cloner::clone_for_row(
			$template_id,
			$row,
			(string) ( $config['title_fmt'] ?? Page_Cloner::format_title( '{{city}}, {{state}}', $row ) ),
			$parent_id,
			(string) ( $config['job_id'] ?? '' )
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		update_post_meta( (int) $new_id, '_ehbp_bulk_resolution', 'cloned' );
		return (int) $new_id;
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
