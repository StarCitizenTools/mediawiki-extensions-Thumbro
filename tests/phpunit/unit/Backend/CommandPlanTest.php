<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend;

use MediaWiki\Extension\Thumbro\Backend\CommandPlan;
use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\CommandPlan
 */
class CommandPlanTest extends MediaWikiUnitTestCase {

	public function testEmptyPlanIsEmpty(): void {
		$plan = CommandPlan::empty();
		$this->assertTrue( $plan->isEmpty() );
		$this->assertSame( [], $plan->getCommands() );
	}

	public function testOfKeepsCommandsInOrder(): void {
		$factory = $this->createMock( TempFSFileFactory::class );
		$a = new ShellCommand( $factory, 'libvips', '/bin/a', [] );
		$b = new ShellCommand( $factory, 'libwebp', '/bin/b', [], 'gif2webp' );
		$plan = CommandPlan::of( $a, $b );

		$this->assertFalse( $plan->isEmpty() );
		$this->assertSame( [ $a, $b ], $plan->getCommands() );
	}
}
