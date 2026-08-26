<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Linker;

use MediaWiki\Html\Html;

/**
 * Builds Thumbro's hidden crawler anchor — the link that makes the original-resolution
 * file reachable by search engines (T54647). On a stock wiki the source URL appears
 * nowhere crawlable: the <img> carries a thumbnail, and the thumbnail listings that do
 * link originals are NOINDEX'd.
 *
 * Two code paths emit this anchor, because each parser exposes the file differently:
 * ThumbroThumbnailImage::toHtml() has the File object at construction time, while
 * Parsoid output is post-processed and identifies files by the img's `resource`
 * attribute. The markup itself lives here so those paths cannot drift.
 *
 * @see \MediaWiki\Extension\Thumbro\ThumbroThumbnailImage::toHtml()
 * @see \MediaWiki\Extension\Thumbro\Linker\ParsoidCrawlerAnchorInjector
 */
class CrawlerAnchor {

	/** Class marking the anchor; matched by the stripper and the injector's idempotency check. */
	public const CLASS_NAME = 'mw-file-source';

	/** Text of the anchor's comment payload, without the delimiters. */
	public const COMMENT_TEXT = ' Image link for Crawlers ';

	/** Sole content of the anchor — it has no text, so it renders at zero size. */
	public const CONTENT = '<!--' . self::COMMENT_TEXT . '-->';

	/**
	 * The anchor's attributes. Both emitting paths build from this array — the string
	 * path via build(), the DOM path by setting each pair — so neither can drift.
	 *
	 * Inert for humans, intact for crawlers, which honour neither attribute. tabindex and
	 * aria-hidden must stay together: aria-hidden on a focusable element is itself a WCAG
	 * 4.1.2 failure, and tabindex alone would leave a screen reader announcing one
	 * nameless link per thumbnail (#97).
	 *
	 * @param string $sourceUrl URL of the original-resolution file
	 * @return array<string,string>
	 */
	public static function attributes( string $sourceUrl ): array {
		return [
			'href' => $sourceUrl,
			'class' => self::CLASS_NAME,
			'tabindex' => '-1',
			'aria-hidden' => 'true',
		];
	}

	/**
	 * @param string $sourceUrl URL of the original-resolution file
	 * @return string HTML for the anchor
	 */
	public static function build( string $sourceUrl ): string {
		return Html::rawElement( 'a', self::attributes( $sourceUrl ), self::CONTENT );
	}
}
