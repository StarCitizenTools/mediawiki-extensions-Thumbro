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
	 * Advertise rotation support so MediaWiki auto-rotates EXIF-oriented images.
	 *
	 * getScalerType() above returns 'libvips', which core's BitmapHandler::canRotate()
	 * does not recognise (it only knows im/imext/gd) and so reports as false. That leaves
	 * autoRotateEnabled() false and getRotation() 0, so ExifBitmapHandler never swaps
	 * width/height for the EXIF Orientation tag: a portrait photo stored with landscape
	 * sensor dimensions then reports its raw (swapped) dimensions and renders at the wrong
	 * aspect ratio. vipsthumbnail auto-rotates from EXIF orientation itself, so rotation is
	 * genuinely supported here — we just have to tell core so.
	 *
	 * @see https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/issues/86
	 * @return bool
	 */
	public function canRotate() {
		return true;
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
				// Forwarded so MW 1.46's modifyClientThumbUrl() can still append the
				// request-provenance query param on this shortcut path, as core does.
				'requestProvenance' => $params['requestProvenance'] ?? null,
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
	 * Absorbs two core API changes made in MW 1.46 while still supporting 1.43–1.45:
	 *   - The array core passes here changed from the scaler params (clientWidth/clientHeight)
	 *     to the media-handler params (width/height). We read whichever is present so the
	 *     thumbnail keeps its on-page dimensions instead of collapsing to 0×0.
	 *   - File::getFilePageThumbUrl() was removed in favour of File::modifyClientThumbUrl(),
	 *     which performs the file-page cache-busting (and request-provenance) handling itself.
	 *
	 * @see TransformationalImageHandler::getClientScalingThumbnailImage
	 * @see https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/issues/85
	 *
	 * @inheritDoc
	 */
	protected function getClientScalingThumbnailImage( $image, $scalerParams ) {
		$params = [
			'width' => $scalerParams['clientWidth'] ?? $scalerParams['width'] ?? null,
			'height' => $scalerParams['clientHeight'] ?? $scalerParams['height'] ?? null,
		];

		$url = $image->getUrl();
		if ( method_exists( $image, 'modifyClientThumbUrl' ) ) {
			// MW 1.46+: mirror core by delegating the file-page/versioned URL logic to the file.
			// @phan-suppress-next-line PhanUndeclaredMethod modifyClientThumbUrl() added in MW 1.46
			$url = $image->modifyClientThumbUrl( $url, $scalerParams );
		} elseif ( !empty( $scalerParams['isFilePageThumb'] ) ) {
			// MW 1.43–1.45: append a cache-busting version parameter on file description pages.
			$url = $image->getFilePageThumbUrl( $url );
		}

		return new ThumbroThumbnailImage( $image, $url, null, $params );
	}
}
