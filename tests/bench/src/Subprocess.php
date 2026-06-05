<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

use RuntimeException;

class Subprocess {
	/** @var string Path to GNU time; overridable for environments that place it elsewhere. */
	public static string $timeBin = '/usr/bin/time';

	/**
	 * Run a command under `/usr/bin/time -v`, capturing wall time (PHP-side, high
	 * resolution) and peak RSS (from time's report written to a temp stat file).
	 *
	 * @param string[] $cmd Argv array (already shell-safe; passed without a shell)
	 */
	public static function run( array $cmd ): ProcResult {
		if ( !is_executable( self::$timeBin ) ) {
			throw new RuntimeException(
				"GNU time not found at " . self::$timeBin . " (apt-get install time)"
			);
		}
		$statFile = tempnam( sys_get_temp_dir(), 'thumbro_time_' );
		$full = array_merge( [ self::$timeBin, '-v', '-o', $statFile ], $cmd );

		$descriptors = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$t0 = microtime( true );
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open
		$proc = proc_open( $full, $descriptors, $pipes );
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.is_resource
		if ( !is_resource( $proc ) ) {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			@unlink( $statFile );
			throw new RuntimeException( 'proc_open failed for: ' . implode( ' ', $cmd ) );
		}
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $proc );
		$wallMs = ( microtime( true ) - $t0 ) * 1000;

		$peak = is_file( $statFile ) ? self::parseTimeStat( (string)file_get_contents( $statFile ) ) : null;
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $statFile );

		return new ProcResult( $exit, (string)$stdout, (string)$stderr, $wallMs, $peak );
	}

	/** Extract "Maximum resident set size (kbytes): N" from a GNU time -v report. */
	public static function parseTimeStat( string $report ): ?int {
		if ( preg_match( '/Maximum resident set size \(kbytes\):\s*(\d+)/', $report, $m ) ) {
			return (int)$m[1];
		}
		return null;
	}
}
