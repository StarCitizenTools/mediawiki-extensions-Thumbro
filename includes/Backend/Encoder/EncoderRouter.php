<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Encoder;

use RuntimeException;

/**
 * Pure encode-list router. Selects the first encode-list entry whose `when` capability guard is
 * satisfied by the file's {@see FileTraits}; an entry with no `when` is the catch-all. Replaces
 * the hardcoded LibwebpBackend::chooseStrategy with config-visible routing.
 */
class EncoderRouter {
	/**
	 * @param array<int,array{encoder:string,when?:array<string,bool>,options?:array<string,string>}> $encodeList
	 */
	public function choose( array $encodeList, FileTraits $traits ): EncodeChoice {
		foreach ( $encodeList as $entry ) {
			$when = $entry['when'] ?? [];
			if ( $traits->satisfies( $when ) ) {
				return new EncodeChoice( $entry['encoder'], $when, $entry['options'] ?? [] );
			}
		}
		throw new RuntimeException( 'No encode-list entry matched and no catch-all present' );
	}
}
