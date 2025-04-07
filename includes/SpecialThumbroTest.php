<?php
/*
 * Copyright © Bryan Tong Minh, 2011
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extension\Thumbro;

use Imagick;
use MediaTransformOutput;
use MediaWiki\Extension\Thumbro\MediaHandlers;
use MediaWiki\Extension\Thumbro\Libraries\Libvips;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\HTMLForm\Field\HTMLIntField;
use MediaWiki\HTMLForm\Field\HTMLTextField;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\StreamFile;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MWHttpRequest;
use OOUI\FieldsetLayout;
use OOUI\HtmlSnippet;
use OOUI\LabelWidget;
use OOUI\PanelLayout;
use PermissionsError;

/**
 * A Special page intended to test Thumbro.
 * @author Bryan Tong Minh
 */
class SpecialThumbroTest extends SpecialPage {

	public function __construct() {
		parent::__construct( 'ThumbroTest', 'thumbro-test' );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'media';
	}

	/**
	 * @inheritDoc
	 */
	public function userCanExecute( User $user ): bool {
		return $this->getConfig()->get( 'ThumbroExposeTestPage' ) && parent::userCanExecute( $user );
	}

	/**
	 * @inheritDoc
	 */
	public function displayRestrictionError(): void {
		if ( !$this->getConfig()->get( 'ThumbroExposeTestPage' ) ) {
			throw new PermissionsError(
				null,
				[ 'querypage-disabled' ]
			);
		}

		parent::displayRestrictionError();
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ): void {
		$request = $this->getRequest();
		$this->setHeaders();

		$isInternalRequest = $request->getHeader( 'X-Thumbro-Secret' ) === $this->getConfig()->get( MainConfigNames::SecretKey );
		$isUserAllowed = $this->userCanExecute( $this->getUser() );

		if ( !$isUserAllowed && !$isInternalRequest ) {
			$this->displayRestrictionError();
		}

		if ( $request->getText( 'thumb' ) ) {
			$this->streamThumbnail();
		} else {
			$this->showForm();
		}
	}

	/**
	 * Render the generated thumbnails panel
	 */
	protected function showThumbnails(): void {
		$request = $this->getRequest();
		$this->getOutput()->enableOOUI();
		// Check if there is any input
		if ( !( $request->getText( 'file' ) ) ) {
			return;
		}

		// Check if valid file was provided
		$title = Title::newFromText( $request->getText( 'file' ), NS_FILE );
		if ( $title === null ) {
			$this->getOutput()->addWikiMsg( 'thumbro-invalid-file' );
			return;
		}
		$services = MediaWikiServices::getInstance();
		$file = $services->getRepoGroup()->findFile( $title );
		if ( !$file || !$file->exists() ) {
			$this->getOutput()->addWikiMsg( 'thumbro-invalid-file' );
			return;
		}

		// Create options
		$width = $request->getInt( 'width' );
		if ( !$width ) {
			$this->getOutput()->addWikiMsg( 'thumbro-invalid-width' );
			return;
		}
		$vipsUrlOptions = [ 'thumb' => $file->getName(), 'width' => $width ];
		/*
		if ( $request->getRawVal( 'sharpen' ) !== null ) {
			$vipsUrlOptions['sharpen'] = $request->getFloat( 'sharpen' );
		}
		if ( $request->getBool( 'bilinear' ) ) {
			$vipsUrlOptions['bilinear'] = 1;
		}
		*/

		// Generate normal thumbnail
		$params = [ 'width' => $width ];
		$thumb = $file->transform( $params );
		if ( !$thumb || $thumb->isError() ) {
			$this->getOutput()->addWikiMsg( 'thumbro-thumb-error' );
			return;
		}

		$imageUrls = [
			'original' => $file->getFullUrl(),
			'normal' => $services->getUrlUtils()->expand( $thumb->getUrl() ),
			'thumbro' => $this->getPageTitle()->getFullUrl( $vipsUrlOptions )
		];

		// Check if we actually scaled the file
		if ( $imageUrls['normal'] === $imageUrls['original'] ) {
			$this->getOutput()->addWikiMsg( 'thumbro-thumb-notscaled' );
		}

		$thumbWidth = $thumb->getWidth();
		$thumbHeight = $thumb->getHeight();

		$imgs = [];
		foreach( $imageUrls as $type => $url ) {
			$imgs[$type] = Html::element( 'img', [
				'src' => $url,
				'alt' => $this->msg( "thumbro-$type-image" )->text(),
				'width' => $thumbWidth,
				'height' => $thumbHeight
			] );
		}

		// HTML for the thumbnails
		$thumbs = new HtmlSnippet( Html::rawElement( 'div', [ 'class' => 'mw-thumbrotest-thumbnails' ],
			$imgs['normal'] . $imgs['thumbro']
		) );

		$thumbsOrigCurr = new HtmlSnippet( Html::rawElement( 'div', [ 'class' => 'mw-thumbrotest-thumbnails' ],
			$imgs['original'] . $imgs['normal']
		) );

		$thumbsOrigThumbro = new HtmlSnippet( Html::rawElement( 'div', [ 'class' => 'mw-thumbrotest-thumbnails' ],
			$imgs['original'] . $imgs['thumbro']
		) );

		// Helper messages shown above the thumbnails rendering
		$form = [
			new LabelWidget( [ 'label' => $this->msg( 'thumbro-thumbs-help' )->text() ] )
		];

		$fieldset = new FieldsetLayout( [
			'label' => $this->msg( 'thumbro-thumbs-legend' )->text(),
			'items' => $form,
		] );

		// Need Imagick to output comparison data
		if ( extension_loaded( 'imagick' ) ) {
			// Debug stuff to work around Docker localhost HTTP request issue
			foreach( $imageUrls as $type => $url ) {
				$imageUrls[$type] = str_replace( 'localhost', '172.18.0.4', $url );
			}

			$imagesInfo = $this->getImagesInfo( $imageUrls );

			$infoHtml = '';
			foreach( $imagesInfo as $type => $info ) {
				$infoHtml .= "<div><div>$type</div><ul>";
				foreach( $info as $key => $value ) {
					$infoHtml .= "<li>$key: $value</li>";
				}
				$infoHtml .= '</ul></div>';
			}

			$infoHtml = new HtmlSnippet( "<div style='display: grid; grid-template-columns:1fr 1fr 1fr; gap: 8px;'>$infoHtml</div>" );
			$this->getOutput()->addHTML(
				new PanelLayout( [
					'expanded' => false,
					'padded' => true,
					'framed' => true,
					'content' => [ $infoHtml ],
				] )
			);
		}

		$this->getOutput()->addHTML(
			new PanelLayout( [
				'expanded' => false,
				'padded' => true,
				'framed' => true,
				'content' => [ $fieldset , $thumbs, $thumbsOrigCurr, $thumbsOrigThumbro ],
			] )
		);

		$this->getOutput()->addModules( [ 'ext.thumbro' ] );
	}

	/**
	 * Return the information for display for both images
	 */
	private function getImagesInfo( array $imageUrls ): array {
		$images = [];
		foreach ( $imageUrls as $type => $url ) {
			$req = $this->getImageRequest( $url );
			$image = new Imagick();
			$image->readImageBlob( $req->getContent() );
			$images[$type] = $image;
		}

		// Resize the original image to match the thumbnail's dimensions for more accurate comparison
		$images['original']->resizeImage(
			$images['normal']->getImageWidth(),
			$images['normal']->getImageHeight(),
			Imagick::FILTER_LANCZOS, 1
		);

		$info = [];
		foreach( $images as $type => $image ) {
			if ( $type === 'original' ) {
				continue;
			}

			$info[$type] = [
				'Width' => $image->getImageWidth(),
				'Height' => $image->getImageHeight(),
				'Type' => $image->getImageMimeType(),
				'Size' => $this->humanFileSize( $image->getImageLength() ),
				'PSNR' => $image->compareImages( $images['original'], Imagick::METRIC_PEAKSIGNALTONOISERATIO )[1]
			];

			// ImageMagick 6.9.0+
			if ( defined( 'Imagick::METRIC_PERCEPTUALHASH_ERROR' ) ) {
				$info[$type]['Perceptual Hash'] = $image->compareImages( $images['original'], Imagick::METRIC_PERCEPTUALHASH_ERROR )[1];
			}

			// ImageMagick 7.0.7
			if ( defined( 'Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR' ) ) {
				$info[$type]['SSIM'] = $image->compareImages( $images['original'], Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR )[1];
			}
		}
		return $info;
	}

	/**
	 * Return the metric of Imagick::compareImages if avaliable
	 */
	private function getImageMetric( Imagick $image, string $metric ): ?string {
		if ( defined( $metric ) ) {
			return $image->compareImages( $image, $metric )[1];
		}
	}

	/**
	 * Return the MWHttpRequest object if the request is successful
	 */
	private function getImageRequest( string $url ): ?MWHttpRequest {
		$httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$req = $httpRequestFactory->create( $url, [], __METHOD__ );
		$req->setHeader( 'X-Thumbro-Secret', $this->getConfig()->get( MainConfigNames::SecretKey ) );
		$result = $req->execute();
		if ( !$result->isGood() ) {
			return null;
		}
		return $req;
	}

	/**
	 * Format bytes into human-readable file size
	 */
	private function humanFileSize( int $bytes, ?int $decimals = 2 ): string {
		$size = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
		$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );
		return sprintf( "%.{$decimals}f %s",
			$bytes / ( 1000 ** $factor ),
			$size[$factor]
		);
	}

	/**
	 * Special:ThumbroTest form HTML
	 */
	protected function showForm(): void {
		$form = HTMLForm::factory( 'ooui', $this->getFormFields(), $this->getContext() );
		$form->setWrapperLegend( $this->msg( 'thumbro-form-legend' )->text() );
		$form->setSubmitText( $this->msg( 'thumbro-form-submit' )->text() );
		$form->setSubmitCallback( [ __CLASS__, 'processForm' ] );
		$form->setMethod( 'get' );

		// Looks like HTMLForm does not actually show the form if submission
		// was correct. So we have to show it again.
		// See HTMLForm::show()
		$result = $form->show();
		if ( $result === true || ( $result instanceof Status && $result->isGood() ) ) {
			$form->displayForm( $result );
			$this->showThumbnails();
		}
	}

	/**
	 * Special:ThumbroTest form structure for HTMLForm
	 */
	protected function getFormFields(): array {
		$fields = [
			'File' => [
				'name'          => 'file',
				'class'         => HTMLTextField::class,
				'required'      => true,
				'size' 			=> '80',
				'label-message' => 'thumbro-form-file',
				'validation-callback' => [ __CLASS__, 'validateFileInput' ],
			],
			'Width' => [
				'name'          => 'width',
				'class'         => HTMLIntField::class,
				'default'       => '640',
				'size'          => '5',
				'required'      => true,
				'label-message' => 'thumbro-form-width',
				'validation-callback' => [ __CLASS__, 'validateWidth' ],
			],
			/*
			'SharpenRadius' => [
				'name'          => 'sharpen',
				'class'         => HTMLFloatField:class,
				'default'		=> '0.0',
				'size'			=> '5',
				'label-message' => 'thumbro-form-sharpen-radius',
				'validation-callback' => [ __CLASS__, 'validateSharpen' ],
			],
			'Bilinear' => [
				'name' 			=> 'bilinear',
				'class' 		=> HTMLCheckField::class,
				'label-message'	=> 'thumbro-form-bilinear',
			],
			*/
		];

		/**
		 * Match ImageMagick by default
		 */
		/*
		if ( preg_match( '/^[0-9.]+x([0-9.]+)$/', $this->getConfig()->get( 'SharpenParameter' ), $m ) ) {
			$fields['SharpenRadius']['default'] = $m[1];
		}
		*/
		return $fields;
	}

	/**
	 * @return bool|string
	 */
	public static function validateFileInput( ?string $input, array $alldata ) {
		if ( !trim( $input ) ) {
			// Don't show an error if the file is not yet specified,
			// because it is annoying
			return true;
		}

		$title = Title::newFromText( $input, NS_FILE );
		if ( $title === null ) {
			return wfMessage( 'thumbro-invalid-file' )->text();
		}
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
		if ( !$file || !$file->exists() ) {
			return wfMessage( 'thumbro-invalid-file' )->text();
		}

		// Looks sensible enough.
		return true;
	}

	/**
	 * @return bool|string
	 */
	public static function validateWidth( int $input, array $allData ) {
		if ( self::validateFileInput( $allData['File'], $allData ) !== true
			|| !trim( $allData['File'] )
		) {
			// Invalid file, error will already be shown at file field
			return true;
		}
		$title = Title::newFromText( $allData['File'], NS_FILE );
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
		if ( $input <= 0 || $input >= $file->getWidth() ) {
			return wfMessage( 'thumbro-invalid-width' )->text();
		}
		return true;
	}

	/**
	 * @param int $input
	 * @param array $allData
	 * @return bool|string
	 */
	/*
	public static function validateSharpen( $input, $allData ) {
		if ( $input >= 5.0 || $input < 0.0 ) {
			return wfMessage( 'thumbro-invalid-sharpen' )->text();
		}
		return true;
	}
	*/

	/**
	 * Process data submitted by the form.
	 */
	public static function processForm( array $data ): Status {
		return Status::newGood();
	}

	/**
	 * Stream thumbnail from Special:ThumbroTest&thumb=
	 */
	protected function streamThumbnail() {
		$request = $this->getRequest();

		// Validate title and file existance
		$title = Title::newFromText( $request->getText( 'thumb' ), NS_FILE );
		if ( $title === null ) {
			$this->streamError( 404, "Thumbro: invalid title" );
			return;
		}
		$services = MediaWikiServices::getInstance();
		$file = $services->getRepoGroup()->findFile( $title );
		if ( !$file || !$file->exists() ) {
			$this->streamError( 404, "Thumbro: file not found" );
			return;
		}

		$inputMimeType = $file->getMimeType();
		$handlerClass = MediaHandlers::HANDLERS[$inputMimeType] ?? null;
		$handler =  $handlerClass !== null && class_exists( $handlerClass )
			?  new $handlerClass()
			: $file->getHandler();

		// Validate param string
		$params = [ 'width' => $request->getInt( 'width' ) ];
		if ( !$handler->normaliseParams( $file, $params ) ) {
			$this->streamError( 500, "Thumbro: invalid parameters" );
			return;
		}

		$config = $this->getConfig();
		$thumbroTestExpiry = $config->get( 'ThumbroTestExpiry' );
		$thumbroOptions = $config->get( 'ThumbroOptions' );
		$thumbroLibraries = $config->get( 'ThumbroLibraries' );

		// Respect MediaHandler thumbType
		[ $extension, $mimeType ] = $handler->getThumbType( $file->getExtension(), $inputMimeType );

		// Get the thumbnail
		// No remote scaler, need to do it ourselves.
		// Emulate the BitmapHandlerTransform hook
		$tmpFile = ShellCommand::makeTemp( $extension );
		$tmpFile->bind( $this );
		$dstPath = $tmpFile->getPath();
		$dstUrl = '';
		wfDebug( __METHOD__ . ": Creating vips thumbnail at $dstPath" );

		$scalerParams = [
			// The size to which the image will be resized
			'physicalWidth' => $params['physicalWidth'],
			'physicalHeight' => $params['physicalHeight'],
			'physicalDimensions' => "{$params['physicalWidth']}x{$params['physicalHeight']}",
			// The size of the image on the page
			'clientWidth' => $params['width'],
			'clientHeight' => $params['height'],
			// Comment as will be added to the EXIF of the thumbnail
			'comment' => isset( $params['descriptionUrl'] ) ?
				"File source: {$params['descriptionUrl']}" : '',
			// Properties of the original image
			'srcWidth' => $file->getWidth(),
			'srcHeight' => $file->getHeight(),
			'mimeType' => $mimeType,
			'srcPath' => $file->getLocalRefPath(),
			'dstPath' => $dstPath,
			'dstUrl' => $dstUrl,
			'interlace' => $request->getBool( 'interlace' ),
		];

		$library = $thumbroOptions[$mimeType]['library'] ?? 'libvips';
		$options = [
			'command' => $thumbroLibraries[$library]['command'],
			'inputOptions' => $thumbroOptions[$inputMimeType]['inputOptions'] ?? [],
			'outputOptions' => $thumbroOptions[$mimeType]['outputOptions'] ?? []
		];

		/*
		if ( $request->getBool( 'bilinear' ) ) {
			$options['bilinear'] = true;
			wfDebug( __METHOD__ . ": using bilinear scaling" );
		}
		if ( $request->getRawVal( 'sharpen' ) !== null && $request->getFloat( 'sharpen' ) < 5 ) {
			// Limit sharpen sigma to 5, otherwise we have to write huge convolution matrices
			$sharpen = $request->getFloat( 'sharpen' );
			$options['sharpen'] = [ 'sigma' => $sharpen ];
			wfDebug( __METHOD__ . ": sharpening with radius {$sharpen}" );
		}
		*/

		// Call the hook
		/** @var MediaTransformOutput $mto */
		Libvips::doTransform( $handler, $file, $scalerParams, $options, $mto );
		if ( $mto && !$mto->isError() ) {
			wfDebug( __METHOD__ . ": streaming thumbnail..." );
			$this->getOutput()->disable();
			StreamFile::stream( $dstPath, [
				"Cache-Control: public, max-age=$thumbroTestExpiry, s-maxage=$thumbroTestExpiry",
				'Expires: ' . gmdate( 'r ', time() + $thumbroTestExpiry )
			] );
		} else {
			'@phan-var MediaTransformError $mto';
			$this->streamError( 500, $mto->getHtmlMsg() );
		}

		// Cleanup the temporary file
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $dstPath );
	}

	/**
	 * Generates a blank page with given HTTP error code
	 */
	protected function streamError( int $httpCode, string $error = '' ): void {
		$output = $this->getOutput();
		$output->setStatusCode( $httpCode );
		$output->setArticleBodyOnly( true );
		$output->addHTML( $error );
	}
}
