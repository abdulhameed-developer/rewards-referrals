(function (wp) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;
	wp.blocks.registerBlockType('rewards-referrals/component', {
		apiVersion: 3,
		title: __('Rewards & Referrals', 'rewards-referrals'),
		icon: 'star-filled',
		category: 'widgets',
		attributes: { component: { type: 'string', default: 'wallet' } },
		supports: { html: false },
		edit: function (props) {
			return el('div', wp.blockEditor.useBlockProps(),
				el(wp.blockEditor.InspectorControls, null,
					el(wp.components.PanelBody, { title: __('Rewards component', 'rewards-referrals') },
						el(wp.components.SelectControl, { label: __('Display', 'rewards-referrals'), value: props.attributes.component, options: window.RREditorConfig.components, onChange: function (value) { props.setAttributes({ component: value }); } })
					)
				),
				el(ServerSideRender, { block: 'rewards-referrals/component', attributes: props.attributes })
			);
		},
		save: function () { return null; }
	});
}(window.wp));
