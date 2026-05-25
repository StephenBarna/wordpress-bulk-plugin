<?php
/**
 * Smoke-test Image_Pipeline::rewrite_filename without booting WordPress.
 * Re-implements the function inline to exercise the regex behaviour
 * against the real-world filename patterns the user described.
 */

function rewrite_filename( string $filename, string $source_token, string $target_token ): string {
	if ( '' === $filename || '' === $source_token || '' === $target_token || $source_token === $target_token ) {
		return $filename;
	}
	$ext  = '';
	$base = $filename;
	if ( false !== ( $pos = strrpos( $filename, '.' ) ) ) {
		$base = substr( $filename, 0, $pos );
		$ext  = substr( $filename, $pos );
	}
	$strict = preg_replace( '/\b' . preg_quote( $source_token, '/' ) . '\b/i', $target_token, $base );
	if ( null !== $strict && $strict !== $base ) {
		return $strict . $ext;
	}
	if ( false !== stripos( $base, $source_token ) ) {
		$loose = preg_replace( '/' . preg_quote( $source_token, '/' ) . '/i', $target_token, $base );
		if ( null !== $loose && $loose !== $base ) {
			return $loose . $ext;
		}
	}
	return $filename;
}

$cases = array(
	// [ filename, source_token, target_token, expected ]
	array( '30-yard-dumpster-rental-sizes-orlando-fl.jpg', 'orlando', 'celebration',    '30-yard-dumpster-rental-sizes-celebration-fl.jpg' ),
	array( 'bulk-trash-removal-services-in-orlando-fl.jpg', 'orlando', 'celebration',   'bulk-trash-removal-services-in-celebration-fl.jpg' ),
	array( '20-yard-dumpster-orlandoflorida.jpg',           'orlando', 'celebration',   '20-yard-dumpster-celebrationflorida.jpg' ),
	array( '20-yard-orlando-fl.png',                        'orlando', 'champions-gate','20-yard-champions-gate-fl.png' ),
	array( '20-yard-orlando-fl.png',                        'orlando', 'mount-dora',    '20-yard-mount-dora-fl.png' ),
	array( 'roll-off-orlando.jpg',                          'orlando', 'celebration',   'roll-off-celebration.jpg' ),
	array( 'header-orlando.svg',                            'orlando', 'celebration',   'header-celebration.svg' ),
	// No source token -> unchanged.
	array( 'generic-photo.jpg',                             'orlando', 'celebration',   'generic-photo.jpg' ),
	// Should NOT replace inside an unrelated word like "orlandowide" if used strictly,
	// but our loose fallback still catches glued cases like "orlandoflorida". Document expected.
	array( 'pre-orlandowide-suffix.jpg',                    'orlando', 'celebration',   'pre-celebrationwide-suffix.jpg' ), // loose fallback fires
	// Same source + target -> unchanged.
	array( '20-yard-orlando-fl.jpg',                        'orlando', 'orlando',       '20-yard-orlando-fl.jpg' ),
);

$ok = true;
foreach ( $cases as $i => $c ) {
	[ $filename, $src, $tgt, $expected ] = $c;
	$got = rewrite_filename( $filename, $src, $tgt );
	$mark = $got === $expected ? 'PASS' : 'FAIL';
	if ( $got !== $expected ) {
		$ok = false;
	}
	printf(
		"[%s] %s + (%s -> %s)\n  got: %s\n  want: %s\n\n",
		$mark,
		$filename,
		$src,
		$tgt,
		$got,
		$expected
	);
}

echo $ok ? "All filename cases pass.\n" : "Some cases FAILED.\n";
exit( $ok ? 0 : 1 );
