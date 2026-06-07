<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Encoder;

use InvalidArgumentException;

/** Immutable per-file routing inputs: the facts the encode-list `when` guards match against. */
class FileTraits {
	public function __construct(
		public readonly bool $animated,
		public readonly bool $hasAlpha,
		public readonly bool $underThreshold,
	) {
	}

	/**
	 * Does this trait set satisfy a `when` guard (a subset of animated|alpha|underThreshold => bool)?
	 *
	 * @param array<string,bool> $when
	 */
	public function satisfies( array $when ): bool {
		foreach ( $when as $key => $required ) {
			// An unknown key is a config typo (e.g. 'animted'); fail loudly rather than
			// silently mis-routing — a guard that can never match the intended trait.
			$actual = match ( $key ) {
				'animated' => $this->animated,
				'alpha' => $this->hasAlpha,
				'underThreshold' => $this->underThreshold,
				default => throw new InvalidArgumentException( "Unknown encode-list `when` key: $key" ),
			};
			if ( $actual !== $required ) {
				return false;
			}
		}
		return true;
	}
}
