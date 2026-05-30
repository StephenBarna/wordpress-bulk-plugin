<?php
/**
 * Patch_Image_Cloner: per-target-page image localization for patched
 * Beaver Builder rows.
 *
 * Patch_Engine handles structural row copying and string substitution.
 * What it doesn't do is sideload the source-page's images as fresh
 * city-localized attachments. Without this pass the patched row on
 * Winter Haven still references Orlando's media library entries: the
 * card images load from `/uploads/.../...-orlando.jpg` and the
 * underlying attachment IDs point at Orlando's posts.
 *
 * This class:
 *   1. Walks photo modules in a freshly-cloned subtree.
 *   2. For each photo, looks up the source attachment's file on disk.
 *   3. Renames the filename to swap the source city slug ("orlando")
 *      for the target city slug ("winter-haven").
 *   4. Sideloads a copy as a NEW attachment, parented to the target
 *      post, stamped with provenance meta so re-runs can reuse rather
 *      than duplicate.
 *   5. Rewrites the photo module to reference the new attachment ID,
 *      URL, and BB data blob.
 *
 * No AI calls. Alt text + image titles are localized by the engine's
 * upstream slug-swap pass; we only handle the file/attachment side.
 *
 * Idempotency: each clone is stamped with `_ehbp_cloned_for_post_id`
 * meta. On re-run, an existing clone with the same source-attachment-ID
 * + target-post-ID is reused instead of creating a duplicate.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Patch_Image_Cloner {

	/** Stamped on attachments produced by the patch image pass. */
	public const META_CLONED_FOR_POST_ID = '_ehbp_cloned_for_post_id';

	/**
	 * Walk a cloned subtree and localize every photo module's image to
	 * the target city. Mutates $subtree in place. Safe to call when
	 * source/target have the same city slug (no-op).
	 *
	 * @param array<string, object> $subtree     Output of Patch_Engine::deep_clone_subtree.
	 * @param array{city:string, state:string, slug_state:string, proper_city:string, proper_label:string} $source_pair
	 * @param array{city:string, state:string, slug_state:string, proper_city:string, proper_label:string} $target_pair
	 * @param int                   $target_id   Target page post ID. Used as the
	 *                                            attachment parent + dedup key.
	 * @param array<int, string>    $log         Reference into Patch_Engine's
	 *                                            debug log lines; we append to
	 *                                            it for traceability.
	 *
	 * @return array{cloned:int, reused:int, skipped:int, failed:int}
	 */
	public static function localize_photos_in_subtree(
		array &$subtree,
		array $source_pair,
		array $target_pair,
		int $target_id,
		array &$log
	): array {
		$counters = array( 'cloned' => 0, 'reused' => 0, 'skipped' => 0, 'failed' => 0 );

		// No-op when source/target share a city. This usually means the
		// source IS the target — invalid in apply() but we belt-and-brace.
		if ( '' === $source_pair['city'] || '' === $target_pair['city']
			|| $source_pair['city'] === $target_pair['city'] ) {
			$log[] = sprintf(
				'  [image_cloner] skip: source slug %s == target slug %s (or empty)',
				$source_pair['city'],
				$target_pair['city']
			);
			return $counters;
		}

		foreach ( $subtree as $node ) {
			if ( ! is_object( $node ) || empty( $node->settings ) ) {
				continue;
			}
			$module = (string) ( $node->settings->type ?? '' );
			if ( 'photo' !== $module ) {
				continue;
			}
			$result = self::localize_one_photo( $node->settings, $source_pair, $target_pair, $target_id, $log );
			$counters[ $result ]++;
		}

		$log[] = sprintf(
			'  [image_cloner] cloned=%d reused=%d skipped=%d failed=%d',
			$counters['cloned'],
			$counters['reused'],
			$counters['skipped'],
			$counters['failed']
		);
		return $counters;
	}

	/**
	 * Localize a single photo module. Returns one of: cloned | reused |
	 * skipped | failed (caller bumps the matching counter).
	 *
	 * "Skipped" cases:
	 *   - photo source is a URL string (not an attachment ID)
	 *   - source attachment is missing or has no on-disk file
	 *   - filename does not contain the source city slug (we won't
	 *     rename non-localized filenames; usually that's a generic
	 *     placeholder image like a logo)
	 */
	private static function localize_one_photo(
		object $settings,
		array $source_pair,
		array $target_pair,
		int $target_id,
		array &$log
	): string {
		$source_attachment_id = self::resolve_source_attachment_id( $settings );
		if ( $source_attachment_id <= 0 ) {
			$log[] = '  [image_cloner] skip: photo module without numeric attachment id';
			return 'skipped';
		}

		// Reuse a previous patch run's clone if one exists for this
		// (source attachment, target post) pair. Avoids accumulating
		// duplicate attachments on repeated patch runs.
		$existing = self::find_existing_clone( $source_attachment_id, $target_id );
		if ( $existing > 0 ) {
			self::rewrite_photo_module( $settings, $existing );
			$log[] = sprintf(
				'  [image_cloner] reused attachment %d (source=%d) on photo node',
				$existing,
				$source_attachment_id
			);
			return 'reused';
		}

		$source_path = (string) get_attached_file( $source_attachment_id );
		if ( '' === $source_path || ! file_exists( $source_path ) ) {
			$log[] = sprintf(
				'  [image_cloner] skip: source attachment %d has no on-disk file (%s)',
				$source_attachment_id,
				$source_path
			);
			return 'skipped';
		}

		$source_filename = basename( $source_path );
		$new_filename    = Image_Pipeline::rewrite_filename(
			$source_filename,
			$source_pair['city'],
			$target_pair['city']
		);
		if ( $new_filename === $source_filename ) {
			$log[] = sprintf(
				'  [image_cloner] skip: filename %s does not contain source slug %s (kept attachment %d)',
				$source_filename,
				$source_pair['city'],
				$source_attachment_id
			);
			return 'skipped';
		}

		$new_id = Image_Pipeline::sideload_attachment_copy(
			$source_attachment_id,
			$source_path,
			$new_filename,
			$target_id
		);
		if ( is_wp_error( $new_id ) || (int) $new_id <= 0 ) {
			$log[] = sprintf(
				'  [image_cloner] FAIL: sideload of %s -> %s for source=%d, target=%d (%s)',
				$source_filename,
				$new_filename,
				$source_attachment_id,
				$target_id,
				is_wp_error( $new_id ) ? $new_id->get_error_message() : 'invalid id'
			);
			return 'failed';
		}

		// Stamp dedup meta so future runs can find this clone.
		update_post_meta( (int) $new_id, self::META_CLONED_FOR_POST_ID, $target_id );

		// Localize alt text + title on the new attachment so any
		// downstream rendering (Yoast OpenGraph, srcset alt fallbacks)
		// uses the target city's wording.
		self::localize_attachment_text(
			(int) $new_id,
			$source_attachment_id,
			$source_pair,
			$target_pair
		);

		self::rewrite_photo_module( $settings, (int) $new_id );

		$log[] = sprintf(
			'  [image_cloner] cloned attachment %d -> %d (%s -> %s)',
			$source_attachment_id,
			(int) $new_id,
			$source_filename,
			$new_filename
		);
		return 'cloned';
	}

	/**
	 * Pull the source attachment ID off a BB photo module's settings.
	 * BB stores it on `photo` as either an integer or a numeric string;
	 * if the user uploaded via URL it'll be a non-numeric URL string,
	 * which we treat as "not localizable".
	 */
	private static function resolve_source_attachment_id( object $settings ): int {
		$raw = $settings->photo ?? '';
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}
		// `data->id` is the secondary location BB writes the id to.
		if ( isset( $settings->data ) && is_object( $settings->data ) && isset( $settings->data->id ) && is_numeric( $settings->data->id ) ) {
			return (int) $settings->data->id;
		}
		return 0;
	}

	/**
	 * Look up a previously-cloned attachment for the given source +
	 * target. Returns the attachment ID, or 0 if none found.
	 */
	private static function find_existing_clone( int $source_attachment_id, int $target_id ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_ehbp_source_attachment_id',
						'value' => (string) $source_attachment_id,
					),
					array(
						'key'   => self::META_CLONED_FOR_POST_ID,
						'value' => (string) $target_id,
					),
				),
			)
		);
		$ids = $query->posts;
		return is_array( $ids ) && ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Rewrite the BB photo module to reference a new attachment ID.
	 * Updates `photo`, `photo_src`, and `data` so the editor and the
	 * front-end render the new image consistently.
	 */
	private static function rewrite_photo_module( object $settings, int $new_id ): void {
		$settings->photo     = (string) $new_id;
		$settings->photo_src = (string) wp_get_attachment_url( $new_id );
		$settings->data      = Image_Pipeline::get_photo_data_object( $new_id );
	}

	/**
	 * Copy alt text + caption + post_title from the source attachment
	 * onto the new one, with the source city slug/name swapped for the
	 * target's. Mirrors the substitutions Patch_Engine's slug-swap pass
	 * does on inline strings, but applied to the attachment's own
	 * fields (which BB renders into srcset alt fallbacks etc.).
	 */
	private static function localize_attachment_text(
		int $new_id,
		int $source_attachment_id,
		array $source_pair,
		array $target_pair
	): void {
		$source_alt   = (string) get_post_meta( $source_attachment_id, '_wp_attachment_image_alt', true );
		$source_post  = get_post( $source_attachment_id );
		$source_title = $source_post ? (string) $source_post->post_title : '';
		$source_excerpt = $source_post ? (string) $source_post->post_excerpt : '';

		$swap = static function ( string $value ) use ( $source_pair, $target_pair ): string {
			if ( '' === $value ) {
				return $value;
			}
			// Slug-state form first ("orlando-fl" -> "winter-haven-fl").
			$value = (string) preg_replace(
				'/(?<![a-z0-9])' . preg_quote( $source_pair['slug_state'], '/' ) . '(?![a-z0-9])/i',
				$target_pair['slug_state'],
				$value
			);
			// Bare city, case-aware (proper-case in prose, slug in URLs).
			$proper       = '' !== $target_pair['proper_city'] ? $target_pair['proper_city'] : $target_pair['city'];
			$proper_upper = strtoupper( $proper );
			$slug_target  = $target_pair['city'];
			$value        = (string) preg_replace_callback(
				'/(?<![a-z0-9])' . preg_quote( $source_pair['city'], '/' ) . '(?![a-z0-9])/i',
				static function ( array $m ) use ( $proper, $proper_upper, $slug_target ): string {
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
				$value
			);
			return $value;
		};

		$new_alt     = $swap( $source_alt );
		$new_title   = $swap( $source_title );
		$new_excerpt = $swap( $source_excerpt );

		if ( '' !== $new_alt ) {
			update_post_meta( $new_id, '_wp_attachment_image_alt', $new_alt );
		}
		$update = array( 'ID' => $new_id );
		if ( '' !== $new_title ) {
			$update['post_title'] = $new_title;
		}
		if ( '' !== $new_excerpt ) {
			$update['post_excerpt'] = $new_excerpt;
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
	}
}
