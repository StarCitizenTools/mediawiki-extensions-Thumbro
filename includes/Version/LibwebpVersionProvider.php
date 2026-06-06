<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Version;

use MediaWiki\Shell\Shell;

/**
 * Reports the libwebp version via the configured gif2webp binary.
 *
 * gif2webp ships as part of libwebp and has no independent version; `gif2webp -version`
 * reports the libwebp library version, so this is surfaced as "libwebp" on Special:Version.
 */
class LibwebpVersionProvider implements SoftwareVersionProvider {

	public function __construct(
		private readonly string $gif2webpCommand,
	) {
	}

	public function getLabel(): string {
		return '[https://developers.google.com/speed/webp libwebp]';
	}

	public function getVersion(): ?string {
		if ( $this->gif2webpCommand === '' || !is_executable( $this->gif2webpCommand ) ) {
			return null;
		}
		$result = Shell::command( [ $this->gif2webpCommand, '-version' ] )->execute();
		if ( $result->getExitCode() !== 0 ) {
			return null;
		}
		// gif2webp -version prints e.g. "WebP Encoder version: 1.2.4" (the libwebp version) on line 1.
		$line = trim( strtok( $result->getStdout(), "\n" ) ?: '' );
		if ( preg_match( '/(\d+\.\d+\.\d+)/', $line, $matches ) ) {
			return $matches[1];
		}
		return null;
	}
}
