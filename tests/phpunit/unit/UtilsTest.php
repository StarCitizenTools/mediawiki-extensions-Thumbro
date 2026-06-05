<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit;

use File;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Thumbro\Utils;
use MediaWikiUnitTestCase;
use TransformationalImageHandler;

/**
 * @covers \MediaWiki\Extension\Thumbro\Utils
 */
class UtilsTest extends MediaWikiUnitTestCase {
	private function config(): HashConfig {
		return new HashConfig( [
			'ThumbroLibraries' => [
				'libvips' => [ 'command' => '/usr/bin/vipsthumbnail' ],
				'libwebp' => [ 'command' => '/usr/bin/gif2webp' ],
			],
			'ThumbroOptions' => [
				'image/gif' => [ 'enabled' => true, 'library' => 'libwebp',
					'inputOptions' => [ 'n' => '-1' ], 'outputOptions' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ] ],
				'image/jpeg' => [ 'enabled' => true, 'library' => 'libvips', 'inputOptions' => [] ],
				'image/webp' => [ 'enabled' => true, 'library' => 'libvips',
					'inputOptions' => [],
					'outputOptions' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ] ],
			],
		] );
	}

	private function handler(): TransformationalImageHandler {
		$h = $this->createMock( TransformationalImageHandler::class );
		$h->method( 'getThumbType' )->willReturn( [ 'webp', 'image/webp' ] );
		$h->method( 'getImageArea' )->willReturn( 1000 );
		return $h;
	}

	private function file( string $mime, string $ext ): File {
		$f = $this->createMock( File::class );
		$f->method( 'getMimeType' )->willReturn( $mime );
		$f->method( 'getExtension' )->willReturn( $ext );
		$f->method( 'isMultipage' )->willReturn( false );
		return $f;
	}

	public function testGifResolvesLibwebpLibrary(): void {
		$opts = Utils::getOptions( $this->handler(), $this->file( 'image/gif', 'gif' ), $this->config() );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libwebp', $opts['library'] );
		$this->assertSame( [ 'n' => '-1' ], $opts['inputOptions'] );
		// webp-block output options still resolved (unchanged behaviour).
		$this->assertSame( '90', $opts['outputOptions']['Q'] );
	}

	public function testJpegResolvesLibvipsLibrary(): void {
		$opts = Utils::getOptions( $this->handler(), $this->file( 'image/jpeg', 'jpg' ), $this->config() );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libvips', $opts['library'] );
	}
}
