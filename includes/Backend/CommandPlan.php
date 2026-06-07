<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

use MediaWiki\Extension\Thumbro\Shell\ShellCommand;

/**
 * An ordered list of shell commands planned for one transform.
 *
 * The {@see EncodePipeline} is a pure planner: it builds a CommandPlan but never executes it.
 * {@see CommandPlanRunner} executes any plan uniformly. An empty plan is the
 * "nothing to do — let core continue" signal (e.g. no available encoder).
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
