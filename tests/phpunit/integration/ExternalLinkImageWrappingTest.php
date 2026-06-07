<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration;

use HtmlArmor;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * Exercises the real LinkRenderer + registered LinkerMakeExternalLink hook to confirm a
 * thumbnail's crawler anchor is stripped when wrapped in an external link, so the wrap
 * stays valid (trailing text remains inside the link).
 *
 * @covers \MediaWiki\Extension\Thumbro\Hooks\MediaWikiHooks::onLinkerMakeExternalLink
 * @covers \MediaWiki\Extension\Thumbro\Linker\CrawlerAnchorStripper
 * @group Thumbro
 */
class ExternalLinkImageWrappingTest extends MediaWikiIntegrationTestCase {

	public function testExternalLinkStripsWrappedCrawlerAnchor(): void {
		$linkRenderer = $this->getServiceContainer()->getLinkRenderer();

		$inner = '<picture><img src="/t.webp"></picture>'
			. '<a href="/images/orig.png" class="mw-file-source" title="View source image">'
			. '<!-- Image link for Crawlers --></a>Undertale';

		$html = $linkRenderer->makeExternalLink(
			'https://undertale.wiki/',
			new HtmlArmor( $inner ),
			Title::newFromText( 'Thumbro test' )
		);

		$this->assertStringNotContainsString( 'mw-file-source', $html );
		$this->assertStringContainsString( '<picture><img src="/t.webp"></picture>', $html );
		// Trailing text stays inside the single external anchor.
		$this->assertStringContainsString( 'Undertale</a>', $html );
	}
}
