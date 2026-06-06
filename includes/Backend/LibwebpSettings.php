<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

/**
 * Immutable, config-derived settings the Libwebp backend needs that are NOT per-request:
 * the gif2webp encoder binary and its flags, the libvips binary used for the resize step
 * and the libvips delegation, and the animated-area threshold.
 *
 * Built once in ServiceWiring from ThumbroLibraries / ThumbroOptions['image/gif'] /
 * ThumbroMaxAnimatedArea. Replaces the per-call BackendDispatcher::libwebpOptions() bundle.
 */
class LibwebpSettings {

	/**
	 * @param string $gif2webpCommand
	 * @param array<string,string> $gif2webpFlags
	 * @param string $libvipsCommand
	 * @param int $maxAnimatedArea
	 */
	public function __construct(
		private readonly string $gif2webpCommand,
		private readonly array $gif2webpFlags,
		private readonly string $libvipsCommand,
		private readonly int $maxAnimatedArea,
	) {
	}

	public function gif2webpCommand(): string {
		return $this->gif2webpCommand;
	}

	/** @return array<string,string> */
	public function gif2webpFlags(): array {
		return $this->gif2webpFlags;
	}

	public function libvipsCommand(): string {
		return $this->libvipsCommand;
	}

	public function maxAnimatedArea(): int {
		return $this->maxAnimatedArea;
	}

	/** True when a usable gif2webp binary is configured and executable. */
	public function libwebpAvailable(): bool {
		return $this->gif2webpCommand !== '' && is_executable( $this->gif2webpCommand );
	}
}
