/**
 * SlashPhoto lightbox.
 *
 * Progressive enhancement: without JavaScript every gallery image is a plain
 * link to its full-size file. With JavaScript, clicks open a native <dialog>
 * (focus trap, Esc and inert background for free). 360° images open in an
 * interactive viewer that is downloaded only when first needed.
 *
 * @package SlashPhoto
 */

const MODULE_DATA_ID = 'wp-script-module-data-@slashphoto/lightbox';
const SWIPE_THRESHOLD = 50;

const config = readConfig();
const i18n = config.i18n || {};

let viewerModule = null;

function readConfig() {
	const el = document.getElementById( MODULE_DATA_ID );
	if ( ! el ) {
		return {};
	}
	try {
		return JSON.parse( el.textContent ) || {};
	} catch {
		return {};
	}
}

function hasWebGL() {
	try {
		const canvas = document.createElement( 'canvas' );
		return !! ( canvas.getContext( 'webgl2' ) || canvas.getContext( 'webgl' ) );
	} catch {
		return false;
	}
}

/**
 * Loads the 360° viewer module and its stylesheet once.
 *
 * @return {Promise<Object>} The viewer module.
 */
function loadViewer() {
	if ( ! viewerModule ) {
		if ( config.viewerStyle && ! document.querySelector( 'link[data-slashphoto-viewer-style]' ) ) {
			const link = document.createElement( 'link' );
			link.rel = 'stylesheet';
			link.href = config.viewerStyle;
			link.dataset.slashphotoViewerStyle = '';
			document.head.append( link );
		}
		viewerModule = import( '@slashphoto/panorama-viewer' );
	}
	return viewerModule;
}

function format( template, ...values ) {
	let i = 0;
	return String( template || '' ).replace( /%(\d+\$)?d/g, ( match, position ) => {
		const index = position ? parseInt( position, 10 ) - 1 : i++;
		return String( values[ index ] ?? '' );
	} );
}

function icon( path ) {
	return `<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="${ path }" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
}

/**
 * Reads the items of one gallery from its markup.
 *
 * @param {HTMLElement} gallery Gallery element.
 * @return {Object[]} Items.
 */
function readItems( gallery ) {
	// Items hidden by a filter (for example "show only selected") are skipped.
	return [ ...gallery.querySelectorAll( '.slashphoto-item:not([hidden])' ) ].map( ( li ) => {
		const link = li.querySelector( '.slashphoto-link' );
		const img = li.querySelector( 'img' );
		let panorama = null;
		if ( li.dataset.slashphotoPanorama ) {
			try {
				panorama = JSON.parse( li.dataset.slashphotoPanorama );
			} catch {
				panorama = null;
			}
		}
		return {
			element: li,
			link,
			kind: li.dataset.slashphotoKind || 'photo',
			src: link ? link.href : '',
			width: link ? parseInt( link.dataset.slashphotoWidth || '0', 10 ) : 0,
			height: link ? parseInt( link.dataset.slashphotoHeight || '0', 10 ) : 0,
			alt: img ? img.alt : '',
			caption: li.querySelector( '.slashphoto-caption' )?.textContent.trim() || '',
			panorama,
		};
	} );
}

class Lightbox {
	constructor() {
		this.items = [];
		this.index = 0;
		this.viewer = null;
		this.token = 0;
		this.trigger = null;
		this.swiped = false;
		this.build();
	}

	build() {
		const dialog = document.createElement( 'dialog' );
		dialog.className = 'slashphoto-lightbox';
		dialog.setAttribute( 'aria-label', i18n.dialog || 'Image viewer' );
		// Focus the dialog itself on open, not the close button; Tab reaches the controls.
		dialog.autofocus = true;
		dialog.tabIndex = -1;
		dialog.innerHTML = `
			<div class="slashphoto-lightbox__bar">
				<span class="slashphoto-lightbox__counter" aria-live="polite"></span>
				<div class="slashphoto-lightbox__tools"></div>
				<button type="button" class="slashphoto-lightbox__button slashphoto-lightbox__close">${ icon( 'M6 6l12 12M18 6L6 18' ) }</button>
			</div>
			<div class="slashphoto-lightbox__stage"></div>
			<button type="button" class="slashphoto-lightbox__button slashphoto-lightbox__nav slashphoto-lightbox__prev">${ icon( 'M15 5l-7 7 7 7' ) }</button>
			<button type="button" class="slashphoto-lightbox__button slashphoto-lightbox__nav slashphoto-lightbox__next">${ icon( 'M9 5l7 7-7 7' ) }</button>
			<p class="slashphoto-lightbox__caption"></p>
		`;

		this.dialog = dialog;
		this.stage = dialog.querySelector( '.slashphoto-lightbox__stage' );
		this.counter = dialog.querySelector( '.slashphoto-lightbox__counter' );
		this.caption = dialog.querySelector( '.slashphoto-lightbox__caption' );
		this.tools = dialog.querySelector( '.slashphoto-lightbox__tools' );
		this.prevButton = dialog.querySelector( '.slashphoto-lightbox__prev' );
		this.nextButton = dialog.querySelector( '.slashphoto-lightbox__next' );
		const closeButton = dialog.querySelector( '.slashphoto-lightbox__close' );

		closeButton.setAttribute( 'aria-label', i18n.close || 'Close' );
		this.prevButton.setAttribute( 'aria-label', i18n.previous || 'Previous image' );
		this.nextButton.setAttribute( 'aria-label', i18n.next || 'Next image' );

		closeButton.addEventListener( 'click', () => dialog.close() );
		this.prevButton.addEventListener( 'click', () => this.step( -1 ) );
		this.nextButton.addEventListener( 'click', () => this.step( 1 ) );
		dialog.addEventListener( 'close', () => this.onClose() );
		dialog.addEventListener( 'keydown', ( event ) => this.onKeydown( event ) );
		// A click on the dialog itself (not its content) is a click on the backdrop area.
		dialog.addEventListener( 'click', ( event ) => {
			if ( this.swiped ) {
				this.swiped = false;
				return;
			}
			if ( event.target === dialog || event.target === this.stage ) {
				dialog.close();
			}
		} );
		this.bindSwipe();

		document.body.append( dialog );
	}

	open( items, index, trigger ) {
		this.items = items;
		this.trigger = trigger;
		document.documentElement.classList.add( 'slashphoto-lightbox-open' );
		if ( ! this.dialog.open ) {
			this.dialog.showModal();
		}
		this.show( index );
	}

	step( delta ) {
		if ( this.items.length < 2 ) {
			return;
		}
		this.show( ( this.index + delta + this.items.length ) % this.items.length );
	}

	async show( index ) {
		const item = this.items[ index ];
		if ( ! item ) {
			return;
		}
		this.index = index;
		const token = ++this.token;

		this.destroyViewer();
		this.stage.replaceChildren();
		this.dialog.dataset.kind = item.kind;

		const many = this.items.length > 1;
		this.prevButton.hidden = ! many;
		this.nextButton.hidden = ! many;
		this.counter.textContent = many ? format( i18n.counter || '%1$d of %2$d', index + 1, this.items.length ) : '';
		this.caption.textContent = item.caption;
		this.caption.hidden = ! item.caption;

		// Lets other modules (proofing) add buttons for this image to the bar.
		this.tools.replaceChildren();
		document.dispatchEvent(
			new CustomEvent( 'slashphoto:lightbox-show', {
				detail: {
					element: item.element,
					tools: this.tools,
					dialog: this.dialog,
					printView: () => 'true' === this.dialog.dataset.panoramaReady ? this.viewer?.printView() : null,
				},
			} )
		);

		if ( '360' === item.kind && hasWebGL() ) {
			await this.showPanorama( item, token );
		} else {
			this.showPhoto( item );
			if ( '360' === item.kind ) {
				this.caption.textContent = i18n.noWebGL || '';
				this.caption.hidden = false;
			}
		}
		this.preload( index );
	}

	showPhoto( item ) {
		const img = document.createElement( 'img' );
		img.className = 'slashphoto-lightbox__image';
		img.decoding = 'async';
		img.src = item.src;
		img.alt = item.alt;
		if ( item.width && item.height ) {
			img.width = item.width;
			img.height = item.height;
		}
		this.stage.append( img );
		const revision = item.element.dataset.slashphotoRevision;
		if ( revision ) {
			const compare = document.createElement( 'button' );
			compare.className = 'slashphoto-lightbox__button slashphoto-lightbox__compare';
			compare.type = 'button';
			compare.textContent = i18n.compare || 'Show revised photo';
			let revised = false;
			compare.addEventListener( 'click', () => {
				revised = ! revised;
				img.src = revised ? revision : item.src;
				compare.textContent = revised ? i18n.original || 'Show original photo' : i18n.compare || 'Show revised photo';
			} );
			this.tools.append( compare );
		}
	}

	async showPanorama( item, token ) {
		const container = document.createElement( 'div' );
		container.className = 'slashphoto-lightbox__panorama';
		container.setAttribute( 'role', 'application' );
		container.setAttribute( 'aria-roledescription', i18n.viewer360 || '360° panorama' );
		container.setAttribute( 'aria-label', item.alt || item.caption || i18n.viewer360 || '' );
		const status = document.createElement( 'p' );
		status.className = 'slashphoto-lightbox__status';
		status.textContent = i18n.loading || 'Loading…';
		container.append( status );
		this.stage.append( container );

		try {
			const { createViewer } = await loadViewer();
			if ( token !== this.token ) {
				return;
			}
			status.remove();
			this.viewer = createViewer( container, {
				src: item.src,
				panoData: item.panorama,
				caption: item.caption,
				i18n,
			} );
			this.dialog.dataset.panoramaReady = '';
			await this.viewer.ready;
			if ( token === this.token ) {
				this.dialog.dataset.panoramaReady = 'true';
			}
		} catch ( error ) {
			if ( token !== this.token ) {
				return;
			}
			// eslint-disable-next-line no-console
			console.error( 'SlashPhoto: 360° viewer failed, showing the flat image.', error );
			this.destroyViewer();
			this.stage.replaceChildren();
			this.showPhoto( item );
		}
	}

	preload( index ) {
		[ index - 1, index + 1 ].forEach( ( i ) => {
			const item = this.items[ ( i + this.items.length ) % this.items.length ];
			if ( item && 'photo' === item.kind ) {
				const img = new Image();
				img.decoding = 'async';
				img.src = item.src;
			}
		} );
	}

	destroyViewer() {
		if ( this.viewer ) {
			this.viewer.destroy();
			this.viewer = null;
		}
		delete this.dialog.dataset.panoramaReady;
	}

	onKeydown( event ) {
		if ( document.fullscreenElement ) {
			return; // The 360° viewer uses the arrow keys in fullscreen.
		}
		if ( event.target.closest?.( 'textarea, input, select' ) ) {
			return; // Typing a note.
		}
		if ( 'ArrowLeft' === event.key ) {
			event.preventDefault();
			this.step( -1 );
		} else if ( 'ArrowRight' === event.key ) {
			event.preventDefault();
			this.step( 1 );
		}
	}

	bindSwipe() {
		let startX = null;
		let startY = 0;
		this.stage.addEventListener( 'pointerdown', ( event ) => {
			// Dragging rotates 360° images; only photos swipe.
			startX = 'photo' === this.dialog.dataset.kind || 'wide' === this.dialog.dataset.kind ? event.clientX : null;
			startY = event.clientY;
		} );
		this.stage.addEventListener( 'pointerup', ( event ) => {
			if ( null === startX ) {
				return;
			}
			const dx = event.clientX - startX;
			const dy = event.clientY - startY;
			startX = null;
			if ( Math.abs( dx ) > SWIPE_THRESHOLD && Math.abs( dx ) > Math.abs( dy ) ) {
				this.swiped = true;
				this.step( dx < 0 ? 1 : -1 );
			}
		} );
	}

	onClose() {
		this.token++;
		this.tools.replaceChildren();
		this.dialog.querySelector( '.slashphoto-lightbox__note' )?.remove();
		this.destroyViewer();
		this.stage.replaceChildren();
		document.documentElement.classList.remove( 'slashphoto-lightbox-open' );
		if ( this.trigger && this.trigger.isConnected ) {
			this.trigger.focus( { preventScroll: true } );
		}
	}
}

let lightbox = null;

function init() {
	document.querySelectorAll( '.slashphoto-gallery' ).forEach( ( gallery ) => {
		if ( gallery.dataset.slashphotoLightbox ) {
			return;
		}
		gallery.dataset.slashphotoLightbox = 'ready';
		gallery.addEventListener( 'click', ( event ) => {
			const link = event.target.closest( '.slashphoto-item .slashphoto-link' );
			// Let the browser handle new-tab clicks and other modified clicks.
			if ( ! link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}
			event.preventDefault();
			const items = readItems( gallery );
			const index = items.findIndex( ( item ) => item.link === link );
			lightbox ??= new Lightbox();
			lightbox.open( items, Math.max( 0, index ), link );
		} );
	} );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
