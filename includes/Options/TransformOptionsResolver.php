<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Options;

use File;
use MediaWiki\Config\Config;
use TransformationalImageHandler;

/**
 * Resolves whether Thumbro should transform a file and, if so, with which options.
 *
 * Worth knowing: every Thumbro handler's getThumbType() reports image/webp, so the matched
 * ThumbroOptions block is always image/webp — its enabled/minArea/maxArea gate the transform.
 * `library`, `inputOptions` and `outputOptions` are each taken from the input-MIME block (falling
 * back to the webp block), so a MIME can carry its own webpsave flags.
 *
 * A libwebp block is the exception for `outputOptions`: there they are gif2webp encoder flags
 * (which live on the libwebp library), not webpsave flags, so libwebp always takes the webp-block
 * fallback — the webpsave options its opaque-GIF -> libvips delegation needs.
 */
class TransformOptionsResolver {

	public function __construct(
		private readonly Config $config,
	) {
	}

	public function resolve( TransformationalImageHandler $handler, File $file ): ?TransformOptions {
		$options = $this->config->get( 'ThumbroOptions' );
		$libraries = $this->config->get( 'ThumbroLibraries' );
		$inputMimeType = $file->getMimeType();
		$outputMimeType = $handler->getThumbType( $file->getExtension(), $inputMimeType )[1];

		foreach ( $options as $mimeType => $option ) {
			if ( $mimeType !== $outputMimeType ) {
				continue;
			}

			if ( !isset( $option['enabled'] ) || $option['enabled'] !== true ) {
				continue;
			}

			// Backend selection is per INPUT MIME type. getThumbType() always reports
			// image/webp, so $option here is the webp block; take `library` from the
			// input-MIME block instead so e.g. image/gif can select libwebp.
			$library = $options[$inputMimeType]['library'] ?? $option['library'];
			if ( !isset( $libraries[$library] ) || !isset( $libraries[$library]['command'] ) ) {
				continue;
			}

			// Multi-page files are not supported
			if ( $file->isMultipage() ) {
				continue;
			}

			$area = $handler->getImageArea( $file );
			if ( isset( $option['minArea'] ) && $area < $option['minArea'] ) {
				continue;
			}
			if ( isset( $option['maxArea'] ) && $area >= $option['maxArea'] ) {
				continue;
			}

			// Per-MIME webpsave flags, falling back to the webp block. libwebp always takes the
			// fallback (its outputOptions are encoder flags, not webpsave — see the class docblock);
			// this also keeps old configs with gif2webp flags under image/gif.outputOptions safe.
			$inputBlockOutput = $library === 'libwebp'
				? null
				: ( $options[$inputMimeType]['outputOptions'] ?? null );
			$outputOptions = $inputBlockOutput ?? $option['outputOptions'] ?? [];

			return new TransformOptions(
				$library,
				$libraries[$library]['command'],
				// inputOptions come from the input-MIME block.
				$options[$inputMimeType]['inputOptions'] ?? [],
				$outputOptions,
				!empty( $option['setcomment'] )
			);
		}
		return null;
	}
}
