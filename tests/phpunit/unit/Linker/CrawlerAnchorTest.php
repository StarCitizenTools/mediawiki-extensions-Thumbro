<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Linker;

use MediaWiki\Extension\Thumbro\Linker\CrawlerAnchor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Linker\CrawlerAnchor
 */
class CrawlerAnchorTest extends MediaWikiUnitTestCase {

	/**
	 * Exact-markup pin. Both emitting paths (toHtml and the Parsoid injector) route
	 * through here, so this is the single place the anchor's shape is guarded. None of
	 * these attributes has a visible effect, so nothing else would catch their removal.
	 */
	public function testBuildsInertCrawlerAnchor(): void {
		$this->assertSame(
			'<a href="/w/images/9/90/Foo.png" class="mw-file-source" tabindex="-1"'
				. ' aria-hidden="true"><!-- Image link for Crawlers --></a>',
			CrawlerAnchor::build( '/w/images/9/90/Foo.png' )
		);
	}

	/**
	 * aria-hidden is not one of Html's boolean attributes, so a PHP bool would render the
	 * invalid aria-hidden="1" — a silent failure with no other symptom.
	 */
	public function testAriaHiddenIsTheStringTrue(): void {
		$html = CrawlerAnchor::build( '/x.png' );

		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringNotContainsString( 'aria-hidden="1"', $html );
	}

	/**
	 * The anchor must never gain an accessible name: it is out of the accessibility tree,
	 * and a name would also mean shipping an untranslated string.
	 */
	public function testHasNoAccessibleName(): void {
		$html = CrawlerAnchor::build( '/x.png' );

		$this->assertStringNotContainsString( 'title=', $html );
		$this->assertStringNotContainsString( 'View source image', $html );
	}

	public function testEscapesTheUrl(): void {
		$html = CrawlerAnchor::build( '/w/images/a/ab/Foo"bar&baz.png' );

		$this->assertStringContainsString( '&quot;', $html );
		$this->assertStringContainsString( '&amp;', $html );
		$this->assertStringNotContainsString( '"bar', $html );
	}

	/**
	 * The stripper and the injector's idempotency check both key on this token.
	 */
	public function testClassNameMatchesTheEmittedMarkup(): void {
		$this->assertStringContainsString(
			'class="' . CrawlerAnchor::CLASS_NAME . '"',
			CrawlerAnchor::build( '/x.png' )
		);
	}
}
