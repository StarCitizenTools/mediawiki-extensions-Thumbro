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

		// Mirror TransformationalImageHandler::doTransform()'s "return unscaled image" guard.
		// normaliseParams() clamps physicalWidth/Height down to the source size when the
		// requested size is larger (e.g. the responsive 1.5x/2x variants of an image that is
		// smaller than the requested density). In that case core serves the original file
		// rather than a thumbnail. Without this, the TRANSFORM_LATER path emits a thumb URL
		// (e.g. 144px-Foo.webp) that thumb_handler.php rejects with HTTP 400 when
		// $wgGenerateThumbnailOnParse = false. See issue #51.
		if (
			!$image->mustRender() &&
			$params['physicalWidth'] == $image->getWidth() &&
			$params['physicalHeight'] == $image->getHeight() &&
			( $params['quality'] ?? null ) !== 'low'
		) {
			return $this->getClientScalingThumbnailImage( $image, [
				'clientWidth' => $params['width'],
				'clientHeight' => $params['height'],
				'isFilePageThumb' => $params['isFilePageThumb'] ?? false,
			] );
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
