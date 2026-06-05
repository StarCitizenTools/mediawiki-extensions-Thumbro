<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\AcceptanceGate;
use MediaWiki\Extension\Thumbro\Bench\GateThresholds;
use MediaWiki\Extension\Thumbro\Bench\Verdict;
use MediaWikiUnitTestCase;

/**
 * Proves the gate discriminates: a strictly-better candidate PASSes and a
 * strictly-worse one FAILs. Guards against a gate that rubber-stamps everything.
 *
 * @covers \MediaWiki\Extension\Thumbro\Bench\AcceptanceGate
 */
class GateDiscriminationTest extends MediaWikiUnitTestCase {
	public function testKnownGoodPasses(): void {
		$g = new AcceptanceGate( new GateThresholds(), false );
		$v = $g->evaluate( 800, 88.0, 900, 60000, 1600, 80.0, 1000, 60000 );
		$this->assertSame( Verdict::PASS, $v->verdict );
	}

	public function testKnownBadFails(): void {
		$g = new AcceptanceGate( new GateThresholds(), false );
		$v = $g->evaluate( 2400, 62.0, 1000, 60000, 1600, 80.0, 1000, 60000 );
		$this->assertSame( Verdict::FAIL, $v->verdict );
	}
}
