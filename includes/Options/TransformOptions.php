<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Options;

/**
 * Immutable, resolved option set for a single Thumbro transform.
 *
 * Produced by {@see TransformOptionsResolver} from the configured ThumbroOptions /
 * ThumbroLibraries blocks. Replaces the loose associative array that the static
 * pipeline used to pass around. inputOptions/outputOptions are the vipsthumbnail
 * load/save options rendered as the "[k=v,…]" suffix.
 */
class TransformOptions {

	/**
	 * @param string $library
	 * @param string $command
	 * @param array<string,string> $inputOptions
	 * @param array<string,string> $outputOptions
	 * @param bool $setComment
	 */
	public function __construct(
		private readonly string $library,
		private readonly string $command,
		private readonly array $inputOptions,
		private readonly array $outputOptions,
		private readonly bool $setComment,
	) {
	}

	public function library(): string {
		return $this->library;
	}

	/** Resolved backend binary for this option set's library. */
	public function command(): string {
		return $this->command;
	}

	/** @return array<string,string> */
	public function inputOptions(): array {
		return $this->inputOptions;
	}

	/** @return array<string,string> */
	public function outputOptions(): array {
		return $this->outputOptions;
	}

	public function setComment(): bool {
		return $this->setComment;
	}

	/**
	 * Return a copy with the library/command/input/output overridden, used when one backend
	 * delegates to another (e.g. Libwebp delegating a frame to the libvips backend).
	 *
	 * @param string $library
	 * @param string $command
	 * @param array<string,string> $inputOptions
	 * @param array<string,string> $outputOptions
	 */
	public function withDelegated(
		string $library,
		string $command,
		array $inputOptions,
		array $outputOptions
	): self {
		return new self( $library, $command, $inputOptions, $outputOptions, $this->setComment );
	}
}
