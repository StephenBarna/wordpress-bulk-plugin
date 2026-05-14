<?php
/**
 * Parses uploaded neighborhoods CSVs into a city_slug -> [neighborhood, ...]
 * map. The expected long-format ("one neighborhood per row") is the format
 * AI models output most reliably without escaping mistakes.
 *
 * Expected columns: city_slug, neighborhood. Extra columns are ignored.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Neighborhoods_CSV_Importer {

	public const REQUIRED_COLUMNS = array( 'city_slug', 'neighborhood' );

	/**
	 * Parse a CSV file path into a slug -> list-of-neighborhoods map.
	 *
	 * @param string $file_path Absolute path to the CSV file on disk.
	 * @return array{groups: array<string, array<int, string>>, total: int}|WP_Error
	 */
	public static function parse_file( string $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'ehbp_csv_unreadable', __( 'CSV file not found or unreadable.', 'earthhaul-bulk-pages' ) );
		}

		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			return new WP_Error( 'ehbp_csv_open', __( 'Could not open CSV file.', 'earthhaul-bulk-pages' ) );
		}

		try {
			$headers_raw = fgetcsv( $handle );
			if ( false === $headers_raw ) {
				return new WP_Error( 'ehbp_csv_empty', __( 'CSV is empty.', 'earthhaul-bulk-pages' ) );
			}

			$headers = array_map(
				static fn( $h ) => strtolower( trim( (string) $h ) ),
				$headers_raw
			);

			$missing = array_diff( self::REQUIRED_COLUMNS, $headers );
			if ( ! empty( $missing ) ) {
				return new WP_Error(
					'ehbp_csv_missing_columns',
					sprintf(
						/* translators: %s: comma-separated list of missing column names */
						__( 'Neighborhoods CSV is missing required column(s): %s. Expected: city_slug, neighborhood.', 'earthhaul-bulk-pages' ),
						implode( ', ', $missing )
					)
				);
			}

			$slug_idx         = array_search( 'city_slug', $headers, true );
			$neighborhood_idx = array_search( 'neighborhood', $headers, true );

			$groups        = array();
			$total         = 0;
			$row_index     = 1;
			$row_errors    = array();
			$seen_per_city = array();

			while ( false !== ( $values = fgetcsv( $handle ) ) ) {
				$row_index++;

				if ( count( $values ) === 1 && ( null === $values[0] || '' === trim( (string) $values[0] ) ) ) {
					continue;
				}

				$slug         = sanitize_title( (string) ( $values[ $slug_idx ] ?? '' ) );
				$neighborhood = trim( (string) ( $values[ $neighborhood_idx ] ?? '' ) );

				if ( '' === $slug || '' === $neighborhood ) {
					$row_errors[] = sprintf(
						/* translators: %d: row number */
						__( 'Row %d: city_slug and neighborhood are required.', 'earthhaul-bulk-pages' ),
						$row_index
					);
					continue;
				}

				$dedupe_key = strtolower( $neighborhood );
				if ( isset( $seen_per_city[ $slug ][ $dedupe_key ] ) ) {
					continue;
				}

				if ( ! isset( $groups[ $slug ] ) ) {
					$groups[ $slug ] = array();
					$seen_per_city[ $slug ] = array();
				}
				$groups[ $slug ][] = $neighborhood;
				$seen_per_city[ $slug ][ $dedupe_key ] = true;
				$total++;
			}

			if ( ! empty( $row_errors ) ) {
				return new WP_Error( 'ehbp_csv_row_errors', implode( ' ', $row_errors ) );
			}

			if ( empty( $groups ) ) {
				return new WP_Error( 'ehbp_csv_no_rows', __( 'CSV had headers but no valid data rows.', 'earthhaul-bulk-pages' ) );
			}

			return array(
				'groups' => $groups,
				'total'  => $total,
			);
		} finally {
			fclose( $handle );
		}
	}
}
