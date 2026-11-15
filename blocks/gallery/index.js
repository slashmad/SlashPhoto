/**
 * Editor UI of the slashphoto/gallery block.
 *
 * Plain JavaScript on the wp.* globals, so the block needs no build step.
 * The preview is rendered by the server with the same code and access rules
 * as the front end.
 *
 * @package SlashPhoto
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, ComboboxControl, Placeholder, Disabled } = wp.components;
	const { useSelect } = wp.data;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;

	const POST_TYPE = 'slashphoto_gallery';

	function GalleryPicker( { value, onChange, isGallery } ) {
		const galleries = useSelect(
			( select ) =>
				select( 'core' ).getEntityRecords( 'postType', POST_TYPE, {
					per_page: 100,
					orderby: 'title',
					order: 'asc',
					status: [ 'publish', 'private', 'draft', 'pending', 'future' ],
					_fields: 'id,title,parent',
				} ),
			[]
		);

		const options = [];
		if ( isGallery ) {
			options.push( { value: '0', label: __( 'This gallery', 'slashphoto' ) } );
		}
		( galleries || [] ).forEach( ( gallery ) => {
			options.push( {
				value: String( gallery.id ),
				label: ( gallery.title && gallery.title.rendered ? gallery.title.rendered : __( '(no title)', 'slashphoto' ) ) + ( gallery.parent ? ' ↳' : '' ),
			} );
		} );

		return el( ComboboxControl, {
			label: __( 'Gallery', 'slashphoto' ),
			help: __( 'Passwords, client lists, expiry dates and terms of the gallery always apply.', 'slashphoto' ),
			value: String( value ),
			options,
			onChange: ( next ) => onChange( parseInt( next || '0', 10 ) || 0 ),
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
		} );
	}

	registerBlockType( 'slashphoto/gallery', {
		edit( { attributes, setAttributes, context } ) {
			const blockProps = useBlockProps();
			const isGallery = context.postType === POST_TYPE;
			const hasTarget = attributes.galleryId > 0 || isGallery;

			const picker = el( GalleryPicker, {
				value: attributes.galleryId,
				isGallery,
				onChange: ( galleryId ) => setAttributes( { galleryId } ),
			} );

			return el(
				Fragment,
				null,
				el( InspectorControls, null, el( PanelBody, { title: __( 'Gallery', 'slashphoto' ) }, picker ) ),
				el(
					'div',
					blockProps,
					hasTarget
						? el(
								Disabled,
								null,
								el( ServerSideRender, {
									block: 'slashphoto/gallery',
									attributes,
									urlQueryArgs: context.postId ? { post_id: context.postId } : {},
								} )
						  )
						: el( Placeholder, { icon: 'format-gallery', label: __( 'SlashPhoto gallery', 'slashphoto' ), instructions: __( 'Choose the gallery to show.', 'slashphoto' ) }, picker )
				)
			);
		},
		save: () => null,
	} );
} )( window.wp );
