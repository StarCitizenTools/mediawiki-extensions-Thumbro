<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Bench;

use MediaWiki\Extension\Thumbro\Bench\Subprocess;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Bench\Subprocess
 */
class SubprocessTest extends MediaWikiUnitTestCase {
	public function testParseTimeStatExtractsPeakRss(): void {
		$report = "\tCommand being timed: \"convert\"\n"
			. "\tMaximum resident set size (kbytes): 83020\n"
			. "\tExit status: 0\n";
		$this->assertSame( 83020, Subprocess::parseTimeStat( $report ) );
	}

	public function testParseTimeStatReturnsNullWhenAbsent(): void {
		$this->assertNull( Subprocess::parseTimeStat( "garbage\n" ) );
	}

	public function testRunCapturesNonNegativeWallTime(): void {
		// End-to-end smoke of run(): a trivial command must report exit 0 and a
		// non-negative wall time. The monotonic clock (hrtime) guarantees the latter —
		// microtime's CLOCK_REALTIME could go negative on a clock step (e.g. WSL2).
		if ( !is_executable( Subprocess::$timeBin ) || !is_executable( '/bin/true' ) ) {
			$this->markTestSkipped( 'GNU time or /bin/true unavailable' );
		}
		$res = Subprocess::run( [ '/bin/true' ] );
		$this->assertSame( 0, $res->exitCode );
		$this->assertGreaterThanOrEqual( 0.0, $res->wallMs );
	}
}
