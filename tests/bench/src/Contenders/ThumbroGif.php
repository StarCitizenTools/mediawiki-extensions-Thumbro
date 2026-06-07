<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench\Contenders;

use MediaWiki\Extension\Thumbro\Bench\Contender;
use MediaWiki\Extension\Thumbro\Bench\ImageDims;
use MediaWiki\Extension\Thumbro\Bench\Result;
use MediaWiki\Extension\Thumbro\Bench\Subprocess;
use MediaWiki\Extension\Thumbro\Bench\ToolLocator;

/**
 * Reproduces Thumbro's GIF path faithfully — the part the libvips-only contender could not.
 *
 * Production routes GIFs through the resize→encode pipeline, which makes a runtime decision (see
 * {@see self::chooseStrategy()}, mirrored from the production routing and locked by a truth-table
 * test): transparent animated GIFs under the area threshold are encoded with gif2webp (the
 * two-step vipsthumbnail -> temp .gif -> gif2webp pipeline); opaque or over-threshold animations
 * encode to animated WebP via vips-webp (n=-1); static GIFs take the first frame (no n key — vips
 * defaults to the first frame). The routing inputs — animation, frame count, transparency, area —
 * are probed at runtime with vipsheader, exactly as production does (its AlphaDetector reads
 * `vipsheader -f bands`), so the contender never goes stale against a hand-kept manifest.
 *
 * Encoder settings come from extension.json's per-MIME encode lists: the webpsave options (the
 * image/webp block's vips-webp entry) for the vips delegation, and the gif2webp flags (the gif
 * block's gif2webp encode entry) for the encoder.
 */
class ThumbroGif implements Contender {

	public function name(): string {
		return 'thumbro-gif';
	}

	public function applies( string $mime ): bool {
		return $mime === 'image/gif';
	}

	public function isAvailable(): bool {
		// vipsthumbnail drives every strategy (gif2webp is optional: without it, transparent
		// animations fall back to the libvips delegation, matching production).
		return ToolLocator::path( 'vipsthumbnail' ) !== null;
	}

	/**
	 * The runtime routing decision, mirroring the production encode-list routing (EncoderRouter
	 * over the gif encode list). A unit test asserts the bench rule and the production router agree
	 * across the whole truth table (the standalone harness can't load the production classes, so
	 * the rule is mirrored, not shared).
	 *
	 * @return string 'libwebp' | 'vips-animated' | 'vips-static'
	 */
	public static function chooseStrategy(
		bool $animated, bool $underThreshold, bool $hasTransparency, bool $libwebpAvailable
	): string {
		if ( !$animated || !$underThreshold ) {
			return 'vips-static';
		}
		if ( $hasTransparency && $libwebpAvailable ) {
			return 'libwebp';
		}
		return 'vips-animated';
	}

	public function run( string $srcPath, string $mime, int $targetWidth, string $destDir ): Result {
		$vips = ToolLocator::path( 'vipsthumbnail' );
		if ( $vips === null ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'vipsthumbnail not found' );
		}
		$cfg = self::config();

		// Probe the routing inputs the way production does (frame count + transparency from the
		// header; area = width * height * frames, like core GIFHandler::getImageArea).
		$frames = max( 1, self::probe( $srcPath, 'n-pages' ) ?? 1 );
		$animated = $frames > 1;
		[ $w, $h ] = ImageDims::of( $srcPath );
		$underThreshold = ( $w * $h * $frames ) <= $cfg['maxAnimatedArea'];
		$gif2webp = ToolLocator::path( 'gif2webp' );
		$libwebpAvailable = $gif2webp !== null;
		// Only probe transparency when it can change the decision (matches production).
		$hasTransparency = $animated && $underThreshold && $libwebpAvailable
			&& ( self::probe( $srcPath, 'bands' ) ?? 0 ) >= 4;

		$strategy = self::chooseStrategy( $animated, $underThreshold, $hasTransparency, $libwebpAvailable );
		$dst = $destDir . '/thumbro_' . $targetWidth . '.webp';

		if ( $strategy === 'libwebp' ) {
			return $this->runLibwebp( $vips, (string)$gif2webp, $srcPath, $targetWidth, $dst, $cfg );
		}
		return $this->runVips( $vips, $strategy, $srcPath, $targetWidth, $dst, $cfg );
	}

	/** vips-static (first frame, no n key) or vips-animated (all frames, n=-1) -> WebP with webpsave opts. */
	private function runVips(
		string $vips, string $strategy, string $srcPath, int $targetWidth, string $dst, array $cfg
	): Result {
		// vips-animated forces n=-1 to keep every frame; vips-static loads no n key (vips defaults
		// to the first frame), matching the production pipeline's frame-loading derivation.
		$in = $srcPath . ( $strategy === 'vips-animated' ? '[n=-1]' : '' );
		$out = $dst . self::makeOptions( $cfg['webpOutput'] );
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
	 * The two-step gif2webp pipeline: resize to a temp animated GIF, then encode to animated
	 * WebP with gif2webp. Wall time is the sum of both steps; peak RSS the max (they run in
	 * sequence). Mirrors the production gif2webp two-step path (VipsResizer + Gif2webpEncoder).
	 */
	private function runLibwebp(
		string $vips, string $gif2webp, string $srcPath, int $targetWidth, string $dst, array $cfg
	): Result {
		$tmpGif = dirname( $dst ) . '/thumbro_resize_' . $targetWidth . '.gif';
		// Resize load options: the gif block's resize.options with n=-1 prepended (gif2webp is an
		// animation-capable encoder chosen only for animated, under-threshold sources, so the
		// pipeline always keeps every frame for this path).
		$in = $srcPath . self::makeOptions( [ 'n' => '-1' ] + $cfg['gifResize'] );
		$resize = [ $vips, $in, '--size', $targetWidth . 'x100000', '-o', $tmpGif ];
		$p1 = Subprocess::run( $resize );
		if ( !$p1->ok() || !is_file( $tmpGif ) ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'vips resize failed: ' . $p1->stderr );
		}

		$encode = array_merge( [ $gif2webp ], self::gif2webpArgs( $cfg['gif2webpFlags'] ), [ $tmpGif, '-o', $dst ] );
		$p2 = Subprocess::run( $encode );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $tmpGif );
		if ( !$p2->ok() || !is_file( $dst ) ) {
			return Result::unavailable( $this->name(), $srcPath, $targetWidth, 'gif2webp failed: ' . $p2->stderr );
		}
		return new Result(
			$this->name(), $srcPath, $targetWidth, $dst, filesize( $dst ),
			$p1->wallMs + $p2->wallMs, max( $p1->peakRssKb ?? 0, $p2->peakRssKb ?? 0 ), true
		);
	}

	/**
	 * Render gif2webp flags as argv tokens, matching ShellCommand's 'libwebp' style: each flag
	 * is "-key", with a following value token only when the flag carries one ("" means bare).
	 *
	 * @param array<string,string> $flags
	 * @return string[]
	 */
	private static function gif2webpArgs( array $flags ): array {
		$args = [];
		foreach ( $flags as $key => $value ) {
			$args[] = "-$key";
			if ( $value !== '' && $value !== null && $value !== true ) {
				$args[] = (string)$value;
			}
		}
		return $args;
	}

	/**
	 * Format an options array as a "[key=value,...]" suffix, empty array -> "". Matches
	 * VipsOptionSuffix::make.
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

	/** A single integer header field via vipsheader, or null if unavailable. */
	private static function probe( string $path, string $field ): ?int {
		$vipsheader = ToolLocator::path( 'vipsheader' );
		if ( $vipsheader === null ) {
			return null;
		}
		$proc = Subprocess::run( [ $vipsheader, '-f', $field, $path ] );
		if ( !$proc->ok() ) {
			return null;
		}
		$value = trim( $proc->stdout );
		return $value === '' ? null : (int)$value;
	}

	/**
	 * Encoder settings from extension.json's per-MIME encode lists: webpsave options (the
	 * image/webp block's vips-webp entry) for the vips delegation, gif2webp flags (the gif block's
	 * gif2webp encode entry) for the encoder, the gif block's resize options, and the
	 * animated-area threshold.
	 *
	 * @return array{webpOutput:array<string,string>,gifResize:array<string,string>,gif2webpFlags:array<string,string>,maxAnimatedArea:int}
	 */
	private static function config(): array {
		static $cache = null;
		if ( $cache === null ) {
			$json = json_decode(
				(string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true
			);
			$config = $json['config'] ?? [];
			$opts = $config['ThumbroOptions']['value'] ?? [];
			$cache = [
				'webpOutput' => self::encodeOptions( $opts['image/webp'] ?? [], 'vips-webp' ),
				'gifResize' => $opts['image/gif']['resize']['options'] ?? [],
				'gif2webpFlags' => self::encodeOptions( $opts['image/gif'] ?? [], 'gif2webp' ),
				'maxAnimatedArea' => (int)( $config['ThumbroMaxAnimatedArea']['value'] ?? 0 ),
			];
		}
		return $cache;
	}

	/**
	 * The `options` of the first encode-list entry using $encoder in a MIME block, or [] if absent.
	 *
	 * @param array<string,mixed> $block a single ThumbroOptions MIME block
	 * @param string $encoder encoder name to match
	 * @return array<string,string>
	 */
	private static function encodeOptions( array $block, string $encoder ): array {
		foreach ( $block['encode'] ?? [] as $entry ) {
			if ( ( $entry['encoder'] ?? '' ) === $encoder ) {
				return $entry['options'] ?? [];
			}
		}
		return [];
	}
}
