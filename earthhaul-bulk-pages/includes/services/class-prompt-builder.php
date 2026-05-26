<?php
/**
 * Composes the OpenAI prompts used by Rewrite_Engine.
 *
 * Design philosophy: this is REWRITING, not generation. Every prompt the
 * model sees has to make it crystal clear that the model's job is:
 *
 *   1. Take the original copy as ground truth.
 *   2. Reword it so it's fresh and not duplicate content.
 *   3. Substitute any source-city / source-state references with the
 *      target city / state.
 *   4. Preserve HTML structure, length, list/paragraph counts.
 *   5. Avoid AI-tell phrases (em-dashes, "delve", "tapestry", etc).
 *   6. NEVER invent specifics that aren't in the original (zip codes,
 *      phone numbers, neighborhood names, prices, license numbers).
 *
 * Per-field-kind instructions narrow the rules further (e.g. button
 * labels vs body paragraphs vs FAQ answers all want different shapes).
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Prompt_Builder {

	/**
	 * Map walker (module_slug, path, kind) -> a "field role" key into
	 * FIELD_INSTRUCTIONS. Different roles get different per-field guidance.
	 */
	public static function derive_role( string $module_slug, string $path, string $kind ): string {
		if ( 'image_meta' === $kind ) {
			return 'image_meta';
		}
		if ( 'image_id' === $kind || 'image_url' === $kind ) {
			return 'image_url';
		}
		if ( 'url' === $kind ) {
			return 'url';
		}

		// Heading module: always treat the heading field as a heading.
		if ( 'heading' === $module_slug && 'heading' === $path ) {
			return 'heading';
		}

		// Buttons - top-level button module and button-group items both have
		// short action-oriented labels.
		if ( 'button' === $module_slug && 'text' === $path ) {
			return 'button';
		}
		if ( 'button-group' === $module_slug && self::ends_with( $path, '.text' ) ) {
			return 'button';
		}

		// List item titles (list-icon module).
		if ( 'list-icon' === $module_slug && self::ends_with( $path, '.title' ) ) {
			return 'list_item';
		}

		// FAQ items.
		if ( 'uabb-faq' === $module_slug ) {
			if ( self::ends_with( $path, '.faq_question' ) ) {
				return 'faq_question';
			}
			if ( self::ends_with( $path, '.faq_answer' ) ) {
				return 'faq_answer';
			}
		}

		// Icon module's "text" field is the small description below an icon.
		if ( 'icon' === $module_slug && 'text' === $path ) {
			return 'icon_text';
		}

		// Photo caption.
		if ( 'photo' === $module_slug && 'caption' === $path ) {
			return 'caption';
		}

		// Default: rich body copy if the field looks like html, generic text otherwise.
		if ( 'rich-text' === $module_slug ) {
			return 'rich_text';
		}
		if ( 'html' === $module_slug ) {
			return 'html_block';
		}

		return 'html' === $kind ? 'rich_text' : 'plain_text';
	}

	/**
	 * Per-field-kind constraints. Layered on top of SYSTEM_PROMPT.
	 *
	 * Updated for paraphrase mode: the per-field instructions now ask
	 * the model to vary sentence structure and word choice while keeping
	 * facts, claim count, length, and HTML structure intact. The goal is
	 * unique-feeling copy on every cloned city page, not a thesaurus pass.
	 */
	public const FIELD_INSTRUCTIONS = array(
		'heading'      => 'Field type: page HEADING. Keep it short (6-12 words). Preserve any HTML tags (e.g. <span class="highlight">) exactly. You may reword for variety as long as the topic and key noun(s) are unchanged. Replace source city / state references with the target. Do not add new claims.',
		'rich_text'    => 'Field type: BODY PARAGRAPH copy. Preserve HTML tags exactly. Match length within ±20%. PARAPHRASE: rewrite the sentence so the cloned page does not read as a duplicate of the source page. Vary sentence openers, restructure clauses, change connective tissue. The MEANING, FACTS, and CLAIM COUNT stay identical; the WORDING changes. Replace source city / state with the target. Never invent new facts or services.',
		'html_block'   => 'Field type: HTML BLOCK. Preserve all tags, classes, attributes, and IDs EXACTLY. Only modify inner text content. You may paraphrase the inner text for variety. Replace source city / state. Do not add or remove any HTML structure.',
		'button'       => 'Field type: BUTTON LABEL. Action-oriented (verb-first). Maximum 4 words. Do NOT include the city name. Keep the same call to action - if the original is "Get a Free Quote", do NOT rewrite as "Request a Quote". Buttons are NOT paraphrased.',
		'icon_text'    => 'Field type: SHORT TEXT BELOW AN ICON. Single sentence, casual tone. You may rephrase for variety. If the original mentions a city, swap to the target city.',
		'faq_question' => 'Field type: FAQ QUESTION. Replace source city / state references with the target. Keep the question intent identical but you may rephrase the question for variety (e.g. "How long can I keep a dumpster?" -> "How many days does the rental last?"). One sentence. End with a question mark.',
		'faq_answer'   => 'Field type: FAQ ANSWER. Preserve HTML tags exactly. 1-3 sentences. Paraphrase freely. Replace source city / state. Do not invent specifics like ZIP codes, neighborhood names, license numbers, prices, or fees that are not in the original.',
		'list_item'    => 'Field type: LIST ITEM. Short phrase, 2-6 words. You may rephrase but do not invent new items, do not split, do not merge.',
		'caption'      => 'Field type: IMAGE CAPTION. Short, descriptive, 6-12 words. May be rephrased. Replace source city if mentioned.',
		'plain_text'   => 'Field type: SHORT PLAIN TEXT. Reword for variety if it contains the source city. If the original is a generic UI label (e.g. "Read More", "Submit"), return it UNCHANGED.',
	);

	/**
	 * The system message - hardcoded guardrails that apply to every field.
	 *
	 * Every rule here is a hill we are willing to die on. If the model
	 * violates one (most commonly an em-dash or an AI cliché), the rewrite
	 * is wrong even if the meaning is correct.
	 */
	private const SYSTEM_PROMPT = <<<'TXT'
You are a content localization editor for a small business website. Your job is to PARAPHRASE the original copy for a different city so that 80+ cloned location pages each read uniquely instead of being word-for-word duplicates of one another. You ARE allowed to rephrase, restructure sentences, and change word order. You are NOT a creative writer: you preserve every fact, claim, and structural element of the original.

INPUT YOU WILL RECEIVE:
  - SOURCE: a city/state currently mentioned in the original (e.g. "Orlando, FL").
  - TARGET: a city/state the rewrite is for (e.g. "Tampa, FL").
  - FIELD INSTRUCTION: per-field guidance (heading, body paragraph, button, FAQ, etc.).
  - ORIGINAL: the existing copy.

YOU WILL OUTPUT:
  - ONLY the rewritten value. No quotes, no preamble, no markdown code fences,
    no "Here is the rewrite:". Just the rewritten string.

WHAT "PARAPHRASE" MEANS HERE:
  - Vary sentence openers and clause order. Do not start every sentence the same way the source does.
  - Combine or split sentences if it reads more naturally (within the length budget).
  - Substitute common synonyms freely (e.g. "fast" -> "quick", "easy" -> "straightforward", "big" -> "large", "help" -> "assist") - the goal is unique-feeling copy.
  - PRESERVE: every fact, every claim, every list item count, every paragraph break, every HTML tag, every CTA verb.
  - It is fine for two cloned pages to share short phrases (especially brand vocabulary) - the rewrite does not have to be 100% original, just clearly not a copy/paste.

HARD RULES (any violation is a failed response):
  1. NEVER invent specifics that are not in the original. No new ZIP codes,
     phone numbers, addresses, neighborhood names, area codes, prices,
     license numbers, review counts, or business hours. If the original
     does not mention something, your rewrite does not mention it either.
  2. NEVER add new services, features, claims, or selling points. If the
     original lists 3 things, your rewrite lists exactly 3 things.
  3. PRESERVE HTML structure exactly. Tags, classes, attributes, IDs are
     identical to the original. Only inner text changes.
  4. PRESERVE list and paragraph counts. Do not split, merge, or reorder
     list items. Do not split or merge paragraphs.
  5. MATCH length within ±25% of the original character count.
  6. NEVER use em-dashes (—) or en-dashes (–). Use a comma, period, or
     colon instead. Hyphens (-) inside compound words are fine.
  7. NEVER use these AI-tell words or phrases: "delve", "tapestry",
     "embark", "journey", "navigate", "navigating the landscape", "in
     today's fast-paced world", "in the realm of", "pivotal",
     "paramount", "plethora", "leverage", "robust", "seamless",
     "elevate", "unlock", "cutting-edge", "revolutionary", "intricate",
     "multifaceted", "holistic", "moreover", "furthermore",
     "it is important to note", "keep in mind", "rest assured", "look
     no further". If the original uses one of these, you may keep it,
     but never introduce one.
  8. NEVER add hedging or filler ("essentially", "basically", "of course",
     "certainly"). "Simply" is fine if it is in the original.
  9. If the ORIGINAL does NOT mention the source city or state by name,
     your rewrite must NOT mention the target city or state either. Do
     not shoehorn the city into copy that was previously generic.
 10. If the ORIGINAL is a SHORT generic UI label (e.g. "Get a quote",
     "Submit", "Read more", "Contact us"), return it UNCHANGED.
 11. DO NOT change category nouns when they are field labels:
     "Available Sizes" stays "Available Sizes" (do NOT rewrite to
     "Available Dimensions"). Specifically forbidden swaps:
     "size" -> "dimensions", "rental" -> "lease". Other synonyms are OK.
 12. NEVER change a numeric figure ("4 easy steps" stays "4", "20-yard"
     stays "20"). Numbers are facts, not phrasing.
 13. Headings that contain a number + units (e.g. "20 Yard Dumpster")
     must keep that exact number + unit. Only the surrounding words
     may be rephrased.

LOCALIZATION:
  - Where the original mentions the source city, swap to the target city.
  - Where the original mentions the source state (full or abbreviation),
    swap to the target state.
  - ALWAYS use the two-letter U.S. state abbreviation (FL, CA, TX, NY,
    etc.), never the full state name. If the original says "Orlando,
    Florida", your output uses "Tampa, FL" - shorten the state. If the
    SOURCE / TARGET label spells out the state, still output the
    abbreviation. The only exception is if the original spells out the
    state inside a proper noun or quote that must remain literal.

TONE:
  - Match the original's tone exactly. If it is friendly, stay friendly.
    If it is formal, stay formal. Do not shift register.
TXT;

	/**
	 * Build the (system, user) message pair for a single field.
	 *
	 * @return array{system: string, user: string}
	 */
	public static function build( array $args ): array {
		$brand_voice = trim( (string) ( $args['brand_voice'] ?? '' ) );
		$role        = (string) ( $args['role'] ?? 'rich_text' );
		$source      = (string) ( $args['source_label'] ?? '' );
		$target      = (string) ( $args['target_label'] ?? '' );
		$original    = (string) ( $args['original'] ?? '' );

		$field_instruction = self::FIELD_INSTRUCTIONS[ $role ] ?? self::FIELD_INSTRUCTIONS['rich_text'];

		$system = self::SYSTEM_PROMPT;
		if ( '' !== $brand_voice ) {
			$system .= "\n\nBRAND VOICE (apply to the rewrite):\n" . $brand_voice;
		}

		$user = sprintf(
			"FIELD INSTRUCTION:\n%s\n\nSOURCE: %s\nTARGET: %s\n\nORIGINAL:\n%s\n\nREWRITTEN:",
			$field_instruction,
			$source,
			$target,
			$original
		);

		return array(
			'system' => $system,
			'user'   => $user,
		);
	}

	/**
	 * Best-effort: detect whether a value still contains an AI-tell or an
	 * em-dash. The rewrite engine uses this for a soft warning in the diff
	 * UI; not a hard reject. Returns array of issues found (empty = clean).
	 *
	 * @return string[]
	 */
	public static function lint_output( string $value ): array {
		$issues = array();

		if ( str_contains( $value, "\u{2014}" ) || str_contains( $value, "\u{2013}" ) ) {
			$issues[] = 'em_dash';
		}

		$banned = array(
			'delve', 'tapestry', 'embark', 'navigating the landscape', 'in the realm of',
			'pivotal', 'paramount', 'plethora', 'leverage', 'seamless',
			'elevate', 'unlock', 'cutting-edge', 'intricate', 'multifaceted',
			'holistic', 'rest assured', 'look no further', 'simply put',
		);
		$lower = strtolower( wp_strip_all_tags( $value ) );
		foreach ( $banned as $needle ) {
			if ( str_contains( $lower, $needle ) ) {
				$issues[] = 'banned:' . $needle;
			}
		}

		return $issues;
	}

	private static function ends_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}
		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}
