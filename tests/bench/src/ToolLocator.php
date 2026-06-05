<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

use RuntimeException;

class ToolLocator {
	/** @var array<string,?string> */
	private static array $cache = [];

	/** Absolute path to a binary on PATH, or null if absent. */
	public static function path( string $bin ): ?string {
		if ( !array_key_exists( $bin, self::$cache ) ) {
			$out = [];
			$code = 1;
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
			exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null', $out, $code );
			self::$cache[$bin] = ( $code === 0 && isset( $out[0] ) && $out[0] !== '' ) ? $out[0] : null;
		}
		return self::$cache[$bin];
	}

	public static function require( string $bin, string $pkgHint ): string {
		$p = self::path( $bin );
		if ( $p === null ) {
			throw new RuntimeException( "Required tool '$bin' not found (install: $pkgHint)" );
		}
		return $p;
	}
}
