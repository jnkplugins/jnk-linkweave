/**
 * Where Insert link may write in the open Elementor page.
 *
 * Works on Elementor's editor containers (the same objects its own commands
 * take), in page order:
 *   - classic text widgets: Text Editor, icon box / image box text, testimonial,
 *     alert;
 *   - the items of the classic Tabs, Accordion and Toggle widgets (their
 *     "tabs" repeater, each item's content; never the item title);
 *   - Elementor 4's Paragraph widget (e-paragraph), whose text is a typed value
 *     { $$type: 'escaped-html', value }. A Paragraph that is itself a link, or
 *     that sits in a tab or accordion title, is left alone;
 *   - widgets inside containers, including the Nested Tabs / Nested Accordion
 *     panes and the Elementor 4 Tabs / Accordion content areas.
 * Headings (Heading, e-heading) are never in the list.
 */

/** Widget type => the setting that holds its rich text. */
export const TEXT_SETTINGS = {
	'text-editor': 'editor',
	'icon-box': 'description_text',
	'image-box': 'description_text',
	testimonial: 'testimonial_content',
	alert: 'alert_description',
};

/** Widget type => [ repeater, item setting ] for the classic repeater widgets. */
export const REPEATER_SETTINGS = {
	tabs: [ 'tabs', 'tab_content' ],
	accordion: [ 'tabs', 'tab_content' ],
	toggle: [ 'tabs', 'tab_content' ],
};

/** Elementor 4 elements whose words are a tab or accordion title (buttons). */
const TITLE_ELEMENTS = new Set( [
	'e-tabs-menu',
	'e-tab',
	'e-accordion-item-header',
	'e-accordion-item-title',
] );

/** Typed text values Insert link can write back. */
const TEXT_TYPES = new Set( [ 'escaped-html', 'html', 'string' ] );

function get( container, key ) {
	return container && container.settings
		? container.settings.get( key )
		: undefined;
}

/**
 * A typed Elementor 4 link value that points somewhere.
 *
 * @param {*} link The widget's link setting.
 * @return {boolean} True when the widget itself is a link.
 */
function hasLink( link ) {
	const dest = link && link.value && link.value.destination;
	return !! ( dest && dest.value );
}

/**
 * The children of a container. A widget with one repeater (Tabs, Accordion,
 * Toggle) answers `children` with its repeater items and a deprecation warning,
 * so its items are read through `repeaters` instead.
 *
 * @param {Object} container Editor container.
 * @return {Array} Child containers.
 */
function kids( container ) {
	const model = container.model;
	if (
		model &&
		model.get( 'elType' ) === 'widget' &&
		container.repeaters &&
		Object.keys( container.repeaters ).length &&
		! model.get( 'supportRepeaterChildren' )
	) {
		return [];
	}
	return Array.from( container.children || [] );
}

/**
 * Every place in the page Insert link may write, in page order.
 *
 * @param {Object} root The document's container.
 * @return {Array<{container:Object,key:string,html:string,value:Function}>} Spots; value( html ) is what to store.
 */
export function textSpots( root ) {
	const out = [];
	const walk = ( container, depth, inTitle ) => {
		if ( ! container || depth > 30 ) {
			return;
		}
		kids( container ).forEach( ( child ) => {
			const model = child.model;
			const elType = model ? model.get( 'elType' ) : '';
			const title = inTitle || TITLE_ELEMENTS.has( elType );
			if ( elType === 'widget' ) {
				const type = model.get( 'widgetType' );
				const key = TEXT_SETTINGS[ type ];
				const rep = REPEATER_SETTINGS[ type ];
				if ( key ) {
					const html = get( child, key );
					if ( typeof html === 'string' ) {
						out.push( {
							container: child,
							key,
							html,
							value: ( v ) => v,
						} );
					}
				} else if (
					rep &&
					child.repeaters &&
					child.repeaters[ rep[ 0 ] ]
				) {
					Array.from(
						child.repeaters[ rep[ 0 ] ].children || []
					).forEach( ( item ) => {
						const html = get( item, rep[ 1 ] );
						if ( typeof html === 'string' ) {
							out.push( {
								container: item,
								key: rep[ 1 ],
								html,
								value: ( v ) => v,
							} );
						}
					} );
				} else if ( type === 'e-paragraph' && ! title ) {
					const typed = get( child, 'paragraph' );
					if (
						typed &&
						TEXT_TYPES.has( typed.$$type ) &&
						typeof typed.value === 'string' &&
						! hasLink( get( child, 'link' ) )
					) {
						out.push( {
							container: child,
							key: 'paragraph',
							html: typed.value,
							value: ( v ) => ( {
								$$type: typed.$$type,
								value: v,
							} ),
						} );
					}
				}
			}
			walk( child, depth + 1, title );
		} );
	};
	walk( root, 0, false );
	return out;
}
