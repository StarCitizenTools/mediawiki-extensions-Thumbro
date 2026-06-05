<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench\Contenders;

use MediaWiki\Extension\Thumbro\Bench\Contender;
use MediaWiki\Extension\Thumbro\Bench\Result;
use MediaWiki\Extension\Thumbro\Bench\Subprocess;
use MediaWiki\Extension\Thumbro\Bench\ToolLocator;

class GdBaseline implements Contender {
	public function name(): string {
		return 'gd';
	}

	public function applies( string $mime ): bool {
		// GD has no real animated-GIF path; only compare on static raster.
		return in_array( $mime, [ 'image/jpeg', 'image/png' ], true );
	}

	public function isAvailable(): bool {
		return extension_loaded( 'gd' ) && ToolLocator::path( 'php' ) !== null;
	}

	public function run( string $srcPath, string $mime, int $targetWidth, string $destDir ): Result {
		if ( !$this->isAvailable() ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'php-gd unavailable' );
		}
		$ext = $mime === 'image/jpeg' ? 'jpg' : 'png';
		$dst = $destDir . '/gd_' . $targetWidth . '.' . $ext;
		$script = dirname( __DIR__, 2 ) . '/bin/gd_thumb.php';
		$proc = Subprocess::run( [ ToolLocator::path( 'php' ), $script, $srcPath, (string)$targetWidth, $dst ] );
		if ( !$proc->ok() || !is_file( $dst ) ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'gd failed: ' . $proc->stderr );
		}
		return new Result(
			$this->name(), $srcPath, $targetWidth, $dst,
			filesize( $dst ), $proc->wallMs, $proc->peakRssKb, true
		);
	}
}
