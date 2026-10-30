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

    function phraseWeight( phrase ) {
        return phrase.length + ( phrase.match( CJK_CHAR ) || [] ).length;
    }

    /** Whole-word pattern for a phrase; CJK letters next to it do not count as the same word. */
    function phrasePattern( phrase ) {
        const escaped = phrase.replace( /[.*+?^${}()|[\]\\]/g, "\\$&" ).replace( /\s+/g, "\\s+" );
        return new RegExp( "(^|[^\\p{L}\\p{N}]|[" + CJK + "])(" + escaped + ")(?=$|[^\\p{L}\\p{N}]|[" + CJK + "])", "iu" );
    }

    /** Title phrases of 2–4 words, then a one-word title, most specific first (same rules as the block editor). */
    function titlePhrases( title ) {
        const found = new Map();
        decodeEntities( title )
            .split( /\s*[:|—–]\s*|\s+-\s+/u )
            .forEach( ( segment ) => {
                const words = segment.split( /[^\p{L}\p{N}'-]+/u ).filter( Boolean );
                for ( let n = Math.min( 4, words.length ); n >= 2; n-- ) {
                    for ( let i = 0; i + n <= words.length; i++ ) {
                        const slice = words.slice( i, i + n );
                        if ( STOPWORDS.has( slice[ 0 ].toLowerCase() ) || STOPWORDS.has( slice[ n - 1 ].toLowerCase() ) ) {
                            continue;
                        }
                        const phrase = slice.join( " " );
                        if ( phraseWeight( phrase ) >= 6 && ! found.has( phrase.toLowerCase() ) ) {
                            found.set( phrase.toLowerCase(), { phrase, n } );
                        }
                    }
                }
                // A one-word title ("한국은행", "Espresso") is the page's name: link it where it is used.
                if ( words.length === 1 && phraseWeight( words[ 0 ] ) >= 4 && ! STOPWORDS.has( words[ 0 ].toLowerCase() ) && ! found.has( words[ 0 ].toLowerCase() ) ) {
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
