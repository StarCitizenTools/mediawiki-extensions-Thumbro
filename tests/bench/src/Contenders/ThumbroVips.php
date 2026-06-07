<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench\Contenders;

use MediaWiki\Extension\Thumbro\Bench\Contender;
use MediaWiki\Extension\Thumbro\Bench\Result;
use MediaWiki\Extension\Thumbro\Bench\Subprocess;
use MediaWiki\Extension\Thumbro\Bench\ToolLocator;

/**
 * Reproduces Thumbro's vipsthumbnail path.
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
		$bin = ToolLocator::path( 'vipsthumbnail' );
		if ( $bin === null ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'vipsthumbnail not found' );
		}
		$dst = $destDir . '/thumbro_' . $targetWidth . '.webp';
		[ $inSuffix, $outSuffix ] = self::optionsFor( $mime, self::thumbroOptions() );
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

	/**
	 * The vipsthumbnail "[..]" load/save suffixes Thumbro runs for $mime, derived from the given
	 * ThumbroOptions config. Mirrors the production resize→encode path: load options from the
	 * input-MIME block's `resize.options`; webpsave options from that block's `vips-webp` encode
	 * entry, else the image/webp block's `vips-webp` entry.
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
