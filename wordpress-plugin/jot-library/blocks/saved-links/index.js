(function (blocks, element, blockEditor, components, i18n, serverSideRender) {
	"use strict";

	const el = element.createElement;
	const { InspectorControls, useBlockProps } = blockEditor;
	const { PanelBody, TextControl } = components;
	const { __ } = i18n;
	const ServerSideRender = serverSideRender;

	blocks.registerBlockType("jot/saved-links", {
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
						{ title: __("Date range", "jot-library") },
						el(TextControl, {
							label: __("From", "jot-library"),
							type: "date",
							value: attributes.fromDate,
							onChange: function (value) {
								setAttributes({ fromDate: value });
							},
						}),
						el(TextControl, {
							label: __("To", "jot-library"),
							type: "date",
							value: attributes.toDate,
							onChange: function (value) {
								setAttributes({ toDate: value });
							},
						}),
						el("p", { className: "components-base-control__help" }, __("Both dates are inclusive and use the WordPress site timezone.", "jot-library"))
					)
				),
				el(
					"div",
					blockProps,
					el(ServerSideRender, { block: "jot/saved-links", attributes: attributes })
				)
			);
		},
		save: function () {
			return null;
		},
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender);
