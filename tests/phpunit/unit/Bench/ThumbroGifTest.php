<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Backend\Encoder\EncoderRouter;
use MediaWiki\Extension\Thumbro\Backend\Encoder\FileTraits;
use MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroGif;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroGif
 */
class ThumbroGifTest extends MediaWikiUnitTestCase {

	/** The real gif encode list from extension.json — the production routing source of truth. */
	private function gifEncodeList(): array {
		return json_decode(
			(string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true
		)['config']['ThumbroOptions']['value']['image/gif']['encode'];
	}

	/**
	 * Route the real production gif encode list through the real EncoderRouter and translate the
	 * chosen encoder + frame derivation into the bench's strategy vocabulary, reproducing the
	 * pipeline: drop gif2webp when unavailable, route, then map the choice to a strategy.
	 */
	private function productionStrategy(
		bool $animated, bool $underThreshold, bool $hasTransparency, bool $libwebpAvailable
	): string {
		// Match the pipeline's alpha probe: only probed for animated, under-threshold sources.
		$hasAlpha = $animated && $underThreshold && $hasTransparency;
		$list = $this->gifEncodeList();
		if ( !$libwebpAvailable ) {
			$list = array_values( array_filter(
				$list, static fn ( array $e ): bool => $e['encoder'] !== 'gif2webp' ) );
		}
		$choice = ( new EncoderRouter() )->choose(
			$list, new FileTraits( $animated, $hasAlpha, $underThreshold ) );

		if ( $choice->encoder === 'gif2webp' ) {
			return 'libwebp';
		}
		// vips-webp: animation only when the source is animated and under threshold.
		return ( $animated && $underThreshold ) ? 'vips-animated' : 'vips-static';
	}

	/**
	 * The bench contender mirrors the production routing (the EncoderRouter over the gif encode
	 * list; the standalone harness can't load the production services). This locks the two together
	 * across the entire truth table, so the bench routing cannot silently drift from production.
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
						$prod = $this->productionStrategy(
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
