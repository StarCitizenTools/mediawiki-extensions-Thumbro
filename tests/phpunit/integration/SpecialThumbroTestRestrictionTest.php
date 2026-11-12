<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration;

use MediaWikiIntegrationTestCase;
use PermissionsError;

/**
 * Special:ThumbroTest must stay limited to users with the `thumbro-test` right on every
 * supported MediaWiki version.
 *
 * The restriction is declared by overriding getRestriction() because MW 1.47 no longer
 * accepts it as a constructor argument. Before MW 1.46, core's restriction checks ignore
 * getRestriction(), so without the page's own overrides the page would be open to everyone.
 *
 * @covers \MediaWiki\Extension\Thumbro\SpecialThumbroTest
 * @group Thumbro
 * @group Database
 */
class SpecialThumbroTestRestrictionTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'ThumbroExposeTestPage', true );
	}

	public function testPageIsRestrictedToThumbroTestRight(): void {
		$special = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'ThumbroTest' );

		$this->assertSame( 'thumbro-test', $special->getRestriction() );
		$this->assertTrue( $special->isRestricted() );
		$this->assertFalse( $special->userCanExecute( $this->getTestUser()->getUser() ) );
		$this->assertTrue( $special->userCanExecute( $this->getTestSysop()->getUser() ) );
	}

	public function testRestrictionErrorListsGroupsWithThumbroTestRight(): void {
		$special = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'ThumbroTest' );

		try {
			$special->displayRestrictionError();
			$this->fail( 'Expected PermissionsError' );
		} catch ( PermissionsError $e ) {
			// 'badaccess-groups' lists the groups holding the required right (sysop). An empty
			// restriction would have no such groups and produce 'badaccess-group0' instead.
			$this->assertSame( 'badaccess-groups', $e->getMessageObject()->getKey() );
		}
	}
}
