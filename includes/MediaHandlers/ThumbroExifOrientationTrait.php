<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\MediaHandlers;

/**
 * EXIF-orientation handling for the BitmapHandler-based handlers (WebP, PNG).
 *
 * Unlike JpegHandler/ExifBitmapHandler, core's WebPHandler and PNGHandler never translate an
 * EXIF Orientation tag into a width/height swap — WebP stashes it under a nested metadata key
 * and PNG does not read it at all. vipsthumbnail, however, auto-rotates every format from EXIF
 * by default, so without this an oriented WebP/PNG is resized against its raw (unswapped)
 * dimensions and served at the wrong aspect ratio: the issue #86 class of bug, but for formats
 * canRotate() cannot reach (they have no ExifBitmapHandler to consume a non-zero rotation).
 *
 * We swap the reported dimensions for the 90°/270° cases and cache the applied angle so
 * getRotation() stays cheap (mustRender() calls it during parsing). Where the orientation comes
 * from is the implementing handler's business ({@see thumbroSourceOrientation}) — WebP already
 * has it in metadata; PNG must probe libvips. Both the swap and getRotation() are gated on
 * autoRotateEnabled(), mirroring ExifBitmapHandler.
 *
 * Known limitation: vipsthumbnail auto-rotates unconditionally (it is a libvips setting, not a
 * MediaWiki one), so setting $wgEnableAutoRotation = false does not stop the pixels being rotated
 * — it only stops the dimension swap here, reintroducing the mismatch. This matches how the JPEG
 * path behaves under Thumbro and is not something this seam can fix without passing vips a
 * no-rotate flag; the default ($wgEnableAutoRotation = null) is unaffected.
 *
 * @require-extends \BitmapHandler
 */
trait ThumbroExifOrientationTrait {

	/**
	 * @inheritDoc
	 */
	public function getSizeAndMetadata( $state, $filename ) {
		// @phan-suppress-next-line PhanTraitParentReference parent provided by @require-extends
		$info = parent::getSizeAndMetadata( $state, $filename );

		// Broken-file results carry no dimensions — nothing to rotate.
		if ( !is_array( $info ) || !isset( $info['width'] ) || !isset( $info['height'] ) ) {
			return $info;
		}
		// Only swap when autorotation is on, mirroring ExifBitmapHandler::applyExifRotation.
		// @phan-suppress-next-line PhanUndeclaredMethod autoRotateEnabled from @require-extends parent
		if ( !$this->autoRotateEnabled() ) {
			return $info;
		}

		$rotation = $this->thumbroExifRotation( $info, $filename );
		if ( $rotation === 0 ) {
			return $info;
		}
		$info['metadata'][self::rotationMetaKey()] = $rotation;
		if ( $rotation === 90 || $rotation === 270 ) {
			[ $info['width'], $info['height'] ] = [ $info['height'], $info['width'] ];
		}
		return $info;
	}

	/**
	 * @inheritDoc
	 */
	public function getRotation( $file ) {
		// @phan-suppress-next-line PhanUndeclaredMethod autoRotateEnabled from @require-extends parent
		if ( !$this->autoRotateEnabled() ) {
			return 0;
		}
		return (int)( $file->getMetadataArray()[self::rotationMetaKey()] ?? 0 );
	}

	/**
	 * Metadata key caching the rotation (degrees) libvips will apply, read back by getRotation().
	 *
	 * A method rather than a trait constant on purpose: trait constants require PHP 8.2, but
	 * MediaWiki 1.43 still supports PHP 8.1, where a trait constant is a fatal parse error.
	 */
	private static function rotationMetaKey(): string {
		return '_thumbro_exif_rotation';
	}

	/**
	 * The rotation (degrees) libvips will auto-apply to the source, mapped from its EXIF
	 * orientation. Mirrors ExifBitmapHandler's orientation-to-angle mapping.
	 */
	private function thumbroExifRotation( array $info, string $filename ): int {
		switch ( $this->thumbroSourceOrientation( $info, $filename ) ) {
			case 8:
				return 90;
			case 3:
				return 180;
			case 6:
				return 270;
			default:
				return 0;
		}
	}

	/**
	 * The source's EXIF orientation (1-8), for the metadata just computed by parent.
	 *
	 * How this is obtained differs by format and must NOT be inferred from metadata shape: a plain
	 * WebP with no EXIF and a PNG both lack the nested key, yet only WebP's core class actually
	 * parses orientation. So each handler declares its own source — WebP reads the value core
	 * already extracted (free); PNG, which core never parses, probes libvips (one short-lived
	 * vipsheader process, run only here at upload/refreshImageMetadata and cached in img_metadata,
	 * so the thumbnail and page-view paths never reach it).
	 *
	 * @param array $info The size+metadata array returned by parent::getSizeAndMetadata().
	 * @param string $filename Local source path.
	 * @return int EXIF orientation 1-8 (1 = upright / unknown).
	 */
	abstract protected function thumbroSourceOrientation( array $info, string $filename ): int;
}
