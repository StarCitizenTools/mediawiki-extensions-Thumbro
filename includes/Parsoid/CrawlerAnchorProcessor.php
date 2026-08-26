<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Parsoid;

use MediaWiki\Extension\Thumbro\Linker\CrawlerAnchor;
use MediaWiki\Title\Title;
use RepoGroup;
use Wikimedia\Parsoid\DOM\Document;
use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Ext\DOMProcessor;
use Wikimedia\Parsoid\Ext\DOMUtils;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Parsoid\Utils\DOMCompat;

/**
 * Adds Thumbro's crawler anchor to Parsoid-generated media.
 *
 * Parsoid builds the media DOM itself and never calls
 * ThumbroThumbnailImage::toHtml(), so without this the original-resolution URL is absent
 * from Parsoid read views entirely and T54647 fully regresses — on a stock wiki that URL
 * appears nowhere crawlable, because the <img> carries a thumbnail and the thumbnail
 * listings that do link originals are NOINDEX'd.
 *
 * Runs as a Parsoid DOM processor rather than a post-parse hook so the work happens on the
 * live DOM inside the parse: no re-parse, no re-serialize, and therefore no risk of
 * disturbing data-parsoid/data-mw, which are held off-DOM in a node-data bag during the
 * parse and written back by Parsoid itself.
 *
 * Ordering: `extpp` runs after the `media` pass, so AddMediaInfo has already replaced the
 * placeholder with the real <img> by the time this sees the tree.
 */
class CrawlerAnchorProcessor extends DOMProcessor {

	public function __construct( private readonly RepoGroup $repoGroup ) {
	}

	/**
	 * @inheritDoc
	 */
	public function wtPostprocess(
		ParsoidExtensionAPI $extApi, Node $root, array $options
	): void {
		// The interface types $root as Node; Parsoid documents it as DocumentFragment|Element
		// and only those can be queried.
		if ( !$root instanceof Element && !$root instanceof DocumentFragment ) {
			return;
		}

		foreach ( DOMCompat::querySelectorAll( $root, 'img.mw-file-element[resource]' ) as $img ) {
			$container = $this->findMediaContainer( $img );
			if ( !$container ) {
				continue;
			}

			// Idempotency: never add a second anchor to the same container.
			if ( DOMCompat::querySelector( $container, 'a.' . CrawlerAnchor::CLASS_NAME ) ) {
				continue;
			}

			$url = $this->resolveSourceUrl( DOMCompat::getAttribute( $img, 'resource' ) ?? '' );
			if ( $url === null ) {
				continue;
			}

			$wrapper = $this->findMediaWrapper( $img, $container );
			if ( !$wrapper ) {
				continue;
			}

			// Parsoid's DOM\Document is a class_alias, so this is a no-op at runtime; the
			// check narrows the type for static analysis, which resolves the alias
			// differently across the supported MediaWiki versions.
			$doc = $img->ownerDocument;
			if ( !$doc instanceof Document ) {
				continue;
			}

			$anchor = $this->buildAnchor( $doc, $url );
			if ( !$anchor ) {
				continue;
			}

			// After the media wrapper, never as the container's first child:
			// MediaStructure::parse() takes the first non-separator child as the link
			// element, would find this anchor's comment where the media element belongs,
			// and return null — which makes figureHandler() emit nothing and silently
			// deletes the image on the next save.
			$container->insertBefore( $anchor, $wrapper->nextSibling );
		}
	}

	/**
	 * The <figure> or <span> carrying a mw:File typeof that encloses this media element.
	 *
	 * @param Element $img
	 * @return Element|null
	 */
	private function findMediaContainer( Element $img ): ?Element {
		$node = $img->parentNode;
		while ( $node instanceof Element ) {
			// typeof is multi-valued and Parsoid PREPENDS to it, so template-generated
			// media reads "mw:Transclusion mw:File/Thumb". A prefix test would miss every
			// infobox image on the wiki.
			if ( DOMUtils::matchTypeOf( $node, '#^mw:File($|/)#D' ) !== null ) {
				return $node;
			}
			$node = $node->parentNode;
		}
		return null;
	}

	/**
	 * The direct child of $container holding $img — the <a> for linked media, or a bare
	 * <span> for link=.
	 *
	 * @param Element $img
	 * @param Element $container
	 * @return Element|null
	 */
	private function findMediaWrapper( Element $img, Element $container ): ?Element {
		$node = $img;
		while ( $node->parentNode !== $container ) {
			$parent = $node->parentNode;
			if ( !( $parent instanceof Element ) ) {
				return null;
			}
			$node = $parent;
		}
		return $node;
	}

	/**
	 * Resolve the img's `resource` attribute to the original file's URL. The file is
	 * resolved properly rather than deriving a URL from the thumbnail path, which is
	 * silently wrong for foreign repos and custom thumb scripts.
	 *
	 * @param string $resource
	 * @return string|null
	 */
	private function resolveSourceUrl( string $resource ): ?string {
		if ( !str_starts_with( $resource, './' ) ) {
			// Parsoid's spec form for `resource` is a relative title. Anything else is not
			// something this pass should guess at.
			return null;
		}

		$title = Title::newFromText( rawurldecode( substr( $resource, 2 ) ) );
		if ( !$title || $title->getNamespace() !== NS_FILE ) {
			return null;
		}

		$file = $this->repoGroup->findFile( $title );
		return $file ? $file->getUrl() : null;
	}

	/**
	 * @param Document $doc
	 * @param string $url
	 * @return Element|null
	 */
	private function buildAnchor( Document $doc, string $url ): ?Element {
		$anchor = $doc->createElement( 'a' );
		if ( !$anchor instanceof Element ) {
			return null;
		}
		foreach ( CrawlerAnchor::attributes( $url ) as $name => $value ) {
			$anchor->setAttribute( $name, $value );
		}
		$anchor->appendChild( $doc->createComment( CrawlerAnchor::COMMENT_TEXT ) );
		return $anchor;
	}
}
