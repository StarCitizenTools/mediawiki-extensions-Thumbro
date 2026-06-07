<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend\Encoder;

use MediaWiki\Extension\Thumbro\Backend\Encoder\EncodeInput;
use MediaWiki\Extension\Thumbro\Backend\Encoder\Gif2webpEncoder;
use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWikiUnitTestCase;
use Wikimedia\FileBackend\FSFile\TempFSFile;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\Encoder\Gif2webpEncoder
 * @covers \MediaWiki\Extension\Thumbro\Backend\Encoder\EncodeInput
 */
class Gif2webpEncoderTest extends MediaWikiUnitTestCase {

	private const TEMP_GIF = '/tmp/thumbro_test.gif';

	private function factory(): ShellCommandFactory {
		$tempFile = $this->createMock( TempFSFile::class );
		$tempFile->method( 'getPath' )->willReturn( self::TEMP_GIF );
		$tempFactory = $this->createMock( TempFSFileFactory::class );
		$tempFactory->method( 'newTempFSFile' )->willReturn( $tempFile );
		return new ShellCommandFactory( $tempFactory );
	}

	public function testPlanEncodeProducesCorrectArgv(): void {
		$factory = $this->factory();

		$resize = $factory->create( 'libvips', '/usr/bin/vipsthumbnail', [ 'size' => '250x250' ] );
		$resize->setIO( '/src.gif[n=-1]', 'gif', ShellCommand::TEMP_OUTPUT );

		$enc = new Gif2webpEncoder( '/usr/bin/gif2webp' );
		$cmd = $enc->planEncode(
			$factory,
			EncodeInput::fromResized( $resize ),
			'/dst.webp',
			[ 'mixed' => '', 'q' => '80', 'm' => '4' ]
		);

		$argv = $cmd->buildCommandForTest();

		$this->assertSame( '/usr/bin/gif2webp', $argv[0], 'first element is the gif2webp binary' );

		// Flags must appear in order before the input token.
		$oIdx = array_search( '-o', $argv, true );
		$this->assertNotFalse( $oIdx, '-o token must be present' );

		// Input token is immediately before -o.
		$inputToken = $argv[$oIdx - 1];
		$this->assertSame( $resize->getOutput(), $inputToken, 'input is the resize command temp output' );

		// Output token follows -o.
		$this->assertSame( '/dst.webp', $argv[$oIdx + 1], 'output is the destination path' );

		// Flag tokens appear in the correct order.
		$flags = array_values( array_filter( $argv, static fn ( $v ) => str_starts_with( $v, '-' ) && $v !== '-o' ) );
		$this->assertSame( [ '-mixed', '-q', '-m' ], $flags, 'flags are in order' );

		// Flag values.
		$qIdx = array_search( '-q', $argv, true );
		$this->assertSame( '80', $argv[$qIdx + 1], '-q value is 80' );
		$mIdx = array_search( '-m', $argv, true );
		$this->assertSame( '4', $argv[$mIdx + 1], '-m value is 4' );
	}

	public function testCapabilities(): void {
		$enc = new Gif2webpEncoder( '/usr/bin/gif2webp' );

		$this->assertSame( 'gif2webp', $enc->name() );
		$this->assertTrue( $enc->requiresResizedInput() );
		$this->assertSame( 'gif', $enc->intermediateFormat() );
		$this->assertTrue( $enc->supportsAnimation() );
		$this->assertTrue( $enc->supportsAlpha() );
	}

	public function testIsAvailableWhenBinaryIsExecutable(): void {
		// Gated on an executable binary path (the old libwebpAvailable check).
		$this->assertTrue( ( new Gif2webpEncoder( '/bin/sh' ) )->isAvailable() );
	}

	public function testIsNotAvailableForBogusOrEmptyBinary(): void {
		$this->assertFalse( ( new Gif2webpEncoder( '/no/such/gif2webp' ) )->isAvailable() );
		$this->assertFalse( ( new Gif2webpEncoder( '' ) )->isAvailable() );
	}
}
