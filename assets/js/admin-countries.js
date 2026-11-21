/* Search and label the native shipping country checkboxes. */
( function () {
	const picker = document.querySelector( '[data-slashphoto-country-picker]' );
	if ( ! picker ) {
		return;
	}
	const list = picker.querySelector( '[data-countries]' );
	const search = picker.querySelector( 'input[type="search"]' );
	const summary = picker.querySelector( '[data-selection]' );
	const noResults = picker.querySelector( '[data-no-results]' );
	const language = document.documentElement.lang || 'en';
	let names;
	try {
		names = new Intl.DisplayNames( [ language ], { type: 'region', fallback: 'none' } );
	} catch {
		// The server's labels and country codes remain available.
	}
	const rows = Array.from( list.querySelectorAll( 'label' ), ( row ) => {
		const label = row.querySelector( 'span' );
		const input = row.querySelector( 'input' );
		label.textContent = names?.of( input.value ) ?? label.textContent;
		return { row, input, name: label.textContent };
	} );
	// Keep the first shipping destination in the same position on resave.
	rows.sort( ( a, b ) => Number( b.input.checked ) - Number( a.input.checked ) || ( a.input.checked ? 0 : a.name.localeCompare( b.name ) ) );
	list.append( ...rows.map( ( item ) => item.row ) );

	function showSelection() {
		const chosen = rows.filter( ( item ) => item.input.checked ).map( ( item ) => item.name );
		const more = chosen.length > 4 ? ' +' + ( chosen.length - 4 ) : '';
		summary.textContent = chosen.length ? chosen.slice( 0, 4 ).join( ', ' ) + more : summary.dataset.empty;
	}
	showSelection();
	list.addEventListener( 'change', showSelection );
	search.addEventListener( 'input', () => {
		const term = search.value.trim().toLocaleLowerCase();
		rows.forEach( ( { row, input, name } ) => {
			row.hidden = ! name.toLocaleLowerCase().includes( term ) && ! input.value.toLowerCase().includes( term );
		} );
		noResults.hidden = rows.some( ( { row } ) => ! row.hidden );
		list.hidden = ! noResults.hidden;
		list.scrollTop = 0;
	} );
	search.addEventListener( 'keydown', ( event ) => {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
		}
	} );
	search.hidden = false;
} )();
