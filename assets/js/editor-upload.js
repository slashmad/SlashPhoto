/**
 * "Upload photos" panel in the block editor sidebar for galleries.
 *
 * Full-size originals go through the chunked uploader (uploader.js), so
 * their size is not limited by the server's upload settings.
 *
 * @package SlashPhoto
 */
( function ( wp, slashphoto ) {
	const { registerPlugin } = wp.plugins;
	const PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || wp.editPost.PluginDocumentSettingPanel;
	const { createElement: el, useState, Fragment } = wp.element;
	const { FormFileUpload, DropZone, Button } = wp.components;
	const { useSelect } = wp.data;
	const { __, _n, sprintf } = wp.i18n;

	const POST_TYPE = 'slashphoto_gallery';
	const config = slashphoto.uploadConfig || {};

	function UploadPanel() {
		const { postType, postId } = useSelect( ( select ) => ( {
			postType: select( 'core/editor' ).getCurrentPostType(),
			postId: select( 'core/editor' ).getCurrentPostId(),
		} ), [] );
		const [ items, setItems ] = useState( [] );
		const [ busy, setBusy ] = useState( false );

		if ( POST_TYPE !== postType ) {
			return null;
		}

		const start = async ( fileList ) => {
			const files = [ ...fileList ];
			if ( ! files.length || busy ) {
				return;
			}
			const offset = items.length;
			setItems( ( current ) => [ ...current, ...files.map( ( file ) => ( { name: file.name, phase: 'waiting' } ) ) ] );
			setBusy( true );
			await slashphoto.uploadAll( files, postId, ( index, state ) => {
				setItems( ( current ) => current.map( ( item, i ) => ( i === offset + index ? { ...item, ...state } : item ) ) );
			} );
			setBusy( false );
		};

		const done = items.filter( ( item ) => 'done' === item.phase ).length;
		const help = config.snapotter
			? __( 'Upload full-size originals, also RAW, HEIC, TIFF and PSD. SnapOtter makes the display copies; originals are kept private.', 'slashphoto' )
			: __( 'Upload full-size originals (JPEG, PNG, WebP, AVIF). Display copies are made automatically; originals are kept private.', 'slashphoto' );

		return el(
			PluginDocumentSettingPanel,
			{ name: 'slashphoto-upload', title: __( 'Upload photos', 'slashphoto' ), className: 'slashphoto-upload-panel', initialOpen: true },
			el( 'p', { className: 'components-base-control__help' }, help ),
			el(
				'div',
				{ style: { position: 'relative' } },
				el( DropZone, { onFilesDrop: start } ),
				el(
					FormFileUpload,
					{
						multiple: true,
						accept: ( config.extensions || [] ).map( ( ext ) => '.' + ext ).join( ',' ),
						onChange: ( event ) => start( event.currentTarget.files ),
						render: ( { openFileDialog } ) => el( Button, { variant: 'secondary', onClick: openFileDialog, disabled: busy, isBusy: busy, __next40pxDefaultSize: true }, __( 'Choose files…', 'slashphoto' ) ),
					}
				)
			),
			items.length > 0 &&
				el(
					'ul',
					{ className: 'slashphoto-upload-list', style: { margin: '12px 0 0', padding: 0, listStyle: 'none' } },
					items.map( ( item, index ) =>
						el(
							'li',
							{ key: index, style: { margin: '0 0 8px' } },
							el( 'div', { style: { overflowWrap: 'anywhere', fontWeight: 500 } }, item.name ),
							'done' !== item.phase && 'error' !== item.phase && el( 'progress', { max: 1, value: item.progress || 0, style: { width: '100%' } } ),
							el( 'div', { className: 'components-base-control__help', style: { color: 'error' === item.phase ? '#cc1818' : undefined }, 'aria-live': 'polite' }, slashphoto.statusText( item ) )
						)
					)
				),
			done > 0 &&
				el(
					Fragment,
					null,
					/* translators: %d: number of images */
					el( 'p', null, sprintf( _n( '%d image added. It shows on the gallery page.', '%d images added. They show on the gallery page.', done, 'slashphoto' ), done ) )
				)
		);
	}

	registerPlugin( 'slashphoto-upload', { render: UploadPanel } );
} )( window.wp, window.slashphoto || {} );
