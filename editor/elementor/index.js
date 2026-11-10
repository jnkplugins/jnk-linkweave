/**
 * JnK Linkweave: link ideas inside the Elementor editor (free).
 *
 * Page Settings has a "Linkweave link ideas" section (a RAW_HTML control with an
 * empty .jnk-lw-el-ideas box). Elementor draws that panel only when it is opened,
 * so watch for the box and fill it from the same REST route the block editor
 * sidebar uses.
 *
 * Each idea has:
 *   - Insert link: puts the link on the first words from the idea's title that a
 *     text widget already has (Text Editor, Elementor 4 Paragraph, Tabs /
 *     Accordion / Toggle items and the others listed in widgets.js), through Elementor's own editor command. Elementor's History
 *     panel and undo cover it, and nothing is saved until the user clicks
 *     Update. Nothing here writes to the page from the server.
 *   - Copy link: for adding the link by hand with Elementor's own link box.
 */
import { __, sprintf } from '@wordpress/i18n';
import { linkFirstWords } from './link-words';
import { titlePhrases } from '../src/phrases';
import { textSpots } from './widgets';

( function () {
	const cfg = window.JnkLinkweaveElementor;
	if ( ! cfg ) {
		return;
	}
	const cache = {};
	const ideas = new WeakMap();

	function esc( v ) {
		return String( v === null || v === undefined ? '' : v ).replace(
			/[&<>"']/g,
			( c ) =>
				( {
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#39;',
				} )[ c ]
		);
	}

	function readable( url ) {
		try {
			return decodeURI( url );
		} catch ( e ) {
			return url;
		}
	}

	function canInsert() {
		return !! (
			window.$e &&
			typeof window.$e.run === 'function' &&
			window.elementor &&
			window.elementor.documents &&
			typeof window.elementor.documents.getCurrent === 'function'
		);
	}

	/** Places in the open page that hold rich text, in page order (widgets.js). */
	function textWidgets() {
		const doc = canInsert()
			? window.elementor.documents.getCurrent()
			: null;
		return doc ? textSpots( doc.container ) : [];
	}

	/**
	 * Does the open page already link to this address in a text widget?
	 *
	 * @param {string} url Address.
	 * @return {boolean} True when a text widget links there.
	 */
	function linked( url ) {
		const href = 'href="' + esc( url ) + '"';
		return textWidgets().some( ( w ) => w.html.indexOf( href ) !== -1 );
	}

	async function load( postId ) {
		if ( ! cache[ postId ] ) {
			cache[ postId ] = fetch(
				cfg.root + 'suggestions/' + encodeURIComponent( postId ),
				{
					headers: { 'X-WP-Nonce': cfg.nonce },
					credentials: 'same-origin',
				}
			).then( ( r ) => {
				if ( ! r.ok ) {
					throw new Error(
						__( 'Could not load link ideas.', 'jnk-linkweave' )
					);
				}
				return r.json();
			} );
		}
		return cache[ postId ];
	}

	function status( text ) {
		return (
			'<p class="jnk-lw-el-status" role="status" style="margin:4px 0 0;font-size:11px">' +
			esc( text ) +
			'</p>'
		);
	}

	function item( s, i, insert ) {
		const done = insert && linked( s.url );
		return (
			'<li style="margin:0 0 10px" data-jnk-idea="' +
			i +
			'">' +
			'<strong style="display:block">' +
			esc( s.title ) +
			'</strong>' +
			'<span dir="ltr" style="display:block;font-size:11px;opacity:.75;overflow-wrap:anywhere">' +
			esc( readable( s.url ) ) +
			'</span>' +
			( insert && ! done
				? '<button type="button" class="elementor-button elementor-button-success" style="margin-block-start:4px;margin-inline-end:4px;padding:4px 10px" data-jnk-insert="' +
				  i +
				  '">' +
				  esc( __( 'Insert link', 'jnk-linkweave' ) ) +
				  '</button>'
				: '' ) +
			'<button type="button" class="elementor-button elementor-button-default" style="margin-block-start:4px;padding:4px 10px" data-jnk-copy="' +
			esc( s.url ) +
			'">' +
			esc( __( 'Copy link', 'jnk-linkweave' ) ) +
			'</button>' +
			( done
				? status(
						__( 'This page already links here.', 'jnk-linkweave' )
				  )
				: '' ) +
			'</li>'
		);
	}

	function render( box, data ) {
		const list = ( ( data && data.suggestions ) || [] ).slice( 0, 10 );
		if ( ! list.length ) {
			box.innerHTML =
				'<p>' +
				esc(
					__(
						'No link ideas for this page yet. Linkweave reads it again after you publish or update it.',
						'jnk-linkweave'
					)
				) +
				'</p>';
			return;
		}
		ideas.set( box, list );
		const insert = canInsert();
		box.innerHTML =
			( insert
				? '<p style="margin:0 0 8px">' +
				  esc(
						__(
							'Insert link puts the link on matching words in the page text: Text Editor and Paragraph widgets, tabs, accordions and toggles. Nothing is saved until you click Publish or Update.',
							'jnk-linkweave'
						)
				  ) +
				  '</p>'
				: '' ) +
			'<p style="margin:0 0 8px">' +
			esc(
				__(
					"Select words in a Text Editor widget, click its link button and paste. Or paste into a button's Link field.",
					'jnk-linkweave'
				)
			) +
			'</p>' +
			'<ul style="margin:0;padding:0;list-style:none">' +
			list.map( ( s, i ) => item( s, i, insert ) ).join( '' ) +
			'</ul>';
	}

	async function fill( box ) {
		if ( box.dataset.filled ) {
			return;
		}
		box.dataset.filled = '1';
		try {
			render( box, await load( box.dataset.post ) );
		} catch ( e ) {
			box.innerHTML = '<p>' + esc( e.message ) + '</p>';
		}
	}

	function record( postId, s, words ) {
		if ( ! s.target_id ) {
			return;
		}
		// Best-effort, like the block editor: the change in the page is what matters.
		fetch( cfg.root + 'links/insert', {
			method: 'POST',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/json',
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				source_post_id: Number( postId ),
				target_post_id: s.target_id,
				anchor_text: words,
			} ),
		} ).catch( () => {} );
	}

	/**
	 * Link the first matching words in the page, through Elementor's own
	 * command so its History and undo see the change.
	 *
	 * @param {Object} s Suggestion.
	 * @return {string|null} The linked words, or null when none matched.
	 */
	function insertIdea( s ) {
		// Title phrases first, in every widget (singular or plural alike: "Raised
		// Bed Kit" goes on "raised beds"). Only then a single title word the idea
		// was built from ("seeds" for "Herb Seed Collection").
		for ( const byTerms of [ false, true ] ) {
			for ( const w of textWidgets() ) {
				const placed = linkFirstWords( w.html, s, byTerms );
				if ( placed ) {
					window.$e.run( 'document/elements/settings', {
						container: w.container,
						settings: { [ w.key ]: w.value( placed.html ) },
						options: { external: true },
					} );
					return placed.text;
				}
			}
		}
		return null;
	}

	/**
	 * Why Insert link found no place, in words the editor can act on.
	 *
	 * @param {Object} s Suggestion.
	 * @return {string} The reason, with what to do instead.
	 */
	function whyNot( s ) {
		if ( ! textWidgets().length ) {
			return __(
				"This page has no Text Editor, Paragraph, tab, accordion or toggle text to put a link in. Use Copy link and paste it into a button's Link field.",
				'jnk-linkweave'
			);
		}
		const looked = titlePhrases( s.title ).slice( 0, 2 );
		if ( ! looked.length ) {
			return __(
				'The page text has no words from this title outside headings and links. Use Copy link and add the link by hand.',
				'jnk-linkweave'
			);
		}
		return sprintf(
			/* translators: %s: one or two phrases from the idea's title, in quotes. */
			__(
				'Linkweave looked for %s and similar words in the page text, outside headings and links, and found none. Use Copy link, then select your own words in a Text Editor widget, click its link button and paste.',
				'jnk-linkweave'
			),
			looked.map( ( p ) => '“' + p + '”' ).join( ', ' )
		);
	}

	function onInsert( btn ) {
		const box = btn.closest( '.jnk-lw-el-ideas' );
		const li = btn.closest( '[data-jnk-idea]' );
		const s = box && ( ideas.get( box ) || [] )[ btn.dataset.jnkInsert ];
		if ( ! s || ! li ) {
			return;
		}
		li.querySelectorAll( '.jnk-lw-el-status' ).forEach( ( n ) =>
			n.remove()
		);
		let words = null;
		let failed = false;
		try {
			words = insertIdea( s );
		} catch ( e ) {
			failed = true;
		}
		if ( words ) {
			btn.remove();
			li.insertAdjacentHTML(
				'beforeend',
				status(
					sprintf(
						/* translators: %s: the words that became the link. */
						__(
							'Linked “%s”. Click Publish or Update to save the page.',
							'jnk-linkweave'
						),
						words
					)
				)
			);
			record( box.dataset.post, s, words );
			return;
		}
		li.insertAdjacentHTML(
			'beforeend',
			status(
				failed
					? __(
							'Elementor did not accept the change. Use Copy link instead.',
							'jnk-linkweave'
					  )
					: whyNot( s )
			)
		);
		// The way forward is Copy link: point at it.
		const copy = li.querySelector( '[data-jnk-copy]' );
		if ( copy ) {
			copy.classList.add( 'elementor-button-success' );
			copy.classList.remove( 'elementor-button-default' );
		}
	}

	async function onCopy( btn ) {
		const url = btn.getAttribute( 'data-jnk-copy' );
		let ok = false;
		try {
			await window.navigator.clipboard.writeText( url );
			ok = true;
		} catch ( e ) {
			// Older browsers / no permission: select a temporary field instead.
			const t = document.createElement( 'textarea' );
			t.value = url;
			document.body.appendChild( t );
			t.select();
			try {
				ok = document.execCommand( 'copy' );
			} catch ( x ) {
				ok = false;
			}
			t.remove();
		}
		const label = btn.textContent;
		/* translators: %s: the address to copy by hand. */
		const manual = __( 'Copy this: %s', 'jnk-linkweave' );
		btn.textContent = ok
			? __( 'Copied', 'jnk-linkweave' )
			: sprintf( manual, url );
		setTimeout( () => {
			btn.textContent = label;
		}, 2000 );
	}

	document.addEventListener( 'click', function ( ev ) {
		const target = ev.target && ev.target.closest ? ev.target : null;
		const insertBtn = target && target.closest( '[data-jnk-insert]' );
		if ( insertBtn ) {
			onInsert( insertBtn );
			return;
		}
		const copyBtn = target && target.closest( '[data-jnk-copy]' );
		if ( copyBtn ) {
			onCopy( copyBtn );
		}
	} );

	// Undo and redo in Elementor take a link out or put it back: draw the
	// ideas again so Insert link shows where it can be used.
	if ( canInsert() && window.$e.commands && window.$e.commands.on ) {
		window.$e.commands.on( 'run:after', ( component, command ) => {
			if (
				! /^document\/history\/(undo|redo)/.test( String( command ) )
			) {
				return;
			}
			document
				.querySelectorAll( '.jnk-lw-el-ideas[data-filled]' )
				.forEach( ( box ) => {
					const list = ideas.get( box );
					if ( list ) {
						render( box, { suggestions: list } );
					}
				} );
		} );
	}

	const observer = new window.MutationObserver( () => {
		document
			.querySelectorAll( '.jnk-lw-el-ideas:not([data-filled])' )
			.forEach( fill );
	} );
	observer.observe( document.documentElement, {
		childList: true,
		subtree: true,
	} );
} )();
