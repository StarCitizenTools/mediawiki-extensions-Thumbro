<?php
declare( strict_types=1 );
// Regenerates the STRESS-tier benchmark fixtures into ../corpus. Requires `convert` (ImageMagick).
//
// Scope: this generates only the synthetic stress fixtures (pathologies and size extremes),
// where precise control over frame count / transparency / area is needed to hit thresholds.
// The REPRESENTATIVE tier is real, committed, freely-licensed images that are curated by hand,
// NOT generated here — see corpus/CREDITS.md and corpus/PROVENANCE.md.

$dir = dirname( __DIR__ ) . '/corpus';
// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
@mkdir( $dir, 0777, true );

$run = static function ( string $cmd ): void {
	// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, MediaWiki.Usage.ForbiddenFunctions.exec
	exec( $cmd . ' 2>&1', $o, $c );
	if ( $c !== 0 ) {
		fwrite( STDERR, "FAILED: $cmd\n" . implode( "\n", $o ) . "\n" );
		exit( 1 );
	}
};
// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
$q = static fn ( string $s ) => escapeshellarg( $s );

// STRESS: large animated GIF (700x700 x 30 frames = 14,700,000 px*frames) — exercises the
// ImageMagick -coalesce memory blow-up (full canvas held per frame) vs Thumbro's flat profile.
// Build the frame list in PHP to avoid shell arithmetic / for-loop portability issues.
$frameParts = [];
$colors = [ 'red', 'blue', 'green', 'orange', 'purple', 'cyan' ];
for ( $i = 1; $i <= 30; $i++ ) {
	$cx = 20 * $i;
	$color = $colors[ ( $i - 1 ) % count( $colors ) ];
	$frameParts[] = '\\( -clone 0 -fill ' . $color . ' -draw "circle ' . $cx . ',350 ' . $cx . ',300" \\)';
}
$frameArgs = implode( ' ', $frameParts );
$run( 'convert -size 700x700 xc:white -delay 5 '
	. $frameArgs
	. ' -loop 0 ' . $q( "$dir/anim-large.gif" ) );

// size extremes
// tiny.png is a perf/size-extreme fixture (16x16), not a quality-representativeness one;
// near-no-downscale means its perceptual score is always ~100 regardless of content.
$run( 'convert -size 16x16 plasma:fractal ' . $q( "$dir/tiny.png" ) );
// huge.jpg — same structured approach as photographic.jpg scaled to 4000x3000
$run( 'convert -size 4000x3000 gradient:skyblue-navy '
	. '\( -size 4000x3000 xc:none '
	. '-fill gold -draw "circle 1100,1000 1100,450" '
	. '-fill crimson -draw "roundrectangle 2400,600 3700,1800 150,150" '
	. '-fill white -draw "circle 3000,2300 3000,2000" \) '
	. '-composite ' . $q( "$dir/huge.jpg" ) );

// anim-transparent: transparent animated GIF — regression fixture for the libvips animated-WebP
// alpha pathology.  libvips's VP8 encoder handles per-frame alpha very inefficiently, so thumbnailing
// a transparent animated GIF with vips blows up the output (e.g. Spamton @84px: 1.91 MB transparent
// vs 786 KB flattened).  This fixture reproduces that pathology: a detailed opaque sprite on a
// TRANSPARENT background, rotating + translating each frame so the alpha boundary moves every frame.
// Design: 200×200, 48 frames, binary GIF transparency.  NO fonts/text — avoids font-dependency.
// (48 frames is well past the ~15 needed to reproduce the pathology, but keeps the SSIMULACRA2
// per-frame metric run tractable — 120 frames pushed the gif benchmark past its timeout.)
//
// Frame generation uses proc_open to avoid exec() shell-string-length limits.
// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open
$runArray = static function ( array $args ) use ( $dir ): void {
	$descriptors = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
	// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open
	$proc = proc_open( $args, $descriptors, $pipes );
	// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.is_resource
	if ( !is_resource( $proc ) ) {
		fwrite( STDERR, "proc_open failed: " . implode( ' ', $args ) . "\n" );
		exit( 1 );
	}
	fclose( $pipes[1] );
	$stderr = (string)stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	if ( $code !== 0 ) {
		fwrite( STDERR, "FAILED: " . implode( ' ', $args ) . "\n$stderr\n" );
		exit( 1 );
	}
};

$transpFrameDir = sys_get_temp_dir() . '/thumbro_corpus_transp_' . getmypid();
// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
@mkdir( $transpFrameDir, 0777, true );

// Build sprite base once: detailed opaque shapes on a transparent canvas.
$sprFile = $transpFrameDir . '/sprite.png';
$runArray( [ 'convert', '-size', '180x180', 'xc:none',
	'-fill', 'yellow', '-draw', 'polygon 90,20 150,150 30,150',
	'-fill', 'red', '-draw', 'circle 90,95 90,55',
	'-fill', 'cyan', '-draw', 'rectangle 55,80 125,115',
	'-fill', '#33cc66', '-draw', 'circle 60,60 60,40',
	'-fill', 'magenta', '-draw', 'roundrectangle 110,100 150,140 8,8',
	$sprFile,
] );

// Generate each frame: sprite rotated + translated on a transparent 200×200 canvas.
$transpFrames = 48;
$transpFrameFiles = [];
for ( $i = 0; $i < $transpFrames; $i++ ) {
	$ang = $i * 3;
	$dx = ( $i * 5 ) % 60 - 30;
	$frameFile = $transpFrameDir . '/frame_' . sprintf( '%04d', $i ) . '.gif';
	$transpFrameFiles[] = $frameFile;
	$geom = ( $dx >= 0 ? '+' : '' ) . $dx . '+0';
	$runArray( [ 'convert', '-size', '200x200', 'xc:none',
		$sprFile, '-gravity', 'center', '-geometry', $geom, '-composite',
		'-distort', 'SRT', (string)$ang,
		'-channel', 'A', '-threshold', '50%', '+channel',
		$frameFile,
	] );
}

// Combine frames into an animated GIF, preserving transparency between frames.
$combineCmd = [ 'convert', '-dispose', 'background', '-delay', '4', '-loop', '0' ];
foreach ( $transpFrameFiles as $f ) {
	$combineCmd[] = $f;
}
$combineCmd[] = "$dir/anim-transparent.gif";
$runArray( $combineCmd );

// Clean up temp frame files.
foreach ( $transpFrameFiles as $f ) {
	// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
	@unlink( $f );
}
// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
@unlink( $sprFile );
// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
@rmdir( $transpFrameDir );

// STRESS: transparent animated WebP — derived from the transparent GIF above. The WebP-input
// animation path has no gif2webp escape, so libvips re-encodes per-frame alpha (inefficient);
// this fixture exercises that blow-up (animate-all policy) against the hard safety caps.
$runArray( [ 'vips', 'copy', "$dir/anim-transparent.gif[n=-1]", "$dir/anim-transparent.webp" ] );

echo "corpus regenerated in $dir\n";
