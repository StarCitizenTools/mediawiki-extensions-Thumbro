<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro;

use MediaWiki\Shell\Shell;

/**
 * Detects whether a source image has an alpha channel, using libvips's vipsheader
 * (which properly parses GIF transparency). Used to route only transparent animated
 * GIFs to the libwebp backend (libvips's animated-WebP encoder blows up on alpha).
 */
class Transparency {
	/**
	 * @param string $srcPath Source file path.
	 * @param string $vipsthumbnailCommand Configured vipsthumbnail binary path
	 *   (vipsheader is derived from it — they ship together).
	 * @return bool True if the image reports an alpha band (>= 4 bands). On any
	 *   failure (vipsheader missing / error) returns false — the safe default that
	 *   keeps the file on libvips.
	 */
	public static function hasAlpha( string $srcPath, string $vipsthumbnailCommand ): bool {
		$vipsheader = str_replace( 'vipsthumbnail', 'vipsheader', $vipsthumbnailCommand );
		if ( $vipsheader === $vipsthumbnailCommand || !is_executable( $vipsheader ) ) {
			return false;
		}
		$result = Shell::command( [ $vipsheader, '-f', 'bands', $srcPath ] )->execute();
		if ( $result->getExitCode() !== 0 ) {
			return false;
		}
		return (int)trim( $result->getStdout() ) >= 4;
	}
}
