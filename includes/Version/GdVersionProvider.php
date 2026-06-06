<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Version;

/**
 * Reports the GD version via the gd PHP extension, when loaded.
 */
class GdVersionProvider implements SoftwareVersionProvider {

	public function getLabel(): string {
		return '[https://www.php.net/manual/en/book.image.php GD]';
	}

	public function getVersion(): ?string {
		if ( !extension_loaded( 'gd' ) ) {
			return null;
		}
		$gdVersion = gd_info()['GD Version'];
		if ( !$gdVersion ) {
			return null;
		}
		return $gdVersion;
	}
}
