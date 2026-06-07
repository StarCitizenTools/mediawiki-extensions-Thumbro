<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Encoder;

use MediaWiki\Extension\Thumbro\Backend\VipsOptionSuffix;
use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;

/**
 * libvips WebP encoder (vipsthumbnail). Fused: it resizes and encodes in a single command, so it
 * requires no separate resize step. Mirrors the former LibvipsBackend::plan byte-for-byte.
 */
class VipsWebpEncoder implements Encoder {

	public function __construct(
		private readonly string $command,
	) {
	}

	public function name(): string {
		return 'vips-webp';
	}

	/**
	 * Always available: libvips is Thumbro's core scaler. It was never gated under the old
	 * routing (only gif2webp was), so a transform that needs vips fails the same way it did
	 * before rather than silently routing elsewhere.
	 */
	public function isAvailable(): bool {
		return true;
	}

	public function supportsAnimation(): bool {
		return true;
	}

	public function supportsAlpha(): bool {
		return true;
	}

	public function requiresResizedInput(): bool {
		return false;
	}

	public function intermediateFormat(): ?string {
		return null;
	}

	public function planEncode(
		ShellCommandFactory $factory, EncodeInput $input, string $dstPath, array $options
	): ShellCommand {
		$command = $factory->create( 'libvips', $this->command, [ 'size' => $input->physicalSize ] );
		$command->setIO(
			$input->srcPath . VipsOptionSuffix::make( $input->loadOptions ),
			$dstPath . VipsOptionSuffix::make( $options )
		);
		return $command;
	}
}
