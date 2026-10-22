/**
 * Linkweave link map (Inbound links screen).
 *
 * Two views of the same data, both readable at thousands of posts:
 * - Posts: one square per post, grouped by topic, coloured by how many of your
 *   posts link to it. Red squares are posts nothing links to.
 * - Topics: a grid of how many links run from each topic to each other topic.
 */
( function () {
    "use strict";

    const host = document.getElementById( "jnk-linkweave-map" );
    if ( ! host ) {
        return;
    }
    let data;
    try {
        data = JSON.parse( host.getAttribute( "data-map" ) || "{}" );
    } catch ( e ) {
        return;
    }
    const { __, _n, sprintf } = wp.i18n;
    const rows = data.rows || [];
    const tip = host.querySelector( ".jnk-map-tip" );

    const BUCKETS = [
        { min: 0, label: __( "No links in", "jnk-linkweave" ), cls: "b0" },
        { min: 1, label: __( "1 to 2 links", "jnk-linkweave" ), cls: "b1" },
        { min: 3, label: __( "3 to 5 links", "jnk-linkweave" ), cls: "b3" },
        { min: 6, label: __( "6 or more", "jnk-linkweave" ), cls: "b6" },
    ];
    const bucket = ( n ) => BUCKETS.filter( ( b ) => n >= b.min ).pop();
    const esc = ( s ) => String( s ).replace( /[&<>"']/g, ( c ) => ( { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ] ) );
    /* translators: %d: number of posts. */
    const postCount = ( n ) => sprintf( _n( "%d post", "%d posts", n, "jnk-linkweave" ), n );
    /* translators: %d: number of internal links pointing at a post. */
    const linksIn = ( n ) => sprintf( _n( "%d link in", "%d links in", n, "jnk-linkweave" ), n );
    /* translators: %d: number of links. */
    const linkCount = ( n ) => sprintf( _n( "%d link", "%d links", n, "jnk-linkweave" ), n );

    /* ---------- Posts view ---------- */
    const tiles = document.createElement( "div" );
    const total = rows.reduce( ( a, r ) => a + r.posts.length, 0 );
    // Small sites get bigger squares; big sites keep them small so a topic fits on a line or two.
    tiles.className = "jnk-map-tiles" + ( total < 200 ? " is-large" : "" );
    let html = '<div class="jnk-map-legend">' + BUCKETS.map( ( b ) => `<span><i class="jnk-tile ${ b.cls }"></i>${ b.label }</span>` ).join( "" ) + "</div>";
    rows.forEach( ( row, r ) => {
        const zero = row.posts.filter( ( p ) => p[ 1 ] === 0 ).length;
        html += '<div class="jnk-map-row">'
            + `<div class="jnk-map-name"><strong>${ esc( row.name ) }</strong><span>${ postCount( row.posts.length ) }`
            /* translators: %d: number of posts with no internal links pointing at them. */
            + ( zero ? ` · <em>${ esc( sprintf( _n( "%d with no links in", "%d with no links in", zero, "jnk-linkweave" ), zero ) ) }</em>` : "" ) + "</span></div>"
            + '<div class="jnk-map-cells">'
            + row.posts.map( ( p, i ) => `<a class="jnk-tile ${ bucket( p[ 1 ] ).cls }" href="${ esc( data.edit + p[ 0 ] ) }" data-r="${ r }" data-i="${ i }" aria-label="${ esc( p[ 2 ] ) }: ${ esc( linksIn( p[ 1 ] ) ) }"></a>` ).join( "" )
            + "</div></div>";
    } );
    tiles.innerHTML = html;
    host.insertBefore( tiles, tip );

    const showTip = ( el ) => {
        const p = rows[ +el.dataset.r ].posts[ +el.dataset.i ];
        tip.innerHTML = `<strong>${ esc( p[ 2 ] ) }</strong>${ esc( p[ 1 ] === 0 ? __( "Nothing links here yet", "jnk-linkweave" ) : linksIn( p[ 1 ] ) ) }`;
        const a = el.getBoundingClientRect();
        const h = host.getBoundingClientRect();
        tip.style.left = ( a.left - h.left + a.width / 2 ) + "px";
        tip.style.top = ( a.top - h.top ) + "px";
        tip.hidden = false;
    };
    tiles.addEventListener( "mouseover", ( e ) => {
        const el = e.target.closest( ".jnk-map-cells .jnk-tile" );
        if ( el ) {
            showTip( el );
        }
    } );
    tiles.addEventListener( "focusin", ( e ) => {
        if ( e.target.matches( ".jnk-map-cells .jnk-tile" ) ) {
            showTip( e.target );
        }
    } );
    tiles.addEventListener( "mouseleave", () => { tip.hidden = true; } );
    tiles.addEventListener( "focusout", () => { tip.hidden = true; } );

    /* ---------- Topics view ---------- */
    const names = data.topics || [];
    const m = data.matrix || [];
    if ( names.length < 2 ) {
        return;
    }
    const grid = document.createElement( "div" );
    grid.className = "jnk-map-grid";
    grid.hidden = true;
    const max = Math.max( 1, ...m.flat() );
    const out = m.map( ( row ) => row.reduce( ( a, b ) => a + b, 0 ) );
    let g = '<p class="jnk-map-help">' + esc( __( "Read across a row: how many links that topic's posts send to each topic. The shaded diagonal is links inside the same topic. A row of empty cells is a topic that never links out.", "jnk-linkweave" ) ) + "</p>"
        + '<div class="jnkui-table-wrap"><table class="jnk-matrix"><thead><tr><th class="jnk-matrix-corner">' + esc( __( "From ↓", "jnk-linkweave" ) ) + " &nbsp; " + esc( __( "To →", "jnk-linkweave" ) ) + "</th>"
        + names.map( ( n ) => `<th scope="col"><span>${ esc( n ) }</span></th>` ).join( "" ) + '<th scope="col"><span>' + esc( __( "Total out", "jnk-linkweave" ) ) + "</span></th></tr></thead><tbody>";
    names.forEach( ( from, i ) => {
        g += `<tr><th scope="row">${ esc( from ) }</th>`;
        names.forEach( ( to, j ) => {
            const v = m[ i ][ j ];
            const a = v ? 0.12 + 0.78 * ( v / max ) : 0;
            const style = v ? ` style="background:rgba(14,116,144,${ a.toFixed( 2 ) });color:${ a > 0.5 ? "#fff" : "#0f172a" }"` : "";
            g += `<td class="${ i === j ? "is-self" : "" }${ v ? "" : " is-zero" }"${ style } title="${ esc( from ) } → ${ esc( to ) }: ${ esc( linkCount( v ) ) }">${ v || "" }</td>`;
        } );
        g += `<td class="jnk-matrix-total${ out[ i ] ? "" : " is-none" }">${ out[ i ] || esc( __( "None", "jnk-linkweave" ) ) }</td></tr>`;
    } );
    g += "</tbody></table></div>";
    grid.innerHTML = g;
    host.insertBefore( grid, tip );

    const card = host.closest( ".jnkui-card" );
    const subText = card.querySelector( ".jnkui-card-head p" );
    if ( subText ) {
        subText.dataset.tiles = subText.textContent;
    }
    card.querySelectorAll( "[data-view]" ).forEach( ( btn ) => {
        btn.addEventListener( "click", () => {
            card.querySelectorAll( "[data-view]" ).forEach( ( b ) => b.classList.toggle( "is-active", b === btn ) );
            tiles.hidden = btn.dataset.view !== "tiles";
            grid.hidden = btn.dataset.view !== "grid";
            tip.hidden = true;
            const sub = card.querySelector( ".jnkui-card-head p" );
            if ( sub ) {
                sub.textContent = btn.dataset.view === "grid"
                    ? __( "Which topics link to which. Topics that link to each other help readers move around your site.", "jnk-linkweave" )
                    : sub.dataset.tiles || sub.textContent;
            }
        } );
    } );
} )();
