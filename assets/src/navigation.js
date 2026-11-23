/**
 * The screen's state in the URL: ?tab=links&status=broken,redirect.
 *
 * replaceState keeps it without reloading, so a reload or a copied URL opens
 * the same tab with the same status filter.
 */
import { STATUSES } from './labels';

/**
 * URL of the screen with some query arguments changed.
 *
 * @param {Object} args Arguments to set; null removes one.
 * @return {URL} URL.
 */
export function screenUrl( args ) {
	const url = new URL( window.location.href );

	Object.entries( args ).forEach( ( [ key, value ] ) => {
		if ( value === null ) {
			url.searchParams.delete( key );
		} else {
			url.searchParams.set( key, value );
		}
	} );

	return url;
}

/**
 * Changes query arguments without reloading.
 *
 * @param {Object} args Arguments to set; null removes one.
 */
export function replaceArgs( args ) {
	window.history.replaceState( null, '', screenUrl( args ) );
}

/**
 * Link to the Links tab filtered by one status.
 *
 * @param {string} status Status.
 * @return {string} URL.
 */
export function linksUrl( status ) {
	return screenUrl( { tab: 'links', status } ).toString();
}

/**
 * Statuses from ?status=, unknown values dropped.
 *
 * @return {string[]} Statuses; empty when none.
 */
export function statusesFromUrl() {
	const param =
		new URLSearchParams( window.location.search ).get( 'status' ) || '';

	return param
		.split( ',' )
		.filter( ( value ) =>
			STATUSES.some( ( status ) => status.value === value )
		);
}
