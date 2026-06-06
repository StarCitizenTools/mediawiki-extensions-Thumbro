<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\MediaHandlers;

use MediaWiki\MediaWikiServices;
use WebPHandler;

class ThumbroWebPHandler extends WebPHandler {
	use ThumbroHandlerTrait;

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
	 * @inheritDoc
	 */
	public function canRender( $file ) {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function mustRender( $file ) {
		return false;
	}
}
