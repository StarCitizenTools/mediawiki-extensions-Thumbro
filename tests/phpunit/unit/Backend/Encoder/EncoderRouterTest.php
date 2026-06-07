<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend\Encoder;

use MediaWiki\Extension\Thumbro\Backend\Encoder\EncoderRouter;
use MediaWiki\Extension\Thumbro\Backend\Encoder\FileTraits;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\Encoder\EncoderRouter
 * @covers \MediaWiki\Extension\Thumbro\Backend\Encoder\EncodeChoice
 * @covers \MediaWiki\Extension\Thumbro\Backend\Encoder\FileTraits
 */
class EncoderRouterTest extends MediaWikiUnitTestCase {

	private function gifList(): array {
		return [
			[
				'encoder' => 'gif2webp',
				'when' => [ 'animated' => true, 'alpha' => true, 'underThreshold' => true ],
				'options' => [ 'q' => '80' ],
			],
			[
				'encoder' => 'vips-webp',
				'when' => [ 'animated' => true, 'underThreshold' => true ],
				'options' => [ 'Q' => '90' ],
			],
			[ 'encoder' => 'vips-webp', 'options' => [ 'Q' => '90' ] ],
		];
	}

	public function testTransparentAnimatedUnderThresholdPicksGif2webp(): void {
		$choice = ( new EncoderRouter() )->choose(
			$this->gifList(),
			new FileTraits( animated: true, hasAlpha: true, underThreshold: true )
		);
		$this->assertSame( 'gif2webp', $choice->encoder );
	}

	public function testOpaqueAnimatedPicksVipsAnimated(): void {
		$choice = ( new EncoderRouter() )->choose(
			$this->gifList(),
			new FileTraits( animated: true, hasAlpha: false, underThreshold: true )
		);
		$this->assertSame( 'vips-webp', $choice->encoder );
		$this->assertSame( [ 'animated' => true, 'underThreshold' => true ], $choice->when );
	}

	public function testStaticPicksCatchAll(): void {
		$choice = ( new EncoderRouter() )->choose(
			$this->gifList(),
			new FileTraits( animated: false, hasAlpha: false, underThreshold: false )
		);
		$this->assertSame( 'vips-webp', $choice->encoder );
		$this->assertSame( [], $choice->when );
	}

	public function testOverThresholdAnimatedFallsToCatchAll(): void {
		$choice = ( new EncoderRouter() )->choose(
			$this->gifList(),
			new FileTraits( animated: true, hasAlpha: true, underThreshold: false )
		);
		$this->assertSame( 'vips-webp', $choice->encoder );
		$this->assertSame( [], $choice->when );
	}

	public function testUnknownWhenKeyThrows(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new EncoderRouter() )->choose(
			[ [ 'encoder' => 'vips-webp', 'when' => [ 'animted' => true ] ] ],
			new FileTraits( animated: true, hasAlpha: false, underThreshold: true )
		);
	}
}
