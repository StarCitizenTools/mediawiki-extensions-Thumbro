<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro;

use File;
use MediaTransformOutput;
use MediaWiki\Config\Config;
use MediaWiki\Extension\Thumbro\Libraries\Libvips;
use MediaWiki\Extension\Thumbro\Libraries\Libwebp;
use TransformationalImageHandler;

/**
 * Selects and invokes the thumbnail backend for an already-resolved option set
 * (from Utils::getOptions).
 *
 * This is the single backend-selection seam, shared by the BitmapHandlerTransform
 * hook and Special:ThumbroTest, so the backend a file is routed to can never diverge
 * between production output and the sysop comparison page.
 */
class BackendDispatcher {

	/**
	 * Dispatch a transform to the backend named by the resolved options' 'library'.
	 *
	 * $options is the option set from Utils::getOptions() — it carries 'library',
	 * 'command', 'inputOptions' and 'outputOptions'. Returns the backend's own return
	 * value (false stops further processing).
	 */
	public static function dispatch(
		TransformationalImageHandler $handler,
		File $file,
		array $params,
		array $options,
		Config $config,
		?MediaTransformOutput &$mto
	): bool {
		$library = $options['library'] ?? 'libvips';

		if ( $library === 'libwebp' ) {
			return Libwebp::doTransform(
				$handler, $file, $params, self::libwebpOptions( $config, $options ), $mto
			);
		}

		return Libvips::doTransform( $handler, $file, $params, $options, $mto );
	}

	/**
	 * Assemble the option bundle the Libwebp backend needs from the resolved $options
	 * (Utils::getOptions) and config: the libvips command for the resize step and the
	 * libvips delegation, the gif2webp command + flags for the encode step, the WebP-save
	 * flags for the opaque/static libvips fallback, and the animated-area threshold.
	 */
	public static function libwebpOptions( Config $config, array $options ): array {
		$libraries = $config->get( 'ThumbroLibraries' );
		$gifOptions = $config->get( 'ThumbroOptions' )['image/gif'] ?? [];

		return [
			// libvips for the resize step and the libvips delegation.
			'command' => $libraries['libvips']['command'] ?? $options['command'],
			// gif2webp for the encode step.
			'webpCommand' => $libraries['libwebp']['command'] ?? '',
			'webpOptions' => $gifOptions['outputOptions'] ?? [],
			// libvips WebP-save flags for the opaque/static delegation.
			'outputOptions' => $options['outputOptions'] ?? [],
			'inputOptions' => $options['inputOptions'] ?? [],
			'maxAnimatedArea' => (int)$config->get( 'ThumbroMaxAnimatedArea' ),
		];
	}
}
