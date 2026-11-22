/**
 * SlashPhoto shop: add images to the cart from the lightbox, and the cart
 * and checkout page.
 *
 * The cart is kept in this browser only (localStorage: image IDs, product
 * IDs, print option IDs and quantities). Prices, names and availability always come from the
 * site, which checks again that the visitor can see each image.
 *
 * @package SlashPhoto
 */

const DATA_ID = 'wp-script-module-data-@slashphoto/shop';
const CART_KEY = 'slashphoto-cart';
const COUPON_KEY = 'slashphoto-coupon';
const GIFT_KEY = 'slashphoto-gift-card';
const MAX_PACKAGE = 500;
const MAX_LINES = 200;
const MAX_QTY = 99;

const config = readConfig();
const i18n = config.i18n || {};
const cartListeners = new Set();
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

const CART =
	'<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M3 4h2.2l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.4a1.5 1.5 0 0 0 1.5-1.1L20.5 8H6.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="19.5" r="1.4" fill="currentColor"/><circle cx="17" cy="19.5" r="1.4" fill="currentColor"/></svg>';

/* ------------------------------------------------------------------ */
/* Cart in this browser                                                */
/* ------------------------------------------------------------------ */

function readCart() {
	try {
		const value = JSON.parse( window.localStorage.getItem( CART_KEY ) || '[]' );
		if ( ! Array.isArray( value ) ) {
			return [];
		}
		return value
			.map( ( item ) => {
				if ( item && item.giftcard ) {
					return {
						giftcard: parseInt( item.giftcard, 10 ) || 0,
						qty: Math.min( MAX_QTY, Math.max( 1, parseInt( item.qty, 10 ) || 1 ) ),
					};
				}
				if ( item && item.package ) {
					return {
						package: String( item.package ).replace( /[^a-z0-9]/g, '' ).slice( 0, 20 ),
						product: String( item.product || '' ),
						gallery: parseInt( item.gallery, 10 ) || 0,
						count: parseInt( item.count, 10 ) || 0,
						images: ( Array.isArray( item.images ) ? item.images : [] ).map( Number ).filter( ( id ) => id > 0 ).slice( 0, MAX_PACKAGE ),
					};
				}
				return {
					image: parseInt( item?.image, 10 ) || 0,
					product: String( item?.product || '' ),
					variant: String( item?.variant || '' ),
					qty: Math.min( MAX_QTY, Math.max( 1, parseInt( item?.qty, 10 ) || 1 ) ),
				};
			} )
			.filter( ( item ) => ( item.giftcard ? item.giftcard > 0 : /^[a-f0-9]{8}$/.test( item.product ) && ( item.package ? item.package.length > 0 : item.image > 0 ) ) )
			.slice( 0, MAX_LINES );
	} catch {
		return [];
	}
}

let memoryCart = null; // Used when storage is disabled.

function getCart() {
	return memoryCart ?? readCart();
}

function setCart( items ) {
	const clean = items.slice( 0, MAX_LINES );
	try {
		if ( clean.length ) {
			window.localStorage.setItem( CART_KEY, JSON.stringify( clean ) );
		} else {
			window.localStorage.removeItem( CART_KEY );
		}
		memoryCart = null;
	} catch {
		memoryCart = clean;
	}
	cartListeners.forEach( ( listener ) => listener( clean ) );
}

function lineKey( item ) {
	if ( item.giftcard ) {
		return 'gc:' + item.giftcard;
	}
	if ( item.package ) {
		return 'pkg:' + item.package;
	}
	return item.image + ':' + item.product + ( item.variant ? ':' + item.variant : '' );
}

function addToCart( image, product, variant, qty, kind ) {
	const items = getCart();
	const key = lineKey( { image, product, variant } );
	const existing = items.find( ( item ) => lineKey( item ) === key );
	if ( existing ) {
		// A download is bought once; prints add up.
		existing.qty = 'download' === kind ? 1 : Math.min( MAX_QTY, existing.qty + qty );
	} else {
		items.push( { image, product, variant, qty: 'download' === kind ? 1 : qty } );
	}
	setCart( items );
}

/**
 * Adds a photo to a package: to an unfinished package of that kind, or a
 * new one. A whole-gallery package (count 0) is added once per gallery.
 *
 * @return {{ added: boolean, chosen: number, count: number }} Result.
 */
function addToPackage( product, gallery, image ) {
	const items = getCart();
	const count = product.count;
	const mine = items.filter( ( item ) => item.package && item.product === product.id );
	if ( ! count ) {
		if ( mine.some( ( item ) => item.gallery === gallery ) ) {
			return { added: false, chosen: 0, count };
		}
		items.push( { package: newId(), product: product.id, gallery, count: 0, images: [] } );
		setCart( items );
		return { added: true, chosen: 0, count };
	}
	const holding = mine.find( ( item ) => item.images.includes( image ) );
	if ( holding ) {
		return { added: false, chosen: holding.images.length, count };
	}
	let open = mine.find( ( item ) => item.images.length < count );
	if ( ! open ) {
		open = { package: newId(), product: product.id, gallery, count, images: [] };
		items.push( open );
	}
	open.images.push( image );
	setCart( items );
	return { added: true, chosen: open.images.length, count };
}

function newId() {
	return Math.random().toString( 36 ).slice( 2, 12 ) || 'p' + Date.now();
}

/**
 * Adds a gift card of an amount (minor units) to the cart.
 *
 * @param {number} amount Amount.
 */
function addGiftCard( amount ) {
	const items = getCart();
	const existing = items.find( ( item ) => item.giftcard === amount );
	if ( existing ) {
		existing.qty = Math.min( MAX_QTY, existing.qty + 1 );
	} else {
		items.push( { giftcard: amount, qty: 1 } );
	}
	setCart( items );
}

let memoryGift = ''; // Used when storage is disabled.

function getGift() {
	try {
		return window.localStorage.getItem( GIFT_KEY ) || '';
	} catch {
		return memoryGift;
	}
}

function setGift( code ) {
	memoryGift = code;
	try {
		if ( code ) {
			window.localStorage.setItem( GIFT_KEY, code );
		} else {
			window.localStorage.removeItem( GIFT_KEY );
		}
	} catch {
		// Kept in memory for this page view.
	}
}

function cartCount( items = getCart() ) {
	return items.reduce( ( sum, item ) => sum + ( item.package ? 1 : item.qty ), 0 );
}

function getCoupon() {
	try {
		return window.localStorage.getItem( COUPON_KEY ) || '';
	} catch {
		return '';
	}
}

function setCoupon( code ) {
	try {
		if ( code ) {
			window.localStorage.setItem( COUPON_KEY, code );
		} else {
			window.localStorage.removeItem( COUPON_KEY );
		}
	} catch {
		// Without storage the code lasts for this page view.
	}
}

window.addEventListener( 'storage', ( event ) => {
	if ( CART_KEY === event.key ) {
		cartListeners.forEach( ( listener ) => listener( getCart() ) );
	}
} );

async function api( path, body ) {
	const response = await fetch( config.restUrl + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			...( config.nonce ? { 'X-WP-Nonce': config.nonce } : {} ),
		},
		body: JSON.stringify( body ),
	} );
	const data = await response.json().catch( () => ( {} ) );
	if ( ! response.ok ) {
		const error = new Error( data.message || i18n.error || 'Error' );
		error.status = response.status;
		error.code = data.code || '';
		error.data = data.data || {};
		throw error;
	}
	return data;
}

/* ------------------------------------------------------------------ */
/* Galleries: cart bar and the lightbox "Buy" panel                     */
/* ------------------------------------------------------------------ */

function offerFor( gallery ) {
	try {
		const products = JSON.parse( gallery.dataset.slashphotoShop || '[]' );
		return Array.isArray( products ) ? products : [];
	} catch {
		return [];
	}
}

function buildBar( gallery ) {
	const bar = document.createElement( 'div' );
	bar.className = 'slashphoto-shopbar';
	bar.setAttribute( 'role', 'region' );
	bar.setAttribute( 'aria-label', i18n.cart || 'Cart' );
	bar.innerHTML = '<p class="slashphoto-shopbar__status" aria-live="polite"></p><a class="slashphoto-shopbar__link wp-element-button"></a>';
	const status = bar.querySelector( '.slashphoto-shopbar__status' );
	const link = bar.querySelector( '.slashphoto-shopbar__link' );
	link.href = config.cartUrl || '#';
	link.textContent = i18n.goToCart || 'Go to cart';

	const render = ( items ) => {
		const count = cartCount( items );
		status.textContent = count ? format( 1 === count ? i18n.countOne : i18n.count, count ) : i18n.hint || '';
		link.hidden = ! count || ! config.cartUrl;
		bar.classList.toggle( 'is-empty', ! count );
	};
	cartListeners.add( render );
	render( getCart() );

	const anchor = gallery.querySelector( '.slashphoto-proofing' ) || gallery.querySelector( '.slashphoto-grid' ) || gallery.lastElementChild;
	( anchor || gallery ).after( bar );
	gallery.dataset.slashphotoShopReady = '1';
}

function onLightboxShow( event ) {
	const { element, tools, dialog } = event.detail || {};
	dialog?.querySelector( '.slashphoto-lightbox__buy' )?.remove();
	const token = ++lightboxToken;
	const gallery = element?.closest( '.slashphoto-gallery[data-slashphoto-shop]' );
	if ( ! gallery || ! tools ) {
		return;
	}
	const products = offerFor( gallery );
	if ( ! products.length ) {
		return;
	}
	const id = parseInt( element.dataset.slashphotoId, 10 );
	const group = document.createElement( 'span' );
	group.className = 'slashphoto-lightbox__group slashphoto-lightbox__group--shop';
	const buy = document.createElement( 'button' );
	buy.type = 'button';
	buy.className = 'slashphoto-lightbox__button slashphoto-lightbox__buy-button';
	buy.innerHTML = CART;
	buy.setAttribute( 'aria-label', i18n.buy || 'Buy this photo' );
	buy.setAttribute( 'aria-expanded', 'false' );
	buy.addEventListener( 'click', () => {
		if ( token !== lightboxToken ) {
			return;
		}
		const open = dialog.querySelector( '.slashphoto-lightbox__buy' );
		if ( open ) {
			open.remove();
			buy.setAttribute( 'aria-expanded', 'false' );
		} else {
			openBuyPanel( dialog, buy, id, products );
		}
	} );
	group.append( buy );
	tools.append( group );
}

function openBuyPanel( dialog, opener, id, products ) {
	const panel = document.createElement( 'div' );
	panel.className = 'slashphoto-lightbox__buy';
	panel.setAttribute( 'role', 'group' );
	panel.setAttribute( 'aria-label', i18n.buy || 'Buy this photo' );

	const list = document.createElement( 'ul' );
	list.className = 'slashphoto-lightbox__products';
	const status = document.createElement( 'p' );
	status.className = 'slashphoto-lightbox__buy-status';
	status.setAttribute( 'aria-live', 'polite' );

	products.forEach( ( product ) => {
		const row = document.createElement( 'li' );
		row.className = 'slashphoto-lightbox__product';
		const uid = `slashphoto-qty-${ id }-${ product.id }`;
		row.innerHTML = `
			<span class="slashphoto-lightbox__product-name"></span>
			<span class="slashphoto-lightbox__product-price"></span>
			<span class="slashphoto-lightbox__product-actions"></span>
		`;
		row.querySelector( '.slashphoto-lightbox__product-name' ).textContent = product.name;
		const price = row.querySelector( '.slashphoto-lightbox__product-price' );
		price.textContent = product.price;
		const actions = row.querySelector( '.slashphoto-lightbox__product-actions' );
		if ( 'package' === product.kind ) {
			const info = document.createElement( 'span' );
			info.className = 'slashphoto-lightbox__product-info';
			info.textContent = product.count
				? format( i18n.packageOf || '%1$d photos you choose, each: %2$s', product.count, product.includes )
				: format( i18n.packageAll || 'Every photo in this gallery, each: %s', product.includes );
			row.insertBefore( info, actions );
			const add = document.createElement( 'button' );
			add.type = 'button';
			add.className = 'slashphoto-lightbox__add';
			add.textContent = product.count ? i18n.addToPackage || 'Add to package' : i18n.add || 'Add';
			add.setAttribute( 'aria-label', format( product.count ? i18n.addToPackageName || 'Add this photo to %s' : i18n.addProduct || 'Add %s to cart', product.name ) );
			add.addEventListener( 'click', () => {
				const result = addToPackage( product, product.gallery, id );
				let text;
				if ( ! product.count ) {
					text = format( result.added ? i18n.added || 'Added: %s.' : i18n.alreadyInCart || '%s is already in your cart.', product.name );
				} else if ( ! result.added ) {
					text = format( i18n.alreadyInPackage || 'This photo is already in %s.', product.name );
				} else {
					text = format( i18n.addedToPackage || 'Added to %1$s: %2$d of %3$d photos.', product.name, result.chosen, result.count );
				}
				status.replaceChildren( document.createTextNode( text + ' ' ) );
				if ( config.cartUrl ) {
					const link = document.createElement( 'a' );
					link.href = config.cartUrl;
					link.textContent = i18n.goToCart || 'Go to cart';
					status.append( link );
				}
			} );
			actions.append( add );
			list.append( row );
			return;
		}
		let qty = null;
		if ( 'print' === product.kind ) {
			const label = document.createElement( 'label' );
			label.className = 'screen-reader-text';
			label.htmlFor = uid;
			label.textContent = format( i18n.quantityFor || 'Quantity, %s', product.name );
			qty = document.createElement( 'input' );
			qty.type = 'number';
			qty.id = uid;
			qty.min = '1';
			qty.max = String( MAX_QTY );
			qty.value = '1';
			qty.className = 'slashphoto-lightbox__qty';
			actions.append( label, qty );
		}
		const add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'slashphoto-lightbox__add';
		add.textContent = i18n.add || 'Add';
		let option = product.variants?.[ 0 ];
		const productName = () => option ? product.name + ' — ' + option.label : product.name;
		const updateChoice = () => {
			price.textContent = option?.price ?? product.price;
			add.setAttribute( 'aria-label', format( i18n.addProduct || 'Add %s to cart', productName() ) );
		};
		if ( option ) {
			const field = document.createElement( 'label' );
			field.className = 'slashphoto-lightbox__print-options';
			const label = document.createElement( 'span' );
			label.textContent = i18n.printOptions || 'Paper and framing';
			const select = document.createElement( 'select' );
			select.setAttribute( 'aria-label', format( i18n.printOptionsFor || 'Paper and framing, %s', product.name ) );
			product.variants.forEach( ( choice ) => {
				select.add( new Option( choice.label + ' — ' + choice.price, choice.id ) );
			} );
			price.setAttribute( 'aria-live', 'polite' );
			select.addEventListener( 'change', () => {
				option = product.variants[ select.selectedIndex ];
				updateChoice();
			} );
			field.append( label, select );
			row.insertBefore( field, actions );
		}
		updateChoice();
		add.addEventListener( 'click', () => {
			const amount = qty ? Math.min( MAX_QTY, Math.max( 1, parseInt( qty.value, 10 ) || 1 ) ) : 1;
			addToCart( id, product.id, option?.id ?? '', amount, product.kind );
			status.replaceChildren( document.createTextNode( format( i18n.added || 'Added: %s.', productName() ) + ' ' ) );
			if ( config.cartUrl ) {
				const link = document.createElement( 'a' );
				link.href = config.cartUrl;
				link.textContent = i18n.goToCart || 'Go to cart';
				status.append( link );
			}
		} );
		actions.append( add );
		list.append( row );
	} );

	const close = document.createElement( 'button' );
	close.type = 'button';
	close.className = 'slashphoto-lightbox__buy-close';
	close.textContent = i18n.close || 'Close';
	const closePanel = () => {
		panel.remove();
		opener.setAttribute( 'aria-expanded', 'false' );
		opener.focus();
	};
	close.addEventListener( 'click', closePanel );
	panel.addEventListener( 'keydown', ( event ) => {
		// Esc closes the panel, not the whole lightbox.
		if ( 'Escape' === event.key ) {
			event.preventDefault();
			event.stopPropagation();
			closePanel();
		}
	} );

	panel.append( list, status, close );
	dialog.append( panel );
	opener.setAttribute( 'aria-expanded', 'true' );
	panel.querySelector( 'select, input, button' )?.focus();
}

/* ------------------------------------------------------------------ */
/* Cart and checkout page                                               */
/* ------------------------------------------------------------------ */

class Checkout {
	constructor( root ) {
		this.root = root;
		this.cart = root.querySelector( '[data-slashphoto-cart]' );
		this.form = root.querySelector( '[data-slashphoto-checkout-form]' );
		this.error = root.querySelector( '[data-slashphoto-checkout-error]' );
		this.address = root.querySelector( '[data-slashphoto-address]' );
		this.coupon = root.querySelector( '[data-slashphoto-coupon]' );
		this.gift = root.querySelector( '[data-slashphoto-gift-card-code]' );
		this.residence = root.querySelector( '[data-slashphoto-residence]' );
		this.quote = null;
		this.notice = '';
		this.busy = false;

		if ( this.coupon ) {
			const input = this.coupon.querySelector( 'input' );
			input.value = getCoupon();
			const applyCode = () => {
				setCoupon( input.value.trim().toUpperCase() );
				this.refresh();
			};
			this.coupon.querySelector( 'button' ).addEventListener( 'click', applyCode );
			input.addEventListener( 'keydown', ( event ) => {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					applyCode();
				}
			} );
		}

		if ( this.gift ) {
			const input = this.gift.querySelector( 'input' );
			input.value = getGift();
			const applyGift = () => {
				setGift( input.value.trim().toUpperCase() );
				this.refresh();
			};
			this.gift.querySelector( 'button' ).addEventListener( 'click', applyGift );
			input.addEventListener( 'keydown', ( event ) => {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					applyGift();
				}
			} );
		}

		// VAT can depend on the country: the delivery country for prints,
		// otherwise the country the customer lives in.
		this.form?.addEventListener( 'change', ( event ) => {
			if ( [ 'country', 'residence' ].includes( event.target.name ) && this.quote?.taxByCountry ) {
				this.refresh();
			}
		} );

		this.form?.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			this.submit();
		} );
		cartListeners.add( () => this.refresh() );
		root.dataset.slashphotoCheckoutReady = '1';
		this.refresh();
	}

	async refresh() {
		const items = getCart();
		if ( ! items.length ) {
			this.quote = null;
			this.render();
			return;
		}
		try {
			const quote = await api( 'quote', { items, coupon: getCoupon(), country: this.country(), gift_card: getGift() } );
			// The first quote tells whether prints decide the country; ask again if so.
			if ( quote.taxByCountry && quote.taxCountry !== this.country( quote ) && this.country( quote ) ) {
				this.quote = quote;
				return this.refresh();
			}
			this.apply( quote );
		} catch ( error ) {
			this.cart.textContent = error.message;
		}
	}

	/**
	 * The country VAT is charged for: the delivery country when prints are
	 * in the cart, otherwise the country the customer lives in.
	 *
	 * @param {Object|null} quote Quote to decide by (default: the current one).
	 * @return {string} Country code or ''.
	 */
	country( quote = this.quote ) {
		const field = ( name ) => this.form?.elements.namedItem( name )?.value || '';
		return quote?.needsShipping ? field( 'country' ) : field( 'residence' );
	}

	apply( quote ) {
		this.quote = quote;
		if ( quote.unavailable?.length ) {
			const gone = new Set( quote.unavailable );
			this.notice = i18n.unavailable || '';
			setCart( getCart().filter( ( item ) => ! gone.has( lineKey( item ) ) ) );
			return; // setCart() triggers a new quote.
		}
		// Photos the site left out of a package (not visible, not for sale).
		const chosen = new Map( quote.lines.filter( ( line ) => line.package ).map( ( line ) => [ line.package, line.images.map( ( image ) => image.id ) ] ) );
		const cart = getCart();
		const trimmed = cart.filter( ( item ) => item.package && item.images.length && chosen.has( item.package ) && chosen.get( item.package ).length < item.images.length );
		if ( trimmed.length ) {
			trimmed.forEach( ( item ) => ( item.images = item.images.filter( ( id ) => chosen.get( item.package ).includes( id ) ) ) );
			this.notice = i18n.unavailable || '';
			setCart( cart );
			return;
		}
		this.render();
	}

	render() {
		const quote = this.quote;
		this.cart.replaceChildren();
		if ( this.notice ) {
			const notice = document.createElement( 'p' );
			notice.className = 'slashphoto-cart__notice';
			notice.textContent = this.notice;
			this.cart.append( notice );
		}
		if ( ! quote || ! quote.lines.length ) {
			const empty = document.createElement( 'p' );
			empty.className = 'slashphoto-cart__empty';
			empty.textContent = i18n.empty || 'Your cart is empty.';
			this.cart.append( empty );
			if ( this.form ) {
				this.form.hidden = true;
			}
			if ( this.coupon ) {
				this.coupon.hidden = true;
			}
			if ( this.gift ) {
				this.gift.hidden = true;
			}
			return;
		}
		if ( this.gift ) {
			const message = this.gift.querySelector( '.slashphoto-coupon__message' );
			this.gift.hidden = ! quote.giftCards;
			message.classList.toggle( 'is-error', !! quote.giftCardError );
			message.textContent = quote.giftCardError || ( quote.giftCard ? i18n.giftCardApplied || 'Gift card applied.' : '' );
			message.hidden = ! getGift();
		}
		if ( this.coupon ) {
			this.coupon.hidden = false;
			const message = this.coupon.querySelector( '.slashphoto-coupon__message' );
			const typed = getCoupon();
			message.classList.toggle( 'is-error', !! quote.couponError );
			message.textContent = quote.couponError || ( quote.coupon ? format( i18n.couponApplied || 'Code %s applied.', quote.coupon ) : '' );
			message.hidden = ! typed;
		}

		const list = document.createElement( 'ul' );
		list.className = 'slashphoto-cart__lines';
		quote.lines.forEach( ( line ) => list.append( this.renderLine( line ) ) );

		const totals = document.createElement( 'dl' );
		totals.className = 'slashphoto-cart__totals';
		const row = ( label, value, className = '' ) => {
			const dt = document.createElement( 'dt' );
			const dd = document.createElement( 'dd' );
			dt.textContent = label;
			dd.textContent = value;
			if ( className ) {
				dt.className = className;
				dd.className = className;
			}
			totals.append( dt, dd );
		};
		row( i18n.subtotal || 'Subtotal', quote.subtotal );
		if ( quote.discount ) {
			row( format( i18n.discount || 'Discount (%s)', quote.coupon ), quote.discount );
		}
		if ( quote.needsShipping ) {
			row( i18n.shipping || 'Shipping', quote.shipping );
		}
		if ( quote.tax ) {
			row( quote.taxLabel, quote.tax );
		}
		row( i18n.total || 'Total', quote.total, 'slashphoto-cart__total' );
		if ( quote.giftCard ) {
			row( format( i18n.giftCardRow || 'Gift card (…%s)', quote.giftCardHint ), quote.giftCard );
			row( i18n.due || 'To pay', quote.due, 'slashphoto-cart__total' );
		}

		this.cart.append( list, totals );
		if ( this.form ) {
			this.form.hidden = false;
			this.address.hidden = ! quote.needsShipping;
			// Nothing to pay (a gift card covers it, or a free order): no payment method.
			const gateways = this.form.querySelector( '[data-slashphoto-gateways]' );
			if ( gateways ) {
				gateways.hidden = 0 === quote.dueMinor;
			}
			if ( this.residence ) {
				const ask = !! quote.taxByCountry && ! quote.needsShipping;
				this.residence.hidden = ! ask;
				this.residence.querySelector( 'select' ).required = ask;
			}
			[ 'line1', 'postcode', 'city', 'country' ].forEach( ( name ) => {
				const field = this.form.elements.namedItem( name );
				if ( field ) {
					field.required = !! quote.needsShipping;
				}
			} );
		}
	}

	renderPackage( line ) {
		const li = document.createElement( 'li' );
		li.className = 'slashphoto-cart__line slashphoto-cart__line--package';
		li.dataset.key = line.key;
		li.innerHTML = `
			<img class="slashphoto-cart__thumb" alt="" width="64" height="64" loading="lazy">
			<span class="slashphoto-cart__what"><span class="slashphoto-cart__name"></span><span class="slashphoto-cart__title"></span><span class="slashphoto-cart__progress"></span></span>
			<span class="slashphoto-cart__qty"></span>
			<span class="slashphoto-cart__price"></span>
			<button type="button" class="slashphoto-cart__remove"></button>
			<ul class="slashphoto-cart__package"></ul>
		`;
		const img = li.querySelector( 'img' );
		if ( line.thumb ) {
			img.src = line.thumb;
		} else {
			img.remove();
		}
		li.querySelector( '.slashphoto-cart__name' ).textContent = line.name;
		li.querySelector( '.slashphoto-cart__title' ).textContent = line.title + ' · ' + line.includes;
		li.querySelector( '.slashphoto-cart__price' ).textContent = line.total;
		const cartItem = getCart().find( ( item ) => item.package === line.package );
		const whole = cartItem && ! cartItem.count;
		const left = line.count - line.images.length;
		li.querySelector( '.slashphoto-cart__progress' ).textContent = whole
			? format( i18n.packageWhole || 'All %d photos', line.images.length )
			: format( left > 0 ? i18n.packageLeft || '%1$d of %2$d photos chosen. Open more photos to add them.' : i18n.packageFull || '%1$d of %2$d photos chosen.', line.images.length, line.count );

		const strip = li.querySelector( '.slashphoto-cart__package' );
		line.images.forEach( ( image ) => {
			const item = document.createElement( 'li' );
			const thumb = document.createElement( 'img' );
			thumb.alt = image.title;
			thumb.width = 48;
			thumb.height = 48;
			thumb.loading = 'lazy';
			if ( image.thumb ) {
				thumb.src = image.thumb;
			}
			item.append( thumb );
			if ( ! whole ) {
				const unpick = document.createElement( 'button' );
				unpick.type = 'button';
				unpick.className = 'slashphoto-cart__unpick';
				unpick.textContent = '×';
				unpick.setAttribute( 'aria-label', format( i18n.removeFromPackage || 'Remove %s from the package', image.title ) );
				unpick.addEventListener( 'click', () => {
					setCart(
						getCart()
							.map( ( entry ) => ( entry.package === line.package ? { ...entry, images: entry.images.filter( ( id ) => id !== image.id ) } : entry ) )
							.filter( ( entry ) => ! entry.package || entry.images.length || ! entry.count )
					);
				} );
				item.append( unpick );
			}
			strip.append( item );
		} );

		const remove = li.querySelector( '.slashphoto-cart__remove' );
		remove.textContent = i18n.remove || 'Remove';
		remove.setAttribute( 'aria-label', format( i18n.removeLine || 'Remove %s', line.name ) );
		remove.addEventListener( 'click', () => {
			this.notice = '';
			setCart( getCart().filter( ( item ) => item.package !== line.package ) );
		} );
		return li;
	}

	renderLine( line ) {
		if ( 'package' === line.kind ) {
			return this.renderPackage( line );
		}
		const li = document.createElement( 'li' );
		li.className = 'slashphoto-cart__line';
		li.dataset.key = line.key;
		li.innerHTML = `
			<img class="slashphoto-cart__thumb" alt="" width="64" height="64" loading="lazy">
			<span class="slashphoto-cart__what"><span class="slashphoto-cart__name"></span><span class="slashphoto-cart__title"></span></span>
			<span class="slashphoto-cart__qty"></span>
			<span class="slashphoto-cart__price"></span>
			<button type="button" class="slashphoto-cart__remove"></button>
		`;
		const img = li.querySelector( 'img' );
		if ( line.thumb ) {
			img.src = line.thumb;
		} else {
			img.remove();
		}
		li.querySelector( '.slashphoto-cart__name' ).textContent = line.name;
		li.querySelector( '.slashphoto-cart__title' ).textContent = line.title;
		li.querySelector( '.slashphoto-cart__price' ).textContent = line.total;
		const qtyCell = li.querySelector( '.slashphoto-cart__qty' );
		if ( 'print' === line.kind || 'giftcard' === line.kind ) {
			const uid = 'slashphoto-cart-qty-' + line.key.replaceAll( ':', '-' );
			const label = document.createElement( 'label' );
			label.className = 'screen-reader-text';
			label.htmlFor = uid;
			label.textContent = format( i18n.quantityFor || 'Quantity, %s', line.name + ', ' + line.title );
			const input = document.createElement( 'input' );
			input.type = 'number';
			input.id = uid;
			input.min = '1';
			input.max = String( MAX_QTY );
			input.value = String( line.qty );
			input.addEventListener( 'change', () => {
				const qty = Math.min( MAX_QTY, Math.max( 1, parseInt( input.value, 10 ) || 1 ) );
				setCart( getCart().map( ( item ) => ( lineKey( item ) === line.key ? { ...item, qty } : item ) ) );
			} );
			qtyCell.append( label, input );
		}
		const remove = li.querySelector( '.slashphoto-cart__remove' );
		remove.textContent = i18n.remove || 'Remove';
		remove.setAttribute( 'aria-label', format( i18n.removeLine || 'Remove %s', line.name + ', ' + line.title ) );
		remove.addEventListener( 'click', () => {
			this.notice = '';
			setCart( getCart().filter( ( item ) => lineKey( item ) !== line.key ) );
		} );
		return li;
	}

	showError( message, field ) {
		this.error.textContent = message;
		this.error.hidden = ! message;
		if ( field ) {
			const input = this.form.elements.namedItem( field );
			if ( input && input.focus ) {
				input.setAttribute( 'aria-invalid', 'true' );
				input.focus();
			}
		}
	}

	async submit() {
		if ( this.busy || ! this.quote ) {
			return;
		}
		this.form.querySelectorAll( '[aria-invalid]' ).forEach( ( el ) => el.removeAttribute( 'aria-invalid' ) );
		if ( ! this.form.checkValidity() ) {
			const invalid = this.form.querySelector( ':invalid' );
			this.showError( i18n.fillIn || 'Fill in the marked fields.', invalid?.name );
			return;
		}
		const value = ( name ) => this.form.elements.namedItem( name )?.value?.trim() ?? '';
		const body = {
			items: getCart(),
			total: this.quote.totalMinor,
			email: value( 'email' ),
			name: value( 'name' ),
			phone: value( 'phone' ),
			note: value( 'note' ),
			coupon: getCoupon(),
			gift_card: getGift(),
			website: value( 'website' ),
			gateway: this.form.querySelector( 'input[name="gateway"]:checked' )?.value || '',
			terms: !! this.form.querySelector( 'input[name="terms"]' )?.checked,
		};
		if ( this.quote.needsShipping ) {
			body.address = { line1: value( 'line1' ), line2: value( 'line2' ), postcode: value( 'postcode' ), city: value( 'city' ), country: value( 'country' ) };
		} else if ( this.quote.taxByCountry ) {
			body.country = value( 'residence' );
		}

		const button = this.form.querySelector( '[type="submit"]' );
		this.busy = true;
		button.disabled = true;
		this.showError( '' );
		try {
			const result = await api( 'orders', body );
			window.location.assign( result.redirect );
		} catch ( error ) {
			this.busy = false;
			button.disabled = false;
			if ( error.data?.quote ) {
				this.quote = error.data.quote;
				this.render();
			}
			if ( 'coupon' === error.data?.field || 'gift_card' === error.data?.field || 'slashphoto_gift_card_changed' === error.code ) {
				this.refresh();
			}
			const field = 'billing_country' === error.data?.field ? 'residence' : error.data?.field;
			this.showError( error.message, [ 'coupon', 'gift_card' ].includes( field ) ? null : field );
		}
	}
}

/* ------------------------------------------------------------------ */

function init() {
	document.addEventListener( 'slashphoto:lightbox-show', onLightboxShow );
	document.querySelectorAll( '.slashphoto-gallery[data-slashphoto-shop]' ).forEach( ( gallery ) => {
		if ( ! gallery.dataset.slashphotoShopReady && offerFor( gallery ).length ) {
			buildBar( gallery );
		}
	} );
	document.querySelectorAll( '[data-slashphoto-gift-cards]' ).forEach( ( root ) => {
		if ( root.dataset.slashphotoGiftCardsReady ) {
			return;
		}
		root.dataset.slashphotoGiftCardsReady = '1';
		const status = root.querySelector( '[data-slashphoto-gift-card-status]' );
		const link = root.querySelector( '[data-slashphoto-gift-card-link]' );
		root.querySelectorAll( '[data-slashphoto-gift-card]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				addGiftCard( parseInt( button.dataset.slashphotoGiftCard, 10 ) );
				status.textContent = format( i18n.added || 'Added: %s.', button.dataset.name );
				if ( link ) {
					link.hidden = false;
				}
			} );
		} );
	} );
	document.querySelectorAll( '[data-slashphoto-checkout]' ).forEach( ( root ) => {
		if ( ! root.dataset.slashphotoCheckoutReady ) {
			new Checkout( root ); // eslint-disable-line no-new
		}
	} );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
