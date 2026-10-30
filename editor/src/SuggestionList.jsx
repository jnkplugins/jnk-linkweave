import { useEffect, useState, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Button, Spinner, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { fetchSuggestions, recordInsertion } from './api';
import SuggestionItem from './SuggestionItem';
import { contentHtml, placeAnchors, insertIntoFreeform } from './insert-anchor';
import useRefetchAfterSave from './use-refetch-after-save';

export default function SuggestionList() {
	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);
	const selectedBlock = useSelect(
		( select ) => select( 'core/block-editor' ).getSelectedBlock(),
		[]
	);
	const { updateBlockAttributes } = useDispatch( 'core/block-editor' );

	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ reason, setReason ] = useState( null );

	const load = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		setLoading( true );
		setError( null );
		fetchSuggestions( postId )
			.then( ( res ) => {
				setItems( res.suggestions || [] );
				setReason( res.reason || null );
			} )
			.catch( ( e ) => setError( e.message || String( e ) ) )
			.finally( () => setLoading( false ) );
	}, [ postId ] );

	useEffect( () => {
		load();
	}, [ load ] );

	useRefetchAfterSave( load, () => items.length === 0 );

	const insertLink = useCallback(
		async ( suggestion ) => {
			if ( ! selectedBlock ) {
				setError(
					__(
						'Click into the paragraph you want the link in, then insert.',
						'jnk-linkweave'
					)
				);
				return;
			}

			let anchorText = suggestion.title;
			if ( selectedBlock.name === 'core/freeform' ) {
				const result = insertIntoFreeform(
					selectedBlock.clientId,
					[ suggestion ],
					contentHtml( selectedBlock )
				);
				if ( result !== true ) {
					updateBlockAttributes( selectedBlock.clientId, {
						content: result,
					} );
				}
			} else {
				const html = contentHtml( selectedBlock );
				if ( html === null ) {
					setError(
						__(
							'That block cannot hold a link. Select a paragraph or list item and try again.',
							'jnk-linkweave'
						)
					);
					return;
				}
				const placed = placeAnchors( html, [ suggestion ] );
				anchorText = placed.anchors[ 0 ]?.text || suggestion.title;
				updateBlockAttributes( selectedBlock.clientId, {
					content: placed.html,
				} );
			}

			setError( null );
			try {
				await recordInsertion(
					postId,
					suggestion.target_id,
					anchorText
				);
			} catch ( e ) {
				// Logging the insertion is best-effort; the editor change is what matters.
			}
		},
		[ selectedBlock, updateBlockAttributes, postId ]
	);

	if ( loading ) {
		return <Spinner />;
	}

	// A transient warning ("click into a paragraph first") used to REPLACE the whole
	// list, and the only Refresh button lived in the empty state — so one mistaken
	// click emptied the panel with no obvious way back. Show it above the list and
	// leave the suggestions in place.
	const notice = error ? (
		<Notice
			status="error"
			onRemove={ () => setError( null ) }
			isDismissible
		>
			{ error }
		</Notice>
	) : null;

	if ( ! items.length ) {
		return (
			<div>
				{ notice }
				<EmptyState reason={ reason } onRefresh={ load } />
			</div>
		);
	}

	return (
		<div>
			{ notice }
			<ul className="jnk-linkweave-suggestions">
				{ items.map( ( s ) => (
					<SuggestionItem
						key={ s.target_id }
						suggestion={ s }
						onInsert={ insertLink }
					/>
				) ) }
			</ul>
			<Button variant="secondary" onClick={ load }>
				{ __( 'Refresh', 'jnk-linkweave' ) }
			</Button>
		</div>
	);
}

/**
 * Explain an empty panel honestly.
 *
 * This used to assert one cause — "the index has to be built once" — and offer a
 * Build-the-index button for every empty result, including on sites whose index was
 * already complete. Someone whose post was simply too short followed that advice,
 * came back, and saw the identical message. The API now reports the real reason.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.reason    Why the list is empty, from the API.
 * @param {Function} props.onRefresh Re-runs the suggestion request.
 * @return {Element} The empty-state panel.
 */
function EmptyState( { reason, onRefresh } ) {
	const code = reason?.code || 'index_empty';

	if ( code === 'too_short' ) {
		return (
			<div>
				<p>
					{ sprintf(
						/* translators: 1: indexable word count of this post, 2: configured minimum. */
						__(
							'This post is too short to index, so it has no suggestions yet. It has %1$d indexable words and the minimum is %2$d. Small, common words are not counted, so that is roughly half the visible word count.',
							'jnk-linkweave'
						),
						reason.words,
						reason.min_words
					) }
				</p>
				<p>
					{ __(
						'Add a few more paragraphs, or lower the minimum in Settings.',
						'jnk-linkweave'
					) }
				</p>
				<Button
					variant="secondary"
					href="admin.php?page=jnk-linkweave-settings"
					target="_blank"
				>
					{ __( 'Open settings', 'jnk-linkweave' ) }
				</Button>
			</div>
		);
	}

	if ( code === 'type_excluded' ) {
		return (
			<div>
				<p>
					{ __(
						'This post type is not being indexed, so there is nothing to suggest here. You can add it under Post types in Settings.',
						'jnk-linkweave'
					) }
				</p>
				<Button
					variant="secondary"
					href="admin.php?page=jnk-linkweave-settings"
					target="_blank"
				>
					{ __( 'Open settings', 'jnk-linkweave' ) }
				</Button>
			</div>
		);
	}

	if ( code === 'no_matches' ) {
		return (
			<div>
				<p>
					{ __(
						'No related posts cleared the relevance threshold for this one. That usually means it covers a topic nothing else on the site touches yet.',
						'jnk-linkweave'
					) }
				</p>
				<p>
					{ __(
						'Lowering the minimum relevance score in Settings will surface weaker matches.',
						'jnk-linkweave'
					) }
				</p>
				<Button variant="secondary" onClick={ onRefresh }>
					{ __( 'Refresh', 'jnk-linkweave' ) }
				</Button>
			</div>
		);
	}

	if ( code === 'not_published' ) {
		return (
			<div>
				<p>
					{ __(
						'Suggestions appear once this post is published: drafts are not indexed yet. After publishing, this panel fills in within a minute or so.',
						'jnk-linkweave'
					) }
				</p>
				<Button variant="secondary" onClick={ onRefresh }>
					{ __( 'Refresh', 'jnk-linkweave' ) }
				</Button>
			</div>
		);
	}

	if ( code === 'not_indexed' ) {
		return (
			<div>
				<p>
					{ __(
						'This post has not been indexed yet. It is normally picked up within a minute of saving; a rebuild will catch it immediately.',
						'jnk-linkweave'
					) }
				</p>
				<Button
					variant="primary"
					href="admin.php?page=jnk-linkweave"
					target="_blank"
				>
					{ __( 'Rebuild the index', 'jnk-linkweave' ) }
				</Button>{ ' ' }
				<Button variant="secondary" onClick={ onRefresh }>
					{ __( 'Refresh', 'jnk-linkweave' ) }
				</Button>
			</div>
		);
	}

	// index_empty — the genuine first-run case.
	return (
		<div>
			<p>
				{ __(
					'No suggestions yet. Linkweave ranks suggestions against an index of your existing posts, so that index has to be built once before it can suggest anything.',
					'jnk-linkweave'
				) }
			</p>
			<p>
				<Button
					variant="primary"
					href="admin.php?page=jnk-linkweave"
					target="_blank"
				>
					{ __( 'Build the index', 'jnk-linkweave' ) }
				</Button>
			</p>
			<p>
				{ __(
					'Already built it? Save this post, then refresh.',
					'jnk-linkweave'
				) }
			</p>
			<Button variant="secondary" onClick={ onRefresh }>
				{ __( 'Refresh', 'jnk-linkweave' ) }
			</Button>
		</div>
	);
}
