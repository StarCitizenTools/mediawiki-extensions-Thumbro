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
			$request->srcPath() . $this->makeOptions( $options->inputOptions() ),
			$request->dstPath() . $this->makeOptions( $options->outputOptions() )
		);

		return CommandPlan::of( $command );
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
