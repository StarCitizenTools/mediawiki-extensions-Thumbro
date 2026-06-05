<?php
declare( strict_types=1 );

// Standalone autoloader so the CLI runs WITHOUT a MediaWiki bootstrap.
// Mirrors the extension.json TestAutoloadNamespaces 'Bench\' -> tests/bench/src/ mapping.
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'MediaWiki\\Extension\\Thumbro\\Bench\\';
	if ( !str_starts_with( $class, $prefix ) ) {
		return;
	}
	$rel = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
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

fwrite( STDERR, "Not yet implemented; see plan Task 8.\n" );
exit( 1 );
