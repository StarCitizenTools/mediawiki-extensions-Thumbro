<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroVips;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Contenders\ThumbroVips
 */
class ThumbroVipsTest extends MediaWikiUnitTestCase {
	/**
	 * A synthetic ThumbroOptions config (new per-MIME resize + encode schema).
	 * The image/webp block mirrors production: animated → vips-webp; static → cwebp (q80,m6);
	 * vips-webp catch-all for a missing cwebp binary.
	 */
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
			'encode' => [
				[
					'encoder' => 'vips-webp',
					'when' => [ 'animated' => true ],
					'options' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ],
				],
				[
					'encoder' => 'cwebp',
					'options' => [ 'q' => '80', 'm' => '6' ],
				],
				[
					'encoder' => 'vips-webp',
					'options' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ],
				],
			],
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

	public function testWebpVipsWebpOptionsFromAnimatedEntry(): void {
		// optionsFor returns the first vips-webp entry's options (the animated guard entry).
		// These options are used for the vips animated-webp path; static webp uses cwebp instead.
		[ , $out ] = ThumbroVips::optionsFor( 'image/webp', self::CONFIG );
		$this->assertSame( '[strip=true,Q=90,smart_subsample=true]', $out );
	}

	public function testFallsBackToWebpEntryWhenBlockHasNoVipsWebp(): void {
		// A block with no vips-webp entry falls back to the image/webp block's vips-webp options.
		$config = self::CONFIG;
		$config['image/jpeg']['encode'] = [ [ 'encoder' => 'gif2webp', 'options' => [ 'q' => '80' ] ] ];
		[ , $out ] = ThumbroVips::optionsFor( 'image/jpeg', $config );
		$this->assertSame(
			'[strip=true,Q=90,smart_subsample=true]', $out, 'fell back to the webp block vips-webp options'
		);
	}

	public function testOptionsRenderInConfigInsertionOrder(): void {
		// Same rule as VipsOptionSuffix::make — keys are emitted in config order.
		[ , $out ] = ThumbroVips::optionsFor( 'image/png', self::CONFIG );
		$this->assertSame( '[near_lossless=true,Q=60]', $out );
	}

	// --- staticEncoderFor ---

	public function testStaticEncoderForWebpIsCwebp(): void {
		// Static image/webp → cwebp (the first encode entry without animated:true guard).
		$encoder = ThumbroVips::staticEncoderFor( 'image/webp', self::CONFIG );
		$this->assertSame( 'cwebp', $encoder );
	}

	public function testStaticEncoderForJpegIsVipsWebp(): void {
		$encoder = ThumbroVips::staticEncoderFor( 'image/jpeg', self::CONFIG );
		$this->assertSame( 'vips-webp', $encoder );
	}

	public function testStaticEncoderForPngIsVipsWebp(): void {
		$encoder = ThumbroVips::staticEncoderFor( 'image/png', self::CONFIG );
		$this->assertSame( 'vips-webp', $encoder );
	}

	// --- cwebpOptionsFor ---

	public function testCwebpOptionsForWebp(): void {
		$opts = ThumbroVips::cwebpOptionsFor( 'image/webp', self::CONFIG );
		$this->assertSame( [ 'q' => '80', 'm' => '6' ], $opts );
	}

	public function testCwebpOptionsForJpegReturnsEmpty(): void {
		// jpeg has no cwebp entry.
		$opts = ThumbroVips::cwebpOptionsFor( 'image/jpeg', self::CONFIG );
		$this->assertSame( [], $opts );
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

	/**
	 * Exact production suffixes for jpeg and png must be unchanged. For webp, optionsFor returns
	 * the animated vips-webp entry's options (the first vips-webp in the encode list); the static
	 * path is now cwebp, tested separately via staticEncoderFor/cwebpOptionsFor.
	 */
	public function testProducesProductionSuffixes(): void {
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
		// The vips-webp suffix for webp (animated path); optionsFor picks the first vips-webp entry.
		$this->assertSame(
			[ '', '[strip=true,Q=90,smart_subsample=true]' ],
			ThumbroVips::optionsFor( 'image/webp', $config )
		);
	}

	/** Production routing: static webp → cwebp; jpeg/png → vips-webp. */
	public function testProductionStaticEncoderRouting(): void {
		$config = json_decode(
			(string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true
		)['config']['ThumbroOptions']['value'];

		$this->assertSame( 'cwebp', ThumbroVips::staticEncoderFor( 'image/webp', $config ),
			'static image/webp routes to cwebp' );
		$this->assertSame( 'vips-webp', ThumbroVips::staticEncoderFor( 'image/jpeg', $config ),
			'image/jpeg stays on vips-webp' );
		$this->assertSame( 'vips-webp', ThumbroVips::staticEncoderFor( 'image/png', $config ),
			'image/png stays on vips-webp' );
	}

	/** Production cwebp options for static webp. */
	public function testProductionCwebpOptionsForWebp(): void {
		$config = json_decode(
			(string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true
		)['config']['ThumbroOptions']['value'];

		$opts = ThumbroVips::cwebpOptionsFor( 'image/webp', $config );
		$this->assertSame( '80', $opts['q'], 'cwebp q=80' );
		$this->assertSame( '6', $opts['m'], 'cwebp m=6' );
	}
}
