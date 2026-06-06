<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\AcceptanceGate;
use MediaWiki\Extension\Thumbro\Bench\GateThresholds;
use MediaWiki\Extension\Thumbro\Bench\Verdict;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\AcceptanceGate
 */
class AcceptanceGateTest extends MediaWikiUnitTestCase {
	private function gate(): AcceptanceGate {
		// Not animated
		return new AcceptanceGate( new GateThresholds(), false );
	}

	public function testDominatesSmallerAndBetter(): void {
		$v = $this->gate()->evaluate(
			candBytes: 900, candQuality: 85.0, candWallMs: 1500, candRssKb: 80000,
			baseBytes: 1700, baseQuality: 80.0, baseWallMs: 1000, baseRssKb: 70000
		);
		$this->assertSame( Verdict::PASS, $v->verdict );
	}

	public function testBaselineDominatesIsFail(): void {
		$v = $this->gate()->evaluate(
			candBytes: 2000, candQuality: 70.0, candWallMs: 1500, candRssKb: 80000,
			baseBytes: 1700, baseQuality: 80.0, baseWallMs: 1000, baseRssKb: 70000
		);
		$this->assertSame( Verdict::FAIL, $v->verdict );
	}

	public function testQualityFloorBreachIsFail(): void {
		$v = $this->gate()->evaluate(
			candBytes: 100, candQuality: 45.0, candWallMs: 100, candRssKb: 1000,
			baseBytes: 1700, baseQuality: 80.0, baseWallMs: 1000, baseRssKb: 70000
		);
		$this->assertSame( Verdict::FAIL, $v->verdict );
		$this->assertContains( 'quality-floor', $v->reasons );
	}

	public function testTimeCeilingBreachIsFail(): void {
		$v = $this->gate()->evaluate(
			candBytes: 900, candQuality: 85.0, candWallMs: 3500, candRssKb: 80000,
			baseBytes: 1700, baseQuality: 80.0, baseWallMs: 1000, baseRssKb: 70000
		);
		$this->assertSame( Verdict::FAIL, $v->verdict );
		$this->assertContains( 'time-ceiling', $v->reasons );
	}

	public function testTradeOffIsIncomparableWithFlags(): void {
		$v = $this->gate()->evaluate(
			candBytes: 800, candQuality: 73.0, candWallMs: 1200, candRssKb: 80000,
			baseBytes: 1700, baseQuality: 80.0, baseWallMs: 1000, baseRssKb: 70000
		);
		$this->assertSame( Verdict::INCOMPARABLE, $v->verdict );
		$this->assertContains( 'quality-below-baseline', $v->flags );
	}

	public function testExactTieIsIncomparable(): void {
		// Candidate is identical to baseline on both bytes and quality.
		// Neither dominates strictly, so result must be INCOMPARABLE.
		$v = $this->gate()->evaluate(
			candBytes: 1700, candQuality: 80.0, candWallMs: 1000, candRssKb: 70000,
			baseBytes: 1700, baseQuality: 80.0, baseWallMs: 1000, baseRssKb: 70000
		);
		$this->assertSame( Verdict::INCOMPARABLE, $v->verdict );
	}

	public function testCapsOnlyPassesUnderEveryCap(): void {
		// Under quality floor (50), time ceiling (3000 static), RSS ceiling (512000) => PASS.
		$v = $this->gate()->evaluateCaps( candQuality: 60.0, candWallMs: 1500, candRssKb: 80000 );
		$this->assertSame( Verdict::PASS, $v->verdict );
		$this->assertSame( [], $v->reasons );
	}

	public function testCapsOnlyFailsOnQualityFloor(): void {
		$v = $this->gate()->evaluateCaps( candQuality: 39.0, candWallMs: 100, candRssKb: 1000 );
		$this->assertSame( Verdict::FAIL, $v->verdict );
		$this->assertContains( 'quality-floor', $v->reasons );
	}

	public function testCapsOnlyFailsOnRssCeiling(): void {
		// 600 MB > 512 MB ceiling.
		$v = $this->gate()->evaluateCaps( candQuality: 90.0, candWallMs: 100, candRssKb: 600_000 );
		$this->assertSame( Verdict::FAIL, $v->verdict );
		$this->assertContains( 'rss-ceiling', $v->reasons );
	}

	public function testCapsOnlyUsesAnimatedTimeCeiling(): void {
		$animated = new AcceptanceGate( new GateThresholds(), true );
		// 9000 ms is under the 10000 ms animated ceiling => PASS (would breach the 3000 static one).
		$v = $animated->evaluateCaps( candQuality: 90.0, candWallMs: 9000, candRssKb: 80000 );
		$this->assertSame( Verdict::PASS, $v->verdict );
		// 11000 ms breaches even the animated ceiling.
		$v2 = $animated->evaluateCaps( candQuality: 90.0, candWallMs: 11000, candRssKb: 80000 );
		$this->assertSame( Verdict::FAIL, $v2->verdict );
		$this->assertContains( 'time-ceiling', $v2->reasons );
	}

	public function testAnimatedTimeCeiling(): void {
		// Animated: ceiling is 10000 ms
		$animatedGate = new AcceptanceGate( new GateThresholds(), true );

		// 9000 ms < 10000 ms ceiling — must NOT breach
		$v = $animatedGate->evaluate(
			candBytes: 900, candQuality: 85.0, candWallMs: 9000, candRssKb: 80000,
			baseBytes: 1700, baseQuality: 80.0, baseWallMs: 1000, baseRssKb: 70000
		);
		$this->assertNotSame( Verdict::FAIL, $v->verdict );
		$this->assertNotContains( 'time-ceiling', $v->reasons );

		// 11000 ms > 10000 ms ceiling — must breach
		$v2 = $animatedGate->evaluate(
			candBytes: 900, candQuality: 85.0, candWallMs: 11000, candRssKb: 80000,
			baseBytes: 1700, baseQuality: 80.0, baseWallMs: 1000, baseRssKb: 70000
		);
		$this->assertSame( Verdict::FAIL, $v2->verdict );
		$this->assertContains( 'time-ceiling', $v2->reasons );
	}
}
