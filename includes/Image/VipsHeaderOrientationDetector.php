<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Image;

use MediaWiki\Shell\Shell;

/**
 * Orientation detection via libvips's vipsheader — the same engine vipsthumbnail uses to
 * auto-rotate — so the reported orientation is exactly the one that will be applied.
 *
 * Mirrors {@see VipsHeaderAlphaDetector}: the vipsthumbnail binary is injected so the sibling
 * vipsheader can be located next to it.
 */
class VipsHeaderOrientationDetector implements OrientationDetector {

	public function __construct(
		private readonly string $vipsthumbnailCommand,
	) {
	}

	/**
	 * @param string $srcPath Source file path.
	 * @return int The EXIF orientation (1-8), or 1 (no rotation) on any failure — the safe
	 *   default that leaves reported dimensions untouched.
	 */
	public function getOrientation( string $srcPath ): int {
		if ( Shell::isDisabled() ) {
			return 1;
		}
		// vipsheader ships alongside vipsthumbnail, so look for it in the same directory. This
		// handles a renamed or wrapped vipsthumbnail (where substituting the binary name in the
		// path would not), as long as vipsheader is its sibling.
		$vipsheader = dirname( $this->vipsthumbnailCommand ) . '/vipsheader';
		if ( !is_executable( $vipsheader ) ) {
			wfDebug( "[Extension:Thumbro] vipsheader not found next to {$this->vipsthumbnailCommand}; "
				. 'treating the image as unoriented.' );
			return 1;
		}
		// Any image that was never rotated — every PNG, most of everything else — has no
		// orientation field, so vipsheader writes to stderr and exits non-zero. That is this
		// probe's normal path, but MediaWiki turns stderr logging on for every shell command
		// globally, which would report each of those as an ERROR on the exec channel. Opt out
		// and keep the detail on the debug channel instead (#104).
		$result = Shell::command( [ $vipsheader, '-f', 'orientation', $srcPath ] )
			->logStderr( false )
			->execute();
		// Logged whenever stderr is non-empty rather than only on a non-zero exit, because
		// that is exactly the condition the suppressed ERROR was keyed on: a warning printed
		// by a probe that still succeeded would otherwise now go unrecorded entirely.
		$stderr = trim( $result->getStderr() );
		if ( $stderr !== '' ) {
			wfDebug( "[Extension:Thumbro] vipsheader -f orientation exited {$result->getExitCode()}"
				. " for $srcPath: $stderr" );
		}
		// A file with no orientation field exits non-zero; that simply means "no rotation".
		if ( $result->getExitCode() !== 0 ) {
			return 1;
		}
		$orientation = (int)trim( $result->getStdout() );
		return ( $orientation >= 1 && $orientation <= 8 ) ? $orientation : 1;
	}
}
