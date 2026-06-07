<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Shell;

use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Shell\ShellCommand
 */
class ShellCommandTest extends MediaWikiUnitTestCase {
	private function command( string $name, string $cmd, array $args, string $style = 'vips' ): ShellCommand {
		return new ShellCommand( $this->createMock( TempFSFileFactory::class ), $name, $cmd, $args, $style );
	}

	public function testVipsStyleIsUnchanged(): void {
		$cmd = $this->command( 'libvips', '/usr/bin/vipsthumbnail', [ 'size' => '84x120' ] );
		$cmd->setIO( '/src.gif[n=-1]', '/out.webp' );
		$this->assertSame(
			[ '/usr/bin/vipsthumbnail', '/src.gif[n=-1]', '--size=84x120', '-o', '/out.webp' ],
			$cmd->buildCommandForTest()
		);
	}

	public function testLibwebpStyleFlagsAndPositionalInput(): void {
		$cmd = $this->command(
			'libwebp', '/usr/bin/gif2webp', [ 'mixed' => '', 'q' => '80', 'm' => '4' ], 'libwebp'
		);
		$cmd->setIO( '/tmp.gif', '/out.webp' );
		$this->assertSame(
			[ '/usr/bin/gif2webp', '-mixed', '-q', '80', '-m', '4', '/tmp.gif', '-o', '/out.webp' ],
			$cmd->buildCommandForTest()
		);
	}
}
