<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Image;

/**
 * Detects the EXIF orientation libvips will auto-apply to a source image.
 *
 * The BitmapHandler-based handlers (WebP, PNG) — unlike JpegHandler/ExifBitmapHandler — never
 * translate an EXIF Orientation tag into a width/height swap, yet vipsthumbnail auto-rotates
 * every format from EXIF by default. This lets those handlers keep their reported dimensions in
 * step with the auto-rotated output.
 */
interface OrientationDetector {

	/**
	 * @param string $srcPath Source file path.
	 * @return int The EXIF orientation (1-8). Returns 1 (no rotation) on any probe failure —
	 *   the safe default that leaves dimensions untouched.
	 */
	public function getOrientation( string $srcPath ): int;
}
