(function (blocks, element, blockEditor, components, i18n, serverSideRender) {
	const el = element.createElement;
	const { InspectorControls, useBlockProps } = blockEditor;
	const { PanelBody, RangeControl, ToggleControl } = components;
	const { __ } = i18n;
	const ServerSideRender = serverSideRender;

	blocks.registerBlockType("jot/library", {
		edit: function ({ attributes, setAttributes }) {
			const blockProps = useBlockProps();
			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __("Library settings", "jot-library") },
						el(RangeControl, {
							label: __("Entries per page", "jot-library"),
							min: 1,
							max: 48,
							value: attributes.postsPerPage,
							onChange: (value) => setAttributes({ postsPerPage: value }),
						}),
						el(ToggleControl, {
							label: __("Show search and topic filters", "jot-library"),
							checked: attributes.showSearch,
							onChange: (value) => setAttributes({ showSearch: value }),
						})
					)
				),
				el(
					"div",
					blockProps,
					el(ServerSideRender, { block: "jot/library", attributes: attributes })
				)
			);
		},
		save: function () {
			return null;
		},
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender);
