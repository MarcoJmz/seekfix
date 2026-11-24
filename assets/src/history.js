/**
 * History tab: every fix SeekFix made, newest first, with Undo.
 *
 * A fix can be undone while the post is exactly as the fix left it. After
 * someone edits the post, the row links to the revisions instead.
 */
import { Button, ExternalLink } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { ACTION_LABELS, EditLink } from './fix-dialogs';
import Intro from './help';
import { formatDate } from './labels';
import { useNotify } from './notices';

const STATES = {
	available: __( 'Can be undone', 'seekfix' ),
	undone: __( 'Undone', 'seekfix' ),
	edited: __( 'Post edited since', 'seekfix' ),
	no_post: __( 'Post deleted', 'seekfix' ),
	no_snapshot: __( 'Revision deleted', 'seekfix' ),
};

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	titleField: 'post_title',
	fields: [ 'created_at', 'action', 'change', 'user', 'state' ],
	layout: {
		styles: {
			post_title: { maxWidth: 260 },
			created_at: { minWidth: 120, maxWidth: 160 },
			action: { minWidth: 110, maxWidth: 150 },
			change: { minWidth: 320 },
			user: { minWidth: 90, maxWidth: 140 },
			state: { minWidth: 170, maxWidth: 220 },
		},
	},
};

/**
 * Old and new URL of a fix.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Row.
 * @return {Element} Element.
 */
function Change( { item } ) {
	return (
		<span>
			<del className="seekfix-break">{ item.old_url }</del>
			{ item.new_url ? (
				<>
					{ ' → ' }
					<span className="seekfix-url">
						<ExternalLink href={ item.new_url }>
							{ item.new_url }
						</ExternalLink>
					</span>
				</>
			) : (
				<span className="seekfix-muted">
					{ ' → ' }
					{ __( 'link removed', 'seekfix' ) }
				</span>
			) }
		</span>
	);
}

/**
 * Undo button, or why the fix cannot be undone.
 *
 * The button is in the row itself rather than only in the actions menu, so
 * it is seen without hovering the row.
 *
 * @param {Object}                    props        Props.
 * @param {Object}                    props.item   Row.
 * @param {boolean}                   props.busy   Whether an undo is running.
 * @param {(items: Object[]) => void} props.onUndo Undoes the given rows.
 * @return {Element} Element.
 */
function State( { item, busy, onUndo } ) {
	if ( item.state === 'available' ) {
		return (
			<Button
				variant="secondary"
				size="compact"
				isBusy={ busy }
				disabled={ busy }
				onClick={ () => onUndo( [ item ] ) }
			>
				{ __( 'Undo', 'seekfix' ) }
			</Button>
		);
	}

	return (
		<span className={ `seekfix-state is-${ item.state }` }>
			{ STATES[ item.state ] || item.state }
			{ item.undone_at && (
				<span className="seekfix-muted">
					{ ' · ' }
					{ formatDate( item.undone_at ) }
				</span>
			) }
			{ item.state === 'edited' && item.revisions_url && (
				<>
					<br />
					<ExternalLink href={ item.revisions_url }>
						{ __( 'Compare revisions', 'seekfix' ) }
					</ExternalLink>
				</>
			) }
		</span>
	);
}

/**
 * Builds the DataViews fields.
 *
 * @param {boolean}                   busy   Whether an undo is running.
 * @param {(items: Object[]) => void} onUndo Undoes the given rows.
 * @return {Array} Fields.
 */
const buildFields = ( busy, onUndo ) => [
	{
		id: 'post_title',
		label: __( 'Post', 'seekfix' ),
		enableHiding: false,
		enableSorting: false,
		render: EditLink,
	},
	{
		id: 'created_at',
		label: __( 'Date', 'seekfix' ),
		enableSorting: false,
		render: ( { item } ) => formatDate( item.created_at ),
	},
	{
		id: 'action',
		label: __( 'Fix', 'seekfix' ),
		enableSorting: false,
		render: ( { item } ) => ACTION_LABELS[ item.action ] || item.action,
	},
	{
		id: 'change',
		label: __( 'Change', 'seekfix' ),
		enableSorting: false,
		render: Change,
	},
	{
		id: 'user',
		label: __( 'By', 'seekfix' ),
		enableSorting: false,
		render: ( { item } ) => item.user,
	},
	{
		id: 'state',
		label: __( 'Undo', 'seekfix' ),
		enableSorting: false,
		render: ( { item } ) => (
			<State item={ item } busy={ busy } onUndo={ onUndo } />
		),
	},
];

export default function HistoryTab() {
	const notify = useNotify();
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ data, setData ] = useState( { items: [], total: 0, pages: 1 } );
	const [ loading, setLoading ] = useState( true );
	const [ reload, setReload ] = useState( 0 );
	const [ undoing, setUndoing ] = useState( false );

	const refresh = useCallback( () => setReload( ( n ) => n + 1 ), [] );

	useEffect( () => {
		let live = true;

		setLoading( true );

		api.history( { page: view.page, per_page: view.perPage } )
			.then( ( result ) => live && setData( result ) )
			.catch(
				( error ) =>
					live && notify( error.message, { status: 'error' } )
			)
			.finally( () => live && setLoading( false ) );

		return () => {
			live = false;
		};
	}, [ view.page, view.perPage, reload, notify ] );

	const undo = useCallback(
		async ( items ) => {
			setUndoing( true );

			// Newest first: each undo needs the post as the next fix left it.
			try {
				for ( const item of items ) {
					await api.undo( item.id );
				}

				notify( __( 'Change undone.', 'seekfix' ) );
			} catch ( error ) {
				const link = error.data && error.data.revisions_url;

				notify( error.message, {
					status: 'error',
					actions: link
						? [
								{
									label: __( 'Compare revisions', 'seekfix' ),
									url: link,
								},
							]
						: [],
				} );
			}

			setUndoing( false );
			refresh();
		},
		[ notify, refresh ]
	);

	const fields = useMemo(
		() => buildFields( undoing, undo ),
		[ undoing, undo ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'undo',
				label: __( 'Undo', 'seekfix' ),
				supportsBulk: true,
				isEligible: ( item ) => item.state === 'available',
				callback: undo,
			},
		],
		[ undo ]
	);

	return (
		<>
			<Intro>
				<p>
					{ __(
						'Every fix made from the Links tab is listed here, newest first. Undo puts the post back as it was before the fix.',
						'seekfix'
					) }
				</p>
				<p className="seekfix-muted">
					{ __(
						'A fix can be undone only while the post is exactly as the fix left it. If someone edited the post afterwards, use Compare revisions to restore it by hand, so their changes are not lost.',
						'seekfix'
					) }
				</p>
			</Intro>
			<div className="seekfix-links seekfix-history">
				<DataViews
					view={ view }
					onChangeView={ setView }
					fields={ fields }
					data={ data.items }
					getItemId={ ( item ) => String( item.id ) }
					isLoading={ loading }
					actions={ actions }
					paginationInfo={ {
						totalItems: data.total,
						totalPages: data.pages,
					} }
					defaultLayouts={ { table: {} } }
					search={ false }
					empty={
						<p>
							{ __(
								'No fixes yet. Links you fix from the Links tab appear here.',
								'seekfix'
							) }
						</p>
					}
				/>
			</div>
		</>
	);
}
