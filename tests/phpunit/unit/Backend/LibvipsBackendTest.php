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

	private function request(
		TransformOptions $options, ?TransformationalImageHandler $handler = null
	): BackendRequest {
		return new BackendRequest(
			$handler ?? $this->createMock( TransformationalImageHandler::class ),
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

	private function handler( bool $animated, bool $canAnimate ): TransformationalImageHandler {
		$h = $this->createMock( TransformationalImageHandler::class );
		$h->method( 'isAnimatedImage' )->willReturn( $animated );
		$h->method( 'canAnimateThumbnail' )->willReturn( $canAnimate );
		return $h;
	}

	private function inputToken( TransformOptions $options, ?TransformationalImageHandler $handler ): string {
		// The source argument is element [1] of the built command: "<src><suffix>".
		return $this->backend()->plan( $this->request( $options, $handler ) )
			->getCommands()[0]->buildCommandForTest()[1];
	}

	private function opts( array $inputOptions ): TransformOptions {
		return new TransformOptions( 'libvips', '/usr/bin/vipsthumbnail', $inputOptions, [], false );
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

	public function testAnimatableSourceForcesAllFrames(): void {
		// Animated source under the area threshold (canAnimateThumbnail) -> keep every frame.
		$this->assertSame( '/src.png[n=-1]', $this->inputToken( $this->opts( [] ), $this->handler( true, true ) ) );
	}

	public function testOverThresholdAnimationKeepsFirstFrame(): void {
		// Animated but too large to animate -> no n forced, vipsthumbnail takes the first frame.
		$this->assertSame( '/src.png', $this->inputToken( $this->opts( [] ), $this->handler( true, false ) ) );
	}

	public function testStaticSourceUnchanged(): void {
		$this->assertSame( '/src.png', $this->inputToken( $this->opts( [] ), $this->handler( false, true ) ) );
	}

	public function testExplicitNIsNotOverridden(): void {
		// The GIF backend pins `n` when it delegates; an animatable source must not override it.
		$this->assertSame( '/src.png[n=1]', $this->inputToken( $this->opts( [ 'n' => '1' ] ), $this->handler( true, true ) ) );
	}
}
