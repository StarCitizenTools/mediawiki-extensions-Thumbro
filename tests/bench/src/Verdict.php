<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

enum Verdict: string {
	case PASS = 'PASS';
	case FAIL = 'FAIL';
	case INCOMPARABLE = 'INCOMPARABLE';
}
