import { escapeHtml, findInText } from '../src/phrases';

/**
 * Put a suggestion's link on words already in a widget's HTML.
 *
 * Works on the HTML string, not a DOM, so the rest of the widget's markup is
 * left exactly as Elementor saved it. Words inside a link, a heading, code, a
 * button or a shortcode are never linked: the same places the auto-link rules
 * leave alone.
 */

/** Tags whose words are never linked. */
const PROTECTED = new Set( [
	'a',
	'h1',
	'h2',
	'h3',
	'h4',
	'h5',
	'h6',
	'code',
	'pre',
	'script',
	'style',
	'button',
	'textarea',
	'select',
	'svg',
] );

/** Entities a writer's words often carry, read as the characters they are. */
const ENTITIES = {
	'&nbsp;': ' ',
	'&#160;': ' ',
	'&rsquo;': '’',
	'&#8217;': '’',
	'&lsquo;': '‘',
	'&#8216;': '‘',
	'&#39;': "'",
	'&apos;': "'",
};
const ENTITY_RE = /&nbsp;|&#160;|&rsquo;|&#8217;|&lsquo;|&#8216;|&#39;|&apos;/g;

/**
 * Shortcode spans in a piece of text, so a match inside one is skipped.
 *
 * @param {string} text Text between tags.
 * @return {Array<Array<number>>} [start, end] pairs.
 */
function shortcodeSpans( text ) {
	const spans = [];
	for ( const m of text.matchAll( /\[[^\[\]]*\]/g ) ) {
		spans.push( [ m.index, m.index + m[ 0 ].length ] );
	}
	return spans;
}

/**
 * Link the first whole-word match of one of the title's phrases.
 *
 * @param {string}  html       The widget's HTML.
 * @param {Object}  suggestion Suggestion with url, title and matched_terms.
 * @param {boolean} byTerms    Look for single title words the idea was built
 *                             from (its matched_terms) instead of title phrases.
 * @return {{html:string,text:string}|null} The new HTML and the linked words, or null when no words match.
 */
export function linkFirstWords( html, suggestion, byTerms ) {
	const parts = String( html || '' ).split( /(<!--[\s\S]*?-->|<[^>]*>)/ );
	const open = {};
	let shut = 0;
	for ( let i = 0; i < parts.length; i++ ) {
		const part = parts[ i ];
		if ( i % 2 === 1 ) {
			const tag = /^<\s*(\/)?\s*([a-z][a-z0-9-]*)/i.exec( part );
			const name = tag ? tag[ 2 ].toLowerCase() : '';
			if ( PROTECTED.has( name ) && ! /\/\s*>$/.test( part ) ) {
				const before = open[ name ] || 0;
				open[ name ] = tag[ 1 ]
					? Math.max( 0, before - 1 )
					: before + 1;
				shut += open[ name ] - before;
			}
			continue;
		}
		if ( shut > 0 || ! part.trim() ) {
			continue;
		}
		const text = part.replace( ENTITY_RE, ( e ) => ENTITIES[ e ] );
		const spans = shortcodeSpans( text );
		const range = findInText(
			text,
			suggestion.title,
			( start, end ) =>
				! spans.some( ( [ a, b ] ) => start < b && end > a ),
			byTerms ? suggestion.matched_terms || [] : undefined
		);
		if ( ! range ) {
			continue;
		}
		const words = text.slice( range.start, range.end );
		parts[ i ] =
			text.slice( 0, range.start ) +
			`<a href="${ escapeHtml(
				suggestion.url
			) }" class="jnk-linkweave-suggested">${ words }</a>` +
			text.slice( range.end );
		return { html: parts.join( '' ), text: words };
	}
	return null;
}
