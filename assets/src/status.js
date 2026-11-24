/**
 * Status column of the Links tab: the cell and its header.
 *
 * The cell shows the path the link took: each redirect's code, an arrow,
 * then the final answer, so "301 → 200" reads as "redirected, then loaded"
 * rather than as one code.
 */
import { Tooltip } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, arrowRight, info } from '@wordpress/icons';

import { STATUSES, answerOf, labelOf } from './labels';

/**
 * Short meaning of the HTTP codes users meet most.
 */
const CODE_NAMES = {
	200: __( 'OK', 'seekfix' ),
	301: __( 'Moved', 'seekfix' ),
	302: __( 'Moved for now', 'seekfix' ),
	303: __( 'See other', 'seekfix' ),
	307: __( 'Moved for now', 'seekfix' ),
	308: __( 'Moved', 'seekfix' ),
	400: __( 'Bad request', 'seekfix' ),
	401: __( 'Login required', 'seekfix' ),
	403: __( 'Access denied', 'seekfix' ),
	404: __( 'Not found', 'seekfix' ),
	410: __( 'Gone', 'seekfix' ),
	429: __( 'Too many requests', 'seekfix' ),
	500: __( 'Server error', 'seekfix' ),
	502: __( 'Bad gateway', 'seekfix' ),
	503: __( 'Unavailable', 'seekfix' ),
	504: __( 'Gateway timeout', 'seekfix' ),
};

/**
 * A code with its meaning, like "301 (Moved)"; errors stay as they are.
 *
 * @param {string|number} code Code or error.
 * @return {string} Text.
 */
function describe( code ) {
	const name = CODE_NAMES[ code ];

	if ( ! name ) {
		return String( code );
	}

	/* translators: 1: HTTP code, 2: its meaning, like "Not found". */
	return sprintf( __( '%1$s (%2$s)', 'seekfix' ), code, name );
}

/**
 * Explanation of a link's path, for the tooltip.
 *
 * @param {number[]} hops  Codes of the redirects.
 * @param {string}   final Final answer: code or error.
 * @return {string} Text.
 */
function pathText( hops, final ) {
	if ( hops.length === 0 ) {
		return sprintf(
			/* translators: %s: answer, like "404" or "Timed out". */
			__( 'Last answer from the server: %s.', 'seekfix' ),
			describe( final )
		);
	}

	if ( hops.length === 1 ) {
		return sprintf(
			/* translators: 1: redirect code, like "301", 2: final answer. */
			__(
				'The link answers %1$s and sends visitors to another address, which answers %2$s.',
				'seekfix'
			),
			describe( hops[ 0 ] ),
			describe( final )
		);
	}

	return sprintf(
		/* translators: 1: number of redirects, 2: redirect codes, like "301, 302", 3: final answer. */
		__(
			'The link sends visitors through %1$d other addresses (%2$s). The last one answers %3$s.',
			'seekfix'
		),
		hops.length,
		hops.join( ', ' ),
		describe( final )
	);
}

/**
 * One step of the path: a code, with its meaning on the last step.
 *
 * @param {Object}  props       Props.
 * @param {string}  props.text  Code or error.
 * @param {boolean} props.final Whether it is the final answer.
 * @return {Element} Element.
 */
function Step( { text, final } ) {
	const name = final && CODE_NAMES[ text ];

	return (
		<span className={ `seekfix-step${ final ? ' is-final' : '' }` }>
			{ text }
			{ name && <span className="seekfix-step__name"> { name }</span> }
		</span>
	);
}

/**
 * Status cell: label, the path the link took, and certificate warning.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Row.
 * @return {Element} Element.
 */
export function StatusCell( { item } ) {
	const status = item.ignored ? 'ignored' : item.status;
	const hops = ( item.redirect_chain || [] )
		.map( ( hop ) => hop.code )
		.filter( Boolean );
	const final = answerOf( item );

	return (
		<span className={ `seekfix-status is-${ status }` }>
			<span className="seekfix-status__label">
				{ labelOf( STATUSES, status ) }
			</span>
			{ ( final || hops.length > 0 ) && (
				<Tooltip text={ pathText( hops, final || '?' ) }>
					<span className="seekfix-path" tabIndex={ 0 }>
						{ hops.map( ( code, index ) => (
							<span key={ index } className="seekfix-path__hop">
								<Step text={ String( code ) } />
								<Icon
									className="seekfix-path__arrow"
									icon={ arrowRight }
									size={ 16 }
								/>
							</span>
						) ) }
						{ final && <Step text={ final } final /> }
					</span>
				</Tooltip>
			) }
			{ item.ssl_invalid && item.error_code !== 'ssl' && (
				<span className="seekfix-badge">
					{ __( 'Invalid certificate', 'seekfix' ) }
				</span>
			) }
		</span>
	);
}

/**
 * Header of the Status column, with an icon that explains the column.
 *
 * @return {Element} Element.
 */
export function StatusHeader() {
	const lines = [
		[
			__( 'Broken:', 'seekfix' ),
			__(
				'the page is missing, confirmed by a second check.',
				'seekfix'
			),
		],
		[
			__( 'Cannot verify:', 'seekfix' ),
			__(
				'the site blocked the check or did not answer. Open the link to check it by hand.',
				'seekfix'
			),
		],
		[
			__( 'Redirect:', 'seekfix' ),
			__(
				'the link works, but goes through another address first.',
				'seekfix'
			),
		],
	];

	// The header sits inside the column's menu button, which already has a
	// Tooltip, and a nested Tooltip does not show. So the bubble is plain
	// CSS: it shows while the button is hovered or has keyboard focus.
	return (
		<span className="seekfix-th-help">
			{ __( 'Status', 'seekfix' ) }
			<span className="seekfix-th-help__icon">
				<Icon icon={ info } size={ 18 } />
			</span>
			<span className="seekfix-th-help__bubble" role="tooltip">
				<span className="seekfix-th-help__line">
					{ __(
						'Result of the last check of each link.',
						'seekfix'
					) }
				</span>
				{ lines.map( ( [ term, text ] ) => (
					<span key={ term } className="seekfix-th-help__line">
						<strong>{ term }</strong> { text }
					</span>
				) ) }
				<span className="seekfix-th-help__line">
					{ __(
						'Below, the server answers in order: 301 → 200 means the link was redirected, then the page loaded.',
						'seekfix'
					) }
				</span>
			</span>
		</span>
	);
}
