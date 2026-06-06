<?php
declare( strict_types=1 );

/**
 * JPEG -> WebP parameter sweep (research tool, NOT the acceptance gate).
 *
 * Encodes each JPEG fixture to WebP across a grid of webpsave options, scores every
 * cell with SSIMULACRA2 against the same lossless vips reference the gate uses, and
 * prints a size-vs-quality table so the knee can be read off by eye. It produces NO
 * verdict: selection is a human decision recorded in docs/encoding/image-jpeg.md, after
 * which the chosen profile is written into extension.json and proven with benchmark.php.
 *
 * Reuses the gate's own scoring plumbing (Reference, Ssimulacra2, ImageDims, Subprocess,
 * ToolLocator) so a sweep score means exactly what a gate score means.
 *
 * Usage: php tests/bench/bin/sweep-jpeg.php [--out=DIR]
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
	fwrite( STDOUT, "Usage: php tests/bench/bin/sweep-jpeg.php [--out=DIR]\n" );
	exit( 0 );
}
$outDir = is_string( $opts['out'] ?? null ) ? $opts['out'] : ( getcwd() . '/sweep-out' );
if ( !is_dir( $outDir ) && !mkdir( $outDir, 0777, true ) && !is_dir( $outDir ) ) {
	fwrite( STDERR, "Cannot create out dir: $outDir\n" );
	exit( 1 );
}

$corpusDir = __DIR__ . '/../corpus';

/**
 * Fixtures to sweep. Representative fixtures drive knee selection; the stress fixture
 * (huge.jpg) is encoded only so its size/time/RSS can be eyeballed against the hard caps
 * — it is never part of the knee read.
 */
$fixtures = [
	[ 'file' => 'photo.jpg', 'widths' => [ 180, 250, 400 ], 'role' => 'representative' ],
	[ 'file' => 'portrait.jpg', 'widths' => [ 180, 250, 400 ], 'role' => 'representative' ],
	[ 'file' => 'concept-art.jpg', 'widths' => [ 180, 250, 400 ], 'role' => 'representative' ],
	[ 'file' => 'huge.jpg', 'widths' => [ 320 ], 'role' => 'stress' ],
];

/**
 * The grid. strip is fixed true (always drop metadata). preset 'default' is encoded by
 * omitting the key (vips default). effort 4 is the vips default; 6 is the max ("free"
 * per the cached-generation trade-off principle). vips 8.14 uses `effort` (renamed from
 * `reduction_effort` in 8.12).
 */
$grid = [
	'Q' => [ 76, 80, 82, 84, 86, 90 ],
	'smart_subsample' => [ false, true ],
	'preset' => [ 'default', 'photo' ],
	'effort' => [ 4, 6 ],
];

// Production profile today (image/jpeg falls back to the image/webp block): Q=90,
// smart_subsample=true, default effort/preset. Tag whichever grid cell matches it.
$prodKey = 'Q=90,ss=1,preset=default,effort=4';

/** Build the vipsthumbnail output "[k=v,...]" suffix and a short label for a config. */
$suffixFor = static function ( array $cfg ): array {
	$parts = [ 'strip=true', 'Q=' . $cfg['Q'] ];
	$parts[] = 'smart_subsample=' . ( $cfg['smart_subsample'] ? 'true' : 'false' );
	if ( $cfg['preset'] !== 'default' ) {
		$parts[] = 'preset=' . $cfg['preset'];
	}
	$parts[] = 'effort=' . $cfg['effort'];
	$label = sprintf(
		'Q=%d,ss=%d,preset=%s,effort=%d',
		$cfg['Q'], $cfg['smart_subsample'] ? 1 : 0, $cfg['preset'], $cfg['effort']
	);
	return [ '[' . implode( ',', $parts ) . ']', $label ];
};

$vips = ToolLocator::require( 'vipsthumbnail', 'libvips-tools' );
// Fail fast on the metric so we don't encode 200 thumbs then discover it's missing.
ToolLocator::require( Ssimulacra2::$bin, 'tests/bench/bin/install-ssimulacra2.sh' );

// Enumerate the cartesian product of the grid.
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

$rows = [];
$total = 0;
foreach ( $fixtures as $fx ) {
	$total += count( $fx['widths'] ) * count( $configs );
}
$done = 0;

foreach ( $fixtures as $fx ) {
	$src = $corpusDir . '/' . $fx['file'];
	if ( !is_file( $src ) ) {
		fwrite( STDERR, "Missing fixture: $src\n" );
		continue;
	}
	foreach ( $fx['widths'] as $w ) {
		foreach ( $configs as $cfg ) {
			[ $suffix, $label ] = $suffixFor( $cfg );
			$done++;
			$cellDir = sprintf( '%s/%s_%d/%s', $outDir, pathinfo( $fx['file'], PATHINFO_FILENAME ), $w, $label );
			if ( !is_dir( $cellDir ) && !mkdir( $cellDir, 0777, true ) && !is_dir( $cellDir ) ) {
				fwrite( STDERR, "Cannot create $cellDir\n" );
				continue;
			}
			$dst = $cellDir . '/thumb.webp';

			$proc = Subprocess::run( [ $vips, $src, '--size', $w . 'x100000', '-o', $dst . $suffix ] );
			if ( !$proc->ok() || !is_file( $dst ) ) {
				fwrite( STDERR, "  [$done/$total] vips FAILED $fx[file]@${w} $label: $proc->stderr\n" );
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
				fwrite( STDERR, "  scoring failed $fx[file]@${w} $label: {$e->getMessage()}\n" );
			}

			$rows[] = [
				'fixture' => $fx['file'],
				'role' => $fx['role'],
				'width' => $w,
				'config' => $label,
				'is_prod' => $label === $prodKey,
				'bytes' => $bytes,
				'ssimulacra2' => $score !== null ? round( $score, 2 ) : null,
				'wall_ms' => round( $proc->wallMs, 1 ),
				'peak_rss_kb' => $proc->peakRssKb,
			];
			fwrite( STDOUT, sprintf(
				"[%d/%d] %-12s @%-4d %-34s  %7d B  s2=%-6s  %5.0fms\n",
				$done, $total, $fx['file'], $w, $label, $bytes,
				$score !== null ? number_format( $score, 2 ) : 'n/a', $proc->wallMs
			) );
		}
	}
}

// Persist raw rows for later inspection / docs/encoding/image-jpeg.md.
file_put_contents( $outDir . '/sweep-results.json', json_encode( $rows, JSON_PRETTY_PRINT ) . "\n" );

// Per (fixture,width) table sorted by size ascending — the knee is read top-to-bottom:
// smaller files first, watch where SSIMULACRA2 starts dropping out of the 'high' band.
$groups = [];
foreach ( $rows as $r ) {
	$groups[$r['fixture'] . ' @' . $r['width'] . 'px (' . $r['role'] . ')'][] = $r;
}
fwrite( STDOUT, "\n================ SIZE-SORTED (knee read top-down) ================\n" );
foreach ( $groups as $title => $grp ) {
	usort( $grp, static fn ( $a, $b ) => $a['bytes'] <=> $b['bytes'] );
	fwrite( STDOUT, "\n## $title\n" );
	fwrite( STDOUT, sprintf( "  %-34s %9s %8s %9s %10s\n", 'config', 'bytes', 's2', 'wall_ms', 'rss_kb' ) );
	foreach ( $grp as $r ) {
		fwrite( STDOUT, sprintf(
			"  %-34s %9d %8s %9s %10s%s\n",
			$r['config'], $r['bytes'],
			$r['ssimulacra2'] !== null ? number_format( $r['ssimulacra2'], 2 ) : 'n/a',
			number_format( $r['wall_ms'], 0 ),
			$r['peak_rss_kb'] !== null ? number_format( $r['peak_rss_kb'] ) : 'n/a',
			$r['is_prod'] ? '  <- current prod' : ''
		) );
	}
}
fwrite( STDOUT, "\nWrote {$outDir}/sweep-results.json\n" );
