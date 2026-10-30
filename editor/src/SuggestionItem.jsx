import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import strength from './strength';

export default function SuggestionItem( { suggestion, onInsert } ) {
	const label = sprintf(
		/* translators: 1: plain-language strength, 2: raw relevance score. */
		__(
			'%1$s — relevance %2$s. Higher means more topic words in common.',
			'jnk-linkweave'
		),
		strength( suggestion.score, suggestion.matched_terms ),
		suggestion.score.toFixed( 2 )
	);

	return (
		<li className="jnk-linkweave-suggestion">
			<div className="jnk-linkweave-suggestion__title">
				<a
					href={ suggestion.url }
					target="_blank"
					rel="noreferrer noopener"
				>
					{ suggestion.title }
				</a>
				<span
					className="jnk-linkweave-suggestion__score"
					title={ label }
					aria-label={ label }
				>
					{ strength( suggestion.score, suggestion.matched_terms ) }
				</span>
			</div>
			{ suggestion.excerpt && (
				<p className="jnk-linkweave-suggestion__excerpt">
					{ suggestion.excerpt }
				</p>
			) }
			{ suggestion.matched_terms &&
				suggestion.matched_terms.length > 0 && (
					<p className="jnk-linkweave-suggestion__terms">
						{ sprintf(
							/* translators: %s: comma-separated list of shared words. */
							__( 'Both mention: %s', 'jnk-linkweave' ),
							suggestion.matched_terms.join( ', ' )
						) }
					</p>
				) }
			<Button
				variant="secondary"
				size="small"
				onClick={ () => onInsert( suggestion ) }
			>
				{ __( 'Insert link', 'jnk-linkweave' ) }
			</Button>
		</li>
	);
}
