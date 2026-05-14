<?php
/**
 * Wraps Beaver Builder's FLBuilderModel::duplicate_post and applies
 * per-row metadata (title, slug, parent) so the new draft is ready for
 * subsequent content/image rewrites.
 *
 * Also tags the cloned post with the originating job ID so we can roll
 * back / delete every page a given job produced.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use FLBuilderModel;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Page_Cloner {

	public const META_JOB_ID         = '_ehbp_job_id';
	public const META_SOURCE_POST_ID = '_ehbp_source_post_id';
	public const META_CITY_SLUG      = '_ehbp_city_slug';
	public const META_CITY_NAME      = '_ehbp_city_name';
	public const META_STATE          = '_ehbp_state';

	/**
	 * Duplicate a Beaver Builder template post and apply per-row metadata.
	 *
	 * @param int    $template_post_id The source post to clone.
	 * @param array  $row              Validated CSV row (slug, city, state, ...extras).
	 * @param string $title_format     Title template, e.g. "Roll-Off Dumpster Rentals in {{city}}, {{state}}".
	 * @param int    $parent_post_id   Parent page for the new post (0 = top-level).
	 * @param string $job_id           Job identifier used for later rollback.
	 * @return int|WP_Error            New post ID on success.
	 */
	public static function clone_for_row(
		int $template_post_id,
		array $row,
		string $title_format,
		int $parent_post_id,
		string $job_id
	) {
		if ( ! class_exists( '\FLBuilderModel' ) ) {
			return new WP_Error(
				'ehbp_no_bb',
				__( 'Beaver Builder is not active. Activate it before running a job.', 'earthhaul-bulk-pages' )
			);
		}

		$template = get_post( $template_post_id );
		if ( ! $template ) {
			return new WP_Error(
				'ehbp_no_template',
				sprintf(
					/* translators: %d: post ID */
					__( 'Template post %d does not exist.', 'earthhaul-bulk-pages' ),
					$template_post_id
				)
			);
		}

		$new_post_id = FLBuilderModel::duplicate_post( $template_post_id );
		if ( ! is_int( $new_post_id ) || $new_post_id <= 0 ) {
			return new WP_Error( 'ehbp_clone_failed', __( 'FLBuilderModel::duplicate_post did not return a post ID.', 'earthhaul-bulk-pages' ) );
		}

		$title = self::format_title( $title_format, $row );
		$slug  = sanitize_title( (string) ( $row['slug'] ?? '' ) );

		$update = wp_update_post(
			array(
				'ID'          => $new_post_id,
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_parent' => $parent_post_id,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $update ) ) {
			wp_delete_post( $new_post_id, true );
			return $update;
		}

		update_post_meta( $new_post_id, self::META_JOB_ID, $job_id );
		update_post_meta( $new_post_id, self::META_SOURCE_POST_ID, $template_post_id );
		update_post_meta( $new_post_id, self::META_CITY_SLUG, $slug );
		update_post_meta( $new_post_id, self::META_CITY_NAME, sanitize_text_field( (string) ( $row['city'] ?? '' ) ) );
		update_post_meta( $new_post_id, self::META_STATE, sanitize_text_field( (string) ( $row['state'] ?? '' ) ) );

		return $new_post_id;
	}

	/**
	 * Render a title template like "Dumpster Rentals in {{city}}, {{state}}"
	 * using the CSV row values. Falls back to "Copy of <template title>" if the
	 * format string is empty.
	 */
	public static function format_title( string $format, array $row ): string {
		$format = trim( $format );
		if ( '' === $format ) {
			$format = '{{city}}, {{state}}';
		}

		$replacements = array();
		foreach ( $row as $key => $value ) {
			$replacements[ '{{' . $key . '}}' ] = (string) $value;
		}

		return trim( strtr( $format, $replacements ) );
	}

	/**
	 * Find every post tagged with a given job ID. Used for the rollback flow.
	 *
	 * @return int[]
	 */
	public static function find_posts_for_job( string $job_id ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_key'       => self::META_JOB_ID,
				'meta_value'     => $job_id,
				'no_found_rows'  => true,
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
