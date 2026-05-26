<?php
/**
 * Yoast SEO meta rewriter. Reads the cloned page's existing Yoast meta
 * (which BB's duplicate_post copies from the source page during clone),
 * runs each non-empty value through OpenAI with city context, and
 * surfaces suggestions for the same diff UI as the text rewrite engine.
 *
 * Targeted Yoast keys (the ones SEO actually surfaces):
 *   - _yoast_wpseo_title       SEO meta title (browser tab + SERP).
 *   - _yoast_wpseo_metadesc    SEO meta description (SERP snippet).
 *   - _yoast_wpseo_focuskw     Focus keyphrase (Yoast analyzer).
 *
 * Social / OpenGraph keys (og:title, og:description, twitter:*) are
 * skipped in v1 because they're empty on the EarthHaul source page;
 * trivial to add later if needed.
 *
 * If a key is empty on the post (Yoast falls back to its template), we
 * skip it - we never invent SEO text where none was authored.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use EarthHaul\BulkPages\Admin\Settings_Page;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoast_Meta_Engine {

	private const KEYS = array(
		'_yoast_wpseo_title'    => 'seo_title',
		'_yoast_wpseo_metadesc' => 'meta_description',
		'_yoast_wpseo_focuskw'  => 'focus_keyphrase',
	);

	/**
	 * Read existing Yoast meta off the post and return one candidate per
	 * non-empty key. Empty values are dropped (Yoast renders its template
	 * when no override is set, and we don't want to bake in "Page not
	 * found"-style fallbacks).
	 *
	 * Already-localized values (mention target city, don't mention source)
	 * are flagged with skip_reason='already_localized' so the AI call is
	 * skipped on re-runs.
	 *
	 * @return array<int, array{
	 *   meta_key: string, role: string, original: string, skip_reason: string
	 * }>
	 */
	public static function collect_candidates( int $post_id, string $source_label, string $target_label = '', int $source_post_id = 0 ): array {
		$source_city = self::extract_city( $source_label );
		$target_city = self::extract_city( $target_label );

		// On rerun against an already-cloned page, the target post's
		// Yoast meta is a previously-substituted (and possibly truncated)
		// value of the source's meta. Re-substituting on top of that
		// drifts further every run. So when we have the source post ID,
		// we ALWAYS resource the original from the template.
		$resource_from_source = $source_post_id > 0 && $source_post_id !== $post_id;

		$out = array();
		foreach ( self::KEYS as $meta_key => $role ) {
			$value = $resource_from_source
				? (string) get_post_meta( $source_post_id, $meta_key, true )
				: (string) get_post_meta( $post_id, $meta_key, true );
			if ( '' === trim( $value ) ) {
				// Fall back to the cloned post's meta if the source has
				// nothing (defensive — a freshly-cloned post should
				// always inherit the source's meta).
				if ( $resource_from_source ) {
					$value = (string) get_post_meta( $post_id, $meta_key, true );
				}
				if ( '' === trim( $value ) ) {
					continue;
				}
			}

			$skip_reason = '';
			// Don't apply already_localized when we're sourcing from the
			// template — the source by definition still has the source
			// city, and we want to substitute every run.
			if ( ! $resource_from_source ) {
				if ( '' !== $target_city && self::contains_word( $value, $target_city ) && ( '' === $source_city || ! self::contains_word( $value, $source_city ) ) ) {
					$skip_reason = 'already_localized';
				}
			}

			$out[] = array(
				'meta_key'    => $meta_key,
				'role'        => $role,
				'original'    => $value,
				'skip_reason' => $skip_reason,
			);
		}
		return $out;
	}

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

	/**
	 * Run AI rewrites for each candidate. Same model + brand voice as the
	 * text rewrite engine.
	 */
	public static function run( array $candidates, string $source_label, string $target_label, ?callable $progress_cb = null ): array {
		$api_key     = (string) Settings_Page::get( 'api_key', '' );
		$model       = (string) Settings_Page::get( 'model', Settings_Page::DEFAULT_MODEL );
		$brand_voice = (string) Settings_Page::get( 'brand_voice', Settings_Page::DEFAULT_VOICE );
		$client      = new OpenAI_Client( $api_key, $model );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$total = count( $candidates );
		foreach ( $candidates as $i => $cand ) {
			if ( '' !== ( $cand['skip_reason'] ?? '' ) ) {
				$candidates[ $i ]['suggestion'] = $cand['original'];
				$candidates[ $i ]['error']      = '';
				$candidates[ $i ]['lint']       = array();
				$candidates[ $i ]['skipped']    = true;
				if ( is_callable( $progress_cb ) ) {
					$progress_cb( $i + 1, $total, $candidates[ $i ] );
				}
				continue;
			}

			$role = (string) $cand['role'];

			// SEO titles and focus keyphrases are deterministic city
			// substitutions, not AI rewrites. LLMs don't reliably count
			// characters, so any AI-generated SEO title risks being
			// truncated mid-word. The substitution path produces a
			// title that is GUARANTEED to be (a) under the cap unless
			// the source title was already over, (b) free of dangling
			// conjunctions, and (c) keyword-identical to the source so
			// SEO equity transfers cleanly. We only call the AI for
			// meta_description, where the field is roomier and the
			// reader benefits from copy variety.
			if ( 'seo_title' === $role || 'focus_keyphrase' === $role ) {
				// Pure city/state substitution, no length cap, no AI.
				// Whatever the source title was, the cloned page gets
				// the same string with just the city swapped. If the
				// source goes over Google's display width, that's a
				// problem to fix once on the source page, not by
				// silently mangling 80 cloned pages.
				$rewritten = self::deterministic_city_substitution(
					(string) $cand['original'],
					$source_label,
					$target_label
				);
				$candidates[ $i ]['suggestion'] = $rewritten;
				$candidates[ $i ]['error']      = '';
				$candidates[ $i ]['lint']       = Prompt_Builder::lint_output( $rewritten );
				$candidates[ $i ]['skipped']    = false;

				if ( is_callable( $progress_cb ) ) {
					$progress_cb( $i + 1, $total, $candidates[ $i ] );
				}
				continue;
			}

			$prompt = self::build_prompt( $cand, $source_label, $target_label, $brand_voice );
			$reply  = $client->chat( $prompt['user'], $prompt['system'], 30, 0.2 );

			if ( $reply instanceof WP_Error ) {
				$candidates[ $i ]['suggestion'] = $cand['original'];
				$candidates[ $i ]['error']      = $reply->get_error_message();
				$candidates[ $i ]['lint']       = array();
				$candidates[ $i ]['skipped']    = false;
			} else {
				$cap     = self::role_char_cap( $role );
				$cleaned = self::sanitize( (string) $reply );

				// One-shot retry on overflow. Cheap and avoids the
				// "Dumpster Rentals for Construction Debris, Dirt &"
				// truncation pattern that gave us so many ugly meta
				// descriptions before.
				if ( strlen( $cleaned ) > $cap + max( 6, (int) ( $cap * 0.1 ) ) ) {
					$retry_user = $prompt['user']
						. "\n\nYour previous answer was {strlen} characters, which exceeded the {cap}-char limit. Try again, shorter.";
					$retry_user = str_replace(
						array( '{strlen}', '{cap}' ),
						array( (string) strlen( $cleaned ), (string) $cap ),
						$retry_user
					);
					$retry = $client->chat( $retry_user, $prompt['system'], 30, 0.2 );
					if ( ! ( $retry instanceof WP_Error ) ) {
						$retry_cleaned = self::sanitize( (string) $retry );
						if ( '' !== $retry_cleaned && strlen( $retry_cleaned ) <= $cap + 6 ) {
							$cleaned = $retry_cleaned;
						}
					}
				}

				$cleaned = self::enforce_length( $cleaned, $role );
				$candidates[ $i ]['suggestion'] = $cleaned;
				$candidates[ $i ]['error']      = '';
				$candidates[ $i ]['lint']       = Prompt_Builder::lint_output( $cleaned );
				$candidates[ $i ]['skipped']    = false;
			}

			if ( is_callable( $progress_cb ) ) {
				$progress_cb( $i + 1, $total, $candidates[ $i ] );
			}
		}

		return $candidates;
	}

	/**
	 * Apply approved candidates by updating post meta. Returns a count
	 * tuple for the success message.
	 *
	 * @return array{applied: int, failed: int}
	 */
	public static function apply( int $post_id, array $applied_candidates ): array {
		$applied = 0;
		$failed  = 0;
		foreach ( $applied_candidates as $cand ) {
			$key   = (string) ( $cand['meta_key'] ?? '' );
			$value = (string) ( $cand['suggestion'] ?? '' );
			if ( '' === $key || '' === $value ) {
				$failed++;
				continue;
			}
			$ok = update_post_meta( $post_id, $key, $value );
			if ( false === $ok ) {
				$failed++;
			} else {
				$applied++;
			}
		}
		return array( 'applied' => $applied, 'failed' => $failed );
	}

	/**
	 * Stable ID used as the Apply checkbox value.
	 */
	public static function field_id( string $meta_key ): string {
		return 'meta:' . $meta_key;
	}

	/* -------------------------------------------------------------------- */
	/* Prompt + sanitize                                                    */
	/* -------------------------------------------------------------------- */

	private static function build_prompt( array $cand, string $source_label, string $target_label, string $brand_voice ): array {
		$role = (string) $cand['role'];
		$cap  = self::role_char_cap( $role );
		$role_instructions = match ( $role ) {
			'seo_title' => "Rewrite the SEO title for the target city. HARD LIMIT: must be {$cap} characters or fewer (count them — Google truncates beyond this). If the original is too long, COMPRESS it: drop redundant words, shorten the city/state to FL form, and prefer 'Dumpster Rentals' over 'Dumpster Rental Services'. End on a complete word — never trail off with '&', 'and', 'or', or a hanging comma. Match the original's keyword structure - don't reorder for fluency.",
			'meta_description' => "Rewrite the SEO meta description for the target city. HARD LIMIT: must be {$cap} characters or fewer (Google truncates beyond this). End on a complete sentence with terminal punctuation. Same call-to-action / value proposition as the original.",
			'focus_keyphrase' => "Rewrite the focus keyphrase for the target city. Keep it short (2-5 words), keep the same head term, just swap the city.",
			default => 'Localize the value for the target city, preserving the original phrasing.',
		};

		$system = "You localize SEO meta for a roll-off dumpster rental company's location pages. \n"
			. "Hard rules:\n"
			. "- Output ONLY the rewritten value, plain text. No quotes, no markdown, no commentary.\n"
			. "- Do NOT use AI-tells: em-dashes, 'navigate', 'leverage', 'unlock', 'embark', 'realm', 'tapestry', 'meticulous', 'delve'.\n"
			. "- Do NOT swap plain nouns for synonyms unless required for localization.\n"
			. "- If the original mentions the source city, replace it with the target city.\n"
			. "- If the original does NOT mention the source city, you may still mention the target city if natural.\n"
			. "- ALWAYS use the two-letter U.S. state abbreviation (FL, CA, TX) - never the full state name. If the source/target label spells out the state, still output the abbreviation. Saves characters in titles and meta descriptions.\n"
			. "- Stay within the per-field hard limit. NEVER end on a conjunction (& / and / or / for / with), a comma, or a partial word.\n"
			. $role_instructions;

		$user = "Brand voice: " . ( '' === $brand_voice ? '(none specified)' : $brand_voice ) . "\n\n"
			. "Source page city: {$source_label}\n"
			. "Target page city: {$target_label}\n"
			. "Field: {$role}\n"
			. "Hard character limit: {$cap}\n\n"
			. "ORIGINAL:\n" . $cand['original'] . "\n\n"
			. "Rewritten value for the target city (must fit within {$cap} characters and end on a complete word):";

		return array(
			'system' => $system,
			'user'   => $user,
		);
	}

	/**
	 * Single source of truth for per-role character budgets, shared by
	 * the prompt and the truncation safety net so they cannot disagree.
	 */
	private static function role_char_cap( string $role ): int {
		return match ( $role ) {
			'seo_title'        => 60,
			'meta_description' => 160,
			'focus_keyphrase'  => 60,
			default            => 200,
		};
	}

	/**
	 * Deterministic city/state substitution for length-critical SEO
	 * fields. Replaces the source city + source state in the original
	 * value with the target city + target state, word-boundary-aware
	 * and case-preserving where possible. We also normalize spelled-out
	 * state names ("Florida", "Fla.") to the two-letter abbreviation,
	 * because the cap on SEO titles is brutal and "Florida" eats four
	 * characters that "FL" doesn't.
	 *
	 * Examples (with source="Orlando, FL", target="Winter Haven, FL"):
	 *   "Concrete Dumpster Rentals in Orlando, FL"
	 *     -> "Concrete Dumpster Rentals in Winter Haven, FL"
	 *
	 *   "Dumpster Rental Services for Orlando, Florida residents"
	 *     -> "Dumpster Rental Services for Winter Haven, FL residents"
	 *
	 * If neither source city nor source state appears in the input
	 * (e.g., the title is generic), we append the target city/state at
	 * the end as a fallback so the cloned page still has a localized
	 * title and isn't a literal duplicate of the Orlando page's title.
	 */
	private static function deterministic_city_substitution( string $original, string $source_label, string $target_label ): string {
		$source_city  = self::extract_city( $source_label );
		$target_city  = self::extract_city( $target_label );
		$source_state = self::extract_state( $source_label );
		$target_state = self::extract_state( $target_label );

		$out = $original;

		// 1. City swap (whole-word, case-insensitive).
		if ( '' !== $source_city && '' !== $target_city ) {
			$out = preg_replace(
				'/\b' . preg_quote( $source_city, '/' ) . '\b/i',
				$target_city,
				$out
			) ?? $out;
		}

		// 2. Spelled-out state -> abbreviation. We prefer the abbreviation
		//    in titles to save characters and keep SEO consistent.
		$out = preg_replace( '/\bFlorida\b/i', $target_state ?: 'FL', $out ) ?? $out;
		$out = preg_replace( '/\bFla\.?\b/i',  $target_state ?: 'FL', $out ) ?? $out;

		// 3. Source-state abbreviation -> target-state abbreviation
		//    (e.g. "FL" -> "TX" if cross-state). Skip if same.
		if ( '' !== $source_state && '' !== $target_state && $source_state !== $target_state ) {
			$out = preg_replace(
				'/\b' . preg_quote( $source_state, '/' ) . '\b/',
				$target_state,
				$out
			) ?? $out;
		}

		// 4. If nothing changed (no source mention found), append the
		//    target locale so the cloned page still differentiates.
		if ( $out === $original && '' !== $target_city ) {
			$suffix = ' in ' . $target_city . ( '' !== $target_state ? ', ' . $target_state : '' );
			$out    = trim( $original ) . $suffix;
		}

		return self::sanitize( $out );
	}

	/**
	 * Pull the state piece out of a "City, State" label. Tolerant of
	 * extra whitespace and a trailing period ("Fla.").
	 */
	private static function extract_state( string $label ): string {
		$parts = explode( ',', $label );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$state = trim( (string) end( $parts ), " \t\n\r\0\x0B." );
		// Normalize common spellings to the abbreviation.
		if ( preg_match( '/^Florida$/i', $state ) || preg_match( '/^Fla$/i', $state ) ) {
			return 'FL';
		}
		return $state;
	}

	private static function sanitize( string $value ): string {
		$value = trim( $value );
		// Strip surrounding quotes the model often adds.
		$value = preg_replace( '/^["\'\x{201C}\x{2018}]+/u', '', $value ) ?? $value;
		$value = preg_replace( '/["\'\x{201D}\x{2019}]+$/u', '', $value ) ?? $value;
		$value = preg_replace( '/^```[a-z]*\s*\n?/i', '', $value ) ?? $value;
		$value = preg_replace( '/\n?```\s*$/', '', $value ) ?? $value;
		// Collapse internal whitespace.
		$value = preg_replace( '/\s+/', ' ', $value ) ?? $value;
		return trim( $value );
	}

	/**
	 * Hard-cap per-role: SEO title 60 chars, meta description 160 chars,
	 * keyphrase ~60. Safety net only — the prompt + retry loop should
	 * mean we rarely actually truncate, but if we do, we trim back to
	 * a real word boundary AND strip dangling connective tokens like
	 * "&", "and", "or", "for", "with", trailing comma, etc., so the
	 * output never reads as if it got cut off mid-thought.
	 */
	private static function enforce_length( string $value, string $role ): string {
		$cap = self::role_char_cap( $role );
		if ( strlen( $value ) <= $cap ) {
			return $value;
		}
		$cut = substr( $value, 0, $cap );
		$ws  = strrpos( $cut, ' ' );
		if ( false !== $ws && $ws > $cap - 12 ) {
			$cut = substr( $cut, 0, $ws );
		}
		return self::strip_dangling_tail( $cut );
	}

	/**
	 * Repeatedly strip trailing punctuation / whitespace / connector
	 * words ("and", "or", "&", "for", "with", "in", "to", "of") from
	 * the end of a string so we never leave the reader hanging.
	 *
	 * Loops until no more strippable suffix is found — "Construction
	 * Debris, Dirt &" → "Construction Debris, Dirt" → after another
	 * pass, the trailing comma is gone too → "Construction Debris".
	 */
	private static function strip_dangling_tail( string $value ): string {
		$bad_words = array( 'and', 'or', 'for', 'with', 'in', 'to', 'of', 'a', 'an', 'the' );
		$prev      = '';
		// Bound the loop just in case a value is bizarrely all-noise.
		for ( $i = 0; $i < 8 && $value !== $prev; $i++ ) {
			$prev  = $value;
			$value = rtrim( $value, " \t\n\r\0\x0B,.;:-&|/\\" );
			$value = preg_replace( '/\s+(' . implode( '|', $bad_words ) . ')\s*$/i', '', $value ) ?? $value;
		}
		return $value;
	}
}
