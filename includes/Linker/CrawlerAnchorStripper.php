<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Linker;

/**
 * Removes Thumbro's hidden crawler anchor (<a class="mw-file-source">…</a>) from an HTML
 * fragment. Used to strip the anchor when a thumbnail is wrapped in an external link,
 * where a nested <a> would be invalid HTML and break the wrap. The class appears nowhere
 * in MediaWiki core, so matching on it cannot remove a core anchor.
 *
 * @see \MediaWiki\Extension\Thumbro\ThumbroThumbnailImage::toHtml()
 */
class CrawlerAnchorStripper {

	/**
	 * @param string $html HTML fragment that may contain crawler anchors
	 * @return string The fragment with every crawler anchor removed
	 */
	public static function strip( string $html ): string {
		if ( !str_contains( $html, 'mw-file-source' ) ) {
			return $html;
		}

		// CSS classes are whitespace-delimited, so bound the token on chars that cannot
		// appear in a class name (not \b, which treats the '-' in mw-file-source-* as a
		// boundary and would over-match e.g. class="mw-file-source-extra"). The double
		// quotes are coupled to Html::rawElement(), which always emits double-quoted attrs.
		$stripped = preg_replace(
			'#<a\b[^>]*\bclass="[^"]*(?<![-\w])mw-file-source(?![-\w])[^"]*"[^>]*>.*?</a>#s',
			'',
			$html
		);

		return $stripped ?? $html;
	}
}
