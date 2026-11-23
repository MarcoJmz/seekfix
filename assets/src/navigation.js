/**
 * The screen's state in the URL: ?tab=links&status=broken,redirect, or
 * ?status=all for no status filter.
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
 * ?status= value meaning "no status filter".
 */
export const ALL_STATUSES = 'all';

/**
 * Statuses from ?status=, unknown values dropped.
 *
 * @return {string[]|null} Statuses; empty for ?status=all; null when the
 *                         URL sets no valid status, so the default applies.
 */
export function statusesFromUrl() {
	const param =
		new URLSearchParams( window.location.search ).get( 'status' ) || '';

	if ( param === ALL_STATUSES ) {
		return [];
	}

	const statuses = param
		.split( ',' )
		.filter( ( value ) =>
			STATUSES.some( ( status ) => status.value === value )
		);

	return statuses.length ? statuses : null;
}
