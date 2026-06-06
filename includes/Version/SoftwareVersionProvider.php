<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Version;

/**
 * Reports the version of one image tool Thumbro can use, for Special:Version.
 *
 * One implementation per tool keeps the SoftwareInfo hook open for extension (adding a
 * backend adds a provider) and closed for modification.
 */
interface SoftwareVersionProvider {

	/** Wikitext label/link shown on Special:Version (the array key). */
	public function getLabel(): string;

	/** The tool's version string, or null when the tool is unavailable. */
	public function getVersion(): ?string;
}
