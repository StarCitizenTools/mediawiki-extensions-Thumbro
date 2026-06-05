<?php
/**
 * PHP wrapper class for the libwebp backend under MediaWiki
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
use MediaWiki\Extension\Thumbro\Transparency;
use MediaWiki\Extension\Thumbro\Utils;
use TransformationalImageHandler;

/**
 * GIF backend strategy. Owns the routing decision for GIF transforms: transparent
 * animated GIFs under the area threshold are encoded to animated WebP with gif2webp
 * (libwebp), which handles per-frame transparency far better than libvips's WebP
 * writer; opaque/over-threshold/static GIFs are delegated to libvips (animated or
 * first-frame).
 */
class Libwebp {
	/**
	 * Pure routing decision.
	 * @return string 'libwebp' | 'vips-animated' | 'vips-static'
	 */
	public static function chooseStrategy(
		bool $animated, bool $underThreshold, bool $hasTransparency, bool $libwebpAvailable
	): string {
		if ( !$animated || !$underThreshold ) {
			return 'vips-static';
		}
		if ( $hasTransparency && $libwebpAvailable ) {
			return 'libwebp';
		}
		return 'vips-animated';
	}

	/**
	 * GIF backend strategy. Options carry: 'command' (vips), 'webpCommand' (gif2webp),
	 * 'webpOptions' (gif2webp flags), 'outputOptions' (libvips WebP-save flags for the
	 * delegation path), 'inputOptions', and 'maxAnimatedArea'.
	 */
	public static function doTransform(
		TransformationalImageHandler $handler,
		File $file,
		array $params,
		array $options,
		?MediaTransformOutput &$mto
	): bool {
		$animated = $handler->isAnimatedImage( $file );
		$underThreshold = $handler->getImageArea( $file ) <= ( $options['maxAnimatedArea'] ?? 0 );
		$libwebpAvailable = ( $options['webpCommand'] ?? '' ) !== ''
			&& is_executable( $options['webpCommand'] );
		// Only probe transparency when it can affect the decision (an animated, under-threshold
		// GIF that could use the libwebp encoder); static/over-threshold GIFs skip the vipsheader call.
		$hasTransparency = $animated && $underThreshold && $libwebpAvailable
			&& Transparency::hasAlpha( $params['srcPath'], $options['command'] );

		$strategy = self::chooseStrategy( $animated, $underThreshold, $hasTransparency, $libwebpAvailable );

		if ( $strategy === 'libwebp' ) {
			return self::encodeWithLibwebp( $handler, $file, $params, $options, $mto );
		}

		// Delegate to libvips. vips-animated keeps all frames; vips-static takes the first.
		$vipsOptions = [
			'command' => $options['command'],
			'inputOptions' => [ 'n' => $strategy === 'vips-animated' ? '-1' : '1' ],
			'outputOptions' => $options['outputOptions'] ?? [],
		];
		return Libvips::doTransform( $handler, $file, $params, $vipsOptions, $mto );
	}

	/** Run the libwebp encode pipeline (vipsthumbnail resize → gif2webp); the transparent animated path. */
	private static function encodeWithLibwebp(
		TransformationalImageHandler $handler,
		File $file,
		array $params,
		array $options,
		?MediaTransformOutput &$mto
	): bool {
		wfDebug( "[Extension:Thumbro] Creating animated WebP for {$file->getName()} using gif2webp" );

		/** @var ShellCommand $command */
		foreach ( self::makeCommands( $params, $options ) as $command ) {
			$retval = $command->execute();
			if ( $retval != 0 ) {
				$error = $command->getErrorString() . "\nError code: $retval";
				wfDebug( "[Extension:Thumbro] gif2webp pipeline failed!\n$error" );
				$mto = $handler->getMediaTransformError( $params, $error );
				return false;
			}
		}

		if ( !empty( $options['setcomment'] ) && !empty( $params['comment'] ) ) {
			Utils::setEXIFComment( $params['dstPath'], $params['comment'] );
		}

		$mto = new ThumbroThumbnailImage( $file, $params['dstUrl'],
			$params['clientWidth'], $params['clientHeight'], $params['dstPath'] );

		return false;
	}

	/**
	 * Build the two-command pipeline:
	 *   1. vipsthumbnail src[inputOptions] --size WxH -o {temp}.gif
	 *   2. gif2webp <webpOptions> {temp}.gif -o dst.webp
	 *
	 * @return ShellCommand[]
	 */
	public static function makeCommands( array $params, array $options ): array {
		$resize = new ShellCommand( 'libvips', $options['command'], [
			'size' => $params['physicalWidth'] . 'x' . $params['physicalHeight'],
		] );
		$resize->setIO(
			$params['srcPath'] . self::makeInputOptions( $options['inputOptions'] ?? [] ),
			'gif',
			ShellCommand::TEMP_OUTPUT
		);

		$encode = new ShellCommand( 'libwebp', $options['webpCommand'], $options['webpOptions'] ?? [], 'gif2webp' );
		$encode->setIO( $resize, $params['dstPath'] );

		return [ $resize, $encode ];
	}

	/**
	 * Format vipsthumbnail load options as a "[key=value,...]" suffix on the source path.
	 */
	private static function makeInputOptions( array $args ): string {
		if ( $args === [] ) {
			return '';
		}
		$parts = [];
		foreach ( $args as $key => $value ) {
			$parts[] = "$key=$value";
		}
		return '[' . implode( ',', $parts ) . ']';
	}
}
