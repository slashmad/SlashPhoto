/**
 * Chunked uploads of full-size originals to a gallery.
 *
 * Files are sent in pieces small enough for the server's request limit, so
 * the size of an original is not capped by PHP's upload settings. Used by
 * the block editor panel (editor-upload.js) and the classic editor box.
 *
 * @package SlashPhoto
 */
( function ( window, document ) {
	const apiFetch = window.wp.apiFetch;
	const { __, sprintf } = window.wp.i18n;
	const config = window.slashphotoUploadConfig || {};

	const sleep = ( ms ) => new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );

	function extension( name ) {
		const match = /\.([^.]+)$/.exec( name || '' );
		return match ? match[ 1 ].toLowerCase() : '';
	}

	function accepts( file ) {
		return ( config.extensions || [] ).includes( extension( file.name ) );
	}

	/**
	 * Uploads one file and returns the new attachment ({ id, title, thumbnail }).
	 *
	 * @param {File}     file       File.
	 * @param {number}   galleryId  Gallery post ID.
	 * @param {Function} onProgress Called with (fraction, phase) where phase is "uploading" or "processing".
	 * @return {Promise<Object>} Attachment.
	 */
	async function uploadOriginal( file, galleryId, onProgress = () => {} ) {
		const { upload, chunkSize } = await apiFetch( {
			path: `/slashphoto/v1/galleries/${ galleryId }/uploads`,
			method: 'POST',
			data: { name: file.name, size: file.size },
		} );

		try {
			let offset = 0;
			while ( offset < file.size ) {
				for ( let attempt = 1; ; attempt++ ) {
					try {
						const response = await apiFetch( {
							path: `/slashphoto/v1/uploads/${ upload }?offset=${ offset }`,
							method: 'POST',
							body: file.slice( offset, offset + chunkSize ),
							headers: { 'Content-Type': 'application/octet-stream' },
						} );
						offset = response.received;
						break;
					} catch ( error ) {
						// The server already has more (or less): continue from there.
						if ( 'slashphoto_upload_offset' === error?.code && Number.isInteger( error?.data?.received ) ) {
							offset = error.data.received;
							break;
						}
						if ( attempt >= 4 ) {
							throw error;
						}
						await sleep( 1000 * attempt );
					}
				}
				onProgress( offset / file.size, 'uploading' );
			}
			onProgress( 1, 'processing' );
			return await apiFetch( { path: `/slashphoto/v1/uploads/${ upload }/complete`, method: 'POST' } );
		} catch ( error ) {
			apiFetch( { path: `/slashphoto/v1/uploads/${ upload }`, method: 'DELETE' } ).catch( () => {} );
			throw error;
		}
	}

	/**
	 * Uploads files one after another.
	 *
	 * @param {File[]}   files     Files.
	 * @param {number}   galleryId Gallery post ID.
	 * @param {Function} onUpdate  Called with (index, { progress, phase, error, result }).
	 */
	async function uploadAll( files, galleryId, onUpdate ) {
		for ( let index = 0; index < files.length; index++ ) {
			const file = files[ index ];
			if ( ! accepts( file ) ) {
				/* translators: %s: file name */
				onUpdate( index, { phase: 'error', error: sprintf( __( '%s: this file type cannot be added.', 'slashphoto' ), file.name ) } );
				continue;
			}
			try {
				const result = await uploadOriginal( file, galleryId, ( progress, phase ) => onUpdate( index, { progress, phase } ) );
				onUpdate( index, { phase: 'done', progress: 1, result } );
			} catch ( error ) {
				onUpdate( index, { phase: 'error', error: error?.message || __( 'Upload failed.', 'slashphoto' ) } );
			}
		}
	}

	function statusText( state ) {
		if ( 'error' === state.phase ) {
			return state.error;
		}
		if ( 'done' === state.phase ) {
			return __( 'Added', 'slashphoto' );
		}
		if ( 'processing' === state.phase ) {
			return __( 'Making the display copy…', 'slashphoto' );
		}
		if ( 'uploading' === state.phase ) {
			/* translators: %d: percentage uploaded */
			return sprintf( __( 'Uploading %d%%', 'slashphoto' ), Math.round( ( state.progress || 0 ) * 100 ) );
		}
		return __( 'Waiting', 'slashphoto' );
	}

	/**
	 * Classic editor box: a file input and a progress list.
	 *
	 * @param {HTMLElement} box Element with data-slashphoto-upload="<gallery ID>".
	 */
	function initBox( box ) {
		const input = box.querySelector( 'input[type="file"]' );
		const list = box.querySelector( '.slashphoto-upload-list' );
		input.addEventListener( 'change', async () => {
			const files = [ ...input.files ];
			input.disabled = true;
			const rows = files.map( ( file ) => {
				const li = document.createElement( 'li' );
				li.innerHTML = '<span class="name"></span> <progress max="1" value="0"></progress> <span class="status" aria-live="polite"></span>';
				li.querySelector( '.name' ).textContent = file.name;
				li.querySelector( '.status' ).textContent = statusText( {} );
				list.append( li );
				return li;
			} );
			await uploadAll( files, parseInt( box.dataset.slashphotoUpload, 10 ), ( index, state ) => {
				rows[ index ].querySelector( 'progress' ).value = state.progress || 0;
				rows[ index ].querySelector( '.status' ).textContent = statusText( state );
				rows[ index ].classList.toggle( 'is-error', 'error' === state.phase );
			} );
			input.value = '';
			input.disabled = false;
		} );
	}

	window.slashphoto = Object.assign( window.slashphoto || {}, { uploadOriginal, uploadAll, accepts, statusText, uploadConfig: config } );

	function ready() {
		document.querySelectorAll( '[data-slashphoto-upload]' ).forEach( initBox );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )( window, document );
