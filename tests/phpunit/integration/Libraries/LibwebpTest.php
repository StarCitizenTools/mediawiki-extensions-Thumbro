<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Libraries;

use MediaWiki\Extension\Thumbro\Libraries\Libwebp;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Libraries\Libwebp
 * @group Thumbro
 */
class LibwebpTest extends MediaWikiIntegrationTestCase {
	private function bin( string $name ): string {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$path = trim( (string)shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );
		if ( $path === '' ) {
			$this->markTestSkipped( "$name not available" );
		}
		return $path;
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

		$params = [
			'srcPath' => $src,
			'dstPath' => $dst,
			'physicalWidth' => 40,
			'physicalHeight' => 40,
		];
		$options = [
			'command' => $vips,
			'webpCommand' => $gif2webp,
			'inputOptions' => [ 'n' => '-1' ],
			'webpOptions' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ],
		];

		$commands = Libwebp::makeCommands( $params, $options );
		foreach ( $commands as $command ) {
			$this->assertSame( 0, $command->execute(), 'pipeline command failed: ' . $command->getErrorString() );
		}

		$this->assertFileExists( $dst );
		$this->assertGreaterThan( 0, filesize( $dst ) );
		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$frames = (int)trim( (string)shell_exec(
			escapeshellarg( $vipsheader ) . ' -f n-pages ' . escapeshellarg( $dst ) . ' 2>/dev/null'
		) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$this->assertSame( 8, $frames, 'animated WebP should preserve all 8 frames' );

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

		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$srcBands = (int)trim( (string)shell_exec(
			escapeshellarg( $vipsheader ) . ' -f bands ' . escapeshellarg( $src ) . ' 2>/dev/null'
		) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$this->assertSame( 3, $srcBands, 'opaque fixture must have no alpha channel so it routes to libvips' );

		$handler = $this->createMock( \TransformationalImageHandler::class );
		$handler->method( 'isAnimatedImage' )->willReturn( true );
		$handler->method( 'getImageArea' )->willReturn( 60 * 60 * 6 );

		$file = $this->createMock( \File::class );
		$file->method( 'getName' )->willReturn( 't.gif' );

		$params = [
			'srcPath' => $src, 'dstPath' => $dst,
			'physicalWidth' => 40, 'physicalHeight' => 40,
			'dstUrl' => 'http://x/40px.webp', 'clientWidth' => 40, 'clientHeight' => 40,
		];
		$options = [
			'command' => $vips, 'webpCommand' => $gif2webp,
			'webpOptions' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ],
			'outputOptions' => [ 'Q' => '90', 'strip' => 'true' ],
			'inputOptions' => [ 'n' => '-1' ], 'maxAnimatedArea' => 25000000,
		];

		$mto = null;
		Libwebp::doTransform( $handler, $file, $params, $options, $mto );

		$this->assertFileExists( $dst );
		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$frames = (int)trim( (string)shell_exec(
			escapeshellarg( $vipsheader ) . ' -f n-pages ' . escapeshellarg( $dst ) . ' 2>/dev/null'
		) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$this->assertSame( 6, $frames, 'opaque animated GIF should stay animated via libvips' );

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

		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$srcBands = (int)trim( (string)shell_exec(
			escapeshellarg( $vipsheader ) . ' -f bands ' . escapeshellarg( $src ) . ' 2>/dev/null'
		) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$this->assertGreaterThanOrEqual( 4, $srcBands, 'transparent fixture must carry an alpha channel' );

		$handler = $this->createMock( \TransformationalImageHandler::class );
		$handler->method( 'isAnimatedImage' )->willReturn( true );
		$handler->method( 'getImageArea' )->willReturn( 60 * 60 * 6 );

		$file = $this->createMock( \File::class );
		$file->method( 'getName' )->willReturn( 't.gif' );

		$params = [
			'srcPath' => $src, 'dstPath' => $dst,
			'physicalWidth' => 40, 'physicalHeight' => 40,
			'dstUrl' => 'http://x/40px.webp', 'clientWidth' => 40, 'clientHeight' => 40,
		];
		$options = [
			'command' => $vips, 'webpCommand' => $gif2webp,
			'webpOptions' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ],
			'outputOptions' => [ 'Q' => '90', 'strip' => 'true' ],
			'inputOptions' => [ 'n' => '-1' ], 'maxAnimatedArea' => 25000000,
		];

		$mto = null;
		Libwebp::doTransform( $handler, $file, $params, $options, $mto );

		$this->assertFileExists( $dst );
		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$frames = (int)trim( (string)shell_exec(
			escapeshellarg( $vipsheader ) . ' -f n-pages ' . escapeshellarg( $dst ) . ' 2>/dev/null'
		) );
		$dstBands = (int)trim( (string)shell_exec(
			escapeshellarg( $vipsheader ) . ' -f bands ' . escapeshellarg( $dst ) . ' 2>/dev/null'
		) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$this->assertSame( 6, $frames, 'transparent animated GIF should stay animated (6 frames) via libwebp' );
		$this->assertGreaterThanOrEqual( 4, $dstBands, 'output WebP should preserve the alpha channel' );

		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $src );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $dst );
	}
}
