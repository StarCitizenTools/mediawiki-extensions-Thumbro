<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro;

use File;
use Imagick;
use MediaTransformOutput;
use MediaWiki\Extension\Thumbro\Backend\BackendRequest;
use MediaWiki\Extension\Thumbro\Backend\EncodePipeline;
use MediaWiki\Extension\Thumbro\Options\TransformOptionsResolver;
use MediaWiki\FileBackend\FSFile\TempFSFileFactory;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\Field\HTMLIntField;
use MediaWiki\HTMLForm\Field\HTMLTextField;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\StreamFile;
use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\Utils\UrlUtils;
use OOUI\FieldsetLayout;
use OOUI\HtmlSnippet;
use OOUI\LabelWidget;
use OOUI\PanelLayout;
use PermissionsError;
use RepoGroup;
use Wikimedia\FileBackend\FSFile\TempFSFile;

/**
 * A Special page intended to test Thumbro.
 * @author Bryan Tong Minh
 */
class SpecialThumbroTest extends SpecialPage {

	public function __construct(
		private readonly TransformOptionsResolver $optionsResolver,
		private readonly EncodePipeline $encodePipeline,
		private readonly RepoGroup $repoGroup,
		private readonly TempFSFileFactory $tempFactory,
		private readonly UrlUtils $urlUtils,
		private readonly PermissionManager $permissionManager,
		private readonly GroupPermissionsLookup $groupPermissionsLookup,
	) {
		// MW 1.47+ throws if a restriction is passed here; the restriction comes from getRestriction().
		parent::__construct( 'ThumbroTest' );
	}

	/**
	 * Before MW 1.46, core's isRestricted(), userCanExecute() and displayRestrictionError()
	 * read the restriction set by the constructor instead of calling this method, so those
	 * three are overridden below to use it. Their parent versions can be used again once
	 * the minimum MediaWiki version is 1.46.
	 *
	 * @inheritDoc
	 */
	public function getRestriction(): string {
		return 'thumbro-test';
	}

	/**
	 * @inheritDoc
	 */
	public function isRestricted(): bool {
		return !$this->groupPermissionsLookup->groupHasPermission( '*', $this->getRestriction() );
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
		return $this->getConfig()->get( 'ThumbroExposeTestPage' )
			&& $this->permissionManager->userHasRight( $user, $this->getRestriction() );
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

		throw new PermissionsError( $this->getRestriction() );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->checkPermissions();

		if ( $this->getRequest()->getText( 'thumb' ) ) {
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
		$file = $this->repoGroup->findFile( $title );
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
		// Render now, so the comparison below can read the thumbnail from storage.
		$thumb = $file->transform( $params, File::RENDER_NOW );
		if ( !$thumb || $thumb->isError() ) {
			$this->getOutput()->addWikiMsg( 'thumbro-thumb-error' );
			return;
		}

		$imageUrls = [
			'original' => $file->getFullUrl(),
			'normal' => $this->urlUtils->expand( $thumb->getUrl() ),
			'thumbro' => $this->getPageTitle()->getFullUrl( $vipsUrlOptions )
		];

		// Check if we actually scaled the file
		if ( $imageUrls['normal'] === $imageUrls['original'] ) {
			$this->getOutput()->addWikiMsg( 'thumbro-thumb-notscaled' );
		}

		$thumbWidth = $thumb->getWidth();
		$thumbHeight = $thumb->getHeight();

		$imgs = [];
		foreach ( $imageUrls as $type => $url ) {
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
		$imagesInfo = extension_loaded( 'imagick' ) ? $this->getImagesInfo( $file, $thumb, $width ) : null;
		if ( $imagesInfo !== null ) {
			$infoHtml = '';
			foreach ( $imagesInfo as $type => $info ) {
				$infoHtml .= "<div><div>$type</div><ul>";
				foreach ( $info as $key => $value ) {
					$infoHtml .= "<li>$key: $value</li>";
				}
				$infoHtml .= '</ul></div>';
			}

			$infoHtml = new HtmlSnippet(
				"<div style='display: grid; grid-template-columns:1fr 1fr 1fr; gap: 8px;'>$infoHtml</div>"
			);
			$this->getOutput()->addHTML( (string)( new PanelLayout( [
				'expanded' => false,
				'padded' => true,
				'framed' => true,
				'content' => [ $infoHtml ],
			] ) ) );
		}

		$this->getOutput()->addHTML( (string)( new PanelLayout( [
			'expanded' => false,
			'padded' => true,
			'framed' => true,
			'content' => [ $fieldset, $thumbs, $thumbsOrigCurr, $thumbsOrigThumbro ],
		] ) ) );

		$this->getOutput()->addModules( [ 'ext.thumbro' ] );
	}

	/**
	 * Return the information for display for both thumbnails, or null if any of the
	 * images is not available locally (e.g. a file from a foreign API repo).
	 *
	 * The images are read from storage, never fetched over HTTP: their URLs can point at
	 * hosts outside the wiki (a foreign repo, a CDN), and the Thumbro thumbnail is only
	 * served to users who may use this page.
	 */
	private function getImagesInfo( File $file, MediaTransformOutput $thumb, int $width ): ?array {
		$images = [
			'original' => $this->readLocalImage( $file->getLocalRefPath() ),
			'normal' => $this->readLocalImage( $thumb->getLocalCopyPath() ),
		];
		if ( !$images['original'] || !$images['normal'] ) {
			wfDebug( __METHOD__ . ': no local copy of the original or current thumbnail, skipping comparison' );
			return null;
		}

		$thumbroThumb = $this->renderThumbroThumbnail( $file, $width );
		if ( is_string( $thumbroThumb ) ) {
			wfDebug( __METHOD__ . ": could not render the Thumbro thumbnail, skipping comparison: $thumbroThumb" );
			return null;
		}
		$images['thumbro'] = $this->readLocalImage( $thumbroThumb->getPath() );
		$thumbroThumb->purge();
		if ( !$images['thumbro'] ) {
			return null;
		}

		// Measure and compare what is shown: for an animation, its first frame
		$frames = array_map( self::getFirstFrame( ... ), $images );

		// Resize the original image to match the thumbnail's dimensions for more accurate comparison
		$frames['original']->resizeImage(
			$frames['normal']->getImageWidth(),
			$frames['normal']->getImageHeight(),
			Imagick::FILTER_LANCZOS, 1
		);

		$info = [];
		foreach ( $frames as $type => $frame ) {
			if ( $type === 'original' ) {
				continue;
			}

			$info[$type] = [
				'Width' => $frame->getImageWidth(),
				'Height' => $frame->getImageHeight(),
				'Type' => $images[$type]->getImageMimeType(),
				'Size' => $this->humanFileSize( $images[$type]->getImageLength() ),
				'PSNR' => $frame->compareImages( $frames['original'], Imagick::METRIC_PEAKSIGNALTONOISERATIO )[1]
			];

			// ImageMagick 6.9.0+
			if ( defined( 'Imagick::METRIC_PERCEPTUALHASH_ERROR' ) ) {
				$info[$type]['Perceptual Hash'] = $frame->compareImages(
					$frames['original'],
					Imagick::METRIC_PERCEPTUALHASH_ERROR
				)[1];
			}

			// ImageMagick 7.0.7
			if ( defined( 'Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR' ) ) {
				$info[$type]['SSIM'] = $frame->compareImages(
					$frames['original'],
					constant( 'Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR' )
				)[1];
			}
		}
		return $info;
	}

	/**
	 * Return the first frame of the image, as it is shown.
	 *
	 * After reading an animation, Imagick points at its last frame, and frames after the
	 * first often hold only the part of the canvas that changed. Coalescing the single
	 * frame also places it on its full canvas, in case it is offset.
	 */
	private static function getFirstFrame( Imagick $image ): Imagick {
		$image->setFirstIterator();
		return $image->getImage()->coalesceImages();
	}

	/**
	 * Return the metric of Imagick::compareImages if avaliable
	 */
	private function getImageMetric( Imagick $image, string $metric ): ?string {
		if ( defined( $metric ) ) {
			return $image->compareImages( $image, constant( $metric ) )[1];
		}
		return null;
	}

	/**
	 * Load an image from a local file, or return null if there is none
	 */
	private function readLocalImage( string|false $path ): ?Imagick {
		if ( !is_string( $path ) || !is_file( $path ) ) {
			return null;
		}
		$blob = file_get_contents( $path );
		if ( $blob === false ) {
			return null;
		}
		$image = new Imagick();
		$image->readImageBlob( $blob );
		return $image;
	}

	/**
	 * Format bytes into human-readable file size
	 */
	private function humanFileSize( int $bytes, ?int $decimals = 2 ): string {
		$size = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
		$factor = (int)floor( ( strlen( (string)$bytes ) - 1 ) / 3 );
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
		$form->setSubmitCallback( self::processForm( ... ) );
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
				'validation-callback' => self::validateFileInput( ... ),
			],
			'Width' => [
				'name'          => 'width',
				'class'         => HTMLIntField::class,
				'default'       => '640',
				'size'          => '5',
				'required'      => true,
				'label-message' => 'thumbro-form-width',
				'validation-callback' => self::validateWidth( ... ),
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

	public static function validateFileInput( ?string $input, array $alldata ): bool|string {
		if ( $input === null || !trim( $input ) ) {
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

	public static function validateWidth( int $input, array $allData ): bool|string {
		if (
			$allData['File'] === null ||
			self::validateFileInput( $allData['File'], $allData ) !== true ||
			!trim( $allData['File'] )
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
	protected function streamThumbnail(): void {
		$request = $this->getRequest();

		// Validate title and file existance
		$title = Title::newFromText( $request->getText( 'thumb' ), NS_FILE );
		if ( $title === null ) {
			$this->streamError( 404, "Thumbro: invalid title" );
			return;
		}
		$file = $this->repoGroup->findFile( $title );
		if ( !$file || !$file->exists() ) {
			$this->streamError( 404, "Thumbro: file not found" );
			return;
		}

		$thumb = $this->renderThumbroThumbnail( $file, $request->getInt( 'width' ), $request->getBool( 'interlace' ) );
		if ( is_string( $thumb ) ) {
			$this->streamError( 500, $thumb );
			return;
		}

		$thumbroTestExpiry = $this->getConfig()->get( 'ThumbroTestExpiry' );
		wfDebug( __METHOD__ . ": streaming thumbnail..." );
		$this->getOutput()->disable();
		// Only users allowed to use this page may see the thumbnail, so it must never be
		// stored in a shared cache (CDN, reverse proxy), which would serve it to anyone.
		StreamFile::stream( $thumb->getPath(), [
			"Cache-Control: private, max-age=$thumbroTestExpiry",
		] );
		$thumb->purge();
	}

	/**
	 * Render a thumbnail of the file through Thumbro, the way the BitmapHandlerTransform
	 * hook would, into a temporary file.
	 *
	 * @return TempFSFile|string The thumbnail, or an HTML error message
	 */
	private function renderThumbroThumbnail( File $file, int $width, bool $interlace = false ): TempFSFile|string {
		$inputMimeType = $file->getMimeType();
		$handlerClass = MediaHandlers::HANDLERS[$inputMimeType] ?? null;
		$handler = $handlerClass !== null && class_exists( $handlerClass )
			? new $handlerClass()
			: $file->getHandler();

		// Validate param string
		$params = [ 'width' => $width ];
		if ( !$handler->normaliseParams( $file, $params ) ) {
			return 'Thumbro: invalid parameters';
		}

		// Respect MediaHandler thumbType
		[ $extension, $mimeType ] = $handler->getThumbType( $file->getExtension(), $inputMimeType );

		// Get the thumbnail
		// No remote scaler, need to do it ourselves.
		// Emulate the BitmapHandlerTransform hook
		$tmpFile = $this->tempFactory->newTempFSFile( 'thumbro_', $extension );
		if ( !$tmpFile ) {
			return 'Thumbro: could not create a temporary file';
		}
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
			'interlace' => $interlace,
		];

		$options = $this->optionsResolver->resolve( $handler, $file );
		if ( $options === null ) {
			// resolve() returns null for any file Thumbro will not transform: the output
			// type's block is disabled, the file is multipage, or its area is outside the
			// configured [minArea, maxArea) range.
			return 'Thumbro: this file is not handled (its type may be disabled, multipage, '
				. 'or outside the configured size limits)';
		}

		// Dispatch through the same seam the BitmapHandlerTransform hook uses, so the
		// comparison reflects the backend production actually serves (e.g. libwebp for
		// animated GIFs) rather than always libvips.
		/** @var MediaTransformOutput $mto */
		$this->encodePipeline->dispatch(
			new BackendRequest( $handler, $file, $scalerParams, $options ),
			$mto
		);
		if ( !$mto ) {
			return 'Thumbro: no thumbnail was produced';
		}
		if ( $mto->isError() ) {
			'@phan-var \MediaTransformError $mto';
			return $mto->getHtmlMsg();
		}
		return $tmpFile;
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
