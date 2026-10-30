import { __ } from '@wordpress/i18n';

/**
 * Describe a relevance score in words.
 *
 * The bare number carried no scale — nothing told a writer whether 0.81 was good,
 * what the range was, or why one suggestion beat another. Scores are normalised to
 * roughly 0–1 and can exceed 1 once the orphan boost applies, so band them.
 * Shared by every panel (free and Pro) so they all speak the same language.
 *
 * The top label also needs two or more shared words. Scores are normalised to
 * the best candidate, so a post that shares a single word ("연금", pension) can
 * score 1.0 and was called a very close match (QA 30 Oct 2026). With one shared
 * word it is a close match at most.
 *
 * @param {number}   score Relevance score.
 * @param {string[]} terms Shared words, when known.
 * @return {string} Plain-language label.
 */
export default function strength( score, terms ) {
	const fewWords = Array.isArray( terms ) && terms.length < 2;
	if ( score >= 0.85 && ! fewWords ) {
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
