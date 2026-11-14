( function( wp ) {
	'use strict';

	const config = window.cwpRssBuilder;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! config || ! Panel ) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;

	function RssTitlePanel() {
		const state = wp.data.useSelect( function( select ) {
			const editor = select( 'core/editor' );
			const post = editor.getCurrentPost();
			return {
				available: post && post.id === config.postId && typeof post[ config.field ] === 'string',
				value: editor.getEditedPostAttribute( config.field ),
				saving: editor.isSavingPost(),
			};
		}, [] );
		const editPost = wp.data.useDispatch( 'core/editor' ).editPost;
		if ( ! state.available ) {
			return null;
		}
		return el(
			Panel,
			{ name: 'cwp-rss-title', title: __( 'RSS Post Title', 'cultivate-rss-builder' ) },
			el( wp.components.TextControl, {
				label: __( 'RSS Post Title', 'cultivate-rss-builder' ),
				value: typeof state.value === 'string' ? state.value : '',
				disabled: state.saving,
				onChange( value ) {
					editPost( { [ config.field ]: value } );
				},
			} ),
		);
	}

	wp.plugins.registerPlugin( 'cultivate-rss-builder', { render: RssTitlePanel, icon: 'rss' } );
}( window.wp ) );
