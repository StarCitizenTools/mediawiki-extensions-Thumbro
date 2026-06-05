<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Libraries;

use MediaWiki\Extension\Thumbro\Libraries\Libwebp;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Libraries\Libwebp
 */
class LibwebpStrategyTest extends MediaWikiUnitTestCase {
	public function testTransparentAnimatedUnderThresholdWithLibwebp(): void {
		$this->assertSame( 'libwebp', Libwebp::chooseStrategy( true, true, true, true ) );
	}

	public function testOpaqueAnimatedDelegatesToVipsAnimated(): void {
		$this->assertSame( 'vips-animated', Libwebp::chooseStrategy( true, true, false, true ) );
	}

	public function testTransparentButNoLibwebpDelegatesToVipsAnimated(): void {
		$this->assertSame( 'vips-animated', Libwebp::chooseStrategy( true, true, true, false ) );
	}

	public function testOverThresholdIsVipsStatic(): void {
		$this->assertSame( 'vips-static', Libwebp::chooseStrategy( true, false, true, true ) );
	}

	public function testStaticIsVipsStatic(): void {
		$this->assertSame( 'vips-static', Libwebp::chooseStrategy( false, true, false, true ) );
	}
}
