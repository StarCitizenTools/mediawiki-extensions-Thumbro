<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend;

use File;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Backend\LibvipsBackend;
use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWikiUnitTestCase;
use TransformationalImageHandler;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\LibvipsBackend
 */
class LibvipsBackendTest extends MediaWikiUnitTestCase {

	private function backend(): LibvipsBackend {
		return new LibvipsBackend(
			new ShellCommandFactory( $this->createMock( TempFSFileFactory::class ) )
		);
	}

	private function request( TransformOptions $options ): BackendRequest {
		return new BackendRequest(
			$this->createMock( TransformationalImageHandler::class ),
			$this->createMock( File::class ),
			[
				'srcPath' => '/src.png',
				'dstPath' => '/out.webp',
				'physicalWidth' => 84,
				'physicalHeight' => 120,
				'dstUrl' => '',
				'clientWidth' => 84,
				'clientHeight' => 120,
			],
			$options
		);
	}

	public function testPlansSingleResizeWithInputAndOutputSuffixes(): void {
		$plan = $this->backend()->plan( $this->request(
			new TransformOptions( 'libvips', '/usr/bin/vipsthumbnail', [ 'n' => '1' ], [ 'Q' => '90' ], false )
		) );

		$commands = $plan->getCommands();
		$this->assertCount( 1, $commands );
		$this->assertSame(
			[ '/usr/bin/vipsthumbnail', '/src.png[n=1]', '--size=84x120', '-o', '/out.webp[Q=90]' ],
			$commands[0]->buildCommandForTest()
		);
	}

	public function testEmptyOptionsProduceNoSuffixes(): void {
		$plan = $this->backend()->plan( $this->request(
			new TransformOptions( 'libvips', '/usr/bin/vipsthumbnail', [], [], false )
		) );

		$this->assertSame(
			[ '/usr/bin/vipsthumbnail', '/src.png', '--size=84x120', '-o', '/out.webp' ],
			$plan->getCommands()[0]->buildCommandForTest()
		);
	}
}
