<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\MediaHandlers;

use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;
use TransformParameterError;

/**
 * @require-extends \TransformationalImageHandler
 */
trait ThumbroHandlerTrait {
	/**
	 * @inheritDoc
	 */
	protected function getScalerType( $dstPath, $checkDstPath = true ) {
		return 'libvips';
	}

	/**
	 * @inheritDoc
	 */
	public function getThumbType( $ext, $mime, $params = null ) {
		return [ 'webp', 'image/webp' ];
	}

	/**
	 * We need to override this method to return a ThumbroThumbnailImage instance.
	 * THe transform later flag can happen before the onBitmapHandlerTransform hook
	 *
	 * @see TransformationalImageHandler::doTransform
	 *
	 * @inheritDoc
	 */
	public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		// @phan-suppress-next-line PhanTraitParentReference parent provided by @require-extends
		if ( !( $flags & parent::TRANSFORM_LATER ) ) {
			// @phan-suppress-next-line PhanTraitParentReference parent provided by @require-extends
			return parent::doTransform( $image, $dstPath, $dstUrl, $params, $flags );
		}

		// @phan-suppress-next-line PhanUndeclaredMethod normaliseParams provided by @require-extends parent
		if ( !$this->normaliseParams( $image, $params ) ) {
			return new TransformParameterError( $params );
		}

		wfDebug( __METHOD__ . ": Transforming later per flags." );
		$newParams = [
			'width' => $params['width'],
			'height' => $params['height']
		];
		if ( isset( $params['quality'] ) ) {
			$newParams['quality'] = $params['quality'];
		}
		if ( isset( $params['page'] ) && $params['page'] ) {
			$newParams['page'] = $params['page'];
		}

		return new ThumbroThumbnailImage( $image, $dstUrl, false, $newParams );
	}

	/**
	 * We need to override this method to return a ThumbroThumbnailImage instance.
	 * Since this can happen before the onBitmapHandlerTransform hook
	 *
	 * @see TransformationalImageHandler::getClientScalingThumbnailImage
	 *
	 * @inheritDoc
	 */
	protected function getClientScalingThumbnailImage( $image, $scalerParams ) {
		$params = [
			'width' => $scalerParams['clientWidth'],
			'height' => $scalerParams['clientHeight']
		];

		$url = $image->getUrl();
		if ( isset( $scalerParams['isFilePageThumb'] ) && $scalerParams['isFilePageThumb'] ) {
			// Use a versioned URL on file description pages
			$url = $image->getFilePageThumbUrl( $url );
		}

		return new ThumbroThumbnailImage( $image, $url, null, $params );
	}
}
