<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\MediaHandlers;

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use WebPHandler;

class ThumbroWebPHandler extends WebPHandler {
	/**
	 * @inheritDoc
	 */
	public function getThumbType( $ext, $mime, $params = null ) {
		return [ 'webp', 'image/webp' ];
	}

	/**
	 * We cannot animate thumbnails that are bigger than a particular size
	 * Use the same value as GIF handler for now
	 *
	 * @inheritDoc
	 */
	public function canAnimateThumbnail( $file ) {
		$maxAnimatedWebPArea = MediaWikiServices::getInstance()->getMainConfig()
			->get( MainConfigNames::MaxAnimatedGifArea );

		return $this->getImageArea( $file ) <= $maxAnimatedWebPArea;
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
