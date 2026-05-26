<?php
/**
 * Patch_Engine: copies a marker-tagged subtree of Beaver Builder nodes
 * (typically one or more rows) from a source page onto a set of target
 * cloned pages, with per-target token substitution on every string field.
 *
 * Use case: after the user authors a "Dumpsters for every job" row on
 * the Orlando location template (with class `ehbp-patch-services` and
 * button URLs containing `{{city_slug_state}}`), they run a patch job
 * to propagate that row onto all 80 cloned location pages. Each cloned
 * page gets its own copy of the row with the buttons rewritten to the
 * cloned page's own city slug.
 *
 * Why this is its own engine instead of being baked into the bulk-clone
 * pipeline:
 *
 *   - Patches run AFTER cloning + AI rewrites, when the user has already
 *     spot-checked the cloned pages and just wants to add a new section
 *     globally without re-running expensive AI work.
 *   - Patches are pure layout copy + token substitution. NO AI calls,
 *     NO image sideload. Run time is ~1s per page, so 80 pages takes
 *     ~1-2 minutes total instead of hours.
 *   - Patches are idempotent under the `replace_existing` rule: re-runs
 *     drop the previously-patched node and re-insert. So when the
 *     Orlando template's patch row changes, re-running the patch flows
 *     updates to all 80.
 *
 * Beaver Builder layout shape (relevant subset):
 *   $layout = array(
 *     'a1b2c3d4' => stdClass(
 *       'type'     => 'row' | 'column' | 'module',
 *       'parent'   => parent node id ('' for top-level rows),
 *       'position' => int (sibling order),
 *       'settings' => stdClass(...),
 *       'node'     => 'a1b2c3d4' (mirrors the array key),
 *     ),
 *     ...
 *   )
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use FLBuilderModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Patch_Engine {

	/** Default marker class for the "Dumpsters for every job" patch row. */
	public const DEFAULT_MARKER_CLASS = 'ehbp-patch-services';

	/**
	 * Apply a patch from $source_post_id onto $target_post_id.
	 *
	 * @param int   $source_post_id Page that holds the marker-tagged subtree.
	 * @param int   $target_post_id Page that receives the copy.
	 * @param array $config {
	 *     @type string $marker_class    CSS class on the source row(s) to copy. Default DEFAULT_MARKER_CLASS.
	 *     @type string $insertion_rule  One of: append | before_anchor | after_anchor | replace_existing.
	 *                                   Default 'append'.
	 *     @type string $anchor_class    Required for before_anchor/after_anchor; CSS class on a target row
	 *                                   used as the insertion anchor.
	 * }
	 * @return array{ok: bool, count: int, message: string, mode: string}
	 */
	public static function apply( int $source_post_id, int $target_post_id, array $config = array() ): array {
		$marker_class = (string) ( $config['marker_class'] ?? self::DEFAULT_MARKER_CLASS );
		$rule         = (string) ( $config['insertion_rule'] ?? 'append' );
		$anchor_class = (string) ( $config['anchor_class'] ?? '' );

		if ( ! class_exists( '\FLBuilderModel' ) ) {
			return self::result( false, 0, 'Beaver Builder is not active.', $rule );
		}
		if ( $source_post_id <= 0 || $target_post_id <= 0 ) {
			return self::result( false, 0, 'Invalid source or target post ID.', $rule );
		}
		if ( $source_post_id === $target_post_id ) {
			return self::result( false, 0, 'Source and target are the same page; nothing to patch.', $rule );
		}

		// Load source layout. Prefer published slot (what's live).
		$source_layout = FLBuilderModel::get_layout_data( 'published', $source_post_id );
		if ( empty( $source_layout ) ) {
			$source_layout = FLBuilderModel::get_layout_data( 'draft', $source_post_id );
		}
		if ( empty( $source_layout ) || ! is_array( $source_layout ) ) {
			return self::result( false, 0, 'No Beaver Builder layout on source page.', $rule );
		}

		// Find marker root nodes on source (rows tagged with the class).
		$marker_root_ids = self::find_root_nodes_with_class( $source_layout, $marker_class );
		if ( empty( $marker_root_ids ) ) {
			return self::result(
				false,
				0,
				sprintf( 'No row on the source page has class "%s".', $marker_class ),
				$rule
			);
		}

		// Load target layout.
		$target_layout = Layout_Mutator::load_layout( $target_post_id );
		if ( empty( $target_layout ) ) {
			return self::result( false, 0, 'No Beaver Builder layout on target page.', $rule );
		}

		// Replace-existing: drop any prior patched roots (and their
		// descendants) on the target before inserting fresh.
		if ( 'replace_existing' === $rule ) {
			$target_layout = self::remove_subtrees_with_class( $target_layout, $marker_class );
		}

		// Resolve the insertion position. For anchor-based modes we need
		// to find the anchor row's position FIRST, before we mutate the
		// layout, otherwise positions shift under us.
		$insert_position = self::resolve_insert_position( $target_layout, $rule, $anchor_class );
		if ( is_wp_error( $insert_position ) ) {
			return self::result( false, 0, $insert_position->get_error_message(), $rule );
		}

		// For each marker root on source, deep-clone its subtree with
		// fresh node IDs, token-resolve every string field, then splice
		// into the target layout at the chosen position. Multiple
		// marker rows are inserted in source-order, contiguously.
		$inserted = 0;
		foreach ( $marker_root_ids as $i => $root_id ) {
			$subtree = self::deep_clone_subtree( $source_layout, $root_id );
			if ( empty( $subtree ) ) {
				continue;
			}

			// Sweep every string property on the cloned subtree in one
			// pass. Token_Resolver::resolve_in_place recurses into
			// nested objects/arrays and rewrites any leaf string that
			// contains `{{`, so URLs, headlines, button labels, alt
			// text, the row's own `class` field, and anything else
			// authored with tokens all get localized to the target.
			foreach ( $subtree as $node ) {
				if ( ! is_object( $node ) || empty( $node->settings ) ) {
					continue;
				}
				Token_Resolver::resolve_in_place( $node->settings, $target_post_id );
			}

			$target_layout = self::insert_subtree(
				$target_layout,
				$subtree,
				$root_id,
				$insert_position + $i
			);
			$inserted++;
		}

		Layout_Mutator::persist_layout( $target_post_id, $target_layout );

		return self::result(
			true,
			$inserted,
			sprintf( 'Inserted %d patched node(s) using rule "%s".', $inserted, $rule ),
			$rule
		);
	}

	/**
	 * Find every row-type node whose `settings->class` (whitespace-
	 * separated class list) contains $target. Module-level matches are
	 * intentionally NOT supported — patches are row-grain only because
	 * inserting a bare module without its parent row would wreck layout.
	 *
	 * @return array<int, string> Node IDs in source-position order.
	 */
	private static function find_root_nodes_with_class( array $layout, string $target ): array {
		$matches = array();
		foreach ( $layout as $node_id => $node ) {
			if ( ! is_object( $node ) || ( $node->type ?? '' ) !== 'row' ) {
				continue;
			}
			$class = isset( $node->settings->class ) ? (string) $node->settings->class : '';
			if ( '' === $class ) {
				continue;
			}
			if ( self::class_list_contains( $class, $target ) ) {
				$matches[ (string) $node_id ] = (int) ( $node->position ?? 0 );
			}
		}
		// Sort by position so multi-row patches preserve source order.
		asort( $matches );
		return array_keys( $matches );
	}

	/**
	 * Return a NEW layout with every row matching the marker class removed
	 * along with all its descendants (columns, modules). Used by the
	 * replace_existing rule.
	 */
	private static function remove_subtrees_with_class( array $layout, string $target_class ): array {
		$drop_roots = self::find_root_nodes_with_class( $layout, $target_class );
		if ( empty( $drop_roots ) ) {
			return $layout;
		}

		$drop_ids = array();
		foreach ( $drop_roots as $root_id ) {
			$drop_ids[ $root_id ] = true;
			foreach ( self::collect_descendants( $layout, $root_id ) as $desc_id ) {
				$drop_ids[ $desc_id ] = true;
			}
		}

		$out = array();
		foreach ( $layout as $node_id => $node ) {
			if ( isset( $drop_ids[ (string) $node_id ] ) ) {
				continue;
			}
			$out[ $node_id ] = $node;
		}
		return $out;
	}

	/**
	 * Resolve the integer position at which the new row(s) should be
	 * spliced into the target layout. Returns a WP_Error if anchor mode
	 * was requested but no anchor was found.
	 *
	 * Position semantics: BB rows are siblings of one another at the
	 * top level. A new row at position N means "render just before any
	 * existing row whose position is >= N". `insert_subtree` handles
	 * the bump-down of later rows.
	 *
	 * @return int|\WP_Error
	 */
	private static function resolve_insert_position( array $layout, string $rule, string $anchor_class ) {
		$top_rows = self::collect_top_level_rows( $layout );

		switch ( $rule ) {
			case 'append':
				if ( empty( $top_rows ) ) {
					return 0;
				}
				return max( $top_rows ) + 1;

			case 'before_anchor':
			case 'after_anchor':
				if ( '' === $anchor_class ) {
					return new \WP_Error(
						'ehbp_no_anchor',
						__( 'Anchor class is required for before/after-anchor insertion.', 'earthhaul-bulk-pages' )
					);
				}
				$anchors = self::find_root_nodes_with_class( $layout, $anchor_class );
				if ( empty( $anchors ) ) {
					return new \WP_Error(
						'ehbp_anchor_missing',
						sprintf(
							/* translators: %s: CSS class */
							__( 'No row on the target page has anchor class "%s".', 'earthhaul-bulk-pages' ),
							$anchor_class
						)
					);
				}
				$anchor_id  = $anchors[0];
				$anchor_pos = (int) ( $layout[ $anchor_id ]->position ?? 0 );
				return 'before_anchor' === $rule ? $anchor_pos : $anchor_pos + 1;

			case 'replace_existing':
				// In replace mode the original rows have already been
				// removed by remove_subtrees_with_class. Anchor at the
				// next available position so the new row(s) sit at the
				// end. Authors who want stable replacement positions
				// should pair it with a stable anchor — but typical
				// usage is "first run uses anchor, future runs use
				// replace_existing" which works because the patched
				// row's class anchors itself.
				if ( empty( $top_rows ) ) {
					return 0;
				}
				return max( $top_rows ) + 1;
		}

		return new \WP_Error( 'ehbp_bad_rule', sprintf( __( 'Unknown insertion rule "%s".', 'earthhaul-bulk-pages' ), $rule ) );
	}

	/**
	 * Deep-clone the row at $root_id and ALL its descendants (columns +
	 * modules), assigning each a fresh node ID and remapping parent
	 * pointers so the cloned subtree is internally consistent and
	 * collision-free against any existing target-layout IDs.
	 *
	 * Returns the cloned subtree as an associative array keyed by NEW
	 * node IDs. Position on the root is left at 0; the caller
	 * (insert_subtree) sets the final position based on the chosen
	 * insertion rule.
	 *
	 * @return array<string, object>
	 */
	private static function deep_clone_subtree( array $layout, string $root_id ): array {
		if ( ! isset( $layout[ $root_id ] ) || ! is_object( $layout[ $root_id ] ) ) {
			return array();
		}

		$descendants = self::collect_descendants( $layout, $root_id );
		$old_to_new  = array();
		$old_to_new[ $root_id ] = self::generate_node_id();
		foreach ( $descendants as $desc_id ) {
			$old_to_new[ $desc_id ] = self::generate_node_id();
		}

		$out = array();
		foreach ( $old_to_new as $old_id => $new_id ) {
			$cloned       = self::deep_clone_object( $layout[ $old_id ] );
			$cloned->node = $new_id;

			// Remap parent pointer if the parent was part of the cloned
			// subtree; otherwise blank it so root rows stay top-level.
			$old_parent = isset( $cloned->parent ) ? (string) $cloned->parent : '';
			if ( $old_parent !== '' && isset( $old_to_new[ $old_parent ] ) ) {
				$cloned->parent = $old_to_new[ $old_parent ];
			} elseif ( $old_id === $root_id ) {
				$cloned->parent = '';
			}

			$out[ $new_id ] = $cloned;
		}

		return $out;
	}

	/**
	 * Walk the layout downward from $root_id and return every node ID
	 * that has $root_id as an ancestor.
	 *
	 * @return array<int, string>
	 */
	private static function collect_descendants( array $layout, string $root_id ): array {
		$out = array();

		// Build child index once for O(N) traversal.
		$children = array();
		foreach ( $layout as $node_id => $node ) {
			if ( ! is_object( $node ) ) {
				continue;
			}
			$parent = isset( $node->parent ) ? (string) $node->parent : '';
			if ( '' === $parent ) {
				continue;
			}
			$children[ $parent ][] = (string) $node_id;
		}

		$queue = isset( $children[ $root_id ] ) ? $children[ $root_id ] : array();
		while ( ! empty( $queue ) ) {
			$id  = array_shift( $queue );
			$out[] = $id;
			if ( isset( $children[ $id ] ) ) {
				foreach ( $children[ $id ] as $sub ) {
					$queue[] = $sub;
				}
			}
		}
		return $out;
	}

	/**
	 * Splice the cloned subtree into the target layout at the requested
	 * position. Bumps any existing top-level row at position >= $position
	 * up by 1 to make room. Sets the cloned root's position to $position
	 * and leaves descendants' relative positions intact.
	 */
	private static function insert_subtree( array $layout, array $subtree, string $source_root_id, int $position ): array {
		// Find the new root id in the cloned subtree (the one whose
		// parent is empty after the deep clone).
		$new_root_id = '';
		foreach ( $subtree as $node_id => $node ) {
			if ( '' === ( $node->parent ?? '' ) ) {
				$new_root_id = (string) $node_id;
				break;
			}
		}
		if ( '' === $new_root_id ) {
			// Defensive: shouldn't happen — deep_clone_subtree always
			// creates exactly one root with empty parent.
			return $layout;
		}

		// Bump existing top-level rows whose position is >= $position.
		foreach ( $layout as $node_id => $node ) {
			if ( ! is_object( $node ) || ( $node->type ?? '' ) !== 'row' ) {
				continue;
			}
			$parent = isset( $node->parent ) ? (string) $node->parent : '';
			if ( '' !== $parent ) {
				continue;
			}
			$pos = (int) ( $node->position ?? 0 );
			if ( $pos >= $position ) {
				$node->position = $pos + 1;
			}
		}

		// Set the cloned root's position.
		$subtree[ $new_root_id ]->position = $position;

		// Merge.
		foreach ( $subtree as $node_id => $node ) {
			$layout[ $node_id ] = $node;
		}
		return $layout;
	}

	/**
	 * Recursively clone a stdClass / nested array structure so the
	 * caller can safely mutate the copy without affecting the source.
	 * Same trick as the rest of the codebase: serialize/unserialize is
	 * the cheapest reliable deep-clone for plain data.
	 */
	private static function deep_clone_object( $value ) {
		if ( is_object( $value ) || is_array( $value ) ) {
			return unserialize( serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.serialize_serialize, WordPress.PHP.DiscouragedFunctions.serialize_unserialize
		}
		return $value;
	}

	/**
	 * Generate a fresh BB-shaped node ID. Uses uniqid() with extra
	 * entropy so two patches running concurrently won't collide.
	 *
	 * BB itself uses 8-char alnum lowercase; we match that shape.
	 */
	private static function generate_node_id(): string {
		// Alnum-lowercase 8 chars, prefixed with 'p' so it's clearly
		// plugin-generated when grepping a layout dump.
		return 'p' . substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * Return a list of positions for every top-level row in the layout.
	 */
	private static function collect_top_level_rows( array $layout ): array {
		$positions = array();
		foreach ( $layout as $node ) {
			if ( ! is_object( $node ) || ( $node->type ?? '' ) !== 'row' ) {
				continue;
			}
			$parent = isset( $node->parent ) ? (string) $node->parent : '';
			if ( '' !== $parent ) {
				continue;
			}
			$positions[] = (int) ( $node->position ?? 0 );
		}
		return $positions;
	}

	/**
	 * Whitespace-separated class list contains a target class as a
	 * whole token (so `ehbp-anchor-services-2` doesn't accidentally
	 * match `ehbp-anchor-services`).
	 */
	private static function class_list_contains( string $haystack, string $target ): bool {
		$tokens = preg_split( '/\s+/', trim( $haystack ) ) ?: array();
		return in_array( $target, $tokens, true );
	}

	private static function result( bool $ok, int $count, string $message, string $mode ): array {
		return array(
			'ok'      => $ok,
			'count'   => $count,
			'message' => $message,
			'mode'    => $mode,
		);
	}
}
