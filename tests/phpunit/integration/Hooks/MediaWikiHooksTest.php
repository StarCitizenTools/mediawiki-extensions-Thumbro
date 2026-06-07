<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Hooks;

use File;
use MediaWiki\Extension\Thumbro\Hooks\MediaWikiHooks;
use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;
use MediaWikiIntegrationTestCase;
use TransformationalImageHandler;

/**
 * Characterization tests for the BitmapHandlerTransform / CheckImageArea / SoftwareInfo
 * hooks, driven through the REAL registered `thumbro` config (extension.json defaults).
 * This pins the critical thumbnail-production path end to end — getOptions resolution,
 * library dispatch, and backend invocation — so a refactor can be verified against it.
 *
 * @covers \MediaWiki\Extension\Thumbro\Hooks\MediaWikiHooks
 * @group Thumbro
 */
class MediaWikiHooksTest extends MediaWikiIntegrationTestCase {

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

	private function hooks(): MediaWikiHooks {
		$services = $this->getServiceContainer();
		return new MediaWikiHooks(
			$services->getConfigFactory(),
			$services->get( 'Thumbro.OptionsResolver' ),
			$services->get( 'Thumbro.EncodePipeline' ),
			$services->get( 'Thumbro.VersionProviders' )
		);
	}

	private function gifHandler(): TransformationalImageHandler {
		$h = $this->createMock( TransformationalImageHandler::class );
		$h->method( 'getThumbType' )->willReturn( [ 'webp', 'image/webp' ] );
		$h->method( 'isAnimatedImage' )->willReturn( true );
		$h->method( 'getImageArea' )->willReturn( 200 * 200 * 6 );
		return $h;
	}

	private function gifFile(): File {
		$f = $this->createMock( File::class );
		$f->method( 'getMimeType' )->willReturn( 'image/gif' );
		$f->method( 'getExtension' )->willReturn( 'gif' );
		$f->method( 'isMultipage' )->willReturn( false );
		$f->method( 'getName' )->willReturn( 't.gif' );
		return $f;
	}

	private function makeAnimatedGif( string $suffix, bool $transparent ): string {
		$convert = $this->bin( 'convert' );
		$path = $this->tmp( $suffix, '.gif' );
		$bg = $transparent ? 'none' : 'white';
		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		shell_exec( escapeshellarg( $convert ) . ' -dispose background -size 200x200 -delay 5 '
			. 'xc:' . $bg . ' -fill red -draw "circle 100,100 100,20" '
			. '\( -clone 0 -rotate 20 \) \( -clone 0 -rotate 40 \) \( -clone 0 -rotate 60 \) '
			. '\( -clone 0 -rotate 80 \) \( -clone 0 -rotate 100 \) -loop 0 ' . escapeshellarg( $path ) );
		// phpcs:enable MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		return $path;
	}

	private function frames( string $vipsheader, string $path ): int {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		return (int)trim( (string)shell_exec(
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
			escapeshellarg( $vipsheader ) . ' -f n-pages ' . escapeshellarg( $path ) . ' 2>/dev/null' ) );
	}

	private function runTransform( string $src, string $dst ): ?\MediaTransformOutput {
		$params = [
			'srcPath' => $src, 'dstPath' => $dst,
			'physicalWidth' => 84, 'physicalHeight' => 84,
			'clientWidth' => 84, 'clientHeight' => 84,
			'dstUrl' => 'http://x/84px.webp', 'comment' => '',
		];
		$mto = null;
		$this->hooks()->onBitmapHandlerTransform( $this->gifHandler(), $this->gifFile(), $params, $mto );
		return $mto;
	}

	public function testTransparentAnimatedGifRoutesToLibwebp(): void {
		$vips = $this->bin( 'vipsthumbnail' );
		$this->bin( 'gif2webp' );
		$vipsheader = $this->bin( 'vipsheader' );
		$src = $this->makeAnimatedGif( 'thumbro_h_tr_', true );
		$dst = $this->tmp( 'thumbro_h_trd_', '.webp' );

		$mto = $this->runTransform( $src, $dst );

		$this->assertInstanceOf( ThumbroThumbnailImage::class, $mto );
		$this->assertFileExists( $dst );
		$this->assertSame( 6, $this->frames( $vipsheader, $dst ), 'animation must be preserved end to end' );

		// Backend-distinguishing pin: gif2webp is far smaller than the libvips animated-WebP
		// path for transparent animation. If a refactor re-routed transparent GIFs back to
		// libvips (the original alpha blow-up bug), this output would balloon and fail here.
		$ref = $this->tmp( 'thumbro_h_ref_', '.webp' );
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		shell_exec( escapeshellarg( $vips ) . ' ' . escapeshellarg( $src ) . '[n=-1] --size 84x84 -o '
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
			. escapeshellarg( $ref . '[strip,Q=90,smart_subsample]' ) . ' 2>/dev/null' );
		$this->assertGreaterThan( 0, filesize( $ref ), 'libvips reference must be produced' );
		$this->assertLessThan(
			filesize( $ref ),
			filesize( $dst ),
			'transparent animation must use gif2webp (smaller than the libvips path it would regress to)'
		);
	}

	public function testOpaqueAnimatedGifRoutesToLibvips(): void {
		$this->bin( 'vipsthumbnail' );
		$vipsheader = $this->bin( 'vipsheader' );
		$src = $this->makeAnimatedGif( 'thumbro_h_op_', false );
		$dst = $this->tmp( 'thumbro_h_opd_', '.webp' );

		$mto = $this->runTransform( $src, $dst );

		$this->assertInstanceOf( ThumbroThumbnailImage::class, $mto );
		$this->assertFileExists( $dst );
		$this->assertSame( 6, $this->frames( $vipsheader, $dst ), 'opaque animation must stay animated via libvips' );
	}

	public function testReturnsTrueAndSkipsWhenThumbroDisabled(): void {
		$this->overrideConfigValue( 'ThumbroEnabled', false );
		$params = [ 'srcPath' => '/tmp/x.gif', 'dstPath' => '/tmp/x.webp' ];
		$mto = null;
		$ret = $this->hooks()->onBitmapHandlerTransform( $this->gifHandler(), $this->gifFile(), $params, $mto );
		$this->assertTrue( $ret, 'disabled Thumbro must let core handle the transform' );
		$this->assertNull( $mto );
	}

	public function testCheckImageAreaFlagsHandledFile(): void {
		$file = $this->gifFile();
		$file->method( 'getHandler' )->willReturn( $this->gifHandler() );
		$params = [];
		$result = null;
		$ret = $this->hooks()->onBitmapHandlerCheckImageArea( $file, $params, $result );
		$this->assertFalse( $ret, 'returning false stops core from applying its own area cap' );
		$this->assertTrue( $result );
	}

	public function testSoftwareInfoReportsLibvipsAndLibwebpVersions(): void {
		$this->bin( 'vipsthumbnail' );
		$this->bin( 'gif2webp' );
		$software = [];
		$this->hooks()->onSoftwareInfo( $software );

		$libvips = $this->valueForKeyContaining( $software, 'libvips' );
		$libwebp = $this->valueForKeyContaining( $software, 'libwebp' );
		$this->assertNotNull( $libvips, 'Special:Version must report libvips' );
		$this->assertNotNull( $libwebp, 'Special:Version must report libwebp' );
		$this->assertMatchesRegularExpression( '/\d+\.\d+\.\d+/', (string)$libvips );
		$this->assertMatchesRegularExpression( '/\d+\.\d+\.\d+/', (string)$libwebp );
	}

	private function valueForKeyContaining( array $software, string $needle ): ?string {
		foreach ( $software as $key => $value ) {
			if ( str_contains( $key, $needle ) ) {
				return $value;
			}
		}
		return null;
	}
}
