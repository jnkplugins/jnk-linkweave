import { useEffect, useRef } from '@wordpress/element';
import { useSelect } from '@wordpress/data';

/**
 * Re-run a panel's load() after the post is saved, while the panel is still empty.
 *
 * A new post is indexed in the background a few seconds after its first save.
 * The sidebar fetched once, before publishing, and then kept saying "This post has
 * not been indexed yet" until the writer happened to click Refresh. Retry on a
 * short back-off instead, and stop as soon as the panel has something to show.
 *
 * @param {Function} load    The panel's fetch function.
 * @param {Function} isEmpty Returns true while the panel still has nothing to show.
 */
export default function useRefetchAfterSave( load, isEmpty ) {
	const { isSaving, isAutosaving, didSucceed } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			isSaving: editor.isSavingPost(),
			isAutosaving: editor.isAutosavingPost(),
			didSucceed: editor.didPostSaveRequestSucceed(),
		};
	}, [] );

	const wasSaving = useRef( false );
	const timers = useRef( [] );
	const latest = useRef( { load, isEmpty } );
	latest.current = { load, isEmpty };

	useEffect( () => {
		if ( wasSaving.current && ! isSaving && didSucceed ) {
			timers.current.forEach( clearTimeout );
			// The background queue often takes 45 s or more to run the reindex job,
			// so stopping at 30 s left "Posts that should link here" empty until a
			// manual Refresh. Keep trying for about two minutes.
			timers.current = [
				3000, 8000, 15000, 30000, 50000, 75000, 110000,
			].map( ( ms ) =>
				setTimeout( () => {
					if ( latest.current.isEmpty() ) {
						latest.current.load();
					}
				}, ms )
			);
		}
		wasSaving.current = isSaving && ! isAutosaving;
	}, [ isSaving, isAutosaving, didSucceed ] );

	useEffect( () => () => timers.current.forEach( clearTimeout ), [] );
}
