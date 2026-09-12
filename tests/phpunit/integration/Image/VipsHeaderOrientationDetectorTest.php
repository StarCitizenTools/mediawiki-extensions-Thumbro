<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Image;

use MediaWiki\Extension\Thumbro\Image\VipsHeaderOrientationDetector;
use MediaWiki\Extension\Thumbro\Tests\VipsHeaderProbeTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * Characterization tests for VipsHeaderOrientationDetector::getOrientation — pins the real
 * vipsheader-based orientation probe, its safe "1" failure modes, and the fact that neither
 * the expected "no orientation field" exit nor a warning from a successful probe reaches the
 * exec log as an ERROR (#104).
 *
 * @covers \MediaWiki\Extension\Thumbro\Image\VipsHeaderOrientationDetector
 * @group Thumbro
 */
class VipsHeaderOrientationDetectorTest extends MediaWikiIntegrationTestCase {
	use VipsHeaderProbeTestTrait;

	private const DATA_DIR = __DIR__ . '/../../data';

	/** @var string[] Stub directories to remove in tearDown (survives assertion failures). */
	private array $stubDirs = [];

	protected function tearDown(): void {
		foreach ( $this->stubDirs as $dir ) {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			@unlink( "$dir/vipsheader" );
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			@rmdir( $dir );
		}
		$this->stubDirs = [];
		parent::tearDown();
	}

	/**
	 * A directory holding a stub `vipsheader` running $body, to stand in for the real binary
	 * where the behaviour under test is one real vipsheader will not produce on demand. The
	 * returned path is the sibling `vipsthumbnail` the detector derives the lookup from; it
	 * need not exist, only its directory.
	 */
	private function stubVipsheader( string $body ): string {
		$dir = tempnam( sys_get_temp_dir(), 'thumbro_stub_' );
		unlink( $dir );
		mkdir( $dir, 0777 );
		$this->stubDirs[] = $dir;
		file_put_contents( "$dir/vipsheader", "#!/bin/sh\n$body" );
		chmod( "$dir/vipsheader", 0755 );
		return "$dir/vipsthumbnail";
	}

	public function testOrientedFileReportsItsExifOrientation(): void {
		$vipsthumbnail = $this->bin( 'vipsthumbnail' );
		$this->bin( 'vipsheader' );
		$this->assertSame( 8, ( new VipsHeaderOrientationDetector( $vipsthumbnail ) )
			->getOrientation( self::DATA_DIR . '/oriented.webp' ) );
	}

	public function testFileWithoutOrientationFieldReportsNoRotation(): void {
		$vipsthumbnail = $this->bin( 'vipsthumbnail' );
		$this->bin( 'vipsheader' );
		$this->assertSame( 1, ( new VipsHeaderOrientationDetector( $vipsthumbnail ) )
			->getOrientation( self::DATA_DIR . '/plain.png' ) );
	}

	/**
	 * The common case — a PNG, or any image that was never rotated, carries no orientation
	 * field, so vipsheader writes to stderr and exits non-zero. That is the documented "no
	 * rotation" path, not a failure, and must not surface as an ERROR in the exec log: before
	 * the fix every upload of such a file produced three of them (#104).
	 */
	public function testMissingOrientationFieldLogsNoError(): void {
		$vipsthumbnail = $this->bin( 'vipsthumbnail' );
		$this->bin( 'vipsheader' );
		$logger = $this->captureExecLog();

		( new VipsHeaderOrientationDetector( $vipsthumbnail ) )
			->getOrientation( self::DATA_DIR . '/plain.png' );

		$this->assertExecLogHasNoErrors( $logger,
			'a file with no orientation field is the normal case, not an error' );
	}

	/**
	 * The suppressed ERROR was keyed on stderr being non-empty, not on the exit code, so a
	 * probe that warns but still succeeds must stay off the exec channel too — and must still
	 * parse its stdout. Real vipsheader does not warn on success on demand, so this drives the
	 * same seam through a stub sibling binary.
	 */
	public function testWarningFromSuccessfulProbeLogsNoErrorAndStillParses(): void {
		$vipsthumbnail = $this->stubVipsheader(
			"echo 6\n" .
			"echo 'iCCP: known incorrect sRGB profile' >&2\n" .
			"exit 0\n"
		);
		$logger = $this->captureExecLog();

		$this->assertSame( 6, ( new VipsHeaderOrientationDetector( $vipsthumbnail ) )
			->getOrientation( self::DATA_DIR . '/plain.png' ),
			'a warning on stderr must not disturb the orientation parsed from stdout' );
		$this->assertExecLogHasNoErrors( $logger,
			'a warning from a probe that still succeeded is not an error either' );
	}

	public function testMissingSourceReturnsNoRotationAndLogsNoError(): void {
		$vipsthumbnail = $this->bin( 'vipsthumbnail' );
		$this->bin( 'vipsheader' );
		$logger = $this->captureExecLog();

		$this->assertSame( 1, ( new VipsHeaderOrientationDetector( $vipsthumbnail ) )
			->getOrientation( '/nonexistent/thumbro-does-not-exist.png' ) );
		$this->assertExecLogHasNoErrors( $logger );
	}

	/**
	 * An orientation outside the 1-8 EXIF range is not trusted, and falls back to upright.
	 */
	public function testOutOfRangeOrientationFallsBackToNoRotation(): void {
		$vipsthumbnail = $this->stubVipsheader( "echo 9\nexit 0\n" );
		$this->assertSame( 1, ( new VipsHeaderOrientationDetector( $vipsthumbnail ) )
			->getOrientation( self::DATA_DIR . '/plain.png' ) );
	}

	public function testVipsheaderMissingFromCommandDirReturnsNoRotation(): void {
		// vipsheader is looked up as a sibling of the configured vips command; a command
		// whose directory has no vipsheader can't be probed => safe 1.
		$this->assertSame( 1, ( new VipsHeaderOrientationDetector( '/nonexistent-thumbro-dir/vipsthumbnail' ) )
			->getOrientation( self::DATA_DIR . '/plain.png' ) );
	}
}
