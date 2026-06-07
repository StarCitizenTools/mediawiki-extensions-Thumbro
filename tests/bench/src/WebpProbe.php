<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

/**
 * Minimal WebP container probe. Reads only the fixed header region (no decode) to decide
 * whether a WebP file is animated, so the baselines can pick the right recipe without
 * depending on an external tool or changing the Contender interface.
 *
 * An animated WebP is an extended-format file: 'RIFF'....'WEBP' then a 'VP8X' chunk whose
 * flags byte has the animation bit (0x02) set. Simple lossy/lossless files ('VP8 '/'VP8L')
 * have no VP8X chunk and are never animated.
 *
 * (ThumbroVips has its own vipsheader-based animation check for the candidate command line;
 * this probe is self-contained so the baselines need no extra tool.)
 *
 * @see https://developers.google.com/speed/webp/docs/riff_container
 */
class WebpProbe {

	/** Animation bit in the VP8X flags byte. */
	private const ANIM_FLAG = 0x02;

	public static function isAnimated( string $path ): bool {
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- fopen warns on a missing file; the false return is handled
		$fh = @fopen( $path, 'rb' );
		if ( $fh === false ) {
			return false;
		}
		$header = (string)fread( $fh, 21 );
		fclose( $fh );
		if ( strlen( $header ) < 21 ) {
			return false;
		}
		if ( substr( $header, 0, 4 ) !== 'RIFF' || substr( $header, 8, 4 ) !== 'WEBP' ) {
			return false;
		}
		if ( substr( $header, 12, 4 ) !== 'VP8X' ) {
			// Simple format (VP8 / VP8L) — single still frame.
			return false;
		}
		// Byte 20 is the VP8X flags byte (12 fourcc + 4 size + flags).
		return ( ord( $header[20] ) & self::ANIM_FLAG ) !== 0;
	}
}
