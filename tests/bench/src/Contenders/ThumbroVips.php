<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench\Contenders;

use MediaWiki\Extension\Thumbro\Bench\Contender;
use MediaWiki\Extension\Thumbro\Bench\Result;
use MediaWiki\Extension\Thumbro\Bench\Subprocess;
use MediaWiki\Extension\Thumbro\Bench\ToolLocator;

/**
 * Reproduces Thumbro's vipsthumbnail path, and the two-step vips-resize→cwebp path for MIMEs
 * whose static encoder resolves to `cwebp` (currently image/webp).
 *
 * The load/save option suffixes are derived from extension.json (config.ThumbroOptions.value)
 * rather than hand-copied, so the contender cannot silently drift from production — earlier
 * copies diverged twice (a stale jpeg Q, and a pngsave `filter` that crashes webpsave).
 * {@see self::optionsFor()} mirrors the production resolution rule (TransformOptionsResolver +
 * EncodePipeline): load/resize options come from the input-MIME block's `resize.options`; the
 * webpsave options come from that block's `vips-webp` encode entry, falling back to the image/webp
 * block's `vips-webp` entry (so jpeg/webp share the webp save options while image/png carries its
 * own near-lossless entry).
 *
 * {@see self::staticEncoderFor()} mirrors EncoderRouter::choose with animated=false: it returns
 * the first encode-list entry whose `when` guard does not require `animated:true` (a missing `when`
 * is the catch-all). For image/webp this is now `cwebp`; for jpeg/png it is `vips-webp`.
 *
 * GIF is handled by {@see ThumbroGif} instead — its options are a runtime encode-list routing
 * decision (gif2webp, or vips-webp with forced n), not a single-entry config lookup.
 */
class ThumbroVips implements Contender {
	/** MIMEs whose vips suffixes are derived from extension.json. */
	private const DERIVED = [ 'image/jpeg', 'image/png', 'image/webp' ];

	public function name(): string {
		return 'thumbro-vips';
	}

	public function applies( string $mime ): bool {
		return in_array( $mime, self::DERIVED, true );
	}

	public function isAvailable(): bool {
		return ToolLocator::path( 'vipsthumbnail' ) !== null;
	}

	public function run( string $srcPath, string $mime, int $targetWidth, string $destDir ): Result {
		$vips = ToolLocator::path( 'vipsthumbnail' );
		if ( $vips === null ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'vipsthumbnail not found' );
		}
		$dst = $destDir . '/thumbro_' . $targetWidth . '.webp';
		$options = self::thumbroOptions();
		$staticEncoder = self::staticEncoderFor( $mime, $options );
		$cwebpBin = ToolLocator::path( 'cwebp' );

		// Two-step path: vips resize to PNG intermediate, then cwebp encode.
		// Only for static (non-animated) sources — cwebp cannot animate, so animated WebP is
		// always handled by the fused vips path below regardless of the static encoder.
		// Falls back to vips path when cwebp binary is absent (mirrors the production
		// vips-webp catch-all that degrades gracefully when cwebp is unavailable).
		if ( $staticEncoder === 'cwebp' && $cwebpBin !== null && !self::isAnimated( $srcPath ) ) {
			return $this->runCwebp( $vips, $cwebpBin, $srcPath, $mime, $targetWidth, $destDir, $dst, $options );
		}

		// Fused vips path (vips-webp encoder): single vipsthumbnail call with webpsave options.
		[ $inSuffix, $outSuffix ] = self::optionsFor( $mime, $options );
		// Animated WebP: production forces n=-1 (the pipeline prepends it for an animation-capable
		// encoder on an animated, under-threshold source) so the thumbnail keeps every frame
		// instead of flattening to the first. Mirror it so the bench measures what production
		// produces. GIF is ThumbroGif's job; jpeg/png are single-frame.
		if ( $mime === 'image/webp' && $inSuffix === '' && self::isAnimated( $srcPath ) ) {
			$inSuffix = '[n=-1]';
		}
		$in = $srcPath . $inSuffix;
		$out = $dst . $outSuffix;
		// Height bound large so width governs (matches MediaWiki width-based thumbs).
		$cmd = [ $vips, $in, '--size', $targetWidth . 'x100000', '-o', $out ];

		$proc = Subprocess::run( $cmd );
		if ( !$proc->ok() || !is_file( $dst ) ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'vips failed: ' . $proc->stderr );
		}
		return new Result(
			$this->name(), $srcPath, $targetWidth, $dst,
			filesize( $dst ), $proc->wallMs, $proc->peakRssKb, true
		);
	}

	/**
	 * Two-step encode: vipsthumbnail to a PNG intermediate, then cwebp to the final WebP.
	 *
	 * @param string $vips Path to vipsthumbnail binary
	 * @param string $cwebpBin Path to cwebp binary
	 * @param string $srcPath Source image path
	 * @param string $mime Input MIME type
	 * @param int $targetWidth Target thumbnail width
	 * @param string $destDir Destination directory
	 * @param string $dst Final WebP destination path
	 * @param array<string,array<string,mixed>> $thumbroOptions ThumbroOptions config
	 */
	private function runCwebp(
		string $vips,
		string $cwebpBin,
		string $srcPath,
		string $mime,
		int $targetWidth,
		string $destDir,
		string $dst,
		array $thumbroOptions
	): Result {
		$tmp = $destDir . '/thumbro_' . $targetWidth . '_tmp.png';
		[ $inSuffix, ] = self::optionsFor( $mime, $thumbroOptions );
		$in = $srcPath . $inSuffix;
		// Step 1: vips resize to PNG intermediate (no webpsave suffix).
		$resizeCmd = [ $vips, $in, '--size', $targetWidth . 'x100000', '-o', $tmp ];
		$resizeProc = Subprocess::run( $resizeCmd );
		if ( !$resizeProc->ok() || !is_file( $tmp ) ) {
			return Result::unavailable(
				$this->name(), $srcPath, $targetWidth,
				'vips resize step failed: ' . $resizeProc->stderr
			);
		}

		// Step 2: cwebp encode the PNG intermediate to the destination WebP.
		$cwebpOptions = self::cwebpOptionsFor( $mime, $thumbroOptions );
		$cwebpCmd = [ $cwebpBin ];
		foreach ( $cwebpOptions as $key => $value ) {
			$cwebpCmd[] = '-' . $key;
			if ( $value !== '' ) {
				$cwebpCmd[] = $value;
			}
		}
		$cwebpCmd[] = $tmp;
		$cwebpCmd[] = '-o';
		$cwebpCmd[] = $dst;
		$encodeProc = Subprocess::run( $cwebpCmd );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $tmp );
		if ( !$encodeProc->ok() || !is_file( $dst ) ) {
			return Result::unavailable(
				$this->name(), $srcPath, $targetWidth,
				'cwebp encode step failed: ' . $encodeProc->stderr
			);
		}
		// Aggregate wall time and take the larger of the two peak RSS readings.
		$wallMs = $resizeProc->wallMs + $encodeProc->wallMs;
		$peakRss = max( $resizeProc->peakRssKb ?? 0, $encodeProc->peakRssKb ?? 0 );
		return new Result(
			$this->name(), $srcPath, $targetWidth, $dst,
			filesize( $dst ), $wallMs, $peakRss === 0 ? null : $peakRss, true
		);
	}

	/**
	 * The vipsthumbnail "[..]" load/save suffixes Thumbro runs for $mime, derived from the given
	 * ThumbroOptions config. Mirrors the production resize→encode path: load options from the
	 * input-MIME block's `resize.options`; webpsave options from that block's `vips-webp` encode
	 * entry, else the image/webp block's `vips-webp` entry.
	 *
	 * For MIMEs routed to cwebp (e.g. static image/webp), this still returns the input suffix
	 * (from resize.options) and an empty output suffix — the caller uses the input suffix for the
	 * vips resize step and ignores the output suffix (cwebp handles encoding separately).
	 *
	 * @param string $mime input MIME type
	 * @param array<string,array<string,mixed>> $thumbroOptions config.ThumbroOptions.value
	 * @return array{0:string,1:string} [ inputSuffix, outputSuffix ]
	 */
	public static function optionsFor( string $mime, array $thumbroOptions ): array {
		$input = $thumbroOptions[$mime]['resize']['options'] ?? [];
		$output = self::vipsWebpOptions( $thumbroOptions[$mime] ?? [] )
			?? self::vipsWebpOptions( $thumbroOptions['image/webp'] ?? [] )
			?? [];
		return [ self::makeOptions( $input ), self::makeOptions( $output ) ];
	}

	/**
	 * The encoder name for a static (non-animated) source of the given MIME. Mirrors
	 * EncoderRouter::choose with animated=false: returns the first encode-list entry whose `when`
	 * guard does not require `animated:true` (a missing `when` is the catch-all).
	 *
	 * @param string $mime input MIME type
	 * @param array<string,array<string,mixed>> $thumbroOptions config.ThumbroOptions.value
	 */
	public static function staticEncoderFor( string $mime, array $thumbroOptions ): string {
		foreach ( $thumbroOptions[$mime]['encode'] ?? [] as $entry ) {
			$when = $entry['when'] ?? [];
			// Skip entries that require animated=true (they only apply to animations).
			if ( ( $when['animated'] ?? null ) === true ) {
				continue;
			}
			return $entry['encoder'] ?? 'vips-webp';
		}
		return 'vips-webp';
	}

	/**
	 * The `options` array from the first `cwebp` encode entry in the given MIME block.
	 *
	 * @param string $mime input MIME type
	 * @param array<string,array<string,mixed>> $thumbroOptions config.ThumbroOptions.value
	 * @return array<string,string>
	 */
	public static function cwebpOptionsFor( string $mime, array $thumbroOptions ): array {
		foreach ( $thumbroOptions[$mime]['encode'] ?? [] as $entry ) {
			if ( ( $entry['encoder'] ?? '' ) === 'cwebp' ) {
				return $entry['options'] ?? [];
			}
		}
		return [];
	}

	/**
	 * The `options` of the first `vips-webp` entry in a MIME block's encode list, or null if the
	 * block has no such entry.
	 *
	 * @param array<string,mixed> $block a single ThumbroOptions MIME block
	 * @return array<string,mixed>|null
	 */
	private static function vipsWebpOptions( array $block ): ?array {
		foreach ( $block['encode'] ?? [] as $entry ) {
			if ( ( $entry['encoder'] ?? '' ) === 'vips-webp' ) {
				return $entry['options'] ?? [];
			}
		}
		return null;
	}

	/**
	 * Format an options array as a "[key=value,key=value]" suffix, empty array -> "". Matches
	 * VipsOptionSuffix::make (insertion order preserved; vips treats save options as
	 * order-independent keyword args).
	 *
	 * @param array<string,mixed> $args
	 */
	private static function makeOptions( array $args ): string {
		if ( $args === [] ) {
			return '';
		}
		$parts = [];
		foreach ( $args as $key => $value ) {
			$parts[] = "$key=$value";
		}
		return '[' . implode( ',', $parts ) . ']';
	}

	/** True if the source reports more than one page/frame (via vipsheader's n-pages). */
	private static function isAnimated( string $path ): bool {
		$vipsheader = ToolLocator::path( 'vipsheader' );
		if ( $vipsheader === null ) {
			return false;
		}
		$proc = Subprocess::run( [ $vipsheader, '-f', 'n-pages', $path ] );
		return $proc->ok() && (int)trim( $proc->stdout ) > 1;
	}

	/**
	 * Reads config.ThumbroOptions.value from extension.json — the production source of truth.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function thumbroOptions(): array {
		static $cache = null;
		if ( $cache === null ) {
			$path = __DIR__ . '/../../../../extension.json';
			$json = json_decode( (string)file_get_contents( $path ), true );
			$cache = $json['config']['ThumbroOptions']['value'] ?? [];
		}
		return $cache;
	}
}
