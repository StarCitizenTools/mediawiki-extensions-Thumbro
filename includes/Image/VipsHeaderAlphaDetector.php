<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Image;

use MediaWiki\Shell\Shell;

/**
 * Alpha detection via libvips's vipsheader (which properly parses GIF transparency).
 *
 * Replaces the static Transparency helper; the vipsthumbnail binary is injected so the
 * sibling vipsheader can be located next to it.
 */
class VipsHeaderAlphaDetector implements AlphaDetector {

	public function __construct(
		private readonly string $vipsthumbnailCommand,
	) {
	}

	/**
	 * @param string $srcPath Source file path.
	 * @return bool True if the image reports an alpha band (>= 4 bands). On any failure
	 *   (vipsheader missing / error) returns false — the safe default that keeps the file
	 *   on libvips.
	 */
	public function hasAlpha( string $srcPath ): bool {
		// vipsheader ships alongside vipsthumbnail, so look for it in the same directory.
		// This handles a renamed or wrapped vipsthumbnail (where substituting the binary
		// name in the path would not), as long as vipsheader is its sibling.
		$vipsheader = dirname( $this->vipsthumbnailCommand ) . '/vipsheader';
		if ( !is_executable( $vipsheader ) ) {
			wfDebug( "[Extension:Thumbro] vipsheader not found next to {$this->vipsthumbnailCommand}; "
				. 'treating the image as opaque.' );
			return false;
		}
		$result = Shell::command( [ $vipsheader, '-f', 'bands', $srcPath ] )->execute();
		if ( $result->getExitCode() !== 0 ) {
			return false;
		}
		return (int)trim( $result->getStdout() ) >= 4;
	}
}
