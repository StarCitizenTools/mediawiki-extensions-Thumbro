<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend\Encoder;

use MediaWiki\Extension\Thumbro\Backend\Encoder\CwebpEncoder;
use MediaWiki\Extension\Thumbro\Backend\Encoder\EncodeInput;
use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\Encoder\CwebpEncoder
 */
class CwebpEncoderTest extends MediaWikiUnitTestCase {

	private function factory(): ShellCommandFactory {
		return new ShellCommandFactory( new TempFSFileFactory() );
	}

	public function testCapabilities(): void {
		$enc = new CwebpEncoder( '/usr/bin/cwebp' );
		$this->assertSame( 'cwebp', $enc->name() );
		$this->assertTrue( $enc->requiresResizedInput() );
		$this->assertSame( 'png', $enc->intermediateFormat() );
		$this->assertFalse( $enc->supportsAnimation() );
		$this->assertTrue( $enc->supportsAlpha() );
	}

	public function testIsAvailable(): void {
		$this->assertTrue( ( new CwebpEncoder( '/bin/sh' ) )->isAvailable() );
		$this->assertFalse( ( new CwebpEncoder( '/no/such/cwebp' ) )->isAvailable() );
		$this->assertFalse( ( new CwebpEncoder( '' ) )->isAvailable() );
	}

	public function testPlanEncodeBuildsCwebpArgv(): void {
		$factory = $this->factory();
		$resize = $factory->create( 'libvips', '/usr/bin/vipsthumbnail', [ 'size' => '250x100000' ] );
		$resize->setIO( '/src.png', 'png', ShellCommand::TEMP_OUTPUT );

		$enc = new CwebpEncoder( '/usr/bin/cwebp' );
		$cmd = $enc->planEncode(
			$factory, EncodeInput::fromResized( $resize ), '/dst.webp', [ 'q' => '80', 'm' => '6' ]
		);
		$argv = $cmd->buildCommandForTest();
		$this->assertSame( '/usr/bin/cwebp', $argv[0] );
		$this->assertSame( [ '-q', '80', '-m', '6' ], array_slice( $argv, 1, 4 ) );
		$this->assertSame( $resize->getOutput(), $argv[5] );
		$this->assertSame( [ '-o', '/dst.webp' ], array_slice( $argv, 6 ) );
	}
}
