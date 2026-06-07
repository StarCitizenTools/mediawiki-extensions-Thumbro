<?php
declare( strict_types=1 );

/**
 * cwebp parameter sweep (research tool, NOT the acceptance gate).
 *
 * For each static raster representative fixture, resizes with vipsthumbnail to a
 * lossless PNG intermediate, then encodes with cwebp -q <Q> -m 6, scores every cell
 * with SSIMULACRA2 against the same lossless vips reference the gate uses, and prints a
 * size-vs-quality table so the knee can be read off by eye.
 *
 * cwebp cannot resize or animate, so only static raster representative fixtures are
 * swept. Produces NO verdict: selection is a human decision recorded in docs/encoding/.
 *
 * Encoding pipeline per cell:
 *   1. vipsthumbnail <src> --size <w>x100000 -o <tmp>.png   (lossless, carries alpha)
 *   2. cwebp -q <Q> -m 6 <tmp>.png -o <out>.webp
 *
 * Reuses the gate's scoring plumbing (Reference, Ssimulacra2, ImageDims, Subprocess,
 * ToolLocator) so a sweep score means exactly what a gate score means.
 *
 * Usage: php tests/bench/bin/sweep-cwebp.php [--out=DIR]
 */

// Standalone autoloader, identical to benchmark.php (no MediaWiki bootstrap).
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'MediaWiki\\Extension\\Thumbro\\Bench\\';
	if ( !str_starts_with( $class, $prefix ) ) {
		return;
	}
	$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
	$last = array_pop( $parts );
	$rel = ( $parts ? implode( '/', $parts ) . '/' : '' ) . $last;
	$file = __DIR__ . '/../src/' . $rel . '.php';
	if ( is_file( $file ) ) {
		require $file;
	}
} );

use MediaWiki\Extension\Thumbro\Bench\ImageDims;
use MediaWiki\Extension\Thumbro\Bench\Reference;
use MediaWiki\Extension\Thumbro\Bench\Ssimulacra2;
use MediaWiki\Extension\Thumbro\Bench\Subprocess;
use MediaWiki\Extension\Thumbro\Bench\ToolLocator;

$opts = getopt( '', [ 'out::', 'help' ] );
if ( isset( $opts['help'] ) ) {
	fwrite( STDOUT, "Usage: php tests/bench/bin/sweep-cwebp.php [--out=DIR]\n" );
	exit( 0 );
}
$outDir = is_string( $opts['out'] ?? null ) ? $opts['out'] : ( getcwd() . '/sweep-out' );
if ( !is_dir( $outDir ) && !mkdir( $outDir, 0777, true ) && !is_dir( $outDir ) ) {
	fwrite( STDERR, "Cannot create out dir: $outDir\n" );
	exit( 1 );
}

$corpusDir = __DIR__ . '/../corpus';

/**
 * Static raster representative fixtures. cwebp cannot animate, so only static fixtures
 * are included. photo.webp is included as a cross-format data point (cwebp re-encoding
 * a lossy WebP source via lossless-PNG intermediate).
 */
$fixtures = [
	[ 'file' => 'photo.jpg', 'widths' => [ 180, 250, 400 ] ],
	[ 'file' => 'portrait.jpg', 'widths' => [ 180, 250, 400 ] ],
	[ 'file' => 'concept-art.jpg', 'widths' => [ 180, 250, 400 ] ],
	[ 'file' => 'logo-transparent.png', 'widths' => [ 180, 250, 400 ] ],
	[ 'file' => 'flat-graphic.png', 'widths' => [ 180, 250, 400 ] ],
	[ 'file' => 'screenshot-ui.png', 'widths' => [ 180, 250, 400 ] ],
	[ 'file' => 'screenshot-gaming.png', 'widths' => [ 180, 250, 400 ] ],
	[ 'file' => 'photo.webp', 'widths' => [ 180, 250, 400 ] ],
];

/**
 * Quality grid. Method (-m) is fixed at 6 (max encoder effort, "free" per the
 * cached-generation trade-off principle). Q values match the vips sweep grid so
 * cwebp and vips-webp results are directly comparable.
 */
$qValues = [ 60, 70, 75, 80, 84, 90 ];

$vips = ToolLocator::require( 'vipsthumbnail', 'libvips-tools' );
$cwebp = ToolLocator::require( 'cwebp', 'libwebp (webp package)' );
// Fail fast on the metric so we don't encode many thumbs then discover it's missing.
ToolLocator::require( Ssimulacra2::$bin, 'tests/bench/bin/install-ssimulacra2.sh' );

$rows = [];
$total = 0;
foreach ( $fixtures as $fx ) {
	$total += count( $fx['widths'] ) * count( $qValues );
}
$done = 0;

foreach ( $fixtures as $fx ) {
	$src = $corpusDir . '/' . $fx['file'];
	if ( !is_file( $src ) ) {
		fwrite( STDERR, "Missing fixture: $src\n" );
		continue;
	}
	// Slug includes the extension so fixtures that share a basename (photo.jpg / photo.webp)
	// don't collide on the same intermediate/cell directory and reuse each other's pixels.
	$slug = str_replace( '.', '_', $fx['file'] );
	foreach ( $fx['widths'] as $w ) {
		// Shared PNG intermediate for all Q values at this (fixture, width).
		$interDir = sprintf( '%s/%s_%d', $outDir, $slug, $w );
		if ( !is_dir( $interDir ) && !mkdir( $interDir, 0777, true ) && !is_dir( $interDir ) ) {
			fwrite( STDERR, "Cannot create $interDir\n" );
			continue;
		}
		$pngPath = $interDir . '/resized.png';

		// Step 1: resize to PNG (done once per (fixture, width); reused across all Q values).
		if ( !is_file( $pngPath ) ) {
			$resizeProc = Subprocess::run( [
				$vips, $src, '--size', $w . 'x100000', '-o', $pngPath . '[strip=true]',
			] );
			if ( !$resizeProc->ok() || !is_file( $pngPath ) ) {
				fwrite( STDERR, "  vips resize FAILED $fx[file]@$w: $resizeProc->stderr\n" );
				continue;
			}
		}

		foreach ( $qValues as $q ) {
			$label = 'Q=' . $q . ',m=6';
			$done++;
			$cellDir = sprintf( '%s/%s_%d/%s', $outDir, $slug, $w, $label );
			if ( !is_dir( $cellDir ) && !mkdir( $cellDir, 0777, true ) && !is_dir( $cellDir ) ) {
				fwrite( STDERR, "Cannot create $cellDir\n" );
				continue;
			}
			$dst = $cellDir . '/thumb.webp';

			// Step 2: encode with cwebp.
			$encProc = Subprocess::run( [ $cwebp, '-q', (string)$q, '-m', '6', $pngPath, '-o', $dst ] );
			if ( !$encProc->ok() || !is_file( $dst ) ) {
				fwrite( STDERR, "  [$done/$total] cwebp FAILED $fx[file]@$w $label: $encProc->stderr\n" );
				continue;
			}
			$bytes = (int)filesize( $dst );

			// Score exactly as the gate does: lossless vips reference at the produced dims,
			// candidate extracted to full-canvas PNG, SSIMULACRA2 over the pair.
			[ $tw, $th ] = ImageDims::of( $dst );
			$refDir = $cellDir . '/ref';
			$candDir = $cellDir . '/cand';
			foreach ( [ $refDir, $candDir ] as $d ) {
				if ( !is_dir( $d ) ) {
					mkdir( $d, 0777, true );
				}
			}
			$score = null;
			try {
				$ref = Reference::forStatic( $src, $tw, $th, $refDir );
				$cand = Ssimulacra2::extractFrames( $dst, 1, $candDir );
				$score = Ssimulacra2::score( [ $ref ], $cand )->mean;
			} catch ( \RuntimeException $e ) {
				fwrite( STDERR, "  scoring failed $fx[file]@$w $label: {$e->getMessage()}\n" );
			}

			$rows[] = [
				'fixture' => $fx['file'],
				'width' => $w,
				'config' => $label,
				'bytes' => $bytes,
				'ssimulacra2' => $score !== null ? round( $score, 2 ) : null,
				'wall_ms' => round( $encProc->wallMs, 1 ),
				'peak_rss_kb' => $encProc->peakRssKb,
			];
			fwrite( STDOUT, sprintf(
				"[%d/%d] %-18s @%-4d %-14s  %8d B  s2=%-6s  %6.0fms\n",
				$done, $total, $fx['file'], $w, $label, $bytes,
				$score !== null ? number_format( $score, 2 ) : 'n/a', $encProc->wallMs
			) );
		}
	}
}

file_put_contents( $outDir . '/sweep-results.json', json_encode( $rows, JSON_PRETTY_PRINT ) . "\n" );

// Per (fixture,width) table sorted by size ascending — the knee is read top-to-bottom.
$groups = [];
foreach ( $rows as $r ) {
	$groups[$r['fixture'] . ' @' . $r['width'] . 'px'][] = $r;
}
fwrite( STDOUT, "\n================ SIZE-SORTED (knee read top-down) ================\n" );
foreach ( $groups as $title => $grp ) {
	usort( $grp, static fn ( $a, $b ) => $a['bytes'] <=> $b['bytes'] );
	fwrite( STDOUT, "\n## $title\n" );
	fwrite( STDOUT, sprintf( "  %-14s %9s %8s %9s %10s\n", 'config', 'bytes', 's2', 'wall_ms', 'rss_kb' ) );
	foreach ( $grp as $r ) {
		fwrite( STDOUT, sprintf(
			"  %-14s %9d %8s %9s %10s\n",
			$r['config'], $r['bytes'],
			$r['ssimulacra2'] !== null ? number_format( $r['ssimulacra2'], 2 ) : 'n/a',
			number_format( $r['wall_ms'], 0 ),
			$r['peak_rss_kb'] !== null ? number_format( $r['peak_rss_kb'] ) : 'n/a'
		) );
	}
}
fwrite( STDOUT, "\nWrote {$outDir}/sweep-results.json\n" );
