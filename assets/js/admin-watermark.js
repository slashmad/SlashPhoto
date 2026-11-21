( function ( document ) {
	'use strict';

	const control = document.getElementById( 'slashphoto-watermark-id' );
	const image = document.getElementById( 'slashphoto-watermark-preview' );
	if ( ! control || ! image ) {
		return;
	}
	control.addEventListener( 'change', () => {
		const url = control.selectedOptions[ 0 ]?.dataset.preview;
		image.parentElement.hidden = ! url;
		if ( url ) {
			image.src = url;
		} else {
			image.removeAttribute( 'src' );
		}
	} );
} )( document );
