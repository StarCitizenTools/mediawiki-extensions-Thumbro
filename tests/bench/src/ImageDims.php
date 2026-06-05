<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

class ImageDims {
	/** @return array{0:int,1:int} width,height of (first frame of) an image. */
	public static function of( string $path ): array {
		$vipsheader = ToolLocator::path( 'vipsheader' );
		if ( $vipsheader !== null ) {
			$w = (int)trim( Subprocess::run( [ $vipsheader, '-f', 'width', $path ] )->stdout );
			$h = (int)trim( Subprocess::run( [ $vipsheader, '-f', 'height', $path ] )->stdout );
			if ( $w > 0 && $h > 0 ) {
				return [ $w, $h ];
			}
		}
		$info = getimagesize( $path );
		if ( $info === false || (int)$info[0] < 1 || (int)$info[1] < 1 ) {
			throw new \RuntimeException( 'Cannot determine image dimensions for ' . $path );
		}
		return [ (int)$info[0], (int)$info[1] ];
	}
}
