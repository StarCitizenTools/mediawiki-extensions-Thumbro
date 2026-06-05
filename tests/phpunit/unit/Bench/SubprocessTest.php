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
}
