/**
 * JnK Linkweave: link ideas inside the Elementor editor (free, read-only).
 *
 * Page Settings has a "Linkweave link ideas" section (a RAW_HTML control with an
 * empty .jnk-lw-el-ideas box). Elementor draws that panel only when it is opened,
 * so watch for the box and fill it from the same REST route the block editor
 * sidebar uses. Each idea has a Copy link button: the link is added with
 * Elementor's own link box, so Elementor's undo history covers it and nothing
 * here writes to the page.
 */
( function () {
    "use strict";
    const cfg = window.JnkLinkweaveElementor;
    if ( ! cfg || ! window.wp || ! wp.i18n ) {
        return;
    }
    const { __, sprintf } = wp.i18n;
    const cache = {};

    function esc( v ) {
        return String( v == null ? "" : v ).replace( /[&<>"']/g, ( c ) => ( { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ] ) );
    }

    function readable( url ) {
        try {
            return decodeURI( url );
        } catch ( e ) {
            return url;
        }
    }

    async function load( postId ) {
        if ( ! cache[ postId ] ) {
            cache[ postId ] = fetch( cfg.root + "suggestions/" + encodeURIComponent( postId ), {
                headers: { "X-WP-Nonce": cfg.nonce },
                credentials: "same-origin",
            } ).then( ( r ) => {
                if ( ! r.ok ) {
                    throw new Error( __( "Could not load link ideas.", "jnk-linkweave" ) );
                }
                return r.json();
            } );
        }
        return cache[ postId ];
    }

    function render( box, data ) {
        const list = ( data && data.suggestions ) || [];
        if ( ! list.length ) {
            box.innerHTML = "<p>" + esc( __( "No link ideas for this page yet. Linkweave reads it again after you publish or update it.", "jnk-linkweave" ) ) + "</p>";
            return;
        }
        box.innerHTML =
            '<p style="margin:0 0 8px">' + esc( __( "Select words in a Text Editor widget, click its link button and paste. Or paste into a button's Link field.", "jnk-linkweave" ) ) + "</p>"
            + '<ul style="margin:0;padding:0;list-style:none">'
            + list.slice( 0, 10 ).map( ( s ) =>
                '<li style="margin:0 0 10px">'
                + '<strong style="display:block">' + esc( s.title ) + "</strong>"
                + '<span dir="ltr" style="display:block;font-size:11px;opacity:.75;overflow-wrap:anywhere">' + esc( readable( s.url ) ) + "</span>"
                + '<button type="button" class="elementor-button elementor-button-default" style="margin-top:4px;padding:4px 10px" data-jnk-copy="' + esc( s.url ) + '">' + esc( __( "Copy link", "jnk-linkweave" ) ) + "</button>"
                + "</li>"
            ).join( "" )
            + "</ul>";
    }

    async function fill( box ) {
        if ( box.dataset.filled ) {
            return;
        }
        box.dataset.filled = "1";
        try {
            render( box, await load( box.dataset.post ) );
        } catch ( e ) {
            box.innerHTML = "<p>" + esc( e.message ) + "</p>";
        }
    }

    document.addEventListener( "click", async function ( ev ) {
        const btn = ev.target.closest && ev.target.closest( "[data-jnk-copy]" );
        if ( ! btn ) {
            return;
        }
        const url = btn.getAttribute( "data-jnk-copy" );
        let ok = false;
        try {
            await navigator.clipboard.writeText( url );
            ok = true;
        } catch ( e ) {
            // Older browsers / no permission: select a temporary field instead.
            const t = document.createElement( "textarea" );
            t.value = url;
            document.body.appendChild( t );
            t.select();
            try {
                ok = document.execCommand( "copy" );
            } catch ( x ) {
                ok = false;
            }
            t.remove();
        }
        const label = btn.textContent;
        /* translators: %s: the address to copy by hand. */
        const manual = __( "Copy this: %s", "jnk-linkweave" );
        btn.textContent = ok ? __( "Copied", "jnk-linkweave" ) : sprintf( manual, url );
        setTimeout( () => { btn.textContent = label; }, 2000 );
    } );

    const observer = new MutationObserver( () => {
        document.querySelectorAll( ".jnk-lw-el-ideas:not([data-filled])" ).forEach( fill );
    } );
    observer.observe( document.documentElement, { childList: true, subtree: true } );
} )();
