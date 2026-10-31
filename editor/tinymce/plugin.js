/* global tinymce, wp */
( function () {
    "use strict";

    if ( typeof tinymce === "undefined" || ! window.JnkLinkweaveTinyMCE ) {
        return;
    }

    const cfg = window.JnkLinkweaveTinyMCE;
    const i18n = cfg.i18n || {};

    // Join a route path (which may carry its own query string) onto cfg.restUrl,
    // appending params with the correct separator so query strings work under
    // both plain (rest_route=) and pretty permalinks.
    function buildUrl( path ) {
        path = String( path ).replace( /^\//, "" );
        const hash = path.indexOf( "#" );
        if ( hash !== -1 ) {
            path = path.slice( 0, hash );
        }
        const qIndex = path.indexOf( "?" );
        let route = path;
        let query = "";
        if ( qIndex !== -1 ) {
            route = path.slice( 0, qIndex );
            query = path.slice( qIndex + 1 );
        }
        let url = cfg.restUrl + route;
        if ( query ) {
            url += ( url.indexOf( "?" ) !== -1 ? "&" : "?" ) + query;
        }
        return url;
    }

    function fetchJSON( path, opts ) {
        opts = opts || {};
        return fetch( buildUrl( path ), {
            method: opts.method || "GET",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-WP-Nonce": cfg.nonce },
            body: opts.body ? JSON.stringify( opts.body ) : undefined,
        } ).then( ( r ) => r.json() );
    }

    function escape( s ) {
        return String( s == null ? "" : s )
            .replace( /&/g, "&amp;" )
            .replace( /</g, "&lt;" )
            .replace( />/g, "&gt;" )
            .replace( /"/g, "&quot;" );
    }

    function decodeEntities( s ) {
        const el = document.createElement( "textarea" );
        el.innerHTML = String( s == null ? "" : s );
        return el.value;
    }

    /**
     * Resolve the post being edited, in either editor.
     *
     * The Classic editor screen loads wp.data but never registers the core/editor
     * store, so try each source in turn instead of branching on wp.data.
     */
    function currentPostId() {
        var fromStore =
            typeof wp !== "undefined" &&
            wp.data &&
            wp.data.select( "core/editor" ) &&
            wp.data.select( "core/editor" ).getCurrentPostId
                ? wp.data.select( "core/editor" ).getCurrentPostId()
                : 0;
        if ( fromStore ) {
            return fromStore;
        }
        var field = document.getElementById( "post_ID" );
        return field ? parseInt( field.value, 10 ) || 0 : 0;
    }

    /** Relevance in words, as in the block editor sidebar (raw figure on hover). */
    function strength( score, terms ) {
        const labels = i18n.strength || [ "Very close match", "Close match", "Related", "Loosely related" ];
        // One shared word is never a very close match (editor/src/strength.js).
        if ( score >= 0.85 && ! ( Array.isArray( terms ) && terms.length < 2 ) ) {
            return labels[ 0 ];
        }
        if ( score >= 0.6 ) {
            return labels[ 1 ];
        }
        if ( score >= 0.4 ) {
            return labels[ 2 ];
        }
        return labels[ 3 ];
    }

    /**
     * The real reason a post has no suggestions. The dialog used to say only "No
     * suggestions for this post yet." even though the API explained why (a draft,
     * a post not indexed yet, a post too short to index…).
     */
    function emptyMessage( reason ) {
        const reasons = i18n.reasons || {};
        const code = reason && reason.code;
        if ( code === "too_short" && reasons.too_short ) {
            return reasons.too_short
                .replace( "%1$d", String( reason.words || 0 ) )
                .replace( "%2$d", String( reason.min_words || 0 ) );
        }
        return ( code && reasons[ code ] ) || i18n.empty;
    }

    const STOPWORDS = new Set(
        (
            "a an and are as at be by for from how in into is it its of on or our " +
            "that the their this to vs what when where which why with your you"
        ).split( " " )
    );

    /**
     * Chinese, Japanese and Korean letters. These languages glue particles to a
     * word ("한국은행의") or use no spaces, so a neighbouring one is not a word
     * edge, and each counts twice towards a phrase's length (same as the block editor).
     */
    const CJK = "\\p{Script=Han}\\p{Script=Hiragana}\\p{Script=Katakana}\\p{Script=Hangul}\\u3005\\u3006\\u30FC";
    const CJK_CHAR = new RegExp( "[" + CJK + "]", "gu" );

    /** Apostrophes, straight or curly, match each other (same as the block editor). */
    const APOS = /['\u2018\u2019\u02BC]/g;
    const APOS_CLASS = "['\u2018\u2019\u02BC]";

    /** Before a word Korean is not transparent ("수환율" is not "환율"); Chinese and Japanese are. */
    const CJK_START = "\\p{Script=Han}\\p{Script=Hiragana}\\p{Script=Katakana}\\u3005\\u3006\\u30FC";

    /** Thai, Lao, Khmer, Myanmar: no spaces; words come from the browser's segmenter (same as the block editor). */
    const SEA = "\\p{Script=Thai}\\p{Script=Lao}\\p{Script=Khmer}\\p{Script=Myanmar}";
    const SEA_CHAR = new RegExp( "[" + SEA + "]", "u" );
    const TH_STOPWORDS = new Set( (
        "และ ที่ ของ ใน เป็น การ ได้ มี จะ ให้ ไม่ กับ แล้ว ก็ ว่า นี้ คือ ความ หรือ " +
        "จาก โดย ซึ่ง อยู่ ไป มา ด้วย แต่ เพื่อ ถ้า ต้อง ควร เมื่อ ทุก กว่า ยัง อีก นั้น " +
        "เรา คุณ เขา มาก ไว้ ทำ แบบ เลย นะ ครับ ค่ะ จึง เพราะ ถึง ทั้ง บาง แค่ กัน ตาม " +
        "สำหรับ อย่าง เช่น นี่"
    ).split( " " ) );
    const KO_PARTICLES = [ "에서", "으로", "에게", "까지", "부터", "처럼", "보다", "하고", "은", "는", "이", "가", "을", "를", "의", "에", "와", "과", "도", "로", "만" ];

    let wordSegmenter;
    function segmenter() {
        if ( wordSegmenter === undefined ) {
            wordSegmenter = typeof Intl !== "undefined" && Intl.Segmenter ? new Intl.Segmenter( "th", { granularity: "word" } ) : null;
        }
        return wordSegmenter;
    }

    function isStopword( word ) {
        return STOPWORDS.has( word.toLowerCase() ) || TH_STOPWORDS.has( word );
    }

    function phraseWeight( phrase ) {
        return phrase.length + ( phrase.match( CJK_CHAR ) || [] ).length;
    }

    /** A title segment's words, each with whether it is glued to the one before (Thai). */
    function titleWords( segment ) {
        const out = [];
        for ( const m of segment.matchAll( /[\p{L}\p{M}\p{N}'\u2018\u2019\u02BC-]+/gu ) ) {
            const run = m[ 0 ];
            const seg = SEA_CHAR.test( run ) ? segmenter() : null;
            if ( ! seg ) {
                out.push( { word: run, glued: false } );
                continue;
            }
            let glued = false;
            for ( const piece of seg.segment( run ) ) {
                if ( piece.isWordLike ) {
                    out.push( { word: piece.segment, glued: glued } );
                    glued = true;
                } else {
                    glued = false;
                }
            }
        }
        return out;
    }

    /** RegExp source for a phrase: any apostrophe, any space run, Korean particles inside the phrase. */
    function phraseSource( phrase ) {
        const words = phrase.split( /\s+/ );
        return words.map( ( word, i ) => {
            let stem = word;
            if ( /^\p{Script=Hangul}+$/u.test( word ) ) {
                const p = KO_PARTICLES.find( ( k ) => word.length - k.length >= 2 && word.endsWith( k ) );
                stem = p ? word.slice( 0, -p.length ) : word;
            }
            let src = stem.replace( /[.*+?^${}()|[\]\\]/g, "\\$&" ).replace( APOS, APOS_CLASS );
            if ( i < words.length - 1 && /\p{Script=Hangul}$/u.test( stem ) ) {
                src += "\\p{Script=Hangul}{0,2}";
            }
            return src;
        } ).join( "\\s+" );
    }

    /** Thai edges must be word edges by the segmenter ("ตา" is not in "ตาราง"). */
    function seaEdgesOk( text, start, end ) {
        const atStart = start > 0 && SEA_CHAR.test( text[ start - 1 ] ) && SEA_CHAR.test( text[ start ] );
        const atEnd = end < text.length && SEA_CHAR.test( text[ end ] ) && SEA_CHAR.test( text[ end - 1 ] );
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

    /**
     * Whole-word pattern for a phrase. exec() returns a match like a RegExp's
     * (m.index, m[1] the character before, m[2] the words), or null.
     */
    function phrasePattern( phrase ) {
        const re = new RegExp( "(^|[^\\p{L}\\p{M}\\p{N}]|[" + CJK_START + SEA + "])(" + phraseSource( phrase ) + ")(?=$|[^\\p{L}\\p{M}\\p{N}]|[" + CJK + SEA + "])", "giu" );
        return {
            exec( text ) {
                re.lastIndex = 0;
                let m;
                while ( ( m = re.exec( text ) ) !== null ) {
                    const start = m.index + m[ 1 ].length;
                    if ( seaEdgesOk( text, start, start + m[ 2 ].length ) ) {
                        return m;
                    }
                    re.lastIndex = m.index + 1;
                }
                return null;
            },
        };
    }

    /** Title phrases of 2–4 words, then a one-word title, most specific first (same rules as the block editor). */
    function titlePhrases( title ) {
        const found = new Map();
        decodeEntities( title )
            .split( /\s*[:|—–]\s*|\s+-\s+/u )
            .forEach( ( segment ) => {
                const list = titleWords( segment );
                const words = list.map( ( w ) => w.word );
                const join = ( i, n ) => list.slice( i, i + n ).map( ( w, k ) => ( k > 0 && ! w.glued ? " " : "" ) + w.word ).join( "" );
                for ( let n = Math.min( 4, words.length ); n >= 2; n-- ) {
                    for ( let i = 0; i + n <= words.length; i++ ) {
                        if ( isStopword( words[ i ] ) || isStopword( words[ i + n - 1 ] ) ) {
                            continue;
                        }
                        const phrase = join( i, n );
                        if ( phraseWeight( phrase ) >= 6 && ! found.has( phrase.toLowerCase() ) ) {
                            found.set( phrase.toLowerCase(), { phrase, n } );
                        }
                    }
                }
                // A one-word title ("한국은행", "Espresso") is the page's name: link it where it is used.
                if ( words.length === 1 && phraseWeight( words[ 0 ] ) >= 4 && ! isStopword( words[ 0 ] ) && ! found.has( words[ 0 ].toLowerCase() ) ) {
                    found.set( words[ 0 ].toLowerCase(), { phrase: words[ 0 ], n: 1 } );
                }
            } );
        return [ ...found.values() ]
            .sort( ( a, b ) => b.n - a.n || b.phrase.length - a.phrase.length )
            .map( ( f ) => f.phrase );
    }

    /** The title as link text, without a one- or two-word label before a colon. */
    function readableTitle( title ) {
        const text = decodeEntities( title ).trim();
        const m = text.match( /^([^:]{1,40}):\s+(.+)$/u );
        if ( m && m[ 1 ].trim().split( /\s+/ ).length <= 2 && m[ 2 ].trim().split( /\s+/ ).length >= 2 ) {
            return m[ 2 ].trim();
        }
        return text;
    }

    /**
     * Wrap the first unlinked whole-word occurrence of a title phrase in the post
     * with the link. Existing links, headings, code and pre are skipped.
     *
     * @return {string|null} The words that were linked, or null when none matched.
     */
    function linkPhraseInBody( editor, url, title ) {
        const body = editor.getBody();
        const doc = editor.getDoc();
        const skip = /^(A|CODE|PRE|H[1-6]|SCRIPT|STYLE)$/;
        const texts = [];
        const walker = doc.createTreeWalker( body, NodeFilter.SHOW_TEXT, {
            acceptNode( node ) {
                for ( let p = node.parentNode; p && p !== body; p = p.parentNode ) {
                    if ( skip.test( p.nodeName ) ) {
                        return NodeFilter.FILTER_REJECT;
                    }
                }
                return NodeFilter.FILTER_ACCEPT;
            },
        } );
        while ( walker.nextNode() ) {
            texts.push( walker.currentNode );
        }

        for ( const phrase of titlePhrases( title ) ) {
            const re = phrasePattern( phrase );
            for ( const node of texts ) {
                const m = re.exec( node.nodeValue );
                if ( ! m ) {
                    continue;
                }
                const start = m.index + m[ 1 ].length;
                const words = m[ 2 ];
                const match = node.splitText( start );
                match.splitText( words.length );
                const a = doc.createElement( "a" );
                a.setAttribute( "href", url );
                a.className = "jnk-linkweave-suggested";
                match.parentNode.insertBefore( a, match );
                a.appendChild( match );
                return words;
            }
        }
        return null;
    }

    /** Is the character just before the caret a letter, digit or punctuation? */
    function needsSpaceBeforeCaret( editor ) {
        const rng = editor.selection.getRng();
        const node = rng && rng.startContainer;
        if ( node && node.nodeType === 3 && rng.startOffset > 0 ) {
            return ! /\s/.test( node.nodeValue.charAt( rng.startOffset - 1 ) );
        }
        return false;
    }

    // The Code (Text) tab's button (editor/quicktags.js) places links with the same rules.
    window.JnkLinkweavePhrases = { titlePhrases: titlePhrases, phrasePattern: phrasePattern, readableTitle: readableTitle };

    tinymce.PluginManager.add( "jnk-linkweave", function ( editor ) {
        editor.addButton( "jnk-linkweave", {
            text: "JnK Linkweave",
            icon: false,
            tooltip: i18n.tooltip,
            onclick: async function () {
                const postId = currentPostId();

                if ( ! postId ) {
                    editor.windowManager.alert( i18n.noPost );
                    return;
                }

                const data = await fetchJSON( "suggestions/" + postId );
                const suggestions = ( data && data.suggestions ) || [];
                if ( ! suggestions.length ) {
                    editor.windowManager.alert( emptyMessage( data && data.reason ) );
                    return;
                }

                editor.windowManager.open( {
                    title: i18n.title,
                    width: 540,
                    height: 400,
                    html: '<div style="padding:10px;max-height:360px;overflow:auto">' +
                        ( i18n.hint ? '<p style="margin:0 0 8px;white-space:normal;color:#50575e">' + escape( i18n.hint ) + "</p>" : "" ) +
                        suggestions.map( ( s ) =>
                            '<div style="border-bottom:1px solid #eee;padding:6px 0;display:flex;justify-content:space-between;align-items:center;gap:8px">' +
                            '<span style="white-space:normal"><strong>' + escape( decodeEntities( s.title ) ) + "</strong> " +
                            '<small title="' + escape( "Relevance " + Number( s.score ).toFixed( 2 ) ) + '">(' + escape( strength( Number( s.score ), s.matched_terms ) ) + ")</small></span>" +
                            '<button type="button" class="button button-primary jnk-linkweave-pick" data-url="' + escape( s.url ) + '" data-title="' + escape( s.title ) + '" data-id="' + escape( s.target_id ) + '">' + escape( i18n.insert || "Insert" ) + "</button>" +
                            "</div>"
                        ).join( "" ) +
                        "</div>",
                    buttons: [ { text: i18n.close, onclick: "close" } ],
                    onPostRender: function () {
                        const dlg = this.getEl();
                        dlg.querySelectorAll( ".jnk-linkweave-pick" ).forEach( ( btn ) => {
                            btn.addEventListener( "click", () => {
                                const rawUrl = btn.getAttribute( "data-url" );
                                const title  = btn.getAttribute( "data-title" );
                                const id     = parseInt( btn.getAttribute( "data-id" ), 10 );
                                // Validate URL is http/https to block javascript: injection.
                                if ( ! /^https?:\/\//i.test( rawUrl ) ) {
                                    return;
                                }
                                let anchor = editor.selection.getContent( { format: "text" } );
                                editor.undoManager.transact( () => {
                                    if ( anchor ) {
                                        // The writer chose the words: link exactly those.
                                        editor.insertContent(
                                            '<a href="' + escape( rawUrl ) + '" class="jnk-linkweave-suggested">' + escape( anchor ) + "</a>"
                                        );
                                        return;
                                    }
                                    // Otherwise put the link on a phrase from the title that
                                    // is already in the text, as the block editor does. Only
                                    // when there is none does the title go in at the cursor,
                                    // and then separated from the word before it.
                                    const placed = linkPhraseInBody( editor, rawUrl, title );
                                    if ( placed ) {
                                        anchor = placed;
                                        return;
                                    }
                                    anchor = readableTitle( title );
                                    editor.insertContent(
                                        ( needsSpaceBeforeCaret( editor ) ? " " : "" ) +
                                        '<a href="' + escape( rawUrl ) + '" class="jnk-linkweave-suggested">' + escape( anchor ) + "</a>"
                                    );
                                } );
                                editor.fire( "change" );
                                editor.nodeChanged();
                                fetchJSON( "links/insert", {
                                    method: "POST",
                                    body: { source_post_id: postId, target_post_id: id, anchor_text: anchor },
                                } ).catch( () => {} );
                                editor.windowManager.close();
                            } );
                        } );
                    },
                } );
            },
        } );
    } );
} )();
