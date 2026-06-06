<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Options;

use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Thumbro\Options\TransformOptions
 */
class TransformOptionsTest extends MediaWikiUnitTestCase {

	public function testExposesAccessors(): void {
		$options = new TransformOptions(
			'libvips', '/usr/bin/vipsthumbnail', [ 'n' => '-1' ], [ 'Q' => '90' ], true
		);
		$this->assertSame( 'libvips', $options->library() );
		$this->assertSame( '/usr/bin/vipsthumbnail', $options->command() );
		$this->assertSame( [ 'n' => '-1' ], $options->inputOptions() );
		$this->assertSame( [ 'Q' => '90' ], $options->outputOptions() );
		$this->assertTrue( $options->setComment() );
	}

	public function testWithDelegatedOverridesButKeepsSetComment(): void {
		$options = new TransformOptions( 'libwebp', '/usr/bin/gif2webp', [], [ 'Q' => '90' ], true );
		$delegated = $options->withDelegated(
			'libvips', '/usr/bin/vipsthumbnail', [ 'n' => '1' ], [ 'strip' => 'true' ]
		);

		$this->assertSame( 'libvips', $delegated->library() );
		$this->assertSame( '/usr/bin/vipsthumbnail', $delegated->command() );
		$this->assertSame( [ 'n' => '1' ], $delegated->inputOptions() );
		$this->assertSame( [ 'strip' => 'true' ], $delegated->outputOptions() );
		$this->assertTrue( $delegated->setComment() );
		// Original is unchanged (immutability).
		$this->assertSame( 'libwebp', $options->library() );
	}
}
