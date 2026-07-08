<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\MediaHandlers;

use MediaWiki\MediaWikiServices;
use WebPHandler;

class ThumbroWebPHandler extends WebPHandler {
	use ThumbroHandlerTrait;
	use ThumbroExifOrientationTrait;

	/**
	 * Cap animated-WebP thumbnails at $wgThumbroMaxAnimatedArea (width × height × frames) — the
	 * same effective threshold the libwebp backend uses for GIF — instead of core WebPHandler's
	 * $wgMaxAnimatedGifArea default, so GIF and WebP animate up to the same size.
	 *
	 * Deliberately NOT shared with the PNG handler: libvips cannot decode APNG (its PNG loader
	 * has no page support), so an APNG must keep returning the core "no animated thumbnail"
	 * answer — otherwise the libvips backend would force n=-1 and vipsthumbnail would error.
	 *
	 * @inheritDoc
	 */
	public function canAnimateThumbnail( $file ) {
		$maxArea = (int)MediaWikiServices::getInstance()->getConfigFactory()
			->makeConfig( 'thumbro' )->get( 'ThumbroMaxAnimatedArea' );
		return $this->getImageArea( $file ) <= $maxArea;
	}

	/**
	 * WebPHandler parses the EXIF chunk into 'media-metadata' while reading the file, so the
	 * orientation is already available — no extra probe needed. A WebP with no EXIF simply lacks
	 * the key, which correctly reads as upright (1).
	 *
	 * @inheritDoc
	 */
	protected function thumbroSourceOrientation( array $info, string $filename ): int {
		return (int)( $info['metadata']['media-metadata']['Orientation'] ?? 1 );
	}

	/**
	 * @inheritDoc
	 */
	public function canRender( $file ) {
		return true;
	}

	/**
	 * WebP is browser-renderable, so a non-oriented file can be served as-is. An EXIF-oriented one
	 * must be rendered, though: vipsthumbnail bakes the rotation into the thumbnail (correct in
	 * every browser), whereas serving the original would rely on the browser honouring WebP EXIF
	 * orientation, which is inconsistent. getRotation() reads the cached angle, so this stays cheap.
	 *
	 * @inheritDoc
	 */
	public function mustRender( $file ) {
		return $this->getRotation( $file ) != 0;
	}
}
