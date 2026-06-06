<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Image;

use MediaWiki\Shell\Shell;

/**
 * Writes an EXIF/IPTC comment onto a generated thumbnail using exiv2.
 *
 * Replaces Utils::setEXIFComment — the exiv2 binary is injected instead of read from the
 * $wgExiv2Command global.
 *
 * @todo handle errors such as the exiv2 binary not being available.
 */
class ExifCommentWriter {

	public function __construct(
		private readonly string $exiv2Command,
	) {
	}

	public function write( string $fileName, string $comment ): void {
		Shell::command( $this->exiv2Command, 'mo', '-c', $comment, $fileName )
			->execute();
	}
}
