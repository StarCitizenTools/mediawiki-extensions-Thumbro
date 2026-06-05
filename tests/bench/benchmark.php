<?php
declare( strict_types=1 );

// Standalone autoloader so the CLI runs WITHOUT a MediaWiki bootstrap.
// Mirrors the extension.json TestAutoloadNamespaces 'Bench\' -> tests/bench/src/ mapping.
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'MediaWiki\\Extension\\Thumbro\\Bench\\';
	if ( !str_starts_with( $class, $prefix ) ) {
		return;
	}
	$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
	// Subdirectory names are lowercase; only the final segment (class name) is mixed-case.
	$last = array_pop( $parts );
	$rel = ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' ) . $last;
	$file = __DIR__ . '/src/' . $rel . '.php';
	if ( is_file( $file ) ) {
		require $file;
	}
} );

$args = array_slice( $argv, 1 );
if ( in_array( '--help', $args, true ) || $args === [] ) {
	fwrite( STDOUT, <<<TXT
Thumbro benchmark harness
Usage: php benchmark.php [--mime=image/gif] [--out=DIR] [--visual] [--help]
  --mime    restrict to one MIME type (default: all in corpus/manifest.json)
  --out     output directory for results.json + thumbnails (default: ./out)
  --visual  also emit the visual contact sheet for flagged cases

TXT );
	exit( 0 );
}

use MediaWiki\Extension\Thumbro\Bench\Orchestrator;
use MediaWiki\Extension\Thumbro\Bench\Reporter;

$opts = getopt( '', [ 'mime::', 'out::', 'visual', 'help' ] );
$mime = $opts['mime'] ?? null;
$outDir = $opts['out'] ?? ( getcwd() . '/out' );
if ( !is_dir( $outDir ) ) {
	mkdir( $outDir, 0777, true );
}

$manifestPath = __DIR__ . '/corpus/manifest.json';
if ( !is_file( $manifestPath ) ) {
	fwrite( STDERR, "Corpus manifest not found: $manifestPath\n" );
	exit( 1 );
}
$manifest = json_decode( (string)file_get_contents( $manifestPath ), true );
$corpus = array_map( static function ( array $e ) {
	$e['path'] = __DIR__ . '/corpus/' . $e['file'];
	return $e;
}, $manifest['files'] );

try {
	$rows = ( new Orchestrator() )->run( $corpus, is_string( $mime ) ? $mime : null, $outDir );
} catch ( \RuntimeException $ex ) {
	fwrite( STDERR, 'ERROR: ' . $ex->getMessage() . "\n" );
	exit( 2 );
}

$reporter = new Reporter();
$reporter->printTable( $rows );
$json = $reporter->writeJson( $rows, $outDir );
fwrite( STDOUT, "\nWrote $json\n" );

if ( isset( $opts['visual'] ) ) {
	require __DIR__ . '/src/VisualReporter.php';
	( new MediaWiki\Extension\Thumbro\Bench\VisualReporter() )->write( $rows, $outDir );
	fwrite( STDOUT, "Wrote $outDir/visual/index.html\n" );
}
exit( 0 );
