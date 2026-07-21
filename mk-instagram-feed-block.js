( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;

	blocks.registerBlockType( 'mk/instagram', {
		apiVersion: 3,
		title: __( 'Instagram Feed', 'mk' ),
		description: __( 'Latest Instagram posts in a grid with an Instagram-style lightbox.', 'mk' ),
		icon: 'instagram',
		category: 'widgets',
		keywords: [ 'instagram', 'feed', 'social' ],
		attributes: {
			count: { type: 'number', default: 9 },
			columns: { type: 'number', default: 3 }
		},
		example: {},
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Feed settings', 'mk' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Number of posts', 'mk' ),
							value: a.count,
							min: 1,
							max: 20,
							onChange: function ( v ) {
								props.setAttributes( { count: v } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Columns', 'mk' ),
							value: a.columns,
							min: 1,
							max: 6,
							onChange: function ( v ) {
								props.setAttributes( { columns: v } );
							}
						} )
					)
				),
				el( serverSideRender, {
					block: 'mk/instagram',
					attributes: a
				} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
