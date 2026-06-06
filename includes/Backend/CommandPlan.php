<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

use MediaWiki\Extension\Thumbro\Shell\ShellCommand;

/**
 * An ordered list of shell commands a backend plans to run for one transform.
 *
 * Backends ({@see ThumbnailBackend}) are pure planners: they build a CommandPlan but never
 * execute it. {@see CommandPlanRunner} executes any plan uniformly. An empty plan is the
 * "nothing to do — let core continue" signal (e.g. libvips with no commands).
 */
class CommandPlan {

	/**
	 * @param ShellCommand[] $commands
	 */
	private function __construct(
		private readonly array $commands,
	) {
	}

	public static function empty(): self {
		return new self( [] );
	}

	public static function of( ShellCommand ...$commands ): self {
		return new self( array_values( $commands ) );
	}

	public function isEmpty(): bool {
		return $this->commands === [];
	}

	/** @return ShellCommand[] */
	public function getCommands(): array {
		return $this->commands;
	}
}
