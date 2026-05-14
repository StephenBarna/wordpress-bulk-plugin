<?php
/**
 * Applies a list of neighborhoods to a Beaver Builder page by finding the
 * module(s) tagged with the "ehbp-neighborhoods" CSS class on the page's
 * BB layout and rebuilding their content from the supplied list.
 *
 * Two module types are supported:
 *   - HTML modules:        rewrites `settings->html` with one `<div>` per
 *                          neighborhood. Variable count - if the CSV has
 *                          27 entries you get 27 divs, no padding/clipping.
 *   - list-icon modules:   rebuilds `settings->items` from item[0] as a
 *                          template (preserves icon, color, font), one
 *                          entry per neighborhood.
 *
 * State abbreviation is auto-derived from the city slug suffix
 * (e.g. "celebration-fl" -> "FL") and falls back to a state-name map
 * keyed off the post's `_ehbp_state` meta if the slug doesn't carry it.
 *
 * Writes happen to BOTH the BB draft and published slots, then BB's asset
 * cache for the post is busted, so the live URL reflects the change on the
 * next page load without needing to open the BB editor.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use FLBuilderModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Neighborhoods_Applier {

	/** Default class users add to the BB module's Advanced -> Class field. */
	public const TARGET_CLASS = 'ehbp-neighborhoods';

	/** Maximum HTML entries we'll write to a single module (safety rail). */
	private const MAX_ENTRIES = 200;

	/**
	 * Per-request memoization of parsed source templates keyed by source
	 * post ID. Each apply() looks up its own template by walking up to the
	 * `_ehbp_source_post_id` parent, but a single apply-all batch hits the
	 * same source 10+ times - parse once, reuse.
	 *
	 * @var array<int, array{wrapper_html: string, item_html: string}|null>
	 */
	private static array $template_cache = array();

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
		'nevada'               => 'NV', 'new hampshire'  => 'NH', 'new jersey'    => 'NJ',
		'new mexico'           => 'NM', 'new york'       => 'NY', 'north carolina' => 'NC',
		'north dakota'         => 'ND', 'ohio'           => 'OH', 'oklahoma'       => 'OK',
		'oregon'               => 'OR', 'pennsylvania'   => 'PA', 'rhode island'  => 'RI',
		'south carolina'       => 'SC', 'south dakota'   => 'SD', 'tennessee'     => 'TN',
		'texas'                => 'TX', 'utah'           => 'UT', 'vermont'       => 'VT',
		'virginia'             => 'VA', 'washington'     => 'WA', 'west virginia' => 'WV',
		'wisconsin'            => 'WI', 'wyoming'        => 'WY',
		'district of columbia' => 'DC',
	);

	/**
	 * Find the post (any status) whose `_ehbp_city_slug` meta matches the
	 * given slug. Returns 0 if none.
	 */
	public static function find_post_by_slug( string $slug ): int {
		global $wpdb;
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return 0;
		}

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT pm.post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value = %s
				  AND p.post_status IN ('publish','draft','private','future')
				ORDER BY p.post_modified DESC
				LIMIT 1
				",
				Page_Cloner::META_CITY_SLUG,
				$slug
			)
		);

		return (int) $id;
	}

	/**
	 * Locate every BB node on a post whose CSS class contains the given
	 * target class. Returns the published-slot node objects (preferred for
	 * live rendering), falling back to draft if published is empty.
	 *
	 * @return array<int, object> Node objects from BB layout.
	 */
	public static function locate_modules( int $post_id, string $target_class = self::TARGET_CLASS ): array {
		if ( ! class_exists( '\FLBuilderModel' ) ) {
			return array();
		}

		$layout = FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( empty( $layout ) ) {
			$layout = FLBuilderModel::get_layout_data( 'draft', $post_id );
		}
		if ( empty( $layout ) || ! is_array( $layout ) ) {
			return array();
		}

		$matches = array();
		foreach ( $layout as $node_id => $node ) {
			if ( ! is_object( $node ) || empty( $node->settings ) ) {
				continue;
			}
			$class = isset( $node->settings->class ) ? (string) $node->settings->class : '';
			if ( '' === $class ) {
				continue;
			}
			if ( self::class_list_contains( $class, $target_class ) ) {
				$matches[ (string) $node_id ] = $node;
			}
		}
		return $matches;
	}

	/**
	 * Apply a list of neighborhoods to a single post. Returns a result
	 * summary suitable for surfacing in the admin UI.
	 *
	 * @param int           $post_id        WP post ID.
	 * @param array<string> $neighborhoods  Plain text names, e.g. ["Town Center","Lake Evalyn"].
	 * @return array{ok: bool, message: string, modules: array<int, array<string, mixed>>}
	 */
	public static function apply( int $post_id, array $neighborhoods ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'ok' => false, 'message' => 'Post not found.', 'modules' => array() );
		}

		$neighborhoods = self::dedupe_and_trim( $neighborhoods );
		if ( empty( $neighborhoods ) ) {
			return array( 'ok' => false, 'message' => 'No neighborhoods to apply.', 'modules' => array() );
		}
		if ( count( $neighborhoods ) > self::MAX_ENTRIES ) {
			$neighborhoods = array_slice( $neighborhoods, 0, self::MAX_ENTRIES );
		}

		$state_abbr = self::resolve_state_abbr( $post_id );

		$layout = FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( empty( $layout ) ) {
			$layout = FLBuilderModel::get_layout_data( 'draft', $post_id );
		}
		if ( empty( $layout ) || ! is_array( $layout ) ) {
			return array( 'ok' => false, 'message' => 'No BB layout on that page.', 'modules' => array() );
		}

		$source_post_id = (int) get_post_meta( $post_id, Page_Cloner::META_SOURCE_POST_ID, true );
		$template       = $source_post_id > 0 ? self::get_or_extract_template( $source_post_id ) : null;
		$source_token   = $source_post_id > 0 ? self::derive_city_token( $source_post_id ) : '';
		$target_token   = self::derive_city_token( $post_id );

		$modules_done = array();
		$any_changed  = false;

		foreach ( $layout as $node_id => $node ) {
			if ( ! is_object( $node ) || empty( $node->settings ) ) {
				continue;
			}
			$class = isset( $node->settings->class ) ? (string) $node->settings->class : '';
			if ( ! self::class_list_contains( $class, self::TARGET_CLASS ) ) {
				continue;
			}

			$slug   = isset( $node->settings->type ) ? (string) $node->settings->type : '';
			$result = null;

			if ( $slug === 'rich-text' || $slug === '' || $slug === 'html' ) {
				// HTML / rich-text module: rewrite the content using the
				// source template if available, fall back to flat <div>s.
				$rendered = null !== $template
					? self::render_from_template( $template, $neighborhoods, $state_abbr, $source_token, $target_token )
					: self::render_html_block( $neighborhoods, $state_abbr );

				$key = ( $slug === 'html' ) ? 'html' : 'text';
				$node->settings->$key = $rendered;
				$result = array(
					'node_id' => (string) $node_id,
					'kind'    => null !== $template ? 'html-template' : 'html-flat',
					'count'   => count( $neighborhoods ),
					'preview' => self::short_preview( $rendered ),
				);
			} elseif ( $slug === 'list-icon' ) {
				$rebuilt = self::rebuild_list_icon_items( $node, $neighborhoods, $state_abbr );
				if ( null !== $rebuilt ) {
					$node->settings->items = $rebuilt;
					$result = array(
						'node_id' => (string) $node_id,
						'kind'    => 'list-icon',
						'count'   => count( $neighborhoods ),
						'preview' => implode( ', ', array_slice( array_map( static fn( $n ) => self::format_entry( $n, $state_abbr ), $neighborhoods ), 0, 5 ) ) . '...',
					);
				}
			} else {
				// Unknown module type carrying our class. Best-effort: try
				// the most common content keys (text/html/content) so the
				// user gets something rather than a silent miss.
				$rendered = null !== $template
					? self::render_from_template( $template, $neighborhoods, $state_abbr, $source_token, $target_token )
					: self::render_html_block( $neighborhoods, $state_abbr );
				foreach ( array( 'text', 'html', 'content' ) as $key ) {
					if ( isset( $node->settings->$key ) ) {
						$node->settings->$key = $rendered;
						$result = array(
							'node_id' => (string) $node_id,
							'kind'    => $slug . ':' . $key,
							'count'   => count( $neighborhoods ),
							'preview' => self::short_preview( $rendered ),
						);
						break;
					}
				}
			}

			if ( null !== $result ) {
				$modules_done[] = $result;
				$any_changed    = true;
			}
		}

		if ( ! $any_changed ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					__( 'No module on "%1$s" (#%2$d) has the "%3$s" class. Add it on the Orlando template, then re-clone (or add it directly on this page).', 'earthhaul-bulk-pages' ),
					$post->post_title,
					$post_id,
					self::TARGET_CLASS
				),
				'modules' => array(),
			);
		}

		FLBuilderModel::update_layout_data( $layout, 'draft', $post_id );
		FLBuilderModel::update_layout_data( $layout, 'published', $post_id );

		if ( method_exists( '\FLBuilderModel', 'delete_asset_cache' ) ) {
			FLBuilderModel::delete_asset_cache( $post_id );
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				__( 'Applied %1$d neighborhoods to %2$d module(s) on "%3$s".', 'earthhaul-bulk-pages' ),
				count( $neighborhoods ),
				count( $modules_done ),
				$post->post_title
			),
			'modules' => $modules_done,
		);
	}

	/**
	 * Render the inner HTML for an HTML / rich-text module. One <div> per
	 * neighborhood, each formatted as "Name, ST". This is the fallback
	 * path used when the source template can't be parsed (or doesn't
	 * exist). The BB module's own row settings handle column flow /
	 * typography on the live page.
	 */
	public static function render_html_block( array $neighborhoods, string $state_abbr ): string {
		$lines = array();
		foreach ( $neighborhoods as $name ) {
			$lines[] = '<div>' . esc_html( self::format_entry( $name, $state_abbr ) ) . '</div>';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Render new HTML using a parsed source template. The template
	 * preserves the wrapper element (e.g. <ul class="...">) and the
	 * per-item element (e.g. <li class="..."><span>{name}</span></li>)
	 * exactly as authored on the source page, only swapping:
	 *
	 *   1. The deepest text content of each cloned item -> the new name
	 *   2. The source-city token in the wrapper's class string -> target
	 *      (e.g. "orlando-florida" -> "celebration-florida"), so each
	 *      page can be styled with its own per-city CSS hooks.
	 */
	public static function render_from_template(
		array $template,
		array $neighborhoods,
		string $state_abbr,
		string $source_token,
		string $target_token
	): string {
		$wrapper = (string) $template['wrapper_html'];
		$item    = (string) $template['item_html'];

		if ( '' !== $source_token && '' !== $target_token && $source_token !== $target_token ) {
			$wrapper = self::replace_city_token( $wrapper, $source_token, $target_token );
		}

		$rendered_items = array();
		foreach ( $neighborhoods as $name ) {
			$entry            = self::format_entry( $name, $state_abbr );
			$rendered_items[] = str_replace( '{{NAME}}', esc_html( $entry ), $item );
		}

		$inner = "\n" . implode( "\n", $rendered_items ) . "\n";
		return str_replace( '{{ITEMS}}', $inner, $wrapper );
	}

	/**
	 * Memoized template extractor for a given source post ID. Looks at the
	 * source page's matching module HTML and builds wrapper + item-shaped
	 * placeholders the rendering helper can plug per-neighborhood values
	 * into. Returns null if the source HTML isn't structured enough to
	 * extract a template (e.g. a flat list of <div>s).
	 *
	 * @return array{wrapper_html: string, item_html: string}|null
	 */
	private static function get_or_extract_template( int $source_post_id ): ?array {
		if ( array_key_exists( $source_post_id, self::$template_cache ) ) {
			return self::$template_cache[ $source_post_id ];
		}

		$template = self::extract_template_from_source( $source_post_id );
		self::$template_cache[ $source_post_id ] = $template;
		return $template;
	}

	/**
	 * Read the source post's matching module(s), parse the HTML out of the
	 * first one, and break it into a (wrapper, item) pair suitable for
	 * placeholder substitution. We preserve everything authored on the
	 * source: tag names, class lists, nested elements, attributes.
	 *
	 * @return array{wrapper_html: string, item_html: string}|null
	 */
	private static function extract_template_from_source( int $source_post_id ): ?array {
		$modules = self::locate_modules( $source_post_id );
		if ( empty( $modules ) ) {
			return null;
		}

		$module = reset( $modules );
		$slug   = isset( $module->settings->type ) ? (string) $module->settings->type : '';

		if ( $slug === 'rich-text' || $slug === '' ) {
			$html = isset( $module->settings->text ) ? (string) $module->settings->text : '';
		} elseif ( $slug === 'html' ) {
			$html = isset( $module->settings->html ) ? (string) $module->settings->html : '';
		} else {
			return null;
		}

		$html = trim( $html );
		if ( '' === $html ) {
			return null;
		}

		// If the source HTML is just a flat run of <div>s with no nested
		// elements (e.g. a previous run of this plugin already overwrote
		// it), there's no per-item structure to template - bail to flat.
		if ( ! preg_match( '/<(?!div\b|br\b|\/)\s*[a-z][a-z0-9]*\b/i', $html ) ) {
			return null;
		}

		libxml_use_internal_errors( true );
		$dom    = new \DOMDocument( '1.0', 'UTF-8' );
		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8"?><div id="ehbp-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		if ( false === $loaded ) {
			return null;
		}

		$root = $dom->getElementById( 'ehbp-root' );
		if ( null === $root ) {
			return null;
		}

		$wrapper = self::first_element_child( $root );
		if ( null === $wrapper ) {
			return null;
		}

		$item = self::first_element_child( $wrapper );
		if ( null === $item ) {
			return null;
		}

		// Build the per-item template: clone the first item element and
		// replace its largest text node with a placeholder we can str_replace
		// later with each neighborhood's name.
		$item_clone = $item->cloneNode( true );
		if ( ! self::replace_largest_text_node( $item_clone, '{{NAME}}' ) ) {
			return null;
		}
		$item_html = (string) $dom->saveHTML( $item_clone );

		// Build the wrapper template: shallow-clone the wrapper (preserves
		// tag + attributes + classes), then put a single text-node
		// placeholder where the items will be spliced in.
		$wrapper_clone = $wrapper->cloneNode( false );
		$wrapper_clone->appendChild( $dom->createTextNode( '{{ITEMS}}' ) );
		$wrapper_html = (string) $dom->saveHTML( $wrapper_clone );

		return array(
			'wrapper_html' => trim( $wrapper_html ),
			'item_html'    => trim( $item_html ),
		);
	}

	/**
	 * Derive a hyphenated lowercase token for a post's city, used to swap
	 * city-specific CSS class names on the wrapper.
	 *
	 * Tries: _ehbp_city_name meta -> parsed post title -> post slug minus
	 * trailing 2-letter state. Returns "" if none of those resolve, in
	 * which case the wrapper class swap is skipped (other parts of the
	 * pipeline still work).
	 */
	private static function derive_city_token( int $post_id ): string {
		$name = (string) get_post_meta( $post_id, Page_Cloner::META_CITY_NAME, true );
		if ( '' !== $name ) {
			return sanitize_title( $name );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		if ( class_exists( '\EarthHaul\BulkPages\Admin\Rewrite_Screen' ) ) {
			$parsed = \EarthHaul\BulkPages\Admin\Rewrite_Screen::parse_city_state_from_title( (string) $post->post_title );
			if ( '' !== $parsed['city'] ) {
				return sanitize_title( $parsed['city'] );
			}
		}

		$post_name = (string) $post->post_name;
		if ( '' !== $post_name && preg_match( '/^(.+)-[a-z]{2}$/i', $post_name, $m ) ) {
			return sanitize_title( $m[1] );
		}
		return '' !== $post_name ? sanitize_title( $post_name ) : '';
	}

	/**
	 * Replace whole-word occurrences of $source_token in $haystack with
	 * $target_token. Word boundary uses regex \b which treats hyphens as
	 * boundaries too - so "orlando" matches inside "orlando-florida"
	 * (replaced) but not inside "orlandowide" (not replaced).
	 */
	private static function replace_city_token( string $haystack, string $source_token, string $target_token ): string {
		if ( '' === $source_token ) {
			return $haystack;
		}
		$pattern = '/\b' . preg_quote( $source_token, '/' ) . '\b/i';
		$result  = preg_replace( $pattern, $target_token, $haystack );
		return null === $result ? $haystack : $result;
	}

	/**
	 * Find the first DOMElement child of a given DOM node, skipping over
	 * whitespace text nodes and comments.
	 */
	private static function first_element_child( \DOMNode $parent ): ?\DOMElement {
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Find the longest non-whitespace text node descended from $node and
	 * replace its value with $replacement. Returns true on success, false
	 * if no substantive text node was found (in which case the template
	 * is unusable for placeholder substitution).
	 */
	private static function replace_largest_text_node( \DOMNode $node, string $replacement ): bool {
		$best     = null;
		$best_len = 0;

		$walk = function ( \DOMNode $n ) use ( &$walk, &$best, &$best_len ): void {
			if ( $n instanceof \DOMText ) {
				$trimmed = trim( (string) $n->nodeValue );
				$len     = strlen( $trimmed );
				if ( $len > $best_len ) {
					$best_len = $len;
					$best     = $n;
				}
				return;
			}
			if ( $n->hasChildNodes() ) {
				foreach ( $n->childNodes as $c ) {
					$walk( $c );
				}
			}
		};
		$walk( $node );

		if ( null === $best ) {
			return false;
		}
		$best->nodeValue = $replacement;
		return true;
	}

	/**
	 * Rebuild a list-icon module's `items` array using item[0] as a styling
	 * template. Returns null if the module's items array is missing/empty
	 * (we can't infer styling without a sample), in which case the caller
	 * should fall back to its HTML-block path or skip the module.
	 *
	 * @return array|null
	 */
	private static function rebuild_list_icon_items( object $node, array $neighborhoods, string $state_abbr ): ?array {
		if ( ! isset( $node->settings->items ) ) {
			return null;
		}
		$items = $node->settings->items;
		if ( ! is_array( $items ) || empty( $items ) ) {
			return null;
		}

		$template = $items[0];

		$out = array();
		foreach ( $neighborhoods as $name ) {
			$entry = self::deep_clone( $template );
			$label = self::format_entry( $name, $state_abbr );

			// Try the common keys list-icon uses for the displayed text.
			foreach ( array( 'text', 'title', 'content' ) as $key ) {
				if ( is_object( $entry ) && property_exists( $entry, $key ) ) {
					$entry->$key = $label;
					break;
				}
				if ( is_array( $entry ) && array_key_exists( $key, $entry ) ) {
					$entry[ $key ] = $label;
					break;
				}
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Resolve the state abbreviation for a post. Tries:
	 *   1. trailing -XX in the city slug ("celebration-fl" -> "FL")
	 *   2. _ehbp_state meta as a state-name lookup ("Florida" -> "FL")
	 *   3. _ehbp_state meta if it's already 2 letters
	 *   4. empty string (no abbreviation appended in render)
	 */
	public static function resolve_state_abbr( int $post_id ): string {
		$slug = (string) get_post_meta( $post_id, Page_Cloner::META_CITY_SLUG, true );
		if ( '' !== $slug && preg_match( '/-([a-z]{2})$/i', $slug, $m ) ) {
			return strtoupper( $m[1] );
		}

		$state = (string) get_post_meta( $post_id, Page_Cloner::META_STATE, true );
		$lower = strtolower( trim( $state ) );
		if ( isset( self::STATE_NAME_TO_ABBR[ $lower ] ) ) {
			return self::STATE_NAME_TO_ABBR[ $lower ];
		}
		if ( strlen( $state ) === 2 && ctype_alpha( $state ) ) {
			return strtoupper( $state );
		}

		return '';
	}

	/**
	 * Render a single entry like "Lake Nona, FL". When state_abbr is empty
	 * we just return the raw name, no trailing comma.
	 */
	public static function format_entry( string $name, string $state_abbr ): string {
		$name = trim( $name );
		if ( '' === $state_abbr ) {
			return $name;
		}
		// If the user already included a state in the name, don't double-up.
		if ( preg_match( '/,\s*[A-Z]{2}\s*$/i', $name ) ) {
			return $name;
		}
		return $name . ', ' . $state_abbr;
	}

	/**
	 * Returns true if a space-separated class string contains $target as a
	 * whole class (not a substring of another class).
	 */
	private static function class_list_contains( string $haystack, string $target ): bool {
		$tokens = preg_split( '/\s+/', trim( $haystack ) ) ?: array();
		return in_array( $target, $tokens, true );
	}

	/**
	 * Strip dupes (case-insensitive) and trim whitespace from incoming list.
	 */
	private static function dedupe_and_trim( array $names ): array {
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

	private static function short_preview( string $html ): string {
		$txt = wp_strip_all_tags( $html );
		$txt = preg_replace( '/\s+/', ' ', (string) $txt );
		if ( strlen( $txt ) > 160 ) {
			$txt = substr( $txt, 0, 157 ) . '...';
		}
		return (string) $txt;
	}

	/**
	 * Deep-clone an object/array (used for cloning the list-icon template
	 * item so the original isn't mutated by reference).
	 */
	private static function deep_clone( $value ) {
		if ( is_object( $value ) ) {
			return unserialize( serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.serialize_serialize, WordPress.PHP.DiscouragedFunctions.serialize_unserialize
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::deep_clone( $v );
			}
			return $out;
		}
		return $value;
	}
}
