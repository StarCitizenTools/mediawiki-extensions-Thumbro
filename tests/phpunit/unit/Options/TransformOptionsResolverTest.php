<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Options;

use File;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Thumbro\Options\TransformOptionsResolver;
use MediaWikiUnitTestCase;
use TransformationalImageHandler;

/**
 * Characterization tests for TransformOptionsResolver::resolve — they pin CURRENT behaviour
 * (including its quirks) so this DI refactor is verifiably behaviour-preserving. Ported from
 * the former UtilsTest.
 *
 * Key quirk under test: getThumbType() always reports image/webp, so resolve() always matches
 * the image/webp block; only `library` and `inputOptions` come from the input-MIME block. That
 * makes the image/webp block's `enabled`/`minArea`/`maxArea` the effective gate for ALL inputs.
 *
 * @covers \MediaWiki\Extension\Thumbro\Options\TransformOptionsResolver
 */
class TransformOptionsResolverTest extends MediaWikiUnitTestCase {

	/** @param array $optionsOverride Replaces the ThumbroOptions map when given. */
	private function resolver( array $optionsOverride = [] ): TransformOptionsResolver {
		return new TransformOptionsResolver( new HashConfig( [
			'ThumbroLibraries' => [
				'libvips' => [ 'command' => '/usr/bin/vipsthumbnail' ],
				'libwebp' => [ 'command' => '/usr/bin/gif2webp' ],
			],
			'ThumbroOptions' => $optionsOverride ?: [
				// gif2webp encoder flags now live on the libwebp library, not here; the gif block
				// carries no outputOptions and its delegation webpsave falls back to the webp block.
				'image/gif' => [ 'enabled' => true, 'library' => 'libwebp', 'inputOptions' => [ 'n' => '-1' ] ],
				'image/jpeg' => [ 'enabled' => true, 'library' => 'libvips', 'inputOptions' => [] ],
				'image/png' => [ 'enabled' => true, 'library' => 'libvips', 'inputOptions' => [] ],
				'image/webp' => [ 'enabled' => true, 'library' => 'libvips',
					'inputOptions' => [],
					'outputOptions' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ] ],
			],
		] ) );
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
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/gif', 'gif' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libwebp', $opts->library() );
		$this->assertSame( [ 'n' => '-1' ], $opts->inputOptions() );
		// webp-block output options still resolved (the matched block is always image/webp).
		$this->assertSame( '90', $opts->outputOptions()['Q'] );
		// command resolves to the selected library's binary.
		$this->assertSame( '/usr/bin/gif2webp', $opts->command() );
	}

	public function testJpegResolvesLibvipsLibrary(): void {
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/jpeg', 'jpg' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libvips', $opts->library() );
		$this->assertSame( '/usr/bin/vipsthumbnail', $opts->command() );
		$this->assertSame( [], $opts->inputOptions() );
	}

	public function testPngResolvesLibvipsLibrary(): void {
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/png', 'png' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libvips', $opts->library() );
	}

	public function testWebpResolvesLibvipsLibrary(): void {
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/webp', 'webp' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libvips', $opts->library() );
		$this->assertSame( 'true', $opts->outputOptions()['strip'] );
	}

	public function testReturnsNullWhenWebpBlockDisabled(): void {
		// The matched (image/webp) block gates everything; disabling it disables Thumbro
		// for ALL inputs, even a gif. This is a current quirk, pinned here.
		$resolver = $this->resolver( [
			'image/gif' => [ 'enabled' => true, 'library' => 'libwebp', 'inputOptions' => [] ],
			'image/webp' => [ 'enabled' => false, 'library' => 'libvips', 'inputOptions' => [], 'outputOptions' => [] ],
		] );
		$this->assertNull( $resolver->resolve( $this->handler(), $this->file( 'image/gif', 'gif' ) ) );
	}

	public function testReturnsNullForMultipageFile(): void {
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/webp', 'webp', true ) );
		$this->assertNull( $opts );
	}

	public function testReturnsNullWhenAreaAtOrAboveMaxArea(): void {
		// maxArea is read from the matched (image/webp) block; area >= maxArea => null.
		$resolver = $this->resolver( [
			'image/webp' => [ 'enabled' => true, 'library' => 'libvips', 'maxArea' => 500,
				'inputOptions' => [], 'outputOptions' => [] ],
		] );
		$this->assertNull( $resolver->resolve( $this->handler( 1000 ), $this->file( 'image/webp', 'webp' ) ) );
		// Below the cap it is handled.
		$this->assertNotNull( $resolver->resolve( $this->handler( 100 ), $this->file( 'image/webp', 'webp' ) ) );
	}

	public function testReturnsNullWhenAreaBelowMinArea(): void {
		$resolver = $this->resolver( [
			'image/webp' => [ 'enabled' => true, 'library' => 'libvips', 'minArea' => 2000,
				'inputOptions' => [], 'outputOptions' => [] ],
		] );
		$this->assertNull( $resolver->resolve( $this->handler( 1000 ), $this->file( 'image/webp', 'webp' ) ) );
	}

	public function testReturnsNullWhenSelectedLibraryUnknown(): void {
		// library resolves from the input-MIME block; an unregistered library => null.
		$resolver = $this->resolver( [
			'image/jpeg' => [ 'enabled' => true, 'library' => 'libdoesnotexist', 'inputOptions' => [] ],
			'image/webp' => [ 'enabled' => true, 'library' => 'libvips', 'inputOptions' => [], 'outputOptions' => [] ],
		] );
		$this->assertNull( $resolver->resolve( $this->handler(), $this->file( 'image/jpeg', 'jpg' ) ) );
	}

	public function testUnconfiguredInputMimeFallsThroughToWebpBlockLibrary(): void {
		// An input MIME with no own block still gets handled via the image/webp block:
		// library falls back to the webp block's, inputOptions to []. Pinned quirk.
		$opts = $this->resolver()->resolve( $this->handler(), $this->file( 'image/tiff', 'tiff' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( 'libvips', $opts->library() );
		$this->assertSame( [], $opts->inputOptions() );
	}

	public function testLibvipsBlockUsesItsOwnOutputOptionsWhenSet(): void {
		// The new capability: a libvips MIME block carries its own webpsave flags, taken in
		// preference to the webp-block fallback.
		$resolver = $this->resolver( [
			'image/jpeg' => [ 'enabled' => true, 'library' => 'libvips',
				'inputOptions' => [], 'outputOptions' => [ 'Q' => '82', 'strip' => 'true' ] ],
			'image/webp' => [ 'enabled' => true, 'library' => 'libvips',
				'inputOptions' => [],
				'outputOptions' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ] ],
		] );
		$opts = $resolver->resolve( $this->handler(), $this->file( 'image/jpeg', 'jpg' ) );
		$this->assertNotNull( $opts );
		$this->assertSame( '82', $opts->outputOptions()['Q'], 'jpeg uses its own webpsave Q' );
	}

	public function testLibwebpBlockOutputOptionsAreNotLeakedAsWebpsave(): void {
		// Back-compat guard: an old-style config still carries gif2webp flags under
		// image/gif.outputOptions. Those must NOT surface as the resolved (webpsave) outputOptions;
		// the libwebp delegation uses the webp block's webpsave flags instead.
		$resolver = $this->resolver( [
			'image/gif' => [ 'enabled' => true, 'library' => 'libwebp',
				'inputOptions' => [ 'n' => '-1' ], 'outputOptions' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ] ],
			'image/webp' => [ 'enabled' => true, 'library' => 'libvips',
				'inputOptions' => [],
				'outputOptions' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ] ],
		] );
		$opts = $resolver->resolve( $this->handler(), $this->file( 'image/gif', 'gif' ) );
		$this->assertNotNull( $opts );
		$this->assertSame(
			[ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ],
			$opts->outputOptions(),
			'libwebp delegation uses webp-block webpsave flags, not the gif2webp flags'
		);
		$this->assertArrayNotHasKey( 'mixed', $opts->outputOptions() );
	}
}
