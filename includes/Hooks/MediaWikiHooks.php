<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Hooks;

use File;
use MediaTransformOutput;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Backend\EncodePipeline;
use MediaWiki\Extension\Thumbro\Linker\CrawlerAnchorStripper;
use MediaWiki\Extension\Thumbro\MediaHandlers;
use MediaWiki\Extension\Thumbro\Options\TransformOptionsResolver;
use MediaWiki\Extension\Thumbro\Version\SoftwareVersionProvider;
use MediaWiki\Hook\BitmapHandlerCheckImageAreaHook;
use MediaWiki\Hook\BitmapHandlerTransformHook;
use MediaWiki\Hook\LinkerMakeExternalLinkHook;
use MediaWiki\Hook\SoftwareInfoHook;
use MediaWiki\MainConfigNames;
use MediaWiki\Shell\Shell;
use TransformationalImageHandler;

class MediaWikiHooks implements
	BitmapHandlerTransformHook,
	BitmapHandlerCheckImageAreaHook,
	LinkerMakeExternalLinkHook,
	SoftwareInfoHook
{
	private readonly Config $config;

	/**
	 * @param ConfigFactory $configFactory
	 * @param TransformOptionsResolver $optionsResolver
	 * @param EncodePipeline $encodePipeline
	 * @param SoftwareVersionProvider[] $versionProviders
	 */
	public function __construct(
		ConfigFactory $configFactory,
		private readonly TransformOptionsResolver $optionsResolver,
		private readonly EncodePipeline $encodePipeline,
		private readonly array $versionProviders,
	) {
		$this->config = $configFactory->makeConfig( 'thumbro' );
	}

	public static function initThumbro(): void {
		global $wgThumbroEnabled, $wgMediaHandlers;
		// Thumbro is not enabled, do not add any MediaHandlers
		if ( $wgThumbroEnabled !== true ) {
			return;
		}

		// Attach WebP handlers
		foreach ( MediaHandlers::HANDLERS as $mimeType => $class ) {
			$wgMediaHandlers[$mimeType] = $class;
		}
	}

	/**
	 * Hook to BitmapHandlerTransform. Transforms using the conditions
	 * Set in $wgThumbroOptions
	 *
	 * @param TransformationalImageHandler $handler
	 * @param File $file
	 * @param array &$params
	 * @param MediaTransformOutput|null &$mto
	 * @return bool
	 */
	public function onBitmapHandlerTransform( $handler, $file, &$params, &$mto ) {
		if ( Shell::isDisabled() ) {
			return true;
		}

		// Abort all transformations when Thumbro is not enabled
		if ( $this->config->get( 'ThumbroEnabled' ) !== true ) {
			return true;
		}

		$options = $this->optionsResolver->resolve( $handler, $file );
		if ( $options === null ) {
			return true;
		}

		return $this->encodePipeline->dispatch(
			new BackendRequest( $handler, $file, $params, $options ),
			$mto
		);
	}

	/**
	 * Hook to BitmapHandlerCheckImageArea. Will set $result to true if the
	 * file will by handled by Thumbro.
	 *
	 * @param File $file
	 * @param array &$params
	 * @param mixed &$result
	 * @return bool
	 */
	public function onBitmapHandlerCheckImageArea( $file, &$params, &$result ) {
		$maxImageArea = $this->config->get( MainConfigNames::MaxImageArea );

		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType ImageHandler vs. MediaHandler */
		if ( $this->optionsResolver->resolve( $file->getHandler(), $file ) !== null ) {
			wfDebug( "[Extension:Thumbro] Overriding wgMaxImageArea: $maxImageArea" );
			$result = true;
			return false;
		}
		return true;
	}

	/**
	 * Strip Thumbro's hidden crawler anchor when a thumbnail is wrapped in an external
	 * link. A nested <a> inside the external link's <a> is invalid HTML and breaks the
	 * wrap (trailing text falls outside the link). The crawler anchor is preserved
	 * everywhere else by ThumbroThumbnailImage::toHtml(); it is removed only here, where
	 * it cannot legally live. By parser ordering (handleInternalLinks before
	 * handleExternalLinks, both before Remex tidy) the image HTML is already materialized
	 * in $text at this point, so the wrap comes out well-formed.
	 *
	 * @param string &$url
	 * @param string &$text Link inner HTML; may contain a wrapped thumbnail
	 * @param string &$link
	 * @param array &$attribs
	 * @param string $linktype
	 * @return bool
	 */
	public function onLinkerMakeExternalLink( &$url, &$text, &$link, &$attribs, $linktype ) {
		if ( is_string( $text ) ) {
			$text = CrawlerAnchorStripper::strip( $text );
		}
		return true;
	}

	/**
	 * Hook called to include image tool version info on Special:Version.
	 *
	 * @param array &$software Array of wikitext and version numbers
	 */
	public function onSoftwareInfo( &$software ) {
		if ( Shell::isDisabled() ) {
			return;
		}

		foreach ( $this->versionProviders as $provider ) {
			$version = $provider->getVersion();
			if ( $version !== null ) {
				$software[ $provider->getLabel() ] = $version;
			}
		}
	}
}
