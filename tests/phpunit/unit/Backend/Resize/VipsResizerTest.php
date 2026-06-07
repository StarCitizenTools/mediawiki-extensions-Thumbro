<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend\Resize;

use MediaWiki\Extension\Thumbro\Backend\Resize\VipsResizer;
use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWikiUnitTestCase;
use Wikimedia\FileBackend\FSFile\TempFSFile;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\Resize\VipsResizer
 * @covers \MediaWiki\Extension\Thumbro\Backend\VipsOptionSuffix
 */
class VipsResizerTest extends MediaWikiUnitTestCase {

	private const TEMP_GIF = '/tmp/thumbro_test.gif';

	private function factory(): ShellCommandFactory {
		$tempFile = $this->createMock( TempFSFile::class );
		$tempFile->method( 'getPath' )->willReturn( self::TEMP_GIF );
		$tempFactory = $this->createMock( TempFSFileFactory::class );
		$tempFactory->method( 'newTempFSFile' )->willReturn( $tempFile );
		return new ShellCommandFactory( $tempFactory );
	}

	public function testPlanResizeWithLoadOptions(): void {
		$resizer = new VipsResizer( '/usr/bin/vipsthumbnail' );
		$cmd = $resizer->planResize( $this->factory(), '/src.gif', [ 'n' => '-1' ], '250x250', 'gif' );

		$this->assertInstanceOf( ShellCommand::class, $cmd );
		$this->assertSame(
			[ '/usr/bin/vipsthumbnail', '/src.gif[n=-1]', '--size=250x250', '-o', self::TEMP_GIF ],
			$cmd->buildCommandForTest()
		);
		$this->assertTrue( str_ends_with( $cmd->getOutput(), '.gif' ), 'temp output ends with .gif' );
	}

	public function testPlanResizeWithEmptyLoadOptions(): void {
		$resizer = new VipsResizer( '/usr/bin/vipsthumbnail' );
		$cmd = $resizer->planResize( $this->factory(), '/src.gif', [], '250x250', 'gif' );

		$argv = $cmd->buildCommandForTest();
		$this->assertSame( '/src.gif', $argv[1], 'no suffix appended when loadOptions is empty' );
	}

	public function testOutputIsTempFile(): void {
		$resizer = new VipsResizer( '/usr/bin/vipsthumbnail' );
		$cmd = $resizer->planResize( $this->factory(), '/src.gif', [ 'n' => '-1' ], '320x240', 'gif' );

		$this->assertSame( self::TEMP_GIF, $cmd->getOutput() );
	}
}
