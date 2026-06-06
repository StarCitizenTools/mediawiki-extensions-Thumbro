<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend;

use File;
use MediaWiki\Extension\Thumbro\Backend\BackendDispatcher;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Backend\CommandPlan;
use MediaWiki\Extension\Thumbro\Backend\CommandPlanRunner;
use MediaWiki\Extension\Thumbro\Backend\ThumbnailBackend;
use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWikiUnitTestCase;
use TransformationalImageHandler;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\BackendDispatcher
 */
class BackendDispatcherTest extends MediaWikiUnitTestCase {

	private function request( string $library ): BackendRequest {
		return new BackendRequest(
			$this->createMock( TransformationalImageHandler::class ),
			$this->createMock( File::class ),
			[ 'srcPath' => '/s', 'dstPath' => '/d', 'dstUrl' => '', 'physicalWidth' => 1, 'physicalHeight' => 1 ],
			new TransformOptions( $library, '/bin/x', [], [], false )
		);
	}

	public function testSelectsNamedBackendAndForwardsRunnerResult(): void {
		$libvips = $this->createMock( ThumbnailBackend::class );
		$libvips->expects( $this->never() )->method( 'plan' );
		$libwebp = $this->createMock( ThumbnailBackend::class );
		$libwebp->expects( $this->once() )->method( 'plan' )->willReturn( CommandPlan::empty() );

		$runner = $this->createMock( CommandPlanRunner::class );
		$runner->expects( $this->once() )->method( 'run' )->willReturn( false );

		$dispatcher = new BackendDispatcher( [ 'libvips' => $libvips, 'libwebp' => $libwebp ], $runner );
		$mto = null;
		$this->assertFalse( $dispatcher->dispatch( $this->request( 'libwebp' ), $mto ) );
	}

	public function testFallsBackToLibvipsForUnknownLibrary(): void {
		$libvips = $this->createMock( ThumbnailBackend::class );
		$libvips->expects( $this->once() )->method( 'plan' )->willReturn( CommandPlan::empty() );

		$runner = $this->createMock( CommandPlanRunner::class );
		$runner->method( 'run' )->willReturn( true );

		$dispatcher = new BackendDispatcher( [ 'libvips' => $libvips ], $runner );
		$mto = null;
		$this->assertTrue( $dispatcher->dispatch( $this->request( 'libdoesnotexist' ), $mto ) );
	}
}
