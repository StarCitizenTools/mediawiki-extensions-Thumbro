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
// Match MediaWiki's GD scaling algorithm (imagecopyresampled into a truecolor canvas, as in
// BitmapHandler::transformGd). imagescale(IMG_BICUBIC) produces structurally shifted output
// that the quality metric (correctly) rejects and does not reflect a default install.
$thumb = imagecreatetruecolor( $tw, $th );
if ( $thumb === false ) {
	fwrite( STDERR, "imagecreatetruecolor failed\n" );
	exit( 1 );
}
if ( $info[2] === IMAGETYPE_PNG ) {
	// Preserve alpha: turn off blending and pre-fill the canvas fully transparent so the
	// resample carries the source alpha through instead of compositing onto black.
	imagealphablending( $thumb, false );
	imagesavealpha( $thumb, true );
	$transparent = imagecolorallocatealpha( $thumb, 0, 0, 0, 127 );
	imagefilledrectangle( $thumb, 0, 0, $tw, $th, $transparent );
}
imagecopyresampled( $thumb, $img, 0, 0, 0, 0, $tw, $th, $sw, $sh );
$ok = match ( $info[2] ) {
	IMAGETYPE_JPEG => imagejpeg( $thumb, $dst, 80 ),
	IMAGETYPE_PNG  => imagepng( $thumb, $dst ),
	IMAGETYPE_GIF  => imagegif( $thumb, $dst ),
	default        => false,
};
exit( $ok ? 0 : 1 );
