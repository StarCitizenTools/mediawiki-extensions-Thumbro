<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

/** Result of one production-to-production comparison. */
class GateResult {
	/**
	 * @param Verdict $verdict
	 * @param string[] $reasons hard-constraint FAIL reasons
	 * @param string[] $flags soft-budget breach flags (recorded, not failing)
	 */
	public function __construct(
		public readonly Verdict $verdict,
		public readonly array $reasons,
		public readonly array $flags,
	) {
	}
}
