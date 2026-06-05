<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\Result;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Result
 */
class ResultTest extends MediaWikiUnitTestCase {
	public function testAvailableResultCarriesMetrics(): void {
		$r = new Result( 'vips', '/src.gif', 84, '/out.webp', 1149, 5129.0, 81000, true, null );
		$this->assertSame( 'vips', $r->contender );
		$this->assertSame( 1149, $r->bytes );
		$this->assertSame( 5129.0, $r->wallMs );
		$this->assertTrue( $r->available );
	}

	public function testUnavailableResultHasNullMetrics(): void {
		$r = Result::unavailable( 'gif2webp', '/src.gif', 84, 'binary missing' );
		$this->assertFalse( $r->available );
		$this->assertNull( $r->bytes );
		$this->assertSame( 'binary missing', $r->error );
	}
}
