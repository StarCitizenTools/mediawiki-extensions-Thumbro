<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\Quality;
use MediaWiki\Extension\Thumbro\Bench\Ssimulacra2;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Ssimulacra2
 */
class Ssimulacra2Test extends MediaWikiUnitTestCase {
	public function testParseScore(): void {
		$this->assertSame( 82.34, Ssimulacra2::parseScore( "82.34\n" ) );
		$this->assertSame( 91.0, Ssimulacra2::parseScore( "Score: 91.0\n" ) );
	}

	public function testAggregateMeanAndWorst(): void {
		$q = Ssimulacra2::aggregate( [ 90.0, 70.0, 80.0 ] );
		$this->assertInstanceOf( Quality::class, $q );
		$this->assertSame( 80.0, $q->mean );
		$this->assertSame( 70.0, $q->worst );
		$this->assertSame( 3, $q->frames );
	}

	public function testParseScoreThrowsOnGarbage(): void {
		$this->expectException( \RuntimeException::class );
		Ssimulacra2::parseScore( "no number here" );
	}

	public function testIsWebpDetectsByMagicBytesNotExtension(): void {
		$webp = tempnam( sys_get_temp_dir(), 'thumbro_webp_' );
		$gif = tempnam( sys_get_temp_dir(), 'thumbro_gif_' );
		// RIFF<4-byte size>WEBP… vs GIF89a — extension-free, content only.
		file_put_contents( $webp, "RIFF\x00\x00\x00\x00WEBPVP8X" );
		file_put_contents( $gif, "GIF89a\x00\x00" );
		try {
			$this->assertTrue( Ssimulacra2::isWebp( $webp ) );
			$this->assertFalse( Ssimulacra2::isWebp( $gif ) );
		} finally {
			unlink( $webp );
			unlink( $gif );
		}
	}
}
