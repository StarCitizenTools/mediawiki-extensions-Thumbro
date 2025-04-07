<?php

namespace MediaWiki\Extension\Thumbro\Hooks;

use File;
use MediaTransformOutput;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Extension\Thumbro\Libraries\Libvips;
use MediaWiki\Extension\Thumbro\MediaHandlers;
use MediaWiki\Extension\Thumbro\Utils;
use MediaWiki\Hook\BitmapHandlerCheckImageAreaHook;
use MediaWiki\Hook\BitmapHandlerTransformHook;
use MediaWiki\Hook\SoftwareInfoHook;
use MediaWiki\MainConfigNames;
use MediaWiki\Shell\Shell;
use TransformationalImageHandler;

class MediaWikiHooks implements
	BitmapHandlerTransformHook,
	BitmapHandlerCheckImageAreaHook,
	SoftwareInfoHook
{
	private Config $config;

	public function __construct( ConfigFactory $configFactory ) {
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

		$config = $this->config;

		// Abort all transformations when Thumbro is not enabled
		if ( $config->get( 'ThumbroEnabled' ) !== true ) {
			return true;
		}

		$options = Utils::getOptions( $handler, $file, $config );
		if ( $options === null ) {
			return true;
		}

		/** @todo Add logic to use other libaries */
		return Libvips::doTransform( $handler, $file, $params, $options, $mto );
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
		$config = $this->config;
		$maxImageArea = $config->get( MainConfigNames::MaxImageArea );

		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType ImageHandler vs. MediaHandler */
		if ( Utils::getOptions( $file->getHandler(), $file, $config ) !== false ) {
			wfDebug( "[Extension:Thumbro] Overriding wgMaxImageArea: $maxImageArea" );
			$result = true;
			return false;
		}
		return true;
	}

	/**
	 * Hook called to include Vips version info on Special:Version
	 * TODO: We need to drop CLI and use php-vips directly
	 *
	 * @param array &$software Array of wikitext and version numbers
	 */
	public function onSoftwareInfo( &$software ) {
		if ( Shell::isDisabled() ) {
			return;
		}

		$vipsVersion = Libvips::getSoftwareVersion();
		if ( $vipsVersion ) {
			$software[ '[https://www.libvips.org libvips]' ] = $vipsVersion;
		}

		// TODO: Move this to a class for ImageMagick
		if ( extension_loaded( 'imagick' ) ) {
			$imVersion = \Imagick::getVersion()['versionString'];
			if ( $imVersion ) {
				$parts = explode( ' ', $imVersion );
				if ( isset( $parts[1] ) || preg_match( '/^\d+\.\d+\.\d+$/', $parts[1] ) ) {
					$software[ '[https://imagemagick.org ImageMagick]' ] = $parts[1];
				}
			}
		}

		// TODO: Move this to a class for GD
		if ( extension_loaded( 'gd' ) ) {
			$gdVersion = gd_info()['GD Version'];
			if ( $gdVersion ) {
				$software[ '[https://www.php.net/manual/en/book.image.php GD]' ] = gd_info()['GD Version'];
			}
		}
	}
}
