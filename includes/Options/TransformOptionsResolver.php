<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Options;

use File;
use MediaWiki\Config\Config;
use TransformationalImageHandler;

/**
 * Resolves whether Thumbro should transform a file and, if so, with which options.
 *
 * Behaviour is ported verbatim from the former Utils::getOptions, including its quirk: every
 * Thumbro handler's getThumbType() reports image/webp, so the matched ThumbroOptions block is
 * always the image/webp one — only `library` and `inputOptions` are taken from the input-MIME
 * block, while `outputOptions` (and the enabled/minArea/maxArea gate) come from the webp block.
 * That quirk is preserved here; fixing it is a separate, non-behaviour-preserving change.
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

			return new TransformOptions(
				$library,
				$libraries[$library]['command'],
				// inputOptions come from the input-MIME block; outputOptions from the matched
				// (webp) block — preserving the documented quirk.
				$options[$inputMimeType]['inputOptions'] ?? [],
				$option['outputOptions'] ?? [],
				!empty( $option['setcomment'] )
			);
		}
		return null;
	}
}
