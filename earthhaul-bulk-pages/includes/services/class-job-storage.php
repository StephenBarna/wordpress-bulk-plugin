<?php
/**
 * File-backed storage for the bulky inputs of a Bulk Job (parsed cities CSV
 * + neighborhoods map).
 *
 * Why files instead of just stuffing this into the job's transient?
 *
 * On managed/shared hosts (SiteGround, WP Engine, Pantheon, etc.) the
 * object cache backing transients is typically Memcached or Redis with a
 * default per-value cap of 1 MB. A job for 200+ cities with
 * neighborhood lists can easily blow past that. The cache silently drops
 * the SET, the next page load reads back null, and from the user's
 * perspective "nothing happens" - the form re-renders without a progress
 * bar and no error.
 *
 * We side-step the limit by moving the two largest pieces of state to
 * JSON files under wp-content/uploads/ehbp-jobs/<job_id>/. The transient
 * keeps only metadata + progress + per-city results, which stays small
 * even on big runs (a few hundred KB at most).
 *
 * Files are cleaned up when the job finishes (or is cancelled). Stale
 * directories from interrupted jobs survive in the uploads dir until the
 * site owner cleans them out manually - they're plain JSON and never
 * touched by anything outside this plugin, so leaving them is safe but
 * not free on storage. A future enhancement could add a daily cron sweep
 * for ehbp-jobs/* older than 24h.
 *
 * Layout on disk:
 *   wp-content/uploads/ehbp-jobs/
 *     index.html          ← empty, blocks directory listing
 *     <job_id>/
 *       cities.json       ← parsed CSV rows: [{slug, city, state, ...}, ...]
 *       neighborhoods.json← {city_slug: ["Name 1", "Name 2", ...]}
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Job_Storage {

	/**
	 * Per-request read cache so multiple call sites in a single PHP
	 * process don't re-decode the same JSON. Keyed by job_id.
	 *
	 * @var array<string, array>
	 */
	private static array $cities_cache = array();

	/**
	 * @var array<string, array>
	 */
	private static array $neighborhoods_cache = array();

	/**
	 * Resolve the per-job storage directory, creating the parent and
	 * job-scoped folders if needed. Drops an empty index.html in the
	 * parent so the dir isn't browsable on hosts that allow directory
	 * listings.
	 *
	 * Returns "" on a hard filesystem failure (uploads dir missing,
	 * mkdir denied) so callers can surface a clear error.
	 */
	public static function dir( string $job_id ): string {
		$job_id = self::sanitize_id( $job_id );
		if ( '' === $job_id ) {
			return '';
		}

		$upload  = wp_upload_dir();
		$basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		if ( '' === $basedir ) {
			return '';
		}

		$root = trailingslashit( $basedir ) . 'ehbp-jobs';
		if ( ! file_exists( $root ) ) {
			if ( ! wp_mkdir_p( $root ) ) {
				return '';
			}
			// Empty index.html keeps the dir un-listable.
			$index = $root . DIRECTORY_SEPARATOR . 'index.html';
			if ( ! file_exists( $index ) ) {
				@file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors
			}
		}

		$job_dir = $root . DIRECTORY_SEPARATOR . $job_id;
		if ( ! file_exists( $job_dir ) ) {
			if ( ! wp_mkdir_p( $job_dir ) ) {
				return '';
			}
		}

		return $job_dir;
	}

	/**
	 * Persist the parsed cities array to cities.json. Returns true on
	 * success.
	 */
	public static function write_cities( string $job_id, array $cities ): bool {
		$dir = self::dir( $job_id );
		if ( '' === $dir ) {
			return false;
		}
		$path  = $dir . DIRECTORY_SEPARATOR . 'cities.json';
		$bytes = @file_put_contents( $path, wp_json_encode( $cities ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors
		if ( false === $bytes ) {
			return false;
		}
		self::$cities_cache[ self::sanitize_id( $job_id ) ] = $cities;
		return true;
	}

	/**
	 * Read back the cities array, hitting the per-request cache when warm.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function read_cities( string $job_id ): array {
		$key = self::sanitize_id( $job_id );
		if ( '' === $key ) {
			return array();
		}
		if ( isset( self::$cities_cache[ $key ] ) ) {
			return self::$cities_cache[ $key ];
		}

		$dir = self::dir( $job_id );
		if ( '' === $dir ) {
			return array();
		}
		$path = $dir . DIRECTORY_SEPARATOR . 'cities.json';
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$raw  = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors
		$data = json_decode( $raw, true );
		$out  = is_array( $data ) ? $data : array();
		self::$cities_cache[ $key ] = $out;
		return $out;
	}

	/**
	 * Persist the city_slug => [neighborhoods] map to neighborhoods.json.
	 * Returns true on success.
	 */
	public static function write_neighborhoods( string $job_id, array $neighborhoods ): bool {
		$dir = self::dir( $job_id );
		if ( '' === $dir ) {
			return false;
		}
		$path  = $dir . DIRECTORY_SEPARATOR . 'neighborhoods.json';
		$bytes = @file_put_contents( $path, wp_json_encode( $neighborhoods ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors
		if ( false === $bytes ) {
			return false;
		}
		self::$neighborhoods_cache[ self::sanitize_id( $job_id ) ] = $neighborhoods;
		return true;
	}

	/**
	 * Read back the neighborhoods map, hitting the per-request cache.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function read_neighborhoods( string $job_id ): array {
		$key = self::sanitize_id( $job_id );
		if ( '' === $key ) {
			return array();
		}
		if ( isset( self::$neighborhoods_cache[ $key ] ) ) {
			return self::$neighborhoods_cache[ $key ];
		}

		$dir = self::dir( $job_id );
		if ( '' === $dir ) {
			return array();
		}
		$path = $dir . DIRECTORY_SEPARATOR . 'neighborhoods.json';
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$raw  = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors
		$data = json_decode( $raw, true );
		$out  = is_array( $data ) ? $data : array();
		self::$neighborhoods_cache[ $key ] = $out;
		return $out;
	}

	/**
	 * Wipe the on-disk files (and the per-request cache) for a job.
	 * Called when a job finishes or is cancelled.
	 */
	public static function delete( string $job_id ): void {
		$key = self::sanitize_id( $job_id );
		if ( '' === $key ) {
			return;
		}
		unset( self::$cities_cache[ $key ], self::$neighborhoods_cache[ $key ] );

		$dir = self::dir( $job_id );
		if ( '' === $dir ) {
			return;
		}
		foreach ( array( 'cities.json', 'neighborhoods.json' ) as $file ) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rmdir_rmdir
	}

	/**
	 * Restrict a job_id to characters that are safe for both wp_options
	 * meta keys and filesystem path segments. Job IDs we generate are
	 * already alnum + underscore, but defense in depth never hurts.
	 */
	private static function sanitize_id( string $job_id ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $job_id );
	}
}
