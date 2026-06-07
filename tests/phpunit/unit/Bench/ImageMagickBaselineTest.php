<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\Contenders\ImageMagickBaseline;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Contenders\ImageMagickBaseline
 */
class ImageMagickBaselineTest extends MediaWikiUnitTestCase {

	public function testAppliesToWebpAndRaster(): void {
		$im = new ImageMagickBaseline();
		$this->assertTrue( $im->applies( 'image/webp' ) );
		$this->assertTrue( $im->applies( 'image/jpeg' ) );
		$this->assertTrue( $im->applies( 'image/png' ) );
		$this->assertTrue( $im->applies( 'image/gif' ) );
		$this->assertFalse( $im->applies( 'image/svg+xml' ) );
	}
}
