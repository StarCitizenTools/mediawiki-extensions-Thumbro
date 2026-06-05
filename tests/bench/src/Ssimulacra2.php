<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

use RuntimeException;

class Ssimulacra2 {
	/**
	 * Binary providing SSIMULACRA2 (v2, 0..100). Installed by
	 * tests/bench/bin/install-ssimulacra2.sh as a Python wrapper.
	 *
	 * CLI: ssimulacra2_rs <original.png> <distorted.png>
	 * Output: bare float, e.g. "87.35000000"
	 */
	public static string $bin = 'ssimulacra2_rs';

	/** Parse the numeric score from the tool's stdout (a bare float, or "Score: N"). */
	public static function parseScore( string $stdout ): float {
		if ( preg_match( '/-?\d+(?:\.\d+)?/', $stdout, $m ) ) {
			return (float)$m[0];
		}
		throw new RuntimeException( "Unparseable ssimulacra2 output: $stdout" );
	}

	/**
	 * @param float[] $scores
	 * @return Quality
	 */
	public static function aggregate( array $scores ): Quality {
		if ( $scores === [] ) {
			throw new RuntimeException( 'No frame scores to aggregate' );
		}
		$mean = array_sum( $scores ) / count( $scores );
		return new Quality( round( $mean, 2 ), min( $scores ), count( $scores ) );
	}

	/** Score one reference PNG vs one candidate PNG. */
	public static function scorePair( string $ref, string $candidate ): float {
		$bin = ToolLocator::require( self::$bin, 'tests/bench/bin/install-ssimulacra2.sh' );
		// CLI: ssimulacra2_rs <original.png> <distorted.png>  →  bare float on stdout.
		$proc = Subprocess::run( [ $bin, $ref, $candidate ] );
		if ( !$proc->ok() ) {
			throw new RuntimeException( 'ssimulacra2 failed: ' . $proc->stderr );
		}
		return self::parseScore( $proc->stdout );
	}

	/**
	 * @param string[] $refFrames
	 * @param string[] $candFrames
	 * @return Quality
	 */
	public static function score( array $refFrames, array $candFrames ): Quality {
		if ( count( $refFrames ) !== count( $candFrames ) ) {
			throw new RuntimeException(
				'Frame count mismatch: ref=' . count( $refFrames ) . ' cand=' . count( $candFrames )
			);
		}
		$scores = [];
		foreach ( $refFrames as $i => $ref ) {
			$scores[] = self::scorePair( $ref, $candFrames[$i] );
		}
		return self::aggregate( $scores );
	}

	/**
	 * Extract candidate frames to PNGs. Single frame via convert [0]; animated WebP and
	 * animated GIF both via convert -coalesce (no anim_dump dependency).
	 *
	 * @param string $candidate Path to candidate image
	 * @param int $frameCount Expected number of frames (1 = static)
	 * @param string $destDir Directory to write extracted PNGs into
	 * @return string[] frame PNG paths
	 */
	public static function extractFrames( string $candidate, int $frameCount, string $destDir ): array {
		$convert = ToolLocator::require( 'convert', 'imagemagick' );
		// `-coalesce +repage` restores each frame to the full logical canvas. Optimised
		// GIFs (e.g. an ImageMagick baseline written with `-layers optimize`) store frames
		// as minimal sub-rectangles with an offset; without this the extracted PNG would be
		// the sub-rectangle size and mismatch the full-canvas reference, crashing the metric.
		if ( $frameCount <= 1 ) {
			$dst = $destDir . '/cand_000.png';
			Subprocess::run( [ $convert, $candidate . '[0]', '-coalesce', '+repage', $dst ] );
			return [ $dst ];
		}
		Subprocess::run( [ $convert, $candidate, '-coalesce', '+repage', $destDir . '/cand_%03d.png' ] );
		$frames = glob( $destDir . '/cand_*.png' ) ?: [];
		sort( $frames );
		if ( $frames === [] ) {
			throw new RuntimeException( 'No candidate frames extracted from ' . $candidate );
		}
		return $frames;
	}
}
