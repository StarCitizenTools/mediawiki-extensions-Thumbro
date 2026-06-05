<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

/** Aggregated SSIMULACRA2 score for a candidate (single image or animation). Immutable. */
class Quality {
	public function __construct(
		public readonly float $mean,
		public readonly float $worst,
		public readonly int $frames,
	) {
	}

	/** SSIMULACRA2 band label for human-readable output. */
	public function band(): string {
		return match ( true ) {
			$this->mean >= 90 => 'visually lossless',
			$this->mean >= 70 => 'high',
			$this->mean >= 50 => 'medium',
			default => 'low',
		};
	}
}
