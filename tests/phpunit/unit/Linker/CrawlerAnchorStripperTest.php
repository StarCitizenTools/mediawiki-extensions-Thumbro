<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Linker;

use MediaWiki\Extension\Thumbro\Linker\CrawlerAnchorStripper;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Linker\CrawlerAnchorStripper
 */
class CrawlerAnchorStripperTest extends MediaWikiUnitTestCase {

	public function testStripsSingleCrawlerAnchor(): void {
		$picture = '<picture><img src="/t.webp" width="84" height="60"></picture>';
		$anchor = '<a href="/images/orig.png" class="mw-file-source" title="View source image">'
			. '<!-- Image link for Crawlers --></a>';
		$this->assertSame( $picture, CrawlerAnchorStripper::strip( $picture . $anchor ) );
	}

	public function testStripsMultipleCrawlerAnchors(): void {
		$a = '<a href="/a.png" class="mw-file-source"><!-- x --></a>';
		$b = '<a href="/b.png" class="mw-file-source"><!-- y --></a>';
		$this->assertSame( 'AB', CrawlerAnchorStripper::strip( 'A' . $a . 'B' . $b ) );
	}

	public function testLeavesFragmentWithoutMarkerUntouched(): void {
		$html = '<picture><img src="/t.webp"></picture><a href="/x">link</a>';
		$this->assertSame( $html, CrawlerAnchorStripper::strip( $html ) );
	}

	public function testDoesNotOverMatchUnrelatedTrailingAnchor(): void {
		$anchor = '<a href="/orig.png" class="mw-file-source"><!-- c --></a>';
		$keep = '<a href="/page">Undertale</a>';
		$this->assertSame( 'IMG' . $keep, CrawlerAnchorStripper::strip( 'IMG' . $anchor . $keep ) );
	}

	public function testToleratesMultiClassValue(): void {
		$anchor = '<a href="/orig.png" class="mw-file-source extra"><!-- c --></a>';
		$this->assertSame( 'X', CrawlerAnchorStripper::strip( 'X' . $anchor ) );
	}

	/**
	 * mw-file-source must match as a whole class token, not as a hyphen-segment of a
	 * larger class name — otherwise an unrelated element would be silently deleted.
	 */
	public function testDoesNotMatchHyphenatedClassNames(): void {
		$suffix = '<a href="/x" class="mw-file-source-extra">trailing</a>';
		$this->assertSame( $suffix, CrawlerAnchorStripper::strip( $suffix ) );

		$prefix = '<a href="/x" class="my-mw-file-source">trailing</a>';
		$this->assertSame( $prefix, CrawlerAnchorStripper::strip( $prefix ) );
	}
}
