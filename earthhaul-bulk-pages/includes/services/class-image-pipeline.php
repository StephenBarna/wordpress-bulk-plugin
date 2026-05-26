<?php
/**
 * Image pipeline: collects image references from a BB layout, generates
 * AI alt text per attachment, and (on apply) sideloads renamed copies of
 * the source attachments into the Media Library and rewrites the layout
 * to point at the new attachment IDs / URLs.
 *
 * Works in tandem with Rewrite_Engine and Yoast_Meta_Engine so the
 * Rewrite Page admin screen can run a single Generate -> Diff -> Apply
 * pass over text content, images, and SEO meta.
 *
 * Dedupe rule: if N modules on the same page reference the same Orlando
 * attachment, we sideload exactly one new attachment for the target city
 * and point all N references at it.
 *
 * Skip rules:
 *   - is_global modules: never touched
 *   - modules with the ehbp-neighborhoods (or other managed) class:
 *     never touched (those are CSV-driven, not AI-driven)
 *
 * Filename rule: case-insensitive, whole-word replacement of any source
 * city token (e.g. "orlando") with the target city slug
 * (e.g. "celebration", "champions-gate"). Handles all of:
 *   - 30-yard-dumpster-rental-sizes-orlando-fl.jpg -> ...-celebration-fl.jpg
 *   - bulk-trash-removal-services-in-orlando-fl.jpg -> ...-celebration-fl.jpg
 *   - 20-yard-dumpster-orlandoflorida.jpg -> 20-yard-dumpster-celebrationflorida.jpg
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use EarthHaul\BulkPages\Admin\Settings_Page;
use FLBuilderModel;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Image_Pipeline {

	private const MANAGED_CLASSES   = array( 'ehbp-neighborhoods' );
	private const NO_REWRITE_CLASS  = 'ehbp-no-rewrite';
	private const ALT_MAX_CHARS     = 125;

	/**
	 * Collect a deduped list of image candidates that need per-city copies.
	 *
	 * Sources scanned:
	 *   1. Module image fields (photo, image-icon) from the walker output
	 *   2. Row + column background images (bg_image) read directly from
	 *      the BB layout - these aren't surfaced by the walker because
	 *      the walker only enumerates module content
	 *   3. WordPress featured image (post `_thumbnail_id` meta) - used by
	 *      Yoast for og:image and by themes for archive thumbnails
	 *
	 * Brand-asset filter: when a non-empty $source_token is provided, we
	 * exclude candidates whose filename does NOT contain that token. This
	 * keeps logos, generic icons, and other shared site assets out of
	 * the pipeline so we don't accidentally create per-city duplicates of
	 * brand imagery.
	 *
	 * @param array  $walker_output Output of Layout_Walker::inspect().
	 * @param int    $post_id       Target post (lets us read row/col bg
	 *                              images + featured image directly).
	 * @param string $source_token  Slug-shaped source city token (e.g.
	 *                              "orlando"). When empty, brand-asset
	 *                              filter is disabled.
	 *
	 * @return array<int, array{
	 *   attachment_id: int,
	 *   source_url: string,
	 *   source_filename: string,
	 *   source_path: string,
	 *   current_alt: string,
	 *   mime_type: string,
	 *   references: array<int, array{node_id: string, module_slug: string, path: string, kind: string, value: mixed, label: string}>,
	 * }>
	 */
	public static function collect_candidates( array $walker_output, int $post_id = 0, string $source_token = '' ): array {
		$by_attachment = array();

		// 1. Module images from the walker output.
		foreach ( $walker_output['nodes'] ?? array() as $node ) {
			if ( ! empty( $node['is_global'] ) ) {
				continue;
			}
			if ( 'module' !== ( $node['type'] ?? '' ) ) {
				continue;
			}
			if ( self::has_managed_class( (string) ( $node['class'] ?? '' ) ) ) {
				continue;
			}
			if ( self::has_no_rewrite_class( (string) ( $node['ancestor_classes'] ?? $node['class'] ?? '' ) ) ) {
				continue;
			}

			$node_id     = (string) ( $node['node_id'] ?? '' );
			$module_slug = (string) ( $node['module_slug'] ?? '' );
			$node_label  = (string) ( $node['label'] ?? '' );

			$attachment_id = 0;
			$image_fields  = array();
			foreach ( $node['fields'] ?? array() as $field ) {
				$kind = (string) ( $field['kind'] ?? '' );
				if ( 'image_id' !== $kind && 'image_url' !== $kind && 'image_meta' !== $kind ) {
					continue;
				}
				$image_fields[] = $field;
				if ( 'image_id' === $kind && 0 === $attachment_id ) {
					$attachment_id = (int) ( $field['value'] ?? 0 );
				}
			}

			if ( empty( $image_fields ) || 0 === $attachment_id ) {
				continue;
			}

			if ( ! self::ensure_attachment_bucket( $by_attachment, $attachment_id ) ) {
				continue;
			}

			foreach ( $image_fields as $field ) {
				$by_attachment[ $attachment_id ]['references'][] = array(
					'node_id'     => $node_id,
					'module_slug' => $module_slug,
					'path'        => (string) ( $field['path'] ?? '' ),
					'kind'        => (string) ( $field['kind'] ?? '' ),
					'value'       => $field['value'] ?? '',
					'label'       => $node_label,
				);
			}
		}

		// 2. Row + column background images (live in the raw BB layout).
		if ( $post_id > 0 && class_exists( '\FLBuilderModel' ) ) {
			$layout = FLBuilderModel::get_layout_data( 'published', $post_id );
			if ( empty( $layout ) ) {
				$layout = FLBuilderModel::get_layout_data( 'draft', $post_id );
			}
			if ( is_array( $layout ) ) {
				foreach ( $layout as $node_id => $node ) {
					if ( ! is_object( $node ) || empty( $node->type ) ) {
						continue;
					}
					if ( 'row' !== $node->type && 'column' !== $node->type ) {
						continue;
					}
					if ( FLBuilderModel::is_node_global( $node ) ) {
						continue;
					}
					if ( empty( $node->settings ) ) {
						continue;
					}
					$class = isset( $node->settings->class ) ? (string) $node->settings->class : '';
					if ( self::has_managed_class( $class ) ) {
						continue;
					}
					if ( self::has_no_rewrite_class( $class ) ) {
						continue;
					}

					$bg_id = isset( $node->settings->bg_image ) ? (int) $node->settings->bg_image : 0;
					if ( $bg_id <= 0 ) {
						continue;
					}

					if ( ! self::ensure_attachment_bucket( $by_attachment, $bg_id ) ) {
						continue;
					}

					$by_attachment[ $bg_id ]['references'][] = array(
						'node_id'     => (string) $node_id,
						'module_slug' => $node->type,
						'path'        => 'bg_image',
						'kind'        => 'bg_image',
						'value'       => $bg_id,
						'label'       => ucfirst( $node->type ) . ' background',
					);
				}
			}
		}

		// 3. Featured image (WP _thumbnail_id post meta).
		if ( $post_id > 0 ) {
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumb_id > 0 && self::ensure_attachment_bucket( $by_attachment, $thumb_id ) ) {
				$by_attachment[ $thumb_id ]['references'][] = array(
					'node_id'     => '_post_meta',
					'module_slug' => 'featured_image',
					'path'        => '_thumbnail_id',
					'kind'        => 'featured_image',
					'value'       => $thumb_id,
					'label'       => 'WordPress featured image',
				);
			}
		}

		// Brand-asset filter: drop entries whose filename doesn't contain
		// the source city token (case-insensitive substring). Logos, icons,
		// and other site-wide assets typically don't have a city in their
		// filename, so this keeps them out without manual tagging.
		if ( '' !== $source_token ) {
			foreach ( $by_attachment as $id => $cand ) {
				if ( false === stripos( (string) $cand['source_filename'], $source_token ) ) {
					unset( $by_attachment[ $id ] );
				}
			}
		}

		return array_values( $by_attachment );
	}

	/**
	 * Lazy-init the candidate bucket for an attachment ID. Returns false
	 * if the attachment doesn't exist or its file is missing on disk
	 * (defensive: stale media library entries should be skipped).
	 */
	private static function ensure_attachment_bucket( array &$by_attachment, int $attachment_id ): bool {
		if ( isset( $by_attachment[ $attachment_id ] ) ) {
			return true;
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}
		$path = (string) get_attached_file( $attachment_id );
		if ( '' === $path || ! file_exists( $path ) ) {
			return false;
		}
		$by_attachment[ $attachment_id ] = array(
			'attachment_id'   => $attachment_id,
			'source_url'      => (string) wp_get_attachment_url( $attachment_id ),
			'source_filename' => basename( $path ),
			'source_path'     => $path,
			'current_alt'     => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'mime_type'       => (string) get_post_mime_type( $attachment_id ),
			'references'      => array(),
		);
		return true;
	}

	/**
	 * Generate alt text per candidate using OpenAI. Returns the same
	 * candidate array with `suggested_filename`, `suggested_alt`, and
	 * `error` keys populated.
	 *
	 * @param array  $candidates    From collect_candidates().
	 * @param string $source_label  e.g. "Orlando, FL".
	 * @param string $target_label  e.g. "Celebration, FL".
	 * @param string $source_token  e.g. "orlando" - replaced in filenames.
	 * @param string $target_token  e.g. "celebration" - the replacement.
	 */
	public static function run(
		array $candidates,
		string $source_label,
		string $target_label,
		string $source_token,
		string $target_token,
		?callable $progress_cb = null
	): array {
		$api_key     = (string) Settings_Page::get( 'api_key', '' );
		$model       = (string) Settings_Page::get( 'model', Settings_Page::DEFAULT_MODEL );
		$brand_voice = (string) Settings_Page::get( 'brand_voice', Settings_Page::DEFAULT_VOICE );
		$client      = new OpenAI_Client( $api_key, $model );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$source_city = self::extract_city( $source_label );
		$target_city = self::extract_city( $target_label );

		$total = count( $candidates );
		foreach ( $candidates as $i => $cand ) {
			$candidates[ $i ]['suggested_filename'] = self::rewrite_filename(
				(string) $cand['source_filename'],
				$source_token,
				$target_token
			);
			$candidates[ $i ]['filename_changed'] = $candidates[ $i ]['suggested_filename'] !== $cand['source_filename'];

			// Skip the alt-text API call if the image already looks
			// target-localized: filename didn't change AND existing alt
			// contains the target city without the source city.
			$current_alt    = (string) ( $cand['current_alt'] ?? '' );
			$alt_localized  = '' !== $target_city
				&& self::contains_word( $current_alt, $target_city )
				&& ( '' === $source_city || ! self::contains_word( $current_alt, $source_city ) );

			if ( ! $candidates[ $i ]['filename_changed'] && $alt_localized && '' !== trim( $current_alt ) ) {
				$candidates[ $i ]['suggested_alt'] = $current_alt;
				$candidates[ $i ]['error']         = '';
				$candidates[ $i ]['skipped']       = true;
				if ( is_callable( $progress_cb ) ) {
					$progress_cb( $i + 1, $total, $candidates[ $i ] );
				}
				continue;
			}

			// Pass the just-mutated candidate (which now carries
			// suggested_filename) so the prompt anchors on the renamed
			// file, not the source-city version.
			$prompt = self::build_alt_prompt( $candidates[ $i ], $source_label, $target_label, $brand_voice );
			$reply  = $client->chat( $prompt['user'], $prompt['system'], 30, 0.3 );

			if ( $reply instanceof WP_Error ) {
				$candidates[ $i ]['suggested_alt'] = $current_alt;
				$candidates[ $i ]['error']         = $reply->get_error_message();
				$candidates[ $i ]['skipped']       = false;
			} else {
				$candidates[ $i ]['suggested_alt'] = self::sanitize_alt( (string) $reply );
				$candidates[ $i ]['error']         = '';
				$candidates[ $i ]['skipped']       = false;
			}

			if ( is_callable( $progress_cb ) ) {
				$progress_cb( $i + 1, $total, $candidates[ $i ] );
			}
		}

		return $candidates;
	}

	/**
	 * Apply approved candidates: for each, sideload a renamed copy of the
	 * source attachment, save the new alt text, and rewrite layout
	 * references. Returns a stat summary keyed by source attachment ID.
	 *
	 * @param array $layout            Layout array (modified in place).
	 * @param int   $post_id           Target post (parent for new attachments).
	 * @param array $applied_candidates Subset of candidates to actually apply.
	 * @return array{applied: int, failed: int, errors: array<int, string>, mapping: array<int, int>}
	 */
	public static function apply( array &$layout, int $post_id, array $applied_candidates ): array {
		$applied  = 0;
		$failed   = 0;
		$errors   = array();
		$mapping  = array(); // old_attachment_id -> new_attachment_id

		foreach ( $applied_candidates as $cand ) {
			$old_id            = (int) ( $cand['attachment_id'] ?? 0 );
			$new_filename      = (string) ( $cand['suggested_filename'] ?? '' );
			$alt_text          = (string) ( $cand['suggested_alt'] ?? '' );
			$source_path       = (string) ( $cand['source_path'] ?? '' );

			if ( $old_id <= 0 || '' === $source_path || ! file_exists( $source_path ) ) {
				$failed++;
				$errors[] = sprintf( 'Attachment %d: source file missing', $old_id );
				continue;
			}

			// If filename didn't change AND alt text didn't change, no-op.
			$current_alt = (string) ( $cand['current_alt'] ?? '' );
			if ( ! ( $cand['filename_changed'] ?? false ) && trim( $alt_text ) === trim( $current_alt ) ) {
				$applied++;
				continue;
			}

			$new_id = self::sideload_copy( $old_id, $source_path, $new_filename, $post_id );
			if ( is_wp_error( $new_id ) ) {
				$failed++;
				$errors[] = sprintf( 'Attachment %d: %s', $old_id, $new_id->get_error_message() );
				continue;
			}

			// Save the AI alt text on the new attachment.
			if ( '' !== $alt_text ) {
				update_post_meta( $new_id, '_wp_attachment_image_alt', $alt_text );
			}

			$mapping[ $old_id ] = $new_id;
			self::rewrite_layout_references( $layout, $old_id, $new_id );

			// Featured image is post-meta, not in the BB layout. If any
			// reference for this candidate was tagged featured_image, swap
			// `_thumbnail_id` to point at the new attachment.
			foreach ( (array) ( $cand['references'] ?? array() ) as $ref ) {
				if ( ( $ref['kind'] ?? '' ) === 'featured_image' ) {
					update_post_meta( $post_id, '_thumbnail_id', $new_id );
					break;
				}
			}

			$applied++;
		}

		return array(
			'applied' => $applied,
			'failed'  => $failed,
			'errors'  => $errors,
			'mapping' => $mapping,
		);
	}

	/* -------------------------------------------------------------------- */
	/* Filename + alt-text helpers                                          */
	/* -------------------------------------------------------------------- */

	/**
	 * Replace whole-word occurrences of $source_token in a filename with
	 * $target_token. Operates only on the basename portion (preserves
	 * extension). Word boundary is regex \b which treats hyphens as
	 * boundaries, so:
	 *   "foo-orlando-fl.jpg"        -> "foo-celebration-fl.jpg"
	 *   "foo-orlandoflorida.jpg"    -> "foo-celebrationflorida.jpg" (no \b
	 *                                  between "orlando" and "florida")
	 *
	 * For the "no boundary" case we also do a non-word-boundary fallback
	 * that's only applied if the strict pattern produced no change AND
	 * the source_token literally appears in the filename. This keeps the
	 * old behaviour for filenames like "orlandoflorida".
	 */
	public static function rewrite_filename( string $filename, string $source_token, string $target_token ): string {
		if ( '' === $filename || '' === $source_token || '' === $target_token || $source_token === $target_token ) {
			return $filename;
		}
		$ext  = '';
		$base = $filename;
		if ( false !== ( $pos = strrpos( $filename, '.' ) ) ) {
			$base = substr( $filename, 0, $pos );
			$ext  = substr( $filename, $pos );
		}

		// Strict word-boundary replacement first.
		$strict = preg_replace(
			'/\b' . preg_quote( $source_token, '/' ) . '\b/i',
			$target_token,
			$base
		);
		if ( null !== $strict && $strict !== $base ) {
			return $strict . $ext;
		}

		// Fallback: token appears glued to neighbouring letters (e.g.
		// "orlandoflorida"). Replace as a plain substring, case insensitive.
		if ( false !== stripos( $base, $source_token ) ) {
			$loose = preg_replace(
				'/' . preg_quote( $source_token, '/' ) . '/i',
				$target_token,
				$base
			);
			if ( null !== $loose && $loose !== $base ) {
				return $loose . $ext;
			}
		}

		return $filename;
	}

	private static function build_alt_prompt( array $cand, string $source_label, string $target_label, string $brand_voice ): array {
		$current_alt        = trim( (string) ( $cand['current_alt'] ?? '' ) );
		// Show the model the post-rename filename so the city it sees as a
		// hint is the TARGET city, not the source. Falls back to source
		// filename only if rename produced nothing (defensive).
		$filename_hint      = (string) ( $cand['suggested_filename'] ?? '' );
		if ( '' === $filename_hint ) {
			$filename_hint = (string) ( $cand['source_filename'] ?? '' );
		}
		$module_slug = '';
		if ( ! empty( $cand['references'] ) ) {
			$module_slug = (string) ( $cand['references'][0]['module_slug'] ?? '' );
		}

		// Pre-localize the existing alt before it goes into the prompt:
		// any whole-word source city / state token gets rewritten to the
		// target. The model sees a structurally helpful "this image is
		// a 20-yard dumpster in <Target>, FL" hint instead of a 50/50
		// signal where two cities both look correct. Whole-word so we
		// don't mangle compound identifiers like "Orlandowide".
		$localized_alt = self::localize_text( $current_alt, $source_label, $target_label );

		$system = "You write alt text for images on a roll-off dumpster rental company's location landing page.\n"
			. "Hard rules:\n"
			. "- Plain text only. No quotes, no markdown, no commentary.\n"
			. "- " . self::ALT_MAX_CHARS . " characters max. Aim for 50-100.\n"
			. "- Describe ONLY what is visibly in the image: the object, the scene, and the location. Do NOT speculate on what the image is 'for', who it serves, or what it's 'ready for'.\n"
			. "- BANNED phrasings: 'ready for', 'ideal for', 'perfect for', 'great for', 'showcasing', 'representing'.\n"
			. "- BANNED hype words: 'amazing', 'best', 'top', 'efficient', 'professional', 'reliable'.\n"
			. "- BANNED AI-tells: em-dashes, 'navigate', 'leverage', 'unlock', 'embark', 'realm', 'tapestry', 'meticulous', 'delve'.\n"
			. "- The filename is just a HINT to what the image is. Don't invent use-cases (renovation, cleanup, demolition, project) unless they're literally visible in the photo.\n"
			. "- LOCATION RULE: the ONLY city + state you may name is the TARGET city + state given below. If a hint (filename, existing alt) references any other city or state, IGNORE that and use the target. Never echo a non-target city back in your output.\n"
			. "- Mention the target city + state at the end if it reads naturally. Skip the location if it makes the alt awkward.\n"
			. "- ALWAYS use the two-letter U.S. state abbreviation (FL, CA, TX) - never the full state name. If the target label spells out the state, still output the abbreviation.\n"
			. "- Preferred shape: '<noun phrase describing what's in the photo> in <City>, <State>.'\n"
			. "  Examples: '20-yard roll-off dumpster in Davenport, FL.'\n"
			. "            'Yellow EarthHaul truck dropping a dumpster in Davenport, FL.'\n"
			. "            'EarthHaul logo.' (no city if it adds nothing)";

		$user = "Brand voice: " . ( '' === $brand_voice ? '(none specified)' : $brand_voice ) . "\n\n"
			. "TARGET city + state (the ONLY location you may use): {$target_label}\n"
			. "Image filename (subject hint, do not editorialize): {$filename_hint}\n"
			. "Module type: {$module_slug}\n"
			. "Existing alt text (already pre-localized to the target city; use as a structural hint, do not copy verbatim): " . ( '' === $localized_alt ? '(empty)' : $localized_alt ) . "\n\n"
			. "Write the alt text. Output ONLY the alt text, nothing else.";

		return array(
			'system' => $system,
			'user'   => $user,
		);
	}

	/**
	 * Whole-word case-insensitive replace of source city/state tokens with
	 * target city/state tokens. Used to pre-localize hint text before it's
	 * shown to the model, so the prompt doesn't broadcast the source city
	 * as one of two equally-valid choices.
	 */
	private static function localize_text( string $text, string $source_label, string $target_label ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}
		$src_city = self::extract_city( $source_label );
		$tgt_city = self::extract_city( $target_label );
		$src_state = self::extract_state( $source_label );
		$tgt_state = self::extract_state( $target_label );

		if ( '' !== $src_city && '' !== $tgt_city ) {
			$text = preg_replace(
				'/\b' . preg_quote( $src_city, '/' ) . '\b/i',
				$tgt_city,
				$text
			) ?? $text;
		}
		if ( '' !== $src_state && '' !== $tgt_state ) {
			$text = preg_replace(
				'/\b' . preg_quote( $src_state, '/' ) . '\b/i',
				$tgt_state,
				$text
			) ?? $text;
		}
		return $text;
	}

	private static function extract_state( string $label ): string {
		$parts = explode( ',', $label );
		return trim( (string) ( $parts[1] ?? '' ) );
	}

	private static function sanitize_alt( string $value ): string {
		$value = trim( $value );
		// Strip surrounding quotes if the model wrapped output despite being told not to.
		$value = preg_replace( '/^["\'\x{201C}\x{2018}]+/u', '', $value ) ?? $value;
		$value = preg_replace( '/["\'\x{201D}\x{2019}]+$/u', '', $value ) ?? $value;
		// Strip surrounding markdown code fences.
		$value = preg_replace( '/^```[a-z]*\s*\n?/i', '', $value ) ?? $value;
		$value = preg_replace( '/\n?```\s*$/', '', $value ) ?? $value;
		// Collapse internal whitespace.
		$value = preg_replace( '/\s+/', ' ', $value ) ?? $value;
		// Hard cap.
		if ( strlen( $value ) > self::ALT_MAX_CHARS ) {
			$value = rtrim( substr( $value, 0, self::ALT_MAX_CHARS - 1 ) ) . '.';
		}
		return trim( $value );
	}

	/* -------------------------------------------------------------------- */
	/* Sideload + layout rewrite                                            */
	/* -------------------------------------------------------------------- */

	/**
	 * Copy the source file into the uploads dir under a new filename and
	 * register a new attachment for it. Preserves the parent post (so the
	 * cloned page "owns" its own attachments).
	 *
	 * @return int|WP_Error New attachment ID.
	 */
	private static function sideload_copy( int $source_id, string $source_path, string $new_filename, int $parent_post_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// If filename collides in the uploads dir, WP appends -1, -2 etc.
		// We let it do that rather than overwriting.
		$upload  = wp_upload_dir();
		$basedir = $upload['basedir'] ?? '';
		if ( '' === $basedir ) {
			return new WP_Error( 'ehbp_no_uploads', 'Could not resolve uploads dir.' );
		}

		// Place the new file in the same year/month subdirectory the
		// source lived in, to keep WP's organize-by-date layout consistent.
		$rel_dir       = trim( str_replace( $basedir, '', dirname( $source_path ) ), DIRECTORY_SEPARATOR );
		$target_dir    = '' === $rel_dir ? $basedir : $basedir . DIRECTORY_SEPARATOR . $rel_dir;
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'ehbp_mkdir', "Could not create $target_dir" );
		}

		$candidate_path = $target_dir . DIRECTORY_SEPARATOR . $new_filename;
		$candidate_path = self::resolve_unique_path( $candidate_path );

		if ( ! @copy( $source_path, $candidate_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'ehbp_copy_failed', "Could not copy $source_path -> $candidate_path" );
		}

		$mime         = wp_check_filetype( $candidate_path )['type'] ?: get_post_mime_type( $source_id );
		$relative_url = trailingslashit( $upload['baseurl'] ?? '' ) . ltrim( str_replace( $basedir, '', $candidate_path ), DIRECTORY_SEPARATOR );

		$attachment   = array(
			'guid'           => $relative_url,
			'post_mime_type' => $mime ?: 'image/jpeg',
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $candidate_path ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$new_id = wp_insert_attachment( $attachment, $candidate_path, $parent_post_id );
		if ( is_wp_error( $new_id ) || 0 === (int) $new_id ) {
			@unlink( $candidate_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'ehbp_insert_attach_failed', 'wp_insert_attachment returned 0' );
		}

		$meta = wp_generate_attachment_metadata( $new_id, $candidate_path );
		wp_update_attachment_metadata( $new_id, $meta );

		// Mark this attachment as plugin-sideloaded so the dashboard's
		// cascade-delete and orphan-cleanup tooling can identify it
		// later without ambiguity. _ehbp_source_attachment_id points at
		// the original Orlando-side asset this was copied from, useful
		// for debugging mis-renames or audits.
		update_post_meta( (int) $new_id, '_ehbp_cloned_attachment', 1 );
		update_post_meta( (int) $new_id, '_ehbp_source_attachment_id', (int) $source_id );

		return (int) $new_id;
	}

	private static function resolve_unique_path( string $path ): string {
		if ( ! file_exists( $path ) ) {
			return $path;
		}
		$dir       = dirname( $path );
		$filename  = basename( $path );
		$ext_pos   = strrpos( $filename, '.' );
		$base      = false === $ext_pos ? $filename : substr( $filename, 0, $ext_pos );
		$ext       = false === $ext_pos ? '' : substr( $filename, $ext_pos );
		$counter   = 1;
		while ( true ) {
			$candidate = $dir . DIRECTORY_SEPARATOR . $base . '-' . $counter . $ext;
			if ( ! file_exists( $candidate ) ) {
				return $candidate;
			}
			$counter++;
			if ( $counter > 999 ) {
				return $candidate;
			}
		}
	}

	/**
	 * Walk the layout and rewrite every reference to $old_attachment_id
	 * (in any of the BB image-storing keys: photo, photo_id, photo_src,
	 * photo_url, data, data->id, data->url, data->sizes->*->url) to point
	 * at $new_attachment_id.
	 *
	 * Modifies $layout by reference.
	 */
	private static function rewrite_layout_references( array &$layout, int $old_id, int $new_id ): void {
		$old_url   = (string) wp_get_attachment_url( $old_id );
		$new_url   = (string) wp_get_attachment_url( $new_id );
		$new_data  = self::build_photo_data_object( $new_id );
		$old_sizes = self::collect_size_urls( $old_id );

		foreach ( $layout as $node_id => $node ) {
			if ( ! is_object( $node ) || empty( $node->settings ) ) {
				continue;
			}
			$s = $node->settings;

			// `photo` and `photo_id` (modules) + `bg_image` (rows/columns):
			// scalar attachment IDs.
			foreach ( array( 'photo', 'photo_id', 'bg_image' ) as $k ) {
				if ( isset( $s->$k ) ) {
					$current = $s->$k;
					if ( is_numeric( $current ) && (int) $current === $old_id ) {
						$s->$k = $new_id;
					}
				}
			}

			// `photo_src`, `photo_url` (modules) + `bg_image_src` (rows):
			// image URLs (possibly a sized variant).
			foreach ( array( 'photo_src', 'photo_url', 'bg_image_src' ) as $k ) {
				if ( isset( $s->$k ) && is_string( $s->$k ) && $s->$k !== '' ) {
					if ( $s->$k === $old_url ) {
						$s->$k = $new_url;
					} else {
						foreach ( $old_sizes as $size_url ) {
							if ( $s->$k === $size_url ) {
								$s->$k = $new_url;
								break;
							}
						}
					}
				}
			}

			// `data`: nested object/array with id, url, sizes, etc.
			if ( isset( $s->data ) && ( is_object( $s->data ) || is_array( $s->data ) ) ) {
				$matches = false;
				if ( is_object( $s->data ) && isset( $s->data->id ) && (int) $s->data->id === $old_id ) {
					$matches = true;
				} elseif ( is_array( $s->data ) && isset( $s->data['id'] ) && (int) $s->data['id'] === $old_id ) {
					$matches = true;
				}
				if ( $matches ) {
					$s->data = $new_data;
				}
			}
		}
	}

	/**
	 * Build the object that BB stores in `settings->data` for photo-mode
	 * modules. We use FLBuilderPhoto when available so the shape is
	 * exactly what BB expects; fall back to a minimal hand-rolled object.
	 */
	private static function build_photo_data_object( int $attachment_id ) {
		if ( class_exists( '\FLBuilderPhoto' ) ) {
			try {
				$photo = new \FLBuilderPhoto( $attachment_id );
				if ( isset( $photo->data ) ) {
					return $photo->data;
				}
			} catch ( \Throwable $e ) {
				// Fall through to manual build.
			}
		}
		$obj            = new \stdClass();
		$obj->id        = $attachment_id;
		$obj->url       = (string) wp_get_attachment_url( $attachment_id );
		$obj->alt       = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$obj->title     = get_the_title( $attachment_id );
		$obj->sizes     = self::sizes_object( $attachment_id );
		return $obj;
	}

	/**
	 * Collect every registered size URL for an attachment, so layout
	 * references that point at e.g. -1024x768 variants get rewritten too.
	 *
	 * @return array<int, string>
	 */
	private static function collect_size_urls( int $attachment_id ): array {
		$urls   = array();
		$meta   = wp_get_attachment_metadata( $attachment_id );
		$sizes  = is_array( $meta ) && isset( $meta['sizes'] ) ? $meta['sizes'] : array();
		$base   = wp_get_attachment_url( $attachment_id );

		if ( $base ) {
			$urls[] = $base;
		}
		if ( is_array( $sizes ) && $base ) {
			$base_dir = dirname( $base );
			foreach ( $sizes as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$urls[] = $base_dir . '/' . $size['file'];
				}
			}
		}
		return $urls;
	}

	private static function sizes_object( int $attachment_id ) {
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$sizes = is_array( $meta ) && isset( $meta['sizes'] ) ? $meta['sizes'] : array();
		$base  = (string) wp_get_attachment_url( $attachment_id );
		$out   = new \stdClass();
		if ( ! is_array( $sizes ) || '' === $base ) {
			return $out;
		}
		$base_dir = dirname( $base );
		foreach ( $sizes as $name => $info ) {
			$entry         = new \stdClass();
			$entry->url    = $base_dir . '/' . ( $info['file'] ?? '' );
			$entry->width  = (int) ( $info['width'] ?? 0 );
			$entry->height = (int) ( $info['height'] ?? 0 );
			$out->$name    = $entry;
		}
		return $out;
	}

	/* -------------------------------------------------------------------- */
	/* Misc                                                                 */
	/* -------------------------------------------------------------------- */

	private static function extract_city( string $label ): string {
		$parts = explode( ',', $label );
		return trim( $parts[0] ?? '' );
	}

	private static function contains_word( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return false;
		}
		return (bool) preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/i', $haystack );
	}

	private static function has_managed_class( string $class_attr ): bool {
		if ( '' === $class_attr ) {
			return false;
		}
		$tokens = preg_split( '/\s+/', trim( $class_attr ) ) ?: array();
		foreach ( self::MANAGED_CLASSES as $managed ) {
			if ( in_array( $managed, $tokens, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function has_no_rewrite_class( string $class_attr ): bool {
		if ( '' === $class_attr ) {
			return false;
		}
		$tokens = preg_split( '/\s+/', trim( $class_attr ) ) ?: array();
		return in_array( self::NO_REWRITE_CLASS, $tokens, true );
	}

	/**
	 * Stable ID for an applied candidate (used as Apply checkbox value).
	 */
	public static function field_id( int $attachment_id ): string {
		return 'img:' . $attachment_id;
	}
}
