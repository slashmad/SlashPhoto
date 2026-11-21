( function () {
	'use strict';

	const table = document.querySelector( '[data-slashphoto-products]' );
	const template = document.querySelector( '[data-slashphoto-product-template]' );
	const add = document.querySelector( '[data-slashphoto-add-product]' );
	if ( ! table || ! template || ! add ) {
		return;
	}

	let next = table.tBodies[ 0 ].rows.length + 1;
	const strings = window.slashPhotoPriceLists;
	const rows = ( textarea ) => textarea.value.split( /\r?\n/u ).filter( ( line ) => line.trim() );
	const pairKey = ( pair ) => JSON.stringify( pair.map( ( cell ) => cell.trim().replace( /\s+/gu, ' ' ) ) );

	function applyTemplate( button ) {
		const panel = button.closest( '[data-slashphoto-print-options]' );
		const select = panel.querySelector( '[data-slashphoto-print-template]' );
		const status = panel.querySelector( '[data-slashphoto-template-status]' );
		const textarea = panel.querySelector( '[data-slashphoto-variant-input]' );
		const pairs = JSON.parse( select.selectedOptions[ 0 ].dataset.combinations || '[]' );
		const existing = rows( textarea );
		const keys = new Set( existing.map( ( line ) => pairKey( line.split( '|' ).slice( 0, 2 ) ) ) );
		const additions = pairs.filter( ( pair ) => ! keys.has( pairKey( pair ) ) );
		if ( ! additions.length ) {
			status.textContent = strings.duplicate;
			return;
		}
		if ( existing.length + additions.length > strings.limit ) {
			status.textContent = strings.full;
			return;
		}

		const prefix = textarea.value.trimEnd();
		const appended = additions.map( ( [ paper, frame ] ) => paper + ' | ' + frame + ' | ' );
		textarea.value = [ prefix, ...appended ].filter( Boolean ).join( '\n' );
		textarea.setCustomValidity( '' );
		status.textContent = strings.added;
		textarea.focus();
		const firstPrice = ( prefix ? prefix.length + 1 : 0 ) + appended[ 0 ].length;
		textarea.setSelectionRange( firstPrice, firstPrice );
	}

	add.addEventListener( 'click', () => {
		const html = template.innerHTML.replaceAll( '__i__', 'n' + next++ );
		table.tBodies[ 0 ].insertAdjacentHTML( 'beforeend', html );
		table.tBodies[ 0 ].lastElementChild.querySelector( 'input[type="text"]' ).focus();
	} );

	table.addEventListener( 'change', ( event ) => {
		if ( event.target.matches( 'select[name$="[kind]"]' ) ) {
			const row = event.target.closest( 'tr' );
			row.querySelector( '[data-slashphoto-package-fields]' ).hidden = 'package' !== event.target.value;
			row.querySelector( '[data-slashphoto-print-options]' ).hidden = 'print' !== event.target.value;
			row.querySelector( '[data-slashphoto-variant-input]' ).setCustomValidity( '' );
		}
		if ( event.target.matches( '[data-slashphoto-print-template]' ) ) {
			const tools = event.target.closest( '.slashphoto-print-template' );
			tools.querySelector( '[data-slashphoto-add-combinations]' ).disabled = ! event.target.value;
		}
	} );

	table.addEventListener( 'click', ( event ) => {
		const remove = event.target.closest( '[data-slashphoto-remove-product]' );
		const apply = event.target.closest( '[data-slashphoto-add-combinations]' );
		if ( remove ) {
			remove.closest( 'tr' ).remove();
			add.focus();
		}
		if ( apply ) {
			applyTemplate( apply );
		}
	} );

	table.addEventListener( 'input', ( event ) => {
		if ( event.target.matches( '[data-slashphoto-variant-input]' ) ) {
			event.target.setCustomValidity( '' );
		}
	} );

	table.closest( 'form' ).addEventListener( 'submit', ( event ) => {
		const inputs = table.querySelectorAll( '[data-slashphoto-print-options]:not([hidden]) [data-slashphoto-variant-input]' );
		const pending = Array.from( inputs ).find( ( textarea ) => rows( textarea ).some( ( line ) => /\|\s*$/u.test( line ) ) );
		if ( pending ) {
			event.preventDefault();
			pending.closest( 'details' ).open = true;
			pending.setCustomValidity( strings.missing );
			pending.focus();
			pending.reportValidity();
		}
	}, true );
} )();
