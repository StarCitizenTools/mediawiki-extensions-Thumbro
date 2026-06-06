<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration;

use MediaWiki\Extension\Thumbro\Transparency;
use MediaWikiIntegrationTestCase;

/**
 * Characterization tests for Transparency::hasAlpha — pins the real vipsheader-based
 * alpha probe and its safe-false failure modes.
 *
 * @covers \MediaWiki\Extension\Thumbro\Transparency
 * @group Thumbro
 */
class TransparencyTest extends MediaWikiIntegrationTestCase {

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

	private function bin( string $name ): string {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$path = trim( (string)shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );
		if ( $path === '' ) {
			$this->markTestSkipped( "$name not available" );
		}
		return $path;
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
		$this->assertTrue( Transparency::hasAlpha( $src, $vipsthumbnail ) );
	}

	public function testOpaqueGifReportsNoAlpha(): void {
		$vipsthumbnail = $this->bin( 'vipsthumbnail' );
		$this->bin( 'vipsheader' );
		$src = $this->makeGif( 'thumbro_op_', false );
		$this->assertFalse( Transparency::hasAlpha( $src, $vipsthumbnail ) );
	}

	public function testMissingSourceReturnsFalse(): void {
		$vipsthumbnail = $this->bin( 'vipsthumbnail' );
		$this->bin( 'vipsheader' );
		$this->assertFalse(
			Transparency::hasAlpha( '/nonexistent/thumbro-does-not-exist.gif', $vipsthumbnail )
		);
	}

	public function testVipsheaderMissingFromCommandDirReturnsFalse(): void {
		// vipsheader is looked up as a sibling of the configured vips command; a command
		// whose directory has no vipsheader can't be probed => safe false.
		$this->assertFalse(
			Transparency::hasAlpha( '/tmp/whatever.gif', '/nonexistent-thumbro-dir/vipsthumbnail' )
		);
	}
}
