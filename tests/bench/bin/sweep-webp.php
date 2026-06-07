<?php
declare( strict_types=1 );

/**
 * WebP -> WebP parameter sweep (research tool, NOT the acceptance gate).
 *
 * Encodes each WebP fixture across a grid of webpsave options, scores every cell with
 * SSIMULACRA2 against the same lossless vips reference the gate uses, and prints a
 * size-vs-quality table so the knee can be read off by eye. Produces NO verdict: selection
 * is a human decision recorded in docs/encoding/image-webp.md, after which the chosen profile
 * is written into extension.json and proven with benchmark.php.
 *
 * Static fixtures get the full grid; the animated fixture gets a coarse Q-only sweep (the
 * per-frame metric is ~0.6 s/frame, so the full grid on a 44-frame animation is intractable).
 *
 * Reuses the gate's scoring plumbing (Reference, Ssimulacra2, ImageDims, Subprocess,
 * ToolLocator) so a sweep score means exactly what a gate score means.
 *
 * Usage: php tests/bench/bin/sweep-webp.php [--out=DIR]
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
	fwrite( STDOUT, "Usage: php tests/bench/bin/sweep-webp.php [--out=DIR]\n" );
	exit( 0 );
}
$outDir = is_string( $opts['out'] ?? null ) ? $opts['out'] : ( getcwd() . '/sweep-out' );
if ( !is_dir( $outDir ) && !mkdir( $outDir, 0777, true ) && !is_dir( $outDir ) ) {
	fwrite( STDERR, "Cannot create out dir: $outDir\n" );
	exit( 1 );
}

$corpusDir = __DIR__ . '/../corpus';

/**
 * Fixtures. 'frames' drives per-frame scoring (1 = static). The animated fixture carries a
 * 'coarse' flag so it gets the reduced Q-only grid.
 */
$fixtures = [
	[ 'file' => 'photo.webp', 'widths' => [ 180, 250, 400 ], 'frames' => 1, 'coarse' => false ],
	[ 'file' => 'anim.webp', 'widths' => [ 250 ], 'frames' => 44, 'coarse' => true ],
];

/**
 * Grids. strip is fixed true. effort 4 is the vips default; 6 is the max ("free" per the
 * cached-generation trade-off principle). The full grid is for static fixtures; the coarse
 * grid (Q only, effort 6, ss off) keeps the animated sweep tractable.
 */
$fullGrid = [
	'Q' => [ 60, 70, 75, 80, 84, 90 ],
	'smart_subsample' => [ false, true ],
	'effort' => [ 4, 6 ],
];
$coarseGrid = [
	'Q' => [ 60, 70, 75, 80, 90 ],
	'smart_subsample' => [ false ],
	'effort' => [ 6 ],
];

// Production profile today (image/webp block): Q=90, smart_subsample=true, default effort (4).
$prodKey = 'Q=90,ss=1,effort=4';

/** Build the vipsthumbnail webpsave "[k=v,...]" suffix and a short label for a config. */
$suffixFor = static function ( array $cfg ): array {
	$parts = [ 'strip=true', 'Q=' . $cfg['Q'] ];
	$parts[] = 'smart_subsample=' . ( $cfg['smart_subsample'] ? 'true' : 'false' );
	$parts[] = 'effort=' . $cfg['effort'];
	$label = sprintf(
		'Q=%d,ss=%d,effort=%d',
		$cfg['Q'], $cfg['smart_subsample'] ? 1 : 0, $cfg['effort']
	);
	return [ '[' . implode( ',', $parts ) . ']', $label ];
};

/** Enumerate the cartesian product of a grid. */
$expand = static function ( array $grid ): array {
	$configs = [ [] ];
	foreach ( $grid as $key => $values ) {
		$next = [];
		foreach ( $configs as $partial ) {
			foreach ( $values as $v ) {
				$next[] = $partial + [ $key => $v ];
			}
		}
		$configs = $next;
	}
	return $configs;
};

$vips = ToolLocator::require( 'vipsthumbnail', 'libvips-tools' );
// Fail fast on the metric so we don't encode many thumbs then discover it's missing.
ToolLocator::require( Ssimulacra2::$bin, 'tests/bench/bin/install-ssimulacra2.sh' );

$rows = [];
foreach ( $fixtures as $fx ) {
	$src = $corpusDir . '/' . $fx['file'];
	if ( !is_file( $src ) ) {
		fwrite( STDERR, "Missing fixture: $src\n" );
		continue;
	}
	$configs = $expand( $fx['coarse'] ? $coarseGrid : $fullGrid );
	$animated = $fx['frames'] > 1;
	// Animated source: keep every frame (mirrors production's LibvipsBackend n=-1).
	$inSuffix = $animated ? '[n=-1]' : '';
	foreach ( $fx['widths'] as $w ) {
		foreach ( $configs as $cfg ) {
			[ $suffix, $label ] = $suffixFor( $cfg );
			$cellDir = sprintf( '%s/%s_%d/%s', $outDir, pathinfo( $fx['file'], PATHINFO_FILENAME ), $w, $label );
			if ( !is_dir( $cellDir ) && !mkdir( $cellDir, 0777, true ) && !is_dir( $cellDir ) ) {
				fwrite( STDERR, "Cannot create $cellDir\n" );
				continue;
			}
			$dst = $cellDir . '/thumb.webp';

			$proc = Subprocess::run( [ $vips, $src . $inSuffix, '--size', $w . 'x100000', '-o', $dst . $suffix ] );
			if ( !$proc->ok() || !is_file( $dst ) ) {
				fwrite( STDERR, "  vips FAILED $fx[file]@$w $label: $proc->stderr\n" );
				continue;
			}
			$bytes = (int)filesize( $dst );

			// Score exactly as the gate does (Orchestrator::scoreQuality): reference at the
			// produced dims, candidate extracted to full-canvas frames, SSIMULACRA2 over the pair.
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
				$candFrames = Ssimulacra2::extractFrames( $dst, $fx['frames'], $candDir );
				if ( $animated ) {
					$refFrames = Reference::forFrames( $src, $tw, $th, count( $candFrames ), $refDir );
				} else {
					$refFrames = [ Reference::forStatic( $src, $tw, $th, $refDir ) ];
				}
				$score = Ssimulacra2::score( $refFrames, $candFrames )->mean;
			} catch ( \RuntimeException $e ) {
				fwrite( STDERR, "  scoring failed $fx[file]@$w $label: {$e->getMessage()}\n" );
			}

			$rows[] = [
				'fixture' => $fx['file'],
				'width' => $w,
				'config' => $label,
				'is_prod' => $label === $prodKey,
				'bytes' => $bytes,
				'ssimulacra2' => $score !== null ? round( $score, 2 ) : null,
				'wall_ms' => round( $proc->wallMs, 1 ),
				'peak_rss_kb' => $proc->peakRssKb,
			];
			fwrite( STDOUT, sprintf(
				"%-10s @%-4d %-22s  %8d B  s2=%-6s  %6.0fms\n",
				$fx['file'], $w, $label, $bytes,
				$score !== null ? number_format( $score, 2 ) : 'n/a', $proc->wallMs
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
	fwrite( STDOUT, sprintf( "  %-22s %9s %8s %9s %10s\n", 'config', 'bytes', 's2', 'wall_ms', 'rss_kb' ) );
	foreach ( $grp as $r ) {
		fwrite( STDOUT, sprintf(
			"  %-22s %9d %8s %9s %10s%s\n",
			$r['config'], $r['bytes'],
			$r['ssimulacra2'] !== null ? number_format( $r['ssimulacra2'], 2 ) : 'n/a',
			number_format( $r['wall_ms'], 0 ),
			$r['peak_rss_kb'] !== null ? number_format( $r['peak_rss_kb'] ) : 'n/a',
			$r['is_prod'] ? '  <- current prod' : ''
		) );
	}
}
fwrite( STDOUT, "\nWrote {$outDir}/sweep-results.json\n" );
