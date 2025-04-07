<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro;

use InvalidArgumentException;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Html\Html;
use MediaWiki\Extension\Thumbro\Hooks\HookRunner as ThumbroHookRunner;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use ThumbnailImage;

/**
 * Override MW core ThumbnailImage class
 *
 * @see https://doc.wikimedia.org/mediawiki-core/master/php/ThumbnailImage_8php.html
 */
class ThumbroThumbnailImage extends ThumbnailImage {
	/**
	 * @inheritDoc
	 */
	public function toHtml( $options = [] ) {
		$services = MediaWikiServices::getInstance();
		$mainConfig = $services->getMainConfig();
		$nativeImageLazyLoading = $mainConfig->get( MainConfigNames::NativeImageLazyLoading );
		$useLegacyMediaStyles = $mainConfig->get( MainConfigNames::UseLegacyMediaStyles );

		if ( func_num_args() === 2 ) {
			throw new InvalidArgumentException( __METHOD__ . ' called in the old style' );
		}

		$query = $options['desc-query'] ?? '';

		$attribs = [];

		// An empty alt indicates an image is not a key part of the content and
		// that non-visual browsers may omit it from rendering.  Only set the
		// parameter if it's explicitly requested.
		if ( isset( $options['alt'] ) ) {
			$attribs['alt'] = $options['alt'];
		}

		// Description links get the mw-file-description class and link
		// to the file description page, making the resource redundant
		if (
			!$useLegacyMediaStyles &&
			isset( $options['magnify-resource'] ) &&
			!( $options['desc-link'] ?? false )
		) {
			$attribs['resource'] = $options['magnify-resource'];
		}

		$attribs += [
			'src' => $this->url,
			'decoding' => 'async',
		];

		if ( $options['loading'] ?? $nativeImageLazyLoading ) {
			$attribs['loading'] = $options['loading'] ?? 'lazy';
		}

		if ( !empty( $options['custom-url-link'] ) ) {
			$linkAttribs = [ 'href' => $options['custom-url-link'] ];
			if ( !empty( $options['title'] ) ) {
				$linkAttribs['title'] = $options['title'];
			}
			if ( !empty( $options['custom-target-link'] ) ) {
				$linkAttribs['target'] = $options['custom-target-link'];
			} elseif ( !empty( $options['parser-extlink-target'] ) ) {
				$linkAttribs['target'] = $options['parser-extlink-target'];
			}
			if ( !empty( $options['parser-extlink-rel'] ) ) {
				$linkAttribs['rel'] = $options['parser-extlink-rel'];
			}
		} elseif ( !empty( $options['custom-title-link'] ) ) {
			/** @var Title $title */
			$title = $options['custom-title-link'];
			$linkAttribs = [
				'href' => $title->getLinkURL( $options['custom-title-link-query'] ?? null ),
				'title' => empty( $options['title'] ) ? $title->getPrefixedText() : $options['title']
			];
		} elseif ( !empty( $options['desc-link'] ) ) {
			$linkAttribs = $this->getDescLinkAttribs(
				empty( $options['title'] ) ? null : $options['title'],
				$query
			);
		} elseif ( !empty( $options['file-link'] ) ) {
			$linkAttribs = [ 'href' => $this->file->getUrl() ];
		} else {
			$linkAttribs = false;
			if ( !empty( $options['title'] ) ) {
				if ( $useLegacyMediaStyles ) {
					$attribs['title'] = $options['title'];
				} else {
					$linkAttribs = [ 'title' => $options['title'] ];
				}
			}
		}

		if ( empty( $options['no-dimensions'] ) ) {
			$attribs['width'] = $this->width;
			$attribs['height'] = $this->height;
		}
		if ( !empty( $options['valign'] ) ) {
			$attribs['style'] = "vertical-align: {$options['valign']}";
		}
		if ( !empty( $options['img-class'] ) ) {
			$attribs['class'] = $options['img-class'];
		}
		if ( isset( $options['override-height'] ) ) {
			$attribs['height'] = $options['override-height'];
		}
		if ( isset( $options['override-width'] ) ) {
			$attribs['width'] = $options['override-width'];
		}

		// Additional densities for responsive images, if specified.
		// If any of these urls is the same as src url, it'll be excluded.
		$responsiveUrls = array_diff( $this->responsiveUrls, [ $this->url ] );
		if ( $responsiveUrls ) {
			$attribs['srcset'] = Html::srcSet( $responsiveUrls );
		}

		$hookContainer = $services->getHookContainer();
		( new HookRunner( $hookContainer ) )->onThumbnailBeforeProduceHTML( $this, $attribs, $linkAttribs );

		$sources = [];
		if ( isset ($attribs[ 'srcset' ] ) ) {
			// Move srcset from img to source element
			$sources[] = [ 'srcset' => $this->url . ', ' . $attribs['srcset'] ];
			unset( $attribs['srcset'] );
		}
		( new ThumbroHookRunner( $hookContainer ) )->onThumbroBeforeProduceHtml( $this, $sources );

		$p = Html::openElement('picture');

		foreach ( $sources as $source ) {
			// <source> should always have a valid srcset when inside <picture>
			if (!$source['srcset'] ) {
				continue;
			}

			$sourceAttribs = [
				'srcset' => $source['srcset'],
			];

			if ( !empty( $source['type'] ) ) {
				$sourceAttribs['type'] = $source['type'];
			}
			if ( !empty( $source['sizes'] ) ) {
				$sourceAttribs['sizes'] = $source['sizes'];
			}
			if ( !empty( $source['media'] ) ) {
				$sourceAttribs['media'] = $source['media'];
			}
			if ( !empty($source['width'] ) && !empty( $attribs['width'] ) && $source['width'] !== $attribs['width'] ) {
				$sourceAttribs['width'] = $source['width'];
			}
			if ( !empty($source['height'] ) && !empty( $attribs['height'] ) && $source['height'] !== $attribs['height'] ) {
				$sourceAttribs['height'] = $source['height'];
			}

			$p .= Html::element( 'source', $sourceAttribs );
		}

		// Original image
		$p .= Html::element( 'img', $attribs );

		$p .= Html::closeElement( 'picture' );

		$sourceLink = Html::rawElement(
			'a',
			[
				'href' => $this->file->getUrl(),
				'class' => 'mw-file-source',
				// FIXME: Need i18n
				'title' => 'View source image'
			],
			'<!-- Image link for Crawlers -->'
		);

		return $this->linkWrap( $linkAttribs, $p ) . $sourceLink;
	}
}