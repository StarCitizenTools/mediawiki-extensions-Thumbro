<?php

namespace MediaWiki\Extension\Thumbro\Hooks;

use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;
use MediaWiki\HookContainer\HookContainer;

/**
 * This is a hook runner class, see docs/Hooks.md in core.
 * @internal
 */
class HookRunner implements ThumbroBeforeProduceHtmlHook {
	public function __construct(
		private readonly HookContainer $hookContainer
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onThumbroBeforeProduceHtml( ThumbroThumbnailImage $thumbnail, array &$sources ) {
		return $this->hookContainer->run(
			'ThumbroBeforeProduceHtml',
			[ $thumbnail, &$sources ]
		);
	}
}
