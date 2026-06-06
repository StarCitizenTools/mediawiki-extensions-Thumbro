<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Backend;

use File;
use MediaTransformOutput;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Backend\CommandPlanRunner;
use MediaWiki\Extension\Thumbro\Backend\LibvipsBackend;
use MediaWiki\Extension\Thumbro\Image\ExifCommentWriter;
use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;
use MediaWikiIntegrationTestCase;
use TransformationalImageHandler;

/**
 * Characterization tests for the libvips backend plan + runner over the real vipsthumbnail
 * static-image path (the default backend), so the refactor is verified against real output.
 *
 * @covers \MediaWiki\Extension\Thumbro\Backend\LibvipsBackend
 * @covers \MediaWiki\Extension\Thumbro\Backend\CommandPlanRunner
 * @group Thumbro
 */
class LibvipsBackendPipelineTest extends MediaWikiIntegrationTestCase {

	/** @var string[] Temp files to remove in tearDown (survives assertion failures). */
	private array $tmpFiles = [];

	protected function tearDown(): void {
		foreach ( $this->tmpFiles as $f ) {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			@unlink( $f );
		}
		$this->tmpFiles = [];
		parent::tearDown();
	}

	private function tmp( string $prefix, string $ext ): string {
		$path = tempnam( sys_get_temp_dir(), $prefix ) . $ext;
		$this->tmpFiles[] = $path;
		return $path;
	}

	private function bin( string $name ): string {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$path = trim( (string)shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );
		if ( $path === '' ) {
			$this->markTestSkipped( "$name not available" );
		}
		return $path;
	}

	private function dummyFile(): File {
		$file = $this->createMock( File::class );
		$file->method( 'getName' )->willReturn( 't.png' );
		return $file;
	}

	private function runner(): CommandPlanRunner {
		return new CommandPlanRunner( new ExifCommentWriter( '' ) );
	}

	private function backend(): LibvipsBackend {
		return new LibvipsBackend(
			new ShellCommandFactory( $this->getServiceContainer()->getTempFSFileFactory() )
		);
	}

	public function testProducesWebpFromStaticImage(): void {
		$vips = $this->bin( 'vipsthumbnail' );
		$convert = $this->bin( 'convert' );
		$vipsheader = $this->bin( 'vipsheader' );

		$src = $this->tmp( 'thumbro_vsrc_', '.png' );
		$dst = $this->tmp( 'thumbro_vdst_', '.webp' );
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		shell_exec( escapeshellarg( $convert ) . ' -size 200x200 xc:white -fill blue '
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
			. '-draw "rectangle 40,40 160,160" ' . escapeshellarg( $src ) );

		$params = [
			'srcPath' => $src, 'dstPath' => $dst,
			'physicalWidth' => 80, 'physicalHeight' => 80,
			'dstUrl' => 'http://x/80px.webp', 'clientWidth' => 80, 'clientHeight' => 80,
		];
		$options = new TransformOptions(
			'libvips', $vips, [], [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ], false
		);
		$request = new BackendRequest(
			$this->createMock( TransformationalImageHandler::class ), $this->dummyFile(), $params, $options
		);

		$mto = null;
		$ret = $this->runner()->run( $this->backend()->plan( $request ), $request, $mto );

		// run() returns false (stop further processing) and yields a ThumbroThumbnailImage.
		$this->assertFalse( $ret );
		$this->assertInstanceOf( ThumbroThumbnailImage::class, $mto );
		$this->assertFileExists( $dst );

		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$format = trim( (string)shell_exec(
			escapeshellarg( $vipsheader ) . ' -f vips-loader ' . escapeshellarg( $dst ) . ' 2>/dev/null' ) );
		$width = (int)trim( (string)shell_exec(
			escapeshellarg( $vipsheader ) . ' -f width ' . escapeshellarg( $dst ) . ' 2>/dev/null' ) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$this->assertStringContainsString( 'webp', $format, 'output should be WebP' );
		$this->assertSame( 80, $width, 'output should be resized to the requested width' );
	}

	/**
	 * End-to-end proof that an animated WebP keeps its frames: when the handler reports the
	 * source animated and animatable (canAnimateThumbnail), LibvipsBackend forces n=-1, so the
	 * output WebP is multi-page rather than a flattened first frame.
	 */
	public function testPreservesAnimationForAnimatableWebP(): void {
		$vips = $this->bin( 'vipsthumbnail' );
		$convert = $this->bin( 'convert' );
		$vipsheader = $this->bin( 'vipsheader' );

		$src = $this->tmp( 'thumbro_asrc_', '.webp' );
		$dst = $this->tmp( 'thumbro_adst_', '.webp' );
		// A 3-frame animated WebP.
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		shell_exec( escapeshellarg( $convert )
			. ' -delay 10 -size 100x100 xc:red xc:green xc:blue -loop 0 ' . escapeshellarg( $src ) );

		$handler = $this->createMock( TransformationalImageHandler::class );
		$handler->method( 'isAnimatedImage' )->willReturn( true );
		$handler->method( 'canAnimateThumbnail' )->willReturn( true );

		$dst = $this->transform( $vips, $handler, $src, $dst );

		$this->assertSame( 'webpload', $this->header( $vipsheader, 'vips-loader', $dst ) );
		$this->assertGreaterThan(
			1, $this->nPages( $vipsheader, $dst ), 'animated WebP must keep its frames'
		);
	}

	/**
	 * The counterpart: when the thumbnail may NOT animate (e.g. over the area threshold),
	 * n=-1 is not forced and the output is a single-frame thumbnail.
	 */
	public function testFlattensWebPWhenItCannotAnimate(): void {
		$vips = $this->bin( 'vipsthumbnail' );
		$convert = $this->bin( 'convert' );
		$vipsheader = $this->bin( 'vipsheader' );

		$src = $this->tmp( 'thumbro_fsrc_', '.webp' );
		$dst = $this->tmp( 'thumbro_fdst_', '.webp' );
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		shell_exec( escapeshellarg( $convert )
			. ' -delay 10 -size 100x100 xc:red xc:green xc:blue -loop 0 ' . escapeshellarg( $src ) );

		$handler = $this->createMock( TransformationalImageHandler::class );
		$handler->method( 'isAnimatedImage' )->willReturn( true );
		$handler->method( 'canAnimateThumbnail' )->willReturn( false );

		$dst = $this->transform( $vips, $handler, $src, $dst );

		$this->assertSame(
			1, $this->nPages( $vipsheader, $dst ), 'a non-animatable thumbnail stays single-frame'
		);
	}

	/** Run the libvips pipeline for $handler over $src, returning the produced $dst path. */
	private function transform(
		string $vips, TransformationalImageHandler $handler, string $src, string $dst
	): string {
		$params = [
			'srcPath' => $src, 'dstPath' => $dst,
			'physicalWidth' => 80, 'physicalHeight' => 80,
			'dstUrl' => 'http://x/80px.webp', 'clientWidth' => 80, 'clientHeight' => 80,
		];
		$options = new TransformOptions( 'libvips', $vips, [], [ 'Q' => '90' ], false );
		$request = new BackendRequest( $handler, $this->dummyFile(), $params, $options );

		$mto = null;
		$this->runner()->run( $this->backend()->plan( $request ), $request, $mto );
		$this->assertFileExists( $dst );
		return $dst;
	}

	/** Read one vipsheader string field. */
	private function header( string $vipsheader, string $field, string $path ): string {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		return trim( (string)shell_exec( escapeshellarg( $vipsheader )
			. ' -f ' . escapeshellarg( $field ) . ' ' . escapeshellarg( $path ) . ' 2>/dev/null' ) );
	}

	/** Page/frame count of an image; a single-frame WebP has no n-pages field, so absent = 1. */
	private function nPages( string $vipsheader, string $path ): int {
		$value = $this->header( $vipsheader, 'n-pages', $path );
		return $value === '' ? 1 : (int)$value;
	}

	public function testReportsErrorOnTransformFailure(): void {
		$vips = $this->bin( 'vipsthumbnail' );
		$dst = $this->tmp( 'thumbro_verr_', '.webp' );

		$error = $this->createMock( MediaTransformOutput::class );
		$handler = $this->createMock( TransformationalImageHandler::class );
		$handler->method( 'getMediaTransformError' )->willReturn( $error );

		$params = [
			'srcPath' => '/nonexistent/thumbro-missing.png', 'dstPath' => $dst,
			'physicalWidth' => 80, 'physicalHeight' => 80,
			'dstUrl' => 'http://x/80px.webp', 'clientWidth' => 80, 'clientHeight' => 80,
		];
		$options = new TransformOptions( 'libvips', $vips, [], [], false );
		$request = new BackendRequest( $handler, $this->dummyFile(), $params, $options );

		$mto = null;
		$ret = $this->runner()->run( $this->backend()->plan( $request ), $request, $mto );

		$this->assertFalse( $ret );
		$this->assertSame( $error, $mto, 'a failed transform must surface the handler MediaTransformError' );
	}
}
