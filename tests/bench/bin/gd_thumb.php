<?php
declare( strict_types=1 );
// Usage: php gd_thumb.php <src> <targetWidth> <dst>
[ , $src, $w, $dst ] = $argv + [ null, null, null, null ];
if ( $src === null || $w === null || $dst === null ) {
	fwrite( STDERR, "usage: gd_thumb.php <src> <width> <dst>\n" );
	exit( 2 );
}
// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
$info = @getimagesize( $src );
if ( !$info ) {
	fwrite( STDERR, "unreadable image\n" );
	exit( 1 );
}
$img = match ( $info[2] ) {
	// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
	IMAGETYPE_JPEG => @imagecreatefromjpeg( $src ),
	// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
	IMAGETYPE_PNG  => @imagecreatefrompng( $src ),
	// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
	IMAGETYPE_GIF  => @imagecreatefromgif( $src ),
	default        => false,
};
if ( !$img ) {
	fwrite( STDERR, "unsupported type\n" );
	exit( 1 );
}
$tw = (int)$w;
$sw = imagesx( $img );
$sh = imagesy( $img );
if ( $tw < 1 || $sw < 1 || $sh < 1 ) {
	fwrite( STDERR, "invalid dimensions (tw=$tw sw=$sw sh=$sh)\n" );
	exit( 1 );
}
$th = max( 1, (int)round( $sh * ( $tw / $sw ) ) );
$thumb = imagescale( $img, $tw, $th, IMG_BICUBIC );
if ( $thumb === false ) {
	fwrite( STDERR, "imagescale failed\n" );
	exit( 1 );
}
if ( $info[2] === IMAGETYPE_PNG ) {
	imagealphablending( $thumb, false );
	imagesavealpha( $thumb, true );
}
$ok = match ( $info[2] ) {
	IMAGETYPE_JPEG => imagejpeg( $thumb, $dst, 80 ),
	IMAGETYPE_PNG  => imagepng( $thumb, $dst ),
	IMAGETYPE_GIF  => imagegif( $thumb, $dst ),
	default        => false,
};
exit( $ok ? 0 : 1 );
