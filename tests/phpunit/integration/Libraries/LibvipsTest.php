<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Libraries;

use File;
use MediaTransformOutput;
use MediaWiki\Extension\Thumbro\Libraries\Libvips;
use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;
use MediaWikiIntegrationTestCase;
use TransformationalImageHandler;

/**
 * Characterization tests for Libvips::doTransform — pins the real vipsthumbnail
 * static-image path (the default backend) so a refactor can be verified against it.
 *
 * @covers \MediaWiki\Extension\Thumbro\Libraries\Libvips
 * @group Thumbro
 */
class LibvipsTest extends MediaWikiIntegrationTestCase {

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

		$handler = $this->createMock( TransformationalImageHandler::class );
		$params = [
			'srcPath' => $src, 'dstPath' => $dst,
			'physicalWidth' => 80, 'physicalHeight' => 80,
			'dstUrl' => 'http://x/80px.webp', 'clientWidth' => 80, 'clientHeight' => 80,
		];
		$options = [
			'command' => $vips,
			'inputOptions' => [],
			'outputOptions' => [ 'strip' => 'true', 'Q' => '90', 'smart_subsample' => 'true' ],
		];

		$mto = null;
		$ret = Libvips::doTransform( $handler, $this->dummyFile(), $params, $options, $mto );

		// doTransform returns false (stop further processing) and yields a ThumbroThumbnailImage.
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
		$options = [ 'command' => $vips, 'inputOptions' => [], 'outputOptions' => [] ];

		$mto = null;
		$ret = Libvips::doTransform( $handler, $this->dummyFile(), $params, $options, $mto );

		$this->assertFalse( $ret );
		$this->assertSame( $error, $mto, 'a failed transform must surface the handler MediaTransformError' );
	}
}
