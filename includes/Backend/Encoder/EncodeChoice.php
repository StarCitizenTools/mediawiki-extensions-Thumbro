<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Encoder;

/** The resolved encode-list entry the router selected for a file. */
class EncodeChoice {
	/**
	 * @param string $encoder
	 * @param array<string,bool> $when
	 * @param array<string,string> $options
	 */
	public function __construct(
		public readonly string $encoder,
		public readonly array $when,
		public readonly array $options,
	) {
	}
}
