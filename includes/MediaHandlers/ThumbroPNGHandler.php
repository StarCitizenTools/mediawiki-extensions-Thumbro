<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\MediaHandlers;

use MediaWiki\Extension\Thumbro\Image\OrientationDetector;
use MediaWiki\MediaWikiServices;
use PNGHandler;

class ThumbroPNGHandler extends PNGHandler {
	use ThumbroHandlerTrait;
	use ThumbroExifOrientationTrait;

	/**
	 * Core's PNGHandler does not read the PNG eXIf chunk, so the orientation is not in metadata.
	 * Probe libvips — the engine that will auto-rotate — so the answer matches the rendered output.
	 * Runs only at upload/refreshImageMetadata (cached in img_metadata), never on the render path.
	 *
	 * @inheritDoc
	 */
	protected function thumbroSourceOrientation( array $info, string $filename ): int {
		/** @var OrientationDetector $detector */
		$detector = MediaWikiServices::getInstance()->getService( 'Thumbro.OrientationDetector' );
		return $detector->getOrientation( $filename );
	}
}
