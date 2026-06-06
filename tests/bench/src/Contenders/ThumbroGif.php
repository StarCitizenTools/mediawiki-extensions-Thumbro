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
 * Production routes GIFs through LibwebpBackend, which makes a runtime decision (see
 * {@see self::chooseStrategy()}, mirrored from LibwebpBackend::chooseStrategy and locked by a
 * truth-table test): transparent animated GIFs under the area threshold are encoded with
 * gif2webp (the two-step vipsthumbnail -> temp .gif -> gif2webp pipeline); opaque or
 * over-threshold animations delegate to libvips as animated WebP (n=-1); static GIFs take the
 * first frame (n=1). The routing inputs — animation, frame count, transparency, area — are
 * probed at runtime with vipsheader, exactly as production does (its AlphaDetector reads
 * `vipsheader -f bands`), so the contender never goes stale against a hand-kept manifest.
 *
 * Encoder settings come from extension.json: the webpsave options (image/webp block) for the
 * libvips delegation, and the gif2webp flags (ThumbroLibraries.libwebp.flags) for the encoder.
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
	 * The runtime routing decision, copied verbatim from LibwebpBackend::chooseStrategy. A unit
	 * test asserts the two stay identical across the whole truth table (the standalone harness
	 * can't load the production class, so the rule is mirrored, not shared).
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

	/** vips-static (first frame, n=1) or vips-animated (all frames, n=-1) -> WebP with webpsave opts. */
	private function runVips(
		string $vips, string $strategy, string $srcPath, int $targetWidth, string $dst, array $cfg
	): Result {
		$n = $strategy === 'vips-static' ? '1' : '-1';
		$out = $dst . self::makeOptions( $cfg['webpOutput'] );
		$cmd = [ $vips, $srcPath . "[n=$n]", '--size', $targetWidth . 'x100000', '-o', $out ];

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
	 * sequence). Mirrors LibwebpBackend::planLibwebpEncode.
	 */
	private function runLibwebp(
		string $vips, string $gif2webp, string $srcPath, int $targetWidth, string $dst, array $cfg
	): Result {
		$tmpGif = dirname( $dst ) . '/thumbro_resize_' . $targetWidth . '.gif';
		// Load options are the configured gif block's (production's planLibwebpEncode uses
		// inputOptions() verbatim here, unlike the delegation paths which force n at runtime).
		$resize = [ $vips, $srcPath . self::makeOptions( $cfg['gifInput'] ), '--size', $targetWidth . 'x100000', '-o', $tmpGif ];
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
	 * Render gif2webp flags as argv tokens, matching ShellCommand's 'gif2webp' style: each flag
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
	 * LibvipsBackend::makeOptions.
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
	 * Encoder settings from extension.json: webpsave options for the libvips delegation, gif2webp
	 * flags for the encoder, and the animated-area threshold.
	 *
	 * @return array{webpOutput:array<string,string>,gifInput:array<string,string>,gif2webpFlags:array<string,string>,maxAnimatedArea:int}
	 */
	private static function config(): array {
		static $cache = null;
		if ( $cache === null ) {
			$json = json_decode(
				(string)file_get_contents( __DIR__ . '/../../../../extension.json' ), true
			);
			$config = $json['config'] ?? [];
			$opts = $config['ThumbroOptions']['value'] ?? [];
			$libs = $config['ThumbroLibraries']['value'] ?? [];
			$cache = [
				'webpOutput' => $opts['image/webp']['outputOptions'] ?? [],
				'gifInput' => $opts['image/gif']['inputOptions'] ?? [],
				'gif2webpFlags' => $libs['libwebp']['flags'] ?? [],
				'maxAnimatedArea' => (int)( $config['ThumbroMaxAnimatedArea']['value'] ?? 0 ),
			];
		}
		return $cache;
	}
}
