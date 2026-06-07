<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Resize;

use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;

/**
 * Resizes a source image to an intermediate file at the target physical size, for two-step
 * encoders (gif2webp, cwebp) that cannot resize themselves. One implementation today
 * ({@see VipsResizer}); this seam lets a second resize engine slot in later without touching
 * the encoders.
 */
interface Resizer {
	/**
	 * @param ShellCommandFactory $factory command factory
	 * @param string $srcPath source image path
	 * @param array<string,string> $loadOptions load/resize options for the resize engine
	 * @param string $physicalSize "WxH"
	 * @param string $intermediateFormat output extension for the temp file (e.g. 'gif','png')
	 */
	public function planResize(
		ShellCommandFactory $factory,
		string $srcPath,
		array $loadOptions,
		string $physicalSize,
		string $intermediateFormat
	): ShellCommand;
}
