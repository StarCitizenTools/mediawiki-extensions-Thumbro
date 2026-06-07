<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Backend\Resize;

use MediaWiki\Extension\Thumbro\Backend\VipsOptionSuffix;
use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;

/**
 * libvips resizer (vipsthumbnail). Produces a temp intermediate at the target size, mirroring the
 * resize half of the former LibwebpBackend::planLibwebpEncode byte-for-byte.
 */
class VipsResizer implements Resizer {

	public function __construct(
		private readonly string $command,
	) {
	}

	public function planResize(
		ShellCommandFactory $factory,
		string $srcPath,
		array $loadOptions,
		string $physicalSize,
		string $intermediateFormat
	): ShellCommand {
		$command = $factory->create( 'libvips', $this->command, [ 'size' => $physicalSize ] );
		$command->setIO(
			$srcPath . VipsOptionSuffix::make( $loadOptions ),
			$intermediateFormat,
			ShellCommand::TEMP_OUTPUT
		);
		return $command;
	}
}
