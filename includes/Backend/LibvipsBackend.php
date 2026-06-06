<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;

/**
 * libvips (vipsthumbnail) backend. Plans a single resize command that reads the source with
 * the configured load options and writes the destination with the configured save options.
 *
 * Pure planner — see {@see ThumbnailBackend}. Replaces the static Libvips wrapper's command
 * construction; execution moves to {@see CommandPlanRunner}.
 */
class LibvipsBackend implements ThumbnailBackend {

	public function __construct(
		private readonly ShellCommandFactory $shellFactory,
	) {
	}

	public function plan( BackendRequest $request ): CommandPlan {
		$options = $request->getOptions();

		$command = $this->shellFactory->create( 'libvips', $options->command(), [
			'size' => $request->physicalSize(),
		] );
		$command->setIO(
			$request->srcPath() . $this->makeOptions( $this->inputOptions( $request ) ),
			$request->dstPath() . $this->makeOptions( $options->outputOptions() )
		);

		return CommandPlan::of( $command );
	}

	/**
	 * Source load options. Normally the resolved inputOptions verbatim, but for an animated
	 * source whose thumbnail may itself be animated (the handler's canAnimateThumbnail() — an
	 * animated file under the area threshold) we force `n=-1` so vipsthumbnail keeps every
	 * frame. Without this it reads only the first frame, silently flattening e.g. an animated
	 * WebP to a static thumbnail. A caller that already pinned `n` (the GIF backend, when it
	 * delegates with an explicit first-frame/all-frames choice) is respected, never overridden.
	 *
	 * @return array<string,string>
	 */
	private function inputOptions( BackendRequest $request ): array {
		$options = $request->getOptions()->inputOptions();
		if ( isset( $options['n'] ) ) {
			return $options;
		}
		$handler = $request->getHandler();
		$file = $request->getFile();
		if ( $handler->isAnimatedImage( $file ) && $handler->canAnimateThumbnail( $file ) ) {
			return [ 'n' => '-1' ] + $options;
		}
		return $options;
	}

	/**
	 * Converts the given array of arguments into a "[key=value,key=value,...]" suffix.
	 * Returns an empty string for an empty array.
	 *
	 * @see https://www.libvips.org/API/current/Using-vipsthumbnail.html#output-format-and-options
	 *
	 * @param array<string,string> $args
	 */
	private function makeOptions( array $args ): string {
		$arg = '';
		if ( count( $args ) > 0 ) {
			// Format output options into [key=value,key=value] format
			$arg = '[';
			foreach ( $args as $key => $value ) {
				$arg .= "$key=$value,";
			}
			$arg = rtrim( $arg, "," );
			$arg .= "]";
		}
		return $arg;
	}
}
