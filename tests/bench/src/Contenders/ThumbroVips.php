<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench\Contenders;

use MediaWiki\Extension\Thumbro\Bench\Contender;
use MediaWiki\Extension\Thumbro\Bench\Result;
use MediaWiki\Extension\Thumbro\Bench\Subprocess;
use MediaWiki\Extension\Thumbro\Bench\ToolLocator;

/**
 * Reproduces Thumbro's current vipsthumbnail path. Keep these option strings in
 * sync with extension.json -> config.ThumbroOptions.value[<mime>]. (Manual sync;
 * automated lockstep is future work.)
 */
class ThumbroVips implements Contender {
	/** input [..] options appended to the source path, per MIME. */
	private const INPUT = [
		'image/gif' => '[n=-1]',
	];
	/** output [..] options appended to the .webp dest, per MIME. */
	private const OUTPUT = [
		'image/jpeg' => '[Q=80,strip=true]',
		'image/png'  => '[strip=true,filter=VIPS_FOREIGN_PNG_FILTER_ALL]',
		'image/webp' => '[Q=90,smart_subsample=true,strip=true]',
		'image/gif'  => '',
	];

	public function name(): string {
		return 'thumbro-vips';
	}

	public function applies( string $mime ): bool {
		return isset( self::OUTPUT[$mime] );
	}

	public function isAvailable(): bool {
		return ToolLocator::path( 'vipsthumbnail' ) !== null;
	}

	public function run( string $srcPath, string $mime, int $targetWidth, string $destDir ): Result {
		$bin = ToolLocator::path( 'vipsthumbnail' );
		if ( $bin === null ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'vipsthumbnail not found' );
		}
		$dst = $destDir . '/thumbro_' . $targetWidth . '.webp';
		$in = $srcPath . ( self::INPUT[$mime] ?? '' );
		$out = $dst . ( self::OUTPUT[$mime] ?? '' );
		// Height bound large so width governs (matches MediaWiki width-based thumbs).
		$cmd = [ $bin, $in, '--size', $targetWidth . 'x100000', '-o', $out ];

		$proc = Subprocess::run( $cmd );
		if ( !$proc->ok() || !is_file( $dst ) ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'vips failed: ' . $proc->stderr );
		}
		return new Result(
			$this->name(), $srcPath, $targetWidth, $dst,
			filesize( $dst ), $proc->wallMs, $proc->peakRssKb, true
		);
	}
}
