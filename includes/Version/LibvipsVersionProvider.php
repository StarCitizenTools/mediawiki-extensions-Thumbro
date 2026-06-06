<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Version;

use MediaWiki\Shell\Shell;

/**
 * Reports the libvips version via `vips -v`.
 */
class LibvipsVersionProvider implements SoftwareVersionProvider {

	public function getLabel(): string {
		return '[https://www.libvips.org libvips]';
	}

	public function getVersion(): ?string {
		$result = Shell::command( [ 'vips', '-v' ] )
			->includeStderr()
			->execute();

		if ( $result->getExitCode() != 0 ) {
			// Vips command is not avaliable, exit
			return null;
		}
		// Explode the string by '-'
		// stdout returns something like vips-8.7.4-Sat Nov 21 16:50:57 UTC 2020
		$parts = explode( '-', $result->getStdout() );
		// Check if the first part exists and is a valid version number
		if ( !isset( $parts[1] ) || !preg_match( '/^\d+\.\d+\.\d+$/', $parts[1] ) ) {
			return null;
		}

		return $parts[1];
	}
}
