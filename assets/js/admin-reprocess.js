/**
 * "Make new copies" in the gallery workspace: processes the gallery's photos
 * in batches through the REST API and reports progress and failures.
 */
( function ( wp, document ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;

	const button = document.querySelector( '[data-reprocess-photos]' );
	const status = document.querySelector( '[data-reprocess-status]' );
	if ( ! button || ! status ) {
		return;
	}

	const show = ( text, failures, error ) => {
		status.hidden = false;
		status.classList.toggle( 'slashphoto-workspace__reprocess--error', !! error || failures.length > 0 );
		status.replaceChildren();
		const line = document.createElement( 'p' );
		line.textContent = text;
		status.append( line );
		if ( failures.length ) {
			const list = document.createElement( 'ul' );
			for ( const failure of failures ) {
				const item = document.createElement( 'li' );
				item.textContent = sprintf(
					/* translators: 1: photo title, 2: reason */
					__( '%1$s: %2$s', 'slashphoto' ),
					failure.title || '#' + failure.id,
					failure.message
				);
				list.append( item );
			}
			status.append( list );
		}
	};

	button.addEventListener( 'click', async () => {
		// eslint-disable-next-line no-alert -- a simple confirmation before a long job.
		if ( ! window.confirm( __( 'Make new web copies of every photo in this gallery from the originals, with the current settings? This can take a while. The originals are not changed.', 'slashphoto' ) ) ) {
			return;
		}
		button.disabled = true;
		const failures = [];
		let after = 0;
		let done = 0;
		let total = 0;
		show( __( 'Starting…', 'slashphoto' ), failures, false );
		try {
			for ( ;; ) {
				const result = await wp.apiFetch( {
					path: '/slashphoto/v1/galleries/' + button.dataset.gallery + '/reprocess',
					method: 'POST',
					data: { after },
				} );
				done += result.done;
				failures.push( ...result.failed );
				total = result.total;
				after = result.next;
				const handled = total - result.remaining;
				show(
					sprintf(
						/* translators: 1: photos handled so far, 2: photos in the gallery */
						__( 'Processing photos: %1$d of %2$d…', 'slashphoto' ),
						handled,
						total
					),
					failures,
					false
				);
				if ( result.remaining <= 0 || ( ! result.done && ! result.failed.length ) ) {
					break;
				}
			}
			show(
				failures.length
					? sprintf(
						/* translators: 1: photos with new copies, 2: photos left unchanged */
						__( 'New copies made for %1$d photos. %2$d could not be processed and were left unchanged:', 'slashphoto' ),
						done,
						failures.length
					)
					: sprintf(
						/* translators: %d: number of photos */
						_n( 'New copies made for %d photo. Reload the page to see them.', 'New copies made for %d photos. Reload the page to see them.', done, 'slashphoto' ),
						done
					),
				failures,
				false
			);
		} catch ( error ) {
			show(
				sprintf(
					/* translators: %s: error message */
					__( 'Stopped: %s Photos already done keep their new copies; the rest are unchanged.', 'slashphoto' ),
					error.message || ''
				),
				failures,
				true
			);
		} finally {
			button.disabled = false;
		}
	} );
} )( window.wp, document );
