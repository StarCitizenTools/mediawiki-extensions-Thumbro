<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\WebpProbe;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\WebpProbe
 */
class WebpProbeTest extends MediaWikiUnitTestCase {

	/** Build a minimal RIFF/WEBP/VP8X header with the given flags byte. */
	private function vp8xHeader( int $flags ): string {
		// 'RIFF' + size(4, ignored) + 'WEBP' + 'VP8X' + chunkSize(4) + flags(1) + 9 bytes canvas/reserved
		return 'RIFF' . "\x00\x00\x00\x00" . 'WEBP'
			. 'VP8X' . "\x0a\x00\x00\x00" . chr( $flags ) . str_repeat( "\x00", 9 );
	}

	private function writeTemp( string $bytes ): string {
		$path = tempnam( sys_get_temp_dir(), 'webpprobe' );
		file_put_contents( $path, $bytes );
		return $path;
	}

	public function testAnimatedFlagSet(): void {
		// Animation flag is bit 1 (0x02) of the VP8X flags byte.
		$path = $this->writeTemp( $this->vp8xHeader( 0x02 ) );
		try {
			$this->assertTrue( WebpProbe::isAnimated( $path ) );
		} finally {
			unlink( $path );
		}
	}

	public function testStaticVp8x(): void {
		// 0x10 is some other VP8X flag (e.g. alpha), not the animation bit.
		$path = $this->writeTemp( $this->vp8xHeader( 0x10 ) );
		try {
			$this->assertFalse( WebpProbe::isAnimated( $path ) );
		} finally {
			unlink( $path );
		}
	}

	public function testPlainStaticWebpHasNoVp8x(): void {
		// A simple-format WebP starts RIFF....WEBPVP8 (lossy), no VP8X chunk at all.
		$path = $this->writeTemp( 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8 ' . str_repeat( "\x00", 8 ) );
		try {
			$this->assertFalse( WebpProbe::isAnimated( $path ) );
		} finally {
			unlink( $path );
		}
	}

	public function testGarbageIsNotAnimated(): void {
		$path = $this->writeTemp( 'not an image' );
		try {
			$this->assertFalse( WebpProbe::isAnimated( $path ) );
		} finally {
			unlink( $path );
		}
	}

	public function testMissingFileIsNotAnimated(): void {
		// The @fopen-returns-false branch: a nonexistent path is simply not animated.
		$this->assertFalse( WebpProbe::isAnimated( '/tmp/thumbro-webpprobe-does-not-exist.webp' ) );
	}
}
