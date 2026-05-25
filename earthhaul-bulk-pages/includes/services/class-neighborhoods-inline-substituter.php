<?php
/**
 * Inline-list neighborhood substitution for Beaver Builder rich-text content.
 *
 * The {@see Neighborhoods_Applier} service handles structured modules tagged
 * with the `ehbp-neighborhoods` CSS class (e.g. the bottom-of-page <ul><li>
 * grid). This service handles the OTHER common case: short inline lists
 * embedded mid-sentence in a rich-text/HTML module, like:
 *
 *   EarthHaul offers dumpster rentals throughout <strong>Orlando, FL</strong>,
 *   including: Lake Nona, Downtown Orlando, ... &amp; more!
 *
 * Marking the list with a CSS class doesn't help here because the
 * surrounding sentence is in the same module, so the applier can't tell
 * the prefix from the list portion. Instead we use HTML comment markers
 * the user adds once on the source template:
 *
 *   ... including: <!--ehbp-neighborhoods:12-->Lake Nona, ...,
 *   Windermere &amp; more!<!--/ehbp-neighborhoods-->
 *
 * Comments are invisible to browsers, so the source page renders unchanged.
 * The cloning pipeline copies the comments forward into the cloned draft;
 * this service then walks every text-bearing field on the cloned page,
 * finds the marker pairs, and replaces the wrapped portion with the
 * cloned city's neighborhoods.
 *
 * Design notes:
 *   - We preserve the wrapping comments so re-runs of the job stay
 *     idempotent (the regex still matches the second time around).
 *   - We walk every string property recursively under each node's
 *     `settings`, not just well-known content keys, so comments dropped
 *     into FAQ answers, list-icon item titles, button-group items,
 *     icon-text, etc. all work.
 *   - We short-circuit on `strpos` before running the regex so the cost
 *     for the typical case (no marker in this string) is one substring
 *     scan and nothing else.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Neighborhoods_Inline_Substituter {

	/**
	 * Match an `<!--ehbp-neighborhoods-->` ... `<!--/ehbp-neighborhoods-->`
	 * pair, with an optional `:N` count limit on the opener:
	 *
	 *   <!--ehbp-neighborhoods-->        all neighborhoods, no "& more!"
	 *   <!--ehbp-neighborhoods:12-->     first 12 + "& more!" if list longer
	 *
	 * The opener and closer are kept in the rewritten output so subsequent
	 * runs can find and re-substitute the same span.
	 *
	 * Flags: i (case-insensitive), s (dot matches newlines), u (UTF-8 safe).
	 */
	private const MARKER_REGEX =
		'/<!--\s*ehbp-neighborhoods(?::(\d+))?\s*-->(.*?)<!--\s*\/\s*ehbp-neighborhoods\s*-->/isu';

	/**
	 * Walk every string-valued setting on every node in the layout and
	 * substitute neighborhood lists wherever the marker pair appears.
	 * Mutates $layout in place. Returns the count of fields changed
	 * (one field can contain multiple markers; each rewritten field
	 * counts once).
	 *
	 * @param array         $layout         BB layout, node_id => stdClass node.
	 * @param array<string> $neighborhoods  Plain text names for the cloned city.
	 */
	public static function apply_to_layout( array &$layout, array $neighborhoods ): int {
		$neighborhoods = self::normalize_list( $neighborhoods );
		if ( empty( $neighborhoods ) ) {
			return 0;
		}

		$changed = 0;
		foreach ( $layout as $node ) {
			if ( ! is_object( $node ) || empty( $node->settings ) ) {
				continue;
			}
			$changed += self::rewrite_in_place( $node->settings, $neighborhoods );
		}
		return $changed;
	}

	/**
	 * Recursive walker. Inspects every string property/element on the
	 * given object/array; if it contains the marker token, we run the
	 * regex rewriter and write the result back. Objects in PHP are
	 * reference types so writes via `$obj->$key = ...` propagate; arrays
	 * are value types so the caller (or this function) must reassign
	 * after recursing.
	 *
	 * @param object|array $node Reference into a settings tree.
	 */
	private static function rewrite_in_place( &$node, array $neighborhoods ): int {
		$count = 0;

		if ( is_object( $node ) ) {
			foreach ( get_object_vars( $node ) as $key => $value ) {
				if ( is_string( $value ) ) {
					$new = self::rewrite_string( $value, $neighborhoods );
					if ( $new !== $value ) {
						$node->$key = $new;
						$count++;
					}
				} elseif ( is_array( $value ) ) {
					$count += self::rewrite_in_place( $value, $neighborhoods );
					$node->$key = $value;
				} elseif ( is_object( $value ) ) {
					$count += self::rewrite_in_place( $value, $neighborhoods );
				}
			}
			return $count;
		}

		if ( is_array( $node ) ) {
			foreach ( $node as $key => &$value ) {
				if ( is_string( $value ) ) {
					$new = self::rewrite_string( $value, $neighborhoods );
					if ( $new !== $value ) {
						$value = $new;
						$count++;
					}
				} elseif ( is_array( $value ) || is_object( $value ) ) {
					$count += self::rewrite_in_place( $value, $neighborhoods );
				}
			}
			unset( $value );
		}

		return $count;
	}

	/**
	 * Replace every `<!--ehbp-neighborhoods[:N]-->...<!--/ehbp-neighborhoods-->`
	 * span in $html with the rendered neighborhoods list. Preserves the
	 * surrounding comments so subsequent runs match again.
	 *
	 * The output uses `, ` as a separator, the literal `&amp;` entity for
	 * "and", and an Oxford-style `&amp; more!` suffix when the limit is
	 * lower than the list length (so the page reads naturally).
	 */
	private static function rewrite_string( string $html, array $neighborhoods ): string {
		// Cheap pre-check: only run regex if the marker token is present.
		if ( false === stripos( $html, '<!--ehbp-neighborhoods' )
			&& false === stripos( $html, '<!-- ehbp-neighborhoods' ) ) {
			return $html;
		}

		$result = preg_replace_callback(
			self::MARKER_REGEX,
			static function ( array $m ) use ( $neighborhoods ): string {
				$limit_raw = isset( $m[1] ) ? (string) $m[1] : '';
				$limit     = '' !== $limit_raw ? (int) $limit_raw : 0;
				$total     = count( $neighborhoods );
				$items     = ( $limit > 0 && $limit < $total )
					? array_slice( $neighborhoods, 0, $limit )
					: $neighborhoods;

				$encoded = array_map(
					static fn ( string $name ): string => esc_html( $name ),
					$items
				);
				$body = implode( ', ', $encoded );
				if ( $limit > 0 && $limit < $total ) {
					$body .= ' &amp; more!';
				}

				$opener = '' !== $limit_raw
					? '<!--ehbp-neighborhoods:' . (int) $limit_raw . '-->'
					: '<!--ehbp-neighborhoods-->';
				$closer = '<!--/ehbp-neighborhoods-->';
				return $opener . $body . $closer;
			},
			$html
		);

		return null === $result ? $html : (string) $result;
	}

	/**
	 * Trim, drop empties, and de-dupe (case-insensitive) the incoming
	 * neighborhood list. Same shape Neighborhoods_Applier uses, kept
	 * private here to avoid a cross-class call.
	 */
	private static function normalize_list( array $names ): array {
		$out  = array();
		$seen = array();
		foreach ( $names as $n ) {
			$n = trim( (string) $n );
			if ( '' === $n ) {
				continue;
			}
			$k = strtolower( $n );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = $n;
		}
		return $out;
	}
}
