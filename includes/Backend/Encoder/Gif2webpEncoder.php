<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Encoder;

use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;

/**
 * gif2webp (libwebp) encoder. Two-step: consumes the output of a prior vips resize (a GIF
 * intermediate) and encodes WebP — used for animation by the routing (`when: animated`), though
 * the tool itself handles static GIFs too. Mirrors the former LibwebpBackend::planLibwebpEncode
 * encode half byte-for-byte.
 */
class Gif2webpEncoder implements Encoder {

	public function __construct(
		private readonly string $command,
	) {
	}

	public function name(): string {
		return 'gif2webp';
	}

	/** Available only when the gif2webp binary is configured and executable (old libwebpAvailable). */
	public function isAvailable(): bool {
		return $this->command !== '' && is_executable( $this->command );
	}

	public function supportsAnimation(): bool {
		return true;
	}

	public function supportsAlpha(): bool {
		return true;
	}

	public function requiresResizedInput(): bool {
		return true;
	}

	public function intermediateFormat(): ?string {
		return 'gif';
	}

	public function planEncode(
		ShellCommandFactory $factory, EncodeInput $input, string $dstPath, array $options
	): ShellCommand {
		$command = $factory->create( 'libwebp', $this->command, $options, 'gif2webp' );
		$command->setIO( $input->resizeCommand, $dstPath );
		return $command;
	}
}
