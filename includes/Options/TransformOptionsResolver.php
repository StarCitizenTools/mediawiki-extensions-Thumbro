<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Options;

use File;
use MediaWiki\Config\Config;
use TransformationalImageHandler;

/**
 * Resolves whether Thumbro should transform a file and, if so, with which options.
 *
 * The config is keyed by INPUT MIME type: each block declares its own `enabled`/`minArea`/
 * `maxArea` gate, a `resize` stage, and an ordered `encode` list. The output is always WebP (a
 * property of the encoders), so — unlike the former resolver — this no longer matches on the
 * output MIME or carries any per-library special-case: it just reads the input-MIME block and
 * hands the encode list on verbatim for the pipeline to route.
 */
class TransformOptionsResolver {

	public function __construct(
		private readonly Config $config,
	) {
	}

	public function resolve( TransformationalImageHandler $handler, File $file ): ?TransformOptions {
		$options = $this->config->get( 'ThumbroOptions' );
		$block = $options[$file->getMimeType()] ?? null;
		if ( $block === null ) {
			return null;
		}

		if ( ( $block['enabled'] ?? false ) !== true ) {
			return null;
		}

		// Multi-page files are not supported.
		if ( $file->isMultipage() ) {
			return null;
		}

		$area = $handler->getImageArea( $file );
		if ( isset( $block['minArea'] ) && $area < $block['minArea'] ) {
			return null;
		}
		if ( isset( $block['maxArea'] ) && $area >= $block['maxArea'] ) {
			return null;
		}

		$encodeList = $block['encode'] ?? [];
		if ( $encodeList === [] ) {
			return null;
		}

		return new TransformOptions(
			$block['resize']['options'] ?? [],
			$encodeList,
			!empty( $block['setcomment'] )
		);
	}
}
