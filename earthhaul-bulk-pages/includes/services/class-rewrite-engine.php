<?php
/**
 * Rewrite Engine: turns a Layout_Walker inventory into a list of
 * AI-generated suggestions, ready for review in the diff UI.
 *
 * Scope decisions for v1 (Phase 1b.ii):
 *   - Only rewrites text/html fields. Images and URLs are surfaced but
 *     not modified here (image renaming is Phase 1c, URLs we leave alone).
 *   - One OpenAI call per field. Sequential. Wrapped in set_time_limit(0).
 *     If a single page has so many fields that this times out we'll add
 *     batching, but a typical EarthHaul location page is ~140 fields.
 *   - Skips obviously-static short strings (no city ref, <= 4 words) to
 *     avoid burning tokens on "Submit" and "Get a quote".
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use EarthHaul\BulkPages\Admin\Settings_Page;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rewrite_Engine {

	/**
	 * CSS classes that mark a module as "managed" by another part of this
	 * plugin (e.g. the Neighborhoods admin screen rebuilds those modules
	 * from CSV data). The AI rewrite path must skip these so we don't
	 *   - burn API tokens on content that's about to be overwritten, and
	 *   - have the model hallucinate fake place names into a list that
	 *     should be authoritative CSV-driven data.
	 *
	 * Order in which Rewrite vs Neighborhoods is run no longer matters
	 * because of this skip.
	 */
	private const MANAGED_CLASSES = array( 'ehbp-neighborhoods' );

	/**
	 * Build the list of candidate fields from a walker inventory.
	 *
	 * Each candidate has enough information to (a) preview to the user,
	 * (b) call OpenAI, and (c) write back to the BB layout later.
	 *
	 * @param array  $walker_output Output of Layout_Walker::inspect().
	 * @param string $source_label  e.g. "Orlando, FL".
	 * @param string $target_label  e.g. "Davenport, FL". When provided,
	 *                              fields that already mention the target
	 *                              city (and don't mention the source) are
	 *                              skipped as "already localized" - this
	 *                              avoids burning tokens / risking drift on
	 *                              re-runs of pages that have already been
	 *                              processed.
	 * @return array<int, array<string, mixed>>
	 */
	public static function collect_candidates( array $walker_output, string $source_label, string $target_label = '' ): array {
		$out = array();
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
			foreach ( $node['fields'] ?? array() as $field ) {
				$kind = (string) ( $field['kind'] ?? '' );
				if ( 'text' !== $kind && 'html' !== $kind ) {
					continue;
				}

				$value = (string) ( $field['value'] ?? '' );
				if ( '' === trim( $value ) ) {
					continue;
				}

				$role          = Prompt_Builder::derive_role( (string) $node['module_slug'], (string) $field['path'], $kind );
				$skip_reason   = self::should_skip( $value, $source_label, $target_label );

				$out[] = array(
					'node_id'     => (string) $node['node_id'],
					'module_slug' => (string) $node['module_slug'],
					'row_id'      => (string) ( $node['row_id'] ?? '' ),
					'label'       => (string) ( $node['label'] ?? '' ),
					'path'        => (string) $field['path'],
					'kind'        => $kind,
					'role'        => $role,
					'original'    => $value,
					'skip_reason' => $skip_reason,
				);
			}
		}
		return $out;
	}

	/**
	 * Determine whether we can confidently skip the API call for this
	 * field. Two skip categories:
	 *
	 *   - 'short_no_locale': short template UI like "Get a quote", which
	 *     never benefits from rewriting.
	 *
	 *   - 'already_localized': field mentions the target city and does
	 *     NOT mention the source city. Either the page was processed
	 *     previously and we'd just spin tokens to confirm "yep, still
	 *     Davenport", or the field happened to start out target-specific.
	 *     Either way: no API call needed.
	 */
	private static function should_skip( string $original, string $source_label, string $target_label = '' ): string {
		$plain = trim( wp_strip_all_tags( $original ) );
		if ( '' === $plain ) {
			return 'empty';
		}

		$word_count = str_word_count( $plain );

		$source_city = self::extract_city( $source_label );
		$target_city = self::extract_city( $target_label );
		$has_source  = '' !== $source_city && self::contains_word( $plain, $source_city );
		$has_target  = '' !== $target_city && self::contains_word( $plain, $target_city );
		$has_state   = self::contains_state_token( $plain, $source_label );

		if ( $has_target && ! $has_source ) {
			return 'already_localized';
		}

		// Short strings without any city/state token are template UI like "Get a quote".
		if ( $word_count <= 4 && ! $has_source && ! $has_state ) {
			return 'short_no_locale';
		}

		return '';
	}

	/**
	 * Whole-word case-insensitive containment test. Avoids false positives
	 * like "Davenport" matching inside "Davenports" or partial matches.
	 */
	private static function contains_word( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return false;
		}
		return (bool) preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/i', $haystack );
	}

	/**
	 * Run the engine: call OpenAI for every non-skipped candidate, return
	 * the same array with `suggestion`, `lint`, and `error` keys filled.
	 *
	 * @param array  $candidates    From collect_candidates().
	 * @param string $source_label  e.g. "Orlando, FL".
	 * @param string $target_label  e.g. "Tampa, FL".
	 * @param callable|null $progress_cb  Optional callback (int $i, int $n, array $candidate).
	 * @return array<int, array<string, mixed>>
	 */
	public static function run( array $candidates, string $source_label, string $target_label, ?callable $progress_cb = null ): array {
		$api_key     = (string) Settings_Page::get( 'api_key', '' );
		$model       = (string) Settings_Page::get( 'model', Settings_Page::DEFAULT_MODEL );
		$brand_voice = (string) Settings_Page::get( 'brand_voice', Settings_Page::DEFAULT_VOICE );
		$client      = new OpenAI_Client( $api_key, $model );

		// Long-running by design; we are doing one HTTP roundtrip per field.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$total = count( $candidates );
		foreach ( $candidates as $i => $candidate ) {
			if ( '' !== ( $candidate['skip_reason'] ?? '' ) ) {
				$candidates[ $i ]['suggestion'] = $candidate['original'];
				$candidates[ $i ]['lint']       = array();
				$candidates[ $i ]['error']      = '';
				$candidates[ $i ]['skipped']    = true;
				if ( is_callable( $progress_cb ) ) {
					$progress_cb( $i + 1, $total, $candidates[ $i ] );
				}
				continue;
			}

			$messages = Prompt_Builder::build(
				array(
					'brand_voice'  => $brand_voice,
					'role'         => $candidate['role'],
					'source_label' => $source_label,
					'target_label' => $target_label,
					'original'     => $candidate['original'],
				)
			);

			$response = $client->chat( $messages['user'], $messages['system'], 30 );

			if ( $response instanceof WP_Error ) {
				$candidates[ $i ]['suggestion'] = '';
				$candidates[ $i ]['error']      = $response->get_error_message();
				$candidates[ $i ]['lint']       = array();
				$candidates[ $i ]['skipped']    = false;
			} else {
				$cleaned                         = self::sanitize_response( (string) $response );
				$candidates[ $i ]['suggestion'] = $cleaned;
				$candidates[ $i ]['lint']       = Prompt_Builder::lint_output( $cleaned );
				$candidates[ $i ]['error']      = '';
				$candidates[ $i ]['skipped']    = false;
			}

			if ( is_callable( $progress_cb ) ) {
				$progress_cb( $i + 1, $total, $candidates[ $i ] );
			}
		}

		return $candidates;
	}

	/**
	 * The model occasionally wraps output in quotes or markdown despite
	 * being told not to. Strip the most common offenders.
	 */
	private static function sanitize_response( string $value ): string {
		$value = trim( $value );

		// Strip surrounding markdown code fences.
		$value = preg_replace( '/^```[a-z]*\s*\n?/i', '', $value );
		$value = preg_replace( '/\n?```\s*$/', '', (string) $value );

		// Strip a single pair of surrounding straight or curly quotes.
		$pairs = array(
			array( '"', '"' ),
			array( "'", "'" ),
			array( "\u{201C}", "\u{201D}" ),
			array( "\u{2018}", "\u{2019}" ),
		);
		foreach ( $pairs as [ $open, $close ] ) {
			if ( str_starts_with( (string) $value, $open ) && str_ends_with( (string) $value, $close ) && strlen( (string) $value ) > strlen( $open ) + strlen( $close ) ) {
				$value = substr( (string) $value, strlen( $open ), -strlen( $close ) );
				break;
			}
		}

		return trim( (string) $value );
	}

	/**
	 * Pull the city portion out of a "City, ST" label. Defensive against
	 * inputs like "Orlando" or "Orlando, Florida".
	 */
	private static function extract_city( string $label ): string {
		$parts = explode( ',', $label );
		return trim( $parts[0] ?? '' );
	}

	/**
	 * True if the module's CSS class string contains any of our managed
	 * classes as a whole token (not as a substring).
	 */
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

	/**
	 * True if the haystack looks like it contains the source state -
	 * either as the abbreviation (FL) or the full name (Florida).
	 */
	private static function contains_state_token( string $haystack, string $source_label ): bool {
		$parts = explode( ',', $source_label );
		$state = isset( $parts[1] ) ? trim( $parts[1] ) : '';
		if ( '' === $state ) {
			return false;
		}
		// Match word-boundary so we don't false-positive on substrings like
		// "FLAT" containing "FL".
		return (bool) preg_match( '/\b' . preg_quote( $state, '/' ) . '\b/i', $haystack );
	}
}
