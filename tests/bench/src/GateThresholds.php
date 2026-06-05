<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

/** Named, overridable thresholds for the acceptance gate (spec §6.1). */
class GateThresholds {
	public function __construct(
		// Hard constraints (breach => FAIL)
		public readonly float $qualityFloor = 50.0,
		public readonly float $timeCeilingStaticMs = 3000.0,
		public readonly float $timeCeilingAnimatedMs = 10000.0,
		public readonly int $rssCeilingKb = 512_000,
		// Soft budgets (breach => flag, not FAIL)
		public readonly float $qualityWithinOfBaseline = 5.0,
		public readonly float $timeSoftFloorMs = 250.0,
		public readonly float $timeSoftMultiple = 1.5,
		public readonly float $rssSoftMultiple = 2.0,
	) {
	}
}
