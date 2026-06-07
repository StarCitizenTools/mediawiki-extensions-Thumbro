<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend\Encoder;

use MediaWiki\Extension\Thumbro\Backend\Encoder\EncodeInput;
use MediaWiki\Extension\Thumbro\Backend\Encoder\VipsWebpEncoder;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\Encoder\VipsWebpEncoder
 * @covers \MediaWiki\Extension\Thumbro\Backend\Encoder\EncodeInput
 */
class VipsWebpEncoderTest extends MediaWikiUnitTestCase {

	private function factory(): ShellCommandFactory {
		return new ShellCommandFactory( $this->createMock( TempFSFileFactory::class ) );
	}

	public function testPlanEncodeWithLoadAndEncodeOptions(): void {
		$enc = new VipsWebpEncoder( '/usr/bin/vipsthumbnail' );
		$input = EncodeInput::fromSource( '/src.jpg', '250x166', [ 'n' => '-1' ] );
		$cmd = $enc->planEncode( $this->factory(), $input, '/dst.webp', [ 'Q' => '80' ] );

		$this->assertSame(
			[ '/usr/bin/vipsthumbnail', '/src.jpg[n=-1]', '--size=250x166', '-o', '/dst.webp[Q=80]' ],
			$cmd->buildCommandForTest()
		);
	}

	public function testPlanEncodeWithNoOptions(): void {
		$enc = new VipsWebpEncoder( '/usr/bin/vipsthumbnail' );
		$input = EncodeInput::fromSource( '/src.jpg', '250x166', [] );
		$cmd = $enc->planEncode( $this->factory(), $input, '/dst.webp', [] );

		$this->assertSame(
			[ '/usr/bin/vipsthumbnail', '/src.jpg', '--size=250x166', '-o', '/dst.webp' ],
			$cmd->buildCommandForTest()
		);
	}

	public function testCapabilities(): void {
		$enc = new VipsWebpEncoder( '/usr/bin/vipsthumbnail' );

		$this->assertSame( 'vips-webp', $enc->name() );
		$this->assertFalse( $enc->requiresResizedInput() );
		$this->assertNull( $enc->intermediateFormat() );
		$this->assertTrue( $enc->supportsAnimation() );
		$this->assertTrue( $enc->supportsAlpha() );
	}

	public function testIsAlwaysAvailable(): void {
		// vips is Thumbro's core scaler; it is never gated, even for a bogus binary path.
		$this->assertTrue( ( new VipsWebpEncoder( '/usr/bin/vipsthumbnail' ) )->isAvailable() );
		$this->assertTrue( ( new VipsWebpEncoder( '/no/such/vipsthumbnail' ) )->isAvailable() );
	}
}
