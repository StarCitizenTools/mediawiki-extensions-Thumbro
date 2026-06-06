<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Backend;

use File;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Backend\CommandPlanRunner;
use MediaWiki\Extension\Thumbro\Backend\LibvipsBackend;
use MediaWiki\Extension\Thumbro\Backend\LibwebpBackend;
use MediaWiki\Extension\Thumbro\Backend\LibwebpSettings;
use MediaWiki\Extension\Thumbro\Image\AlphaDetector;
use MediaWiki\Extension\Thumbro\Image\ExifCommentWriter;
use MediaWiki\Extension\Thumbro\Image\VipsHeaderAlphaDetector;
use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWikiIntegrationTestCase;
use TransformationalImageHandler;

/**
 * Characterization tests for the libwebp backend over real vipsthumbnail + gif2webp: the
 * gif2webp encode pipeline and the libvips delegation for opaque/transparent animated GIFs.
 *
 * @covers \MediaWiki\Extension\Thumbro\Backend\LibwebpBackend
 * @group Thumbro
 */
class LibwebpBackendPipelineTest extends MediaWikiIntegrationTestCase {

	private function bin( string $name ): string {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$path = trim( (string)shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );
		if ( $path === '' ) {
			$this->markTestSkipped( "$name not available" );
		}
		return $path;
	}

	private function frames( string $vipsheader, string $path ): int {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		return (int)trim( (string)shell_exec(
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
			escapeshellarg( $vipsheader ) . ' -f n-pages ' . escapeshellarg( $path ) . ' 2>/dev/null' ) );
	}

	private function bands( string $vipsheader, string $path ): int {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		return (int)trim( (string)shell_exec(
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
			escapeshellarg( $vipsheader ) . ' -f bands ' . escapeshellarg( $path ) . ' 2>/dev/null' ) );
	}

	private function backend( string $vips, string $gif2webp, AlphaDetector $alpha ): LibwebpBackend {
		$factory = new ShellCommandFactory( $this->getServiceContainer()->getTempFSFileFactory() );
		$settings = new LibwebpSettings(
			$gif2webp, [ 'mixed' => '', 'q' => '80', 'm' => '4' ], $vips, 25000000
		);
		return new LibwebpBackend( new LibvipsBackend( $factory ), $alpha, $factory, $settings );
	}

	private function request( string $gif2webp, string $src, string $dst ): BackendRequest {
		$handler = $this->createMock( TransformationalImageHandler::class );
		$handler->method( 'isAnimatedImage' )->willReturn( true );
		$handler->method( 'getImageArea' )->willReturn( 60 * 60 * 6 );
		$file = $this->createMock( File::class );
		$file->method( 'getName' )->willReturn( 't.gif' );

		$params = [
			'srcPath' => $src, 'dstPath' => $dst,
			'physicalWidth' => 40, 'physicalHeight' => 40,
			'dstUrl' => 'http://x/40px.webp', 'clientWidth' => 40, 'clientHeight' => 40,
		];
		// gif resolved options: input n=-1 (gif block), output = webp block save flags.
		$options = new TransformOptions(
			'libwebp', $gif2webp, [ 'n' => '-1' ], [ 'Q' => '90', 'strip' => 'true' ], false
		);
		return new BackendRequest( $handler, $file, $params, $options );
	}

	private function alpha( bool $value ): AlphaDetector {
		$alpha = $this->createMock( AlphaDetector::class );
		$alpha->method( 'hasAlpha' )->willReturn( $value );
		return $alpha;
	}

	public function testPipelineProducesAnimatedWebp(): void {
		$vips = $this->bin( 'vipsthumbnail' );
		$gif2webp = $this->bin( 'gif2webp' );
		$convert = $this->bin( 'convert' );
		$vipsheader = $this->bin( 'vipsheader' );

		$src = tempnam( sys_get_temp_dir(), 'thumbro_src_' ) . '.gif';
		$dst = tempnam( sys_get_temp_dir(), 'thumbro_dst_' ) . '.webp';
		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		shell_exec( escapeshellarg( $convert ) . ' -size 60x60 -delay 5 '
			. 'xc:red xc:green xc:blue xc:yellow xc:cyan xc:magenta xc:white xc:black '
			. '-loop 0 ' . escapeshellarg( $src ) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg

		// Force the gif2webp encode path (stub alpha=true) to exercise the pipeline directly.
		$plan = $this->backend( $vips, $gif2webp, $this->alpha( true ) )
			->plan( $this->request( $gif2webp, $src, $dst ) );
		foreach ( $plan->getCommands() as $command ) {
			$this->assertSame( 0, $command->execute(), 'pipeline command failed: ' . $command->getErrorString() );
		}

		$this->assertFileExists( $dst );
		$this->assertGreaterThan( 0, filesize( $dst ) );
		$this->assertSame( 8, $this->frames( $vipsheader, $dst ), 'animated WebP should preserve all 8 frames' );

		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $src );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $dst );
	}

	public function testOpaqueAnimatedDelegatesToLibvips(): void {
		$vips = $this->bin( 'vipsthumbnail' );
		$gif2webp = $this->bin( 'gif2webp' );
		$convert = $this->bin( 'convert' );
		$vipsheader = $this->bin( 'vipsheader' );

		$src = tempnam( sys_get_temp_dir(), 'thumbro_op_' ) . '.gif';
		$dst = tempnam( sys_get_temp_dir(), 'thumbro_opdst_' ) . '.webp';
		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		// Opaque (no transparency) 6-frame animated GIF.
		shell_exec( escapeshellarg( $convert ) . ' -size 60x60 -delay 5 '
			. 'xc:red xc:green xc:blue xc:yellow xc:cyan xc:white -loop 0 ' . escapeshellarg( $src ) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg

		$this->assertSame(
			3, $this->bands( $vipsheader, $src ),
			'opaque fixture must have no alpha channel so it routes to libvips'
		);

		// Real alpha detection routes the opaque GIF through libvips (delegation, all frames).
		$request = $this->request( $gif2webp, $src, $dst );
		$runner = new CommandPlanRunner( new ExifCommentWriter( '' ) );
		$mto = null;
		$runner->run(
			$this->backend( $vips, $gif2webp, new VipsHeaderAlphaDetector( $vips ) )->plan( $request ),
			$request,
			$mto
		);

		$this->assertFileExists( $dst );
		$this->assertSame(
			6, $this->frames( $vipsheader, $dst ),
			'opaque animated GIF should stay animated via libvips'
		);

		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $src );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $dst );
	}

	public function testTransparentAnimatedRoutesThroughLibwebp(): void {
		$vips = $this->bin( 'vipsthumbnail' );
		$gif2webp = $this->bin( 'gif2webp' );
		$convert = $this->bin( 'convert' );
		$vipsheader = $this->bin( 'vipsheader' );

		$src = tempnam( sys_get_temp_dir(), 'thumbro_tr_' ) . '.gif';
		$dst = tempnam( sys_get_temp_dir(), 'thumbro_trdst_' ) . '.webp';
		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		// Transparent (alpha) 6-frame animated GIF: xc:none background + a drawn shape.
		shell_exec( escapeshellarg( $convert ) . ' -dispose background -size 60x60 -delay 5 '
			. 'xc:none -fill red -draw "circle 30,30 30,8" '
			. '\( -clone 0 -rotate 8 \) \( -clone 0 -rotate 16 \) \( -clone 0 -rotate 24 \) '
			. '\( -clone 0 -rotate 32 \) \( -clone 0 -rotate 40 \) -loop 0 ' . escapeshellarg( $src ) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg

		$this->assertGreaterThanOrEqual(
			4, $this->bands( $vipsheader, $src ), 'transparent fixture must carry an alpha channel'
		);

		// Real alpha detection routes the transparent GIF through gif2webp (libwebp).
		$request = $this->request( $gif2webp, $src, $dst );
		$runner = new CommandPlanRunner( new ExifCommentWriter( '' ) );
		$mto = null;
		$runner->run(
			$this->backend( $vips, $gif2webp, new VipsHeaderAlphaDetector( $vips ) )->plan( $request ),
			$request,
			$mto
		);

		$this->assertFileExists( $dst );
		$this->assertSame(
			6, $this->frames( $vipsheader, $dst ),
			'transparent animated GIF should stay animated (6 frames) via libwebp'
		);
		$this->assertGreaterThanOrEqual(
			4, $this->bands( $vipsheader, $dst ), 'output WebP should preserve the alpha channel'
		);

		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $src );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $dst );
	}
}
