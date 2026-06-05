<?php
declare( strict_types=1 );
// Regenerates the benchmark corpus into ../corpus. Requires `convert` (ImageMagick).

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

// photographic — plasma fractal mimics noisy natural-image texture
$run( 'convert -size 800x600 plasma:fractal ' . $q( "$dir/photographic.jpg" ) );

// flat graphic (hard edges, few colours)
$run( 'convert -size 800x600 xc:white -fill "#3366cc" -draw "roundrectangle 80,80 720,520 40,40" '
	. '-fill white -font DejaVu-Sans -pointsize 120 -gravity center -annotate 0 "AB" '
	. $q( "$dir/flat-graphic.png" ) );

// static sprite (small palette, 1 frame)
$run( 'convert -size 64x64 xc:none -fill "#e23" -draw "circle 32,32 32,8" -colors 16 ' . $q( "$dir/sprite.gif" ) );

// alpha PNG with transparency
$run( 'convert -size 400x400 xc:none -fill "rgba(20,140,90,0.7)" -draw "circle 200,200 200,40" '
	. $q( "$dir/alpha.png" ) );

// animated UNDER MaxAnimatedGifArea (wgMaxAnimatedGifArea = 12,500,000 px*frames):
// 120x120 x 20 frames = 288,000 px*frames  (<< 12.5M)
$run( 'convert -size 120x120 xc:white -delay 5 '
	. '\( -clone 0 -fill red   -draw "circle 30,60 30,40"  \) '
	. '\( -clone 0 -fill green -draw "circle 60,60 60,40"  \) '
	. '\( -clone 0 -fill blue  -draw "circle 90,60 90,40"  \) '
	. '\( -clone 0 -fill red   -draw "circle 30,60 30,40"  \) '
	. '\( -clone 0 -fill green -draw "circle 60,60 60,40"  \) '
	. '\( -clone 0 -fill blue  -draw "circle 90,60 90,40"  \) '
	. '\( -clone 0 -fill red   -draw "circle 30,60 30,40"  \) '
	. '\( -clone 0 -fill green -draw "circle 60,60 60,40"  \) '
	. '\( -clone 0 -fill blue  -draw "circle 90,60 90,40"  \) '
	. '\( -clone 0 -fill red   -draw "circle 30,60 30,40"  \) '
	. '\( -clone 0 -fill green -draw "circle 60,60 60,40"  \) '
	. '\( -clone 0 -fill blue  -draw "circle 90,60 90,40"  \) '
	. '\( -clone 0 -fill red   -draw "circle 30,60 30,40"  \) '
	. '\( -clone 0 -fill green -draw "circle 60,60 60,40"  \) '
	. '\( -clone 0 -fill blue  -draw "circle 90,60 90,40"  \) '
	. '\( -clone 0 -fill red   -draw "circle 30,60 30,40"  \) '
	. '\( -clone 0 -fill green -draw "circle 60,60 60,40"  \) '
	. '\( -clone 0 -fill blue  -draw "circle 90,60 90,40"  \) '
	. '\( -clone 0 -fill red   -draw "circle 30,60 30,40"  \) '
	. '\( -clone 0 -fill blue  -draw "circle 90,60 90,40"  \) '
	. '-loop 0 ' . $q( "$dir/anim-small.gif" ) );

// animated OVER MaxAnimatedGifArea: 700x700 x 30 frames = 14,700,000 px*frames (> 12.5M)
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
$run( 'convert -size 16x16 plasma:fractal ' . $q( "$dir/tiny.png" ) );
$run( 'convert -size 4000x3000 plasma:fractal ' . $q( "$dir/huge.jpg" ) );

echo "corpus regenerated in $dir\n";
