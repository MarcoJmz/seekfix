/**
 * Dialogs that fix links: confirm, enter a new URL, review a redirect.
 *
 * Every dialog says how many links in how many posts will change before
 * anything is written; for a whole URL the count comes from the server
 * (plan) and is sent back as `expected`, so the server refuses the fix if
 * more links would change than the user saw.
 */
import {
	Button,
	ExternalLink,
	Flex,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api } from './api';
import { countText } from './labels';

export const ACTION_LABELS = {
	unlink: __( 'Remove link', 'seekfix' ),
	remove_button: __( 'Remove button', 'seekfix' ),
	replace_url: __( 'Replace URL', 'seekfix' ),
	use_final_url: __( 'Update to final URL', 'seekfix' ),
};

const EXPLANATIONS = {
	unlink: __( 'The link is removed and its text stays in place.', 'seekfix' ),
	remove_button: __(
		'The button block is removed. If it was the only button in its group, the group goes too.',
		'seekfix'
	),
	replace_url: __( 'Only the link address changes.', 'seekfix' ),
	use_final_url: __(
		'The link points straight to where the redirects end.',
		'seekfix'
	),
};

/**
 * List of redirect hops.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Row with url, redirect_chain and final_url.
 * @return {Element} Element.
 */
export function RedirectChain( { item } ) {
	const hops = item.redirect_chain || [];

	return (
		<ol className="seekfix-chain">
			{ hops.map( ( hop, index ) => (
				<li key={ index }>
					<code>{ hop.code || '' }</code>{ ' ' }
					<span className="seekfix-break">{ hop.url }</span>
				</li>
			) ) }
			{ item.final_url && (
				<li>
					<code>{ item.http_code || '' }</code>{ ' ' }
					<span className="seekfix-break">{ item.final_url }</span>
				</li>
			) }
		</ol>
	);
}

/**
 * Counts what the fix will change.
 *
 * @param {Object[]} items  Rows.
 * @param {boolean}  byUrl  Whether the rows are URLs (all their instances).
 * @param {string}   action Action.
 * @return {Object} { loading, plans, instances, posts, error }.
 */
function usePlan( items, byUrl, action ) {
	const [ state, setState ] = useState( { loading: byUrl } );

	useEffect( () => {
		if ( ! byUrl ) {
			setState( {
				loading: false,
				instances: items.length,
				posts: new Set( items.map( ( item ) => item.post_id ) ).size,
			} );
			return;
		}

		let live = true;

		Promise.all( items.map( ( item ) => api.plan( item.link_id, action ) ) )
			.then( ( plans ) => {
				if ( live ) {
					setState( {
						loading: false,
						plans,
						instances: plans.reduce(
							( sum, plan ) => sum + plan.instances,
							0
						),
						posts: plans.reduce(
							( sum, plan ) => sum + plan.posts,
							0
						),
						skipped: plans.reduce(
							( sum, plan ) => sum + plan.skipped.length,
							0
						),
					} );
				}
			} )
			.catch(
				( error ) => live && setState( { loading: false, error } )
			);

		return () => {
			live = false;
		};
	}, [ items, byUrl, action ] );

	return state;
}

/**
 * Sends the fix and merges the answers into one summary.
 *
 * @param {Object[]} items  Rows.
 * @param {boolean}  byUrl  Whether the rows are URLs.
 * @param {string}   action Action.
 * @param {Object}   args   new_url and force.
 * @param {Object}   plan   Result of usePlan().
 * @return {Promise<Object>} { actionIds, fixed, skipped, worksAgain }.
 */
async function runFix( items, byUrl, action, args, plan ) {
	const result = { actionIds: [], fixed: 0, skipped: [], worksAgain: 0 };
	const add = ( summary ) => {
		summary.fixed.forEach( ( entry ) => {
			result.actionIds.push( entry.action_id );
			result.fixed += entry.instances;
		} );
		result.skipped.push( ...summary.skipped );
		result.worksAgain += Array.isArray( summary.works_again )
			? summary.works_again.length
			: Number( Boolean( summary.works_again ) );
	};

	if ( byUrl ) {
		for ( let i = 0; i < items.length; i++ ) {
			add(
				await api.fixLink( items[ i ].link_id, {
					action,
					...args,
					expected: plan.plans[ i ].instances,
				} )
			);
		}
	} else if ( items.length === 1 ) {
		const done = await api.fixInstance( items[ 0 ].id, {
			action,
			...args,
		} );

		add( { fixed: [ done ], skipped: [], works_again: [] } );
	} else {
		add(
			await api.fixInstances(
				items.map( ( item ) => item.id ),
				{ action, force: args.force }
			)
		);
	}

	return result;
}

/**
 * Confirmation dialog for one fix action, used as a DataViews RenderModal.
 *
 * @param {Object}   props         Props.
 * @param {Object[]} props.items   Selected rows.
 * @param {boolean}  props.byUrl   Whether rows are URLs.
 * @param {string}   props.action  Action.
 * @param {Function} props.close   Closes the dialog.
 * @param {Function} props.onFixed Called with the summary after a fix.
 * @return {Element} Element.
 */
export function FixDialog( { items, byUrl, action, close, onFixed } ) {
	const plan = usePlan( items, byUrl, action );
	const single = items.length === 1;
	const [ newUrl, setNewUrl ] = useState(
		action === 'replace_url' && single ? items[ 0 ].url : ''
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ worksAgain, setWorksAgain ] = useState( false );

	const submit = async ( force = false ) => {
		setBusy( true );
		setError( null );

		try {
			const result = await runFix(
				items,
				byUrl,
				action,
				{ new_url: newUrl.trim(), force },
				plan
			);

			if ( result.worksAgain > 0 && result.fixed === 0 && single ) {
				setWorksAgain( true );
				setBusy( false );
				return;
			}

			onFixed( result );
			close();
		} catch ( failure ) {
			if ( failure.code === 'seekfix_link_works' ) {
				setWorksAgain( true );
			} else {
				setError( failure.message );
			}

			setBusy( false );
		}
	};

	if ( plan.loading ) {
		return <Spinner />;
	}

	if ( plan.error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ plan.error.message }
			</Notice>
		);
	}

	const nothing = plan.instances === 0;
	const needsUrl = action === 'replace_url' && newUrl.trim() === '';

	return (
		<div className="seekfix-dialog">
			<p>{ EXPLANATIONS[ action ] }</p>

			{ action === 'replace_url' && (
				<TextControl
					__next40pxDefaultSize
					label={ __( 'New URL', 'seekfix' ) }
					type="url"
					value={ newUrl }
					onChange={ setNewUrl }
					help={ __(
						'The new URL is checked in the next scan.',
						'seekfix'
					) }
				/>
			) }

			{ action === 'use_final_url' && single && (
				<RedirectChain item={ items[ 0 ] } />
			) }

			<p>
				<strong>
					{ nothing
						? __(
								'Nothing to change: these links cannot be fixed from here.',
								'seekfix'
							)
						: sprintf(
								/* translators: %s: "N links in M posts". */
								__( 'This changes %s.', 'seekfix' ),
								countText( plan.instances, plan.posts )
							) }
				</strong>{ ' ' }
				{ __(
					'Each post keeps a revision, and you can undo the change.',
					'seekfix'
				) }
			</p>

			{ plan.skipped > 0 && (
				<p className="seekfix-muted">
					{ sprintf(
						/* translators: %d: number of links left as they are. */
						_n(
							'%d more link stays as it is: it is inside a page builder shortcode, does not support this action, or is in a post you cannot edit.',
							'%d more links stay as they are: they are inside page builder shortcodes, do not support this action, or are in posts you cannot edit.',
							plan.skipped,
							'seekfix'
						),
						plan.skipped
					) }
				</p>
			) }

			{ worksAgain && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This link works again. Do you want to fix it anyway?',
						'seekfix'
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Flex
				justify="flex-end"
				gap={ 2 }
				className="seekfix-dialog__buttons"
			>
				<Button variant="tertiary" onClick={ close } disabled={ busy }>
					{ __( 'Cancel', 'seekfix' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive={
						action === 'unlink' || action === 'remove_button'
					}
					isBusy={ busy }
					disabled={ busy || nothing || needsUrl }
					onClick={ () => submit( worksAgain ) }
				>
					{ worksAgain
						? __( 'Fix anyway', 'seekfix' )
						: ACTION_LABELS[ action ] }
				</Button>
			</Flex>
		</div>
	);
}

/**
 * Text of the notice shown after a fix.
 *
 * @param {Object} result Summary from FixDialog.
 * @return {string} Text.
 */
export function fixedText( result ) {
	const parts = [
		sprintf(
			/* translators: %d: number of links fixed. */
			_n( '%d link fixed.', '%d links fixed.', result.fixed, 'seekfix' ),
			result.fixed
		),
	];

	if ( result.worksAgain > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of URLs. */
				_n(
					'%d URL works again and was left as is.',
					'%d URLs work again and were left as is.',
					result.worksAgain,
					'seekfix'
				),
				result.worksAgain
			)
		);
	}

	if ( result.skipped.length > 0 ) {
		parts.push(
			sprintf(
				/* translators: 1: number of skipped items, 2: reason of the first one. */
				__( '%1$d skipped: %2$s', 'seekfix' ),
				result.skipped.length,
				result.skipped[ 0 ].message
			)
		);
	}

	return parts.join( ' ' );
}

/**
 * Link to the post editor.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Row with edit_url and post_title.
 * @return {Element} Element.
 */
export function EditLink( { item } ) {
	return item.edit_url ? (
		<ExternalLink href={ item.edit_url }>{ item.post_title }</ExternalLink>
	) : (
		<span>{ item.post_title }</span>
	);
}
