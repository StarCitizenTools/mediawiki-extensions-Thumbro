<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\MediaHandlers;

use File;
use MediaHandlerState;
use MediaWiki\Extension\Thumbro\Image\OrientationDetector;
use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroPNGHandler;
use MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroWebPHandler;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * vipsthumbnail auto-rotates every format from EXIF orientation, but core's WebPHandler and
 * PNGHandler — unlike JpegHandler — never swap their reported dimensions for it. Thumbro's
 * ThumbroExifOrientationTrait restores the swap so the served dimensions match the rotated
 * output. Follow-up to the #86 JPEG fix, for the formats canRotate() cannot reach.
 *
 * @covers \MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroExifOrientationTrait
 * @covers \MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroWebPHandler
 * @covers \MediaWiki\Extension\Thumbro\MediaHandlers\ThumbroPNGHandler
 * @group Thumbro
 */
class ThumbroExifOrientationTest extends MediaWikiIntegrationTestCase {

	private const DATA_DIR = __DIR__ . '/../../data';

	protected function setUp(): void {
		parent::setUp();
		// autoRotateEnabled() then derives from canRotate() (true), matching a default install.
		$this->overrideConfigValue( MainConfigNames::EnableAutoRotation, null );
	}

	/**
	 * A WebP carrying EXIF Orientation 8 (raw 160x120) must report the auto-rotated 120x160 so it
	 * matches what vipsthumbnail produces, and cache the 90° rotation for getRotation(). The
	 * orientation is read from the metadata core already parsed — no libvips probe.
	 */
	public function testWebpOrientedSwapsDimensions(): void {
		// WebP reads the orientation core parsed from the EXIF chunk, which core only does when
		// $wgShowEXIF is on (default: the exif extension is loaded). Pin both so the test is
		// deterministic, and skip where the extension is genuinely absent.
		if ( !function_exists( 'exif_read_data' ) ) {
			$this->markTestSkipped( 'WebP EXIF orientation requires the PHP exif extension.' );
		}
		$this->overrideConfigValue( MainConfigNames::ShowEXIF, true );

		$handler = new ThumbroWebPHandler();
		$info = $handler->getSizeAndMetadata( $this->createMock( MediaHandlerState::class ),
			self::DATA_DIR . '/oriented.webp' );

		$this->assertSame( 120, $info['width'], 'width/height must be swapped for a 90° orientation' );
		$this->assertSame( 160, $info['height'] );
		$this->assertSame( 90, $handler->getRotation( $this->fileWithMetadata( $info['metadata'] ) ) );
	}

	/**
	 * A WebP with no EXIF orientation must be left exactly as core reports it.
	 */
	public function testWebpPlainIsUnchanged(): void {
		$handler = new ThumbroWebPHandler();
		$info = $handler->getSizeAndMetadata( $this->createMock( MediaHandlerState::class ),
			self::DATA_DIR . '/plain.webp' );

		$this->assertSame( 200, $info['width'] );
		$this->assertSame( 100, $info['height'] );
		$this->assertSame( 0, $handler->getRotation( $this->fileWithMetadata( $info['metadata'] ) ) );
	}

	/**
	 * PNG orientation is not in core metadata, so the handler probes the injected
	 * OrientationDetector. A 270° result (orientation 6) swaps the raw 200x100 to 100x200.
	 */
	public function testPngUsesOrientationDetector(): void {
		$this->setService( 'Thumbro.OrientationDetector', $this->detectorReturning( 6 ) );

		$handler = new ThumbroPNGHandler();
		$info = $handler->getSizeAndMetadata( $this->createMock( MediaHandlerState::class ),
			self::DATA_DIR . '/plain.png' );

		$this->assertSame( 100, $info['width'] );
		$this->assertSame( 200, $info['height'] );
		$this->assertSame( 270, $handler->getRotation( $this->fileWithMetadata( $info['metadata'] ) ) );
	}

	/**
	 * A 180° orientation (EXIF 3) rotates content but does not change the aspect ratio, so the
	 * rotation must be cached for getRotation() while the reported dimensions stay put.
	 */
	public function testPng180DegreesCachesRotationWithoutSwap(): void {
		$this->setService( 'Thumbro.OrientationDetector', $this->detectorReturning( 3 ) );

		$handler = new ThumbroPNGHandler();
		$info = $handler->getSizeAndMetadata( $this->createMock( MediaHandlerState::class ),
			self::DATA_DIR . '/plain.png' );

		$this->assertSame( 200, $info['width'], '180° must not swap width/height' );
		$this->assertSame( 100, $info['height'] );
		$this->assertSame( 180, $handler->getRotation( $this->fileWithMetadata( $info['metadata'] ) ) );
	}

	/**
	 * An orientation the detector reports as upright (1) must leave dimensions untouched and add
	 * no rotation to the metadata.
	 */
	public function testPngUprightIsUnchanged(): void {
		$this->setService( 'Thumbro.OrientationDetector', $this->detectorReturning( 1 ) );

		$handler = new ThumbroPNGHandler();
		$info = $handler->getSizeAndMetadata( $this->createMock( MediaHandlerState::class ),
			self::DATA_DIR . '/plain.png' );

		$this->assertSame( 200, $info['width'] );
		$this->assertSame( 100, $info['height'] );
		$this->assertSame( 0, $handler->getRotation( $this->fileWithMetadata( $info['metadata'] ) ) );
	}

	/**
	 * getRotation() must respect $wgEnableAutoRotation = false even when a rotation is cached,
	 * mirroring ExifBitmapHandler.
	 */
	public function testGetRotationDisabledWhenAutoRotationOff(): void {
		$this->overrideConfigValue( MainConfigNames::EnableAutoRotation, false );

		$handler = new ThumbroWebPHandler();
		$file = $this->fileWithMetadata( [ '_thumbro_exif_rotation' => 90 ] );
		$this->assertSame( 0, $handler->getRotation( $file ) );
	}

	private function detectorReturning( int $orientation ): OrientationDetector {
		return new class( $orientation ) implements OrientationDetector {
			public function __construct( private readonly int $orientation ) {
			}

			public function getOrientation( string $srcPath ): int {
				return $this->orientation;
			}
		};
	}

	private function fileWithMetadata( array $metadata ): File {
		$file = $this->createMock( File::class );
		$file->method( 'getMetadataArray' )->willReturn( $metadata );
		return $file;
	}
}
