<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

/** One contender's output for one (source, targetWidth). Immutable. */
class Result {
	public function __construct(
		public readonly string $contender,
		public readonly string $sourcePath,
		public readonly int $targetWidth,
		public readonly ?string $thumbPath,
		public readonly ?int $bytes,
		public readonly ?float $wallMs,
		public readonly ?int $peakRssKb,
		public readonly bool $available,
		public readonly ?string $error = null,
	) {
	}

	public static function unavailable( string $contender, string $src, int $w, string $why ): self {
		return new self( $contender, $src, $w, null, null, null, null, false, $why );
	}
}
