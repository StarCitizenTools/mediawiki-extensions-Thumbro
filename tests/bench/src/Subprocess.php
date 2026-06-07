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
		if ( $statFile === false ) {
			throw new RuntimeException( 'tempnam failed: cannot create temp stat file' );
		}
		$full = array_merge( [ self::$timeBin, '-v', '-o', $statFile ], $cmd );

		$descriptors = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		// Monotonic clock (hrtime), not microtime: microtime reads CLOCK_REALTIME, which can
		// step backward under an NTP adjustment (and notably on WSL2, where the wall clock
		// jumps), producing a nonsensical negative wall time. hrtime is immune to clock steps.
		$t0 = hrtime( true );
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open
		$proc = proc_open( $full, $descriptors, $pipes );
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.is_resource
		if ( !is_resource( $proc ) ) {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			@unlink( $statFile );
			throw new RuntimeException( 'proc_open failed for: ' . implode( ' ', $cmd ) );
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$stdout = '';
		$stderr = '';
		while ( $pipes[1] !== null || $pipes[2] !== null ) {
			$read = array_values( array_filter( [ $pipes[1], $pipes[2] ] ) );
			if ( $read === [] ) {
				break;
			}
			$write = [];
			$except = [];
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- @ suppresses signal-interrupted EINTR warnings
			if ( @stream_select( $read, $write, $except, 5 ) === false ) {
				break;
			}
			if ( $pipes[1] !== null ) {
				$chunk = fread( $pipes[1], 8192 );
				$stdout .= ( $chunk !== false ) ? $chunk : '';
				if ( feof( $pipes[1] ) ) {
					fclose( $pipes[1] );
					$pipes[1] = null;
				}
			}
			if ( $pipes[2] !== null ) {
				$chunk = fread( $pipes[2], 8192 );
				$stderr .= ( $chunk !== false ) ? $chunk : '';
				if ( feof( $pipes[2] ) ) {
					fclose( $pipes[2] );
					$pipes[2] = null;
				}
			}
		}
		// Close any pipe still open (e.g. stream_select aborted).
		foreach ( [ 1, 2 ] as $i ) {
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.is_resource
			if ( isset( $pipes[$i] ) && is_resource( $pipes[$i] ) ) {
				fclose( $pipes[$i] );
			}
		}
		$exit = proc_close( $proc );
		// hrtime returns nanoseconds; convert to milliseconds.
		$wallMs = ( hrtime( true ) - $t0 ) / 1e6;

		// Cast guards false from file_get_contents on an unreadable file; parseTimeStat then returns null.
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
