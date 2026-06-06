<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroVips;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroVips
 */
class ThumbroVipsTest extends MediaWikiUnitTestCase {
	/** A synthetic ThumbroOptions config exercising the resolution rule. */
	private const CONFIG = [
		'image/jpeg' => [ 'inputOptions' => [] ],
		'image/png'  => [ 'inputOptions' => [], 'outputOptions' => [ 'near_lossless' => 'true', 'Q' => '60' ] ],
		'image/webp' => [ 'inputOptions' => [], 'outputOptions' => [ 'strip' => 'true', 'Q' => '90' ] ],
	];

	public function testPngUsesItsOwnOutputBlock(): void {
		[ $in, $out ] = ThumbroVips::optionsFor( 'image/png', self::CONFIG );
		$this->assertSame( '', $in );
		$this->assertSame( '[near_lossless=true,Q=60]', $out );
	}

	public function testJpegFallsBackToTheWebpOutputBlock(): void {
		[ $in, $out ] = ThumbroVips::optionsFor( 'image/jpeg', self::CONFIG );
		$this->assertSame( '', $in );
		$this->assertSame( '[strip=true,Q=90]', $out );
	}

	public function testWebpUsesItsOwnOutputBlock(): void {
		[ , $out ] = ThumbroVips::optionsFor( 'image/webp', self::CONFIG );
		$this->assertSame( '[strip=true,Q=90]', $out );
	}

	public function testOptionsRenderInConfigInsertionOrder(): void {
		// Same rule as LibvipsBackend::makeOptions — keys are emitted in config order.
		[ , $out ] = ThumbroVips::optionsFor( 'image/png', self::CONFIG );
		$this->assertSame( '[near_lossless=true,Q=60]', $out );
	}

	/**
	 * The anti-drift guard: resolving against the real extension.json must yield the production
	 * suffixes. png and jpeg each carry their own output block (near-lossless for png; the
	 * photographic profile — subsampling off, max effort — for jpeg), so neither matches webp.
	 */
	public function testTracksRealExtensionJson(): void {
		$config = json_decode(
			(string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true
		)['config']['ThumbroOptions']['value'];

		[ , $png ] = ThumbroVips::optionsFor( 'image/png', $config );
		$this->assertStringContainsString( 'near_lossless=true', $png );

		[ , $jpeg ] = ThumbroVips::optionsFor( 'image/jpeg', $config );
		[ , $webp ] = ThumbroVips::optionsFor( 'image/webp', $config );
		// jpeg has its own photographic block — the distinguishing choices are subsampling
		// off and max effort, which the shared webp block does not carry.
		$this->assertStringContainsString( 'smart_subsample=false', $jpeg );
		$this->assertStringContainsString( 'effort=6', $jpeg );
		$this->assertNotSame( $webp, $jpeg, 'jpeg now carries its own output block, not the webp fallback' );
	}
}
