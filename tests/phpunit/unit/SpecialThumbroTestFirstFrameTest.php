<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit;

use Imagick;
use ImagickPixel;
use MediaWiki\Extension\Thumbro\SpecialThumbroTest;
use MediaWikiUnitTestCase;
use ReflectionMethod;

/**
 * Special:ThumbroTest measures and compares the first frame of each image. After reading an
 * animation Imagick points at its last frame, which in an optimised animation is only the
 * part of the canvas that changed, so animated thumbnails were reported at the wrong size
 * and compared against the wrong pixels.
 *
 * @covers \MediaWiki\Extension\Thumbro\SpecialThumbroTest
 * @group Thumbro
 */
class SpecialThumbroTestFirstFrameTest extends MediaWikiUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( !extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'The comparison metrics need the imagick extension.' );
		}
	}

	public function testUsesFirstFrameOfOptimisedAnimation(): void {
		// A full 20x20 red frame, then a 5x5 blue update at +10+10
		$animation = $this->readGif( [
			$this->frame( 20, 20, 0, 0, 'red' ),
			$this->frame( 5, 5, 10, 10, 'blue' ),
		] );
		$this->assertSame( 5, $animation->getImageWidth(), 'precondition: Imagick points at the last frame' );

		$frame = $this->getFirstFrame( $animation );

		$this->assertSame( 1, $frame->getNumberImages() );
		$this->assertSame( [ 20, 20 ], [ $frame->getImageWidth(), $frame->getImageHeight() ] );
		$this->assertSame( 'red', $this->colorAt( $frame, 12, 12 ) );
	}

	public function testPlacesOffsetFirstFrameOnItsCanvas(): void {
		// Kept in memory: a GIF can only fill its canvas with a palette colour, here lime
		$animation = $this->sequence( [
			$this->frame( 5, 5, 3, 3, 'lime' ),
			$this->frame( 5, 5, 10, 10, 'blue' ),
		] );

		$frame = $this->getFirstFrame( $animation );

		$this->assertSame( [ 20, 20 ], [ $frame->getImageWidth(), $frame->getImageHeight() ] );
		$this->assertSame( 'lime', $this->colorAt( $frame, 4, 4 ) );
		$this->assertSame( 'white', $this->colorAt( $frame, 0, 0 ), 'the rest of the canvas is background' );
	}

	public function testLeavesStillImageUnchanged(): void {
		$image = $this->readGif( [ $this->frame( 20, 10, 0, 0, 'red', 20, 10 ) ] );

		$frame = $this->getFirstFrame( $image );

		$this->assertSame( [ 20, 10 ], [ $frame->getImageWidth(), $frame->getImageHeight() ] );
		$this->assertSame( 'red', $this->colorAt( $frame, 0, 0 ) );
	}

	/**
	 * A frame of $width x $height at +$x+$y on a canvas, on a white background
	 */
	private function frame(
		int $width, int $height, int $x, int $y, string $color, int $canvasWidth = 20, int $canvasHeight = 20
	): Imagick {
		$frame = new Imagick();
		$frame->newImage( $width, $height, new ImagickPixel( $color ) );
		$frame->setImageBackgroundColor( new ImagickPixel( 'white' ) );
		$frame->setImagePage( $canvasWidth, $canvasHeight, $x, $y );
		$frame->setImageDispose( Imagick::DISPOSE_NONE );
		$frame->setImageFormat( 'gif' );
		return $frame;
	}

	/**
	 * @param Imagick[] $frames
	 */
	private function sequence( array $frames ): Imagick {
		$sequence = new Imagick();
		foreach ( $frames as $frame ) {
			$sequence->addImage( $frame );
		}
		return $sequence;
	}

	/**
	 * Write the frames as a GIF and read it back, as the special page reads its images
	 *
	 * @param Imagick[] $frames
	 */
	private function readGif( array $frames ): Imagick {
		$image = new Imagick();
		$image->readImageBlob( $this->sequence( $frames )->getImagesBlob() );
		return $image;
	}

	private function getFirstFrame( Imagick $image ): Imagick {
		$method = new ReflectionMethod( SpecialThumbroTest::class, 'getFirstFrame' );
		return $method->invoke( null, $image );
	}

	private function colorAt( Imagick $image, int $x, int $y ): string {
		$color = $image->getImagePixelColor( $x, $y )->getColor();
		if ( $color['a'] === 0 ) {
			return 'transparent';
		}
		return match ( [ $color['r'], $color['g'], $color['b'] ] ) {
			[ 255, 0, 0 ] => 'red',
			[ 0, 255, 0 ] => 'lime',
			[ 0, 0, 255 ] => 'blue',
			[ 255, 255, 255 ] => 'white',
			default => 'other',
		};
	}
}
