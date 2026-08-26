<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Parsoid;

use File;
use MediaWiki\Extension\Thumbro\Parsoid\CrawlerAnchorProcessor;
use MediaWikiIntegrationTestCase;
use RepoGroup;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMUtils;

/**
 * The media fragments are real Parsoid output, captured from a live wiki rendering the
 * media shapes wikitext can produce, so the fixtures cannot drift from what Parsoid emits.
 *
 * Note the asymmetry they encode: `resource` is on every img, but `a.mw-file-description`
 * on only some — link=Page has a different anchor and link= has none. Keying on the
 * description anchor would silently miss those, which is why the processor keys on
 * `resource`.
 *
 * `resource` is the relative spec form here because that is what Parsoid hands a DOM
 * processor during the parse; the absolute URLs visible in rendered markup come from a
 * later expansion step.
 *
 * @covers \MediaWiki\Extension\Thumbro\Parsoid\CrawlerAnchorProcessor
 * @group Thumbro
 */
class CrawlerAnchorProcessorTest extends MediaWikiIntegrationTestCase {

	private const SOURCE_URL = '/w/images/a/a9/PdImg.png';

	private const THUMB = '<figure typeof="mw:File/Thumb">'
		. '<a href="./File:PdImg.png" class="mw-file-description">'
		. '<img resource="./File:PdImg.png" src="/w/images/thumb/a/a9/PdImg.png/300px-PdImg.png.webp"'
		. ' class="mw-file-element"/></a><figcaption>cap</figcaption></figure>';

	private const LINK_PAGE = '<span typeof="mw:File">'
		. '<a href="./Main_Page" title="Main Page">'
		. '<img resource="./File:PdImg.png" src="/w/images/thumb/a/a9/PdImg.png/200px-PdImg.png.webp"'
		. ' class="mw-file-element"/></a></span>';

	private const LINK_NONE = '<span typeof="mw:File"><span>'
		. '<img resource="./File:PdImg.png" src="/w/images/thumb/a/a9/PdImg.png/200px-PdImg.png.webp"'
		. ' class="mw-file-element"/></span></span>';

	// Gallery: media sits inside <li class="gallerybox"><div class="thumb">, and Parsoid uses
	// the same span[typeof=mw:File] wrapper it uses elsewhere. Captured from a live wiki.
	private const GALLERY = '<ul class="gallery mw-gallery-traditional" typeof="mw:Extension/gallery">'
		. '<li class="gallerybox" style="width: 155px;"><div class="thumb" style="width: 150px;">'
		. '<span typeof="mw:File">'
		. '<a href="./File:PdImg.png" class="mw-file-description" title="first">'
		. '<img alt="first" resource="./File:PdImg.png"'
		. ' src="/w/images/thumb/a/a9/PdImg.png/120px-PdImg.png.webp" class="mw-file-element"/>'
		. '</a></span></div><div class="gallerytext">first</div></li>'
		. '<li class="gallerybox" style="width: 155px;"><div class="thumb" style="width: 150px;">'
		. '<span typeof="mw:File">'
		. '<a href="./File:PdImg.png" class="mw-file-description" title="second">'
		. '<img alt="second" resource="./File:PdImg.png"'
		. ' src="/w/images/thumb/a/a9/PdImg.png/120px-PdImg.png.webp" class="mw-file-element"/>'
		. '</a></span></div><div class="gallerytext">second</div></li></ul>';

	// Template-generated media: Parsoid PREPENDS to typeof, so mw:File is not the first token.
	private const TRANSCLUDED = '<figure typeof="mw:Transclusion mw:File/Thumb" about="#mwt1">'
		. '<a href="./File:PdImg.png" class="mw-file-description">'
		. '<img resource="./File:PdImg.png" src="/w/images/thumb/a/a9/PdImg.png/300px-PdImg.png.webp"'
		. ' class="mw-file-element"/></a></figure>';

	private function processor( ?string $url = self::SOURCE_URL ): CrawlerAnchorProcessor {
		$repoGroup = $this->createMock( RepoGroup::class );
		if ( $url === null ) {
			$repoGroup->method( 'findFile' )->willReturn( false );
		} else {
			$file = $this->createMock( File::class );
			$file->method( 'getUrl' )->willReturn( $url );
			$repoGroup->method( 'findFile' )->willReturn( $file );
		}
		return new CrawlerAnchorProcessor( $repoGroup );
	}

	/**
	 * Runs the processor over a fragment the way Parsoid's extpp pass would, and returns
	 * the resulting body HTML.
	 */
	private function process( string $body, ?string $url = self::SOURCE_URL ): string {
		$doc = DOMUtils::parseHTML( '<body>' . $body . '</body>' );
		$root = DOMCompat::getBody( $doc );
		$this->processor( $url )->wtPostprocess(
			$this->createMock( ParsoidExtensionAPI::class ),
			$root,
			[]
		);
		return DOMCompat::getInnerHTML( $root );
	}

	private function anchorCount( string $html ): int {
		return preg_match_all( '#class="mw-file-source"#', $html );
	}

	public static function provideMediaShapes(): array {
		return [
			'thumb' => [ self::THUMB ],
			'link=Page' => [ self::LINK_PAGE ],
			'link= (no anchor)' => [ self::LINK_NONE ],
			'template-generated' => [ self::TRANSCLUDED ],
		];
	}

	/**
	 * @dataProvider provideMediaShapes
	 */
	public function testAddsAnchorToEveryMediaShape( string $shape ): void {
		$out = $this->process( $shape );

		$this->assertSame( 1, $this->anchorCount( $out ) );
		$this->assertStringContainsString( 'href="' . self::SOURCE_URL . '"', $out );
		$this->assertStringContainsString( 'tabindex="-1"', $out );
		$this->assertStringContainsString( 'aria-hidden="true"', $out );
	}

	/**
	 * Galleries nest the media wrapper inside <li class="gallerybox"><div class="thumb">, so
	 * the container walk has further to climb than in the inline shapes. The gallery's own
	 * <ul> carries typeof="mw:Extension/gallery", which must not be mistaken for the media
	 * container.
	 */
	public function testAddsAnchorToEachGalleryItem(): void {
		$out = $this->process( self::GALLERY );

		$this->assertSame( 2, $this->anchorCount( $out ) );
		$this->assertStringContainsString( 'href="' . self::SOURCE_URL . '"', $out );
		// Captions and gallery structure survive.
		$this->assertStringContainsString( 'gallerytext">first', $out );
		$this->assertStringContainsString( 'gallerytext">second', $out );
		$this->assertSame( 2, preg_match_all( '#class="gallerybox"#', $out ) );
	}

	/**
	 * The anchor belongs to the media container (the span), not the gallery <ul> or the
	 * <li> — putting it elsewhere would break the gallery layout and, on the <ul>, would
	 * associate one anchor with a whole gallery instead of each image.
	 */
	public function testGalleryAnchorSitsBesideItsOwnMedia(): void {
		$doc = DOMUtils::parseHTML( '<body>' . $this->process( self::GALLERY ) . '</body>' );

		foreach ( DOMCompat::querySelectorAll( DOMCompat::getBody( $doc ), 'a.mw-file-source' ) as $a ) {
			$parent = $a->parentNode;
			$this->assertInstanceOf( \Wikimedia\Parsoid\DOM\Element::class, $parent );
			$this->assertNotNull(
				DOMUtils::matchTypeOf( $parent, '#^mw:File($|/)#D' ),
				'anchor must be a child of the media container, not the gallery or list item'
			);
		}
	}

	public function testAddsOneAnchorPerImage(): void {
		$out = $this->process( self::THUMB . self::LINK_PAGE . self::LINK_NONE . self::TRANSCLUDED );

		$this->assertSame( 4, $this->anchorCount( $out ) );
	}

	/**
	 * The anchor must never become the media container's first child: MediaStructure::parse()
	 * takes the first non-separator child as the link element, would find this anchor's
	 * comment where the media element belongs, and return null — which makes figureHandler()
	 * emit nothing and silently deletes the image on the next save.
	 *
	 * @dataProvider provideMediaShapes
	 */
	public function testAnchorIsNeverTheContainersFirstChild( string $shape ): void {
		$doc = DOMUtils::parseHTML( '<body>' . $this->process( $shape ) . '</body>' );

		$containers = 0;
		foreach ( DOMCompat::querySelectorAll( DOMCompat::getBody( $doc ), 'figure, span' ) as $el ) {
			if ( DOMUtils::matchTypeOf( $el, '#^mw:File($|/)#D' ) === null ) {
				continue;
			}
			$containers++;
			$first = $el->firstChild;
			$this->assertInstanceOf( \Wikimedia\Parsoid\DOM\Element::class, $first );
			$this->assertNotSame(
				'mw-file-source',
				DOMCompat::getAttribute( $first, 'class' ),
				'anchor must not be the media container\'s first child'
			);
		}
		$this->assertGreaterThan( 0, $containers, 'fixture must contain a media container' );
	}

	public function testIsIdempotent(): void {
		$once = $this->process( self::THUMB );
		$twice = $this->process( $once );

		$this->assertSame( 1, $this->anchorCount( $twice ) );
	}

	public function testUnresolvableFileIsSkippedRatherThanEmittingABrokenAnchor(): void {
		$this->assertSame( 0, $this->anchorCount( $this->process( self::THUMB, null ) ) );
	}

	public function testIgnoresResourceOutsideTheFileNamespace(): void {
		$body = str_replace( 'resource="./File:PdImg.png"', 'resource="./Main_Page"', self::THUMB );

		$this->assertSame( 0, $this->anchorCount( $this->process( $body ) ) );
	}

	public function testLeavesMediaWithoutResourceAlone(): void {
		$body = str_replace( ' resource="./File:PdImg.png"', '', self::THUMB );

		$this->assertSame( 0, $this->anchorCount( $this->process( $body ) ) );
	}

	public function testCaptionIsPreserved(): void {
		$out = $this->process( self::THUMB );

		$this->assertStringContainsString( 'cap</figcaption>', $out );
	}
}
