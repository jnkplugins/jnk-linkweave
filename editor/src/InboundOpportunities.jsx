import { useEffect, useState, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Button, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { fetchInboundOpportunities } from './api';
import strength from './strength';
import useRefetchAfterSave from './use-refetch-after-save';

/**
 * Why the inbound panel is empty. It used to say "No inbound opportunities found
 * yet." for a post that simply had not been indexed, which sounds like a verdict.
 *
 * @param {Object|null} reason Reason object from the API.
 * @return {string} Message.
 */
export function inboundEmptyText( reason ) {
	switch ( reason?.code ) {
		case 'not_published':
			return __(
				'Drafts are not indexed yet. Once this post is published, related posts that should link here show up within a minute or so.',
				'jnk-linkweave'
			);
		case 'not_indexed':
			return __(
				'This post has not been indexed yet. It is normally picked up within a minute of saving; this list refreshes on its own.',
				'jnk-linkweave'
			);
		case 'too_short':
			return __(
				'This post is too short to index, so no related posts can be found for it.',
				'jnk-linkweave'
			);
		case 'type_excluded':
			return __(
				'This post type is not being indexed (see Post types in Settings).',
				'jnk-linkweave'
			);
		case 'index_empty':
			return __(
				'The index has not been built yet (JnK Linkweave → Dashboard → Rebuild index).',
				'jnk-linkweave'
			);
		default:
			return __(
				'No inbound opportunities found yet: every closely related post already links here, or none is related closely enough.',
				'jnk-linkweave'
			);
	}
}

/**
 * "Posts that should link here" — the reverse of the suggestion list. The free
 * build shows the read-only hint list; the Pro editor bundle swaps in a version
 * with checkboxes and one-click bulk-apply via the
 * `jnk_linkweave.editor.inboundOpportunities` filter.
 */
export default function InboundOpportunities() {
	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);
	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ loaded, setLoaded ] = useState( false );
	const [ reason, setReason ] = useState( null );

	const load = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		setLoading( true );
		fetchInboundOpportunities( postId )
			.then( ( res ) => {
				setItems( res.opportunities || [] );
				setReason( res.reason || null );
			} )
			.catch( () => setItems( [] ) )
			.finally( () => {
				setLoading( false );
				setLoaded( true );
			} );
	}, [ postId ] );

	useEffect( () => {
		load();
	}, [ load ] );

	useRefetchAfterSave( load, () => items.length === 0 );

	return (
		<div className="jnk-linkweave-inbound">
			<h2>{ __( 'Posts that should link here', 'jnk-linkweave' ) }</h2>
			<p className="jnk-linkweave-inbound__hint">
				{ __(
					'Topically related posts that don’t yet link to this one. Open one to add the link.',
					'jnk-linkweave'
				) }
			</p>

			{ loading && <Spinner /> }
			{ ! loading && loaded && ! items.length && (
				<p className="jnk-linkweave-inbound__empty">
					{ inboundEmptyText( reason ) }
				</p>
			) }

			{ ! loading && items.length > 0 && (
				<ul className="jnk-linkweave-suggestions">
					{ items.map( ( o ) => (
						<li
							key={ o.target_id }
							className="jnk-linkweave-suggestion"
						>
							<div className="jnk-linkweave-suggestion__title">
								<a
									href={ o.edit_url || o.url }
									target="_blank"
									rel="noreferrer noopener"
								>
									{ o.title }
								</a>
								<span
									className="jnk-linkweave-suggestion__score"
									title={ sprintf(
										/* translators: %s: raw relevance score. */
										__( 'Relevance %s', 'jnk-linkweave' ),
										o.score.toFixed( 2 )
									) }
								>
									{ strength( o.score ) }
								</span>
							</div>
							{ o.matched_terms && o.matched_terms.length > 0 && (
								<p className="jnk-linkweave-suggestion__terms">
									{ sprintf(
										/* translators: %s: comma-separated list of shared words. */
										__(
											'Both mention: %s',
											'jnk-linkweave'
										),
										o.matched_terms.join( ', ' )
									) }
								</p>
							) }
						</li>
					) ) }
				</ul>
			) }
			<Button variant="secondary" onClick={ load }>
				{ __( 'Refresh', 'jnk-linkweave' ) }
			</Button>
		</div>
	);
}
