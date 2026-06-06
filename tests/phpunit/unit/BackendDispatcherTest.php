<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Thumbro\BackendDispatcher;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the pure option-bundle assembly extracted into the dispatch seam.
 * (The dispatch() routing itself is exercised end to end by MediaWikiHooksTest.)
 *
 * @covers \MediaWiki\Extension\Thumbro\BackendDispatcher
 */
class BackendDispatcherTest extends MediaWikiUnitTestCase {

	private function config(
		array $libraries,
		string|int $maxArea = 25000000,
		array $gifOut = [ 'mixed' => '', 'q' => '80', 'm' => '4' ]
	): HashConfig {
		return new HashConfig( [
			'ThumbroLibraries' => $libraries,
			'ThumbroOptions' => [ 'image/gif' => [ 'outputOptions' => $gifOut ] ],
			'ThumbroMaxAnimatedArea' => $maxArea,
		] );
	}

	public function testAssemblesBundleFromConfigAndOptions(): void {
		$config = $this->config( [
			'libvips' => [ 'command' => '/usr/bin/vipsthumbnail' ],
			'libwebp' => [ 'command' => '/usr/bin/gif2webp' ],
		] );
		$options = [
			'library' => 'libwebp', 'command' => '/usr/bin/gif2webp',
			'inputOptions' => [ 'n' => '-1' ], 'outputOptions' => [ 'Q' => '90' ],
		];

		$bundle = BackendDispatcher::libwebpOptions( $config, $options );

		$this->assertSame( '/usr/bin/vipsthumbnail', $bundle['command'], 'resize/delegation uses libvips' );
		$this->assertSame( '/usr/bin/gif2webp', $bundle['webpCommand'], 'encode uses gif2webp' );
		$this->assertSame( [ 'mixed' => '', 'q' => '80', 'm' => '4' ], $bundle['webpOptions'] );
		$this->assertSame( [ 'Q' => '90' ], $bundle['outputOptions'] );
		$this->assertSame( [ 'n' => '-1' ], $bundle['inputOptions'] );
		$this->assertSame( 25000000, $bundle['maxAnimatedArea'] );
	}

	public function testWebpCommandEmptyWhenLibwebpUnconfigured(): void {
		// Without a gif2webp binary the bundle leaves webpCommand empty; the Libwebp
		// backend then treats it as unavailable and delegates to libvips.
		$config = $this->config( [ 'libvips' => [ 'command' => '/usr/bin/vipsthumbnail' ] ] );
		$bundle = BackendDispatcher::libwebpOptions( $config, [ 'command' => '/usr/bin/vipsthumbnail' ] );

		$this->assertSame( '', $bundle['webpCommand'] );
		$this->assertSame( '/usr/bin/vipsthumbnail', $bundle['command'] );
	}

	public function testCommandFallsBackToResolvedOptionWhenLibvipsUnconfigured(): void {
		$config = $this->config( [ 'libwebp' => [ 'command' => '/usr/bin/gif2webp' ] ] );
		$bundle = BackendDispatcher::libwebpOptions( $config, [ 'command' => '/fallback/vipsthumbnail' ] );

		$this->assertSame( '/fallback/vipsthumbnail', $bundle['command'] );
	}

	public function testMaxAnimatedAreaIsCastToInt(): void {
		$config = $this->config(
			[ 'libvips' => [ 'command' => '/v' ], 'libwebp' => [ 'command' => '/g' ] ],
			'12345'
		);
		$bundle = BackendDispatcher::libwebpOptions( $config, [ 'command' => '/g' ] );

		$this->assertSame( 12345, $bundle['maxAnimatedArea'] );
	}
}
