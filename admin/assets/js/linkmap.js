/**
 * Linkweave link map (Inbound links screen).
 *
 * Two views of the same data, both readable at thousands of posts:
 * - Posts: one square per post, grouped by topic, coloured by how many of your
 *   posts link to it. Red squares are posts nothing links to.
 * - Graph: one dot per post, topic islands, zoom/pan, click a dot for its links.
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
    const grid = document.createElement( "div" );
    grid.className = "jnk-map-grid";
    grid.hidden = true;
    if ( names.length > 1 ) {
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
    }

    /* ---------- Graph view (built the first time it is opened) ---------- */
    let graph = null;

    const card = host.closest( ".jnkui-card" );
    const subText = card.querySelector( ".jnkui-card-head p" );
    if ( subText ) {
        subText.dataset.tiles = subText.textContent;
    }
    card.querySelectorAll( "[data-view]" ).forEach( ( btn ) => {
        btn.addEventListener( "click", () => {
            card.querySelectorAll( "[data-view]" ).forEach( ( b ) => b.classList.toggle( "is-active", b === btn ) );
            const view = btn.dataset.view;
            if ( view === "graph" && ! graph ) {
                graph = buildGraph( host, tip, data, rows );
            }
            tiles.hidden = view !== "tiles";
            grid.hidden = view !== "grid";
            if ( graph ) {
                graph.show( view === "graph" );
            }
            tip.hidden = true;
            const sub = card.querySelector( ".jnkui-card-head p" );
            if ( sub ) {
                sub.textContent = view === "grid"
                    ? __( "Which topics link to which. Topics that link to each other help readers move around your site.", "jnk-linkweave" )
                    : view === "graph"
                        ? __( "Every dot is one post, grouped into islands by topic. Bigger dots have more links in, red dots have none. Drag to move, scroll or pinch to zoom, click a dot to see its links.", "jnk-linkweave" )
                        : sub.dataset.tiles || sub.textContent;
            }
        } );
    } );

    /**
     * Graph view: one dot per post on a canvas, grouped into topic islands
     * (biggest topic in the middle), hubs at each island's centre. Zoom, pan,
     * click a dot for its links in and out. Drawn only when something changes;
     * dots shrink to squares and labels drop out when zoomed far out, so it
     * stays smooth at a few thousand posts.
     */
    function buildGraph( mapHost, mapTip, d, topicRows ) {
        const COLORS = { b0: "#ef4444", b1: "#f59e0b", b3: "#22c55e", b6: "#0e7490" };
        const IN_COLOR = "#0e7490";
        const OUT_COLOR = "#7c3aed";
        const types = d.types || [];
        const nodes = [];
        const byId = new Map();
        topicRows.forEach( ( row, t ) => {
            row.posts.forEach( ( p ) => {
                const n = { id: p[ 0 ], inn: p[ 1 ], title: p[ 2 ], type: p[ 3 ] || 0, topic: t, out: [], from: [], x: 0, y: 0 };
                n.r = 3 + Math.min( 9, Math.sqrt( n.inn ) * 1.6 );
                nodes.push( n );
                byId.set( n.id, n );
            } );
        } );
        const ed = d.edges || [];
        const edges = [];
        for ( let i = 0; i + 1 < ed.length; i += 2 ) {
            const a = byId.get( ed[ i ] );
            const b = byId.get( ed[ i + 1 ] );
            if ( a && b ) {
                a.out.push( b );
                b.from.push( a );
                edges.push( [ a, b ] );
            }
        }

        const byInn = nodes.slice().sort( ( a, b ) => b.inn - a.inn );

        // Layout: each topic is a sunflower of dots, most-linked in the middle.
        const islands = topicRows.map( ( row, t ) => {
            const members = nodes.filter( ( n ) => n.topic === t ).sort( ( a, b ) => b.inn - a.inn );
            let area = 0;
            members.forEach( ( n, i ) => {
                const cell = ( 2 * n.r + 3 ) * ( 2 * n.r + 3 );
                const rad = i === 0 ? 0 : Math.sqrt( area / Math.PI );
                const ang = i * 2.39996323;
                n.x = Math.cos( ang ) * rad;
                n.y = Math.sin( ang ) * rad;
                area += cell;
            } );
            const R = Math.sqrt( area / Math.PI ) + 14;
            return { name: row.name, members, R, x: 0, y: 0 };
        } );
        // Pack the islands: biggest first, each next one at the nearest free spot.
        const placed = [];
        islands.slice().sort( ( a, b ) => b.R - a.R ).forEach( ( isl ) => {
            if ( ! placed.length ) {
                placed.push( isl );
                return;
            }
            for ( let dist = 0; ; dist += 12 ) {
                let best = null;
                for ( let k = 0; k < 36 && ! best; k++ ) {
                    const ang = k * Math.PI / 18;
                    const x = Math.cos( ang ) * dist;
                    const y = Math.sin( ang ) * dist;
                    if ( placed.every( ( o ) => Math.hypot( o.x - x, o.y - y ) >= o.R + isl.R + 26 ) ) {
                        best = [ x, y ];
                    }
                }
                if ( best ) {
                    isl.x = best[ 0 ];
                    isl.y = best[ 1 ];
                    placed.push( isl );
                    return;
                }
            }
        } );
        islands.forEach( ( isl ) => isl.members.forEach( ( n ) => { n.x += isl.x; n.y += isl.y; } ) );

        // Spatial grid for hit-testing.
        const CELL = 30;
        const grid = new Map();
        nodes.forEach( ( n ) => {
            const k = Math.floor( n.x / CELL ) + "," + Math.floor( n.y / CELL );
            if ( ! grid.has( k ) ) {
                grid.set( k, [] );
            }
            grid.get( k ).push( n );
        } );

        /* ----- DOM ----- */
        const wrap = document.createElement( "div" );
        wrap.className = "jnk-graph";
        const opt = ( v, l ) => `<option value="${ esc( v ) }">${ esc( l ) }</option>`;
        wrap.innerHTML = '<div class="jnk-graph-bar">'
            + `<label><span class="screen-reader-text">${ esc( __( "Topic", "jnk-linkweave" ) ) }</span><select data-g="topic">${ opt( "", __( "All topics", "jnk-linkweave" ) ) }${ islands.map( ( isl, i ) => opt( i, isl.name ) ).join( "" ) }</select></label>`
            + ( types.length > 1 ? `<label><span class="screen-reader-text">${ esc( __( "Post type", "jnk-linkweave" ) ) }</span><select data-g="type">${ opt( "", __( "All post types", "jnk-linkweave" ) ) }${ types.map( ( t, i ) => opt( i, t[ 1 ] ) ).join( "" ) }</select></label>` : "" )
            + `<label class="jnk-graph-check"><input type="checkbox" data-g="orphans"> ${ esc( __( "Only posts with no links in", "jnk-linkweave" ) ) }</label>`
            + `<span class="jnk-graph-find"><input type="search" data-g="find" placeholder="${ esc( __( "Find a post", "jnk-linkweave" ) ) }" aria-label="${ esc( __( "Find a post", "jnk-linkweave" ) ) }" autocomplete="off"><span class="jnk-graph-hits" hidden></span></span>`
            + '<span class="jnk-graph-zoom">'
            + `<button type="button" class="button button-small" data-g="in" aria-label="${ esc( __( "Zoom in", "jnk-linkweave" ) ) }">+</button>`
            + `<button type="button" class="button button-small" data-g="outz" aria-label="${ esc( __( "Zoom out", "jnk-linkweave" ) ) }">−</button>`
            + `<button type="button" class="button button-small" data-g="fit">${ esc( __( "Fit", "jnk-linkweave" ) ) }</button></span>`
            + "</div>"
            + '<div class="jnk-graph-body"><div class="jnk-graph-stage">'
            + `<canvas tabindex="0" aria-label="${ esc( __( "Link graph. Use the arrow keys to move, plus and minus to zoom.", "jnk-linkweave" ) ) }"></canvas>`
            + '<p class="jnk-graph-count"></p></div>'
            + `<aside class="jnk-graph-panel" aria-live="polite"><p class="jnkui-muted">${ esc( __( "Click a dot to see which posts link to it and where it links.", "jnk-linkweave" ) ) }</p></aside>`
            + "</div>";
        mapHost.insertBefore( wrap, mapTip );
        const canvas = wrap.querySelector( "canvas" );
        const ctx = canvas.getContext( "2d" );
        const panel = wrap.querySelector( ".jnk-graph-panel" );
        const countEl = wrap.querySelector( ".jnk-graph-count" );
        const q = ( k ) => wrap.querySelector( `[data-g="${ k }"]` );

        const css = getComputedStyle( mapHost );
        const tok = ( name, fb ) => ( css.getPropertyValue( name ) || "" ).trim() || fb;
        const INK = tok( "--jnk-ink", "#0f172a" );
        const MUTE = tok( "--jnk-mute", "#64748b" );
        const LINE = tok( "--jnk-mute", "#64748b" );
        const BG = tok( "--jnk-card", "#fff" );

        /* ----- state ----- */
        let k = 1;
        let tx = 0;
        let ty = 0;
        let W = 0;
        let H = 0;
        let selected = null;
        let hover = null;
        let fTopic = "";
        let fType = "";
        let fOrphans = false;
        let dirty = false;
        let visible = true;

        const shown = ( n ) => ( fTopic === "" || n.topic === +fTopic )
            && ( fType === "" || n.type === +fType )
            && ( ! fOrphans || n.inn === 0 );
        const bucketOf = ( n ) => n.inn === 0 ? "b0" : n.inn < 3 ? "b1" : n.inn < 6 ? "b3" : "b6";

        function resize() {
            const dpr = window.devicePixelRatio || 1;
            W = canvas.clientWidth;
            H = canvas.clientHeight;
            canvas.width = Math.round( W * dpr );
            canvas.height = Math.round( H * dpr );
            ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
            draw();
        }

        function fit( list ) {
            const pts = ( list && list.length ? list : nodes );
            let x0 = Infinity;
            let y0 = Infinity;
            let x1 = -Infinity;
            let y1 = -Infinity;
            pts.forEach( ( n ) => {
                x0 = Math.min( x0, n.x - n.r );
                y0 = Math.min( y0, n.y - n.r );
                x1 = Math.max( x1, n.x + n.r );
                y1 = Math.max( y1, n.y + n.r );
            } );
            if ( ! isFinite( x0 ) ) {
                return;
            }
            const pad = 40;
            k = Math.min( 4, Math.max( 0.05, Math.min( ( W - pad ) / Math.max( 1, x1 - x0 ), ( H - pad ) / Math.max( 1, y1 - y0 ) ) ) );
            tx = W / 2 - ( ( x0 + x1 ) / 2 ) * k;
            ty = H / 2 - ( ( y0 + y1 ) / 2 ) * k;
            draw();
        }

        function draw() {
            if ( dirty || ! visible ) {
                return;
            }
            dirty = true;
            requestAnimationFrame( paint );
        }

        function paint() {
            dirty = false;
            ctx.fillStyle = BG;
            ctx.fillRect( 0, 0, W, H );
            ctx.save();
            ctx.translate( tx, ty );
            ctx.scale( k, k );
            const vx0 = -tx / k;
            const vy0 = -ty / k;
            const vx1 = ( W - tx ) / k;
            const vy1 = ( H - ty ) / k;
            const onScreen = ( n ) => n.x + n.r >= vx0 && n.x - n.r <= vx1 && n.y + n.r >= vy0 && n.y - n.r <= vy1;
            const lw = 1 / k;

            // Links: faint when nothing is picked (left out when zoomed far out on
            // a big site), coloured in/out for the picked post.
            if ( ! selected && ( edges.length < 6000 || k > 0.5 ) ) {
                ctx.beginPath();
                edges.forEach( ( [ a, b ] ) => {
                    if ( ( onScreen( a ) || onScreen( b ) ) && shown( a ) && shown( b ) ) {
                        ctx.moveTo( a.x, a.y );
                        ctx.lineTo( b.x, b.y );
                    }
                } );
                ctx.globalAlpha = edges.length > 3000 ? 0.07 : 0.14;
                ctx.strokeStyle = LINE;
                ctx.lineWidth = lw;
                ctx.stroke();
                ctx.globalAlpha = 1;
            }
            if ( selected ) {
                [ [ selected.from, IN_COLOR ], [ selected.out, OUT_COLOR ] ].forEach( ( [ list, color ] ) => {
                    ctx.beginPath();
                    list.forEach( ( o ) => {
                        ctx.moveTo( selected.x, selected.y );
                        ctx.lineTo( o.x, o.y );
                    } );
                    ctx.strokeStyle = color;
                    ctx.globalAlpha = 0.7;
                    ctx.lineWidth = 1.6 * lw;
                    ctx.stroke();
                } );
                ctx.globalAlpha = 1;
            }

            // Dots, one path per colour. Dimmed when filtered out or not linked
            // to the picked post.
            const near = selected ? new Set( [ selected, ...selected.from, ...selected.out ] ) : null;
            const tiny = k < 0.6;
            [ true, false ].forEach( ( lit ) => {
                Object.keys( COLORS ).forEach( ( c ) => {
                    ctx.beginPath();
                    let any = false;
                    nodes.forEach( ( n ) => {
                        const on = shown( n ) && ( ! near || near.has( n ) );
                        if ( on !== lit || bucketOf( n ) !== c || ! onScreen( n ) ) {
                            return;
                        }
                        any = true;
                        if ( tiny && n.r * k < 2 ) {
                            ctx.rect( n.x - n.r, n.y - n.r, 2 * n.r, 2 * n.r );
                        } else {
                            ctx.moveTo( n.x + n.r, n.y );
                            ctx.arc( n.x, n.y, n.r, 0, Math.PI * 2 );
                        }
                    } );
                    if ( any ) {
                        ctx.globalAlpha = lit ? 1 : 0.13;
                        ctx.fillStyle = COLORS[ c ];
                        ctx.fill();
                    }
                } );
            } );
            ctx.globalAlpha = 1;
            [ selected, hover ].forEach( ( n ) => {
                if ( n ) {
                    ctx.beginPath();
                    ctx.arc( n.x, n.y, n.r + 3 / k, 0, Math.PI * 2 );
                    ctx.strokeStyle = INK;
                    ctx.lineWidth = 2 * lw;
                    ctx.stroke();
                }
            } );
            ctx.restore();

            // Labels in screen space: topic names first, then post titles once the
            // dots are big enough to tell apart, most-linked first, skipping any
            // label that would overlap one already drawn (at most 160).
            const taken = [];
            const free = ( x, y, w, h ) => {
                if ( taken.some( ( t ) => x < t[ 0 ] + t[ 2 ] && x + w > t[ 0 ] && y < t[ 1 ] + t[ 3 ] && y + h > t[ 1 ] ) ) {
                    return false;
                }
                taken.push( [ x, y, w, h ] );
                return true;
            };
            ctx.textAlign = "center";
            ctx.textBaseline = "top";
            ctx.font = "600 12px -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
            islands.forEach( ( isl, i ) => {
                if ( fTopic !== "" && +fTopic !== i ) {
                    return;
                }
                const cx = isl.x * k + tx;
                const sy = Math.max( 4, ( isl.y - isl.R ) * k + ty - 16 );
                if ( cx > -200 && cx < W + 200 && sy < H ) {
                    const w = ctx.measureText( isl.name ).width + 8;
                    // Keep the name inside the canvas when its island is near an edge.
                    const sx = Math.min( Math.max( cx, w / 2 + 2 ), Math.max( w / 2 + 2, W - w / 2 - 2 ) );
                    // Opaque backing so the name stays readable over dots and links.
                    ctx.globalAlpha = 0.85;
                    ctx.fillStyle = BG;
                    ctx.fillRect( sx - w / 2, sy - 1, w, 16 );
                    ctx.globalAlpha = 1;
                    ctx.fillStyle = INK;
                    ctx.fillText( isl.name, sx, sy );
                    taken.push( [ sx - w / 2, sy, w, 15 ] );
                }
            } );
            ctx.font = "11px -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
            ctx.fillStyle = MUTE;
            let labels = 0;
            const pool = near ? [ selected, ...[ ...near ].filter( ( n ) => n !== selected ) ] : ( k * 6 > 9 ? byInn : [] );
            for ( const n of pool ) {
                if ( labels >= 160 ) {
                    break;
                }
                if ( ! shown( n ) && n !== selected ) {
                    continue;
                }
                const sx = n.x * k + tx;
                const sy = ( n.y + n.r ) * k + ty + 2;
                if ( sx < -50 || sx > W + 50 || sy < -10 || sy > H ) {
                    continue;
                }
                const t = n.title.length > 34 ? n.title.slice( 0, 33 ) + "…" : n.title;
                const w = ctx.measureText( t ).width;
                if ( n === selected || free( sx - w / 2, sy, w, 13 ) ) {
                    if ( n === selected ) {
                        taken.push( [ sx - w / 2, sy, w, 13 ] );
                        ctx.fillStyle = INK;
                    }
                    ctx.fillText( t, sx, sy );
                    ctx.fillStyle = MUTE;
                    labels++;
                }
            }
        }

        function hit( sx, sy ) {
            const wx = ( sx - tx ) / k;
            const wy = ( sy - ty ) / k;
            const cx = Math.floor( wx / CELL );
            const cy = Math.floor( wy / CELL );
            let best = null;
            let bd = Infinity;
            for ( let i = -1; i <= 1; i++ ) {
                for ( let j = -1; j <= 1; j++ ) {
                    ( grid.get( ( cx + i ) + "," + ( cy + j ) ) || [] ).forEach( ( n ) => {
                        if ( ! shown( n ) ) {
                            return;
                        }
                        const dd = Math.hypot( n.x - wx, n.y - wy );
                        if ( dd <= n.r + 4 / k && dd < bd ) {
                            bd = dd;
                            best = n;
                        }
                    } );
                }
            }
            return best;
        }

        const viewUrl = ( n ) => {
            const t = types[ n.type ];
            const slug = t ? t[ 0 ] : "post";
            return d.view + "?p=" + n.id + ( slug !== "post" ? "&post_type=" + encodeURIComponent( slug ) : "" );
        };

        function list( title, arr, color ) {
            if ( ! arr.length ) {
                return `<h4><i style="background:${ color }"></i>${ esc( title ) }</h4><p class="jnkui-muted">${ esc( __( "None", "jnk-linkweave" ) ) }</p>`;
            }
            const sorted = arr.slice().sort( ( a, b ) => a.title.localeCompare( b.title ) );
            const more = sorted.length - 40;
            return `<h4><i style="background:${ color }"></i>${ esc( title ) } <span>${ sorted.length }</span></h4><ul>`
                + sorted.slice( 0, 40 ).map( ( o ) => `<li><button type="button" class="button-link" data-pick="${ o.id }">${ esc( o.title || "#" + o.id ) }</button></li>` ).join( "" )
                /* translators: %d: number of further posts not listed. */
                + ( more > 0 ? `<li class="jnkui-muted">${ esc( sprintf( _n( "and %d more", "and %d more", more, "jnk-linkweave" ), more ) ) }</li>` : "" )
                + "</ul>";
        }

        function select( n, center ) {
            selected = n;
            if ( ! n ) {
                panel.innerHTML = `<p class="jnkui-muted">${ esc( __( "Click a dot to see which posts link to it and where it links.", "jnk-linkweave" ) ) }</p>`;
                draw();
                return;
            }
            const t = types[ n.type ];
            panel.innerHTML = `<h3>${ esc( n.title || "#" + n.id ) }</h3>`
                + `<p class="jnkui-muted">${ esc( islands[ n.topic ].name ) }${ t ? " · " + esc( t[ 1 ] ) : "" }</p>`
                + `<p>${ esc( n.inn === 0 ? __( "Nothing links here yet", "jnk-linkweave" ) : linksIn( n.inn ) ) }</p>`
                + `<p class="jnk-graph-actions"><a class="button button-small" href="${ esc( viewUrl( n ) ) }" target="_blank" rel="noopener">${ esc( __( "Open post", "jnk-linkweave" ) ) }</a> `
                + `<a class="button button-small" href="${ esc( d.edit + n.id ) }">${ esc( __( "Edit post", "jnk-linkweave" ) ) }</a></p>`
                + list( __( "Linked from", "jnk-linkweave" ), n.from, IN_COLOR )
                + list( __( "Links to", "jnk-linkweave" ), n.out, OUT_COLOR );
            if ( center ) {
                k = Math.max( k, 1.2 );
                tx = W / 2 - n.x * k;
                ty = H / 2 - n.y * k;
            }
            draw();
        }
        panel.addEventListener( "click", ( e ) => {
            const b = e.target.closest( "[data-pick]" );
            if ( b ) {
                select( byId.get( +b.dataset.pick ), true );
            }
        } );

        function updateCount() {
            const vis = nodes.filter( shown );
            const set = new Set( vis );
            const links = edges.filter( ( [ a, b ] ) => set.has( a ) && set.has( b ) ).length;
            /* translators: 1: number of posts, 2: number of links. */
            countEl.textContent = sprintf( __( "Showing %1$s and %2$s.", "jnk-linkweave" ), postCount( vis.length ), linkCount( links ) );
            return vis;
        }

        /* ----- input ----- */
        const pointers = new Map();
        let drag = null;
        let pinch = null;
        const zoomAt = ( sx, sy, f ) => {
            const nk = Math.min( 8, Math.max( 0.03, k * f ) );
            tx = sx - ( sx - tx ) * ( nk / k );
            ty = sy - ( sy - ty ) * ( nk / k );
            k = nk;
            draw();
        };
        const pos = ( e ) => {
            const r = canvas.getBoundingClientRect();
            return [ e.clientX - r.left, e.clientY - r.top ];
        };
        canvas.addEventListener( "pointerdown", ( e ) => {
            canvas.setPointerCapture( e.pointerId );
            pointers.set( e.pointerId, pos( e ) );
            if ( pointers.size === 1 ) {
                drag = { start: pos( e ), tx, ty, moved: false };
            } else if ( pointers.size === 2 ) {
                const [ a, b ] = [ ...pointers.values() ];
                pinch = { dist: Math.hypot( a[ 0 ] - b[ 0 ], a[ 1 ] - b[ 1 ] ), k };
                drag = null;
            }
        } );
        canvas.addEventListener( "pointermove", ( e ) => {
            const p = pos( e );
            if ( pointers.has( e.pointerId ) ) {
                pointers.set( e.pointerId, p );
            }
            if ( pinch && pointers.size === 2 ) {
                const [ a, b ] = [ ...pointers.values() ];
                const dist = Math.hypot( a[ 0 ] - b[ 0 ], a[ 1 ] - b[ 1 ] );
                zoomAt( ( a[ 0 ] + b[ 0 ] ) / 2, ( a[ 1 ] + b[ 1 ] ) / 2, ( pinch.k * dist / pinch.dist ) / k );
                return;
            }
            if ( drag ) {
                const dx = p[ 0 ] - drag.start[ 0 ];
                const dy = p[ 1 ] - drag.start[ 1 ];
                if ( Math.abs( dx ) + Math.abs( dy ) > 4 ) {
                    drag.moved = true;
                }
                if ( drag.moved ) {
                    tx = drag.tx + dx;
                    ty = drag.ty + dy;
                    mapTip.hidden = true;
                    draw();
                    return;
                }
            }
            const n = hit( p[ 0 ], p[ 1 ] );
            if ( n !== hover ) {
                hover = n;
                canvas.style.cursor = n ? "pointer" : "grab";
                if ( n ) {
                    mapTip.innerHTML = `<strong>${ esc( n.title || "#" + n.id ) }</strong>${ esc( n.inn === 0 ? __( "Nothing links here yet", "jnk-linkweave" ) : linksIn( n.inn ) ) }`;
                    const cr = canvas.getBoundingClientRect();
                    const hr = mapHost.getBoundingClientRect();
                    mapTip.style.left = ( cr.left - hr.left + n.x * k + tx ) + "px";
                    mapTip.style.top = ( cr.top - hr.top + ( n.y - n.r ) * k + ty ) + "px";
                    mapTip.hidden = false;
                } else {
                    mapTip.hidden = true;
                }
                draw();
            }
        } );
        const up = ( e ) => {
            pointers.delete( e.pointerId );
            if ( pointers.size < 2 ) {
                pinch = null;
            }
            if ( drag && ! drag.moved && e.type === "pointerup" ) {
                const p = pos( e );
                select( hit( p[ 0 ], p[ 1 ] ), false );
            }
            drag = null;
        };
        canvas.addEventListener( "pointerup", up );
        canvas.addEventListener( "pointercancel", up );
        canvas.addEventListener( "pointerleave", () => {
            hover = null;
            mapTip.hidden = true;
            draw();
        } );
        canvas.addEventListener( "wheel", ( e ) => {
            e.preventDefault();
            const p = pos( e );
            zoomAt( p[ 0 ], p[ 1 ], Math.exp( -e.deltaY * ( e.deltaMode === 1 ? 0.05 : 0.0015 ) ) );
        }, { passive: false } );
        canvas.addEventListener( "keydown", ( e ) => {
            const step = 60;
            const keys = {
                ArrowLeft: () => { tx += step; },
                ArrowRight: () => { tx -= step; },
                ArrowUp: () => { ty += step; },
                ArrowDown: () => { ty -= step; },
                "+": () => zoomAt( W / 2, H / 2, 1.3 ),
                "=": () => zoomAt( W / 2, H / 2, 1.3 ),
                "-": () => zoomAt( W / 2, H / 2, 1 / 1.3 ),
                0: () => fit(),
                Escape: () => select( null ),
            };
            if ( keys[ e.key ] ) {
                e.preventDefault();
                keys[ e.key ]();
                draw();
            }
        } );
        q( "in" ).addEventListener( "click", () => zoomAt( W / 2, H / 2, 1.4 ) );
        q( "outz" ).addEventListener( "click", () => zoomAt( W / 2, H / 2, 1 / 1.4 ) );
        q( "fit" ).addEventListener( "click", () => fit( nodes.filter( shown ) ) );
        const refilter = () => {
            fTopic = q( "topic" ).value;
            fType = q( "type" ) ? q( "type" ).value : "";
            fOrphans = q( "orphans" ).checked;
            if ( selected && ! shown( selected ) ) {
                select( null );
            }
            fit( updateCount() );
        };
        [ "topic", "type", "orphans" ].forEach( ( key ) => q( key ) && q( key ).addEventListener( "change", refilter ) );

        const find = q( "find" );
        const hits = wrap.querySelector( ".jnk-graph-hits" );
        find.addEventListener( "input", () => {
            const s = find.value.trim().toLowerCase();
            if ( s.length < 2 ) {
                hits.hidden = true;
                return;
            }
            const found = nodes.filter( ( n ) => n.title.toLowerCase().includes( s ) ).slice( 0, 8 );
            hits.innerHTML = found.length
                ? found.map( ( n ) => `<button type="button" class="button-link" data-pick="${ n.id }">${ esc( n.title ) }</button>` ).join( "" )
                : `<span class="jnkui-muted">${ esc( __( "No post with that title on the map.", "jnk-linkweave" ) ) }</span>`;
            hits.hidden = false;
        } );
        hits.addEventListener( "click", ( e ) => {
            const b = e.target.closest( "[data-pick]" );
            if ( b ) {
                const n = byId.get( +b.dataset.pick );
                if ( ! shown( n ) ) {
                    q( "topic" ).value = "";
                    if ( q( "type" ) ) {
                        q( "type" ).value = "";
                    }
                    q( "orphans" ).checked = false;
                    refilter();
                }
                select( n, true );
                hits.hidden = true;
            }
        } );

        if ( "ResizeObserver" in window ) {
            new ResizeObserver( () => resize() ).observe( canvas );
        } else {
            window.addEventListener( "resize", resize );
        }
        updateCount();
        resize();
        fit();

        return {
            show( on ) {
                wrap.hidden = ! on;
                visible = on;
                if ( on ) {
                    resize();
                }
            },
            // For tests and debugging.
            stats: () => ( { nodes: nodes.length, edges: edges.length, k } ),
        };
    }
} )();
