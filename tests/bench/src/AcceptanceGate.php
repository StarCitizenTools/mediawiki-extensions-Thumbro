<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

class AcceptanceGate {
	public function __construct(
		private readonly GateThresholds $t,
		private readonly bool $animated,
	) {
	}

	public function evaluate(
		int $candBytes, float $candQuality, float $candWallMs, int $candRssKb,
		int $baseBytes, float $baseQuality, float $baseWallMs, int $baseRssKb
	): GateResult {
		$reasons = [];
		$flags = [];

		// Hard constraints (any breach => FAIL)
		if ( $candQuality < $this->t->qualityFloor ) {
			$reasons[] = 'quality-floor';
		}
		$timeCeiling = $this->animated ? $this->t->timeCeilingAnimatedMs : $this->t->timeCeilingStaticMs;
		if ( $candWallMs > $timeCeiling ) {
			$reasons[] = 'time-ceiling';
		}
		if ( $candRssKb > $this->t->rssCeilingKb ) {
			$reasons[] = 'rss-ceiling';
		}
		if ( $reasons !== [] ) {
			return new GateResult( Verdict::FAIL, $reasons, $flags );
		}

		// Soft budgets (flag, do not FAIL)
		if ( $candQuality < $baseQuality - $this->t->qualityWithinOfBaseline ) {
			$flags[] = 'quality-below-baseline';
		}
		$timeBudget = max( $this->t->timeSoftFloorMs, $this->t->timeSoftMultiple * $baseWallMs );
		if ( $candWallMs > $timeBudget ) {
			$flags[] = 'time-regression';
		}
		if ( $candRssKb > $this->t->rssSoftMultiple * $baseRssKb ) {
			$flags[] = 'memory-regression';
		}

		// Dominance on {bytes, quality}
		$noWorse = $candBytes <= $baseBytes && $candQuality >= $baseQuality;
		$strictly = $candBytes < $baseBytes || $candQuality > $baseQuality;
		if ( $noWorse && $strictly ) {
			return new GateResult( Verdict::PASS, $reasons, $flags );
		}

		$baselineNoWorse = $baseBytes <= $candBytes && $baseQuality >= $candQuality;
		$baselineStrictly = $baseBytes < $candBytes || $baseQuality > $candQuality;
		if ( $baselineNoWorse && $baselineStrictly ) {
			return new GateResult( Verdict::FAIL, [ 'baseline-dominates' ], $flags );
		}

		// Genuine trade-off within constraints.
		return new GateResult( Verdict::INCOMPARABLE, $reasons, $flags );
	}
}
