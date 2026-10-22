import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import SuggestionList from './SuggestionList';
import InboundOpportunities from './InboundOpportunities';

/**
 * The free sidebar renders the free panels and exposes filter seams. The Pro
 * editor bundle (loaded only in the paid build) registers filters that swap in
 * richer panels — bulk insert, inbound bulk-apply — and appends the cross-site
 * panel. Filters are resolved at render time (not module load) so the Pro bundle
 * has already registered by the time the user opens the sidebar.
 */
export default function Sidebar() {
	const SuggestionListPanel = applyFilters(
		'jnk_linkweave.editor.suggestionList',
		SuggestionList
	);
	const InboundPanel = applyFilters(
		'jnk_linkweave.editor.inboundOpportunities',
		InboundOpportunities
	);

	return (
		<div style={ { padding: 16 } }>
			<h2 style={ { margin: '0 0 4px', fontSize: 14 } }>
				{ __( 'Suggested links', 'jnk-linkweave' ) }
			</h2>
			<p style={ { margin: '0 0 14px', color: '#64748b', fontSize: 12 } }>
				{ __(
					'Posts that cover the same topic. Click into a paragraph, then insert a link.',
					'jnk-linkweave'
				) }
			</p>
			<SuggestionListPanel />
			<hr style={ { margin: '20px 0' } } />
			<InboundPanel />
			{ applyFilters( 'jnk_linkweave.editor.afterPanels', null ) }
		</div>
	);
}
