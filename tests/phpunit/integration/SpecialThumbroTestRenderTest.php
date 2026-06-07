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

/**
 * Regression test for the comparison-render path of Special:ThumbroTest.
 *
 * showThumbnails() had no coverage and a DI refactor left an undefined $services variable
 * on the line that expands the "normal" thumbnail URL — a guaranteed fatal the moment a
 * sysop ran a comparison. This drives showThumbnails() with a mock file and the REAL injected
 * UrlUtils to prove that line executes. Targets the non-imagick path (the imagick branch makes
 * outbound HTTP requests, out of scope for a unit-level render check).
 *
 * @covers \MediaWiki\Extension\Thumbro\SpecialThumbroTest
 * @group Thumbro
 */
class SpecialThumbroTestRenderTest extends MediaWikiIntegrationTestCase {

	public function testShowThumbnailsExpandsThumbUrlWithoutFatal(): void {
		if ( extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'targets the non-imagick comparison path' );
		}

		$services = $this->getServiceContainer();

		$thumb = $this->createMock( MediaTransformOutput::class );
		$thumb->method( 'isError' )->willReturn( false );
		$thumb->method( 'getUrl' )->willReturn( '/w/images/thumb/Cherry.jpg/120px-Cherry.jpg' );
		$thumb->method( 'getWidth' )->willReturn( 120 );
		$thumb->method( 'getHeight' )->willReturn( 80 );

		$file = $this->createMock( File::class );
		$file->method( 'exists' )->willReturn( true );
		$file->method( 'getName' )->willReturn( 'Cherry.jpg' );
		$file->method( 'transform' )->willReturn( $thumb );
		$file->method( 'getFullUrl' )->willReturn( 'http://wiki.example/w/images/Cherry.jpg' );

		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );

		$special = new SpecialThumbroTest(
			$services->get( 'Thumbro.OptionsResolver' ),
			$services->get( 'Thumbro.EncodePipeline' ),
			$repoGroup,
			$services->getHttpRequestFactory(),
			$services->getTempFSFileFactory(),
			$services->getUrlUtils()
		);

		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setRequest( new FauxRequest( [ 'file' => 'Cherry.jpg', 'width' => '120' ] ) );
		$context->setTitle( Title::makeTitle( NS_SPECIAL, 'ThumbroTest' ) );
		$context->setOutput( new OutputPage( $context ) );
		$special->setContext( $context );

		$method = new ReflectionMethod( $special, 'showThumbnails' );
		$method->setAccessible( true );
		// Pre-fix this threw "Call to a member function getUrlUtils() on null".
		$method->invoke( $special );

		$html = $context->getOutput()->getHTML();
		// The expanded "normal" thumbnail URL (UrlUtils->expand of the thumb URL) must appear,
		// proving the comparison rendered past the previously-fatal line.
		$this->assertStringContainsString( '120px-Cherry.jpg', $html );
	}
}
