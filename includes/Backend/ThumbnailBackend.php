<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

/**
 * A thumbnail backend. Implementations are pure planners: given a request they decide which
 * shell commands to run and return them as a {@see CommandPlan}, but never execute anything.
 * {@see CommandPlanRunner} executes the plan. This keeps backend decision logic testable
 * without invoking any external binary.
 */
interface ThumbnailBackend {

	public function plan( BackendRequest $request ): CommandPlan;
}
