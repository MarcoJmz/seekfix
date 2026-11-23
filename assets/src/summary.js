/**
 * Summary tab: counts, scan progress and scan controls.
 *
 * While a scan runs, the tab asks the server for one scan step every five
 * seconds, so the scan finishes even when WP-Cron does not run. With WP-Cron
 * it also continues in the background, more slowly.
 */
import {
	Button,
	Card,
	CardBody,
	Flex,
	Notice,
	ProgressBar,
	Spinner,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, chevronRight } from '@wordpress/icons';

import { api } from './api';
import { formatDate } from './labels';
import { linksUrl } from './navigation';
import { useNotify } from './notices';

const POLL_MS = 5000;

const { cronDisabled = false } = window.seekfixAdmin || {};

/**
 * One count card. With a status, the whole card links to the Links tab
 * filtered by it.
 *
 * @param {Object}                   props        Props.
 * @param {string}                   props.label  Label.
 * @param {number}                   props.value  Value.
 * @param {string}                   props.tone   Extra class.
 * @param {string}                   props.status Status to filter the Links tab by.
 * @param {(status: string) => void} props.onOpen Opens the Links tab without reloading.
 * @return {Element} Element.
 */
function Stat( { label, value, tone = '', status, onOpen } ) {
	const body = (
		<>
			<div className="seekfix-stat__value">
				{ Number( value || 0 ).toLocaleString() }
			</div>
			<div className="seekfix-stat__label">{ label }</div>
		</>
	);

	if ( ! status ) {
		return (
			<Card className={ `seekfix-stat ${ tone }` } size="small">
				<CardBody>{ body }</CardBody>
			</Card>
		);
	}

	// A plain click switches tabs in place; Ctrl/Cmd-click and middle
	// click open the URL in a new tab as usual.
	const open = ( event ) => {
		if (
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}

		event.preventDefault();
		onOpen( status );
	};

	return (
		<Card className={ `seekfix-stat is-link ${ tone }` } size="small">
			<a
				className="seekfix-stat__link"
				href={ linksUrl( status ) }
				onClick={ open }
			>
				<CardBody>
					{ body }
					<span className="seekfix-stat__more">
						{ __( 'View links', 'seekfix' ) }
						<Icon icon={ chevronRight } size={ 16 } />
					</span>
				</CardBody>
			</a>
		</Card>
	);
}

/**
 * Progress line of a running or paused scan.
 *
 * @param {Object} props      Props.
 * @param {Object} props.scan Progress from the API.
 * @return {Element} Element.
 */
function ScanProgress( { scan } ) {
	const extracting = scan.phase === 'extract';
	const done = extracting ? scan.posts_done : scan.urls_checked;
	const total = extracting ? scan.posts_total : scan.urls_total;
	const percent = total > 0 ? Math.min( 100, ( done / total ) * 100 ) : 0;

	const text = extracting
		? /* translators: 1: posts read, 2: total posts. */
			__( 'Reading posts: %1$d of %2$d', 'seekfix' )
		: /* translators: 1: URLs checked, 2: total URLs. */
			__( 'Checking URLs: %1$d of %2$d', 'seekfix' );

	return (
		<div className="seekfix-progress">
			<ProgressBar value={ percent } />
			<p>
				{ sprintf( text, done, total ) }
				{ scan.status === 'paused' &&
					' — ' + __( 'Paused', 'seekfix' ) }
			</p>
		</div>
	);
}

export default function SummaryTab( { onOpenLinks } ) {
	const notify = useNotify();
	const [ summary, setSummary ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const stepping = useRef( false );

	const load = useCallback(
		() =>
			api
				.summary()
				.then( setSummary )
				.catch( ( error ) =>
					notify( error.message, { status: 'error' } )
				),
		[ notify ]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	const running = summary && summary.scan.status === 'running';

	useEffect( () => {
		if ( ! running ) {
			return undefined;
		}

		const timer = window.setInterval( () => {
			if ( stepping.current ) {
				return;
			}

			stepping.current = true;

			api.scan( 'step' )
				.then( load )
				.catch( ( error ) =>
					notify( error.message, { status: 'error' } )
				)
				.finally( () => {
					stepping.current = false;
				} );
		}, POLL_MS );

		return () => window.clearInterval( timer );
	}, [ running, load, notify ] );

	const control = ( action ) => {
		setBusy( true );

		api.scan( action )
			.then( load )
			.catch( ( error ) => notify( error.message, { status: 'error' } ) )
			.finally( () => setBusy( false ) );
	};

	if ( ! summary ) {
		return <Spinner />;
	}

	const { counts, scan, next_scan_at: nextScanAt } = summary;
	const checked =
		counts.ok + counts.redirect + counts.broken + counts.unverifiable;
	const neverScanned = ! scan.last_finished_at && scan.status === 'idle';

	return (
		<div className="seekfix-summary">
			{ neverScanned && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No scan yet. Start one to find the broken links in your posts and pages. It runs on your server and sends nothing to any external service.',
						'seekfix'
					) }
				</Notice>
			) }

			<div className="seekfix-stats">
				<Stat
					label={ __( 'Broken', 'seekfix' ) }
					value={ counts.broken }
					tone="is-broken"
					status="broken"
					onOpen={ onOpenLinks }
				/>
				<Stat
					label={ __( 'Cannot verify', 'seekfix' ) }
					value={ counts.unverifiable }
					status="unverifiable"
					onOpen={ onOpenLinks }
				/>
				<Stat
					label={ __( 'Redirects', 'seekfix' ) }
					value={ counts.redirect }
					status="redirect"
					onOpen={ onOpenLinks }
				/>
				<Stat label={ __( 'Checked', 'seekfix' ) } value={ checked } />
				<Stat
					label={ __( 'Waiting to be checked', 'seekfix' ) }
					value={ counts.pending }
				/>
			</div>

			{ scan.status !== 'idle' && <ScanProgress scan={ scan } /> }

			<Flex justify="flex-start" gap={ 2 } className="seekfix-controls">
				{ scan.status === 'idle' && (
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () => control( 'start' ) }
					>
						{ neverScanned
							? __( 'Start scan', 'seekfix' )
							: __( 'Scan again', 'seekfix' ) }
					</Button>
				) }
				{ scan.status === 'running' && (
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => control( 'pause' ) }
					>
						{ __( 'Pause', 'seekfix' ) }
					</Button>
				) }
				{ scan.status === 'paused' && (
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => control( 'resume' ) }
					>
						{ __( 'Resume', 'seekfix' ) }
					</Button>
				) }
				{ scan.status !== 'idle' && (
					<Button
						variant="tertiary"
						isDestructive
						disabled={ busy }
						onClick={ () => control( 'cancel' ) }
					>
						{ __( 'Cancel', 'seekfix' ) }
					</Button>
				) }
			</Flex>

			{ scan.last_finished_at && (
				<p className="seekfix-muted">
					{ sprintf(
						/* translators: %s: date and time. */
						__( 'Last scan finished: %s', 'seekfix' ),
						formatDate( scan.last_finished_at )
					) }
				</p>
			) }
			{ nextScanAt && scan.status === 'idle' && (
				<p className="seekfix-muted">
					{ sprintf(
						/* translators: %s: date and time. */
						__( 'Next automatic scan: %s', 'seekfix' ),
						formatDate( nextScanAt )
					) }
				</p>
			) }
			{ running && (
				<p className="seekfix-muted">
					{ cronDisabled
						? __(
								'Keep this screen open until the scan finishes. Links that fail once are checked again before they are marked broken.',
								'seekfix'
							)
						: __(
								'You can leave this screen: the scan continues in the background, more slowly. Links that fail once are checked again before they are marked broken.',
								'seekfix'
							) }
				</p>
			) }
		</div>
	);
}
