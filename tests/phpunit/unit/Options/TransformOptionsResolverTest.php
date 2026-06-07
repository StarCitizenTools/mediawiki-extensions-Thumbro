<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Options;

use File;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Thumbro\Options\TransformOptionsResolver;
use MediaWikiUnitTestCase;
use TransformationalImageHandler;

/**
 * Tests for TransformOptionsResolver::resolve against the per-input-MIME schema.
 *
 * The resolver keys directly on the INPUT MIME block: it gates on that block's
 * enabled/minArea/maxArea/multipage and returns its resize options + encode list verbatim. There
 * is no longer a getThumbType/image-webp indirection, so gating is per-input-MIME (disabling
 * image/webp does NOT disable image/gif), and an unconfigured input MIME resolves to null.
 *
 * @covers \MediaWiki\Extension\Thumbro\Options\TransformOptionsResolver
 */
class TransformOptionsResolverTest extends MediaWikiUnitTestCase {

	/** The migrated production encode lists (values identical to extension.json). */
	private const GIF_ENCODE = [
		[
			'encoder' => 'gif2webp',
			'when' => [ 'animated' => true, 'alpha' => true, 'underThreshold' => true ],
			'options' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ],
		],
		[
			'encoder' => 'vips-webp',
			'when' => [ 'animated' => true, 'underThreshold' => true ],
			'options' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ],
		],
		[
			'encoder' => 'vips-webp',
			'options' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ],
		],
	];
	private const JPEG_ENCODE = [
		[
			'encoder' => 'vips-webp',
			'options' => [ 'strip' => 'true', 'Q' => '80', 'smart_subsample' => 'false', 'effort' => '6' ],
		],
	];
	private const PNG_ENCODE = [
		[ 'encoder' => 'vips-webp', 'options' => [ 'near_lossless' => 'true', 'Q' => '60', 'strip' => 'true' ] ],
	];
	private const WEBP_ENCODE = [
		[ 'encoder' => 'vips-webp', 'options' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ] ],
	];

	/** @param array $optionsOverride Replaces the ThumbroOptions map when given. */
	private function resolver( array $optionsOverride = [] ): TransformOptionsResolver {
		return new TransformOptionsResolver( new HashConfig( [
			'ThumbroOptions' => $optionsOverride ?: [
				'image/gif' => [ 'enabled' => true, 'resize' => [ 'options' => [] ], 'encode' => self::GIF_ENCODE ],
				'image/jpeg' => [ 'enabled' => true, 'resize' => [ 'options' => [] ], 'encode' => self::JPEG_ENCODE ],
				'image/png' => [ 'enabled' => true, 'resize' => [ 'options' => [] ], 'encode' => self::PNG_ENCODE ],
				'image/webp' => [ 'enabled' => true, 'resize' => [ 'options' => [] ], 'encode' => self::WEBP_ENCODE ],
			],
		] ) );
	}

	private function handler( int $area = 1000 ): TransformationalImageHandler {
		$h = $this->createMock( TransformationalImageHandler::class );
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

	public function testGifResolvesToTheThreeEntryEncodeList(): void {
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/gif', 'gif' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( self::GIF_ENCODE, $opts->encodeList() );
		$this->assertSame( [], $opts->resizeOptions() );
	}

	public function testJpegResolvesToOneEntryEncodeList(): void {
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/jpeg', 'jpg' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( self::JPEG_ENCODE, $opts->encodeList() );
		$this->assertSame( [], $opts->resizeOptions() );
	}

	public function testPngResolvesToOneEntryEncodeList(): void {
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/png', 'png' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( self::PNG_ENCODE, $opts->encodeList() );
	}

	public function testWebpResolvesToOneEntryEncodeList(): void {
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/webp', 'webp' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( self::WEBP_ENCODE, $opts->encodeList() );
	}

	public function testResizeOptionsAreReturned(): void {
		$resolver = $this->resolver( [
			'image/gif' => [
				'enabled' => true,
				'resize' => [ 'options' => [ 'n' => '-1' ] ],
				'encode' => self::GIF_ENCODE,
			],
		] );
		$opts = $resolver->resolve( $this->handler(), $this->file( 'image/gif', 'gif' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( [ 'n' => '-1' ], $opts->resizeOptions() );
	}

	public function testReturnsNullWhenBlockDisabled(): void {
		$resolver = $this->resolver( [
			'image/jpeg' => [ 'enabled' => false, 'resize' => [ 'options' => [] ], 'encode' => self::JPEG_ENCODE ],
		] );
		$this->assertNull( $resolver->resolve( $this->handler(), $this->file( 'image/jpeg', 'jpg' ) ) );
	}

	public function testGatingIsPerInputMime(): void {
		// New behaviour: disabling image/webp does NOT disable image/gif (no webp indirection).
		$resolver = $this->resolver( [
			'image/gif' => [ 'enabled' => true, 'resize' => [ 'options' => [] ], 'encode' => self::GIF_ENCODE ],
			'image/webp' => [ 'enabled' => false, 'resize' => [ 'options' => [] ], 'encode' => self::WEBP_ENCODE ],
		] );
		$this->assertNotNull( $resolver->resolve( $this->handler(), $this->file( 'image/gif', 'gif' ) ) );
		$this->assertNull( $resolver->resolve( $this->handler(), $this->file( 'image/webp', 'webp' ) ) );
	}

	public function testReturnsNullForUnconfiguredInputMime(): void {
		// New behaviour: an input MIME with no block resolves to null (no webp fallback).
		$this->assertNull( $this->resolver()->resolve( $this->handler(), $this->file( 'image/tiff', 'tiff' ) ) );
	}

	public function testReturnsNullForMultipageFile(): void {
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/jpeg', 'jpg', true ) );
		$this->assertNull( $opts );
	}

	public function testReturnsNullWhenAreaAtOrAboveMaxArea(): void {
		$resolver = $this->resolver( [
			'image/jpeg' => [
				'enabled' => true, 'maxArea' => 500,
				'resize' => [ 'options' => [] ], 'encode' => self::JPEG_ENCODE,
			],
		] );
		$this->assertNull( $resolver->resolve( $this->handler( 1000 ), $this->file( 'image/jpeg', 'jpg' ) ) );
		// Below the cap it is handled.
		$this->assertNotNull( $resolver->resolve( $this->handler( 100 ), $this->file( 'image/jpeg', 'jpg' ) ) );
	}

	public function testReturnsNullWhenAreaBelowMinArea(): void {
		$resolver = $this->resolver( [
			'image/jpeg' => [
				'enabled' => true, 'minArea' => 2000,
				'resize' => [ 'options' => [] ], 'encode' => self::JPEG_ENCODE,
			],
		] );
		$this->assertNull( $resolver->resolve( $this->handler( 1000 ), $this->file( 'image/jpeg', 'jpg' ) ) );
	}

	public function testReturnsNullWhenEncodeListEmpty(): void {
		$resolver = $this->resolver( [
			'image/jpeg' => [ 'enabled' => true, 'resize' => [ 'options' => [] ], 'encode' => [] ],
		] );
		$this->assertNull( $resolver->resolve( $this->handler(), $this->file( 'image/jpeg', 'jpg' ) ) );
	}
}
