<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

class AcceptanceGate {
	public function __construct(
		private readonly GateThresholds $t,
		private readonly bool $animated,
	) {
	}

	/**
	 * Caps-only evaluation for the STRESS tier. Returns PASS when the candidate is under every
	 * hard safety cap (quality floor, wall-time ceiling, RSS ceiling) and FAIL otherwise, with
	 * the breached caps as reasons. It consults no baseline: the stress tier asks "does Thumbro
	 * stay safe on pathological input?", never "is Thumbro better?", so it can never produce a
	 * win/loss/trade-off.
	 */
	public function evaluateCaps( float $candQuality, float $candWallMs, int $candRssKb ): GateResult {
		$reasons = [];
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
		return new GateResult( $reasons === [] ? Verdict::PASS : Verdict::FAIL, $reasons, [] );
	}

	/**
	 * Dominance evaluation vs one baseline. When $qualityAdvisory is true (a small,
	 * ≤ qualityAdvisoryMaxWidth thumbnail whose SSIMULACRA2 is unstable), quality is advisory: a
	 * sub-floor score is flagged rather than a hard FAIL, and quality differences within
	 * $qualityWithinOfBaseline are treated as ties so metric jitter cannot hand either side a win.
	 * See tests/bench/README.md.
	 */
	public function evaluate(
		int $candBytes, float $candQuality, float $candWallMs, int $candRssKb,
		int $baseBytes, float $baseQuality, float $baseWallMs, int $baseRssKb,
		bool $qualityAdvisory = false
	): GateResult {
		$reasons = [];
		$flags = [];

		// Hard constraints (any breach => FAIL). At advisory widths the quality metric is
		// unreliable, so a sub-floor score becomes a flag for visual follow-up, never a FAIL.
		if ( $candQuality < $this->t->qualityFloor ) {
			if ( $qualityAdvisory ) {
				$flags[] = 'quality-floor-advisory';
			} else {
				$reasons[] = 'quality-floor';
			}
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

		// Dominance on {bytes, quality}. At advisory widths, quality gaps within the metric's
		// noise band ($qualityWithinOfBaseline) count as ties, so a smaller file is not denied a
		// win by jitter — and, symmetrically, the baseline cannot win on jitter either.
		$tol = $qualityAdvisory ? $this->t->qualityWithinOfBaseline : 0.0;
		$candNoWorseQ = $candQuality >= $baseQuality - $tol;
		$candBetterQ = $candQuality > $baseQuality + $tol;
		$baseNoWorseQ = $baseQuality >= $candQuality - $tol;
		$baseBetterQ = $baseQuality > $candQuality + $tol;

		$noWorse = $candBytes <= $baseBytes && $candNoWorseQ;
		$strictly = $candBytes < $baseBytes || $candBetterQ;
		if ( $noWorse && $strictly ) {
			return new GateResult( Verdict::PASS, $reasons, $flags );
		}

		$baselineNoWorse = $baseBytes <= $candBytes && $baseNoWorseQ;
		$baselineStrictly = $baseBytes < $candBytes || $baseBetterQ;
		if ( $baselineNoWorse && $baselineStrictly ) {
			return new GateResult( Verdict::FAIL, [ 'baseline-dominates' ], $flags );
		}

		// Genuine trade-off within constraints.
		return new GateResult( Verdict::INCOMPARABLE, $reasons, $flags );
	}
}
