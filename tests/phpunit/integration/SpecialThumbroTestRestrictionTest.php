<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
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

	public static function provideSecretKey(): array {
		return [
			'request carrying $wgSecretKey in X-Thumbro-Secret' => [ 'not-so-secret-key' ],
			'$wgSecretKey unset and no header' => [ false ],
		];
	}

	/**
	 * The page once let in any request whose X-Thumbro-Secret header matched $wgSecretKey, so
	 * the server could fetch its own thumbnails over HTTP. That sent the key to every host the
	 * images were fetched from, and let everyone in when $wgSecretKey was unset, because a
	 * missing header (false) then matched it. Only the thumbro-test right may grant access.
	 *
	 * @dataProvider provideSecretKey
	 */
	public function testSecretKeyHeaderDoesNotBypassRestriction( string|false $secretKey ): void {
		$this->overrideConfigValue( MainConfigNames::SecretKey, $secretKey );

		$request = new FauxRequest();
		if ( $secretKey !== false ) {
			$request->setHeader( 'X-Thumbro-Secret', $secretKey );
		}
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setConfig( $this->getServiceContainer()->getMainConfig() );
		$context->setRequest( $request );
		$context->setUser( $this->getServiceContainer()->getUserFactory()->newAnonymous() );
		$context->setTitle( Title::makeTitle( NS_SPECIAL, 'ThumbroTest' ) );
		$context->setOutput( new OutputPage( $context ) );

		$special = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'ThumbroTest' );
		$special->setContext( $context );

		$this->expectException( PermissionsError::class );
		$special->execute( null );
	}
}
