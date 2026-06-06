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

	/**
	 * Fixed opaque background used to flatten transparent frames before scoring.
	 * SSIMULACRA2 compares RGB only, so alpha must be composited away; both the
	 * candidate and its reference are flattened over THIS SAME colour so the
	 * comparison stays fair. Alpha-channel fidelity itself is not scored.
	 */
	private const SCORE_BACKGROUND = '#808080';

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
		// Flatten transparency away first (see SCORE_BACKGROUND): without this,
		// transparent content yields garbage/negative scores. Opaque images are
		// unaffected because compositing leaves their visible RGB unchanged.
		// CLI: ssimulacra2_rs <original.png> <distorted.png>  →  bare float on stdout.
		$proc = Subprocess::run( [ $bin, self::flatten( $ref ), self::flatten( $candidate ) ] );
		if ( !$proc->ok() ) {
			throw new RuntimeException( 'ssimulacra2 failed: ' . $proc->stderr );
		}
		return self::parseScore( $proc->stdout );
	}

	/**
	 * Composite $png over SCORE_BACKGROUND and return the flattened copy's path.
	 * Lossless for opaque inputs (no transparent pixels to fill).
	 */
	private static function flatten( string $png ): string {
		$convert = ToolLocator::require( 'convert', 'imagemagick' );
		$flat = preg_replace( '/\.png$/', '_flat.png', $png );
		if ( $flat === null || $flat === $png ) {
			$flat = $png . '_flat.png';
		}
		$proc = Subprocess::run(
			[ $convert, $png, '-background', self::SCORE_BACKGROUND, '-alpha', 'remove', '-alpha', 'off', $flat ]
		);
		if ( !$proc->ok() || !is_file( $flat ) ) {
			throw new RuntimeException( 'flatten failed for ' . $png . ': ' . $proc->stderr );
		}
		return $flat;
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
	 * Extract candidate frames to full-canvas PNGs. Single frame via convert [0]; animated GIF
	 * via convert -coalesce; animated WebP via libvips, page by page.
	 *
	 * The tool split matters: ImageMagick's -coalesce does NOT correctly reconstruct animated
	 * WebP whose frames are stored as optimised partial/disposed regions (gif2webp output), so
	 * the extracted PNGs come out mostly transparent and the metric craters (negative scores).
	 * libvips's WebP loader composites each page to the full canvas, so it is used for WebP.
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
		if ( self::isWebp( $candidate ) ) {
			return self::extractWebpFrames( $candidate, $destDir );
		}
		Subprocess::run( [ $convert, $candidate, '-coalesce', '+repage', $destDir . '/cand_%03d.png' ] );
		$frames = glob( $destDir . '/cand_*.png' ) ?: [];
		sort( $frames );
		if ( $frames === [] ) {
			throw new RuntimeException( 'No candidate frames extracted from ' . $candidate );
		}
		return $frames;
	}

	/**
	 * Extract every page of an animated WebP to a full-canvas PNG via libvips (see
	 * {@see self::extractFrames()} for why ImageMagick can't be used here).
	 *
	 * @return string[] frame PNG paths, index-ordered
	 */
	private static function extractWebpFrames( string $candidate, string $destDir ): array {
		$vips = ToolLocator::require( 'vips', 'libvips-tools' );
		$pages = self::webpPageCount( $candidate );
		$frames = [];
		for ( $i = 0; $i < $pages; $i++ ) {
			$dst = sprintf( '%s/cand_%03d.png', $destDir, $i );
			// `vips copy file.webp[page=N]` yields the composited full frame N.
			$proc = Subprocess::run( [ $vips, 'copy', $candidate . "[page=$i]", $dst ] );
			if ( !$proc->ok() || !is_file( $dst ) ) {
				throw new RuntimeException( "vips failed extracting WebP frame $i from $candidate: " . $proc->stderr );
			}
			$frames[] = $dst;
		}
		if ( $frames === [] ) {
			throw new RuntimeException( 'No candidate frames extracted from ' . $candidate );
		}
		return $frames;
	}

	/** Number of pages (frames) in an image, via vipsheader's n-pages field (>= 1). */
	private static function webpPageCount( string $path ): int {
		$vipsheader = ToolLocator::require( 'vipsheader', 'libvips-tools' );
		$proc = Subprocess::run( [ $vipsheader, '-f', 'n-pages', $path ] );
		return $proc->ok() ? max( 1, (int)trim( $proc->stdout ) ) : 1;
	}

	/** True if the file is a RIFF/WebP container (by magic bytes, not extension). */
	public static function isWebp( string $path ): bool {
		$header = (string)file_get_contents( $path, false, null, 0, 12 );
		return strlen( $header ) >= 12
			&& substr( $header, 0, 4 ) === 'RIFF'
			&& substr( $header, 8, 4 ) === 'WEBP';
	}
}
