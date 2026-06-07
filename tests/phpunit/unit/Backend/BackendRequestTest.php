<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Unit\Backend;

use File;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWikiUnitTestCase;
use TransformationalImageHandler;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\BackendRequest
 */
class BackendRequestTest extends MediaWikiUnitTestCase {

	private function request( array $paramsOverride = [] ): BackendRequest {
		$params = $paramsOverride + [
			'srcPath' => '/src.gif',
			'dstPath' => '/out.webp',
			'dstUrl' => 'http://wiki/out.webp',
			'clientWidth' => 84,
			'clientHeight' => 120,
			'physicalWidth' => 168,
			'physicalHeight' => 240,
			'comment' => 'hello',
		];
		return new BackendRequest(
			$this->createMock( TransformationalImageHandler::class ),
			$this->createMock( File::class ),
			$params,
			new TransformOptions( [], [ [ 'encoder' => 'vips-webp' ] ], false )
		);
	}

	public function testTypedAccessorsReadFromParams(): void {
		$request = $this->request();
		$this->assertSame( '/src.gif', $request->srcPath() );
		$this->assertSame( '/out.webp', $request->dstPath() );
		$this->assertSame( 'http://wiki/out.webp', $request->dstUrl() );
		$this->assertSame( 84, $request->clientWidth() );
		$this->assertSame( 120, $request->clientHeight() );
		$this->assertSame( '168x240', $request->physicalSize() );
		$this->assertSame( 'hello', $request->comment() );
	}

	public function testMissingCommentDefaultsToEmptyString(): void {
		$request = $this->request( [ 'comment' => null ] );
		$this->assertSame( '', $request->comment() );
	}
}
