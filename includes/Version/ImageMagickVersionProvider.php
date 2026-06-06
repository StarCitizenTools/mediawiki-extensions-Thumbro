<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Version;

use Imagick;

/**
 * Reports the ImageMagick version via the imagick PHP extension, when loaded.
 */
class ImageMagickVersionProvider implements SoftwareVersionProvider {

	public function getLabel(): string {
		return '[https://imagemagick.org ImageMagick]';
	}

	public function getVersion(): ?string {
		if ( !extension_loaded( 'imagick' ) ) {
			return null;
		}
		$imVersion = Imagick::getVersion()['versionString'];
		if ( !$imVersion ) {
			return null;
		}
		$parts = explode( ' ', $imVersion );
		if ( isset( $parts[1] ) || preg_match( '/^\d+\.\d+\.\d+$/', $parts[1] ) ) {
			return $parts[1];
		}
		return null;
	}
}
