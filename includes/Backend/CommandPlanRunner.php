<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

use MediaTransformOutput;
use MediaWiki\Extension\Thumbro\Image\ExifCommentWriter;
use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;

/**
 * Executes the {@see CommandPlan} produced by the {@see EncodePipeline}: runs each command,
 * surfaces failures as a transform error, optionally writes an EXIF comment, and constructs
 * the resulting thumbnail. This is the single home for the execute-and-finalise tail.
 */
class CommandPlanRunner {

	public function __construct(
		private readonly ExifCommentWriter $exifWriter,
	) {
	}

	/**
	 * @param CommandPlan $plan Commands to run.
	 * @param BackendRequest $request The originating request (file, params, options).
	 * @param MediaTransformOutput|null &$mto Set to the thumbnail on success or an error on failure.
	 * @return bool True when there was nothing to do (let core continue); false when Thumbro
	 *   handled the transform (success or error), matching the BitmapHandlerTransform contract.
	 */
	public function run( CommandPlan $plan, BackendRequest $request, ?MediaTransformOutput &$mto ): bool {
		if ( $plan->isEmpty() ) {
			return true;
		}

		foreach ( $plan->getCommands() as $command ) {
			$retval = $command->execute();
			if ( $retval != 0 ) {
				$error = $command->getErrorString() . "\nError code: $retval";
				wfDebug( "[Extension:Thumbro] thumbnail command failed!\n$error" );
				$mto = $request->getHandler()->getMediaTransformError( $request->getParams(), $error );
				return false;
			}
		}

		$options = $request->getOptions();
		if ( $options->setComment() && $request->hasComment() ) {
			$this->exifWriter->write( $request->dstPath(), $request->comment() );
		}

		$mto = new ThumbroThumbnailImage(
			$request->getFile(),
			$request->dstUrl(),
			$request->clientWidth(),
			$request->clientHeight(),
			$request->dstPath()
		);

		return false;
	}
}
