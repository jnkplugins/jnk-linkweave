( function () {
    "use strict";

    if ( typeof window.JnkLinkweaveAdmin === "undefined" ) {
        return;
    }

    const cfg = window.JnkLinkweaveAdmin;
    const { __, _n, sprintf } = wp.i18n;

    // Join a route path (which may carry its own query string, e.g.
    // "rules?per_page=100") onto cfg.restUrl. Under PLAIN permalinks restUrl is
    // already "…/index.php?rest_route=/jnk-linkweave/v1/", so a naive concat would
    // produce a second "?" and WP would treat "rules?per_page=100" as a literal
    // route (404 rest_no_route). Split off the query and re-append each param
    // with the correct separator regardless of permalink mode.
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

    function api( path, opts ) {
        opts = opts || {};
        return fetch( buildUrl( path ), {
            method: opts.method || "GET",
            credentials: "same-origin",
            headers: Object.assign(
                { "Content-Type": "application/json", "X-WP-Nonce": cfg.nonce },
                opts.headers || {}
            ),
            body: opts.body ? JSON.stringify( opts.body ) : undefined,
        } ).then( async ( res ) => {
            const data = await res.json().catch( () => ( {} ) );
            if ( ! res.ok ) {
                const err = new Error( data.message || data.error || res.statusText );
                // Callers that poll need the status and, after a 429, how long to wait.
                err.status     = res.status;
                err.retryAfter = Number( ( data.data && data.data.retry_after ) || res.headers.get( "Retry-After" ) || 0 );
                throw err;
            }
            return data;
        } );
    }

    // Rule flags arrive as booleans now, but older responses (and cached pages)
    // carried the DB strings "0"/"1" — and "0" is truthy, which pre-ticked every
    // off flag in the edit form. Read all spellings explicitly.
    function flag( v ) {
        return v === true || v === 1 || v === "1" || v === "true";
    }

    // Title cell with Edit / View links, so a report row can be acted on.
    function postCell( r ) {
        const title = escape( r.post_title || ( "#" + r.post_id ) );
        const main  = r.edit_url ? `<a href="${ escape( r.edit_url ) }"><strong>${ title }</strong></a>` : title;
        const acts  = [];
        if ( r.edit_url ) {
            acts.push( `<a href="${ escape( r.edit_url ) }">${ __( "Edit", "jnk-linkweave" ) }</a>` );
        }
        if ( r.view_url ) {
            acts.push( `<a href="${ escape( r.view_url ) }" target="_blank" rel="noopener">${ __( "View", "jnk-linkweave" ) }</a>` );
        }
        return main + ( acts.length ? `<div class="row-actions visible">${ acts.join( " | " ) }</div>` : "" );
    }

    function escape( s ) {
        return String( s == null ? "" : s )
            .replace( /&/g, "&amp;" )
            .replace( /</g, "&lt;" )
            .replace( />/g, "&gt;" )
            .replace( /"/g, "&quot;" );
    }

    // Friendly date/time + relative, e.g. "Jul 23, 2026 7:59 PM (13 days ago)",
    // in the admin's language (WordPress sets <html lang>). Bare MySQL datetimes
    // in these tables are stored as GMT, so treat tz-less values as UTC.
    const uiLang = document.documentElement.lang || "en-US";
    function relTime( ms ) {
        var past = ms < 0, s = Math.abs( ms ) / 1000;
        var units = [ [ "year", 31536000 ], [ "month", 2592000 ], [ "day", 86400 ], [ "hour", 3600 ], [ "minute", 60 ] ];
        for ( var i = 0; i < units.length; i++ ) {
            var v = Math.floor( s / units[ i ][ 1 ] );
            if ( v >= 1 ) {
                try {
                    return new Intl.RelativeTimeFormat( uiLang, { numeric: "always" } ).format( past ? -v : v, units[ i ][ 0 ] );
                } catch ( e ) {
                    return past ? v + " " + units[ i ][ 0 ] + "(s) ago" : "in " + v + " " + units[ i ][ 0 ] + "(s)";
                }
            }
        }
        return past ? __( "just now", "jnk-linkweave" ) : __( "in a moment", "jnk-linkweave" );
    }
    /** "%e0%a4%85..." reads as the Devanagari it is; anything that will not decode stays as it was. */
    function readableUrl( value ) {
        try {
            return decodeURI( String( value || "" ) );
        } catch ( e ) {
            return String( value || "" );
        }
    }
    function fmtDate( value ) {
        if ( ! value ) { return "—"; }
        var s = String( value ).trim().replace( " ", "T" );
        if ( /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test( s ) && ! /[zZ]|[+\-]\d{2}:?\d{2}$/.test( s ) ) {
            s += "Z";
        }
        var d = new Date( s );
        if ( isNaN( d.getTime() ) ) { return String( value ); }
        var abs;
        try {
            abs = d.toLocaleString( uiLang, { year: "numeric", month: "short", day: "numeric", hour: "numeric", minute: "2-digit" } );
        } catch ( e ) {
            abs = d.toLocaleString( "en-US" );
        }
        return abs + " (" + relTime( d.getTime() - Date.now() ) + ")";
    }

    /* ---------- Extension points (used by the Pro admin bundle) ----------
     * The premium build ships pro/assets/js/admin-pro.js, enqueued after this
     * script. To keep all premium behaviour out of the free wordpress.org build,
     * this file exposes its request helpers and a couple of generic seams instead
     * of carrying any Pro-gated code:
     *   - cfg.api / cfg.escape / cfg.fmtDate: reused so Pro never re-implements them.
     *   - cfg.settingsFilters: callbacks that may add fields to the settings body
     *     before it is saved (Pro appends its LLM / keyword-boost fields here).
     *   - DOM events "jnk_linkweave:broken-rendered" and
     *     "jnk_linkweave:settings-saved": let Pro augment the broken-links
     *     report (repair buttons) and react after a settings save.
     */
    cfg.api           = api;
    cfg.escape        = escape;
    cfg.fmtDate       = fmtDate;
    cfg.settingsFilters = cfg.settingsFilters || [];
    // Callbacks (body, form) that may add fields to a rule before it is saved, and
    // the "jnk_linkweave:rule-form" / "jnk_linkweave:rules-rendered" events, let an
    // extension add its own rule options (a [data-slot=rule-extra] box in each form).
    cfg.ruleFilters     = cfg.ruleFilters || [];
    function ruleBody( body, form ) {
        cfg.ruleFilters.forEach( function ( fn ) {
            try { fn( body, form ); } catch ( e ) {}
        } );
        return body;
    }

    /* ---------- Dashboard: rebuild button + polling ---------- */

    // States from /index/status: idle | queued | running | stalled | error.
    // "queued" means the job waits for WordPress's background queue, which only
    // starts from an admin page load or a real cron run. After RUN_NOW_AFTER ms
    // in that state the dashboard offers to do the work right here instead.
    const RUN_NOW_AFTER = 15000;
    const rebuildBtn    = document.getElementById( "jnk-linkweave-rebuild" );
    const runNowBtn     = document.getElementById( "jnk-linkweave-run-now" );
    const rebuildStatus = document.getElementById( "jnk-linkweave-rebuild-status" );
    const summaryEl     = document.getElementById( "jnk-linkweave-index-summary" );
    let queuedSince     = 0;
    let pollTimer       = 0;
    // Set once this page has watched a read start or run. When it finishes, the
    // page reloads: every tile, list and callout below the button was rendered
    // by PHP for the old state ("reading for the first time", 0 links, all red).
    let reloadWhenDone  = false;

    function showRunNow( show, label ) {
        if ( ! runNowBtn ) {
            return;
        }
        runNowBtn.style.display = show ? "" : "none";
        runNowBtn.disabled = false;
        if ( label ) {
            runNowBtn.textContent = label;
        }
    }

    function setLine( text, spin ) {
        if ( ! rebuildStatus ) {
            return;
        }
        rebuildStatus.innerHTML = ( spin ? '<span class="jnkui-spin"></span> ' : "" ) + escape( text );
    }

    function progressText( s ) {
        if ( s.state === "queued" ) {
            return __( "Queued, waiting for WordPress to start it", "jnk-linkweave" );
        }
        if ( s.phase === "writing" ) {
            /* translators: 1: posts saved so far, 2: total posts. */
            return sprintf( __( "Saving what it learned: %1$d of %2$d", "jnk-linkweave" ), s.indexed || 0, s.total || 0 );
        }
        /* translators: 1: posts read so far, 2: total posts. */
        return sprintf( __( "Reading posts %1$d of %2$d", "jnk-linkweave" ), s.read || 0, s.total || 0 );
    }

    function renderIndexStatus( s ) {
        if ( s.is_running ) {
            reloadWhenDone = true;
            if ( s.state === "queued" ) {
                queuedSince = queuedSince || Date.now();
                showRunNow( Date.now() - queuedSince >= RUN_NOW_AFTER, __( "Run now", "jnk-linkweave" ) );
            } else {
                queuedSince = 0;
                showRunNow( false );
            }
            if ( rebuildBtn ) {
                rebuildBtn.disabled = true;
            }
            setLine( progressText( s ), true );
            if ( summaryEl ) {
                summaryEl.textContent = progressText( s );
            }
            return true;
        }
        queuedSince = 0;
        if ( rebuildBtn ) {
            rebuildBtn.disabled = false;
        }
        if ( s.state === "stalled" || s.state === "error" ) {
            setLine( s.state === "stalled"
                ? __( "The last read stopped before it finished.", "jnk-linkweave" )
                : __( "The last read failed.", "jnk-linkweave" ) + ( s.error && s.error !== "stopped" ? " " + s.error : "" ) );
            showRunNow( true, __( "Run it again now", "jnk-linkweave" ) );
            return false;
        }
        showRunNow( false );
        setLine( cfg.i18n.complete + " (" + ( s.indexed || 0 ) + ")" );
        // The summary line above is rendered by PHP on page load, so without
        // this it kept insisting "0 posts indexed (last full rebuild: never)"
        // directly above a button reporting success, until you reloaded.
        if ( summaryEl && cfg.i18n.indexedJustNow ) {
            summaryEl.textContent = cfg.i18n.indexedJustNow.replace( "%d", s.indexed || 0 );
        }
        const tile = document.getElementById( "jnk-linkweave-index-count" );
        if ( tile ) {
            tile.textContent = String( s.indexed || 0 );
        }
        if ( reloadWhenDone ) {
            reloadWhenDone = false;
            setTimeout( function () {
                window.location.reload();
            }, 1200 );
        }
        return false;
    }

    function pollIndexStatus() {
        clearTimeout( pollTimer );
        api( "index/status" ).then( ( s ) => {
            if ( renderIndexStatus( s ) ) {
                pollTimer = setTimeout( pollIndexStatus, 2000 );
            }
        } ).catch( ( e ) => {
            setLine( e.message );
            if ( rebuildBtn ) {
                rebuildBtn.disabled = false;
            }
        } );
    }

    async function startRebuild( now ) {
        reloadWhenDone = true;
        if ( rebuildBtn ) {
            rebuildBtn.disabled = true;
        }
        showRunNow( false );
        clearTimeout( pollTimer );
        setLine( cfg.i18n.running, true );
        try {
            const res = await api( "index/rebuild" + ( now ? "?now=1" : "" ), { method: "POST" } );
            if ( res && res.status && renderIndexStatus( res.status ) ) {
                pollTimer = setTimeout( pollIndexStatus, 2000 );
            }
        } catch ( e ) {
            setLine( e.message );
            if ( rebuildBtn ) {
                rebuildBtn.disabled = false;
            }
            showRunNow( true, __( "Run it again now", "jnk-linkweave" ) );
        }
    }

    if ( rebuildBtn ) {
        rebuildBtn.addEventListener( "click", function () {
            startRebuild( false );
        } );
        // A read queued on activation, or one still running in the background:
        // pick it up on page load instead of waiting for a click.
        if ( rebuildBtn.dataset.state === "queued" || rebuildBtn.dataset.state === "running" ) {
            const since = Date.parse( String( rebuildBtn.dataset.queuedAt || "" ).replace( " ", "T" ) + "Z" );
            queuedSince = isNaN( since ) ? Date.now() : Math.min( Date.now(), since );
            reloadWhenDone = true;
            pollIndexStatus();
        } else if ( rebuildBtn.dataset.state === "stalled" || rebuildBtn.dataset.state === "error" ) {
            showRunNow( true, __( "Run it again now", "jnk-linkweave" ) );
        }
    }
    if ( runNowBtn ) {
        runNowBtn.addEventListener( "click", function () {
            startRebuild( true );
        } );
    }

    /* ---------- Rules CRUD ---------- */

    const rulesMount = document.querySelector( "[data-mount=rules]" );
    if ( rulesMount ) {
        renderRules();
    }

    // A visible confirmation after a change. Adding or saving a rule used to just
    // redraw the table, which read as if nothing had happened.
    function flash( anchor, text, type ) {
        if ( ! anchor || ! anchor.parentNode ) {
            return;
        }
        let box = anchor.parentNode.querySelector( ":scope > .jnk-linkweave-flash" );
        if ( ! box ) {
            box = document.createElement( "div" );
            box.className = "jnk-linkweave-flash";
            anchor.parentNode.insertBefore( box, anchor );
        }
        box.innerHTML = text
            ? `<div class="notice notice-${ type || "success" } inline" role="status"><p>${ escape( text ) }</p></div>`
            : "";
    }
    cfg.flash = flash;

    async function renderRules( message ) {
        rulesMount.innerHTML = '<div class="jnkui-empty"><span class="jnkui-spin"></span> ' + __( "Loading…", "jnk-linkweave" ) + "</div>";
        flash( rulesMount, message || "" );
        try {
            const data = await api( "rules?per_page=100" );
            const rows = ( data.rules || [] ).map( ( r ) => `
                <tr data-id="${ escape( r.id ) }">
                    <td><b>${ escape( r.keyword ) }</b>${ flag( r.is_generated ) ? ` <span class="jnkui-pill is-info" title="${ escape( __( "Created by the link map. Clearing the map removes it; rules you wrote are never touched.", "jnk-linkweave" ) ) }">${ __( "from link map", "jnk-linkweave" ) }</span>` : "" }${ flag( r.paused_needs_pro ) ? `<div class="jnkui-muted" style="margin-top:4px">${ __( "This rule uses Pro options and is paused on the free version.", "jnk-linkweave" ) }</div>` : "" }</td>
                    <td><a href="${ escape( r.target_url ) }" target="_blank" rel="noopener">${ escape( r.target_url.replace( /^https?:\/\/[^/]+/, "" ) || r.target_url ) }</a></td>
                    <td>${ flag( r.paused_needs_pro ) ? `<span class="jnkui-pill is-warn" title="${ escape( __( "Its settings are kept. It runs again when the Pro version is back.", "jnk-linkweave" ) ) }">${ __( "Paused", "jnk-linkweave" ) }</span>` : flag( r.is_active ) ? `<span class="jnkui-pill is-ok">${ __( "On", "jnk-linkweave" ) }</span>` : `<span class="jnkui-pill">${ __( "Paused", "jnk-linkweave" ) }</span>` }</td>
                    <td class="is-num">${ flag( r.paused_needs_pro ) ? 0 : ( r.applied_count || 0 ) }</td>
                    <td style="text-align:right;white-space:nowrap"><button class="button button-small" data-action="edit-rule">${ __( "Edit", "jnk-linkweave" ) }</button> <button class="button button-small is-danger" data-action="delete-rule">${ __( "Delete", "jnk-linkweave" ) }</button></td>
                </tr>` ).join( "" );
            rulesMount.innerHTML = `
                <div class="jnkui-grid-2" style="grid-template-columns:1.7fr 1fr;align-items:start">
                <div class="jnkui-table-wrap"><table class="jnk-linkweave-table">
                    <thead><tr><th>${ __( "Phrase", "jnk-linkweave" ) }</th><th>${ __( "Links to", "jnk-linkweave" ) }</th><th>${ __( "Status", "jnk-linkweave" ) }</th><th class="is-num"><span title="${ escape( __( "Links this rule adds across your published posts right now. Mentions already inside a link, a heading or code, and mentions on the target page itself, are skipped, so 0 can be correct.", "jnk-linkweave" ) ) }">${ __( "Links added", "jnk-linkweave" ) }</span></th><th></th></tr></thead>
                    <tbody>${ rows || `<tr><td colspan="5"><div class="jnkui-empty" style="border:0"><h3>${ __( "No rules yet", "jnk-linkweave" ) }</h3><p>${ __( "A rule links one phrase to one page everywhere it appears. Add your first one on the right.", "jnk-linkweave" ) }</p></div></td></tr>` }</tbody>
                </table></div>
                <div class="jnkui-card"><div class="jnkui-card-head"><div><h2>${ __( "Add a rule", "jnk-linkweave" ) }</h2><p>${ __( "Linkweave links this phrase wherever it appears in your posts.", "jnk-linkweave" ) }</p></div></div>
                <form id="jnk-linkweave-add-rule" class="jnkui-card-body" onsubmit="return false;" style="display:grid;gap:14px">
                    <label class="jnkui-field"><span>${ __( "Phrase", "jnk-linkweave" ) }</span><input type="text" name="keyword" placeholder="${ escape( __( "e.g. internal linking", "jnk-linkweave" ) ) }" required></label>
                    <label class="jnkui-field"><span>${ __( "Links to", "jnk-linkweave" ) }</span><input type="url" name="target_url" placeholder="https://…" required></label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px 16px">
                        <label><input type="checkbox" name="is_active" value="1" checked> ${ __( "On", "jnk-linkweave" ) }</label>
                        <label><input type="checkbox" name="case_sensitive" value="1"> ${ __( "Match capital letters", "jnk-linkweave" ) }</label>
                        <label><input type="checkbox" name="open_in_new_tab" value="1"> ${ __( "Open in a new tab", "jnk-linkweave" ) }</label>
                        <label title="${ escape( __( "Tells search engines not to pass ranking credit through this link", "jnk-linkweave" ) ) }"><input type="checkbox" name="nofollow" value="1"> nofollow</label>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <label class="jnkui-field"><span>${ __( "Most per post", "jnk-linkweave" ) }</span><input type="number" name="max_per_post" value="1" min="1"></label>
                        <label class="jnkui-field"><span>${ __( "Most across the site", "jnk-linkweave" ) }</span><input type="number" name="max_per_site" value="0" min="0" title="${ escape( __( "0 means no limit", "jnk-linkweave" ) ) }"></label>
                    </div>
                    <div data-slot="rule-extra"></div>
                    <div><button class="button button-primary" data-action="create-rule">${ __( "Add rule", "jnk-linkweave" ) }</button></div>
                </form></div>
                </div>`;

            rulesMount.querySelectorAll( "[data-action=delete-rule]" ).forEach( ( btn ) => {
                btn.addEventListener( "click", async function () {
                    const id = this.closest( "tr" ).dataset.id;
                    if ( ! confirm( __( "Delete this rule?", "jnk-linkweave" ) ) ) {
                        return;
                    }
                    await api( "rules/" + id, { method: "DELETE" } );
                    renderRules( __( "Rule deleted.", "jnk-linkweave" ) );
                } );
            } );

            rulesMount.querySelectorAll( "[data-action=edit-rule]" ).forEach( ( btn ) => {
                btn.addEventListener( "click", function () {
                    const tr = this.closest( "tr" );
                    const id = tr.dataset.id;
                    const r  = ( data.rules || [] ).find( ( x ) => String( x.id ) === String( id ) );
                    if ( ! r ) {
                        return;
                    }
                    tr.innerHTML = `
                        <td colspan="5">
                            <form class="jnk-linkweave-edit-rule" onsubmit="return false;">
                                <p>
                                    <input type="text" name="keyword" value="${ escape( r.keyword ) }" required>
                                    <input type="url" name="target_url" value="${ escape( r.target_url ) }" class="regular-text" required>
                                </p>
                                <p>
                                    <label><input type="checkbox" name="case_sensitive" ${ flag( r.case_sensitive ) ? "checked" : "" }> ${ __( "Match capital letters", "jnk-linkweave" ) }</label>
                                    <label style="margin-left:12px;"><input type="checkbox" name="open_in_new_tab" ${ flag( r.open_in_new_tab ) ? "checked" : "" }> ${ __( "Open in a new tab", "jnk-linkweave" ) }</label>
                                    <label style="margin-left:12px;"><input type="checkbox" name="nofollow" ${ flag( r.nofollow ) ? "checked" : "" }> nofollow</label>
                                    <label style="margin-left:12px;"><input type="checkbox" name="is_active" ${ flag( r.is_active ) ? "checked" : "" }> ${ __( "On", "jnk-linkweave" ) }</label>
                                </p>
                                <p>
                                    ${ __( "Most per post", "jnk-linkweave" ) } <input type="number" name="max_per_post" value="${ parseInt( r.max_per_post || 1, 10 ) }" min="1" style="width:60px;">
                                    ${ __( "Most across the site", "jnk-linkweave" ) } <input type="number" name="max_per_site" value="${ parseInt( r.max_per_site || 0, 10 ) }" min="0" style="width:80px;"> (${ __( "0 means no limit", "jnk-linkweave" ) })
                                </p>
                                <div data-slot="rule-extra"></div>
                                <p>
                                    <button class="button button-primary" data-action="save-rule">${ __( "Save", "jnk-linkweave" ) }</button>
                                    <button class="button" data-action="cancel-rule">${ __( "Cancel", "jnk-linkweave" ) }</button>
                                </p>
                            </form>
                        </td>`;
                    const form = tr.querySelector( "form.jnk-linkweave-edit-rule" );
                    document.dispatchEvent( new CustomEvent( "jnk_linkweave:rule-form", { detail: { form: form, rule: r } } ) );
                    form.querySelector( "[data-action=cancel-rule]" ).addEventListener( "click", function () { renderRules(); } );
                    form.querySelector( "[data-action=save-rule]" ).addEventListener( "click", async function () {
                        const body = {
                            keyword: form.keyword.value,
                            target_url: form.target_url.value,
                            case_sensitive: form.case_sensitive.checked,
                            open_in_new_tab: form.open_in_new_tab.checked,
                            nofollow: form.nofollow.checked,
                            is_active: form.is_active.checked,
                            max_per_post: parseInt( form.max_per_post.value, 10 ),
                            max_per_site: parseInt( form.max_per_site.value, 10 ),
                        };
                        ruleBody( body, form );
                        try {
                            await api( "rules/" + id, { method: "PUT", body } );
                            renderRules( __( "Rule saved.", "jnk-linkweave" ) );
                        } catch ( e ) {
                            alert( e.message );
                        }
                    } );
                } );
            } );

            document.dispatchEvent( new CustomEvent( "jnk_linkweave:rule-form", { detail: { form: document.getElementById( "jnk-linkweave-add-rule" ), rule: null } } ) );
            document.dispatchEvent( new CustomEvent( "jnk_linkweave:rules-rendered", { detail: { mount: rulesMount, rules: data.rules || [], rerender: renderRules } } ) );

            rulesMount.querySelector( "[data-action=create-rule]" ).addEventListener( "click", async function () {
                const f = document.getElementById( "jnk-linkweave-add-rule" );
                const body = {
                    keyword: f.keyword.value,
                    target_url: f.target_url.value,
                    case_sensitive: f.case_sensitive.checked,
                    open_in_new_tab: f.open_in_new_tab.checked,
                    nofollow: f.nofollow.checked,
                    is_active: f.is_active.checked,
                    max_per_post: parseInt( f.max_per_post.value, 10 ),
                    max_per_site: parseInt( f.max_per_site.value, 10 ),
                };
                ruleBody( body, f );
                try {
                    await api( "rules", { method: "POST", body } );
                    renderRules( __( "Rule added.", "jnk-linkweave" ) );
                } catch ( e ) {
                    alert( e.message );
                }
            } );
        } catch ( e ) {
            rulesMount.innerHTML = '<p class="jnk-linkweave-empty">' + escape( e.message ) + "</p>";
        }
    }

    /* ---------- Orphans: posts nothing links to, each with "who should link here" ---------- */

    const orphansMount = document.querySelector( '[data-mount="orphans"]' );
    if ( orphansMount ) {
        orphansMount.innerHTML = '<div class="jnkui-empty"><span class="jnkui-spin"></span> ' + __( "Loading…", "jnk-linkweave" ) + "</div>";
        api( "reports/orphans" ).then( ( data ) => {
            const items = data.orphans || [];
            if ( ! items.length ) {
                // "Nothing to show" is ambiguous here: it means either a healthy
                // site or an index that was never built. Say which, and link the fix.
                orphansMount.innerHTML = '<div class="jnkui-empty"><div class="jnkui-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>'
                    + "<h3>" + __( "No orphan posts", "jnk-linkweave" ) + "</h3><p>"
                    + sprintf(
                        /* translators: 1: opening link tag to the Overview screen, 2: closing link tag. */
                        __( "Every published post has at least one internal link pointing at it. If you have not built the index yet, %1$sdo that on the Overview%2$s first.", "jnk-linkweave" ),
                        '<a href="admin.php?page=jnk-linkweave">',
                        "</a>"
                    ) + "</p></div>";
                return;
            }
            orphansMount.innerHTML = '<div class="jnkui-card"><div class="jnkui-card-head"><div><h2>'
                /* translators: %d: number of posts with no links pointing at them. */
                + escape( sprintf( _n( "%d post needs a link", "%d posts need a link", items.length, "jnk-linkweave" ), items.length ) ) + "</h2>"
                + "<p>" + __( "Linkweave compares each one with the rest of your site and lists the posts that talk about the same thing.", "jnk-linkweave" ) + "</p></div></div>"
                + '<ul class="jnkui-list jnk-orphans">' + items.map( ( r ) =>
                    `<li data-post="${ escape( r.post_id ) }"><div class="jnk-orphan-main"><span>`
                    + ( r.edit_url ? `<a href="${ escape( r.edit_url ) }">${ escape( r.post_title || ( "#" + r.post_id ) ) }</a>` : escape( r.post_title ) )
                    + `<span class="jnkui-sub">${ escape( r.post_type ) }${ r.view_url ? ` · <a href="${ escape( r.view_url ) }" target="_blank" rel="noopener">${ __( "View", "jnk-linkweave" ) }</a>` : "" }</span></span>`
                    + '<span class="jnk-orphan-actions">'
                    + ( r.indexed ? "" : `<span class="jnkui-pill is-warn" title="${ escape( __( "Shorter than the minimum length in Settings, so it is never suggested as a link target.", "jnk-linkweave" ) ) }">${ __( "Too short to suggest", "jnk-linkweave" ) }</span>` )
                    + `<button type="button" class="button button-small" data-action="find" aria-expanded="false">${ __( "Find posts that could link here", "jnk-linkweave" ) }</button></span></div>`
                    + '<div class="jnk-orphan-found" hidden></div></li>'
                ).join( "" ) + "</ul></div>";
        } ).catch( ( e ) => {
            orphansMount.innerHTML = '<div class="jnkui-empty">' + escape( e.message ) + "</div>";
        } );

        orphansMount.addEventListener( "click", async function ( ev ) {
            const btn = ev.target.closest( "[data-action]" );
            if ( ! btn ) { return; }
            const li  = btn.closest( "li[data-post]" );
            const box = li && li.querySelector( ".jnk-orphan-found" );
            const postId = li ? parseInt( li.getAttribute( "data-post" ), 10 ) : 0;

            if ( btn.dataset.action === "find" ) {
                const open = btn.getAttribute( "aria-expanded" ) === "true";
                btn.setAttribute( "aria-expanded", open ? "false" : "true" );
                btn.textContent = open ? __( "Find posts that could link here", "jnk-linkweave" ) : __( "Hide", "jnk-linkweave" );
                box.hidden = open;
                if ( open || box.dataset.loaded ) { return; }
                box.innerHTML = '<p class="jnkui-muted"><span class="jnkui-spin"></span> ' + __( "Looking through your posts…", "jnk-linkweave" ) + "</p>";
                try {
                    const res  = await api( "reports/inbound-opportunities/" + postId + "?limit=6" );
                    const opps = res.opportunities || [];
                    box.dataset.loaded = "1";
                    if ( ! opps.length ) {
                        box.innerHTML = '<p class="jnkui-muted">' + __( "No close matches yet. Write a related post and link it here, or add an auto-link rule for this page.", "jnk-linkweave" ) + "</p>";
                        return;
                    }
                    box.innerHTML = '<div class="jnk-opps">' + opps.map( ( o, i ) => {
                        const pct = Math.round( Math.max( 0, Math.min( 1, o.score || 0 ) ) * 100 );
                        return `<label class="jnk-opp" data-source="${ escape( o.target_id ) }" data-rank="${ i }" data-score="${ escape( o.score || 0 ) }">`
                            + `<span class="jnk-opp-body"><b>${ escape( o.title ) }</b>`
                            + `<span class="jnk-opp-terms">${ ( o.matched_terms || [] ).map( ( t ) => `<span class="jnkui-pill">${ escape( t ) }</span>` ).join( "" ) }</span></span>`
                            + `<span class="jnk-opp-score" title="${ escape( __( "How closely the two posts match", "jnk-linkweave" ) ) }"><span class="jnkui-bar"><i style="width:${ pct }%"></i></span> <span class="jnkui-muted">${ pct }%</span></span>`
                            + ( o.edit_url ? `<a class="button button-small" href="${ escape( o.edit_url ) }">${ __( "Open", "jnk-linkweave" ) }</a>` : "" )
                            + `</label>`;
                    } ).join( "" ) + "</div>"
                        + '<p class="jnkui-muted jnk-opps-foot">' + __( "Open a post and add the link in the editor.", "jnk-linkweave" )
                        + ( cfg.isPro ? "" : ' <a href="admin.php?page=jnk-linkweave-upgrade&src=orphans">' + __( "Pro adds the links for you", "jnk-linkweave" ) + "</a>." ) + "</p>";
                    // Pro adds "tick and add links in one click" to this box.
                    document.dispatchEvent( new CustomEvent( "jnk_linkweave:orphan-opportunities", { detail: { box: box, postId: postId, li: li } } ) );
                } catch ( e ) {
                    box.innerHTML = '<p class="jnkui-muted">' + escape( e.message ) + "</p>";
                }
            }

        } );
    }

    /* ---------- Inbound links: a ranked bar list with a filter ---------- */

    const inboundMount = document.querySelector( '[data-mount="inbound-counts"]' );
    if ( inboundMount ) {
        inboundMount.innerHTML = '<div class="jnkui-empty"><span class="jnkui-spin"></span> ' + __( "Loading…", "jnk-linkweave" ) + "</div>";
        api( "reports/inbound-counts" ).then( ( data ) => {
            const items = data.counts || [];
            if ( ! items.length ) {
                inboundMount.innerHTML = '<div class="jnkui-empty"><h3>' + __( "Nothing to show yet", "jnk-linkweave" ) + "</h3><p>" + __( "Build the index on the Overview and the counts appear here.", "jnk-linkweave" ) + "</p></div>";
                return;
            }
            const max = Math.max( 1, ...items.map( ( r ) => Number( r.inbound || 0 ) ) );
            const zero = items.filter( ( r ) => Number( r.inbound ) === 0 ).length;
            const row = ( r ) => {
                const v = Number( r.inbound || 0 );
                const tone = v === 0 ? "is-bad" : ( v < 3 ? "is-warn" : "is-ok" );
                return `<li data-title="${ escape( ( r.post_title || "" ).toLowerCase() ) }"><span class="jnk-hbar-label">`
                    + ( r.edit_url ? `<a href="${ escape( r.edit_url ) }">${ escape( r.post_title || ( "#" + r.post_id ) ) }</a>` : escape( r.post_title ) )
                    + ( r.view_url ? `<span class="jnkui-sub"><a href="${ escape( r.view_url ) }" target="_blank" rel="noopener">${ __( "View", "jnk-linkweave" ) }</a></span>` : "" )
                    + `</span><span class="jnk-hbar"><span class="jnkui-bar"><i style="width:${ Math.max( 2, Math.round( v / max * 100 ) ) }%"></i></span>`
                    /* translators: %d: number of internal links pointing at a post. */
                    + `<span class="jnkui-pill ${ tone }">${ escape( sprintf( _n( "%d in", "%d in", v, "jnk-linkweave" ), v ) ) }</span></span></li>`;
            };
            inboundMount.innerHTML = '<div class="jnkui-card"><div class="jnkui-card-head"><div><h2>'
                /* translators: %d: number of posts. */
                + escape( sprintf( _n( "%d post", "%d posts", items.length, "jnk-linkweave" ), items.length ) ) + "</h2><p>"
                /* translators: %d: number of posts with no internal links pointing at them. */
                + escape( zero ? sprintf( _n( "%d with no links in, shown in red.", "%d with no links in, shown in red.", zero, "jnk-linkweave" ), zero ) : __( "Every post has at least one link in.", "jnk-linkweave" ) ) + "</p></div>"
                + '<input type="search" placeholder="' + escape( __( "Filter by title…", "jnk-linkweave" ) ) + '" aria-label="' + escape( __( "Filter by title", "jnk-linkweave" ) ) + '" data-role="filter" style="min-width:240px"></div>'
                + '<ul class="jnkui-list jnk-hbars">' + items.map( row ).join( "" ) + "</ul></div>";
            inboundMount.querySelector( "[data-role=filter]" ).addEventListener( "input", function () {
                const q = this.value.trim().toLowerCase();
                inboundMount.querySelectorAll( "li[data-title]" ).forEach( ( li ) => {
                    li.hidden = q !== "" && li.getAttribute( "data-title" ).indexOf( q ) === -1;
                } );
            } );
        } ).catch( ( e ) => {
            inboundMount.innerHTML = '<div class="jnkui-empty">' + escape( e.message ) + "</div>";
        } );
    }

    /* ---------- Broken links report ---------- */

    const brokenMount = document.querySelector( '[data-mount="broken"]' );
    if ( brokenMount ) {
        // "Check all links now": the scan runs in short steps. While this page is
        // open it drives the steps itself and shows how far it got; a background
        // job carries on if the page is closed. It used to fire one background job
        // and poll a flag that expired, so a scan that died looked finished.
        const scanBtn    = document.getElementById( "jnk-linkweave-scan-now" );
        const scanStatus = document.getElementById( "jnk-linkweave-scan-status" );
        let driving      = false;
        let following    = false;
        const sleep      = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
        // Requests in a row that may fail before the page stops driving the scan.
        const MAX_STEP_ERRORS = 3;
        // How often the page looks at a scan it no longer drives (read-only GET).
        const FOLLOW_MS = 15000;
        const showProgress = ( scan ) => {
            const total = Number( scan.total || 0 );
            const done  = Number( scan.checked || 0 );
            scanStatus.innerHTML = '<span class="jnkui-spin"></span> ' + escape( total
                /* translators: 1: links checked so far, 2: links in total. */
                ? sprintf( __( "Checking links: %1$d of %2$d…", "jnk-linkweave" ), done, total )
                : __( "Collecting your links…", "jnk-linkweave" ) )
                + ( total ? ' <span class="jnkui-bar" style="display:inline-block;width:120px;vertical-align:middle"><i style="width:' + Math.round( done / total * 100 ) + '%"></i></span>' : "" );
        };
        // Why a scan ended, for the line next to the button and the summary.
        const stoppedText = ( scan ) => {
            const n = Number( scan.failed_steps || 0 );
            return scan.stop_reason
                /* translators: 1: number of failed attempts, 2: the error the host reported. */
                ? sprintf( __( "The check stopped: a step failed %1$d times in a row (%2$s). Click “Check all links now” to start again.", "jnk-linkweave" ), n, scan.stop_reason )
                /* translators: %d: number of failed attempts. */
                : sprintf( __( "The check stopped: a step failed %d times in a row. Click “Check all links now” to start again.", "jnk-linkweave" ), n );
        };
        const showEnd = ( scan ) => {
            const last = scan.last_scan || {};
            if ( scan.stopped ) {
                scanStatus.textContent = stoppedText( scan );
            } else if ( ! scan.stalled && last.finished_at ) {
                /* translators: 1: links checked, 2: links to missing pages, 3: links that did not answer. */
                scanStatus.textContent = sprintf( __( "Done: %1$d links checked, %2$d to missing pages, %3$d did not answer.", "jnk-linkweave" ), Number( last.checked || 0 ), Number( last.broken || 0 ), Number( last.unreachable || 0 ) );
            } else {
                scanStatus.textContent = __( "The check stopped before it finished. Click the button to run it again.", "jnk-linkweave" );
            }
        };
        // Watch a scan the page does not drive (the background job runs it) with
        // the cheap GET, which never counts toward the rate limit, until it ends.
        const followScan = async () => {
            if ( following ) { return; }
            following = true;
            let lastChecked = -1;
            let still       = 0;
            for ( ;; ) {
                await sleep( FOLLOW_MS );
                if ( ! following ) {
                    return; // the button started driving the scan again
                }
                let scan;
                try {
                    scan = ( await api( "reports/broken-links" ) ).scan || {};
                } catch ( e ) {
                    continue; // try again at the next look
                }
                if ( ! scan.running ) {
                    showEnd( scan );
                    break;
                }
                showProgress( scan );
                // No background job is moving it (WP-Cron off, a queue that does
                // not run): after two looks with no progress, drive it again.
                const checked = Number( scan.checked || 0 );
                still       = checked === lastChecked ? still + 1 : 0;
                lastChecked = checked;
                if ( still >= 2 ) {
                    following = false;
                    driveScan();
                    return;
                }
            }
            following = false;
            renderBroken();
        };
        const driveScan = async () => {
            if ( driving || following ) { return; }
            driving = true;
            if ( scanBtn ) { scanBtn.disabled = true; }
            let errors   = 0;
            let finished = false;
            for ( ;; ) {
                let res;
                try {
                    res    = await api( "reports/broken-links/scan/step", { method: "POST" } );
                    errors = 0;
                } catch ( e ) {
                    // Retrying every error at once hammered the site in a tight loop
                    // and kept it rate-limited (QA 27 Oct 2026). Wait as long as the
                    // server asks, back off, and give up driving after a few in a row.
                    errors++;
                    if ( errors >= MAX_STEP_ERRORS ) {
                        scanStatus.textContent = sprintf(
                            /* translators: %s: the error message from the site. */
                            __( "This page could not go on with the check (%s). It carries on in the background; this line updates when it is done.", "jnk-linkweave" ),
                            e.message
                        );
                        break;
                    }
                    await sleep( e.retryAfter > 0 ? e.retryAfter * 1000 : 5000 * errors );
                    continue;
                }
                const scan = res.scan || {};
                if ( ! scan.running ) {
                    showEnd( scan );
                    finished = true;
                    break;
                }
                showProgress( scan );
                if ( scan.busy ) {
                    // A background step is running; wait for it rather than race it.
                    await sleep( 5000 );
                }
            }
            driving = false;
            if ( scanBtn ) { scanBtn.disabled = false; }
            if ( finished ) {
                renderBroken();
            } else {
                followScan();
            }
        };
        if ( scanBtn ) {
            scanBtn.addEventListener( "click", async function () {
                scanBtn.disabled = true;
                scanStatus.innerHTML = '<span class="jnkui-spin"></span> ' + __( "Collecting your links…", "jnk-linkweave" );
                try {
                    await api( "reports/broken-links/scan", { method: "POST" } );
                    following = false;
                    driveScan();
                } catch ( e ) {
                    scanBtn.disabled = false;
                    scanStatus.textContent = e.message;
                }
            } );
        }

        // "Last full check: …, N links checked, M never checked", always on top,
        // so an old result is never mistaken for a fresh one.
        const scanSummary = ( scan ) => {
            const last  = scan.last_scan || {};
            const parts = [];
            if ( last.finished_at ) {
                /* translators: 1: date and time, 2: number of links checked. */
                parts.push( sprintf( __( "Last full check: %1$s, %2$d links checked.", "jnk-linkweave" ), fmtDate( last.finished_at ), Number( last.checked || 0 ) ) );
            } else {
                parts.push( __( "No full check has finished yet.", "jnk-linkweave" ) );
            }
            if ( Number( scan.never_checked || 0 ) > 0 ) {
                /* translators: %d: number of links never checked. */
                parts.push( sprintf( _n( "%d link has never been checked.", "%d links have never been checked.", Number( scan.never_checked ), "jnk-linkweave" ), Number( scan.never_checked ) ) );
            }
            if ( scan.stopped ) {
                parts.push( stoppedText( scan ) );
            } else if ( scan.stalled ) {
                parts.push( __( "The last check stopped part way. Click “Check all links now” to finish it.", "jnk-linkweave" ) );
            }
            return '<p class="jnkui-muted jnk-linkweave-scan-summary">' + escape( parts.join( " " ) ) + "</p>";
        };

        const renderBroken = () => {
            brokenMount.innerHTML = '<div class="jnkui-empty"><span class="jnkui-spin"></span> ' + __( "Loading…", "jnk-linkweave" ) + "</div>";
            api( "reports/broken-links" ).then( ( data ) => {
                const items   = data.broken || [];
                const summary = scanSummary( data.scan || { last_scan: data.last_scan } );
                if ( data.scan && data.scan.running && ! driving && ! following ) {
                    driveScan(); // a scan is under way: show it and help it along
                }
                if ( ! items.length ) {
                    // "Nothing to show." read identically whether every link was
                    // healthy or the scan had never run once. Say which it is.
                    const scan = data.last_scan || {};
                    brokenMount.innerHTML = scan.finished_at
                        ? '<div class="jnkui-empty"><div class="jnkui-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>'
                            + "<h3>" + __( "No broken links", "jnk-linkweave" ) + "</h3><p>"
                            + escape( scan.checked
                                /* translators: 1: date and time of the last check, 2: number of links checked. */
                                ? sprintf( _n( "Last checked %1$s, %2$d link in total.", "Last checked %1$s, %2$d links in total.", Number( scan.checked ), "jnk-linkweave" ), fmtDate( scan.finished_at ), Number( scan.checked ) )
                                /* translators: %s: date and time of the last check. */
                                : sprintf( __( "Last checked %s.", "jnk-linkweave" ), fmtDate( scan.finished_at ) ) ) + "</p></div>"
                        : '<div class="jnkui-empty"><h3>' + __( "Not checked yet", "jnk-linkweave" ) + "</h3><p>" + __( "Linkweave checks your links once a day. Click “Check all links now” to do it straight away.", "jnk-linkweave" ) + "</p></div>";
                    brokenMount.insertAdjacentHTML( "afterbegin", summary );
                    return;
                }
                // Show titles, not raw post IDs — "429 → 440" told you nothing about
                // which article to go and fix. The target falls back to the dead URL
                // when the post it pointed at no longer exists.
                const rows = items.map( ( r ) => {
                    const source = r.source_edit
                        ? `<a href="${ escape( r.source_edit ) }">${ escape( r.source_title ) }</a>`
                        : escape( r.source_title );
                    return `<tr data-broken-id="${ escape( r.id ) }">`
                        + `<td>${ source }</td>`
                        + `<td class="jnk-linkweave-target">${ escape( readableUrl( r.target_label ) ) }</td>`
                        + `<td>${ escape( r.anchor_text ) }</td>`
                        + ( Number( r.is_broken ) === 2
                            ? `<td><span class="jnk-linkweave-pill is-unreachable" title="${ escape( __( "No answer in time, or the server returned an error. The page may be fine: open it to check. Remove all leaves these links alone.", "jnk-linkweave" ) ) }">${ __( "Did not answer", "jnk-linkweave" ) }</span></td>`
                            : `<td><span class="jnk-linkweave-pill is-broken">${ __( "Page missing", "jnk-linkweave" ) }</span></td>` )
                        + `<td style="white-space:nowrap">${ escape( fmtDate( r.last_checked ) ) }</td>`
                        + `</tr>`;
                } ).join( "" );
                brokenMount.innerHTML = summary +
                    '<table class="jnk-linkweave-table"><thead><tr><th>' + __( "Found in", "jnk-linkweave" ) + "</th><th>" + __( "Points at", "jnk-linkweave" ) + "</th><th>" + __( "Link text", "jnk-linkweave" ) + "</th><th>" + __( "Status", "jnk-linkweave" ) + "</th><th>" + __( "Checked", "jnk-linkweave" ) + "</th></tr></thead><tbody>" + rows + "</tbody></table>";

                // Let the Pro bundle (if present) add one-click repair controls.
                document.dispatchEvent( new CustomEvent( "jnk_linkweave:broken-rendered", {
                    detail: { mount: brokenMount, items: items, rerender: renderBroken },
                } ) );
            } ).catch( ( e ) => {
                brokenMount.innerHTML = '<p class="jnk-linkweave-empty">' + escape( e.message ) + "</p>";
            } );
        };
        renderBroken();
    }

    /* ---------- Settings save ---------- */

    const settingsForm = document.getElementById( "jnk-linkweave-settings-form" );
    if ( settingsForm ) {
        const status = document.getElementById( "jnk-linkweave-settings-status" );
        settingsForm.querySelector( '[data-action="save-settings"]' ).addEventListener( "click", async function () {
            const body = {
                min_post_length: parseInt( settingsForm.min_post_length.value, 10 ),
                auto_index_on_save: settingsForm.auto_index_on_save.checked,
                delete_data_on_uninstall: settingsForm.delete_data_on_uninstall.checked,
                post_types: Array.prototype.map.call(
                    settingsForm.querySelectorAll( 'input[name="post_types[]"]:checked' ),
                    function ( el ) { return el.value; }
                ),
            };
            if ( settingsForm.ranking ) {
                body.ranking = settingsForm.ranking.value;
            }
            if ( settingsForm.suggestions_per_post ) {
                body.suggestions_per_post = parseInt( settingsForm.suggestions_per_post.value, 10 );
            }
            if ( settingsForm.min_relevance_score ) {
                body.min_relevance_score = parseFloat( settingsForm.min_relevance_score.value );
            }
            if ( settingsForm.stopwords_custom ) {
                body.stopwords_custom = settingsForm.stopwords_custom.value.split( /[,\n]+/ ).map( ( w ) => w.trim() ).filter( Boolean );
            }
            // Premium builds add their own fields (LLM re-ranking, focus-keyword
            // boost) to the payload via registered filters — see admin-pro.js.
            cfg.settingsFilters.forEach( function ( fn ) {
                try { fn( body, settingsForm ); } catch ( e ) {}
            } );
            status.textContent = __( "Saving…", "jnk-linkweave" );
            flash( settingsForm, "" );
            try {
                const saved = await api( "settings", { method: "POST", body } );
                status.textContent = __( "Settings saved.", "jnk-linkweave" );
                flash(
                    settingsForm,
                    __( "Settings saved.", "jnk-linkweave" ) + ( saved && saved.rebuild_queued ? " " + __( "The index is being rebuilt in the background so the change reaches every post.", "jnk-linkweave" ) : "" )
                );
                // `response` and `status` let an extension add a warning about what
                // was saved (e.g. a mode that needs a field left empty).
                document.dispatchEvent( new CustomEvent( "jnk_linkweave:settings-saved", {
                    detail: { form: settingsForm, response: saved, status: status },
                } ) );
            } catch ( e ) {
                status.textContent = e.message;
                flash( settingsForm, e.message, "error" );
            }
        } );
    }
} )();
