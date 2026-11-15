/**
 * SlashPhoto proofing: clients mark favourites and send a selection.
 *
 * Logged-in clients store their selection on the site through the REST API
 * (only their own choices: images, notes, a message). Visitors who are not
 * logged in keep favourites in their own browser (localStorage) and are
 * offered a log-in link; nothing is sent to the server for them. When they
 * log in, those favourites are merged into their selection once.
 *
 * Progressive enhancement: without JavaScript the gallery works as before.
 *
 * @package SlashPhoto
 */

const DATA_ID = 'wp-script-module-data-@slashphoto/proofing';
const LOCAL_PREFIX = 'slashphoto-favourites-';
const NOTE_LIMIT = 1000;
const MESSAGE_LIMIT = 5000;

const config = readConfig();
const i18n = config.i18n || {};
const stores = new Map();
let lightboxView = null;
let lightboxToken = 0;

function readConfig() {
	const el = document.getElementById( DATA_ID );
	if ( ! el ) {
		return {};
	}
	try {
		return JSON.parse( el.textContent ) || {};
	} catch {
		return {};
	}
}

function format( template, ...values ) {
	let i = 0;
	return String( template || '' ).replace( /%(\d+\$)?[ds]/g, ( match, position ) => {
		const index = position ? parseInt( position, 10 ) - 1 : i++;
		return String( values[ index ] ?? '' );
	} );
}

const HEART =
	'<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M12 20.5s-7.5-4.6-9.2-9.4C1.6 7.6 3.9 4 7.4 4c2 0 3.5 1.1 4.6 2.7C13.1 5.1 14.6 4 16.6 4c3.5 0 5.8 3.6 4.6 7.1-1.7 4.8-9.2 9.4-9.2 9.4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
const NOTE =
	'<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M4 5h16v11H9l-5 4V5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';

/* ------------------------------------------------------------------ */
/* Stores                                                              */
/* ------------------------------------------------------------------ */

class Store {
	constructor( root ) {
		this.root = root;
		this.items = new Map();
		this.max = 0;
		this.submitted = null;
		this.error = '';
		this.listeners = new Set();
	}

	subscribe( listener ) {
		this.listeners.add( listener );
	}

	emit() {
		this.listeners.forEach( ( listener ) => listener( this ) );
	}

	has( id ) {
		return this.items.has( id );
	}

	note( id ) {
		return this.items.get( id ) || '';
	}

	get count() {
		return this.items.size;
	}

	get locked() {
		return !! this.submitted;
	}
}

/**
 * Favourites kept only in this browser.
 */
class LocalStore extends Store {
	constructor( root ) {
		super( root );
		this.mode = 'local';
	}

	async load() {
		this.items = new Map( readLocal( this.root ).map( ( id ) => [ id, '' ] ) );
		this.emit();
	}

	async toggle( id ) {
		if ( this.items.has( id ) ) {
			this.items.delete( id );
		} else {
			this.items.set( id, '' );
		}
		writeLocal( this.root, [ ...this.items.keys() ] );
		this.emit();
	}
}

/**
 * The logged-in client's selection on the site.
 */
class ServerStore extends Store {
	constructor( root ) {
		super( root );
		this.mode = 'server';
		this.message = '';
	}

	async request( path, body ) {
		const response = await fetch( config.restUrl + this.root + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				...( body ? { 'Content-Type': 'application/json' } : {} ),
			},
			body: body ? JSON.stringify( body ) : undefined,
		} );
		const data = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			const error = new Error( data.message || i18n.error || 'Error' );
			error.status = response.status;
			throw error;
		}
		this.apply( data );
		return data;
	}

	apply( data ) {
		this.items = new Map( ( data.items || [] ).map( ( item ) => [ item.id, item.note || '' ] ) );
		this.max = data.max || 0;
		this.submitted = data.submitted || null;
		this.message = data.message || '';
		this.error = '';
		this.emit();
	}

	async load() {
		await this.request( '' );
		const local = readLocal( this.root );
		if ( local.length && ! this.submitted ) {
			await this.request( '/merge', { ids: local } );
		}
		clearLocal( this.root );
	}

	async run( path, body, optimistic ) {
		const before = new Map( this.items );
		if ( optimistic ) {
			optimistic();
			this.emit();
		}
		try {
			await this.request( path, body );
			return true;
		} catch ( error ) {
			this.items = before;
			this.error = error.message;
			this.emit();
			return false;
		}
	}

	toggle( id ) {
		const selected = ! this.items.has( id );
		return this.run( '/items', { id, selected }, () => ( selected ? this.items.set( id, '' ) : this.items.delete( id ) ) );
	}

	setNote( id, note ) {
		return this.run( '/items', { id, selected: true, note }, () => this.items.set( id, note ) );
	}

	submit( message ) {
		return this.run( '/submit', { message } );
	}
}

function readLocal( root ) {
	try {
		const value = JSON.parse( window.localStorage.getItem( LOCAL_PREFIX + root ) || '[]' );
		return Array.isArray( value ) ? value.map( Number ).filter( ( id ) => id > 0 ) : [];
	} catch {
		return [];
	}
}

function writeLocal( root, ids ) {
	try {
		if ( ids.length ) {
			window.localStorage.setItem( LOCAL_PREFIX + root, JSON.stringify( ids ) );
		} else {
			window.localStorage.removeItem( LOCAL_PREFIX + root );
		}
	} catch {
		// Storage can be disabled; favourites then last for this page view.
	}
}

function clearLocal( root ) {
	writeLocal( root, [] );
}

/**
 * One store per proofing root, shared by every gallery on the page that
 * belongs to it. Falls back to browser-only favourites when the site does
 * not accept the request (not logged in, or a stale cached page).
 *
 * @param {number} root Proofing root gallery ID.
 * @return {Promise<Store>} Store.
 */
function storeFor( root ) {
	if ( ! stores.has( root ) ) {
		stores.set(
			root,
			( async () => {
				if ( config.loggedIn && config.nonce ) {
					const server = new ServerStore( root );
					try {
						await server.load();
						return server;
					} catch {
						// Fall through to browser-only favourites.
					}
				}
				const local = new LocalStore( root );
				await local.load();
				return local;
			} )()
		);
	}
	return stores.get( root );
}

/* ------------------------------------------------------------------ */
/* Gallery                                                             */
/* ------------------------------------------------------------------ */

function button( className, html ) {
	const el = document.createElement( 'button' );
	el.type = 'button';
	el.className = className;
	el.innerHTML = html;
	return el;
}

function loginUrl() {
	try {
		const url = new URL( config.loginUrl || '/wp-login.php', window.location.href );
		url.searchParams.set( 'redirect_to', window.location.href );
		return url.href;
	} catch {
		return config.loginUrl || '/wp-login.php';
	}
}

class GalleryView {
	constructor( gallery, store ) {
		this.gallery = gallery;
		this.store = store;
		this.onlySelected = false;
		this.items = [ ...gallery.querySelectorAll( '.slashphoto-item[data-slashphoto-id]' ) ].map( ( element ) => ( {
			element,
			id: parseInt( element.dataset.slashphotoId, 10 ),
		} ) );

		this.items.forEach( ( item ) => {
			const pick = button( 'slashphoto-pick', HEART );
			pick.addEventListener( 'click', () => this.store.toggle( item.id ) );
			( item.element.querySelector( '.slashphoto-figure' ) || item.element ).append( pick );
			item.pick = pick;
		} );

		this.buildBar();
		store.subscribe( () => this.render() );
		this.render();
		gallery.dataset.slashphotoProofingReady = store.mode;
	}

	buildBar() {
		const bar = document.createElement( 'div' );
		bar.className = 'slashphoto-proofing';
		bar.setAttribute( 'role', 'region' );
		bar.setAttribute( 'aria-label', i18n.selectionName || 'Your selection' );
		bar.innerHTML = `
			<div class="slashphoto-proofing__row">
				<p class="slashphoto-proofing__status" aria-live="polite"></p>
				<div class="slashphoto-proofing__actions">
					<button type="button" class="slashphoto-proofing__filter" aria-pressed="false"></button>
					<a class="slashphoto-proofing__login"></a>
					<button type="button" class="slashphoto-proofing__send wp-element-button"></button>
				</div>
			</div>
			<form class="slashphoto-proofing__form" hidden>
				<label class="slashphoto-proofing__label"></label>
				<textarea class="slashphoto-proofing__message" rows="3"></textarea>
				<p class="slashphoto-proofing__help"></p>
				<div class="slashphoto-proofing__actions">
					<button type="submit" class="slashphoto-proofing__submit wp-element-button"></button>
					<button type="button" class="slashphoto-proofing__cancel"></button>
				</div>
			</form>
			<p class="slashphoto-proofing__error" role="alert" hidden></p>
		`;

		const uid = 'slashphoto-message-' + this.store.root + '-' + Math.random().toString( 36 ).slice( 2, 8 );
		this.bar = bar;
		this.status = bar.querySelector( '.slashphoto-proofing__status' );
		this.filter = bar.querySelector( '.slashphoto-proofing__filter' );
		this.login = bar.querySelector( '.slashphoto-proofing__login' );
		this.send = bar.querySelector( '.slashphoto-proofing__send' );
		this.form = bar.querySelector( '.slashphoto-proofing__form' );
		this.message = bar.querySelector( '.slashphoto-proofing__message' );
		this.error = bar.querySelector( '.slashphoto-proofing__error' );

		const label = bar.querySelector( '.slashphoto-proofing__label' );
		label.textContent = i18n.message || 'Message to the photographer (optional)';
		label.htmlFor = uid;
		this.message.id = uid;
		this.message.maxLength = MESSAGE_LIMIT;
		bar.querySelector( '.slashphoto-proofing__help' ).textContent = i18n.sendHelp || '';
		bar.querySelector( '.slashphoto-proofing__submit' ).textContent = i18n.sendNow || 'Send';
		bar.querySelector( '.slashphoto-proofing__cancel' ).textContent = i18n.cancel || 'Cancel';
		this.filter.textContent = i18n.showSelected || 'Show only selected';
		this.send.textContent = i18n.send || 'Send selection…';
		this.login.textContent = i18n.logIn || 'Log in';
		this.login.href = loginUrl();

		this.filter.addEventListener( 'click', () => {
			this.onlySelected = ! this.onlySelected;
			this.render();
		} );
		this.send.addEventListener( 'click', () => {
			this.form.hidden = false;
			this.send.hidden = true;
			this.message.focus();
		} );
		bar.querySelector( '.slashphoto-proofing__cancel' ).addEventListener( 'click', () => this.closeForm() );
		this.form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const submit = this.form.querySelector( '.slashphoto-proofing__submit' );
			submit.disabled = true;
			const ok = await this.store.submit( this.message.value );
			submit.disabled = false;
			if ( ok ) {
				this.closeForm();
			}
		} );

		const grid = this.gallery.querySelector( '.slashphoto-grid' );
		( grid || this.gallery ).after( bar );
	}

	closeForm() {
		this.form.hidden = true;
		this.render();
	}

	render() {
		const store = this.store;
		const server = 'server' === store.mode;

		this.items.forEach( ( item ) => {
			const selected = store.has( item.id );
			item.element.classList.toggle( 'is-selected', selected );
			item.element.classList.toggle( 'has-note', selected && '' !== store.note( item.id ) );
			item.element.hidden = this.onlySelected && ! selected;
			item.pick.setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
			item.pick.setAttribute( 'aria-label', selected ? i18n.deselect || 'Remove from selection' : i18n.select || 'Add to selection' );
			item.pick.disabled = store.locked;
		} );

		if ( ! store.count && this.onlySelected ) {
			this.onlySelected = false;
			this.items.forEach( ( item ) => ( item.element.hidden = false ) );
		}

		let status;
		if ( store.locked ) {
			const date = new Date( store.submitted );
			status = format( i18n.sent || '%1$d images sent on %2$s.', store.count, date.toLocaleDateString( document.documentElement.lang || undefined ) );
		} else if ( ! server ) {
			status = format( i18n.localCount || '%d favourites', store.count );
		} else if ( store.max ) {
			status = format( i18n.countMax || '%1$d of %2$d selected', store.count, store.max );
		} else {
			status = format( i18n.count || '%d selected', store.count );
		}
		this.status.textContent = status;

		this.filter.hidden = ! store.count;
		this.filter.setAttribute( 'aria-pressed', this.onlySelected ? 'true' : 'false' );
		this.login.hidden = server || ! store.count;
		this.send.hidden = ! server || store.locked || ! this.form.hidden;
		this.send.disabled = ! store.count;
		this.bar.classList.toggle( 'is-empty', ! store.count && ! store.locked );
		this.bar.classList.toggle( 'is-locked', store.locked );

		this.error.textContent = store.error;
		this.error.hidden = ! store.error;
	}
}

/* ------------------------------------------------------------------ */
/* Lightbox tools                                                      */
/* ------------------------------------------------------------------ */

async function onLightboxShow( event ) {
	const { element, tools, dialog } = event.detail || {};
	dialog?.querySelector( '.slashphoto-lightbox__note' )?.remove();
	lightboxView = null;
	const token = ++lightboxToken;
	const gallery = element?.closest( '.slashphoto-gallery[data-slashphoto-proofing]' );
	if ( ! gallery || ! tools ) {
		return;
	}
	const store = await storeFor( parseInt( gallery.dataset.slashphotoProofing, 10 ) );
	if ( token !== lightboxToken ) {
		return; // The visitor already moved on to another image.
	}
	lightboxView = { element, tools, dialog, store, id: parseInt( element.dataset.slashphotoId, 10 ) };
	renderLightboxTools();
}

function renderLightboxTools() {
	if ( ! lightboxView || ! lightboxView.dialog.open ) {
		return;
	}
	const { tools, store, id, dialog } = lightboxView;
	const selected = store.has( id );
	tools.replaceChildren();

	const pick = button( 'slashphoto-lightbox__button slashphoto-lightbox__pick', HEART );
	pick.setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
	pick.setAttribute( 'aria-label', selected ? i18n.deselect || 'Remove from selection' : i18n.select || 'Add to selection' );
	pick.disabled = store.locked;
	pick.addEventListener( 'click', () => store.toggle( id ) );
	tools.append( pick );

	if ( 'server' === store.mode && selected && ! store.locked ) {
		const note = button( 'slashphoto-lightbox__button slashphoto-lightbox__note-button', NOTE );
		note.setAttribute( 'aria-label', i18n.note || 'Note' );
		note.setAttribute( 'aria-pressed', store.note( id ) ? 'true' : 'false' );
		note.addEventListener( 'click', () => openNote( dialog, store, id ) );
		tools.append( note );
	}
}

function openNote( dialog, store, id ) {
	dialog.querySelector( '.slashphoto-lightbox__note' )?.remove();
	const form = document.createElement( 'form' );
	form.className = 'slashphoto-lightbox__note';
	const uid = 'slashphoto-note-' + id;
	form.innerHTML = `
		<label class="slashphoto-lightbox__note-label" for="${ uid }"></label>
		<textarea id="${ uid }" rows="3"></textarea>
		<div class="slashphoto-lightbox__note-actions">
			<button type="submit" class="slashphoto-lightbox__note-save"></button>
			<button type="button" class="slashphoto-lightbox__note-cancel"></button>
		</div>
	`;
	const textarea = form.querySelector( 'textarea' );
	form.querySelector( 'label' ).textContent = i18n.noteLabel || 'Note';
	form.querySelector( '.slashphoto-lightbox__note-save' ).textContent = i18n.save || 'Save';
	form.querySelector( '.slashphoto-lightbox__note-cancel' ).textContent = i18n.cancel || 'Cancel';
	textarea.maxLength = NOTE_LIMIT;
	textarea.value = store.note( id );

	const close = () => {
		form.remove();
		dialog.querySelector( '.slashphoto-lightbox__note-button' )?.focus();
	};
	form.querySelector( '.slashphoto-lightbox__note-cancel' ).addEventListener( 'click', close );
	textarea.addEventListener( 'keydown', ( event ) => {
		// Esc closes the note, not the whole lightbox.
		if ( 'Escape' === event.key ) {
			event.preventDefault();
			event.stopPropagation();
			close();
		}
	} );
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		if ( await store.setNote( id, textarea.value.trim() ) ) {
			close();
		}
	} );

	dialog.append( form );
	textarea.focus();
}

/* ------------------------------------------------------------------ */

async function init() {
	document.addEventListener( 'slashphoto:lightbox-show', onLightboxShow );
	const galleries = [ ...document.querySelectorAll( '.slashphoto-gallery[data-slashphoto-proofing]' ) ];
	for ( const gallery of galleries ) {
		if ( gallery.dataset.slashphotoProofingReady ) {
			continue;
		}
		gallery.dataset.slashphotoProofingReady = 'loading';
		const store = await storeFor( parseInt( gallery.dataset.slashphotoProofing, 10 ) );
		store.subscribe( renderLightboxTools );
		new GalleryView( gallery, store ); // eslint-disable-line no-new
	}
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
