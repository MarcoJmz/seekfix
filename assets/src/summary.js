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
	Spinner,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, chevronRight } from '@wordpress/icons';

import { api } from './api';
import Intro from './help';
import { formatDate, toDate } from './labels';
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
 * @param {string}                   props.help   What the count means.
 * @param {number}                   props.value  Value.
 * @param {string}                   props.tone   Extra class.
 * @param {string}                   props.status Status to filter the Links tab by.
 * @param {(status: string) => void} props.onOpen Opens the Links tab without reloading.
 * @return {Element} Element.
 */
function Stat( { label, help, value, tone = '', status, onOpen } ) {
	const body = (
		<>
			<div className="seekfix-stat__label">{ label }</div>
			<div className="seekfix-stat__value">
				{ Number( value || 0 ).toLocaleString() }
			</div>
			{ help && <div className="seekfix-stat__help">{ help }</div> }
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
 * Seconds since a date, updated every second while `live`.
 *
 * @param {string|null} since RFC 3339 date.
 * @param {boolean}     live  Whether to keep counting.
 * @return {number|null} Seconds, or null without a date.
 */
function useElapsed( since, live ) {
	const [ now, setNow ] = useState( () => Date.now() );

	useEffect( () => {
		if ( ! live ) {
			return undefined;
		}

		const timer = window.setInterval( () => setNow( Date.now() ), 1000 );

		return () => window.clearInterval( timer );
	}, [ live ] );

	if ( ! since ) {
		return null;
	}

	return Math.max(
		0,
		Math.floor( ( now - toDate( since ).getTime() ) / 1000 )
	);
}

/**
 * "1 h 05 min", "4 min 20 s" or "12 s".
 *
 * @param {number} seconds Seconds.
 * @return {string} Text.
 */
function formatDuration( seconds ) {
	const hours = Math.floor( seconds / 3600 );
	const minutes = Math.floor( ( seconds % 3600 ) / 60 );
	const rest = seconds % 60;

	/*
	 * The translator comments sit inside sprintf() so the minifier keeps
	 * them next to __() when it folds these returns into one expression.
	 */
	if ( hours > 0 ) {
		return sprintf(
			/* translators: 1: hours, 2: minutes. */
			__( '%1$d h %2$02d min', 'seekfix' ),
			hours,
			minutes
		);
	}

	if ( minutes > 0 ) {
		return sprintf(
			/* translators: 1: minutes, 2: seconds. */
			__( '%1$d min %2$02d s', 'seekfix' ),
			minutes,
			rest
		);
	}

	return sprintf(
		/* translators: %d: seconds. */
		__( '%d s', 'seekfix' ),
		rest
	);
}

/**
 * Card of a running or paused scan: phase, a large bar and the time spent.
 *
 * Between two answers of the server the bar's stripes move and the timer
 * counts, so the scan never looks stuck.
 *
 * @param {Object}  props         Props.
 * @param {Object}  props.scan    Progress from the API.
 * @param {boolean} props.working Whether a scan step is being sent.
 * @return {Element} Element.
 */
function ScanProgress( { scan, working } ) {
	const running = scan.status === 'running';
	const extracting = scan.phase === 'extract';
	const done = extracting ? scan.posts_done : scan.urls_checked;
	const total = extracting ? scan.posts_total : scan.urls_total;
	const known = total > 0;
	const percent = known ? Math.min( 100, ( done / total ) * 100 ) : 0;
	const elapsed = useElapsed( scan.started_at, running );

	const phase = extracting
		? __( 'Step 1 of 2: reading your posts and pages', 'seekfix' )
		: __( 'Step 2 of 2: checking each URL', 'seekfix' );

	const count = extracting
		? sprintf(
				/* translators: 1: posts read, 2: total posts. */
				_n(
					'%1$s of %2$s post read',
					'%1$s of %2$s posts read',
					total,
					'seekfix'
				),
				done.toLocaleString(),
				total.toLocaleString()
			)
		: sprintf(
				/* translators: 1: URLs checked, 2: total URLs. */
				_n(
					'%1$s of %2$s URL checked',
					'%1$s of %2$s URLs checked',
					total,
					'seekfix'
				),
				done.toLocaleString(),
				total.toLocaleString()
			);

	return (
		<Card
			className={ `seekfix-progress ${
				running ? 'is-running' : 'is-paused'
			}` }
		>
			<CardBody>
				<Flex justify="space-between" align="center">
					<div className="seekfix-progress__title">
						{ running && <Spinner /> }
						<strong>
							{ running
								? __( 'Scan in progress', 'seekfix' )
								: __( 'Scan paused', 'seekfix' ) }
						</strong>
					</div>
					{ known && (
						<span className="seekfix-progress__percent">
							{ Math.floor( percent ) }%
						</span>
					) }
				</Flex>

				<p className="seekfix-progress__phase">{ phase }</p>

				<div
					className={
						'seekfix-progress__track' +
						( known ? '' : ' is-indeterminate' )
					}
					role="progressbar"
					aria-label={ phase }
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
					aria-valuenow={ known ? Math.floor( percent ) : undefined }
				>
					<div
						className="seekfix-progress__fill"
						style={ known ? { width: `${ percent }%` } : undefined }
					/>
				</div>

				<Flex
					justify="space-between"
					wrap
					className="seekfix-progress__meta"
				>
					<span aria-live="polite">
						{ known
							? count
							: __( 'Counting what to scan…', 'seekfix' ) }
					</span>
					<span className="seekfix-muted">
						{ running &&
							working &&
							__( 'Working…', 'seekfix' ) + ' · ' }
						{ running &&
							elapsed !== null &&
							sprintf(
								/* translators: %s: time since the scan started, like "4 min 20 s". */
								__( 'Running for %s', 'seekfix' ),
								formatDuration( elapsed )
							) }
					</span>
				</Flex>

				{ ! running && (
					<p className="seekfix-muted">
						{ __(
							'Nothing is checked while the scan is paused. Resume it to continue where it stopped.',
							'seekfix'
						) }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

export default function SummaryTab( { onOpenLinks } ) {
	const notify = useNotify();
	const [ summary, setSummary ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ working, setWorking ] = useState( false );
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

		const step = () => {
			if ( stepping.current ) {
				return;
			}

			stepping.current = true;
			setWorking( true );

			api.scan( 'step' )
				.then( load )
				.catch( ( error ) =>
					notify( error.message, { status: 'error' } )
				)
				.finally( () => {
					stepping.current = false;
					setWorking( false );
				} );
		};

		// The first step goes right away, so the numbers move as soon as
		// the scan starts or the screen opens.
		step();

		const timer = window.setInterval( step, POLL_MS );

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
			<Intro>
				<p>
					{ __(
						'SeekFix reads your posts and pages, then checks every link, button and image in them. Broken links are listed in the Links tab, where you can fix them without opening each post.',
						'seekfix'
					) }
				</p>
			</Intro>

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
					help={ __(
						'Not found or gone, confirmed by a second check.',
						'seekfix'
					) }
					value={ counts.broken }
					tone="is-broken"
					status="broken"
					onOpen={ onOpenLinks }
				/>
				<Stat
					label={ __( 'Cannot verify', 'seekfix' ) }
					help={ __(
						'The site blocked the check or did not answer. Open them to check by hand.',
						'seekfix'
					) }
					value={ counts.unverifiable }
					status="unverifiable"
					onOpen={ onOpenLinks }
				/>
				<Stat
					label={ __( 'Redirects', 'seekfix' ) }
					help={ __(
						'They work, but go through another address first.',
						'seekfix'
					) }
					value={ counts.redirect }
					status="redirect"
					onOpen={ onOpenLinks }
				/>
				<Stat
					label={ __( 'Checked', 'seekfix' ) }
					help={ __( 'Different URLs checked so far.', 'seekfix' ) }
					value={ checked }
				/>
				<Stat
					label={ __( 'Waiting to be checked', 'seekfix' ) }
					help={ __(
						'Found in your content, not checked yet.',
						'seekfix'
					) }
					value={ counts.pending }
				/>
			</div>

			{ scan.status !== 'idle' && (
				<ScanProgress scan={ scan } working={ working } />
			) }

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
