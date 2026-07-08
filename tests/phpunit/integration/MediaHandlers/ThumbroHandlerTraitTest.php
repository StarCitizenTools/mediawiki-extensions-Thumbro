<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\MediaHandlers;

use File;
use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroGIFHandler;
use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroJpegHandler;
use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroPNGHandler;
use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroWebPHandler;
use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroHandlerTrait
 * @group Thumbro
 */
class ThumbroHandlerTraitTest extends MediaWikiIntegrationTestCase {

	private function makeFile( int $width, int $height, string $url ): File {
		$file = $this->createMock( File::class );
		$file->method( 'getWidth' )->willReturn( $width );
		$file->method( 'getHeight' )->willReturn( $height );
		$file->method( 'pageCount' )->willReturn( 1 );
		$file->method( 'getMimeType' )->willReturn( 'image/webp' );
		$file->method( 'mustRender' )->willReturn( false );
		$file->method( 'getUrl' )->willReturn( $url );
		return $file;
	}

	/**
	 * When a deferred (TRANSFORM_LATER) transform requests a size at or above the source
	 * dimensions — e.g. the responsive 1.5x/2x variants of an image smaller than the
	 * requested density — the output must point at the original file, not a thumbnail URL
	 * that thumb_handler.php would reject with HTTP 400 when $wgGenerateThumbnailOnParse is
	 * false.
	 *
	 * Regression test for https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/issues/51
	 *
	 * @dataProvider provideUnscaledWidths
	 */
	public function testTransformLaterReturnsOriginalWhenNotUpscalable( int $requestedWidth ): void {
		$originalUrl = '/w/images/f/f5/Anvil_Carrack.webp';
		$thumbUrl = '/w/images/thumb/f/f5/Anvil_Carrack.webp/' . $requestedWidth . 'px-Anvil_Carrack.webp';
		$file = $this->makeFile( 144, 144, $originalUrl );

		$handler = new ThumbroWebPHandler();
		$result = $handler->doTransform(
			$file,
			'/tmp/thumb.webp',
			$thumbUrl,
			[ 'width' => $requestedWidth ],
			ThumbroWebPHandler::TRANSFORM_LATER
		);

		$this->assertInstanceOf( ThumbroThumbnailImage::class, $result );
		$this->assertSame(
			$originalUrl,
			$result->getUrl(),
			'A non-upscalable deferred transform must serve the original file URL'
		);
	}

	public static function provideUnscaledWidths(): array {
		return [
			'exactly source size (1.5x of a 96px display)' => [ 144 ],
			'larger than source (2x of a 96px display)' => [ 192 ],
		];
	}

	/**
	 * A genuine downscale (requested width below the source) must still defer to a
	 * thumbnail URL.
	 */
	public function testTransformLaterReturnsThumbnailWhenDownscaling(): void {
		$thumbUrl = '/w/images/thumb/f/f5/Anvil_Carrack.webp/96px-Anvil_Carrack.webp';
		$file = $this->makeFile( 144, 144, '/w/images/f/f5/Anvil_Carrack.webp' );

		$handler = new ThumbroWebPHandler();
		$result = $handler->doTransform(
			$file,
			'/tmp/thumb.webp',
			$thumbUrl,
			[ 'width' => 96 ],
			ThumbroWebPHandler::TRANSFORM_LATER
		);

		$this->assertInstanceOf( ThumbroThumbnailImage::class, $result );
		$this->assertSame(
			$thumbUrl,
			$result->getUrl(),
			'A downscaling deferred transform must point at the thumbnail URL'
		);
	}

	/**
	 * A forced low-quality re-render must still produce a thumbnail URL even when the
	 * requested size matches the source, mirroring core's exclusion of the unscaled
	 * shortcut when a quality override is set.
	 */
	public function testTransformLaterRendersAtSourceSizeForLowQuality(): void {
		$thumbUrl = '/w/images/thumb/f/f5/Anvil_Carrack.webp/144px-Anvil_Carrack.webp';
		$file = $this->makeFile( 144, 144, '/w/images/f/f5/Anvil_Carrack.webp' );

		$handler = new ThumbroWebPHandler();
		$result = $handler->doTransform(
			$file,
			'/tmp/thumb.webp',
			$thumbUrl,
			[ 'width' => 144, 'quality' => 'low' ],
			ThumbroWebPHandler::TRANSFORM_LATER
		);

		$this->assertInstanceOf( ThumbroThumbnailImage::class, $result );
		$this->assertSame(
			$thumbUrl,
			$result->getUrl(),
			'A low-quality deferred transform must render rather than serve the original'
		);
	}

	/**
	 * getScalerType() returns 'libvips', which core's BitmapHandler::canRotate() does not
	 * recognise. The trait must override canRotate() to report true so MediaWiki applies EXIF
	 * autorotation (dimension swap) rather than reporting raw sensor dimensions.
	 *
	 * Regression test for https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/issues/86
	 *
	 * @dataProvider provideHandlers
	 */
	public function testCanRotateIsTrue( string $handlerClass ): void {
		$handler = new $handlerClass();
		$this->assertTrue(
			$handler->canRotate(),
			"$handlerClass must advertise rotation support so core auto-rotates EXIF-oriented images"
		);
	}

	public static function provideHandlers(): array {
		return [
			'JPEG' => [ ThumbroJpegHandler::class ],
			'PNG' => [ ThumbroPNGHandler::class ],
			'GIF' => [ ThumbroGIFHandler::class ],
			'WebP' => [ ThumbroWebPHandler::class ],
		];
	}

	/**
	 * With canRotate() true and $wgEnableAutoRotation left at its default (null), the JPEG
	 * handler must honour the EXIF Orientation tag: autoRotateEnabled() derives from canRotate(),
	 * and getRotation() then reports the angle core uses to swap width/height. If canRotate()
	 * regressed to false, autoRotateEnabled() would be false and every case below would return 0.
	 *
	 * Regression test for https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/issues/86
	 *
	 * @dataProvider provideExifOrientation
	 */
	public function testGetRotationHonoursExifOrientation( ?int $orientation, int $expectedRotation ): void {
		$this->overrideConfigValue( MainConfigNames::EnableAutoRotation, null );

		$handler = new ThumbroJpegHandler();
		$this->assertTrue( $handler->autoRotateEnabled(), 'autoRotateEnabled() must derive true from canRotate()' );

		$file = $this->createMock( File::class );
		$file->method( 'getMetadataItem' )->willReturn( $orientation );

		$this->assertSame( $expectedRotation, $handler->getRotation( $file ) );
	}

	public static function provideExifOrientation(): array {
		return [
			'no orientation tag' => [ null, 0 ],
			'orientation 1 (normal)' => [ 1, 0 ],
			'orientation 8 (rotate 90)' => [ 8, 90 ],
			'orientation 3 (rotate 180)' => [ 3, 180 ],
			'orientation 6 (rotate 270)' => [ 6, 270 ],
		];
	}
}
