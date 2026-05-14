<?php
/**
 * Smoke-test the Neighborhoods_Applier template extractor logic without
 * needing WordPress, by reimplementing the relevant pure functions and
 * running them against the sample HTML the user provided.
 *
 * Run with: php scripts/test-template-extract.php
 */

// Inline copies of the core methods (so we can test without WP loaded).

function first_element_child( DOMNode $parent ): ?DOMElement {
	foreach ( $parent->childNodes as $child ) {
		if ( $child instanceof DOMElement ) {
			return $child;
		}
	}
	return null;
}

function replace_largest_text_node( DOMNode $node, string $replacement ): bool {
	$best     = null;
	$best_len = 0;

	$walk = function ( DOMNode $n ) use ( &$walk, &$best, &$best_len ): void {
		if ( $n instanceof DOMText ) {
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

function extract_template( string $html ): ?array {
	libxml_use_internal_errors( true );
	$dom = new DOMDocument( '1.0', 'UTF-8' );
	$ok  = $dom->loadHTML(
		'<?xml encoding="UTF-8"?><div id="ehbp-root">' . $html . '</div>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	libxml_clear_errors();
	if ( ! $ok ) {
		return null;
	}

	$root = $dom->getElementById( 'ehbp-root' );
	if ( ! $root ) return null;

	$wrapper = first_element_child( $root );
	if ( ! $wrapper ) return null;

	$item = first_element_child( $wrapper );
	if ( ! $item ) return null;

	$item_clone = $item->cloneNode( true );
	if ( ! replace_largest_text_node( $item_clone, '{{NAME}}' ) ) {
		return null;
	}
	$item_html = trim( (string) $dom->saveHTML( $item_clone ) );

	$wrapper_clone = $wrapper->cloneNode( false );
	$wrapper_clone->appendChild( $dom->createTextNode( '{{ITEMS}}' ) );
	$wrapper_html = trim( (string) $dom->saveHTML( $wrapper_clone ) );

	return array(
		'wrapper_html' => $wrapper_html,
		'item_html'    => $item_html,
	);
}

function replace_city_token( string $haystack, string $source, string $target ): string {
	if ( '' === $source ) return $haystack;
	$result = preg_replace( '/\b' . preg_quote( $source, '/' ) . '\b/i', $target, $haystack );
	return null === $result ? $haystack : $result;
}

function render_from_template( array $template, array $names, string $state, string $source_tok, string $target_tok ): string {
	$wrapper = (string) $template['wrapper_html'];
	if ( '' !== $source_tok && '' !== $target_tok && $source_tok !== $target_tok ) {
		$wrapper = replace_city_token( $wrapper, $source_tok, $target_tok );
	}
	$items = array();
	foreach ( $names as $n ) {
		$entry   = $n . ', ' . $state;
		$items[] = str_replace( '{{NAME}}', htmlspecialchars( $entry, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), (string) $template['item_html'] );
	}
	$inner = "\n" . implode( "\n", $items ) . "\n";
	return str_replace( '{{ITEMS}}', $inner, $wrapper );
}

// --- Test ----------------------------------------------------------------

$source_html = <<<HTML
<ul class="location-info orlando-florida">
    <li class="location-info-list-item address">
        <span>Downtown Orlando, FL</span>
    </li>
    <li class="location-info-list-item address">
        <span>International Drive, FL</span>
    </li>
    <li class="location-info-list-item address">
        <span>Lake Nona, FL</span>
    </li>
</ul>
HTML;

echo "--- Source HTML ---\n$source_html\n\n";

$template = extract_template( $source_html );
if ( null === $template ) {
	fwrite( STDERR, "FAILED: template extraction returned null\n" );
	exit( 1 );
}

echo "--- Extracted wrapper_html ---\n{$template['wrapper_html']}\n\n";
echo "--- Extracted item_html ---\n{$template['item_html']}\n\n";

$rendered = render_from_template(
	$template,
	array( 'Town Center', 'North Village', "O'Brien Park", 'Lake Evalyn' ),
	'FL',
	'orlando',
	'celebration'
);

echo "--- Rendered for Celebration ---\n$rendered\n\n";

// Sanity assertions
$ok = true;
if ( strpos( $rendered, 'celebration-florida' ) === false ) {
	echo "FAIL: wrapper class not swapped to celebration-florida\n"; $ok = false;
}
if ( strpos( $rendered, 'orlando-florida' ) !== false ) {
	echo "FAIL: still contains orlando-florida\n"; $ok = false;
}
if ( strpos( $rendered, 'Town Center, FL' ) === false ) {
	echo "FAIL: missing Town Center, FL\n"; $ok = false;
}
if ( strpos( $rendered, 'Lake Evalyn, FL' ) === false ) {
	echo "FAIL: missing Lake Evalyn, FL\n"; $ok = false;
}
if ( substr_count( $rendered, '<li class="location-info-list-item address">' ) !== 4 ) {
	echo "FAIL: expected 4 <li> entries\n"; $ok = false;
}
if ( strpos( $rendered, 'O&#039;Brien Park, FL' ) === false && strpos( $rendered, 'O&apos;Brien Park, FL' ) === false ) {
	echo "FAIL: O'Brien wasn't HTML-escaped\n"; $ok = false;
}

echo $ok ? "\nAll assertions passed.\n" : "\nSome assertions FAILED.\n";
exit( $ok ? 0 : 1 );
