<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

class ProcResult {
	public function __construct(
		public readonly int $exitCode,
		public readonly string $stdout,
		public readonly string $stderr,
		public readonly float $wallMs,
		public readonly ?int $peakRssKb,
	) {
	}

	public function ok(): bool {
		return $this->exitCode === 0;
	}
}
