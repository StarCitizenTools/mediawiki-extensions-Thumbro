<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Encoder;

use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;

/**
 * A WebP encoder. Each encoder owns its own option formatting and declares its capabilities so
 * routing and the resize/encode wiring are explicit, not hidden in the coordinator.
 *
 * `requiresResizedInput()` is the explicit fused-vs-two-step flag: vips-webp resizes and encodes
 * in one command (false); external tools (gif2webp, cwebp) consume a vips-produced
 * intermediate (true), whose format `intermediateFormat()` names.
 */
interface Encoder {
	public function name(): string;

	/** Whether this encoder's binary is usable (drops it from routing when its tool is absent). */
	public function isAvailable(): bool;

	public function supportsAnimation(): bool;

	public function supportsAlpha(): bool;

	public function requiresResizedInput(): bool;

	/** Intermediate format consumed when requiresResizedInput() (e.g. 'png','gif'); null if fused. */
	public function intermediateFormat(): ?string;

	/**
	 * Build the encode command for the given {@see EncodeInput}: a fused encoder reads
	 * srcPath/physicalSize/loadOptions; a two-step encoder reads the prior resize command.
	 * $options is this encoder's own option bag.
	 *
	 * @param ShellCommandFactory $factory
	 * @param EncodeInput $input
	 * @param string $dstPath
	 * @param array<string,string> $options
	 */
	public function planEncode(
		ShellCommandFactory $factory, EncodeInput $input, string $dstPath, array $options
	): ShellCommand;
}
