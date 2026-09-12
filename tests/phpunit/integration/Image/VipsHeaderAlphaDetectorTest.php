<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Image;

use MediaWiki\Extension\Thumbro\Image\VipsHeaderAlphaDetector;
use MediaWiki\Extension\Thumbro\Tests\VipsHeaderProbeTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * Characterization tests for VipsHeaderAlphaDetector::hasAlpha — pins the real vipsheader-based
 * alpha probe and its safe-false failure modes.
 *
 * @covers \MediaWiki\Extension\Thumbro\Image\VipsHeaderAlphaDetector
 * @group Thumbro
 */
class VipsHeaderAlphaDetectorTest extends MediaWikiIntegrationTestCase {
	use VipsHeaderProbeTestTrait;

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

	private function makeGif( string $suffix, bool $transparent ): string {
		$convert = $this->bin( 'convert' );
		$path = tempnam( sys_get_temp_dir(), $suffix ) . '.gif';
		$this->tmpFiles[] = $path;
		$bg = $transparent ? 'none' : 'white';
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		shell_exec( escapeshellarg( $convert ) . ' -size 40x40 xc:' . $bg
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
			. ' -fill red -draw "circle 20,20 20,4" ' . escapeshellarg( $path ) );
		return $path;
	}

	public function testTransparentGifReportsAlpha(): void {
		$vipsthumbnail = $this->bin( 'vipsthumbnail' );
		$this->bin( 'vipsheader' );
		$src = $this->makeGif( 'thumbro_tr_', true );
		$this->assertTrue( ( new VipsHeaderAlphaDetector( $vipsthumbnail ) )->hasAlpha( $src ) );
	}

	public function testOpaqueGifReportsNoAlpha(): void {
		$vipsthumbnail = $this->bin( 'vipsthumbnail' );
		$this->bin( 'vipsheader' );
		$src = $this->makeGif( 'thumbro_op_', false );
		$this->assertFalse( ( new VipsHeaderAlphaDetector( $vipsthumbnail ) )->hasAlpha( $src ) );
	}

	/**
	 * A probe the detector already handles must not also surface as an ERROR on the exec
	 * channel via MediaWiki's global stderr logging (#104).
	 */
	public function testMissingSourceReturnsFalseAndLogsNoError(): void {
		$vipsthumbnail = $this->bin( 'vipsthumbnail' );
		$this->bin( 'vipsheader' );
		$logger = $this->captureExecLog();

		$this->assertFalse(
			( new VipsHeaderAlphaDetector( $vipsthumbnail ) )->hasAlpha( '/nonexistent/thumbro-does-not-exist.gif' )
		);
		$this->assertExecLogHasNoErrors( $logger,
			'a probe failure the detector already handles is not an exec-channel error' );
	}

	public function testVipsheaderMissingFromCommandDirReturnsFalse(): void {
		// vipsheader is looked up as a sibling of the configured vips command; a command
		// whose directory has no vipsheader can't be probed => safe false.
		$this->assertFalse(
			( new VipsHeaderAlphaDetector( '/nonexistent-thumbro-dir/vipsthumbnail' ) )->hasAlpha( '/tmp/whatever.gif' )
		);
	}
}
