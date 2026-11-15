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
		proofing: '_slashphoto_proofing',
		proofingMax: '_slashphoto_proofing_max',
		priceList: '_slashphoto_price_list',
		emailGate: '_slashphoto_email_gate',
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
					{ value: 'members', label: __( 'Anyone logged in to this site', 'slashphoto' ) },
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
			el( SelectControl, {
				label: __( 'Ask for an email address', 'slashphoto' ),
				help: __( 'Visitors give their email address (with consent) before seeing the photos. You find the addresses under SlashPhoto → Visitors. Logged-in visitors are not asked.', 'slashphoto' ),
				value: meta[ META.emailGate ] || '',
				options: [
					{ value: '', label: parent > 0 ? __( 'Same as parent gallery', 'slashphoto' ) : __( 'No', 'slashphoto' ) },
					{ value: 'on', label: __( 'Yes', 'slashphoto' ) },
					{ value: 'off', label: __( 'No, not for this gallery', 'slashphoto' ) },
				].filter( ( option, index ) => parent > 0 || index < 2 ),
				onChange: ( value ) => update( META.emailGate, value ),
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
			} ),
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
				help: __( 'Optional. Each acceptance is logged with the date and the visitor’s account or email address (SlashPhoto → Visitors).', 'slashphoto' ),
				value: meta[ META.terms ] || '',
				onChange: ( value ) => update( META.terms, value ),
				__nextHasNoMarginBottom: true,
			} ),
			parent > 0 &&
				el( Notice, { status: 'info', isDismissible: false }, __( 'This is a sub-gallery: the password, client list, secret link, email request, end date and terms of the galleries above it also apply.', 'slashphoto' ) )
		);
	}

	function ProofingPanel() {
		const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
		const parent = useSelect( ( select ) => select( 'core/editor' ).getEditedPostAttribute( 'parent' ), [] );
		const [ meta, setMeta ] = useEntityProp( 'postType', POST_TYPE, 'meta' );

		if ( POST_TYPE !== postType || ! meta ) {
			return null;
		}
		const value = meta[ META.proofing ] || '';
		const update = ( key, next ) => setMeta( { ...meta, [ key ]: next } );

		return el(
			PluginDocumentSettingPanel,
			{ name: 'slashphoto-proofing', title: __( 'Client selections', 'slashphoto' ), className: 'slashphoto-proofing-panel' },
			el( SelectControl, {
				label: __( 'Let clients choose favourites', 'slashphoto' ),
				help: __( 'Logged-in clients mark images and send their selection to you. Visitors without an account keep favourites in their own browser only.', 'slashphoto' ),
				value,
				options: [
					{ value: '', label: parent > 0 ? __( 'Same as parent gallery', 'slashphoto' ) : __( 'Off', 'slashphoto' ) },
					{ value: 'on', label: parent > 0 ? __( 'On, as a separate selection', 'slashphoto' ) : __( 'On', 'slashphoto' ) },
					{ value: 'off', label: __( 'Off for this gallery', 'slashphoto' ) },
				].filter( ( option, index, all ) => parent > 0 || index < 2 ),
				onChange: ( next ) => update( META.proofing, next ),
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
			} ),
			'on' === value &&
				el( TextControl, {
					type: 'number',
					min: 0,
					label: __( 'Maximum number of images', 'slashphoto' ),
					help: __( '0 means no limit. Applies to the whole selection, including sub-galleries.', 'slashphoto' ),
					value: String( meta[ META.proofingMax ] || 0 ),
					onChange: ( next ) => update( META.proofingMax, Math.max( 0, parseInt( next, 10 ) || 0 ) ),
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				} ),
			'on' === value &&
				el( 'p', { className: 'components-base-control__help' }, __( 'Sent selections appear in the “Client selections” box at the bottom of the editor (Meta Boxes). You also get an email.', 'slashphoto' ) )
		);
	}

	function ShopPanel() {
		const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
		const parent = useSelect( ( select ) => select( 'core/editor' ).getEditedPostAttribute( 'parent' ), [] );
		const [ meta, setMeta ] = useEntityProp( 'postType', POST_TYPE, 'meta' );
		const shop = window.slashphotoShopEditor || { lists: [] };

		if ( POST_TYPE !== postType || ! meta ) {
			return null;
		}
		const value = meta[ META.priceList ] || '';
		let inherit;
		if ( parent > 0 ) {
			inherit = __( 'Same as parent gallery', 'slashphoto' );
		} else if ( shop.defaultTitle ) {
			/* translators: %s: price list name */
			inherit = sprintf( __( 'Default (%s)', 'slashphoto' ), shop.defaultTitle );
		} else {
			inherit = __( 'Default (not for sale)', 'slashphoto' );
		}

		return el(
			PluginDocumentSettingPanel,
			{ name: 'slashphoto-shop', title: __( 'Shop', 'slashphoto' ), className: 'slashphoto-shop-panel' },
			el( SelectControl, {
				label: __( 'Price list', 'slashphoto' ),
				help: __( 'Visitors who can see this gallery can buy downloads and prints of its photos at these prices. Sub-galleries use the same list unless they choose another.', 'slashphoto' ),
				value,
				options: [
					{ value: '', label: inherit },
					{ value: 'none', label: __( 'Not for sale', 'slashphoto' ) },
					...shop.lists.map( ( list ) => ( { value: list.id, label: list.title } ) ),
				],
				onChange: ( next ) => setMeta( { ...meta, [ META.priceList ]: next } ),
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
			} ),
			shop.canManage &&
				el(
					'p',
					{ className: 'components-base-control__help' },
					el( 'a', { href: shop.manageUrl }, __( 'Price lists', 'slashphoto' ) ),
					' · ',
					el( 'a', { href: shop.settingsUrl }, __( 'Shop settings', 'slashphoto' ) )
				)
		);
	}

	registerPlugin( 'slashphoto-shop', { render: ShopPanel } );
	registerPlugin( 'slashphoto-proofing', { render: ProofingPanel } );
	registerPlugin( 'slashphoto-access', { render: AccessPanel } );
} )( window.wp );
