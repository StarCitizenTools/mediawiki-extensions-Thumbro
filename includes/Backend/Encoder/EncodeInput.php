<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Encoder;

use MediaWiki\Extension\Thumbro\Shell\ShellCommand;

/**
 * Input to an {@see Encoder}. A fused encoder (vips) encodes from the source at a target size with
 * load options; a two-step encoder (gif2webp, cwebp) encodes from the output of a prior
 * resize command.
 */
class EncodeInput {
	/**
	 * @param string|null $srcPath
	 * @param string|null $physicalSize
	 * @param array<string,string> $loadOptions
	 * @param ShellCommand|null $resizeCommand
	 */
	private function __construct(
		public readonly ?string $srcPath,
		public readonly ?string $physicalSize,
		public readonly array $loadOptions,
		public readonly ?ShellCommand $resizeCommand,
	) {
	}

	/**
	 * @param string $srcPath
	 * @param string $physicalSize
	 * @param array<string,string> $loadOptions
	 */
	public static function fromSource( string $srcPath, string $physicalSize, array $loadOptions ): self {
		return new self( $srcPath, $physicalSize, $loadOptions, null );
	}

	public static function fromResized( ShellCommand $resizeCommand ): self {
		return new self( null, null, [], $resizeCommand );
	}
}
