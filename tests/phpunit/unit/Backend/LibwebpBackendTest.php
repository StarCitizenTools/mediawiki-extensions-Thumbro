<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend;

use File;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Backend\LibvipsBackend;
use MediaWiki\Extension\Thumbro\Backend\LibwebpBackend;
use MediaWiki\Extension\Thumbro\Backend\LibwebpSettings;
use MediaWiki\Extension\Thumbro\Image\AlphaDetector;
use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWikiUnitTestCase;
use TransformationalImageHandler;
use Wikimedia\FileBackend\FSFile\TempFSFile;

/**
 * Routing/planning tests for the libwebp backend. The pure chooseStrategy() decision is
 * covered separately by LibwebpStrategyTest; here we assert the planned command pipeline
 * for each branch with an injected (fake) alpha detector and stub handler.
 *
 * @covers \MediaWiki\Extension\Thumbro\Backend\LibwebpBackend
 */
class LibwebpBackendTest extends MediaWikiUnitTestCase {

	private const VIPS = '/usr/bin/vipsthumbnail';

	/** A guaranteed-executable path standing in for the gif2webp binary. */
	private function gif2webp(): string {
		return PHP_BINARY;
	}

	private function shellFactory(): ShellCommandFactory {
		$tempFile = $this->createMock( TempFSFile::class );
		$tempFile->method( 'getPath' )->willReturn( '/tmp/thumbro_test.gif' );
		$tempFactory = $this->createMock( TempFSFileFactory::class );
		$tempFactory->method( 'newTempFSFile' )->willReturn( $tempFile );
		return new ShellCommandFactory( $tempFactory );
	}

	private function backend( ShellCommandFactory $factory, bool $hasAlpha, bool $available ): LibwebpBackend {
		$alpha = $this->createMock( AlphaDetector::class );
		$alpha->method( 'hasAlpha' )->willReturn( $hasAlpha );
		$settings = new LibwebpSettings(
			$available ? $this->gif2webp() : '',
			[ 'mixed' => '', 'q' => '80', 'm' => '4' ],
			self::VIPS,
			25000000
		);
		return new LibwebpBackend( new LibvipsBackend( $factory ), $alpha, $factory, $settings );
	}

	private function request( bool $animated, int $area ): BackendRequest {
		$handler = $this->createMock( TransformationalImageHandler::class );
		$handler->method( 'isAnimatedImage' )->willReturn( $animated );
		$handler->method( 'getImageArea' )->willReturn( $area );
		return new BackendRequest(
			$handler,
			$this->createMock( File::class ),
			[
				'srcPath' => '/src.gif',
				'dstPath' => '/out.webp',
				'physicalWidth' => 84,
				'physicalHeight' => 120,
				'dstUrl' => '',
				'clientWidth' => 84,
				'clientHeight' => 120,
			],
			// gif resolved options: input n=-1 (gif block), output = webp block's save flags.
			new TransformOptions( 'libwebp', $this->gif2webp(), [ 'n' => '-1' ], [ 'strip' => 'true' ], false )
		);
	}

	public function testTransparentAnimatedUnderThresholdPlansGif2webpPipeline(): void {
		$backend = $this->backend( $this->shellFactory(), true, true );
		$plan = $backend->plan( $this->request( true, 10000 ) );
		$commands = $plan->getCommands();

		$this->assertCount( 2, $commands );
		$this->assertSame(
			[ self::VIPS, '/src.gif[n=-1]', '--size=84x120', '-o', '/tmp/thumbro_test.gif' ],
			$commands[0]->buildCommandForTest()
		);
		$this->assertSame(
			[ $this->gif2webp(), '-mixed', '-q', '80', '-m', '4', '/tmp/thumbro_test.gif', '-o', '/out.webp' ],
			$commands[1]->buildCommandForTest()
		);
	}

	public function testOpaqueAnimatedDelegatesToLibvipsKeepingAllFrames(): void {
		$backend = $this->backend( $this->shellFactory(), false, true );
		$plan = $backend->plan( $this->request( true, 10000 ) );
		$commands = $plan->getCommands();

		$this->assertCount( 1, $commands );
		$this->assertSame(
			[ self::VIPS, '/src.gif[n=-1]', '--size=84x120', '-o', '/out.webp[strip=true]' ],
			$commands[0]->buildCommandForTest()
		);
	}

	public function testStaticDelegatesToLibvipsFirstFrame(): void {
		$backend = $this->backend( $this->shellFactory(), false, true );
		$plan = $backend->plan( $this->request( false, 10000 ) );
		$commands = $plan->getCommands();

		$this->assertCount( 1, $commands );
		$this->assertSame(
			[ self::VIPS, '/src.gif[n=1]', '--size=84x120', '-o', '/out.webp[strip=true]' ],
			$commands[0]->buildCommandForTest()
		);
	}

	public function testTransparentButLibwebpUnavailableDelegatesToLibvips(): void {
		$backend = $this->backend( $this->shellFactory(), true, false );
		$plan = $backend->plan( $this->request( true, 10000 ) );

		// vips-animated fallback: a single resize command, no gif2webp encode step.
		$this->assertCount( 1, $plan->getCommands() );
		$this->assertSame(
			[ self::VIPS, '/src.gif[n=-1]', '--size=84x120', '-o', '/out.webp[strip=true]' ],
			$plan->getCommands()[0]->buildCommandForTest()
		);
	}
}
