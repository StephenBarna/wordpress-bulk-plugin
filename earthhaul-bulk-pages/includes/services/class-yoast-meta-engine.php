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
	public static function collect_candidates( int $post_id, string $source_label, string $target_label = '' ): array {
		$source_city = self::extract_city( $source_label );
		$target_city = self::extract_city( $target_label );

		$out = array();
		foreach ( self::KEYS as $meta_key => $role ) {
			$value = (string) get_post_meta( $post_id, $meta_key, true );
			if ( '' === trim( $value ) ) {
				continue;
			}

			$skip_reason = '';
			if ( '' !== $target_city && self::contains_word( $value, $target_city ) && ( '' === $source_city || ! self::contains_word( $value, $source_city ) ) ) {
				$skip_reason = 'already_localized';
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

			$prompt = self::build_prompt( $cand, $source_label, $target_label, $brand_voice );
			$reply  = $client->chat( $prompt['user'], $prompt['system'], 30, 0.2 );

			if ( $reply instanceof WP_Error ) {
				$candidates[ $i ]['suggestion'] = $cand['original'];
				$candidates[ $i ]['error']      = $reply->get_error_message();
				$candidates[ $i ]['lint']       = array();
				$candidates[ $i ]['skipped']    = false;
			} else {
				$cleaned = self::sanitize( (string) $reply );
				$cleaned = self::enforce_length( $cleaned, (string) $cand['role'] );
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
		$role_instructions = match ( $role ) {
			'seo_title' => "Rewrite the SEO title for the target city. Keep it concise (50-60 chars). Match the original's keyword structure - don't reorder for fluency.",
			'meta_description' => "Rewrite the SEO meta description for the target city. Keep it 140-160 characters. Same call-to-action / value proposition as the original.",
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
			. "- Keep the original word count similar (within +/- 20%).\n"
			. $role_instructions;

		$user = "Brand voice: " . ( '' === $brand_voice ? '(none specified)' : $brand_voice ) . "\n\n"
			. "Source page city: {$source_label}\n"
			. "Target page city: {$target_label}\n"
			. "Field: {$role}\n\n"
			. "ORIGINAL:\n" . $cand['original'] . "\n\n"
			. "Rewritten value for the target city:";

		return array(
			'system' => $system,
			'user'   => $user,
		);
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
	 * keyphrase ~60. We trim to whitespace boundaries to avoid truncating
	 * mid-word.
	 */
	private static function enforce_length( string $value, string $role ): string {
		$cap = match ( $role ) {
			'seo_title'        => 60,
			'meta_description' => 160,
			'focus_keyphrase'  => 60,
			default            => 200,
		};
		if ( strlen( $value ) <= $cap ) {
			return $value;
		}
		$cut = substr( $value, 0, $cap );
		$ws  = strrpos( $cut, ' ' );
		if ( false !== $ws && $ws > $cap - 12 ) {
			$cut = substr( $cut, 0, $ws );
		}
		return rtrim( $cut, " ,.;:-" );
	}
}
