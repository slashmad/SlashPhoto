/**
 * "Access" panel in the block editor sidebar for galleries.
 *
 * Plain JavaScript on the wp.* globals (no build step). Values are post meta
 * registered in GalleryMeta, saved with the post through the REST API.
 *
 * @package SlashPhoto
 */
( function ( wp ) {
	const { registerPlugin } = wp.plugins;
	const PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || wp.editPost.PluginDocumentSettingPanel;
	const { createElement: el, useState } = wp.element;
	const { SelectControl, FormTokenField, TextControl, TextareaControl, Notice, Button, Flex } = wp.components;
	const { useSelect } = wp.data;
	const apiFetch = wp.apiFetch;
	const { useEntityProp } = wp.coreData;
	const { __, sprintf } = wp.i18n;

	const POST_TYPE = 'slashphoto_gallery';
	const META = {
		mode: '_slashphoto_access',
		clients: '_slashphoto_clients',
		expires: '_slashphoto_expires',
		terms: '_slashphoto_terms',
		watermark: '_slashphoto_watermark',
	};

	function ShareLink( { postId, savedMode } ) {
		const [ busy, setBusy ] = useState( false );
		const [ copied, setCopied ] = useState( false );
		const [ renewed, setRenewed ] = useState( '' );
		const saved = useSelect( ( select ) => select( 'core' ).getEditedEntityRecord( 'postType', POST_TYPE, postId )?.slashphoto_share_url || '', [ postId ] );
		const url = renewed || saved;

		if ( 'link' !== savedMode || ! url ) {
			return el( 'p', { className: 'components-base-control__help' }, __( 'Save the gallery to create its secret link.', 'slashphoto' ) );
		}

		const copy = () => {
			window.navigator.clipboard.writeText( url ).then( () => {
				setCopied( true );
				window.setTimeout( () => setCopied( false ), 2000 );
			} );
		};
		const renew = () => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Create a new link? The current link stops working, also for everyone who already opened it.', 'slashphoto' ) ) ) {
				return;
			}
			setBusy( true );
			apiFetch( { path: `/slashphoto/v1/galleries/${ postId }/share-link`, method: 'POST' } )
				.then( ( response ) => setRenewed( response.url ) )
				.finally( () => setBusy( false ) );
		};

		return el(
			'div',
			{ className: 'slashphoto-share-link' },
			el( TextControl, {
				label: __( 'Secret link', 'slashphoto' ),
				value: url,
				readOnly: true,
				onFocus: ( event ) => event.target.select(),
				onChange: () => {},
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
			} ),
			el(
				Flex,
				{ justify: 'flex-start', style: { marginTop: '8px' } },
				el( Button, { variant: 'secondary', onClick: copy }, copied ? __( 'Copied', 'slashphoto' ) : __( 'Copy link', 'slashphoto' ) ),
				el( Button, { variant: 'tertiary', isDestructive: true, isBusy: busy, disabled: busy, onClick: renew }, __( 'New link', 'slashphoto' ) )
			)
		);
	}

	function userLabel( user ) {
		return sprintf( '%1$s (%2$s)', user.name, user.slug );
	}

	function AccessPanel() {
		const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
		const [ meta, setMeta ] = useEntityProp( 'postType', POST_TYPE, 'meta' );
		const [ search, setSearch ] = useState( '' );

		const { password, parent, postId, savedMode } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			const saved = editor.getCurrentPostAttribute( 'meta' );
			return {
				password: editor.getEditedPostAttribute( 'password' ),
				parent: editor.getEditedPostAttribute( 'parent' ),
				postId: editor.getCurrentPostId(),
				savedMode: saved ? saved[ META.mode ] : '',
			};
		}, [] );

		const clientIds = ( meta && meta[ META.clients ] ) || [];
		const users = useSelect(
			( select ) => {
				const core = select( 'core' );
				const selected = clientIds.length ? core.getUsers( { include: clientIds, per_page: 100, context: 'edit' } ) || [] : [];
				const found = search.length > 1 ? core.getUsers( { search, per_page: 20, context: 'edit' } ) || [] : [];
				return { selected, found };
			},
			[ clientIds.join( ',' ), search ]
		);

		if ( POST_TYPE !== postType || ! meta ) {
			return null;
		}

		const update = ( key, value ) => setMeta( { ...meta, [ key ]: value } );
		const known = [ ...users.selected, ...users.found ];
		const byLabel = new Map( known.map( ( user ) => [ userLabel( user ), user.id ] ) );
		const tokens = clientIds.map( ( id ) => {
			const user = known.find( ( candidate ) => candidate.id === id );
			return user ? userLabel( user ) : '#' + id;
		} );

		return el(
			PluginDocumentSettingPanel,
			{ name: 'slashphoto-access', title: __( 'Access', 'slashphoto' ), className: 'slashphoto-access-panel' },
			el( SelectControl, {
				label: __( 'Who can view this gallery', 'slashphoto' ),
				value: meta[ META.mode ] || 'public',
				options: [
					{ value: 'public', label: __( 'Everyone with the link', 'slashphoto' ) },
					{ value: 'link', label: __( 'Only people with the secret link', 'slashphoto' ) },
					{ value: 'clients', label: __( 'Only selected clients (log-in required)', 'slashphoto' ) },
				],
				onChange: ( value ) => update( META.mode, value ),
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
			} ),
			'link' === meta[ META.mode ] && el( ShareLink, { postId, savedMode } ),
			'clients' === meta[ META.mode ] &&
				el( FormTokenField, {
					label: __( 'Clients', 'slashphoto' ),
					value: tokens,
					suggestions: users.found.map( userLabel ),
					onInputChange: setSearch,
					onChange: ( next ) =>
						update(
							META.clients,
							next
								.map( ( token ) => byLabel.get( token ) || ( /^#\d+$/.test( token ) ? parseInt( token.slice( 1 ), 10 ) : 0 ) )
								.filter( Boolean )
						),
					__experimentalShowHowTo: false,
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				} ),
			el(
				'p',
				{ className: 'components-base-control__help' },
				password
					? __( 'Password protected. Change it under Status → Visibility.', 'slashphoto' )
					: __( 'No password. You can add one under Status → Visibility.', 'slashphoto' )
			),
			el( TextControl, {
				type: 'date',
				label: __( 'Available until', 'slashphoto' ),
				help: __( 'After this day only you can see the gallery. Leave empty for no end date.', 'slashphoto' ),
				value: meta[ META.expires ] || '',
				onChange: ( value ) => update( META.expires, value ),
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
			} ),
			el( SelectControl, {
				label: __( 'Watermark', 'slashphoto' ),
				help: __( 'Applies to images uploaded after the change. Originals are never changed.', 'slashphoto' ),
				value: meta[ META.watermark ] || '',
				options: [
					{ value: '', label: __( 'Site default or parent gallery', 'slashphoto' ) },
					{ value: 'on', label: __( 'On', 'slashphoto' ) },
					{ value: 'off', label: __( 'Off', 'slashphoto' ) },
				],
				onChange: ( value ) => update( META.watermark, value ),
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
			} ),
			el( TextareaControl, {
				label: __( 'Terms to accept before viewing', 'slashphoto' ),
				help: __( 'Optional. Acceptance is remembered only in a cookie in the visitor’s browser; nothing is logged.', 'slashphoto' ),
				value: meta[ META.terms ] || '',
				onChange: ( value ) => update( META.terms, value ),
				__nextHasNoMarginBottom: true,
			} ),
			parent > 0 &&
				el( Notice, { status: 'info', isDismissible: false }, __( 'This is a sub-gallery: the password, client list, secret link, end date and terms of the galleries above it also apply.', 'slashphoto' ) )
		);
	}

	registerPlugin( 'slashphoto-access', { render: AccessPanel } );
} )( window.wp );
