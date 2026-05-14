<?php
/**
 * Parses uploaded city CSVs into structured row arrays.
 *
 * Expected columns: slug, city, state. Extra columns are passed through
 * as a 'meta' array, useful when the same CSV is re-used in service-location
 * jobs where each row also carries a service_slug.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CSV_Importer {

	public const REQUIRED_COLUMNS = array( 'slug', 'city', 'state' );

	/**
	 * Parse a CSV file path into validated rows.
	 *
	 * @param string $file_path Absolute path to the CSV file on disk.
	 * @return array{rows: array<int, array<string, string>>, headers: array<int, string>}|WP_Error
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
						__( 'CSV is missing required column(s): %s', 'earthhaul-bulk-pages' ),
						implode( ', ', $missing )
					)
				);
			}

			$rows         = array();
			$row_index    = 1;
			$seen_slugs   = array();
			$row_errors   = array();

			while ( false !== ( $values = fgetcsv( $handle ) ) ) {
				$row_index++;

				if ( count( $values ) === 1 && ( null === $values[0] || '' === trim( (string) $values[0] ) ) ) {
					continue;
				}

				$row = array();
				foreach ( $headers as $i => $header ) {
					$row[ $header ] = isset( $values[ $i ] ) ? trim( (string) $values[ $i ] ) : '';
				}

				$slug = sanitize_title( $row['slug'] ?? '' );
				$city = $row['city'] ?? '';

				if ( '' === $slug || '' === $city ) {
					$row_errors[] = sprintf(
						/* translators: %d: row number */
						__( 'Row %d: slug and city are required.', 'earthhaul-bulk-pages' ),
						$row_index
					);
					continue;
				}

				if ( isset( $seen_slugs[ $slug ] ) ) {
					$row_errors[] = sprintf(
						/* translators: 1: row number, 2: slug */
						__( 'Row %1$d: duplicate slug "%2$s".', 'earthhaul-bulk-pages' ),
						$row_index,
						$slug
					);
					continue;
				}
				$seen_slugs[ $slug ] = true;

				$row['slug'] = $slug;
				$rows[]      = $row;
			}

			if ( ! empty( $row_errors ) ) {
				return new WP_Error( 'ehbp_csv_row_errors', implode( ' ', $row_errors ) );
			}

			if ( empty( $rows ) ) {
				return new WP_Error( 'ehbp_csv_no_rows', __( 'CSV had headers but no valid data rows.', 'earthhaul-bulk-pages' ) );
			}

			return array(
				'rows'    => $rows,
				'headers' => $headers,
			);
		} finally {
			fclose( $handle );
		}
	}
}
