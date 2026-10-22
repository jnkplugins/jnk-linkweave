import { registerPlugin } from '@wordpress/plugins';
import * as editorPkg from '@wordpress/editor';
import * as editPostPkg from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import Sidebar from './Sidebar';
import './editor.css';

// PluginSidebar moved from @wordpress/edit-post to @wordpress/editor in WP 6.6.
// Pick the canonical location when available so newer WPs don't log a
// deprecation warning, and fall back for WP 6.0-6.5.
const { PluginSidebar, PluginSidebarMoreMenuItem } = editorPkg.PluginSidebar
	? editorPkg
	: editPostPkg;

registerPlugin( 'jnk-linkweave', {
	icon: 'admin-links',
	render: () => (
		<>
			<PluginSidebarMoreMenuItem target="jnk-linkweave-sidebar">
				{ __( 'JnK Linkweave', 'jnk-linkweave' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="jnk-linkweave-sidebar"
				title={ __( 'JnK Linkweave', 'jnk-linkweave' ) }
			>
				<Sidebar />
			</PluginSidebar>
		</>
	),
} );
