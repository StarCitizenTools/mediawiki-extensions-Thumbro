<?php
/**
 * PHP wrapper class for VIPS under MediaWiki
 *
 * Copyright © Bryan Tong Minh, 2011
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro;

use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use Wikimedia\FileBackend\FSFile\TempFSFile;

/**
 * Wrapper class around the shell command, useful to chain multiple commands
 * with intermediate files
 */
class ShellCommand {

	/** Flag to indicate that the output file should be a temporary file */
	public const TEMP_OUTPUT = true;

	protected string $err;

	protected string $output;

	protected string $input;

	protected bool $removeInput;

	protected string $command;

	protected array $args;

	protected string $name;

	/**
	 * Constructor
	 */
	public function __construct( string $name, string $command, array $args ) {
		$this->name = $name;
		$this->command = $command;
		$this->args = $args;
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
			$tmpFile = self::makeTemp( $output );
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

	/**
	 * Flatten arguments into "--key=name" array
	 */
	private function makeArguments( array $args ): array {
		$cmdArgs = [];
		foreach ( $args as $key => $value ) {
			$cmdArg = "--$key";
			if ( $value ) {
				$cmdArg .= "=$value";
			}
			array_push( $cmdArgs, $cmdArg );
		}
		return $cmdArgs;
	}

	/**
	 * Constructs the command line array for executing the command.
	 */
	private function buildCommand(): array {
		$cmd = [
			$this->command,
			$this->input,
		];
		# Input arguments
		$cmd = array_merge( $cmd, $this->makeArguments( $this->args ) );
		# Output arguments
		$cmd[] = '-o';
		$cmd[] = $this->output;
		return $cmd;
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
	 * Generate a random, non-existent temporary file with a specified
	 * extension.
	 */
	public static function makeTemp( string $extension ): TempFSFile {
		$tmpFactory = MediaWikiServices::getInstance()->getTempFSFileFactory();
		return $tmpFactory->newTempFSFile( 'thumbro_', $extension );
	}
}
