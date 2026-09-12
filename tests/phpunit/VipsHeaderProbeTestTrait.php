<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests;

use Psr\Log\LogLevel;
use TestLogger;

/**
 * Shared plumbing for the vipsheader probe tests: locating the real binaries, and capturing
 * what MediaWiki's shell layer logs while a probe runs.
 *
 * Both detectors opt their probe out of stderr logging (#104), so both need the same
 * "nothing reached the exec channel as an error" assertion — kept here in one shape so the
 * two test classes cannot drift apart on what that means.
 */
trait VipsHeaderProbeTestTrait {

	/** Absolute path to $name, or skip the test where it is genuinely absent. */
	private function bin( string $name ): string {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.shell_exec,MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$path = trim( (string)shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );
		if ( $path === '' ) {
			$this->markTestSkipped( "$name not available" );
		}
		return $path;
	}

	/**
	 * Collect everything MediaWiki's shell layer logs on the 'exec' channel.
	 *
	 * CommandFactory takes its logger once, at service-construction time, but
	 * MediaWikiIntegrationTestCase installs a fresh service container for every test and the
	 * factory is lazy, so overriding the channel before the first Shell::command() call is
	 * enough. {@see assertExecLogHasNoErrors} fails loudly if that ever stops holding.
	 */
	private function captureExecLog(): TestLogger {
		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'exec', $logger );
		return $logger;
	}

	/**
	 * Assert nothing reached the exec channel at ERROR or worse.
	 *
	 * The buffer check guards against a vacuous pass. Shellbox's UnboxedExecutor emits an
	 * info-level "Executing: …" from the same logger, in the same execute() call, as the error
	 * this suppresses — so a non-empty buffer proves the capture is live, and an empty one
	 * means the override stopped reaching CommandFactory rather than that the probe was quiet.
	 */
	private function assertExecLogHasNoErrors( TestLogger $logger, string $message = '' ): void {
		$this->assertNotSame( [], $logger->getBuffer(),
			'nothing was captured on the exec channel, so this assertion would pass vacuously' );
		$errors = array_values( array_filter(
			$logger->getBuffer(),
			static fn ( array $record ): bool => in_array(
				$record[0],
				[ LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY ],
				true
			)
		) );
		$this->assertSame( [], $errors, $message );
	}
}
