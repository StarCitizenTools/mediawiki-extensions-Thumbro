<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Tests\Integration\Backend;

use File;
use MediaTransformError;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Backend\CommandPlan;
use MediaWiki\Extension\Thumbro\Backend\CommandPlanRunner;
use MediaWiki\Extension\Thumbro\Image\ExifCommentWriter;
use MediaWiki\Extension\Thumbro\Options\TransformOptions;
use MediaWiki\Extension\Thumbro\Shell\ShellCommand;
use MediaWiki\Extension\Thumbro\ThumbroThumbnailImage;
use MediaWikiIntegrationTestCase;
use TransformationalImageHandler;

/**
 * @covers \MediaWiki\Extension\Thumbro\Backend\CommandPlanRunner
 * @group Thumbro
 */
class CommandPlanRunnerTest extends MediaWikiIntegrationTestCase {

	private function request(
		TransformationalImageHandler $handler,
		bool $setComment = false,
		string $comment = ''
	): BackendRequest {
		return new BackendRequest(
			$handler,
			$this->createMock( File::class ),
			[
				'srcPath' => '/src.gif',
				'dstPath' => '/out.webp',
				'dstUrl' => '/w/thumb/out.webp',
				'clientWidth' => 84,
				'clientHeight' => 120,
				'physicalWidth' => 84,
				'physicalHeight' => 120,
				'comment' => $comment,
			],
			new TransformOptions( 'libvips', '/usr/bin/vipsthumbnail', [], [], $setComment )
		);
	}

	private function command( int $exit, string $err = '' ): ShellCommand {
		$command = $this->createMock( ShellCommand::class );
		$command->method( 'execute' )->willReturn( $exit );
		$command->method( 'getErrorString' )->willReturn( $err );
		return $command;
	}

	public function testEmptyPlanReturnsTrueAndLeavesMtoUntouched(): void {
		$runner = new CommandPlanRunner( $this->createMock( ExifCommentWriter::class ) );
		$mto = null;
		$result = $runner->run( CommandPlan::empty(), $this->request( $this->createMock(
			TransformationalImageHandler::class
		) ), $mto );

		$this->assertTrue( $result );
		$this->assertNull( $mto );
	}

	public function testFailingCommandSetsErrorAndReturnsFalse(): void {
		$errorMto = $this->createMock( MediaTransformError::class );
		$handler = $this->createMock( TransformationalImageHandler::class );
		$handler->method( 'getMediaTransformError' )->willReturn( $errorMto );

		$runner = new CommandPlanRunner( $this->createMock( ExifCommentWriter::class ) );
		$mto = null;
		$result = $runner->run(
			CommandPlan::of( $this->command( 1, 'boom' ) ),
			$this->request( $handler ),
			$mto
		);

		$this->assertFalse( $result );
		$this->assertSame( $errorMto, $mto );
	}

	public function testSuccessBuildsThumbnailAndReturnsFalse(): void {
		$exif = $this->createMock( ExifCommentWriter::class );
		$exif->expects( $this->never() )->method( 'write' );

		$runner = new CommandPlanRunner( $exif );
		$mto = null;
		$result = $runner->run(
			CommandPlan::of( $this->command( 0 ) ),
			$this->request( $this->createMock( TransformationalImageHandler::class ) ),
			$mto
		);

		$this->assertFalse( $result );
		$this->assertInstanceOf( ThumbroThumbnailImage::class, $mto );
	}

	public function testSuccessWithCommentWritesExif(): void {
		$exif = $this->createMock( ExifCommentWriter::class );
		$exif->expects( $this->once() )->method( 'write' )->with( '/out.webp', 'a caption' );

		$runner = new CommandPlanRunner( $exif );
		$mto = null;
		$runner->run(
			CommandPlan::of( $this->command( 0 ) ),
			$this->request( $this->createMock( TransformationalImageHandler::class ), true, 'a caption' ),
			$mto
		);
	}

	public function testSetCommentWithoutCommentDoesNotWriteExif(): void {
		$exif = $this->createMock( ExifCommentWriter::class );
		$exif->expects( $this->never() )->method( 'write' );

		$runner = new CommandPlanRunner( $exif );
		$mto = null;
		$runner->run(
			CommandPlan::of( $this->command( 0 ) ),
			$this->request( $this->createMock( TransformationalImageHandler::class ), true, '' ),
			$mto
		);
	}
}
