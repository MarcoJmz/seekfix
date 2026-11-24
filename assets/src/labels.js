/**
 * Translated labels shared by the screens.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

export const STATUSES = [
	{ value: 'broken', label: __( 'Broken', 'seekfix' ) },
	{ value: 'unverifiable', label: __( 'Cannot verify', 'seekfix' ) },
	{ value: 'redirect', label: __( 'Redirect', 'seekfix' ) },
	{ value: 'ok', label: __( 'OK', 'seekfix' ) },
	{ value: 'ignored', label: __( 'Ignored', 'seekfix' ) },
];

export const ELEMENTS = [
	{ value: 'link', label: __( 'Link', 'seekfix' ) },
	{ value: 'button', label: __( 'Button', 'seekfix' ) },
	{ value: 'image', label: __( 'Image', 'seekfix' ) },
	{ value: 'shortcode', label: __( 'Shortcode', 'seekfix' ) },
];

export const ORIGINS = [
	{ value: 'internal', label: __( 'Internal', 'seekfix' ) },
	{ value: 'external', label: __( 'External', 'seekfix' ) },
];

const ERRORS = {
	dns: __( 'Domain not found', 'seekfix' ),
	timeout: __( 'Timed out', 'seekfix' ),
	ssl: __( 'Certificate error', 'seekfix' ),
	connection: __( 'Connection failed', 'seekfix' ),
	waf: __( 'Blocked by a firewall', 'seekfix' ),
	private_host: __( 'Private network', 'seekfix' ),
	host_rule: __( 'Domain rule', 'seekfix' ),
	not_found: __( 'Not found on this site', 'seekfix' ),
};

/**
 * Label of a value in a list of options.
 *
 * @param {Array}  options Options with value and label.
 * @param {string} value   Value.
 * @return {string} Label, or the value itself.
 */
export function labelOf( options, value ) {
	const option = options.find( ( item ) => item.value === value );

	return option ? option.label : value;
}

/**
 * Short description of the last answer: HTTP code or error.
 *
 * @param {Object} item Row with http_code and error_code.
 * @return {string} Text, or an empty string.
 */
export function answerOf( item ) {
	if ( item.http_code ) {
		return String( item.http_code );
	}

	if ( item.error_code ) {
		return ERRORS[ item.error_code ] || item.error_code;
	}

	return '';
}

/**
 * Date from the API. The server sends UTC dates through
 * mysql_to_rfc3339(), which leaves out the offset, and JavaScript would
 * read those as local time.
 *
 * @param {string} date RFC 3339 date, with or without an offset.
 * @return {Date} Date.
 */
export function toDate( date ) {
	return new Date( /(Z|[+-]\d\d:?\d\d)$/i.test( date ) ? date : date + 'Z' );
}

/**
 * Local date and time of an RFC 3339 date.
 *
 * @param {string|null} date Date.
 * @return {string} Formatted date, or an empty string.
 */
export function formatDate( date ) {
	return date
		? toDate( date ).toLocaleString( undefined, {
				dateStyle: 'medium',
				timeStyle: 'short',
			} )
		: '';
}

/**
 * "N links in M posts".
 *
 * @param {number} instances Links.
 * @param {number} posts     Posts.
 * @return {string} Text.
 */
export function countText( instances, posts ) {
	return sprintf(
		/* translators: 1: "N links", 2: "N posts". */
		__( '%1$s in %2$s', 'seekfix' ),
		sprintf(
			/* translators: %d: number of links. */
			_n( '%d link', '%d links', instances, 'seekfix' ),
			instances
		),
		sprintf(
			/* translators: %d: number of posts. */
			_n( '%d post', '%d posts', posts, 'seekfix' ),
			posts
		)
	);
}
