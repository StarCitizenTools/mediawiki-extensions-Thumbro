<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit;

use File;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Thumbro\Utils;
use MediaWikiUnitTestCase;
use TransformationalImageHandler;

/**
 * Characterization tests for Utils::getOptions — they pin CURRENT behaviour
 * (including its quirks) so a later refactor can be verified as behaviour-preserving.
 *
 * Key quirk under test: getThumbType() always reports image/webp, so getOptions
 * always matches the image/webp block; only `library` and `inputOptions` come from
 * the input-MIME block. That makes the image/webp block's `enabled`/`minArea`/`maxArea`
 * the effective gate for ALL inputs.
 *
 * @covers \MediaWiki\Extension\Thumbro\Utils
 */
class UtilsTest extends MediaWikiUnitTestCase {

	/** @param array $optionsOverride Replaces the ThumbroOptions map when given. */
	private function config( array $optionsOverride = [] ): HashConfig {
		return new HashConfig( [
			'ThumbroLibraries' => [
				'libvips' => [ 'command' => '/usr/bin/vipsthumbnail' ],
				'libwebp' => [ 'command' => '/usr/bin/gif2webp' ],
			],
			'ThumbroOptions' => $optionsOverride ?: [
				'image/gif' => [ 'enabled' => true, 'library' => 'libwebp',
					'inputOptions' => [ 'n' => '-1' ], 'outputOptions' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ] ],
				'image/jpeg' => [ 'enabled' => true, 'library' => 'libvips', 'inputOptions' => [] ],
				'image/png' => [ 'enabled' => true, 'library' => 'libvips', 'inputOptions' => [] ],
				'image/webp' => [ 'enabled' => true, 'library' => 'libvips',
					'inputOptions' => [],
					'outputOptions' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ] ],
			],
		] );
	}

	private function handler( int $area = 1000 ): TransformationalImageHandler {
		$h = $this->createMock( TransformationalImageHandler::class );
		// getThumbType always reports image/webp regardless of source — the central quirk.
		$h->method( 'getThumbType' )->willReturn( [ 'webp', 'image/webp' ] );
		$h->method( 'getImageArea' )->willReturn( $area );
		return $h;
	}

	private function file( string $mime, string $ext, bool $multipage = false ): File {
		$f = $this->createMock( File::class );
		$f->method( 'getMimeType' )->willReturn( $mime );
		$f->method( 'getExtension' )->willReturn( $ext );
		$f->method( 'isMultipage' )->willReturn( $multipage );
		return $f;
	}

	public function testGifResolvesLibwebpLibrary(): void {
		$opts = Utils::getOptions( $this->handler(), $this->file( 'image/gif', 'gif' ), $this->config() );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libwebp', $opts['library'] );
		$this->assertSame( [ 'n' => '-1' ], $opts['inputOptions'] );
		// webp-block output options still resolved (the matched block is always image/webp).
		$this->assertSame( '90', $opts['outputOptions']['Q'] );
		// command resolves to the selected library's binary.
		$this->assertSame( '/usr/bin/gif2webp', $opts['command'] );
	}

	public function testJpegResolvesLibvipsLibrary(): void {
		$opts = Utils::getOptions( $this->handler(), $this->file( 'image/jpeg', 'jpg' ), $this->config() );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libvips', $opts['library'] );
		$this->assertSame( '/usr/bin/vipsthumbnail', $opts['command'] );
		$this->assertSame( [], $opts['inputOptions'] );
	}

	public function testPngResolvesLibvipsLibrary(): void {
		$opts = Utils::getOptions( $this->handler(), $this->file( 'image/png', 'png' ), $this->config() );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libvips', $opts['library'] );
	}

	public function testWebpResolvesLibvipsLibrary(): void {
		$opts = Utils::getOptions( $this->handler(), $this->file( 'image/webp', 'webp' ), $this->config() );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libvips', $opts['library'] );
		$this->assertSame( 'true', $opts['outputOptions']['strip'] );
	}

	public function testReturnsNullWhenWebpBlockDisabled(): void {
		// The matched (image/webp) block gates everything; disabling it disables Thumbro
		// for ALL inputs, even a gif. This is a current quirk, pinned here.
		$opts = $this->config( [
			'image/gif' => [ 'enabled' => true, 'library' => 'libwebp', 'inputOptions' => [] ],
			'image/webp' => [ 'enabled' => false, 'library' => 'libvips', 'inputOptions' => [], 'outputOptions' => [] ],
		] );
		$this->assertNull( Utils::getOptions( $this->handler(), $this->file( 'image/gif', 'gif' ), $opts ) );
	}

	public function testReturnsNullForMultipageFile(): void {
		$opts = Utils::getOptions(
			$this->handler(),
			$this->file( 'image/webp', 'webp', true ),
			$this->config()
		);
		$this->assertNull( $opts );
	}

	public function testReturnsNullWhenAreaAtOrAboveMaxArea(): void {
		// maxArea is read from the matched (image/webp) block; area >= maxArea => null.
		$cfg = $this->config( [
			'image/webp' => [ 'enabled' => true, 'library' => 'libvips', 'maxArea' => 500,
				'inputOptions' => [], 'outputOptions' => [] ],
		] );
		$this->assertNull( Utils::getOptions( $this->handler( 1000 ), $this->file( 'image/webp', 'webp' ), $cfg ) );
		// Below the cap it is handled.
		$this->assertNotNull( Utils::getOptions( $this->handler( 100 ), $this->file( 'image/webp', 'webp' ), $cfg ) );
	}

	public function testReturnsNullWhenAreaBelowMinArea(): void {
		$cfg = $this->config( [
			'image/webp' => [ 'enabled' => true, 'library' => 'libvips', 'minArea' => 2000,
				'inputOptions' => [], 'outputOptions' => [] ],
		] );
		$this->assertNull( Utils::getOptions( $this->handler( 1000 ), $this->file( 'image/webp', 'webp' ), $cfg ) );
	}

	public function testReturnsNullWhenSelectedLibraryUnknown(): void {
		// library resolves from the input-MIME block; an unregistered library => null.
		$cfg = $this->config( [
			'image/jpeg' => [ 'enabled' => true, 'library' => 'libdoesnotexist', 'inputOptions' => [] ],
			'image/webp' => [ 'enabled' => true, 'library' => 'libvips', 'inputOptions' => [], 'outputOptions' => [] ],
		] );
		$this->assertNull( Utils::getOptions( $this->handler(), $this->file( 'image/jpeg', 'jpg' ), $cfg ) );
	}

	public function testUnconfiguredInputMimeFallsThroughToWebpBlockLibrary(): void {
		// An input MIME with no own block still gets handled via the image/webp block:
		// library falls back to the webp block's, inputOptions to []. Pinned quirk.
		$opts = Utils::getOptions( $this->handler(), $this->file( 'image/tiff', 'tiff' ), $this->config() );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libvips', $opts['library'] );
		$this->assertSame( [], $opts['inputOptions'] );
	}
}
