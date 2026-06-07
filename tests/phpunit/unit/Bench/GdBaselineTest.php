<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\Contenders\GdBaseline;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Contenders\GdBaseline
 */
class GdBaselineTest extends MediaWikiUnitTestCase {

	public function testAppliesToStaticRasterIncludingWebp(): void {
		$gd = new GdBaseline();
		$this->assertTrue( $gd->applies( 'image/jpeg' ) );
		$this->assertTrue( $gd->applies( 'image/png' ) );
		$this->assertTrue( $gd->applies( 'image/webp' ) );
		// GD has no animated path — GIF is excluded, as before.
		$this->assertFalse( $gd->applies( 'image/gif' ) );
	}

	public function testAnimatedWebpIsUnavailable(): void {
		$gd = new GdBaseline();
		if ( !$gd->isAvailable() ) {
			$this->markTestSkipped( 'php-gd not available' );
		}
		$src = dirname( __DIR__, 3 ) . '/bench/corpus/anim.webp';
		$this->assertFileExists( $src, 'animated WebP corpus fixture missing' );
		$res = $gd->run( $src, 'image/webp', 250, sys_get_temp_dir() );
		$this->assertFalse( $res->available );
		// Prove the animated-source bail fired, not an incidental file-open failure.
		$this->assertStringContainsString( 'animated', (string)$res->error );
	}

	public function testStaticWebpProducesThumbnail(): void {
		$gd = new GdBaseline();
		if ( !$gd->isAvailable() ) {
			$this->markTestSkipped( 'php-gd not available' );
		}
		$src = dirname( __DIR__, 3 ) . '/bench/corpus/photo.webp';
		$res = $gd->run( $src, 'image/webp', 250, sys_get_temp_dir() );
		try {
			$this->assertTrue( $res->available );
			$this->assertNotNull( $res->thumbPath );
			$this->assertFileExists( $res->thumbPath );
		} finally {
			if ( $res->thumbPath !== null && is_file( $res->thumbPath ) ) {
				unlink( $res->thumbPath );
			}
		}
	}
}
