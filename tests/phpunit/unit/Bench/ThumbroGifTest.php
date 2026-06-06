<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Backend\LibwebpBackend;
use MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroGif;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroGif
 */
class ThumbroGifTest extends MediaWikiUnitTestCase {

	/**
	 * The bench contender mirrors LibwebpBackend::chooseStrategy (the standalone harness can't
	 * load the production class). This locks the two together across the entire truth table, so
	 * the bench routing cannot silently drift from production.
	 *
	 * Scope: this guards the decision rule only, not how the inputs (animated / underThreshold /
	 * hasTransparency) are derived — those derivations are verified against production by reading.
	 */
	public function testStrategyMatchesProductionAcrossTheTruthTable(): void {
		foreach ( [ false, true ] as $animated ) {
			foreach ( [ false, true ] as $underThreshold ) {
				foreach ( [ false, true ] as $hasTransparency ) {
					foreach ( [ false, true ] as $libwebpAvailable ) {
						$bench = ThumbroGif::chooseStrategy(
							$animated, $underThreshold, $hasTransparency, $libwebpAvailable );
						$prod = LibwebpBackend::chooseStrategy(
							$animated, $underThreshold, $hasTransparency, $libwebpAvailable );
						$this->assertSame( $prod, $bench, sprintf(
							'animated=%d underThreshold=%d transparency=%d libwebp=%d',
							$animated, $underThreshold, $hasTransparency, $libwebpAvailable ) );
					}
				}
			}
		}
	}

	public function testStaticGifTakesTheFirstFrame(): void {
		$this->assertSame( 'vips-static', ThumbroGif::chooseStrategy( false, true, false, true ) );
	}

	public function testTransparentAnimationUsesGif2webpWhenAvailable(): void {
		$this->assertSame( 'libwebp', ThumbroGif::chooseStrategy( true, true, true, true ) );
	}

	public function testTransparentAnimationFallsBackToVipsWithoutGif2webp(): void {
		$this->assertSame( 'vips-animated', ThumbroGif::chooseStrategy( true, true, true, false ) );
	}

	public function testOpaqueAnimationDelegatesToVips(): void {
		$this->assertSame( 'vips-animated', ThumbroGif::chooseStrategy( true, true, false, true ) );
	}

	public function testOverThresholdAnimationFallsBackToStatic(): void {
		$this->assertSame( 'vips-static', ThumbroGif::chooseStrategy( true, false, true, true ) );
	}
}
