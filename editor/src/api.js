import apiFetch from '@wordpress/api-fetch';

export function fetchSuggestions( postId, { limit = 10, mode = '' } = {} ) {
	const params = new URLSearchParams();
	params.set( 'limit', String( limit ) );
	if ( mode ) {
		params.set( 'mode', mode );
	}
	return apiFetch( {
		path: `/jnk-linkweave/v1/suggestions/${ postId }?${ params.toString() }`,
	} );
}

export function fetchInboundOpportunities( postId, { limit = 10 } = {} ) {
	return apiFetch( {
		path: `/jnk-linkweave/v1/reports/inbound-opportunities/${ postId }?limit=${ limit }`,
	} );
}

export function recordInsertion( sourceId, targetId, anchorText ) {
	return apiFetch( {
		path: '/jnk-linkweave/v1/links/insert',
		method: 'POST',
		data: {
			source_post_id: sourceId,
			target_post_id: targetId,
			anchor_text: anchorText,
		},
	} );
}
