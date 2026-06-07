<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroVips;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroVips
 */
class ThumbroVipsTest extends MediaWikiUnitTestCase {
	/** A synthetic ThumbroOptions config (new per-MIME resize + encode schema). */
	private const CONFIG = [
		'image/jpeg' => [
			'resize' => [ 'options' => [] ],
			'encode' => [ [ 'encoder' => 'vips-webp', 'options' => [ 'strip' => 'true', 'Q' => '80' ] ] ],
		],
		'image/png' => [
			'resize' => [ 'options' => [] ],
			'encode' => [ [ 'encoder' => 'vips-webp', 'options' => [ 'near_lossless' => 'true', 'Q' => '60' ] ] ],
		],
		'image/webp' => [
			'resize' => [ 'options' => [] ],
			'encode' => [ [ 'encoder' => 'vips-webp', 'options' => [ 'strip' => 'true', 'Q' => '90' ] ] ],
		],
	];

	public function testPngUsesItsOwnVipsWebpEntry(): void {
		[ $in, $out ] = ThumbroVips::optionsFor( 'image/png', self::CONFIG );
		$this->assertSame( '', $in );
		$this->assertSame( '[near_lossless=true,Q=60]', $out );
	}

	public function testJpegUsesItsOwnVipsWebpEntry(): void {
		[ $in, $out ] = ThumbroVips::optionsFor( 'image/jpeg', self::CONFIG );
		$this->assertSame( '', $in );
		$this->assertSame( '[strip=true,Q=80]', $out );
	}

	public function testWebpUsesItsOwnVipsWebpEntry(): void {
		[ , $out ] = ThumbroVips::optionsFor( 'image/webp', self::CONFIG );
		$this->assertSame( '[strip=true,Q=90]', $out );
	}

	public function testFallsBackToWebpEntryWhenBlockHasNoVipsWebp(): void {
		// A block with no vips-webp entry falls back to the image/webp block's vips-webp options.
		$config = self::CONFIG;
		$config['image/jpeg']['encode'] = [ [ 'encoder' => 'gif2webp', 'options' => [ 'q' => '80' ] ] ];
		[ , $out ] = ThumbroVips::optionsFor( 'image/jpeg', $config );
		$this->assertSame( '[strip=true,Q=90]', $out, 'fell back to the webp block vips-webp options' );
	}

	public function testOptionsRenderInConfigInsertionOrder(): void {
		// Same rule as VipsOptionSuffix::make — keys are emitted in config order.
		[ , $out ] = ThumbroVips::optionsFor( 'image/png', self::CONFIG );
		$this->assertSame( '[near_lossless=true,Q=60]', $out );
	}

	/**
	 * The anti-drift guard: resolving against the real extension.json must yield the production
	 * suffixes. png and jpeg each carry their own vips-webp entry (near-lossless for png; the
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
		$this->assertNotSame( $webp, $jpeg, 'jpeg carries its own vips-webp entry, not the webp fallback' );
	}

	/** The exact production suffixes for jpeg/png/webp must be unchanged by the schema migration. */
	public function testProducesUnchangedProductionSuffixes(): void {
		$config = json_decode(
			(string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true
		)['config']['ThumbroOptions']['value'];

		$this->assertSame(
			[ '', '[strip=true,Q=80,smart_subsample=false,effort=6]' ],
			ThumbroVips::optionsFor( 'image/jpeg', $config )
		);
		$this->assertSame(
			[ '', '[near_lossless=true,Q=60,strip=true]' ],
			ThumbroVips::optionsFor( 'image/png', $config )
		);
		$this->assertSame(
			[ '', '[strip=true,Q=90,smart_subsample=true]' ],
			ThumbroVips::optionsFor( 'image/webp', $config )
		);
	}
}
