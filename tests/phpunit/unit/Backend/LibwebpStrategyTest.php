<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend;

use MediaWiki\Extension\Thumbro\Backend\LibwebpBackend;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\LibwebpBackend::chooseStrategy
 */
class LibwebpStrategyTest extends MediaWikiUnitTestCase {
	public function testTransparentAnimatedUnderThresholdWithLibwebp(): void {
		$this->assertSame( 'libwebp', LibwebpBackend::chooseStrategy( true, true, true, true ) );
	}

	public function testOpaqueAnimatedDelegatesToVipsAnimated(): void {
		$this->assertSame( 'vips-animated', LibwebpBackend::chooseStrategy( true, true, false, true ) );
	}

	public function testTransparentButNoLibwebpDelegatesToVipsAnimated(): void {
		$this->assertSame( 'vips-animated', LibwebpBackend::chooseStrategy( true, true, true, false ) );
	}

	public function testOverThresholdIsVipsStatic(): void {
		$this->assertSame( 'vips-static', LibwebpBackend::chooseStrategy( true, false, true, true ) );
	}

	public function testStaticIsVipsStatic(): void {
		$this->assertSame( 'vips-static', LibwebpBackend::chooseStrategy( false, true, false, true ) );
	}
}
