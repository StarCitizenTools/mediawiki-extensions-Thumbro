<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

use File;
use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use TransformationalImageHandler;

/**
 * Immutable bundle describing a single transform request: the core handler, the file,
 * MediaWiki's normalised $params array, and the resolved {@see TransformOptions}.
 *
 * $params stays a core-owned array (MediaWiki owns its shape and passes it by reference
 * through the transform pipeline); the typed accessors below read the fields the backends
 * and runner actually consume so the rest of the code never indexes the array directly.
 */
class BackendRequest {

	/**
	 * @param TransformationalImageHandler $handler
	 * @param File $file
	 * @param array<string,mixed> $params MediaWiki's normalised transform params.
	 * @param TransformOptions $options
	 */
	public function __construct(
		private readonly TransformationalImageHandler $handler,
		private readonly File $file,
		private readonly array $params,
		private readonly TransformOptions $options,
	) {
	}

	public function getHandler(): TransformationalImageHandler {
		return $this->handler;
	}

	public function getFile(): File {
		return $this->file;
	}

	/** @return array<string,mixed> */
	public function getParams(): array {
		return $this->params;
	}

	public function getOptions(): TransformOptions {
		return $this->options;
	}

	public function srcPath(): string {
		return $this->params['srcPath'];
	}

	public function dstPath(): string {
		return $this->params['dstPath'];
	}

	public function dstUrl(): string {
		return $this->params['dstUrl'];
	}

	public function clientWidth(): mixed {
		return $this->params['clientWidth'] ?? null;
	}

	public function clientHeight(): mixed {
		return $this->params['clientHeight'] ?? null;
	}

	public function physicalSize(): string {
		return $this->params['physicalWidth'] . 'x' . $this->params['physicalHeight'];
	}

	public function comment(): string {
		return $this->params['comment'] ?? '';
	}

	/** Whether a non-empty comment is present (matches the legacy !empty($params['comment']) guard). */
	public function hasComment(): bool {
		return !empty( $this->params['comment'] );
	}

	/**
	 * Return a copy of this request carrying a different option set, used when a backend
	 * delegates a transform to another backend with adjusted options.
	 */
	public function withOptions( TransformOptions $options ): self {
		return new self( $this->handler, $this->file, $this->params, $options );
	}
}
