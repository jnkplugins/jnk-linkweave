import { __ } from '@wordpress/i18n';

/**
 * Describe a relevance score in words.
 *
 * The bare number carried no scale — nothing told a writer whether 0.81 was good,
 * what the range was, or why one suggestion beat another. Scores are normalised to
 * roughly 0–1 and can exceed 1 once the orphan boost applies, so band them.
 * Shared by every panel (free and Pro) so they all speak the same language.
 *
 * @param {number} score Relevance score.
 * @return {string} Plain-language label.
 */
export default function strength( score ) {
	if ( score >= 0.85 ) {
		return __( 'Very close match', 'jnk-linkweave' );
	}
	if ( score >= 0.6 ) {
		return __( 'Close match', 'jnk-linkweave' );
	}
	if ( score >= 0.4 ) {
		return __( 'Related', 'jnk-linkweave' );
	}
	return __( 'Loosely related', 'jnk-linkweave' );
}
