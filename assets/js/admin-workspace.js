( function ( document ) {
	'use strict';

	document.addEventListener( 'slashphoto:photo-added', ( event ) => {
		const photo = event.detail;
		const grid = document.querySelector( '[data-photo-grid]' );
		const count = document.querySelector( '[data-photo-count]' );
		if ( ! photo || ! grid || ! count ) {
			return;
		}
		const item = document.createElement( 'li' );
		item.dataset.photoId = photo.id;
		const preview = photo.preview || photo.thumbnail;
		if ( preview ) {
			const image = document.createElement( 'img' );
			image.alt = '';
			image.src = preview;
			item.append( image );
		}
		const name = document.createElement( 'span' );
		name.textContent = photo.title;
		item.append( name );
		grid.prepend( item );
		const total = Number( count.dataset.photoCount ) + 1;
		count.dataset.photoCount = total;
		count.textContent = total.toLocaleString( document.documentElement.lang );
		document.querySelector( '.slashphoto-workspace__empty' ).hidden = true;
		const reorder = document.querySelector( '[data-reorder-photos]' );
		if ( reorder ) {
			reorder.hidden = total < 2;
		}
	} );
} )( document );
