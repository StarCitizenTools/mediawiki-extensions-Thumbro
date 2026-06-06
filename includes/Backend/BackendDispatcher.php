<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

use MediaTransformOutput;

/**
 * Selects the backend named by a request's resolved options and runs its plan.
 *
 * This is the single backend-selection seam, shared by the BitmapHandlerTransform hook and
 * Special:ThumbroTest, so the backend a file is routed to can never diverge between production
 * output and the sysop comparison page. Backends are registered by name in ServiceWiring, so
 * adding one needs no change here (open for extension, closed for modification).
 */
class BackendDispatcher {

	/**
	 * @param array<string,ThumbnailBackend> $backends Library name => backend.
	 * @param CommandPlanRunner $runner
	 */
	public function __construct(
		private readonly array $backends,
		private readonly CommandPlanRunner $runner,
	) {
	}

	/**
	 * Dispatch a transform to the backend named by the request's options' library.
	 * Falls back to libvips for an unregistered library. Returns the runner's result:
	 * false stops further processing, true lets core continue.
	 */
	public function dispatch( BackendRequest $request, ?MediaTransformOutput &$mto ): bool {
		$library = $request->getOptions()->library();
		$backend = $this->backends[$library] ?? $this->backends['libvips'];
		return $this->runner->run( $backend->plan( $request ), $request, $mto );
	}
}
