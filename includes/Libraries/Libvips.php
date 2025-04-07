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

namespace MediaWiki\Extension\Thumbro\Libraries;

use File;
use MediaTransformOutput;
use MediaWiki\Extension\Thumbro\ShellCommand;
use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;
use MediaWiki\Extension\Thumbro\Utils;
use MediaWiki\Shell\Shell;
use TransformationalImageHandler;

/**
 * Wrapper class for VIPS, a free image processing system good at handling
 * large pictures.
 *
 * http://www.vips.ecs.soton.ac.uk/
 *
 * @author Bryan Tong Minh
 */
class Libvips {
	/**
	 * Performs a transform with VIPS
	 */
	public static function doTransform(
		TransformationalImageHandler $handler,
		File $file,
		array $params,
		array $options,
		?MediaTransformOutput &$mto
	): bool {
		wfDebug( "[Extension:Thumbro] Creating thumbnails for {$file->getName()} using libvips" );

		$commands = self::makeCommands( $params, $options );
		if ( count( $commands ) == 0 ) {
			return true;
		}

		// Execute the commands
		/** @var ShellCommand $command */
		foreach ( $commands as $i => $command ) {
			$retval = $command->execute();
			if ( $retval != 0 ) {
				$error = $command->getErrorString() . "\nError code: $retval";
				wfDebug( "[Extension:Thumbro] libvips command failed!\n$error" );
				$mto = $handler->getMediaTransformError( $params, $error );
				return false;
			}
		}

		// Set comment
		if ( !empty( $options['setcomment'] ) && !empty( $params['comment'] ) ) {
			Utils::setEXIFComment( $params['dstPath'], $params['comment'] );
		}

		// Set the output variable
		$mto = new ThumbroThumbnailImage( $file, $params['dstUrl'],
			$params['clientWidth'], $params['clientHeight'], $params['dstPath'] );

		// Stop processing
		return false;
	}

	/**
	 * Converts the given array of arguments into a string in the format
	 * [key=value,key=value,...]. If the array is empty, returns an empty string.
	 * 
	 * @see https://www.libvips.org/API/current/Using-vipsthumbnail.html#output-format-and-options
	 */
	private static function makeOptions( array $args ): string {
		$arg = '';
		if ( count( $args ) > 0  ) {
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

	public static function makeCommands( array $params, array $options ): array {
		$commands = [];
		// Create thumbnail into the same file type
		$baseCommand = new ShellCommand( 'libvips', $options['command'], [
			'size' => $params['physicalWidth'] . 'x' . $params['physicalHeight']
		] );
		$baseCommand->setIO(
			$params['srcPath'] . self::makeOptions( $options['inputOptions'] ?? [] ),
			$params['dstPath'] . self::makeOptions( $options['outputOptions'] ?? [] )
		);

		$commands[] = $baseCommand;

		return $commands;
	}

	/**
	 * Return a software version for use in Special:Version
	 */
	public static function getSoftwareVersion(): ?string {
		$result = Shell::command( [ 'vips', '-v' ] )
			->includeStderr()
			->execute();

		if ( $result->getExitCode() != 0 ) {
			// Vips command is not avaliable, exit
			return null;
		}
		// Explode the string by '-'
		// stdout returns something like vips-8.7.4-Sat Nov 21 16:50:57 UTC 2020
		$parts = explode( '-', $result->getStdout() );
		// Check if the first part exists and is a valid version number
		if ( !isset( $parts[1] ) || !preg_match( '/^\d+\.\d+\.\d+$/', $parts[1] ) ) {
			return null;
		}

		return $parts[1];
	}
}
