<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Shell;

use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWiki\Shell\Shell;
use Wikimedia\FileBackend\FSFile\TempFSFile;

/**
 * Wrapper class around the shell command, useful to chain multiple commands
 * with intermediate files.
 *
 * Construct these through {@see ShellCommandFactory} so the temp-file factory is injected.
 */
class ShellCommand {

	/** Flag to indicate that the output file should be a temporary file */
	public const TEMP_OUTPUT = true;

	protected string $err;

	protected string $output;

	protected string $input;

	protected bool $removeInput;

	/**
	 * @param TempFSFileFactory $tempFactory Factory for intermediate temp files.
	 * @param string $name Human-readable backend label for debug logging.
	 * @param string $command Binary to run.
	 * @param array<string,string> $args Flags for the command.
	 * @param string $style Argument flattening style: 'vips' or 'libwebp'.
	 */
	public function __construct(
		private readonly TempFSFileFactory $tempFactory,
		private readonly string $name,
		private readonly string $command,
		private readonly array $args,
		private readonly string $style = 'vips',
	) {
	}

	/**
	 * Set the input and output file of this command
	 *
	 * @param string|ShellCommand $input Input file name or an ShellCommand object to use the
	 * output of that command
	 * @param string $output Output file name or extension of the temporary file
	 * @param bool $tempOutput Output to a temporary file
	 */
	public function setIO( $input, $output, $tempOutput = false ): void {
		if ( $input instanceof ShellCommand ) {
			$this->input = $input->getOutput();
			$this->removeInput = true;
		} else {
			$this->input = $input;
			$this->removeInput = false;
		}
		if ( $tempOutput ) {
			$tmpFile = $this->newTempFile( $output );
			$tmpFile->bind( $this );
			$this->output = $tmpFile->getPath();
		} else {
			$this->output = $output;
		}
	}

	/**
	 * Returns the output filename
	 */
	public function getOutput(): string {
		return $this->output;
	}

	/**
	 * Return the output of the command
	 */
	public function getErrorString(): string {
		return $this->err;
	}

	/** Flatten arguments according to the command's argument style. */
	private function makeArguments( array $args ): array {
		$cmdArgs = [];
		if ( $this->style === 'libwebp' ) {
			// Single-dash flags; valued flags render as two tokens "-flag value".
			foreach ( $args as $key => $value ) {
				$cmdArgs[] = "-$key";
				if ( $value !== '' && $value !== null && $value !== true ) {
					$cmdArgs[] = (string)$value;
				}
			}
			return $cmdArgs;
		}
		// vips style: "--key" or "--key=value".
		foreach ( $args as $key => $value ) {
			$cmdArg = "--$key";
			if ( $value ) {
				$cmdArg .= "=$value";
			}
			$cmdArgs[] = $cmdArg;
		}
		return $cmdArgs;
	}

	/** Constructs the command line array for executing the command. */
	private function buildCommand(): array {
		if ( $this->style === 'libwebp' ) {
			// libwebp style (gif2webp, cwebp): <command> <flags...> <input> -o <output>
			$cmd = array_merge( [ $this->command ], $this->makeArguments( $this->args ) );
			$cmd[] = $this->input;
			$cmd[] = '-o';
			$cmd[] = $this->output;
			return $cmd;
		}
		// vips: <command> <input> <--flags...> -o <output>
		$cmd = [ $this->command, $this->input ];
		$cmd = array_merge( $cmd, $this->makeArguments( $this->args ) );
		$cmd[] = '-o';
		$cmd[] = $this->output;
		return $cmd;
	}

	/** @internal test-only accessor for buildCommand(). */
	public function buildCommandForTest(): array {
		return $this->buildCommand();
	}

	/**
	 * Call the command and returns the return value.
	 */
	public function execute(): int {
		$cmd = $this->buildCommand();

		wfDebug( sprintf( '[Extension:Thumbro] Executing %s: "%s"',
			$this->name,
			implode( '" "', $cmd )
		) );

		$result = Shell::command( $cmd )
			->environment( [ 'IM_CONCURRENCY' => '1' ] )
			->limits( [ 'filesize' => 409600 ] )
			->includeStderr()
			->execute();

		$this->err = $result->getStdout();
		$retval = $result->getExitCode();

		# Cleanup temp file
		if ( $retval != 0 && file_exists( $this->output ) ) {
			unlink( $this->output );
		}
		if ( $this->removeInput ) {
			unlink( $this->input );
		}

		return $retval;
	}

	/**
	 * Generate a random, non-existent temporary file with a specified extension.
	 */
	private function newTempFile( string $extension ): TempFSFile {
		return $this->tempFactory->newTempFSFile( 'thumbro_', $extension );
	}
}
