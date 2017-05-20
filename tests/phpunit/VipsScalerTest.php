<?php
class VipsScalerTest extends MediaWikiMediaTestCase {

	private $handler;
	function setUp() {
		parent::setUp();
		$this->handler = new BitmapHandler;
	}

	/**
	 * @dataProvider shrinkCommandProvider
	 * @param Array $params Thumbnailing parameters
	 * @param String $type Mime type
	 * @param Array $expectedCommands
	 */
	function testShrinkCommand( $params, $type, $expectedCommands ) {
		// This file doesn't neccesarily need to actually exist
		$fakeFile = $this->dataFile( "non-existent", $type );
		$actualCommands = VipsScaler::makeCommands( $this->handler, $fakeFile, $params, [] );
		$this->assertEquals( $expectedCommands, $actualCommands );
	}

	function shrinkCommandProvider() {
		global $wgVipsCommand;
		$paramBase = [
			'comment' => '',
			'srcWidth' => 2048,
			'srcHeight' => 1536,
			'mimeType' => 'image/tiff',
			'dstPath' => '/tmp/fake/thumb/path.jpg',
			'dstUrl' => 'path.jpg',
			'physicalWidth' => '1024',
			'physicalHeight' => '768',
			'clientWidth' => '1024',
			'clientHeight' => '768',
		];
		return [
			[
				$paramBase,
				'image/tiff',
				[
					new VipsCommand( $wgVipsCommand, [ 'shrink', 2, 2 ] )
				]
			],
			[
				$paramBase,
				'image/png',
				[
					new VipsCommand( $wgVipsCommand, [ 'shrink', 2, 2 ] )
				]
			],
			[
				[ 'page' => 3 ] + $paramBase,
				'image/tiff',
				[
					new VipsCommand( $wgVipsCommand, [ 'shrink', 2, 2 ] )
				]
			],
			[
				[ 'physicalWidth' => 1065 ] + $paramBase,
				'image/tiff',
				[
					new VipsCommand( $wgVipsCommand, [ 'im_shrink', $this->calcScale( 2048, 1065 ),
						$this->calcScale( 1536, 768 ) ] )
				]
			],
			[
				[ 'physicalHeight' => 1065 ] + $paramBase,
				'image/tiff',
				[
					new VipsCommand( $wgVipsCommand, [ 'im_shrink', $this->calcScale( 2048, 1024 ),
						$this->calcScale( 1536, 1065 ) ] )
				]
			],
			[
				[ 'physicalWidth' => 1065, 'page' => 5 ] + $paramBase,
				'image/tiff',
				[
					new VipsCommand( $wgVipsCommand, [ 'im_shrink', $this->calcScale( 2048, 1065 ),
						$this->calcScale( 1536, 768 ) ] )
				]
			],
			[
				[ 'physicalWidth' => 1065 ] + $paramBase,
				'image/png',
				[
					new VipsCommand( $wgVipsCommand, [ 'shrink', $this->calcScale( 2048, 1065 ),
						$this->calcScale( 1536, 768 ) ] )
				]
			],
		];
	}

	private function calcScale( $srcDim, $finalDim ) {
		return sprintf( "%.18e", $srcDim / ( $finalDim + 0.125 ) );
	}
}
