<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit;

use MediaWiki\Extension\Thumbro\ShellCommand;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\ShellCommand
 */
class ShellCommandTest extends MediaWikiUnitTestCase {
	public function testVipsStyleIsUnchanged(): void {
		$cmd = new ShellCommand( 'libvips', '/usr/bin/vipsthumbnail', [ 'size' => '84x120' ] );
		$cmd->setIO( '/src.gif[n=-1]', '/out.webp' );
		$this->assertSame(
			[ '/usr/bin/vipsthumbnail', '/src.gif[n=-1]', '--size=84x120', '-o', '/out.webp' ],
			$cmd->buildCommandForTest()
		);
	}

	public function testGif2webpStyleFlagsAndPositionalInput(): void {
		$cmd = new ShellCommand(
			'libwebp', '/usr/bin/gif2webp', [ 'mixed' => '', 'q' => '80', 'm' => '4' ], 'gif2webp'
		);
		$cmd->setIO( '/tmp.gif', '/out.webp' );
		$this->assertSame(
			[ '/usr/bin/gif2webp', '-mixed', '-q', '80', '-m', '4', '/tmp.gif', '-o', '/out.webp' ],
			$cmd->buildCommandForTest()
		);
	}
}
