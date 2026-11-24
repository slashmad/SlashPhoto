/**
 * Produces a print JPEG from an authorized order's private original.
 */

document.querySelectorAll( '[data-slashphoto-panorama-print]' ).forEach( button => {
	button.addEventListener( 'click', async () => {
		const info = JSON.parse( button.dataset.slashphotoPanoramaPrint );
		const status = button.parentElement.querySelector( '[role="status"]' );
		button.disabled = true;
		status.textContent = info.progress;
		let input;
		let output;
		try {
			const response = await fetch( info.source, { credentials: 'same-origin' } );
			if ( ! response.ok ) {
				throw new Error( 'Original unavailable.' );
			}
			input = URL.createObjectURL( await response.blob() );
			const { project } = await import( info.module );
			const pixels = Math.max( 1, Math.min( 4800, Math.round( info.view.width * 300 ) ) );
			const canvas = await project( input, info.view, pixels, true );
			const file = await new Promise( resolve => canvas.toBlob( resolve, 'image/jpeg', 0.96 ) );
			if ( ! file ) {
				throw new Error( 'JPEG output unavailable.' );
			}
			output = URL.createObjectURL( file );
			const link = document.createElement( 'a' );
			link.href = output;
			link.download = info.name;
			link.click();
			status.textContent = info.done;
		} catch {
			status.textContent = info.error;
		} finally {
			if ( input ) {
				URL.revokeObjectURL( input );
			}
			if ( output ) {
				window.setTimeout( () => URL.revokeObjectURL( output ), 30000 );
			}
			button.disabled = false;
		}
	} );
} );
