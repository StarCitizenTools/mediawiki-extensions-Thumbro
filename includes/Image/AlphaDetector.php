<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Image;

/**
 * Detects whether a source image carries an alpha channel. Used to route only transparent
 * animated GIFs to the libwebp backend (libvips's animated-WebP encoder mishandles alpha).
 */
interface AlphaDetector {

	/**
	 * @param string $srcPath Source file path.
	 * @return bool True if the image reports an alpha channel. Implementations return the
	 *   safe default false on any probe failure.
	 */
	public function hasAlpha( string $srcPath ): bool;
}
