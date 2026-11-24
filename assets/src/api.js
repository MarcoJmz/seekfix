/**
 * Calls to the seekfix/v1 REST API.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const BASE = '/seekfix/v1';

const post = ( path, data = {} ) =>
	apiFetch( { path: BASE + path, method: 'POST', data } );

/**
 * Turns a failed request into an Error with the server's message.
 *
 * @param {Object|string} error Rejection from apiFetch.
 * @return {Error} Error with `code` and `data`.
 */
export function toError( error ) {
	const result = new Error(
		error && error.message ? error.message : String( error )
	);

	result.code = error && error.code;
	result.data = error && error.data;

	return result;
}

/**
 * Reads a response that apiFetch returned without parsing.
 *
 * @param {Response} response Response.
 * @return {Promise<Response>} The same response, or a rejection with the server's error.
 */
async function checked( response ) {
	if ( response.ok ) {
		return response;
	}

	let body = {};

	try {
		body = await response.json();
	} catch {
		// Not JSON: keep the generic error.
	}

	throw toError( body );
}

const call = ( promise ) =>
	promise.catch( ( error ) => {
		if ( error instanceof Response ) {
			return checked( error );
		}

		throw toError( error );
	} );

/**
 * GET of a paged list: items plus the totals from the headers.
 *
 * @param {string} path  Path under the namespace.
 * @param {Object} query Query arguments.
 * @return {Promise<Object>} { items, total, pages }.
 */
const paged = ( path, query ) =>
	call(
		apiFetch( {
			path: addQueryArgs( BASE + path, query ),
			parse: false,
		} )
	)
		.then( checked )
		.then( async ( response ) => ( {
			items: await response.json(),
			total: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
			pages: Number( response.headers.get( 'X-WP-TotalPages' ) || 1 ),
		} ) );

export const api = {
	summary: () => call( apiFetch( { path: BASE + '/summary' } ) ),

	scan: ( action ) => call( post( '/scan', { action } ) ),

	list: ( query ) => paged( '/instances', query ),

	history: ( query ) => paged( '/actions', query ),

	hosts: () => call( apiFetch( { path: BASE + '/hosts' } ) ),

	recheck: ( linkId ) => call( post( `/links/${ linkId }/recheck` ) ),

	ignore: ( linkId, ignored ) =>
		call( post( `/links/${ linkId }/ignore`, { ignored } ) ),

	plan: ( linkId, action ) =>
		call(
			apiFetch( {
				path: addQueryArgs( `${ BASE }/links/${ linkId }/plan`, {
					action,
				} ),
			} )
		),

	fixInstance: ( id, data ) => call( post( `/instances/${ id }/fix`, data ) ),

	fixInstances: ( ids, data ) =>
		call( post( '/instances/fix', { ...data, ids } ) ),

	fixLink: ( linkId, data ) => call( post( `/links/${ linkId }/fix`, data ) ),

	undo: ( actionId ) => call( post( `/actions/${ actionId }/undo` ) ),

	exportCsv: ( query ) =>
		call(
			apiFetch( {
				path: addQueryArgs( BASE + '/export', query ),
				parse: false,
			} )
		)
			.then( checked )
			.then( ( response ) => response.text() ),

	getSettings: () => call( apiFetch( { path: BASE + '/settings' } ) ),

	saveSettings: ( settings ) => call( post( '/settings', settings ) ),

	reset: () => call( post( '/reset' ) ),
};
