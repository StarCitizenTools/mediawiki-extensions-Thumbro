<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro;

use MediaWiki\Extension\Thumbro\Backend\CommandPlanRunner;
use MediaWiki\Extension\Thumbro\Backend\EncodePipeline;
use MediaWiki\Extension\Thumbro\Backend\Encoder\CwebpEncoder;
use MediaWiki\Extension\Thumbro\Backend\Encoder\EncoderRouter;
use MediaWiki\Extension\Thumbro\Backend\Encoder\Gif2webpEncoder;
use MediaWiki\Extension\Thumbro\Backend\Encoder\VipsWebpEncoder;
use MediaWiki\Extension\Thumbro\Backend\Resize\VipsResizer;
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

	'Thumbro.CommandPlanRunner' => static function ( MediaWikiServices $services ): CommandPlanRunner {
		return new CommandPlanRunner( $services->get( 'Thumbro.ExifCommentWriter' ) );
	},

	'Thumbro.ExifCommentWriter' => static function ( MediaWikiServices $services ): ExifCommentWriter {
		return new ExifCommentWriter(
			$services->getConfigFactory()->makeConfig( 'thumbro' )->get( 'Exiv2Command' )
		);
	},

	/**
	 * The available encoders, keyed by the name used in a MIME's `encode` list. Each carries its
	 * own binary: vips-webp uses libvips; gif2webp and cwebp are the two libwebp tools.
	 */
	'Thumbro.Encoders' => static function ( MediaWikiServices $services ): array {
		$libraries = $services->getConfigFactory()->makeConfig( 'thumbro' )->get( 'ThumbroLibraries' );
		return [
			'vips-webp' => new VipsWebpEncoder( $libraries['libvips']['command'] ?? '' ),
			'gif2webp' => new Gif2webpEncoder( $libraries['libwebp']['command'] ?? '' ),
			'cwebp' => new CwebpEncoder( $libraries['cwebp']['command'] ?? '' ),
		];
	},

	'Thumbro.VipsResizer' => static function ( MediaWikiServices $services ): VipsResizer {
		$libraries = $services->getConfigFactory()->makeConfig( 'thumbro' )->get( 'ThumbroLibraries' );
		return new VipsResizer( $libraries['libvips']['command'] ?? '' );
	},

	'Thumbro.EncoderRouter' => static function ( MediaWikiServices $services ): EncoderRouter {
		return new EncoderRouter();
	},

	'Thumbro.EncodePipeline' => static function ( MediaWikiServices $services ): EncodePipeline {
		$config = $services->getConfigFactory()->makeConfig( 'thumbro' );
		return new EncodePipeline(
			$services->get( 'Thumbro.Encoders' ),
			$services->get( 'Thumbro.VipsResizer' ),
			$services->get( 'Thumbro.EncoderRouter' ),
			$services->get( 'Thumbro.AlphaDetector' ),
			$services->get( 'Thumbro.ShellCommandFactory' ),
			(int)$config->get( 'ThumbroMaxAnimatedArea' ),
			$services->get( 'Thumbro.CommandPlanRunner' )
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
