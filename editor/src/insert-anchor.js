import { create, insert, toHTMLString } from '@wordpress/rich-text';

/**
 * Shared link-insertion helpers for the free and Pro editor sidebars.
 *
 * These live in one module on purpose. The Pro sidebar replaces the free
 * suggestion list wholesale rather than extending it, so the two carried
 * their own copies of this logic — and when the free copy was fixed, Pro
 * customers kept the broken one. Import from here in both.
 */

/**
 * Read a block's rich-text content as an HTML string.
 *
 * WordPress 6.6 changed `content` on core/paragraph and friends from a plain
 * string to a RichTextData object, so a `typeof === 'string'` test rejected
 * every paragraph on a current install.
 *
 * @param {Object} block Block from the block-editor store.
 * @return {string|null} HTML, or null when the block holds no rich text.
 */
export function contentHtml( block ) {
	const content = block?.attributes?.content;
	if ( content === undefined || content === null ) {
		return null;
	}
	if ( typeof content === 'string' ) {
		return content;
	}
	if ( typeof content.toHTMLString === 'function' ) {
		return content.toHTMLString();
	}
	if ( typeof content.toString === 'function' ) {
		return String( content );
	}
	return null;
}

/**
 * Anchor markup for a suggestion.
 *
 * @param {Object} suggestion Suggestion with url and title.
 * @return {string} The <a> markup.
 */
export function anchorHtml( suggestion ) {
	return `<a href="${
		suggestion.url
	}" class="jnk-linkweave-suggested">${ escapeHtml(
		readableTitle( suggestion.title )
	) }</a>`;
}

/**
 * The title as link text, without a short label in front of a colon.
 *
 * When no phrase from the title is in the text, the title itself becomes the
 * anchor, and "LW-QA: Speed Up WordPress with a CDN" read like a filing code in the
 * middle of a sentence. A prefix of one or two words is dropped when a real title
 * of two or more words follows it.
 *
 * @param {string} title Suggestion title (may carry HTML entities).
 * @return {string} Plain text.
 */
export function readableTitle( title ) {
	const text = decodeEntities( title ).trim();
	const m = text.match( /^([^:]{1,40}):\s+(.+)$/u );
	if ( m ) {
		const prefixWords = m[ 1 ].trim().split( /\s+/ ).length;
		const restWords = m[ 2 ].trim().split( /\s+/ ).length;
		if ( prefixWords <= 2 && restWords >= 2 ) {
			return m[ 2 ].trim();
		}
	}
	return text;
}

const STOPWORDS = new Set(
	(
		'a an and are as at be by for from how in into is it its of on or our ' +
		'that the their this to vs what when where which why with your you'
	).split( ' ' )
);

/**
 * Chinese, Japanese and Korean characters. These languages attach particles
 * and endings to a word or use no spaces at all, so a neighbouring character
 * from them is not a word edge, and each one counts twice towards the minimum
 * phrase length ("주식 투자" is a real five-character phrase).
 */
const CJK =
	'\\p{Script=Han}\\p{Script=Hiragana}\\p{Script=Katakana}\\p{Script=Hangul}\\u3005\\u3006\\u30FC';
const CJK_CHAR = new RegExp( `[${ CJK }]`, 'gu' );

/**
 * Apostrophes: typed ('), curly (’ ‘) or a modifier letter (ʼ). A title
 * "Walnut Chef’s Knife" split at the curly one and linked only "Walnut Chef"
 * in text typed with a straight one (QA 31 Oct 2026); any of them now matches
 * any other.
 */
const APOS = /['\u2018\u2019\u02BC]/g;
const APOS_CLASS = "['\u2018\u2019\u02BC]";

/**
 * Before a word, Korean is not transparent: endings and particles follow a
 * word, so a Hangul syllable in front means another word ("수환율" is not
 * "환율"). Chinese and Japanese write no spaces, so they stay transparent.
 */
const CJK_START =
	'\\p{Script=Han}\\p{Script=Hiragana}\\p{Script=Katakana}\\u3005\\u3006\\u30FC';

/**
 * Thai, Lao, Khmer and Myanmar write no spaces between words. Titles are cut
 * into words with the browser's word segmenter, and a match must start and end
 * where the segmenter says a word does. A Thai title used to be one "word", so
 * Insert link appended the whole title (QA 31 Oct 2026).
 */
const SEA =
	'\\p{Script=Thai}\\p{Script=Lao}\\p{Script=Khmer}\\p{Script=Myanmar}';
const SEA_CHAR = new RegExp( `[${ SEA }]`, 'u' );

/** Thai function words a phrase does not start or end on (and, of, in, do...). */
const TH_STOPWORDS = new Set(
	(
		'และ ที่ ของ ใน เป็น การ ได้ มี จะ ให้ ไม่ กับ แล้ว ก็ ว่า นี้ คือ ความ หรือ ' +
		'จาก โดย ซึ่ง อยู่ ไป มา ด้วย แต่ เพื่อ ถ้า ต้อง ควร เมื่อ ทุก กว่า ยัง อีก นั้น ' +
		'เรา คุณ เขา มาก ไว้ ทำ แบบ เลย นะ ครับ ค่ะ จึง เพราะ ถึง ทั้ง บาง แค่ กัน ตาม ' +
		'สำหรับ อย่าง เช่น นี่'
	).split( ' ' )
);

/**
 * Korean particles. A title word "전망과" (outlook + and) is "전망" in the
 * writer's "전망을"; a word inside a phrase may carry any particle.
 */
const KO_PARTICLES = [
	'에서',
	'으로',
	'에게',
	'까지',
	'부터',
	'처럼',
	'보다',
	'하고',
	'은',
	'는',
	'이',
	'가',
	'을',
	'를',
	'의',
	'에',
	'와',
	'과',
	'도',
	'로',
	'만',
];

let wordSegmenter;
function segmenter() {
	if ( wordSegmenter === undefined ) {
		wordSegmenter =
			typeof Intl !== 'undefined' && Intl.Segmenter
				? new Intl.Segmenter( 'th', { granularity: 'word' } )
				: null;
	}
	return wordSegmenter;
}

function isStopword( word ) {
	return STOPWORDS.has( word.toLowerCase() ) || TH_STOPWORDS.has( word );
}

/**
 * The words of a title segment, each with whether it follows the one before
 * with nothing in between (Thai words cut by the segmenter).
 *
 * @param {string} segment Part of a title.
 * @return {Array<{word:string,glued:boolean}>} Words in order.
 */
function titleWords( segment ) {
	const out = [];
	for ( const m of segment.matchAll(
		/[\p{L}\p{M}\p{N}'\u2018\u2019\u02BC-]+/gu
	) ) {
		const run = m[ 0 ];
		const seg = SEA_CHAR.test( run ) ? segmenter() : null;
		if ( ! seg ) {
			out.push( { word: run, glued: false } );
			continue;
		}
		let glued = false;
		for ( const piece of seg.segment( run ) ) {
			if ( piece.isWordLike ) {
				out.push( { word: piece.segment, glued } );
				glued = true;
			} else {
				glued = false;
			}
		}
	}
	return out;
}

/**
 * The pattern for a phrase (no edges): any apostrophe for any other, any
 * run of space for a space, and a Korean word inside the phrase with its
 * particle dropped and any particle allowed.
 *
 * @param {string} phrase A title phrase.
 * @return {string} RegExp source.
 */
function phraseSource( phrase ) {
	const words = phrase.split( /\s+/ );
	return words
		.map( ( word, i ) => {
			let stem = word;
			if ( /^\p{Script=Hangul}+$/u.test( word ) ) {
				const p = KO_PARTICLES.find(
					( k ) => word.length - k.length >= 2 && word.endsWith( k )
				);
				stem = p ? word.slice( 0, -p.length ) : word;
			}
			let src = stem
				.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' )
				.replace( APOS, APOS_CLASS );
			if ( i < words.length - 1 && /\p{Script=Hangul}$/u.test( stem ) ) {
				src += '\\p{Script=Hangul}{0,2}';
			}
			return src;
		} )
		.join( '\\s+' );
}

/**
 * Where a match meets Thai (Lao, Khmer, Myanmar) letters on both sides of an
 * edge, does the word segmenter agree a word ends there? "ตา" (eye) is not in
 * "ตาราง" (table). Without a segmenter, no match next to those letters.
 *
 * @param {string} text  Text searched.
 * @param {number} start Match start.
 * @param {number} end   Match end.
 * @return {boolean} True when both edges are word edges.
 */
function seaEdgesOk( text, start, end ) {
	const atStart =
		start > 0 &&
		SEA_CHAR.test( text[ start - 1 ] ) &&
		SEA_CHAR.test( text[ start ] );
	const atEnd =
		end < text.length &&
		SEA_CHAR.test( text[ end ] ) &&
		SEA_CHAR.test( text[ end - 1 ] );
	if ( ! atStart && ! atEnd ) {
		return true;
	}
	const seg = segmenter();
	if ( ! seg ) {
		return false;
	}
	const from = Math.max( 0, start - 80 );
	const to = Math.min( text.length, end + 80 );
	const cuts = new Set( [ to ] );
	for ( const piece of seg.segment( text.slice( from, to ) ) ) {
		cuts.add( from + piece.index );
	}
	return ( ! atStart || cuts.has( start ) ) && ( ! atEnd || cuts.has( end ) );
}

function phraseWeight( phrase ) {
	return phrase.length + ( phrase.match( CJK_CHAR ) || [] ).length;
}

function escapeHtml( s ) {
	return String( s )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

function decodeEntities( s ) {
	if ( typeof document === 'undefined' ) {
		return String( s );
	}
	const el = document.createElement( 'textarea' );
	el.innerHTML = String( s );
	return el.value;
}

/**
 * Phrases from a title that could already be in the writer's sentence, most
 * specific first: runs of 2–4 words from each part of the title (split at a colon
 * or dash), never starting or ending on a stop-word, then a part that is a
 * single word.
 *
 * @param {string} title Suggestion title.
 * @return {string[]} Candidate phrases.
 */
export function titlePhrases( title ) {
	const found = new Map();
	decodeEntities( title )
		.split( /\s*[:|—–]\s*|\s+-\s+/u )
		.forEach( ( segment ) => {
			const list = titleWords( segment );
			const words = list.map( ( w ) => w.word );
			const join = ( i, n ) =>
				list
					.slice( i, i + n )
					.map(
						( w, k ) => ( k > 0 && ! w.glued ? ' ' : '' ) + w.word
					)
					.join( '' );
			for ( let n = Math.min( 4, words.length ); n >= 2; n-- ) {
				for ( let i = 0; i + n <= words.length; i++ ) {
					if (
						isStopword( words[ i ] ) ||
						isStopword( words[ i + n - 1 ] )
					) {
						continue;
					}
					const phrase = join( i, n );
					if (
						phraseWeight( phrase ) >= 6 &&
						! found.has( phrase.toLowerCase() )
					) {
						found.set( phrase.toLowerCase(), { phrase, n } );
					}
				}
			}
			// A one-word title ("한국은행", "환율", "Espresso") is the name of the
			// page: link that word where the writer used it. Without this every
			// one-word title was appended to the end of the paragraph, which on a
			// Korean blog was nearly every suggestion.
			if (
				words.length === 1 &&
				phraseWeight( words[ 0 ] ) >= 4 &&
				! isStopword( words[ 0 ] ) &&
				! found.has( words[ 0 ].toLowerCase() )
			) {
				found.set( words[ 0 ].toLowerCase(), {
					phrase: words[ 0 ],
					n: 1,
				} );
			}
		} );
	return [ ...found.values() ]
		.sort( ( a, b ) => b.n - a.n || b.phrase.length - a.phrase.length )
		.map( ( f ) => f.phrase );
}

/**
 * Find an unlinked, unformatted whole-word occurrence of one of the title's
 * phrases in the rich-text value.
 *
 * @param {Object} value      Rich-text value of the block.
 * @param {Object} suggestion Suggestion whose title supplies the phrases.
 * @return {{start:number,end:number}|null} Range in value.text.
 */
function findPhraseRange( value, suggestion ) {
	const text = value.text || '';
	for ( const phrase of titlePhrases( suggestion.title ) ) {
		const re = new RegExp(
			`(?<!(?![${ CJK_START }${ SEA }])[\\p{L}\\p{M}\\p{N}])(${ phraseSource(
				phrase
			) })(?!(?![${ CJK }${ SEA }])[\\p{L}\\p{M}\\p{N}])`,
			'giu'
		);
		let m;
		while ( ( m = re.exec( text ) ) !== null ) {
			const start = m.index;
			const end = start + m[ 1 ].length;
			let clean = seaEdgesOk( text, start, end );
			for ( let i = start; i < end; i++ ) {
				if (
					( value.formats &&
						value.formats[ i ] &&
						value.formats[ i ].length ) ||
					( value.replacements && value.replacements[ i ] )
				) {
					clean = false;
					break;
				}
			}
			if ( clean ) {
				return { start, end };
			}
		}
	}
	return null;
}

/**
 * Insert one or more suggestion links into a block's content.
 *
 * Each link goes onto words the writer already used when a phrase from the
 * suggestion's title is in the text ("WordPress caching" in "…speeds up WordPress
 * caching…"). Only when no phrase is there is the title appended, and then with a
 * separator. Several appended links used to be glued together with nothing between
 * them, because insert() leaves a caret behind and the space was only added when
 * there was no caret.
 *
 * Always passes an explicit position. `insert()` otherwise defaults to
 * value.start/value.end, which are undefined for a block selected without a
 * caret in its text — and the resulting slice-and-concat duplicated the whole block.
 *
 * @param {string} html        Current block HTML.
 * @param {Array}  suggestions Suggestions to insert, in order.
 * @return {{html:string, anchors:Array<{suggestion:Object,text:string,placed:boolean}>}} The new HTML and where each link went.
 */
export function placeAnchors( html, suggestions ) {
	let value = create( { html } );
	const anchors = [];
	let appended = 0;

	suggestions.forEach( ( suggestion ) => {
		const range = findPhraseRange( value, suggestion );
		if ( range ) {
			const words = value.text.slice( range.start, range.end );
			value = insert(
				value,
				create( {
					html: `<a href="${
						suggestion.url
					}" class="jnk-linkweave-suggested">${ escapeHtml(
						words
					) }</a>`,
				} ),
				range.start,
				range.end
			);
			anchors.push( { suggestion, text: words, placed: true } );
			return;
		}

		const end = value.text.length;
		let separator = '';
		if ( appended > 0 ) {
			separator = ', ';
		} else if ( end > 0 && ! /\s$/.test( value.text ) ) {
			separator = ' ';
		}
		value = insert(
			value,
			create( { html: separator + anchorHtml( suggestion ) } ),
			end,
			end
		);
		appended++;
		anchors.push( {
			suggestion,
			text: readableTitle( suggestion.title ),
			placed: false,
		} );
	} );

	return { html: toHTMLString( { value } ), anchors };
}

/**
 * Insert one or more anchors into a block's content and return the new HTML.
 *
 * @param {string} html        Current block HTML.
 * @param {Array}  suggestions Suggestions to insert, in order.
 * @return {string} Updated HTML.
 */
export function withAnchors( html, suggestions ) {
	return placeAnchors( html, suggestions ).html;
}

/**
 * Insert anchors into a Classic (core/freeform) block.
 *
 * That block holds a whole document of raw HTML rather than a single rich-text
 * field, so round-tripping it through create()/toHTMLString() rewrites the
 * markup of the entire post — that is what used to duplicate people's content.
 *
 * Prefer the block's own TinyMCE, which puts the link exactly at the caret.
 * When it has not booted fall back to appending to the raw HTML: less precise,
 * but it always works and cannot mangle the markup.
 *
 * @param {string} clientId    The Classic block's client ID.
 * @param {Array}  suggestions Suggestions to insert, in order.
 * @param {string} html        Current block HTML.
 * @return {string|true} True when TinyMCE handled it, otherwise the new HTML
 *                       for the caller to commit.
 */
export function insertIntoFreeform( clientId, suggestions, html ) {
	const editor = window.tinymce && window.tinymce.get( 'editor-' + clientId );
	if ( editor ) {
		suggestions.forEach( ( suggestion ) => {
			editor.insertContent( ' ' + anchorHtml( suggestion ) );
		} );
		editor.fire( 'change' );
		return true;
	}

	const additions = suggestions
		.map( ( suggestion ) => `<p>${ anchorHtml( suggestion ) }</p>` )
		.join( '\n' );

	return `${ String( html || '' ).trimEnd() }\n${ additions }`;
}
