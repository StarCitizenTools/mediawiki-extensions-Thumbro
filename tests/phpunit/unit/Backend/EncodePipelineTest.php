<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend;

use File;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Backend\CommandPlanRunner;
use MediaWiki\Extension\Thumbro\Backend\EncodePipeline;
use MediaWiki\Extension\Thumbro\Backend\Encoder\EncodeInput;
use MediaWiki\Extension\Thumbro\Backend\Encoder\Encoder;
use MediaWiki\Extension\Thumbro\Backend\Encoder\EncoderRouter;
use MediaWiki\Extension\Thumbro\Backend\Resize\Resizer;
use MediaWiki\Extension\Thumbro\Image\AlphaDetector;
use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWikiUnitTestCase;
use TransformationalImageHandler;
use Wikimedia\FileBackend\FSFile\TempFSFile;

/**
 * Unit tests for the resize→encode coordinator, driven entirely by injected fakes (a fake
 * Resizer + fake Encoders, the real EncoderRouter, a recording AlphaDetector). plan() is tested
 * directly; the CommandPlanRunner is only a constructor dependency for dispatch().
 *
 * @covers \MediaWiki\Extension\Thumbro\Backend\EncodePipeline
 */
class EncodePipelineTest extends MediaWikiUnitTestCase {

	private const MAX_AREA = 1000;

	private function factory(): ShellCommandFactory {
		$tempFile = $this->createMock( TempFSFile::class );
		$tempFile->method( 'getPath' )->willReturn( '/tmp/thumbro_test.gif' );
		$tempFactory = $this->createMock( TempFSFileFactory::class );
		$tempFactory->method( 'newTempFSFile' )->willReturn( $tempFile );
		return new ShellCommandFactory( $tempFactory );
	}

	/**
	 * @param array<string,Encoder> $encoders
	 * @param AlphaDetector|null $alpha
	 */
	private function pipeline( array $encoders, ?AlphaDetector $alpha = null ): EncodePipeline {
		$factory = $this->factory();
		return new EncodePipeline(
			$encoders,
			$this->fakeResizer( $factory ),
			new EncoderRouter(),
			$alpha ?? $this->alphaDetector( false ),
			$factory,
			self::MAX_AREA,
			$this->createMock( CommandPlanRunner::class )
		);
	}

	private function request(
		array $encodeList,
		bool $animated,
		int $area,
		array $resizeOptions = []
	): BackendRequest {
		$handler = $this->createMock( TransformationalImageHandler::class );
		$handler->method( 'isAnimatedImage' )->willReturn( $animated );
		$handler->method( 'getImageArea' )->willReturn( $area );
		$params = [
			'srcPath' => '/src.gif',
			'dstPath' => '/dst.webp',
			'dstUrl' => 'http://x/dst.webp',
			'clientWidth' => 84,
			'clientHeight' => 84,
			'physicalWidth' => 84,
			'physicalHeight' => 84,
			'comment' => '',
		];
		return new BackendRequest(
			$handler,
			$this->createMock( File::class ),
			$params,
			new TransformOptions( $resizeOptions, $encodeList, false )
		);
	}

	private function alphaDetector( bool $hasAlpha ): AlphaDetector {
		return new class( $hasAlpha ) implements AlphaDetector {
			public int $calls = 0;

			public function __construct( private readonly bool $hasAlpha ) {
			}

			public function hasAlpha( string $srcPath ): bool {
				$this->calls++;
				return $this->hasAlpha;
			}
		};
	}

	/** A fake Resizer that produces a real (resize) ShellCommand via the injected factory. */
	private function fakeResizer( ShellCommandFactory $factory ): Resizer {
		return new class( $factory ) implements Resizer {
			public function __construct( private readonly ShellCommandFactory $factory ) {
			}

			public function planResize(
				ShellCommandFactory $factory, string $srcPath, array $loadOptions,
				string $physicalSize, string $intermediateFormat
			): ShellCommand {
				$cmd = $this->factory->create( 'libvips', '/usr/bin/vipsthumbnail', [ 'size' => $physicalSize ] );
				$cmd->setIO( $srcPath, $intermediateFormat, ShellCommand::TEMP_OUTPUT );
				return $cmd;
			}
		};
	}

	/** A fake Encoder recording the EncodeInput it received. */
	private function encoder(
		string $name,
		bool $available,
		bool $supportsAnimation,
		bool $requiresResizedInput
	): Encoder {
		$factory = $this->factory();
		return new class( $name, $available, $supportsAnimation, $requiresResizedInput, $factory ) implements Encoder {
			public ?EncodeInput $received = null;

			public function __construct(
				private readonly string $encName,
				private readonly bool $available,
				private readonly bool $supportsAnim,
				private readonly bool $requiresResized,
				private readonly ShellCommandFactory $factory
			) {
			}

			public function name(): string {
				return $this->encName;
			}

			public function isAvailable(): bool {
				return $this->available;
			}

			public function supportsAnimation(): bool {
				return $this->supportsAnim;
			}

			public function supportsAlpha(): bool {
				return true;
			}

			public function requiresResizedInput(): bool {
				return $this->requiresResized;
			}

			public function intermediateFormat(): ?string {
				return $this->requiresResized ? 'gif' : null;
			}

			public function planEncode(
				ShellCommandFactory $factory, EncodeInput $input, string $dstPath, array $options
			): ShellCommand {
				$this->received = $input;
				$cmd = $this->factory->create( 'libvips', '/usr/bin/enc', [] );
				if ( $input->resizeCommand !== null ) {
					$cmd->setIO( $input->resizeCommand, $dstPath );
				} else {
					$cmd->setIO( (string)$input->srcPath, $dstPath );
				}
				return $cmd;
			}
		};
	}

	public function testFusedEncoderProducesOneCommand(): void {
		$enc = $this->encoder( 'vips-webp', true, true, false );
		$pipeline = $this->pipeline( [ 'vips-webp' => $enc ] );
		$plan = $pipeline->plan( $this->request( [ [ 'encoder' => 'vips-webp' ] ], false, 500 ) );

		$this->assertCount( 1, $plan->getCommands() );
		$this->assertNotNull( $enc->received );
		$this->assertSame( '/src.gif', $enc->received->srcPath, 'fused encoder reads from source' );
	}

	public function testTwoStepEncoderProducesResizeThenEncode(): void {
		$enc = $this->encoder( 'gif2webp', true, true, true );
		$pipeline = $this->pipeline( [ 'gif2webp' => $enc ] );
		$plan = $pipeline->plan( $this->request(
			[ [ 'encoder' => 'gif2webp', 'when' => [ 'animated' => true, 'underThreshold' => true ] ] ],
			true, 500
		) );

		$commands = $plan->getCommands();
		$this->assertCount( 2, $commands, 'resize + encode' );
		$this->assertNotNull( $enc->received );
		$this->assertNotNull( $enc->received->resizeCommand, 'encoder got an EncodeInput::fromResized' );
		// The first command is the resize; the second consumes its temp output.
		$this->assertSame(
			$commands[0]->getOutput(),
			$enc->received->resizeCommand->getOutput(),
			'the encode step consumes the resize output'
		);
	}

	public function testAnimatedUnderThresholdAddsNMinusOneLoadOption(): void {
		$enc = $this->encoder( 'vips-webp', true, true, false );
		$pipeline = $this->pipeline( [ 'vips-webp' => $enc ] );
		$pipeline->plan( $this->request(
			[ [ 'encoder' => 'vips-webp', 'when' => [ 'animated' => true, 'underThreshold' => true ] ] ],
			true, 500
		) );

		$this->assertNotNull( $enc->received );
		$this->assertSame( '-1', $enc->received->loadOptions['n'] ?? null, 'n=-1 prepended for animated source' );
	}

	public function testStaticSourceHasNoNLoadOption(): void {
		$enc = $this->encoder( 'vips-webp', true, true, false );
		$pipeline = $this->pipeline( [ 'vips-webp' => $enc ] );
		$pipeline->plan( $this->request( [ [ 'encoder' => 'vips-webp' ] ], false, 500 ) );

		$this->assertNotNull( $enc->received );
		$this->assertArrayNotHasKey( 'n', $enc->received->loadOptions, 'no n key for a static source' );
	}

	public function testUnavailableFirstChoiceIsSkipped(): void {
		// gif2webp is unavailable, so its transparent-animation entry is dropped and routing
		// falls through to the vips-webp catch-all.
		$gif2webp = $this->encoder( 'gif2webp', false, true, true );
		$vips = $this->encoder( 'vips-webp', true, true, false );
		$pipeline = $this->pipeline( [ 'gif2webp' => $gif2webp, 'vips-webp' => $vips ] );
		$plan = $pipeline->plan( $this->request( [
			[ 'encoder' => 'gif2webp', 'when' => [ 'animated' => true, 'alpha' => true, 'underThreshold' => true ] ],
			[ 'encoder' => 'vips-webp' ],
		], true, 500, ) );

		$this->assertNull( $gif2webp->received, 'unavailable encoder is never invoked' );
		$this->assertNotNull( $vips->received, 'routing fell through to the available encoder' );
		$this->assertCount( 1, $plan->getCommands() );
	}

	public function testAlphaProbeSkippedForStaticSource(): void {
		$alpha = $this->alphaDetector( false );
		$enc = $this->encoder( 'vips-webp', true, true, false );
		$pipeline = $this->pipeline( [ 'vips-webp' => $enc ], $alpha );
		$pipeline->plan( $this->request( [ [ 'encoder' => 'vips-webp' ] ], false, 500 ) );

		$this->assertSame( 0, $alpha->calls, 'alpha is not probed for a static source' );
	}

	public function testAlphaProbedForAnimatedUnderThresholdSource(): void {
		$alpha = $this->alphaDetector( true );
		$enc = $this->encoder( 'vips-webp', true, true, false );
		$pipeline = $this->pipeline( [ 'vips-webp' => $enc ], $alpha );
		$pipeline->plan( $this->request(
			[ [ 'encoder' => 'vips-webp', 'when' => [ 'animated' => true, 'underThreshold' => true ] ] ],
			true, 500
		) );

		$this->assertSame( 1, $alpha->calls, 'alpha is probed for an animated, under-threshold source' );
	}

	public function testOverThresholdAnimatedSkipsAlphaProbe(): void {
		$alpha = $this->alphaDetector( false );
		$enc = $this->encoder( 'vips-webp', true, true, false );
		$pipeline = $this->pipeline( [ 'vips-webp' => $enc ], $alpha );
		$pipeline->plan( $this->request( [ [ 'encoder' => 'vips-webp' ] ], true, self::MAX_AREA + 1 ) );

		$this->assertSame( 0, $alpha->calls, 'alpha is not probed when over the animated-area threshold' );
		$this->assertNotNull( $enc->received );
		$this->assertArrayNotHasKey( 'n', $enc->received->loadOptions, 'over-threshold animation loads no n' );
	}

	public function testNoAvailableEncoderReturnsEmptyPlan(): void {
		$enc = $this->encoder( 'vips-webp', false, true, false );
		$pipeline = $this->pipeline( [ 'vips-webp' => $enc ] );
		$plan = $pipeline->plan( $this->request( [ [ 'encoder' => 'vips-webp' ] ], false, 500 ) );

		$this->assertTrue( $plan->isEmpty(), 'an empty available list yields the let-core-continue plan' );
	}
}
