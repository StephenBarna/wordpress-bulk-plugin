<?php
/**
 * Resolves city/state placeholder tokens against a target post's
 * `_ehbp_city_*` post meta.
 *
 * Used in two places:
 *
 *   1. Patch_Engine — when the user copies a marked row from the Orlando
 *      template onto each cloned location page, the row's link/text fields
 *      may contain literal tokens like:
 *        /locations/{{city_slug_state}}/concrete-dumpster-rentals/
 *      …which we resolve against the TARGET page's meta so each cloned
 *      page's "Learn more" buttons point at its own service sub-pages.
 *
 *   2. Bulk_Job_Runner (optional) — title_fmt already supports {{city}} /
 *      {{state}} via Page_Cloner::format_title, but service-page jobs may
 *      want richer formats. This resolver gives the runner a consistent
 *      vocabulary if we extend that path later.
 *
 * Tokens (all case-sensitive):
 *
 *   {{city}}              → "Orlando"        (META_CITY_NAME)
 *   {{state}}             → "FL"             (META_STATE, normalized to abbr)
 *   {{city_slug}}         → "orlando"        (sanitize_title of city)
 *   {{city_slug_state}}   → "orlando-fl"     (slug + lowercased state abbr)
 *
 * The resolver is deliberately small and side-effect-free; callers pass in
 * either a post ID (resolver pulls meta) or an explicit context map (handy
 * for previews / dry-runs that don't have a real post yet).
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Token_Resolver {

	/**
	 * Per-request memo of resolved contexts keyed by post ID. Patching
	 * 80 pages × dozens of token-bearing strings each adds up; the meta
	 * lookups are cheap individually but free is better.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $context_cache = array();

	/**
	 * Build a context map for the given post by reading its city / state
	 * meta. Returns an associative array of all known tokens (without
	 * the surrounding `{{ }}`) and their resolved values. Empty string
	 * for any token whose underlying meta is missing.
	 *
	 * @return array<string, string>
	 */
	public static function context_for_post( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return self::empty_context();
		}
		if ( isset( self::$context_cache[ $post_id ] ) ) {
			return self::$context_cache[ $post_id ];
		}

		$city  = (string) get_post_meta( $post_id, Page_Cloner::META_CITY_NAME, true );
		$state = (string) get_post_meta( $post_id, Page_Cloner::META_STATE, true );

		// Normalize state to a 2-letter abbreviation so {{state}} reads
		// "FL" not "Florida". Callers can still pass an already-correct
		// 2-letter value and it'll round-trip.
		$state_abbr = self::normalize_state( $state );

		$slug         = '' !== $city ? sanitize_title( $city ) : '';
		$slug_state   = ( '' !== $slug && '' !== $state_abbr )
			? $slug . '-' . strtolower( $state_abbr )
			: $slug;

		$ctx = array(
			'city'            => $city,
			'state'           => $state_abbr,
			'city_slug'       => $slug,
			'city_slug_state' => $slug_state,
		);

		self::$context_cache[ $post_id ] = $ctx;
		return $ctx;
	}

	/**
	 * Resolve every `{{token}}` occurrence in $haystack against the given
	 * post's context. Unknown tokens (anything not in our vocabulary) are
	 * left untouched so existing curly-brace usage in user content
	 * doesn't get accidentally chewed up.
	 */
	public static function resolve( string $haystack, int $post_id ): string {
		if ( false === strpos( $haystack, '{{' ) ) {
			return $haystack;
		}
		return self::resolve_with_context( $haystack, self::context_for_post( $post_id ) );
	}

	/**
	 * Same as {@see resolve()} but with an explicit context map instead
	 * of pulling from post meta. Useful for previews / dry-runs.
	 *
	 * @param array<string, string> $context Token name => value (no braces).
	 */
	public static function resolve_with_context( string $haystack, array $context ): string {
		if ( false === strpos( $haystack, '{{' ) ) {
			return $haystack;
		}

		$pairs = array();
		foreach ( $context as $token => $value ) {
			$pairs[ '{{' . $token . '}}' ] = (string) $value;
		}
		return strtr( $haystack, $pairs );
	}

	/**
	 * Recursively walk an arbitrary value and resolve tokens in every
	 * string leaf. Mutates objects in place; for arrays the caller should
	 * reassign the returned value.
	 *
	 * Used by Patch_Engine to sweep every string property on a cloned
	 * BB node subtree without enumerating each settings key by hand.
	 *
	 * @param mixed $value Reference to the value to walk.
	 * @return int Count of leaf strings rewritten.
	 */
	public static function resolve_in_place( &$value, int $post_id ): int {
		$ctx = self::context_for_post( $post_id );
		return self::walk( $value, $ctx );
	}

	/**
	 * Internal recursive walker. Splits object/array handling because PHP
	 * objects are reference types but arrays are value types — for arrays
	 * we have to write back each element after recursing.
	 *
	 * @param mixed $value
	 * @param array<string, string> $ctx
	 */
	private static function walk( &$value, array $ctx ): int {
		$count = 0;

		if ( is_string( $value ) ) {
			if ( false !== strpos( $value, '{{' ) ) {
				$resolved = self::resolve_with_context( $value, $ctx );
				if ( $resolved !== $value ) {
					$value = $resolved;
					$count++;
				}
			}
			return $count;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $key => $sub ) {
				if ( is_string( $sub ) ) {
					if ( false !== strpos( $sub, '{{' ) ) {
						$resolved = self::resolve_with_context( $sub, $ctx );
						if ( $resolved !== $sub ) {
							$value->$key = $resolved;
							$count++;
						}
					}
				} elseif ( is_array( $sub ) ) {
					$count += self::walk( $sub, $ctx );
					$value->$key = $sub;
				} elseif ( is_object( $sub ) ) {
					$count += self::walk( $sub, $ctx );
				}
			}
			return $count;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => &$sub ) {
				if ( is_string( $sub ) ) {
					if ( false !== strpos( $sub, '{{' ) ) {
						$resolved = self::resolve_with_context( $sub, $ctx );
						if ( $resolved !== $sub ) {
							$sub = $resolved;
							$count++;
						}
					}
				} elseif ( is_array( $sub ) || is_object( $sub ) ) {
					$count += self::walk( $sub, $ctx );
				}
			}
			unset( $sub );
		}

		return $count;
	}

	/**
	 * Normalize a state string to its 2-letter U.S. abbreviation. Accepts
	 * either the already-correct "FL" form or a full name like "Florida".
	 * Returns "" for anything we can't recognize so the calling token
	 * substitutes to empty rather than the original noisy value.
	 */
	public static function normalize_state( string $raw ): string {
		$trimmed = trim( $raw );
		if ( '' === $trimmed ) {
			return '';
		}
		// Already a 2-letter code? Use it (uppercased).
		if ( strlen( $trimmed ) === 2 && ctype_alpha( $trimmed ) ) {
			return strtoupper( $trimmed );
		}
		$lower = strtolower( $trimmed );
		return self::STATE_NAME_TO_ABBR[ $lower ] ?? '';
	}

	/**
	 * Provide the same vocabulary an empty context would have, so callers
	 * doing token resolution against a post with no meta still get
	 * predictable substitutions (each unknown token resolves to "").
	 */
	private static function empty_context(): array {
		return array(
			'city'            => '',
			'state'           => '',
			'city_slug'       => '',
			'city_slug_state' => '',
		);
	}

	/**
	 * Mirror of the table in Neighborhoods_Applier — kept private here to
	 * avoid a cross-class dependency, since this resolver is also used in
	 * patch-only flows that don't otherwise touch neighborhoods code.
	 */
	private const STATE_NAME_TO_ABBR = array(
		'alabama'              => 'AL', 'alaska'         => 'AK', 'arizona'        => 'AZ',
		'arkansas'             => 'AR', 'california'     => 'CA', 'colorado'       => 'CO',
		'connecticut'          => 'CT', 'delaware'       => 'DE', 'florida'        => 'FL',
		'georgia'              => 'GA', 'hawaii'         => 'HI', 'idaho'          => 'ID',
		'illinois'             => 'IL', 'indiana'        => 'IN', 'iowa'           => 'IA',
		'kansas'               => 'KS', 'kentucky'       => 'KY', 'louisiana'      => 'LA',
		'maine'                => 'ME', 'maryland'       => 'MD', 'massachusetts'  => 'MA',
		'michigan'             => 'MI', 'minnesota'      => 'MN', 'mississippi'    => 'MS',
		'missouri'             => 'MO', 'montana'        => 'MT', 'nebraska'       => 'NE',
		'nevada'               => 'NV', 'new hampshire'  => 'NH', 'new jersey'     => 'NJ',
		'new mexico'           => 'NM', 'new york'       => 'NY', 'north carolina' => 'NC',
		'north dakota'         => 'ND', 'ohio'           => 'OH', 'oklahoma'       => 'OK',
		'oregon'               => 'OR', 'pennsylvania'   => 'PA', 'rhode island'   => 'RI',
		'south carolina'       => 'SC', 'south dakota'   => 'SD', 'tennessee'      => 'TN',
		'texas'                => 'TX', 'utah'           => 'UT', 'vermont'        => 'VT',
		'virginia'             => 'VA', 'washington'     => 'WA', 'west virginia'  => 'WV',
		'wisconsin'            => 'WI', 'wyoming'        => 'WY',
		'district of columbia' => 'DC',
	);
}
