<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration;

use File;
use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;
use MediaWikiIntegrationTestCase;

/**
 * Characterization tests for ThumbroThumbnailImage::toHtml — pins the rendered markup
 * (the <picture> wrapper, the <img>, dimensions, and the hidden crawler anchor) that
 * every Thumbro thumbnail emits. Critical path for output HTML.
 *
 * @covers \MediaWiki\Extension\Thumbro\ThumbroThumbnailImage
 * @group Thumbro
 */
class ThumbroThumbnailImageTest extends MediaWikiIntegrationTestCase {

	private function image( string $thumbUrl, string $fileUrl, int $w, int $h ): ThumbroThumbnailImage {
		$file = $this->createMock( File::class );
		$file->method( 'getUrl' )->willReturn( $fileUrl );
		return new ThumbroThumbnailImage( $file, $thumbUrl, $w, $h, '/tmp/thumb.webp' );
	}

	public function testRendersPictureWithImgAndDimensions(): void {
		$html = $this->image( '/w/thumb/84px-t.webp', '/w/images/t.gif', 84, 60 )->toHtml( [] );

		$this->assertStringContainsString( '<picture', $html );
		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'src="/w/thumb/84px-t.webp"', $html );
		$this->assertStringContainsString( 'width="84"', $html );
		$this->assertStringContainsString( 'height="60"', $html );
		$this->assertStringContainsString( 'decoding="async"', $html );
	}

	public function testRendersHiddenCrawlerAnchorToOriginal(): void {
		$html = $this->image( '/w/thumb/84px-t.webp', '/w/images/original.gif', 84, 84 )->toHtml( [] );

		// The hidden anchor lets crawlers reach the original-resolution image (T54647).
		$this->assertStringContainsString( 'mw-file-source', $html );
		$this->assertStringContainsString( 'href="/w/images/original.gif"', $html );
	}

	public function testNoDimensionsOptionOmitsWidthHeight(): void {
		$html = $this->image( '/w/thumb/84px-t.webp', '/w/images/t.gif', 84, 84 )
			->toHtml( [ 'no-dimensions' => true ] );

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'width="84"', $html );
		$this->assertStringNotContainsString( 'height="84"', $html );
	}
}
