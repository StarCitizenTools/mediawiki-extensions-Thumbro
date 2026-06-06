<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro;

use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroGIFHandler;
use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroJpegHandler;
use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroPNGHandler;
use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroWebPHandler;

class MediaHandlers {
	public const HANDLERS = [
		'image/gif' => ThumbroGIFHandler::class,
		'image/jpeg' => ThumbroJpegHandler::class,
		'image/png' => ThumbroPNGHandler::class,
		'image/webp' => ThumbroWebPHandler::class
	];
}
