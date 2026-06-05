<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

use RuntimeException;

class Reference {
	/** Lossless downscale of a static source to exactly $w x $h (PNG). */
	public static function forStatic( string $src, int $w, int $h, string $destDir ): string {
		$dst = $destDir . '/ref.png';
		$vips = ToolLocator::require( 'vips', 'libvips-tools' );
		// `--size force` makes the output exactly $w x $h (ignores aspect).
		$tmp = $destDir . '/ref_tmp.png';
		$proc = Subprocess::run( [
			$vips, 'thumbnail', $src, $tmp, (string)$w, '--height', (string)$h, '--size', 'force',
		] );
		if ( !$proc->ok() || !is_file( $tmp ) ) {
			throw new RuntimeException( 'vips reference failed: ' . $proc->stderr );
		}
		if ( !rename( $tmp, $dst ) ) {
			throw new RuntimeException( 'Failed to move reference temp file to ' . $dst );
		}
		return $dst;
	}

	/**
	 * Coalesce the animated source, resize every frame to exactly $w x $h, return one
	 * PNG path per frame. Frame count is asserted by the caller against the candidate.
	 *
	 * @return string[] frame PNG paths, index-ordered
	 */
	public static function forFrames( string $src, int $w, int $h, int $frameCount, string $destDir ): array {
		$convert = ToolLocator::require( 'convert', 'imagemagick' );
		$pattern = $destDir . '/refframe_%03d.png';
		$proc = Subprocess::run( [
			$convert, $src, '-coalesce', '-resize', $w . 'x' . $h . '!', $pattern,
		] );
		if ( !$proc->ok() ) {
			throw new RuntimeException( 'convert reference frames failed: ' . $proc->stderr );
		}
		$frames = glob( $destDir . '/refframe_*.png' ) ?: [];
		sort( $frames );
		if ( $frames === [] ) {
			throw new RuntimeException( 'convert produced no reference frames for ' . $src );
		}
		return $frames;
	}
}
