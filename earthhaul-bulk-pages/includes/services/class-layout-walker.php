<?php
/**
 * Walks a Beaver Builder layout and produces a structured field inventory.
 *
 * The walker is intentionally read-only and side-effect-free. Phase 1b.i
 * uses it to power the "Inspect Page" UI; Phase 1b.ii will use the same
 * inventory to drive AI rewrites. Phase 1c will use it for image swaps.
 *
 * Design: instead of guessing at field names with regex/heuristics, we
 * keep an explicit per-module-type schema (MODULE_SCHEMAS) listing the
 * exact settings keys that hold real content. This was derived by
 * dumping a real BB layout (see scripts/dump-bb-layout.php and
 * scripts/analyze-module-fields.php in this repo) so it matches the
 * actual data shape rather than aspirational naming conventions.
 *
 * Critical safety rule: any node where FLBuilderModel::is_node_global()
 * returns truthy is reported as global and the walker DOES NOT descend
 * into it. Globals are a single source of truth shared across pages and
 * must never be mutated by bulk processing.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Services;

use FLBuilderModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Layout_Walker {

	/**
	 * Per-module-type content schema.
	 *
	 * Each entry maps a Beaver Builder module slug (settings->type) to a
	 * description of which fields hold real content:
	 *
	 *   'fields'     — flat list of [key, kind] for top-level settings
	 *   'items'      — name of an array field whose entries are repeating items
	 *   'item_fields'— [key, kind] schema applied to each entry in items
	 *   'image_type' — for modules like image-icon/list-icon that switch
	 *                  between icon-mode and photo-mode, the settings key
	 *                  that controls the mode and the value that means
	 *                  "treat as image".
	 *
	 * 'kind' values:
	 *   text        — short plain-text content (heading, button label)
	 *   html        — rich content that may contain HTML tags
	 *   image_id    — attachment post ID
	 *   image_url   — full image URL (photo_src / photo_url)
	 *   image_meta  — attachment metadata blob (data.alt, data.title, ...)
	 *   url         — link URL (rarely rewritten by AI; surfaced for review)
	 */
	private const MODULE_SCHEMAS = array(
		'heading'             => array(
			'fields' => array(
				array( 'key' => 'heading', 'kind' => 'html' ),
				array( 'key' => 'link',    'kind' => 'url' ),
			),
		),
		'rich-text'           => array(
			'fields' => array(
				array( 'key' => 'text', 'kind' => 'html' ),
			),
		),
		'html'                => array(
			'fields' => array(
				array( 'key' => 'html', 'kind' => 'html' ),
			),
		),
		'button'              => array(
			'fields' => array(
				array( 'key' => 'text', 'kind' => 'text' ),
				array( 'key' => 'link', 'kind' => 'url' ),
			),
		),
		'icon'                => array(
			'fields' => array(
				array( 'key' => 'text',    'kind' => 'html' ),
				array( 'key' => 'sr_text', 'kind' => 'text' ),
				array( 'key' => 'link',    'kind' => 'url' ),
			),
		),
		'photo'               => array(
			'fields' => array(
				array( 'key' => 'photo',     'kind' => 'image_id' ),
				array( 'key' => 'photo_src', 'kind' => 'image_url' ),
				array( 'key' => 'photo_url', 'kind' => 'image_url' ),
				array( 'key' => 'caption',   'kind' => 'html' ),
				array( 'key' => 'data',      'kind' => 'image_meta' ),
				array( 'key' => 'link_url',  'kind' => 'url' ),
			),
		),
		'image-icon'          => array(
			'image_type' => array( 'key' => 'image_type', 'photo_value' => 'photo' ),
			'fields'     => array(
				array( 'key' => 'photo',     'kind' => 'image_id',   'requires_photo_mode' => true ),
				array( 'key' => 'photo_src', 'kind' => 'image_url',  'requires_photo_mode' => true ),
				array( 'key' => 'data',      'kind' => 'image_meta', 'requires_photo_mode' => true ),
			),
		),
		// Decorative / no content.
		'separator'           => array( 'fields' => array() ),
		'icon-group'          => array( 'fields' => array() ),
		// Repeating-item modules.
		'button-group'        => array(
			'fields'      => array(),
			'items'       => 'items',
			'item_fields' => array(
				array( 'key' => 'text', 'kind' => 'text' ),
				array( 'key' => 'link', 'kind' => 'url' ),
			),
		),
		'list-icon'           => array(
			'fields'      => array(),
			'items'       => 'list_items',
			'item_fields' => array(
				array( 'key' => 'title', 'kind' => 'text' ),
			),
		),
		'uabb-faq'            => array(
			'fields'      => array(),
			'items'       => 'faq_items',
			'item_fields' => array(
				array( 'key' => 'faq_question', 'kind' => 'text' ),
				array( 'key' => 'faq_answer',   'kind' => 'html' ),
			),
		),
		// All instances of this module on EarthHaul are globals (carousel of
		// dumpster sizes). Listed for completeness; safe defaults.
		'uabb-image-carousel' => array( 'fields' => array() ),
	);

	/**
	 * Walk a post's published layout and return a flat inventory.
	 *
	 * @param int $post_id The post whose BB layout we will inspect.
	 * @return array{
	 *   post_id: int,
	 *   summary: array{rows: int, columns: int, modules: int, globals: int, text_fields: int, html_fields: int, image_fields: int, unknown_modules: int},
	 *   nodes: array<int, array<string, mixed>>,
	 * }
	 */
	public static function inspect( int $post_id ): array {
		$inventory = array(
			'post_id' => $post_id,
			'summary' => array(
				'rows'            => 0,
				'columns'         => 0,
				'modules'         => 0,
				'globals'         => 0,
				'text_fields'     => 0,
				'html_fields'     => 0,
				'image_fields'    => 0,
				'url_fields'      => 0,
				'unknown_modules' => 0,
			),
			'nodes'   => array(),
		);

		if ( ! class_exists( '\FLBuilderModel' ) ) {
			return $inventory;
		}

		$layout = FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( empty( $layout ) ) {
			$layout = FLBuilderModel::get_layout_data( 'draft', $post_id );
		}
		if ( empty( $layout ) || ! is_array( $layout ) ) {
			return $inventory;
		}

		$parent_lookup = array();
		foreach ( $layout as $node_id => $node ) {
			$parent_lookup[ $node_id ] = isset( $node->parent ) ? (string) $node->parent : '';
		}

		foreach ( $layout as $node_id => $node ) {
			$type        = isset( $node->type ) ? (string) $node->type : '';
			$is_global   = (bool) FLBuilderModel::is_node_global( $node );
			$row_node_id = self::ascend_to_row( $node_id, $layout, $parent_lookup );
			$module_slug = self::module_slug( $node );

			if ( 'row' === $type ) {
				$inventory['summary']['rows']++;
			} elseif ( 'column' === $type ) {
				$inventory['summary']['columns']++;
			} elseif ( 'module' === $type ) {
				$inventory['summary']['modules']++;
			}

			if ( $is_global ) {
				$inventory['summary']['globals']++;
			}

			$entry = array(
				'node_id'     => (string) $node_id,
				'type'        => $type,
				'row_id'      => $row_node_id,
				'is_global'   => $is_global,
				'module_slug' => $module_slug,
				'fields'      => array(),
				'label'       => self::derive_node_label( $node ),
				'schema'      => 'n/a',
				'class'       => isset( $node->settings->class ) ? (string) $node->settings->class : '',
			);

			if ( $is_global ) {
				// We intentionally do NOT enumerate fields on globals. They are
				// read-only and any inventory of their content would imply we
				// might mutate it. Listing the global by node ID is enough.
				$inventory['nodes'][] = $entry;
				continue;
			}

			if ( 'module' === $type && isset( $node->settings ) && is_object( $node->settings ) ) {
				if ( isset( self::MODULE_SCHEMAS[ $module_slug ] ) ) {
					$entry['schema'] = 'known';
					$entry['fields'] = self::extract_with_schema( $node->settings, self::MODULE_SCHEMAS[ $module_slug ] );
				} else {
					$entry['schema'] = 'unknown';
					$inventory['summary']['unknown_modules']++;
				}

				foreach ( $entry['fields'] as $field ) {
					switch ( $field['kind'] ) {
						case 'text':
							$inventory['summary']['text_fields']++;
							break;
						case 'html':
							$inventory['summary']['html_fields']++;
							break;
						case 'image_id':
						case 'image_url':
						case 'image_meta':
							$inventory['summary']['image_fields']++;
							break;
						case 'url':
							$inventory['summary']['url_fields']++;
							break;
					}
				}
			}

			$inventory['nodes'][] = $entry;
		}

		return $inventory;
	}

	/**
	 * Apply a module schema to a settings object and return the flat list of
	 * relevant fields. Empty / dead-cache values are filtered out.
	 *
	 * @return array<int, array{path: string, kind: string, value: string}>
	 */
	private static function extract_with_schema( object $settings, array $schema ): array {
		$out = array();

		$is_photo_mode = true;
		if ( isset( $schema['image_type'] ) ) {
			$mode_key      = $schema['image_type']['key'];
			$photo_value   = $schema['image_type']['photo_value'];
			$is_photo_mode = isset( $settings->$mode_key ) && (string) $settings->$mode_key === $photo_value;
		}

		foreach ( ( $schema['fields'] ?? array() ) as $field_def ) {
			if ( ! empty( $field_def['requires_photo_mode'] ) && ! $is_photo_mode ) {
				continue;
			}
			$key = $field_def['key'];
			if ( ! isset( $settings->$key ) ) {
				continue;
			}
			$value = $settings->$key;
			$entry = self::build_field_entry( $key, $field_def['kind'], $value );
			if ( $entry !== null ) {
				$out[] = $entry;
			}
		}

		if ( isset( $schema['items'], $schema['item_fields'] ) ) {
			$items_key = $schema['items'];
			$items     = $settings->$items_key ?? null;
			if ( is_array( $items ) ) {
				foreach ( $items as $idx => $item ) {
					$item = is_object( $item ) ? (array) $item : ( is_array( $item ) ? $item : array() );
					foreach ( $schema['item_fields'] as $field_def ) {
						$key = $field_def['key'];
						if ( ! array_key_exists( $key, $item ) ) {
							continue;
						}
						$entry = self::build_field_entry( $items_key . '.' . $idx . '.' . $key, $field_def['kind'], $item[ $key ] );
						if ( $entry !== null ) {
							$out[] = $entry;
						}
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Build a single field inventory entry, or return null if the value is
	 * empty / structural / not actually content.
	 */
	private static function build_field_entry( string $path, string $kind, $value ): ?array {
		switch ( $kind ) {
			case 'image_id':
				if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( (string) $value ) ) ) {
					$id = (int) $value;
					if ( $id > 0 ) {
						return array( 'path' => $path, 'kind' => $kind, 'value' => (string) $id );
					}
				}
				return null;

			case 'image_url':
			case 'url':
				if ( ! is_string( $value ) ) {
					return null;
				}
				$str = trim( $value );
				if ( $str === '' ) {
					return null;
				}
				return array( 'path' => $path, 'kind' => $kind, 'value' => $str );

			case 'image_meta':
				$meta = is_object( $value ) ? (array) $value : ( is_array( $value ) ? $value : null );
				if ( ! is_array( $meta ) ) {
					return null;
				}
				$summary = array();
				foreach ( array( 'title', 'alt', 'caption', 'description', 'filename', 'url', 'name' ) as $k ) {
					if ( isset( $meta[ $k ] ) && is_string( $meta[ $k ] ) && trim( $meta[ $k ] ) !== '' ) {
						$summary[ $k ] = $meta[ $k ];
					}
				}
				if ( empty( $summary ) ) {
					return null;
				}
				return array( 'path' => $path, 'kind' => $kind, 'value' => wp_json_encode( $summary ) );

			case 'text':
			case 'html':
				if ( ! is_string( $value ) ) {
					return null;
				}
				$str = trim( $value );
				if ( $str === '' ) {
					return null;
				}
				if ( ! preg_match( '/[A-Za-z]{2,}/', $str ) ) {
					return null;
				}
				if ( 'text' === $kind ) {
					$plain = trim( wp_strip_all_tags( $str ) );
					if ( strlen( $plain ) < 1 ) {
						return null;
					}
					return array( 'path' => $path, 'kind' => 'text', 'value' => $str );
				}
				$plain = trim( wp_strip_all_tags( $str ) );
				if ( strlen( $plain ) < 2 ) {
					return null;
				}
				return array( 'path' => $path, 'kind' => 'html', 'value' => $str );
		}

		return null;
	}

	private static function module_slug( object $node ): string {
		if ( ! isset( $node->settings ) ) {
			return '';
		}
		$slug = $node->settings->type ?? '';
		if ( '' === $slug ) {
			$slug = $node->settings->module ?? '';
		}
		return (string) $slug;
	}

	/**
	 * Best-effort label for the node so the inspector UI can show something
	 * human readable in addition to the node ID.
	 */
	private static function derive_node_label( object $node ): string {
		$type = isset( $node->type ) ? (string) $node->type : '';
		if ( 'module' === $type ) {
			$module = self::module_slug( $node ) ?: 'module';
			$candidates = array( 'heading', 'title', 'label', 'text', 'name' );
			foreach ( $candidates as $field ) {
				if ( isset( $node->settings->$field ) && is_string( $node->settings->$field ) && '' !== trim( $node->settings->$field ) ) {
					return $module . ': ' . wp_trim_words( wp_strip_all_tags( (string) $node->settings->$field ), 6, '...' );
				}
			}
			return (string) $module;
		}
		return $type ?: '(node)';
	}

	/**
	 * Climb parent links from any node up to its containing row, returning
	 * the row's node ID (or the original ID if the node IS a row).
	 */
	private static function ascend_to_row( string $node_id, array $layout, array $parent_lookup ): string {
		$current = $node_id;
		$guard   = 0;
		while ( $guard < 16 ) {
			$node = $layout[ $current ] ?? null;
			$type = is_object( $node ) ? ( $node->type ?? '' ) : '';
			if ( 'row' === $type ) {
				return $current;
			}
			$parent = $parent_lookup[ $current ] ?? '';
			if ( '' === $parent || $parent === $current ) {
				return $current;
			}
			$current = $parent;
			$guard++;
		}
		return $current;
	}

	/**
	 * Public accessor for the schema so the inspector UI can call out
	 * unrecognized module types.
	 */
	public static function known_module_types(): array {
		return array_keys( self::MODULE_SCHEMAS );
	}
}
