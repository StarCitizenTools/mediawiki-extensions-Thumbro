<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

use MediaTransformOutput;
use MediaWiki\Extension\Thumbro\Backend\Encoder\EncodeInput;
use MediaWiki\Extension\Thumbro\Backend\Encoder\Encoder;
use MediaWiki\Extension\Thumbro\Backend\Encoder\EncoderRouter;
use MediaWiki\Extension\Thumbro\Backend\Encoder\FileTraits;
use MediaWiki\Extension\Thumbro\Backend\Resize\Resizer;
use MediaWiki\Extension\Thumbro\Image\AlphaDetector;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;

/**
 * The resize→encode pipeline. Replaces the former BackendDispatcher + LibvipsBackend/LibwebpBackend:
 * it builds {@see FileTraits}, drops unavailable encoders, routes the encode list to one encoder
 * ({@see EncoderRouter}), derives frame loading, and assembles the {@see CommandPlan} — fused
 * (one vips command) for an encoder that resizes itself, or two commands (vips resize → encode)
 * for an encode-only tool. {@see CommandPlanRunner} executes the plan.
 */
class EncodePipeline {

	/**
	 * @param array<string,Encoder> $encoders Encoder name => encoder.
	 * @param Resizer $resizer
	 * @param EncoderRouter $router
	 * @param AlphaDetector $alphaDetector
	 * @param ShellCommandFactory $shellFactory
	 * @param int $maxAnimatedArea Animation is only emitted for sources at or under this area.
	 * @param CommandPlanRunner $runner
	 */
	public function __construct(
		private readonly array $encoders,
		private readonly Resizer $resizer,
		private readonly EncoderRouter $router,
		private readonly AlphaDetector $alphaDetector,
		private readonly ShellCommandFactory $shellFactory,
		private readonly int $maxAnimatedArea,
		private readonly CommandPlanRunner $runner,
	) {
	}

	/**
	 * Plan and run the transform. Returns the runner's result: false stops further processing
	 * (Thumbro handled it), true lets core continue (nothing to do).
	 */
	public function dispatch( BackendRequest $request, ?MediaTransformOutput &$mto ): bool {
		return $this->runner->run( $this->plan( $request ), $request, $mto );
	}

	/** Build the command plan for a request (pure aside from the alpha probe / availability stat). */
	public function plan( BackendRequest $request ): CommandPlan {
		$handler = $request->getHandler();
		$file = $request->getFile();

		$animated = $handler->isAnimatedImage( $file );
		// Animatability is gated on the global $wgThumbroMaxAnimatedArea directly. For the only
		// registered animated input handler (WebP) this equals its canAnimateThumbnail(), so it is
		// behaviour-identical today; a future handler with a different area rule would need this
		// revisited rather than silently diverging.
		$underThreshold = $handler->getImageArea( $file ) <= $this->maxAnimatedArea;
		// Probe transparency only when it can affect routing (an animated, under-threshold source).
		$hasAlpha = $animated && $underThreshold && $this->alphaDetector->hasAlpha( $request->srcPath() );
		$traits = new FileTraits( $animated, $hasAlpha, $underThreshold );

		// Drop encoders whose tool is absent before routing (reproduces the old libwebpAvailable
		// fallback: a missing gif2webp lets the transparent-animation entry fall through to vips).
		$available = array_values( array_filter(
			$request->getOptions()->encodeList(),
			fn ( array $entry ): bool => ( $this->encoders[$entry['encoder']] ?? null )?->isAvailable() === true
		) );
		if ( $available === [] ) {
			return CommandPlan::empty();
		}

		$choice = $this->router->choose( $available, $traits );
		$encoder = $this->encoders[$choice->encoder];

		// Frame loading: keep every frame only when this encoder will emit animation; otherwise
		// load nothing extra (vips defaults to the first frame — valid for every loader, whereas
		// an explicit n=1 would error on jpeg/png which have no page support).
		$loadOptions = $request->getOptions()->resizeOptions();
		if ( $encoder->supportsAnimation() && $animated && $underThreshold ) {
			$loadOptions = [ 'n' => '-1' ] + $loadOptions;
		}

		if ( $encoder->requiresResizedInput() ) {
			$resize = $this->resizer->planResize(
				$this->shellFactory,
				$request->srcPath(),
				$loadOptions,
				$request->physicalSize(),
				(string)$encoder->intermediateFormat()
			);
			$encode = $encoder->planEncode(
				$this->shellFactory, EncodeInput::fromResized( $resize ), $request->dstPath(), $choice->options
			);
			return CommandPlan::of( $resize, $encode );
		}

		$encode = $encoder->planEncode(
			$this->shellFactory,
			EncodeInput::fromSource( $request->srcPath(), $request->physicalSize(), $loadOptions ),
			$request->dstPath(),
			$choice->options
		);
		return CommandPlan::of( $encode );
	}
}
