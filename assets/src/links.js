/**
 * Links tab: the report, its filters and the fix actions.
 *
 * One row per link in a post, or one row per URL with "Group by URL".
 * Filtering, sorting and paging run on the server. Bulk actions apply to the
 * rows selected on the current page only.
 */
import {
	Button,
	ExternalLink,
	Flex,
	ToggleControl,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { api } from './api';
import {
	ACTION_LABELS,
	EditLink,
	FixDialog,
	RedirectChain,
	fixedText,
} from './fix-dialogs';
import {
	ELEMENTS,
	ORIGINS,
	STATUSES,
	answerOf,
	formatDate,
	labelOf,
} from './labels';
import { replaceArgs, statusesFromUrl } from './navigation';
import { useNotify } from './notices';

const { postTypes = [] } = window.seekfixAdmin || {};

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'url', direction: 'asc' },
	filters: [ { field: 'status', operator: 'isAny', value: [ 'broken' ] } ],
	titleField: 'url',
	fields: [ 'status', 'element', 'anchor_text', 'post_title' ],
	layout: {},
};

/**
 * First view: the default one, with the status filter from ?status= if any.
 *
 * @return {Object} View.
 */
function initialView() {
	const statuses = statusesFromUrl();

	return statuses.length
		? {
				...DEFAULT_VIEW,
				filters: [
					{ field: 'status', operator: 'isAny', value: statuses },
				],
			}
		: DEFAULT_VIEW;
}

/**
 * Status filter values of a view.
 *
 * @param {Object} view View.
 * @return {string[]} Statuses; empty when there is no status filter.
 */
function statusesOf( view ) {
	const filter = ( view.filters || [] ).find(
		( item ) => item.field === 'status'
	);

	return filter && Array.isArray( filter.value ) ? filter.value : [];
}

const GROUP_FIELDS = [ 'status', 'posts', 'instances' ];
const INSTANCE_FIELDS = DEFAULT_VIEW.fields;

/**
 * Query parameters for the API from a DataViews view.
 *
 * @param {Object}  view  View.
 * @param {boolean} byUrl Group by URL.
 * @return {Object} Query.
 */
function toQuery( view, byUrl ) {
	const query = {
		page: view.page,
		per_page: view.perPage,
		search: view.search || undefined,
		orderby: view.sort ? view.sort.field : 'url',
		order: view.sort ? view.sort.direction : 'asc',
		group_by_url: byUrl || undefined,
		status: [ 'broken', 'unverifiable', 'redirect', 'ok' ],
	};

	( view.filters || [] ).forEach( ( filter ) => {
		const values = Array.isArray( filter.value )
			? filter.value
			: [ filter.value ];

		if ( filter.value === undefined || values.length === 0 ) {
			return;
		}

		if ( filter.field === 'status' ) {
			query.status = values.includes( 'ignored' )
				? [ 'ignored' ]
				: values;
		} else if (
			filter.field === 'post_type' ||
			filter.field === 'element'
		) {
			query[ filter.field ] = values;
		} else if ( filter.field === 'internal' || filter.field === 'host' ) {
			query[ filter.field ] = values[ 0 ];
		}
	} );

	return query;
}

/**
 * Status cell: label, last answer and certificate warning.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Row.
 * @return {Element} Element.
 */
function StatusCell( { item } ) {
	const status = item.ignored ? 'ignored' : item.status;
	const answer = answerOf( item );

	return (
		<span className={ `seekfix-status is-${ status }` }>
			{ labelOf( STATUSES, status ) }
			{ answer && <span className="seekfix-muted"> · { answer }</span> }
			{ item.ssl_invalid && (
				<span className="seekfix-badge">
					{ __( 'Invalid certificate', 'seekfix' ) }
				</span>
			) }
		</span>
	);
}

/**
 * Builds the DataViews fields.
 *
 * @param {boolean} byUrl Group by URL.
 * @param {Array}   hosts Host options.
 * @return {Array} Fields.
 */
function buildFields( byUrl, hosts ) {
	const filterOnly = {
		enableHiding: false,
		enableSorting: false,
		render: () => null,
	};

	return [
		{
			id: 'url',
			label: __( 'URL', 'seekfix' ),
			enableHiding: false,
			enableGlobalSearch: true,
			render: ( { item } ) => (
				<span className="seekfix-url">
					<ExternalLink href={ item.url }>{ item.url }</ExternalLink>
				</span>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'seekfix' ),
			elements: STATUSES,
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
			getValue: ( { item } ) =>
				item.ignored ? 'ignored' : item.status,
			render: StatusCell,
		},
		...( byUrl
			? [
					{
						id: 'posts',
						label: __( 'Posts', 'seekfix' ),
						render: ( { item } ) =>
							sprintf(
								/* translators: %d: number of posts. */
								_n(
									'In %d post',
									'In %d posts',
									item.posts,
									'seekfix'
								),
								item.posts
							),
					},
					{
						id: 'instances',
						label: __( 'Links', 'seekfix' ),
						render: ( { item } ) => item.instances,
					},
				]
			: [
					{
						id: 'element',
						label: __( 'Element', 'seekfix' ),
						elements: ELEMENTS,
						filterBy: { operators: [ 'isAny' ] },
						render: ( { item } ) =>
							labelOf( ELEMENTS, item.element ) +
							( item.shortcode_tag
								? ` [${ item.shortcode_tag }]`
								: '' ),
					},
					{
						id: 'anchor_text',
						label: __( 'Link text', 'seekfix' ),
						render: ( { item } ) => item.anchor_text,
					},
					{
						id: 'post_title',
						label: __( 'Post', 'seekfix' ),
						render: ( { item } ) => (
							<span>
								<EditLink item={ item } />
								<span className="seekfix-muted">
									{ ' ' }
									· { item.post_type_label }
								</span>
							</span>
						),
					},
				] ),
		{
			id: 'last_checked_at',
			label: __( 'Last checked', 'seekfix' ),
			render: ( { item } ) => formatDate( item.last_checked_at ),
		},
		{
			...filterOnly,
			id: 'post_type',
			label: __( 'Content type', 'seekfix' ),
			elements: postTypes,
			filterBy: { operators: [ 'isAny' ] },
		},
		{
			...filterOnly,
			id: 'internal',
			label: __( 'Internal or external', 'seekfix' ),
			elements: ORIGINS,
			filterBy: { operators: [ 'is' ] },
		},
		{
			...filterOnly,
			id: 'host',
			label: __( 'Domain', 'seekfix' ),
			elements: hosts,
			filterBy: { operators: [ 'is' ] },
		},
	];
}

/**
 * Saves a CSV string as a file.
 *
 * @param {string} csv CSV.
 */
function download( csv ) {
	const url = URL.createObjectURL(
		new Blob( [ csv ], { type: 'text/csv;charset=utf-8' } )
	);
	const link = document.createElement( 'a' );

	link.href = url;
	link.download = `seekfix-${ new Date().toISOString().slice( 0, 10 ) }.csv`;
	document.body.appendChild( link );
	link.click();
	link.remove();
	URL.revokeObjectURL( url );
}

export default function LinksTab() {
	const notify = useNotify();
	const [ view, setView ] = useState( initialView );
	const [ byUrl, setByUrl ] = useState( false );
	const [ data, setData ] = useState( { items: [], total: 0, pages: 1 } );
	const [ loading, setLoading ] = useState( true );
	const [ hosts, setHosts ] = useState( [] );
	const [ reload, setReload ] = useState( 0 );

	const refresh = useCallback( () => setReload( ( n ) => n + 1 ), [] );
	const query = useMemo( () => toQuery( view, byUrl ), [ view, byUrl ] );
	const statusArg = statusesOf( view ).join( ',' );

	// Keep the status filter in the URL, so a reload keeps it.
	useEffect( () => {
		replaceArgs( { status: statusArg || null } );
	}, [ statusArg ] );

	useEffect( () => {
		api.hosts()
			.then( ( list ) =>
				setHosts(
					list.map( ( host ) => ( { value: host, label: host } ) )
				)
			)
			.catch( () => {} );
	}, [ reload ] );

	useEffect( () => {
		let live = true;

		setLoading( true );

		api.list( query )
			.then( ( result ) => live && setData( result ) )
			.catch(
				( error ) =>
					live && notify( error.message, { status: 'error' } )
			)
			.finally( () => live && setLoading( false ) );

		return () => {
			live = false;
		};
	}, [ query, reload, notify ] );

	const undo = useCallback(
		async ( actionIds ) => {
			try {
				// Last fix first: each undo needs the post as the next fix left it.
				for ( const id of [ ...actionIds ].reverse() ) {
					await api.undo( id );
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

			refresh();
		},
		[ notify, refresh ]
	);

	const onFixed = useCallback(
		( result ) => {
			notify( fixedText( result ), {
				actions:
					result.actionIds.length > 0
						? [
								{
									label: __( 'Undo', 'seekfix' ),
									onClick: () => undo( result.actionIds ),
								},
							]
						: [],
			} );
			refresh();
		},
		[ notify, refresh, undo ]
	);

	const perLink = useCallback(
		async ( items, request, done ) => {
			const ids = [ ...new Set( items.map( ( item ) => item.link_id ) ) ];

			try {
				for ( const id of ids ) {
					await request( id );
				}

				notify( done( ids.length ) );
			} catch ( error ) {
				notify( error.message, { status: 'error' } );
			}

			refresh();
		},
		[ notify, refresh ]
	);

	const actions = useMemo( () => {
		const fixAction = ( action, bulk ) => ( {
			id: action,
			label: ACTION_LABELS[ action ],
			isPrimary: action !== 'use_final_url',
			supportsBulk: bulk,
			isEligible: ( item ) =>
				item.actions.includes( action ) && ! item.ignored,
			modalHeader: ACTION_LABELS[ action ],
			RenderModal: ( { items, closeModal } ) => (
				<FixDialog
					items={ items }
					byUrl={ byUrl }
					action={ action }
					close={ closeModal }
					onFixed={ onFixed }
				/>
			),
		} );

		return [
			{
				id: 'edit',
				label: __( 'Edit post', 'seekfix' ),
				isEligible: ( item ) => ! byUrl && Boolean( item.edit_url ),
				callback: ( [ item ] ) =>
					window.open( item.edit_url, '_blank', 'noopener' ),
			},
			fixAction( 'unlink', true ),
			fixAction( 'remove_button', true ),
			fixAction( 'replace_url', false ),
			fixAction( 'use_final_url', true ),
			{
				id: 'redirects',
				label: __( 'Show redirects', 'seekfix' ),
				isEligible: ( item ) => item.status === 'redirect',
				modalHeader: __( 'Redirect chain', 'seekfix' ),
				RenderModal: ( { items } ) => (
					<RedirectChain item={ items[ 0 ] } />
				),
			},
			{
				id: 'recheck',
				label: __( 'Check again now', 'seekfix' ),
				supportsBulk: true,
				callback: ( items ) =>
					perLink( items, api.recheck, ( n ) =>
						sprintf(
							/* translators: %d: number of URLs. */
							_n(
								'%d URL checked.',
								'%d URLs checked.',
								n,
								'seekfix'
							),
							n
						)
					),
			},
			{
				id: 'ignore',
				label: __( 'Ignore', 'seekfix' ),
				supportsBulk: true,
				isEligible: ( item ) => ! item.ignored,
				callback: ( items ) =>
					perLink(
						items,
						( id ) => api.ignore( id, true ),
						( n ) =>
							sprintf(
								/* translators: %d: number of URLs. */
								_n(
									'%d URL ignored.',
									'%d URLs ignored.',
									n,
									'seekfix'
								),
								n
							)
					),
			},
			{
				id: 'unignore',
				label: __( 'Stop ignoring', 'seekfix' ),
				supportsBulk: true,
				isEligible: ( item ) => item.ignored,
				callback: ( items ) =>
					perLink(
						items,
						( id ) => api.ignore( id, false ),
						( n ) =>
							sprintf(
								/* translators: %d: number of URLs. */
								_n(
									'%d URL back in the queue.',
									'%d URLs back in the queue.',
									n,
									'seekfix'
								),
								n
							)
					),
			},
		];
	}, [ byUrl, onFixed, perLink ] );

	const fields = useMemo(
		() => buildFields( byUrl, hosts ),
		[ byUrl, hosts ]
	);

	const toggleGroup = ( value ) => {
		setByUrl( value );
		setView( ( current ) => ( {
			...current,
			page: 1,
			sort: { field: 'url', direction: 'asc' },
			fields: value ? GROUP_FIELDS : INSTANCE_FIELDS,
		} ) );
	};

	const exportCsv = () =>
		api
			.exportCsv( {
				...query,
				page: undefined,
				per_page: undefined,
				group_by_url: undefined,
			} )
			.then( download )
			.catch( ( error ) => notify( error.message, { status: 'error' } ) );

	return (
		<div className="seekfix-links">
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
				search
				searchLabel={ __( 'Search URL or link text', 'seekfix' ) }
				header={
					<Flex
						gap={ 3 }
						justify="flex-end"
						className="seekfix-links__header"
					>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Group by URL', 'seekfix' ) }
							checked={ byUrl }
							onChange={ toggleGroup }
						/>
						<Button
							variant="secondary"
							size="compact"
							onClick={ exportCsv }
						>
							{ __( 'Export CSV', 'seekfix' ) }
						</Button>
					</Flex>
				}
				empty={
					<p>
						{ __(
							'No links match these filters. Pending URLs appear here once they are checked.',
							'seekfix'
						) }
					</p>
				}
			/>
		</div>
	);
}
