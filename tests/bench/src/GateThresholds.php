<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

/** Named, overridable thresholds for the acceptance gate (see tests/bench/README.md). */
class GateThresholds {
	public function __construct(
		// Hard constraints (breach => FAIL)
		public readonly float $qualityFloor = 50.0,
		public readonly float $timeCeilingStaticMs = 3000.0,
		public readonly float $timeCeilingAnimatedMs = 10000.0,
		public readonly int $rssCeilingKb = 512_000,
		// Soft budgets (breach => flag, not FAIL)
		// The dominance noise-tolerance: a SSIMULACRA2 gap within this many points counts
		// as a tie (no regression), so metric jitter cannot deny a smaller file its win nor
		// hand one to the baseline. Also gates the quality-below-baseline soft flag. See
		// ADR-0001 and tests/bench/README.md. The hard quality floor (qualityFloor) is
		// separate and unaffected.
		public readonly float $qualityWithinOfBaseline = 3.0,
		public readonly float $timeSoftFloorMs = 250.0,
		public readonly float $timeSoftMultiple = 1.5,
		public readonly float $rssSoftMultiple = 2.0,
	) {
	}
}
