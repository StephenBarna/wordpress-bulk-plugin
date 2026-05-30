<?php
/**
 * Helpers for mutating Beaver Builder layout structures and persisting them.
 *
 * Layouts are arrays of node objects keyed by node_id. Each node has a
 * `settings` object (or array) whose contents the engines want to write
 * into. PHP does not propagate writes through copied subarrays, so the
 * setter walks by reference. Persisting a layout means writing to BOTH
 * BB's draft slot AND its published slot, then busting the per-post asset
 * cache so the next page load regenerates CSS/JS instead of serving the
 * stale ones.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Layout_Mutator {

	/**
	 * Walk a settings object/array along a dot-path like
	 * `faq_items.0.faq_question` and write the value at the leaf. Uses
	 * by-reference traversal because BB nests plain arrays inside settings
	 * objects (e.g. faq_items, list_items, button-group items) and PHP
	 * does not propagate writes through copied subarrays.
	 *
	 * @param mixed  $target Reference into a node's settings object/array.
	 * @return bool          True if the leaf was written successfully.
	 */
	public static function set_at_path( &$target, string $path, string $value ): bool {
		if ( $target === null ) {
			return false;
		}
		$parts = explode( '.', $path );
		$last  = array_pop( $parts );

		$cursor = &$target;
		foreach ( $parts as $key ) {
			if ( is_object( $cursor ) ) {
				if ( ! isset( $cursor->$key ) ) {
					return false;
				}
				$cursor = &$cursor->$key;
				continue;
			}
			if ( is_array( $cursor ) ) {
				if ( ! array_key_exists( $key, $cursor ) ) {
					return false;
				}
				$cursor = &$cursor[ $key ];
				continue;
			}
			return false;
		}
		if ( is_object( $cursor ) ) {
			$cursor->$last = $value;
			return true;
		}
		if ( is_array( $cursor ) ) {
			$cursor[ $last ] = $value;
			return true;
		}
		return false;
	}

	/**
	 * Load the current "live" layout for a post: prefer the published slot
	 * (what the live URL renders), fall back to the draft slot if the page
	 * was never published in BB.
	 *
	 * @return array Empty array on failure.
	 */
	public static function load_layout( int $post_id ): array {
		if ( ! class_exists( '\FLBuilderModel' ) ) {
			return array();
		}
		$layout = \FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( empty( $layout ) ) {
			$layout = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		}
		return is_array( $layout ) ? $layout : array();
	}

	/**
	 * Write the modified layout to BOTH BB slots so the BB editor draft
	 * matches what's live, then bust AND rebuild BB's per-post asset
	 * cache. The rebuild step is what saves the front end from rendering
	 * raw HTML — `delete_asset_cache` only removes the stale CSS/JS
	 * files, and BB's lazy-regen path doesn't always fire on the next
	 * front-end view (especially on hosts with HTTP page caches in
	 * front of WP). Forcing render_css/render_js to run while the
	 * global $post is the target post writes a fresh
	 * `{post_id}-layout.css` immediately.
	 */
	public static function persist_layout( int $post_id, array $layout ): void {
		if ( ! class_exists( '\FLBuilderModel' ) ) {
			return;
		}
		\FLBuilderModel::update_layout_data( $layout, 'draft', $post_id );
		\FLBuilderModel::update_layout_data( $layout, 'published', $post_id );

		if ( method_exists( '\FLBuilderModel', 'delete_asset_cache' ) ) {
			\FLBuilderModel::delete_asset_cache( $post_id );
		}

		self::rebuild_assets( $post_id );
	}

	/**
	 * Rebuild BB's per-post CSS/JS cache so the front end has styling
	 * after a programmatic layout write. BB's render methods read from
	 * the global $post and the active post id; we swap those, render,
	 * then restore. Wrapped in a try/finally because BB throws on
	 * malformed layouts and we'd otherwise leak the post-id swap.
	 */
	private static function rebuild_assets( int $post_id ): void {
		if ( ! class_exists( '\FLBuilder' ) ) {
			return;
		}

		global $post;
		$prior_post     = $post;
		$prior_post_id  = null;
		$swapped_active = false;

		if ( method_exists( '\FLBuilderModel', 'get_post_id' ) ) {
			$prior_post_id = \FLBuilderModel::get_post_id();
		}

		try {
			$target = get_post( $post_id );
			if ( ! $target ) {
				return;
			}
			$post = $target; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			if ( method_exists( '\FLBuilderModel', 'set_post_id' ) ) {
				\FLBuilderModel::set_post_id( $post_id );
				$swapped_active = true;
			}

			if ( method_exists( '\FLBuilder', 'render_css' ) ) {
				\FLBuilder::render_css( 'published', null, null, true );
			}
			if ( method_exists( '\FLBuilder', 'render_js' ) ) {
				\FLBuilder::render_js( 'published', null, null, true );
			}
		} catch ( \Throwable $e ) {
			// Silent: caller already persisted the layout; cache rebuild
			// failure shouldn't undo the save. BB will regen lazily on
			// the next editor open.
		} finally {
			$post = $prior_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			if ( $swapped_active && method_exists( '\FLBuilderModel', 'reset_post_id' ) ) {
				\FLBuilderModel::reset_post_id();
			} elseif ( $swapped_active && null !== $prior_post_id && method_exists( '\FLBuilderModel', 'set_post_id' ) ) {
				\FLBuilderModel::set_post_id( (int) $prior_post_id );
			}
		}
	}
}
