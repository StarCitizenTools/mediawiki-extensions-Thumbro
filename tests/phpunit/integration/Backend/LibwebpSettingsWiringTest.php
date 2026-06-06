<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Backend;

use MediaWiki\Extension\Thumbro\Backend\LibwebpSettings;
use MediaWikiIntegrationTestCase;

/**
 * Verifies ServiceWiring sources the gif2webp encoder flags from ThumbroLibraries.libwebp.flags,
 * falling back to the pre-1.3.x location (image/gif.outputOptions) so existing configs keep working.
 *
 * @coversNothing
 * @group Thumbro
 */
class LibwebpSettingsWiringTest extends MediaWikiIntegrationTestCase {

	private function settings(): LibwebpSettings {
		return $this->getServiceContainer()->get( 'Thumbro.LibwebpSettings' );
	}

	public function testDefaultConfigSourcesFlagsFromLibwebpLibrary(): void {
		// The shipped default puts the flags on the library and leaves the gif block without
		// outputOptions, so a non-empty result proves the wiring reads the new location.
		$this->assertSame( [ 'mixed' => '', 'q' => '80', 'm' => '4' ], $this->settings()->gif2webpFlags() );
	}

	public function testFlagsSourcedFromLibwebpLibraryWhenSet(): void {
		$this->overrideConfigValue( 'ThumbroLibraries', [
			'libvips' => [ 'command' => '/usr/bin/vipsthumbnail' ],
			'libwebp' => [ 'command' => '/usr/bin/gif2webp', 'flags' => [ 'q' => '70', 'm' => '6' ] ],
		] );
		$this->assertSame( [ 'q' => '70', 'm' => '6' ], $this->settings()->gif2webpFlags() );
	}

	public function testLibraryFlagsWinOverStaleOldLocation(): void {
		// Both set => the new library location wins; a stale image/gif.outputOptions is ignored.
		$this->overrideConfigValue( 'ThumbroLibraries', [
			'libvips' => [ 'command' => '/usr/bin/vipsthumbnail' ],
			'libwebp' => [ 'command' => '/usr/bin/gif2webp', 'flags' => [ 'q' => '70' ] ],
		] );
		$this->overrideConfigValue( 'ThumbroOptions', [
			'image/gif' => [ 'enabled' => true, 'library' => 'libwebp',
				'inputOptions' => [ 'n' => '-1' ], 'outputOptions' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ] ],
		] );
		$this->assertSame( [ 'q' => '70' ], $this->settings()->gif2webpFlags() );
	}

	public function testFlagsFallBackToOldGifBlockLocation(): void {
		// No flags on the library => fall back to the pre-1.3.x image/gif.outputOptions.
		$this->overrideConfigValue( 'ThumbroLibraries', [
			'libvips' => [ 'command' => '/usr/bin/vipsthumbnail' ],
			'libwebp' => [ 'command' => '/usr/bin/gif2webp' ],
		] );
		$this->overrideConfigValue( 'ThumbroOptions', [
			'image/gif' => [ 'enabled' => true, 'library' => 'libwebp',
				'inputOptions' => [ 'n' => '-1' ], 'outputOptions' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ] ],
		] );
		$this->assertSame( [ 'mixed' => '', 'q' => '80', 'm' => '4' ], $this->settings()->gif2webpFlags() );
	}
}
