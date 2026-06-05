( function () {
	'use strict';

	function switchThumbs( $thumbnails ) {
		const $mask = $thumbnails.children( '.uc-mask' ),
			$caption = $thumbnails.children( '.uc-caption' ),
			width = $thumbnails.width(),
			maskWidth = $mask.width();

		if ( maskWidth < width / 2 ) {
			// Bar is 3 pixels width. We want to show it on the right.
			$mask.width( width - 3 );
			$caption.html( $thumbnails.children( 'img' ).eq( 0 ).attr( 'alt' ) );
		} else {
			$mask.width( 0 );
			$caption.html( $thumbnails.children( 'img' ).eq( 1 ).attr( 'alt' ) );
		}
	}

	$( () => {
		const nodes = document.querySelectorAll( '.mw-thumbrotest-thumbnails' );
		if ( !nodes.length ) {
			return;
		}
		const $thumbnails = $( nodes );

		// options are detailed in upstream documentation available at
		// http://www.userdot.net/files/jquery/jquery.ucompare/demo/
		//
		// Copying them here for version 1.0
		// - caption: toggle the
		// - leftgap: the gap to the left of the image
		// - rightgap: the gap to the right of the image
		// - defaultgap: the default gap shown before any interactions
		$thumbnails.ucompare( {
			defaultgap: 50,
			leftgap: 0,
			rightgap: 0,
			caption: true,
			reveal: 0.5
		} );

		// Also add a click handler to instantly switch between pics
		$thumbnails.on( 'click', () => switchThumbs( $thumbnails ) );
	} );

}() );
