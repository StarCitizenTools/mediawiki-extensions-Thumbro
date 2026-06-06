<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

use MediaWiki\Extension\Thumbro\Image\AlphaDetector;
use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;

/**
 * GIF backend strategy. Owns the routing decision for GIF transforms: transparent animated
 * GIFs under the area threshold are encoded to animated WebP with gif2webp (libwebp), which
 * handles per-frame transparency far better than libvips's WebP writer; opaque/over-threshold/
 * static GIFs delegate to {@see LibvipsBackend} (animated keeps all frames, static the first).
 *
 * Pure planner — see {@see ThumbnailBackend}. The alpha probe is injected, so the routing is
 * unit-testable without binaries.
 */
class LibwebpBackend implements ThumbnailBackend {

	public function __construct(
		private readonly LibvipsBackend $libvips,
		private readonly AlphaDetector $alphaDetector,
		private readonly ShellCommandFactory $shellFactory,
		private readonly LibwebpSettings $settings,
	) {
	}

	/**
	 * Pure routing decision.
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

	public function plan( BackendRequest $request ): CommandPlan {
		$handler = $request->getHandler();
		$file = $request->getFile();

		$animated = $handler->isAnimatedImage( $file );
		$underThreshold = $handler->getImageArea( $file ) <= $this->settings->maxAnimatedArea();
		$libwebpAvailable = $this->settings->libwebpAvailable();
		// Only probe transparency when it can affect the decision (an animated, under-threshold
		// GIF that could use the libwebp encoder); static/over-threshold GIFs skip the probe.
		$hasTransparency = $animated && $underThreshold && $libwebpAvailable
			&& $this->alphaDetector->hasAlpha( $request->srcPath() );

		$strategy = self::chooseStrategy( $animated, $underThreshold, $hasTransparency, $libwebpAvailable );

		if ( $strategy === 'libwebp' ) {
			return $this->planLibwebpEncode( $request );
		}

		// Delegate to libvips. vips-animated keeps all frames; vips-static takes the first.
		$delegated = $request->getOptions()->withDelegated(
			'libvips',
			$this->settings->libvipsCommand(),
			[ 'n' => $strategy === 'vips-animated' ? '-1' : '1' ],
			$request->getOptions()->outputOptions()
		);
		return $this->libvips->plan( $request->withOptions( $delegated ) );
	}

	/**
	 * Plan the two-command libwebp pipeline (the transparent animated path):
	 *   1. vipsthumbnail src[inputOptions] --size WxH -o {temp}.gif
	 *   2. gif2webp <flags> {temp}.gif -o dst.webp
	 */
	private function planLibwebpEncode( BackendRequest $request ): CommandPlan {
		$resize = $this->shellFactory->create( 'libvips', $this->settings->libvipsCommand(), [
			'size' => $request->physicalSize(),
		] );
		$resize->setIO(
			$request->srcPath() . $this->makeInputOptions( $request->getOptions()->inputOptions() ),
			'gif',
			ShellCommand::TEMP_OUTPUT
		);

		$encode = $this->shellFactory->create(
			'libwebp', $this->settings->gif2webpCommand(), $this->settings->gif2webpFlags(), 'gif2webp'
		);
		$encode->setIO( $resize, $request->dstPath() );

		return CommandPlan::of( $resize, $encode );
	}

	/**
	 * Format vipsthumbnail load options as a "[key=value,...]" suffix on the source path.
	 *
	 * @param array<string,string> $args
	 */
	private function makeInputOptions( array $args ): string {
		if ( $args === [] ) {
			return '';
		}
		$parts = [];
		foreach ( $args as $key => $value ) {
			$parts[] = "$key=$value";
		}
		return '[' . implode( ',', $parts ) . ']';
	}
}
