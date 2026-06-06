<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro;

use MediaWiki\Extension\Thumbro\Backend\BackendDispatcher;
use MediaWiki\Extension\Thumbro\Backend\CommandPlanRunner;
use MediaWiki\Extension\Thumbro\Backend\LibvipsBackend;
use MediaWiki\Extension\Thumbro\Backend\LibwebpBackend;
use MediaWiki\Extension\Thumbro\Backend\LibwebpSettings;
use MediaWiki\Extension\Thumbro\Image\ExifCommentWriter;
use MediaWiki\Extension\Thumbro\Image\VipsHeaderAlphaDetector;
use MediaWiki\Extension\Thumbro\Options\TransformOptionsResolver;
use MediaWiki\Extension\Thumbro\Shell\ShellCommandFactory;
use MediaWiki\Extension\Thumbro\Version\GdVersionProvider;
use MediaWiki\Extension\Thumbro\Version\ImageMagickVersionProvider;
use MediaWiki\Extension\Thumbro\Version\LibvipsVersionProvider;
use MediaWiki\Extension\Thumbro\Version\LibwebpVersionProvider;
use MediaWiki\MediaWikiServices;

/**
 * Service wiring for Thumbro. The thumbro Config (a GlobalVarConfig over the wg* globals)
 * supplies the binary paths and option blocks; everything downstream is constructor-injected
 * so the transform pipeline can be unit-tested without the service container.
 */
return [
	'Thumbro.ShellCommandFactory' => static function ( MediaWikiServices $services ): ShellCommandFactory {
		return new ShellCommandFactory( $services->getTempFSFileFactory() );
	},

	'Thumbro.AlphaDetector' => static function ( MediaWikiServices $services ): VipsHeaderAlphaDetector {
		$libraries = $services->getConfigFactory()->makeConfig( 'thumbro' )->get( 'ThumbroLibraries' );
		return new VipsHeaderAlphaDetector( $libraries['libvips']['command'] ?? '' );
	},

	'Thumbro.BackendDispatcher' => static function ( MediaWikiServices $services ): BackendDispatcher {
		return new BackendDispatcher(
			[
				'libvips' => $services->get( 'Thumbro.LibvipsBackend' ),
				'libwebp' => $services->get( 'Thumbro.LibwebpBackend' ),
			],
			$services->get( 'Thumbro.CommandPlanRunner' )
		);
	},

	'Thumbro.CommandPlanRunner' => static function ( MediaWikiServices $services ): CommandPlanRunner {
		return new CommandPlanRunner( $services->get( 'Thumbro.ExifCommentWriter' ) );
	},

	'Thumbro.ExifCommentWriter' => static function ( MediaWikiServices $services ): ExifCommentWriter {
		return new ExifCommentWriter(
			$services->getConfigFactory()->makeConfig( 'thumbro' )->get( 'Exiv2Command' )
		);
	},

	'Thumbro.LibvipsBackend' => static function ( MediaWikiServices $services ): LibvipsBackend {
		return new LibvipsBackend( $services->get( 'Thumbro.ShellCommandFactory' ) );
	},

	'Thumbro.LibwebpBackend' => static function ( MediaWikiServices $services ): LibwebpBackend {
		return new LibwebpBackend(
			$services->get( 'Thumbro.LibvipsBackend' ),
			$services->get( 'Thumbro.AlphaDetector' ),
			$services->get( 'Thumbro.ShellCommandFactory' ),
			$services->get( 'Thumbro.LibwebpSettings' )
		);
	},

	'Thumbro.LibwebpSettings' => static function ( MediaWikiServices $services ): LibwebpSettings {
		$config = $services->getConfigFactory()->makeConfig( 'thumbro' );
		$libraries = $config->get( 'ThumbroLibraries' );
		$gifOptions = $config->get( 'ThumbroOptions' )['image/gif'] ?? [];
		return new LibwebpSettings(
			$libraries['libwebp']['command'] ?? '',
			$gifOptions['outputOptions'] ?? [],
			// libvips is the primary backend and is always configured; '' would only surface
			// in a libwebp-without-libvips misconfiguration, which produces no thumbnail either way.
			$libraries['libvips']['command'] ?? '',
			(int)$config->get( 'ThumbroMaxAnimatedArea' )
		);
	},

	'Thumbro.OptionsResolver' => static function ( MediaWikiServices $services ): TransformOptionsResolver {
		return new TransformOptionsResolver( $services->getConfigFactory()->makeConfig( 'thumbro' ) );
	},

	'Thumbro.VersionProviders' => static function ( MediaWikiServices $services ): array {
		$libraries = $services->getConfigFactory()->makeConfig( 'thumbro' )->get( 'ThumbroLibraries' );
		return [
			new LibvipsVersionProvider(),
			new LibwebpVersionProvider( $libraries['libwebp']['command'] ?? '' ),
			new ImageMagickVersionProvider(),
			new GdVersionProvider(),
		];
	},
];
