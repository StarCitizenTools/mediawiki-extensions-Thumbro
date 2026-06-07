<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Options;

use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Options\TransformOptions
 */
class TransformOptionsTest extends MediaWikiUnitTestCase {

	public function testExposesAccessors(): void {
		$encodeList = [
			[ 'encoder' => 'gif2webp', 'when' => [ 'animated' => true ], 'options' => [ 'q' => '80' ] ],
			[ 'encoder' => 'vips-webp', 'options' => [ 'Q' => '90' ] ],
		];
		$options = new TransformOptions( [ 'n' => '-1' ], $encodeList, true );

		$this->assertSame( [ 'n' => '-1' ], $options->resizeOptions() );
		$this->assertSame( $encodeList, $options->encodeList() );
		$this->assertTrue( $options->setComment() );
	}

	public function testEmptyDefaults(): void {
		$options = new TransformOptions( [], [], false );

		$this->assertSame( [], $options->resizeOptions() );
		$this->assertSame( [], $options->encodeList() );
		$this->assertFalse( $options->setComment() );
	}
}
