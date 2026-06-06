<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Shell;

use MediaWiki\FileBackend\FSFile\TempFSFileFactory;

/**
 * Mints {@see ShellCommand} instances with the temp-file factory injected, so backends never
 * construct shell commands (or reach for the service container) directly.
 */
class ShellCommandFactory {

	public function __construct(
		private readonly TempFSFileFactory $tempFactory,
	) {
	}

	/**
	 * @param string $name Human-readable backend label for debug logging.
	 * @param string $command Binary to run.
	 * @param array<string,string> $args Flags for the command.
	 * @param string $style Argument flattening style: 'vips' or 'gif2webp'.
	 */
	public function create( string $name, string $command, array $args, string $style = 'vips' ): ShellCommand {
		return new ShellCommand( $this->tempFactory, $name, $command, $args, $style );
	}
}
