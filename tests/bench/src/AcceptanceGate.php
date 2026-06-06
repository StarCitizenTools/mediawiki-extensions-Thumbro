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
	 * hard safety cap (quality floor, wall-time ceiling, RSS ceiling), FAIL otherwise with the
	 * breached caps as reasons. It consults no baseline: the stress tier asks "does Thumbro stay
	 * safe on pathological input?", never "is Thumbro better?", so it never produces a
	 * win/loss/trade-off.
	 *
	 * The quality floor is a hard cap here because the stress animations are scored at standard
	 * widths (≥120px), where SSIMULACRA2 is reliable — small/animated/transparent content is no
	 * longer measured at the unstable 84px.
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
	 * Dominance evaluation vs the binding baseline. A SSIMULACRA2 gap within
	 * GateThresholds::qualityWithinOfBaseline counts as a tie, so a smaller file is not denied
	 * a win by metric jitter and the baseline cannot win on jitter either. The hard quality
	 * floor remains a hard FAIL. See ADR-0001.
	 */
	public function evaluate(
		int $candBytes, float $candQuality, float $candWallMs, int $candRssKb,
		int $baseBytes, float $baseQuality, float $baseWallMs, int $baseRssKb
	): GateResult {
		$reasons = [];
		$flags = [];

		// Hard constraints (any breach => FAIL).
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

		$tol = $this->t->qualityWithinOfBaseline;

		// Soft budgets (flag, do not FAIL)
		if ( $candQuality < $baseQuality - $tol ) {
			$flags[] = 'quality-below-baseline';
		}
		$timeBudget = max( $this->t->timeSoftFloorMs, $this->t->timeSoftMultiple * $baseWallMs );
		if ( $candWallMs > $timeBudget ) {
			$flags[] = 'time-regression';
		}
		if ( $candRssKb > $this->t->rssSoftMultiple * $baseRssKb ) {
			$flags[] = 'memory-regression';
		}

		// Dominance on {bytes, quality}, with the noise-tolerance applied to quality.
		$candNoWorseQ = $candQuality >= $baseQuality - $tol;
		$candBetterQ = $candQuality > $baseQuality + $tol;
		$noWorse = $candBytes <= $baseBytes && $candNoWorseQ;
		$strictly = $candBytes < $baseBytes || $candBetterQ;
		if ( $noWorse && $strictly ) {
			return new GateResult( Verdict::PASS, $reasons, $flags );
		}

		if ( $this->baselineDominates( $candBytes, $candQuality, $baseBytes, $baseQuality ) ) {
			return new GateResult( Verdict::FAIL, [ 'baseline-dominates' ], $flags );
		}

		// Genuine trade-off within constraints.
		return new GateResult( Verdict::INCOMPARABLE, $reasons, $flags );
	}

	/**
	 * Floor check for a non-binding baseline (e.g. GD, the literal MediaWiki default): PASS
	 * unless the baseline dominates the candidate on {bytes, quality}. It is never a win — only
	 * a guard that the candidate is not worse than the weak default. See ADR-0001.
	 */
	public function evaluateFloor(
		int $candBytes, float $candQuality, int $baseBytes, float $baseQuality
	): GateResult {
		if ( $this->baselineDominates( $candBytes, $candQuality, $baseBytes, $baseQuality ) ) {
			return new GateResult( Verdict::FAIL, [ 'baseline-dominates' ], [] );
		}
		return new GateResult( Verdict::PASS, [], [] );
	}

	/**
	 * True when the baseline dominates the candidate on {bytes, quality} (with the same
	 * noise-tolerance) — i.e. the baseline is no worse on both and strictly better on one.
	 * Shared by evaluate() and evaluateFloor().
	 */
	private function baselineDominates(
		int $candBytes, float $candQuality, int $baseBytes, float $baseQuality
	): bool {
		$tol = $this->t->qualityWithinOfBaseline;
		$baseNoWorseQ = $baseQuality >= $candQuality - $tol;
		$baseBetterQ = $baseQuality > $candQuality + $tol;
		$baselineNoWorse = $baseBytes <= $candBytes && $baseNoWorseQ;
		$baselineStrictly = $baseBytes < $candBytes || $baseBetterQ;
		return $baselineNoWorse && $baselineStrictly;
	}
}
