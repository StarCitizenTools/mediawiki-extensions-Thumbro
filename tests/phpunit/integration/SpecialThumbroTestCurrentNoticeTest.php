<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration;

use File;
use MediaTransformOutput;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Thumbro\SpecialThumbroTest;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use ReflectionMethod;
use RepoGroup;
use TransformationalImageHandler;

/**
 * When Thumbro also makes the wiki's current thumbnail of a file, Special:ThumbroTest compares
 * Thumbro with itself. The page then says so, and how to compare with MediaWiki's own
 * thumbnail instead, using the same checks as the BitmapHandlerTransform hook.
 *
 * @covers \MediaWiki\Extension\Thumbro\SpecialThumbroTest
 * @group Thumbro
 */
class SpecialThumbroTestCurrentNoticeTest extends MediaWikiIntegrationTestCase {

	private const NOTICE = '(thumbro-thumb-current-is-thumbro)';

	public function testWarnsWhenThumbroMakesCurrentThumbnail(): void {
		$html = $this->renderComparison( $this->createMock( TransformationalImageHandler::class ) );

		$this->assertStringContainsString( self::NOTICE, $html );
		$this->assertStringContainsString( 'cdx-message--warning', $html );
	}

	public static function provideNoNotice(): array {
		return [
			'Thumbro disabled' => [ [ 'ThumbroEnabled' => false ], true ],
			'file type disabled in $wgThumbroOptions' => [
				[ 'ThumbroOptions' => [ 'image/png' => [ 'enabled' => false ] ] ],
				true,
			],
			'file without a transformational handler' => [ [], false ],
		];
	}

	/**
	 * @dataProvider provideNoNotice
	 */
	public function testNoNoticeWhenThumbroDoesNotMakeCurrentThumbnail( array $config, bool $hasHandler ): void {
		$this->overrideConfigValues( $config );
		$handler = $hasHandler ? $this->createMock( TransformationalImageHandler::class ) : false;

		$html = $this->renderComparison( $handler );

		$this->assertStringNotContainsString( self::NOTICE, $html );
		// The comparison itself still renders
		$this->assertStringContainsString( 'mw-thumbrotest-thumbnails', $html );
	}

	/**
	 * Render a comparison of a PNG file, and return the page HTML
	 */
	private function renderComparison( TransformationalImageHandler|false $handler ): string {
		$services = $this->getServiceContainer();

		$thumb = $this->createMock( MediaTransformOutput::class );
		$thumb->method( 'isError' )->willReturn( false );
		$thumb->method( 'getUrl' )->willReturn( '/w/images/thumb/Dice.png/120px-Dice.png' );
		$thumb->method( 'getWidth' )->willReturn( 120 );
		$thumb->method( 'getHeight' )->willReturn( 90 );
		// No local copies, so the Imagick metrics are skipped where Imagick is loaded
		$thumb->method( 'getLocalCopyPath' )->willReturn( false );

		$file = $this->createMock( File::class );
		$file->method( 'exists' )->willReturn( true );
		$file->method( 'getName' )->willReturn( 'Dice.png' );
		$file->method( 'getMimeType' )->willReturn( 'image/png' );
		$file->method( 'getHandler' )->willReturn( $handler );
		$file->method( 'transform' )->willReturn( $thumb );
		$file->method( 'getFullUrl' )->willReturn( 'http://wiki.example/w/images/Dice.png' );
		$file->method( 'getLocalRefPath' )->willReturn( false );

		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );

		$special = new SpecialThumbroTest(
			$services->get( 'Thumbro.OptionsResolver' ),
			$services->get( 'Thumbro.EncodePipeline' ),
			$repoGroup,
			$services->getTempFSFileFactory(),
			$services->getUrlUtils(),
			$services->getPermissionManager(),
			$services->getGroupPermissionsLookup()
		);

		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setConfig( $services->getMainConfig() );
		$context->setLanguage( 'qqx' );
		$context->setRequest( new FauxRequest( [ 'file' => 'Dice.png', 'width' => '120' ] ) );
		$context->setTitle( Title::makeTitle( NS_SPECIAL, 'ThumbroTest' ) );
		$context->setOutput( new OutputPage( $context ) );
		$special->setContext( $context );

		$method = new ReflectionMethod( $special, 'showThumbnails' );
		$method->invoke( $special );

		return $context->getOutput()->getHTML();
	}
}
