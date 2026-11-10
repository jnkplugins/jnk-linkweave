/**
 * Words from a suggestion's title, found in the writer's own text.
 *
 * Pure string logic with no editor dependency, shared by the block editor
 * sidebar (insert-anchor.js) and the Elementor link-ideas panel
 * (editor/elementor), so a fix to the matching reaches both.
 */

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

/** Words ending in s that are not plurals ("news" is not "new"). */
const NOT_PLURAL = new Set(
	(
		'news series species lens canvas chaos atlas means economics physics ' +
		'politics mathematics ethics athletics always perhaps'
	).split( ' ' )
);

/**
 * An English (Latin-script) word's plural-free stem and the ending pattern that
 * brings its forms back: "Bed" and "beds", "Seeds" and "seed", "Berry" and
 * "berries", "Dish" and "dishes". Ideas are ranked on such stems, so the top
 * idea for a page about "cedar raised beds" was "Raised Bed Kit", which Insert
 * link could not place (QA 10 Nov 2026).
 *
 * @param {string} word A title word.
 * @return {{stem:string,src:string}} Lower-case stem and its RegExp source.
 */
export function wordForms( word ) {
	const esc = ( w ) =>
		w.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ).replace( APOS, APOS_CLASS );
	if ( ! /^\p{Script=Latin}{3,}$/u.test( word ) ) {
		return { stem: word.toLowerCase(), src: esc( word ) };
	}
	const l = word.toLowerCase();
	if ( NOT_PLURAL.has( l ) ) {
		return { stem: l, src: esc( word ) };
	}
	if ( l.length > 4 && l.endsWith( 'ies' ) ) {
		return {
			stem: l.slice( 0, -3 ) + 'y',
			src: esc( word.slice( 0, -3 ) ) + '(?:y|ies)',
		};
	}
	if ( l.length > 3 && /[^aeiouy]y$/.test( l ) ) {
		return { stem: l, src: esc( word.slice( 0, -1 ) ) + '(?:y|ies)' };
	}
	if ( l.length > 4 && /(?:s|x|z|ch|sh)es$/.test( l ) ) {
		return {
			stem: l.slice( 0, -2 ),
			src: esc( word.slice( 0, -2 ) ) + '(?:es)?',
		};
	}
	if ( /(?:s|x|z|ch|sh)$/.test( l ) && ! /[^s]s$/.test( l ) ) {
		return { stem: l, src: esc( word ) + '(?:es)?' };
	}
	if ( l.length > 3 && l.endsWith( 's' ) && ! /(?:ss|us|is)$/.test( l ) ) {
		return {
			stem: l.slice( 0, -1 ),
			src: esc( word.slice( 0, -1 ) ) + '(?:s|es)?',
		};
	}
	return { stem: l, src: esc( word ) + '(?:s)?' };
}

/**
 * The pattern for a phrase (no edges): any apostrophe for any other, any
 * run of space for a space, an English word in its singular or plural, and a
 * Korean word inside the phrase with its particle dropped and any particle
 * allowed.
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
			let src = wordForms( stem ).src;
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
export function seaEdgesOk( text, start, end ) {
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

export function escapeHtml( s ) {
	return String( s )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

export function decodeEntities( s ) {
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
 * The whole-word, case-insensitive pattern for one title phrase. Capture 1 is
 * the words as the writer spelled them.
 *
 * @param {string} phrase A title phrase.
 * @return {RegExp} Global pattern.
 */
export function phraseRegExp( phrase ) {
	return new RegExp(
		`(?<!(?![${ CJK_START }${ SEA }])[\\p{L}\\p{M}\\p{N}])(${ phraseSource(
			phrase
		) })(?!(?![${ CJK }${ SEA }])[\\p{L}\\p{M}\\p{N}])`,
		'giu'
	);
}

/**
 * The first place in a text where one of the title's phrases stands as whole
 * words, most specific phrase first.
 *
 * @param {string}   text    Text searched.
 * @param {string}   title   Suggestion title.
 * @param {Function} isClean Optional ( start, end ) => false to skip a match
 *                           (already linked or formatted there).
 * @param {string[]} [terms] Optional words the idea was built from; when given,
 *                           only title words among them are tried.
 * @return {{start:number,end:number}|null} Range in text.
 */
export function findInText( text, title, isClean, terms ) {
	const phrases = terms
		? titleTermWords( title, terms )
		: titlePhrases( title );
	for ( const phrase of phrases ) {
		const re = phraseRegExp( phrase );
		let m;
		while ( ( m = re.exec( text ) ) !== null ) {
			const start = m.index;
			const end = start + m[ 1 ].length;
			if (
				seaEdgesOk( text, start, end ) &&
				( ! isClean || isClean( start, end ) )
			) {
				return { start, end };
			}
		}
	}
	return null;
}

/**
 * Single title words the idea was built from: the shared words the
 * suggestion lists (matched_terms, as spelled in the page) that are also in
 * its title, in either number ("seeds" for "Herb Seed Collection"). The last
 * resort when no phrase of the title is in the text; a shared word that is not
 * in the title would make a link whose words promise another page.
 *
 * @param {string}   title Suggestion title.
 * @param {string[]} terms The suggestion's matched_terms.
 * @return {string[]} Title words, in the order of terms.
 */
export function titleTermWords( title, terms ) {
	const byStem = new Map();
	for ( const { word } of titleWords( decodeEntities( title ) ) ) {
		if ( ! isStopword( word ) && phraseWeight( word ) >= 4 ) {
			const stem = wordForms( word ).stem;
			if ( ! byStem.has( stem ) ) {
				byStem.set( stem, word );
			}
		}
	}
	const out = [];
	for ( const term of terms || [] ) {
		for ( const part of String( term ).split( /\s+/ ) ) {
			const word = byStem.get( wordForms( part ).stem );
			if ( word && ! out.includes( word ) ) {
				out.push( word );
			}
		}
	}
	return out;
}
