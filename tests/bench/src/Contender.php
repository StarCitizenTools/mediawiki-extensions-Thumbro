<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

interface Contender {
	/** Stable short identifier, e.g. 'im', 'gd', 'thumbro-vips'. */
	public function name(): string;

	/** Does this contender handle the given MIME type? */
	public function applies( string $mime ): bool;

	/** Are all required binaries present? */
	public function isAvailable(): bool;

	/**
	 * Produce a thumbnail of $srcPath at $targetWidth (aspect preserved) into $destDir.
	 * MUST return Result::unavailable(...) rather than throw when a tool is missing.
	 */
	public function run( string $srcPath, string $mime, int $targetWidth, string $destDir ): Result;
}
