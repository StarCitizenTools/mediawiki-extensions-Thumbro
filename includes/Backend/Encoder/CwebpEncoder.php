<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Encoder;

use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;

/**
 * cwebp (libwebp) encoder. Two-step, static only: consumes a vips-resized PNG intermediate and
 * encodes a single WebP. libwebp's cwebp is markedly more byte-efficient than vips webpsave on
 * some content; per-MIME routing (vips-webp vs cwebp) is decided by the benchmark gate.
 */
class CwebpEncoder implements Encoder {

	public function __construct(
		private readonly string $command,
	) {
	}

	public function name(): string {
		return 'cwebp';
	}

	public function isAvailable(): bool {
		return $this->command !== '' && is_executable( $this->command );
	}

	public function supportsAnimation(): bool {
		return false;
	}

	public function supportsAlpha(): bool {
		return true;
	}

	public function requiresResizedInput(): bool {
		return true;
	}

	public function intermediateFormat(): ?string {
		return 'png';
	}

	public function planEncode(
		ShellCommandFactory $factory, EncodeInput $input, string $dstPath, array $options
	): ShellCommand {
		$command = $factory->create( 'libwebp', $this->command, $options, 'libwebp' );
		$command->setIO( $input->resizeCommand, $dstPath );
		return $command;
	}
}
