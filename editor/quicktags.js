/* global QTags, wp */
/**
 * JnK Linkweave in the classic editor's Code (Text) tab.
 *
 * The suggestions button lived only in the Visual tab's toolbar, so someone
 * writing in Code saw no way to get suggestions (persona run, 29 Oct 2026).
 * This adds the same button to the Code tab's toolbar. A picked suggestion
 * links the selected words; otherwise a title phrase already in the text
 * (same rules as the Visual tab); otherwise the title goes in at the cursor.
 */
( function () {
    "use strict";

    if ( typeof QTags === "undefined" || ! window.JnkLinkweaveTinyMCE ) {
        return;
    }

    const cfg = window.JnkLinkweaveTinyMCE;
    const i18n = cfg.i18n || {};

    function buildUrl( path ) {
        path = String( path ).replace( /^\//, "" );
        const q = path.indexOf( "?" );
        const route = q === -1 ? path : path.slice( 0, q );
        const query = q === -1 ? "" : path.slice( q + 1 );
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

    function strength( score, terms ) {
        const labels = i18n.strength || [ "Very close match", "Close match", "Related", "Loosely related" ];
        // One shared word is never a very close match (editor/src/strength.js).
        const fewWords = Array.isArray( terms ) && terms.length < 2;
        return labels[ score >= 0.85 && ! fewWords ? 0 : score >= 0.6 ? 1 : score >= 0.4 ? 2 : 3 ];
    }

    function emptyMessage( reason ) {
        const reasons = i18n.reasons || {};
        const code = reason && reason.code;
        if ( code === "too_short" && reasons.too_short ) {
            return reasons.too_short.replace( "%1$d", String( reason.words || 0 ) ).replace( "%2$d", String( reason.min_words || 0 ) );
        }
        return ( code && reasons[ code ] ) || i18n.empty;
    }

    // ---- link placement in HTML source (pure, tested) ----
    /**
     * Link the first unlinked whole-word use of a title phrase in HTML source.
     * Text inside links, headings, code, pre, script and style, and inside tags
     * and comments, is left alone.
     *
     * @return {{html:string, words:string}|null}
     */
    function linkPhraseInSource( html, url, phrases, phrasePattern ) {
        const parts = String( html ).split( /(<!--[\s\S]*?-->|<[^>]+>)/ );
        const skip = /^(a|code|pre|h[1-6]|script|style)$/i;
        for ( const phrase of phrases ) {
            const re = phrasePattern( phrase );
            let depth = 0;
            for ( let i = 0; i < parts.length; i++ ) {
                const part = parts[ i ];
                if ( i % 2 === 1 ) {
                    const tag = part.match( /^<\s*(\/)?\s*([a-z0-9]+)/i );
                    if ( tag && skip.test( tag[ 2 ] ) && ! /\/>$/.test( part ) ) {
                        depth = Math.max( 0, depth + ( tag[ 1 ] ? -1 : 1 ) );
                    }
                    continue;
                }
                if ( depth > 0 || ! part ) {
                    continue;
                }
                const m = re.exec( part );
                if ( ! m ) {
                    continue;
                }
                const start = m.index + m[ 1 ].length;
                const words = m[ 2 ];
                parts[ i ] = part.slice( 0, start ) + '<a href="' + escape( url ) + '" class="jnk-linkweave-suggested">' + words + "</a>" + part.slice( start + words.length );
                return { html: parts.join( "" ), words: words };
            }
        }
        return null;
    }
    // ---- end pure ----

    function insert( textarea, rawUrl, title, id, postId ) {
        if ( ! /^https?:\/\//i.test( rawUrl ) ) {
            return;
        }
        const helpers = window.JnkLinkweavePhrases;
        const startPos = textarea.selectionStart;
        const endPos = textarea.selectionEnd;
        const value = textarea.value;
        let anchor = value.slice( startPos, endPos );
        if ( anchor ) {
            // The writer chose the words: link exactly those.
            textarea.value = value.slice( 0, startPos ) + '<a href="' + escape( rawUrl ) + '" class="jnk-linkweave-suggested">' + anchor + "</a>" + value.slice( endPos );
        } else {
            const placed = helpers ? linkPhraseInSource( value, rawUrl, helpers.titlePhrases( title ), helpers.phrasePattern ) : null;
            if ( placed ) {
                textarea.value = placed.html;
                anchor = placed.words;
            } else {
                anchor = helpers ? helpers.readableTitle( title ) : decodeEntities( title );
                const before = value.slice( 0, startPos );
                const space = before && ! /\s$/.test( before ) ? " " : "";
                textarea.value = before + space + '<a href="' + escape( rawUrl ) + '" class="jnk-linkweave-suggested">' + escape( anchor ) + "</a>" + value.slice( startPos );
            }
        }
        textarea.dispatchEvent( new Event( "input", { bubbles: true } ) );
        textarea.dispatchEvent( new Event( "change", { bubbles: true } ) );
        fetchJSON( "links/insert", {
            method: "POST",
            body: { source_post_id: postId, target_post_id: id, anchor_text: anchor },
        } ).catch( () => {} );
    }

    function closePanel() {
        const old = document.getElementById( "jnk-linkweave-qt-panel" );
        if ( old ) {
            old.remove();
        }
    }

    function showPanel( textarea, html ) {
        closePanel();
        const panel = document.createElement( "div" );
        panel.id = "jnk-linkweave-qt-panel";
        panel.setAttribute( "role", "dialog" );
        panel.setAttribute( "aria-label", i18n.title || "JnK Linkweave" );
        panel.style.cssText = "border:1px solid #c3c4c7;background:#fff;padding:10px 12px;margin:0 0 8px;max-height:340px;overflow:auto;box-shadow:0 2px 6px rgba(0,0,0,.08)";
        panel.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px"><strong>' + escape( i18n.title || "JnK Linkweave" ) + "</strong>"
            + '<button type="button" class="button button-small" data-close>' + escape( i18n.close || "Close" ) + "</button></div>" + html;
        textarea.parentNode.insertBefore( panel, textarea );
        panel.querySelector( "[data-close]" ).addEventListener( "click", closePanel );
        return panel;
    }

    function postIdNow() {
        const field = document.getElementById( "post_ID" );
        return field ? parseInt( field.value, 10 ) || 0 : 0;
    }

    QTags.addButton( "jnk_linkweave", "JnK Linkweave", async function ( button, textarea ) {
        const postId = postIdNow();
        if ( ! postId ) {
            showPanel( textarea, "<p>" + escape( i18n.noPost ) + "</p>" );
            return;
        }
        // Keep the writer's selection: the panel takes focus.
        const selStart = textarea.selectionStart;
        const selEnd = textarea.selectionEnd;
        const data = await fetchJSON( "suggestions/" + postId ).catch( () => null );
        const suggestions = ( data && data.suggestions ) || [];
        if ( ! suggestions.length ) {
            showPanel( textarea, '<p style="white-space:normal">' + escape( emptyMessage( data && data.reason ) ) + "</p>" );
            return;
        }
        const panel = showPanel( textarea,
            ( i18n.hint ? '<p style="margin:0 0 8px;color:#50575e">' + escape( i18n.hint ) + "</p>" : "" ) +
            suggestions.map( ( s ) =>
                '<div style="border-bottom:1px solid #eee;padding:6px 0;display:flex;justify-content:space-between;align-items:center;gap:8px">' +
                "<span><strong>" + escape( decodeEntities( s.title ) ) + "</strong> <small>(" + escape( strength( Number( s.score ), s.matched_terms ) ) + ")</small></span>" +
                '<button type="button" class="button button-primary button-small" data-url="' + escape( s.url ) + '" data-title="' + escape( s.title ) + '" data-id="' + escape( s.target_id ) + '">' + escape( i18n.insert || "Insert" ) + "</button></div>"
            ).join( "" )
        );
        panel.querySelectorAll( "button[data-url]" ).forEach( ( btn ) => {
            btn.addEventListener( "click", () => {
                textarea.focus();
                textarea.setSelectionRange( selStart, selEnd );
                insert( textarea, btn.getAttribute( "data-url" ), btn.getAttribute( "data-title" ), parseInt( btn.getAttribute( "data-id" ), 10 ), postId );
                closePanel();
            } );
        } );
    }, "", "", i18n.tooltip || "" );
} )();
