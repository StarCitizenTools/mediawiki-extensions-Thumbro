<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend;

use MediaWiki\Extension\Thumbro\Backend\VipsOptionSuffix;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\VipsOptionSuffix
 */
class VipsOptionSuffixTest extends MediaWikiUnitTestCase {

	public function testEmptyArrayProducesEmptyString(): void {
		$this->assertSame( '', VipsOptionSuffix::make( [] ) );
	}

	public function testSingleOption(): void {
		$this->assertSame( '[Q=80]', VipsOptionSuffix::make( [ 'Q' => '80' ] ) );
	}

	public function testMultipleOptionsPreserveOrder(): void {
		$this->assertSame(
			'[Q=80,lossless=0]',
			VipsOptionSuffix::make( [ 'Q' => '80', 'lossless' => '0' ] )
		);
	}
}
