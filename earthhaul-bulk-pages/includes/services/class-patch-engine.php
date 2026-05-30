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
	 * Per-node debug snapshot. Keyed by `node_id|phase` -> array of
	 * "field_path = value" strings. Built by diff_capture_* and flushed
	 * to the log file at the end of apply(). Reset per apply() call.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static array $debug_snapshots = array();

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
	 *     @type bool   $clone_images    When true, sideload each photo module's
	 *                                   image as a target-localized attachment
	 *                                   (rename + alt text + dedup). Default
	 *                                   true. Set false to keep the source
	 *                                   page's attachments referenced as-is.
	 * }
	 * @return array{ok: bool, count: int, message: string, mode: string}
	 */
	public static function apply( int $source_post_id, int $target_post_id, array $config = array() ): array {
		$marker_class = (string) ( $config['marker_class'] ?? self::DEFAULT_MARKER_CLASS );
		$rule         = (string) ( $config['insertion_rule'] ?? 'append' );
		$anchor_class = (string) ( $config['anchor_class'] ?? '' );
		$clone_images = ! isset( $config['clone_images'] ) || (bool) $config['clone_images'];

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
		//
		// Two passes of substitution per cloned subtree:
		//   1. Token_Resolver: rewrites `{{city}}`, `{{city_slug_state}}`,
		//      etc. against the TARGET page's meta. Authors who want
		//      explicit substitution use this.
		//   2. Source-slug swap: rewrites the SOURCE page's literal
		//      city + state slug to the TARGET's. This lets the source
		//      row carry working URLs (`/locations/orlando-fl/...`) so
		//      the source page itself isn't broken between patch runs,
		//      while every cloned page still gets per-target URLs.
		$source_pair = self::derive_slug_state_pair( $source_post_id );
		$inserted    = 0;
		$debug_lines = array();
		$debug_lines[] = sprintf(
			'[%s] BEGIN patch source=%d (%s -> slug_state=%s, proper=%s) target=%d (%s -> slug_state=%s, proper=%s) rule=%s',
			gmdate( 'Y-m-d H:i:s' ),
			$source_post_id,
			$source_pair['city'],
			$source_pair['slug_state'],
			$source_pair['proper_city'],
			$target_post_id,
			'',
			'',
			'',
			$rule
		);
		foreach ( $marker_root_ids as $i => $root_id ) {
			$subtree = self::deep_clone_subtree( $source_layout, $root_id );
			if ( empty( $subtree ) ) {
				continue;
			}

			$target_pair = self::derive_slug_state_pair( $target_post_id );
			$debug_lines[ count( $debug_lines ) - 1 ] = sprintf(
				'[%s] BEGIN patch source=%d (%s -> slug_state=%s, proper=%s) target=%d (%s -> slug_state=%s, proper=%s) rule=%s',
				gmdate( 'Y-m-d H:i:s' ),
				$source_post_id,
				$source_pair['city'],
				$source_pair['slug_state'],
				$source_pair['proper_city'],
				$target_post_id,
				$target_pair['city'],
				$target_pair['slug_state'],
				$target_pair['proper_city'],
				$rule
			);

			// Sweep every string property on the cloned subtree in one
			// pass. Token_Resolver::resolve_in_place recurses into
			// nested objects/arrays and rewrites any leaf string that
			// contains `{{`, so URLs, headlines, button labels, alt
			// text, the row's own `class` field, and anything else
			// authored with tokens all get localized to the target.
			foreach ( $subtree as $node_id => $node ) {
				if ( ! is_object( $node ) || empty( $node->settings ) ) {
					continue;
				}
				$type = (string) ( $node->type ?? '?' );
				$mod  = (string) ( $node->settings->type ?? '' );
				self::diff_capture_pre( $node->settings, $node_id, $type, $mod, $debug_lines );

				Token_Resolver::resolve_in_place( $node->settings, $target_post_id );
				self::diff_capture_phase( $node->settings, $node_id, 'after_token', $debug_lines );

				if ( '' !== $source_pair['city'] && '' !== $target_pair['city'] ) {
					self::swap_source_slug_in_place( $node->settings, $source_pair, $target_pair );
				}
				self::diff_capture_phase( $node->settings, $node_id, 'after_slug_swap', $debug_lines );
			}

			// Image localization pass: sideload each photo module's
			// source attachment as a target-localized clone, and rewrite
			// the photo module's id/url/data to point at the new
			// attachment. No-op when clone_images is false (config flag)
			// or when source/target share a city slug.
			if ( $clone_images ) {
				Patch_Image_Cloner::localize_photos_in_subtree(
					$subtree,
					$source_pair,
					$target_pair,
					$target_post_id,
					$debug_lines
				);
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

		$debug_lines[] = sprintf( '[%s] END inserted=%d', gmdate( 'Y-m-d H:i:s' ), $inserted );
		self::write_debug_log( $debug_lines );

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
	 * Find every top-level row that either CARRIES the target class on
	 * itself, OR has a descendant (column, module) carrying it. Used
	 * for ANCHOR lookup specifically — markers are still row-grain only.
	 *
	 * Returns row IDs ordered by position (earliest first), so the
	 * caller picking $anchors[0] gets the topmost match. If you need
	 * the bottom match, take end($anchors).
	 *
	 * @return array<int, string>
	 */
	private static function find_rows_containing_class( array $layout, string $target ): array {
		if ( '' === $target ) {
			return array();
		}

		// Build a parent lookup once so the row ascent is O(1) per node.
		$parent_lookup = array();
		foreach ( $layout as $node_id => $node ) {
			$parent_lookup[ (string) $node_id ] = isset( $node->parent ) ? (string) $node->parent : '';
		}

		$matched_rows = array();
		foreach ( $layout as $node_id => $node ) {
			if ( ! is_object( $node ) ) {
				continue;
			}
			$class = isset( $node->settings->class ) ? (string) $node->settings->class : '';
			if ( '' === $class ) {
				continue;
			}
			if ( ! self::class_list_contains( $class, $target ) ) {
				continue;
			}
			$row_id = self::ascend_to_row( (string) $node_id, $layout, $parent_lookup );
			if ( '' === $row_id || ! isset( $layout[ $row_id ] ) ) {
				continue;
			}
			$matched_rows[ $row_id ] = (int) ( $layout[ $row_id ]->position ?? 0 );
		}
		asort( $matched_rows );
		return array_keys( $matched_rows );
	}

	/**
	 * Climb parent links from $node_id up to its containing top-level
	 * row, returning that row's node ID. Returns "" if the node is
	 * orphaned or not under a row.
	 */
	private static function ascend_to_row( string $node_id, array $layout, array $parent_lookup ): string {
		$current = $node_id;
		$guard   = 0;
		while ( $guard < 16 ) {
			$node = $layout[ $current ] ?? null;
			if ( is_object( $node ) && ( $node->type ?? '' ) === 'row' ) {
				$parent = isset( $node->parent ) ? (string) $node->parent : '';
				if ( '' === $parent ) {
					return $current;
				}
			}
			$parent = $parent_lookup[ $current ] ?? '';
			if ( '' === $parent || $parent === $current ) {
				return '';
			}
			$current = $parent;
			$guard++;
		}
		return '';
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
				// Anchor lookup is descendant-aware: a class set on any
				// node (column, module) inside a row counts as anchoring
				// that row. This lets users reuse existing classes they
				// applied to inner modules during the bulk-clone run
				// (e.g. `ehbp-neighborhoods` on the neighborhoods text
				// module) without having to retroactively tag every
				// cloned page's containing row.
				$anchors = self::find_rows_containing_class( $layout, $anchor_class );
				if ( empty( $anchors ) ) {
					return new \WP_Error(
						'ehbp_anchor_missing',
						sprintf(
							/* translators: %s: CSS class */
							__( 'No row on the target page contains anchor class "%s" (checked the row, its columns, and its modules).', 'earthhaul-bulk-pages' ),
							$anchor_class
						)
					);
				}
				$anchor_id  = $anchors[0];
				$anchor_pos = (int) ( $layout[ $anchor_id ]->position ?? 0 );
				return 'before_anchor' === $rule ? $anchor_pos : $anchor_pos + 1;

			case 'replace_existing':
				// In replace mode the original patched rows have already
				// been removed by remove_subtrees_with_class. If an
				// anchor class is provided we re-insert AFTER it so
				// the patched row keeps a stable spot across re-runs
				// (the typical case: pair `ehbp-patch-services` marker
				// with `ehbp-neighborhoods` anchor and run as many
				// times as you like). Anchor is OPTIONAL here — if
				// none is given, fall back to appending at the bottom.
				if ( '' !== $anchor_class ) {
					$anchors = self::find_rows_containing_class( $layout, $anchor_class );
					if ( ! empty( $anchors ) ) {
						$anchor_id  = $anchors[0];
						$anchor_pos = (int) ( $layout[ $anchor_id ]->position ?? 0 );
						return $anchor_pos + 1;
					}
				}
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

	/**
	 * Read or infer the target / source page's city + state, in both
	 * slug and proper-name forms.
	 *
	 * Source-of-truth precedence:
	 *
	 *   1. `_ehbp_city_name` ("Winter Haven") + `_ehbp_state` ("FL") --
	 *      both stamped on every plugin-cloned page. We sanitize_title
	 *      the city name to derive the slug ("winter-haven"). DO NOT
	 *      use `_ehbp_city_slug` as the city-only form: the cloner
	 *      stores the full URL slug there ("winter-haven-fl"),
	 *      including state. Treating that as the city portion would
	 *      give us a double state suffix in `slug_state`.
	 *
	 *   2. Post slug parsing — for hand-built source pages like the
	 *      Orlando location template, which don't carry plugin meta.
	 *      `winter-haven-fl` -> city "winter-haven", state "fl".
	 *
	 * @return array{city: string, state: string, slug_state: string,
	 *               proper_city: string, proper_label: string}
	 */
	private static function derive_slug_state_pair( int $post_id ): array {
		$empty = array(
			'city'         => '',
			'state'        => '',
			'slug_state'   => '',
			'proper_city'  => '',
			'proper_label' => '',
		);
		if ( $post_id <= 0 ) {
			return $empty;
		}

		$city_name = (string) get_post_meta( $post_id, '_ehbp_city_name', true );
		$state     = (string) get_post_meta( $post_id, '_ehbp_state', true );

		// Derive city slug from the human name when we have it. This
		// avoids the `_ehbp_city_slug` trap (which is a full URL slug
		// with state suffix, not the city portion).
		$city_slug = '' !== $city_name ? sanitize_title( $city_name ) : '';

		if ( '' === $city_slug || '' === $state ) {
			$post = get_post( $post_id );
			if ( $post && '' !== (string) $post->post_name ) {
				$parsed = self::parse_city_state_from_slug( (string) $post->post_name );
				if ( '' === $city_slug ) {
					$city_slug = $parsed['city'];
				}
				if ( '' === $state ) {
					$state = $parsed['state'];
				}
			}
		}

		$city_slug = sanitize_title( $city_slug );
		$state     = strtolower( trim( $state ) );

		if ( '' === $city_slug || '' === $state ) {
			return $empty;
		}

		// Proper-cased city: prefer explicit meta ("Winter Haven"), else
		// derive from the slug ("winter-haven" -> "Winter Haven").
		$proper_city = trim( $city_name );
		if ( '' === $proper_city ) {
			$proper_city = ucwords( str_replace( '-', ' ', $city_slug ) );
		}

		return array(
			'city'         => $city_slug,
			'state'        => $state,
			'slug_state'   => $city_slug . '-' . $state,
			'proper_city'  => $proper_city,
			'proper_label' => $proper_city . ', ' . strtoupper( $state ),
		);
	}

	/**
	 * Parse a slug like "orlando-fl" or "winter-haven-fl" into city +
	 * state components. The trailing 2-letter alpha segment is treated
	 * as the state code; everything before it is the city.
	 *
	 * Returns empty values when the input doesn't match the convention.
	 *
	 * @return array{city: string, state: string}
	 */
	private static function parse_city_state_from_slug( string $slug ): array {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return array( 'city' => '', 'state' => '' );
		}
		$parts = explode( '-', $slug );
		if ( count( $parts ) < 2 ) {
			return array( 'city' => '', 'state' => '' );
		}
		$tail = (string) end( $parts );
		if ( 2 !== strlen( $tail ) || ! preg_match( '/^[a-z]{2}$/i', $tail ) ) {
			return array( 'city' => '', 'state' => '' );
		}
		array_pop( $parts );
		return array(
			'city'  => implode( '-', $parts ),
			'state' => strtolower( $tail ),
		);
	}

	/**
	 * Recursively walk a value (object / array / string) and rewrite
	 * literal source-city references to target-city ones. Three
	 * substitutions, applied in this order so the longer slug-state
	 * form wins before the bare city/state forms:
	 *
	 *   1. "<source_city>-<source_state>"  →  "<target_city>-<target_state>"
	 *   2. "<source_city>"                 →  "<target_city>"            (whole-word)
	 *   3. "<source_state>"                →  "<target_state>"           (whole-word, only if source != target state)
	 *
	 * Whole-word matching uses `[a-z0-9]` boundaries so:
	 *   "orlando-fl"  stays matched in step 1 (full slug form)
	 *   "Orlando"     matches step 2 case-insensitively
	 *   "orlandowide" does NOT match (good — false-positive guard)
	 *
	 * Mutates objects in place. For arrays the caller should reassign
	 * the returned (modified) array — same convention as
	 * Token_Resolver::resolve_in_place.
	 *
	 * @param mixed $value By reference.
	 * @param array{city: string, state: string, slug_state: string} $source
	 * @param array{city: string, state: string, slug_state: string} $target
	 * @return int Count of leaf strings rewritten.
	 */
	private static function swap_source_slug_in_place( &$value, array $source, array $target ): int {
		if ( is_string( $value ) ) {
			$rewritten = self::swap_source_slug_in_string( $value, $source, $target );
			if ( $rewritten !== $value ) {
				$value = $rewritten;
				return 1;
			}
			return 0;
		}

		$count = 0;
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $key => $sub ) {
				if ( is_string( $sub ) ) {
					$rewritten = self::swap_source_slug_in_string( $sub, $source, $target );
					if ( $rewritten !== $sub ) {
						$value->$key = $rewritten;
						$count++;
					}
				} elseif ( is_array( $sub ) ) {
					$count += self::swap_source_slug_in_place( $sub, $source, $target );
					$value->$key = $sub;
				} elseif ( is_object( $sub ) ) {
					$count += self::swap_source_slug_in_place( $sub, $source, $target );
				}
			}
			return $count;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => &$sub ) {
				if ( is_string( $sub ) ) {
					$rewritten = self::swap_source_slug_in_string( $sub, $source, $target );
					if ( $rewritten !== $sub ) {
						$sub = $rewritten;
						$count++;
					}
				} elseif ( is_array( $sub ) || is_object( $sub ) ) {
					$count += self::swap_source_slug_in_place( $sub, $source, $target );
				}
			}
			unset( $sub );
		}
		return $count;
	}

	/**
	 * Apply the source-city substitution to a single string. Three rules:
	 *
	 *   1. SKIP if the string looks like an image asset URL or filename
	 *      (extension .jpg/.jpeg/.png/.webp/.gif/.svg/.avif, or contains
	 *      `/wp-content/uploads/`). Rewriting filenames would break
	 *      images because the renamed file doesn't exist on disk; image
	 *      sideloading is the rewrite engine's job, not the patch
	 *      engine's.
	 *
	 *   2. Replace the SLUG-STATE form (`orlando-fl`) with the target's
	 *      slug-state (`winter-haven-fl`). This handles URL paths.
	 *
	 *   3. Replace the BARE city ("orlando" or "Orlando") with case-
	 *      aware output:
	 *        - all-lowercase match (URL slug context, e.g. inside a
	 *          path)            -> target slug ("winter-haven")
	 *        - all-UPPER match   -> uppercase proper name ("WINTER HAVEN")
	 *        - mixed/proper case -> proper name ("Winter Haven")
	 *
	 *   4. State swap (`fl` -> `tx`) only fires when patching across
	 *      states; intrastate patches no-op.
	 *
	 * Boundary regex `(?<![a-z0-9])` and `(?![a-z0-9])` so word boundaries
	 * respect dashes and underscores: matches "orlando" inside
	 * "orlando-fl" but not inside "orlandowide".
	 */
	private static function swap_source_slug_in_string( string $haystack, array $source, array $target ): string {
		if ( '' === $haystack ) {
			return $haystack;
		}

		// Bail on image URLs / filenames — they reference files on disk
		// that have the source city in their name; renaming the URL
		// without renaming the file produces 404s on the target page.
		if ( self::looks_like_image_url( $haystack ) ) {
			return $haystack;
		}

		// 1. Slug-state form: "orlando-fl" => "winter-haven-fl".
		if ( '' !== $source['slug_state'] && '' !== $target['slug_state'] && $source['slug_state'] !== $target['slug_state'] ) {
			$haystack = preg_replace(
				'/(?<![a-z0-9])' . preg_quote( $source['slug_state'], '/' ) . '(?![a-z0-9])/i',
				$target['slug_state'],
				$haystack
			) ?? $haystack;
		}

		// 2. Bare city name: case-aware. Lowercase matches go to the
		//    slug form (URL context); proper/upper-case matches go to
		//    the proper-cased city name (prose context).
		if ( '' !== $source['city'] && '' !== $target['city'] && $source['city'] !== $target['city'] ) {
			$proper       = '' !== $target['proper_city'] ? $target['proper_city'] : $target['city'];
			$slug_target  = $target['city'];
			$proper_upper = strtoupper( $proper );

			$haystack = preg_replace_callback(
				'/(?<![a-z0-9])' . preg_quote( $source['city'], '/' ) . '(?![a-z0-9])/i',
				static function ( array $m ) use ( $proper, $slug_target, $proper_upper ): string {
					$hit = $m[0];
					if ( '' === $hit ) {
						return $hit;
					}
					if ( ctype_lower( $hit ) ) {
						return $slug_target;
					}
					if ( ctype_upper( $hit ) ) {
						return $proper_upper;
					}
					return $proper;
				},
				$haystack
			) ?? $haystack;
		}

		// 3. State slug (lowercase URL context only). Same-state patches
		//    skip this branch entirely. We deliberately do NOT replace
		//    the spelled-out form ("Florida" -> "Texas") here because
		//    the patch engine isn't doing AI rewrites; it would risk
		//    mangling content unrelated to the patch.
		if ( '' !== $source['state'] && '' !== $target['state'] && $source['state'] !== $target['state'] ) {
			$haystack = preg_replace(
				'/(?<![a-z0-9])' . preg_quote( $source['state'], '/' ) . '(?![a-z0-9])/i',
				$target['state'],
				$haystack
			) ?? $haystack;
		}

		return $haystack;
	}

	/**
	 * Heuristic: does this string look like an image URL or filename?
	 * If so, the slug swap leaves it alone — renaming an image URL
	 * without renaming the file on disk produces 404s.
	 */
	private static function looks_like_image_url( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}
		// Common image extensions, with optional ?query string.
		if ( preg_match( '/\.(jpe?g|png|webp|gif|svg|avif|bmp|tiff?)(\?.*)?$/i', $value ) ) {
			return true;
		}
		// Anything inside the WordPress uploads directory — captures
		// resized variants like ...-1024x683.jpg even if the trailing
		// extension test got tripped up by query strings.
		if ( false !== stripos( $value, '/wp-content/uploads/' ) ) {
			return true;
		}
		return false;
	}

	private static function result( bool $ok, int $count, string $message, string $mode ): array {
		return array(
			'ok'      => $ok,
			'count'   => $count,
			'message' => $message,
			'mode'    => $mode,
		);
	}

	/**
	 * DEBUG: capture every "interesting" string field on a node before
	 * substitution runs. Interesting = anything that looks like a URL,
	 * heading, label, link, class, or contains the source city/slug.
	 * Stored in $debug_snapshots keyed by "node_id|pre".
	 */
	private static function diff_capture_pre( $settings, string $node_id, string $type, string $module, array &$log ): void {
		$flat = array();
		self::flatten_strings( $settings, '', $flat );
		$key  = $node_id . '|pre';
		self::$debug_snapshots[ $key ] = $flat;
		$log[] = sprintf( '  NODE %s type=%s module=%s -- %d strings captured', $node_id, $type, $module, count( $flat ) );
	}

	/**
	 * DEBUG: capture post-phase state and emit a diff line for every
	 * string that changed between the previous snapshot and now.
	 */
	private static function diff_capture_phase( $settings, string $node_id, string $phase, array &$log ): void {
		$flat = array();
		self::flatten_strings( $settings, '', $flat );

		$prev_key = $node_id . '|' . self::previous_phase( $phase );
		$prev     = self::$debug_snapshots[ $prev_key ] ?? array();

		$diffs = 0;
		foreach ( $flat as $path => $value ) {
			$before = $prev[ $path ] ?? '';
			if ( $before !== $value ) {
				$log[] = sprintf( '    [%s] %s :: %s  =>  %s', $phase, $path, self::trunc( $before ), self::trunc( $value ) );
				$diffs++;
			}
		}

		if ( 0 === $diffs && 'after_slug_swap' === $phase ) {
			$log[] = sprintf( '    [%s] (no changes)', $phase );
		}

		self::$debug_snapshots[ $node_id . '|' . $phase ] = $flat;
	}

	private static function previous_phase( string $phase ): string {
		switch ( $phase ) {
			case 'after_token':
				return 'pre';
			case 'after_slug_swap':
				return 'after_token';
		}
		return 'pre';
	}

	private static function trunc( string $s ): string {
		if ( strlen( $s ) > 200 ) {
			return substr( $s, 0, 197 ) . '...';
		}
		return $s;
	}

	/**
	 * Recursively flatten every string leaf in an object/array into a
	 * dot-pathed map: {"a.b.0.c" => "value", ...}. Used by the debug
	 * machinery to diff settings before/after each substitution phase.
	 */
	private static function flatten_strings( $value, string $path, array &$out ): void {
		if ( is_string( $value ) ) {
			$out[ $path ] = $value;
			return;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				self::flatten_strings( $v, '' === $path ? (string) $k : $path . '.' . $k, $out );
			}
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				self::flatten_strings( $v, '' === $path ? (string) $k : $path . '.' . $k, $out );
			}
		}
	}

	/**
	 * Append the captured debug log to wp-content/uploads/ehbp-patch-debug.log.
	 * The plugin's Patch Pages screen exposes this via a "View debug log"
	 * link so the user can grab it without SSH'ing into the server.
	 */
	private static function write_debug_log( array $lines ): void {
		if ( empty( $lines ) ) {
			return;
		}
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) {
			return;
		}
		$path = trailingslashit( $uploads['basedir'] ) . 'ehbp-patch-debug.log';
		$body = implode( "\n", $lines ) . "\n\n";
		// Keep the file from growing unbounded across many runs (cap at 1MB).
		if ( file_exists( $path ) && filesize( $path ) > 1024 * 1024 ) {
			@unlink( $path );
		}
		@file_put_contents( $path, $body, FILE_APPEND | LOCK_EX );

		// Reset the per-call snapshot store so the next apply() starts clean.
		self::$debug_snapshots = array();
	}
}
