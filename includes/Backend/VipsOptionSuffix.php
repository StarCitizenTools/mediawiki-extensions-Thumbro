<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend;

/**
 * Formats a vips load/save options array as the vipsthumbnail "[key=value,...]" path suffix.
 * Shared by the vips resizer and the vips-webp encoder so the formatting lives in one place.
 */
class VipsOptionSuffix {

	private function __construct() {
		// Static utility; not instantiable.
	}

	/**
	 * @param array<string,string> $args
	 * @return string The "[k=v,...]" suffix, or "" for an empty array.
	 */
	public static function make( array $args ): string {
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
