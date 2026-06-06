<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Version;

use MediaWiki\Extension\Thumbro\Version\GdVersionProvider;
use MediaWiki\Extension\Thumbro\Version\ImageMagickVersionProvider;
use MediaWiki\Extension\Thumbro\Version\LibvipsVersionProvider;
use MediaWiki\Extension\Thumbro\Version\LibwebpVersionProvider;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Version\LibvipsVersionProvider
 * @covers \MediaWiki\Extension\Thumbro\Version\LibwebpVersionProvider
 * @covers \MediaWiki\Extension\Thumbro\Version\ImageMagickVersionProvider
 * @covers \MediaWiki\Extension\Thumbro\Version\GdVersionProvider
 */
class SoftwareVersionProvidersTest extends MediaWikiUnitTestCase {

	public function testLabels(): void {
		$this->assertSame(
			'[https://www.libvips.org libvips]',
			( new LibvipsVersionProvider() )->getLabel()
		);
		$this->assertSame(
			'[https://developers.google.com/speed/webp libwebp]',
			( new LibwebpVersionProvider( '/usr/bin/gif2webp' ) )->getLabel()
		);
		$this->assertSame(
			'[https://imagemagick.org ImageMagick]',
			( new ImageMagickVersionProvider() )->getLabel()
		);
		$this->assertSame(
			'[https://www.php.net/manual/en/book.image.php GD]',
			( new GdVersionProvider() )->getLabel()
		);
	}

	public function testLibwebpReturnsNullForUnconfiguredCommand(): void {
		$this->assertNull( ( new LibwebpVersionProvider( '' ) )->getVersion() );
	}

	/**
	 * Extension-backed providers (no shell) return either a version string or null
	 * depending on whether the PHP extension is loaded in this environment.
	 */
	public function testExtensionProvidersReturnStringOrNull(): void {
		$gd = ( new GdVersionProvider() )->getVersion();
		$this->assertTrue( $gd === null || is_string( $gd ) );

		$im = ( new ImageMagickVersionProvider() )->getVersion();
		$this->assertTrue( $im === null || is_string( $im ) );
	}
}
