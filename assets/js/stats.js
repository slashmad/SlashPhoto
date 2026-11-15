/**
 * SlashPhoto view counts: tells the site that a gallery page or a photo in
 * the lightbox was viewed, once per page view. Sends only the gallery and
 * photo IDs; the site stores daily totals and nothing about the visitor.
 *
 * @package SlashPhoto
 */

const el = document.getElementById( 'wp-script-module-data-@slashphoto/stats' );
let config = {};
try {
	config = el ? JSON.parse( el.textContent ) || {} : {};
} catch {
	config = {};
}
const sent = new Set();

function send( gallery, image = 0 ) {
	const key = gallery + ':' + image;
	if ( ! config.url || ! gallery || sent.has( key ) ) {
		return;
	}
	sent.add( key );
	const body = new URLSearchParams( { gallery: String( gallery ), image: String( image ) } );
	if ( ! navigator.sendBeacon || ! navigator.sendBeacon( config.url, body ) ) {
		fetch( config.url, { method: 'POST', body, credentials: 'omit', keepalive: true } ).catch( () => {} );
	}
}

function init() {
	document.querySelectorAll( '.slashphoto-gallery[data-slashphoto-view]' ).forEach( ( gallery ) => send( parseInt( gallery.dataset.slashphotoView, 10 ) ) );
	document.addEventListener( 'slashphoto:lightbox-show', ( event ) => {
		const item = event.detail?.element;
		const gallery = item?.closest( '.slashphoto-gallery[data-slashphoto-view]' );
		if ( gallery ) {
			send( parseInt( gallery.dataset.slashphotoView, 10 ), parseInt( item.dataset.slashphotoId, 10 ) || 0 );
		}
	} );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
