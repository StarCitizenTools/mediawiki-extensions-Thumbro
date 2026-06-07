<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench\Contenders;

use MediaWiki\Extension\Thumbro\Bench\Contender;
use MediaWiki\Extension\Thumbro\Bench\Result;
use MediaWiki\Extension\Thumbro\Bench\Subprocess;
use MediaWiki\Extension\Thumbro\Bench\ToolLocator;
use MediaWiki\Extension\Thumbro\Bench\WebpProbe;

class ImageMagickBaseline implements Contender {
	public function name(): string {
		return 'im';
	}

	public function applies( string $mime ): bool {
		return in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], true );
	}

	public function isAvailable(): bool {
		return ToolLocator::path( 'convert' ) !== null;
	}

	public function run( string $srcPath, string $mime, int $targetWidth, string $destDir ): Result {
		$convert = ToolLocator::path( 'convert' );
		if ( $convert === null ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'convert not found' );
		}
		$ext = match ( $mime ) {
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			default      => 'gif',
		};
		$dst = $destDir . '/im_' . $targetWidth . '.' . $ext;

		// Animated sources (GIF, or animated WebP) use coalesce + per-frame optimize, matching
		// what default MediaWiki + ImageMagick emits for an animation. Static sources are a plain
		// width resize. WebP output takes no explicit -quality (IM's default), faithfully
		// representing an unconfigured install; only JPEG pins a quality.
		$animated = $mime === 'image/gif'
			|| ( $mime === 'image/webp' && WebpProbe::isAnimated( $srcPath ) );

		$cmd = [ $convert ];
		if ( $animated ) {
			$cmd = array_merge( $cmd, [ $srcPath, '-coalesce', '-resize', $targetWidth . 'x', '-layers', 'optimize' ] );
		} else {
			$cmd = array_merge( $cmd, [ $srcPath, '-resize', $targetWidth . 'x' ] );
			if ( $mime === 'image/jpeg' ) {
				$cmd = array_merge( $cmd, [ '-quality', '80' ] );
			}
		}
		$cmd[] = $dst;

		$proc = Subprocess::run( $cmd );
		if ( !$proc->ok() || !is_file( $dst ) ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'convert failed: ' . $proc->stderr );
		}
		return new Result(
			$this->name(), $srcPath, $targetWidth, $dst,
			filesize( $dst ), $proc->wallMs, $proc->peakRssKb, true
		);
	}
}
