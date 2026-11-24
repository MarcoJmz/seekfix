/**
 * Settings tab: what to scan, automatic scans, e-mail, domain rules and
 * deleting the plugin's data.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	Modal,
	SelectControl,
	Spinner,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { api } from './api';
import Intro from './help';
import { useNotify } from './notices';

const { postTypes = [], adminEmail = '' } = window.seekfixAdmin || {};

const toLines = ( list ) => ( list || [] ).join( '\n' );
const fromLines = ( text ) =>
	text
		.split( /[\n,]+/ )
		.map( ( line ) => line.trim() )
		.filter( Boolean );

export default function SettingsTab() {
	const notify = useNotify();
	const [ settings, setSettings ] = useState( null );
	const [ unverifiable, setUnverifiable ] = useState( '' );
	const [ exclude, setExclude ] = useState( '' );
	const [ recipients, setRecipients ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ confirmReset, setConfirmReset ] = useState( false );

	const apply = ( saved ) => {
		setSettings( saved );
		setUnverifiable( toLines( saved.host_rules.unverifiable ) );
		setExclude( toLines( saved.host_rules.exclude ) );
		setRecipients( toLines( saved.email_recipients ) );
	};

	useEffect( () => {
		api.getSettings()
			.then( apply )
			.catch( ( error ) => notify( error.message, { status: 'error' } ) );
	}, [ notify ] );

	if ( ! settings ) {
		return <Spinner />;
	}

	// null means "every public type".
	const selected =
		settings.post_types || postTypes.map( ( type ) => type.value );

	const toggleType = ( value, checked ) =>
		setSettings( {
			...settings,
			post_types: checked
				? [ ...selected, value ]
				: selected.filter( ( type ) => type !== value ),
		} );

	const save = () => {
		setSaving( true );

		const typed = fromLines( recipients );

		api.saveSettings( {
			post_types: settings.post_types,
			include_drafts: settings.include_drafts,
			schedule: settings.schedule,
			email_enabled: settings.email_enabled,
			email_recipients: typed,
			history_days: settings.history_days,
			host_rules: {
				unverifiable: fromLines( unverifiable ),
				exclude: fromLines( exclude ),
			},
		} )
			.then( ( saved ) => {
				apply( saved );
				notify(
					__(
						'Settings saved. They apply from the next scan.',
						'seekfix'
					)
				);

				if ( saved.email_recipients.length < typed.length ) {
					notify(
						__(
							'Some e-mail addresses were not valid and were removed.',
							'seekfix'
						)
					);
				}
			} )
			.catch( ( error ) => notify( error.message, { status: 'error' } ) )
			.finally( () => setSaving( false ) );
	};

	const reset = () =>
		api
			.reset()
			.then( () => {
				setConfirmReset( false );
				notify(
					__(
						'All SeekFix data was deleted. Posts were not changed.',
						'seekfix'
					)
				);
			} )
			.catch( ( error ) => notify( error.message, { status: 'error' } ) );

	return (
		<div className="seekfix-settings">
			<Intro>
				<p>
					{ __(
						'Choose what SeekFix scans, when it scans on its own and who gets an e-mail about new broken links. Changes apply from the next scan.',
						'seekfix'
					) }
				</p>
			</Intro>

			<Card>
				<CardHeader>
					<h2>{ __( 'What to scan', 'seekfix' ) }</h2>
				</CardHeader>
				<CardBody>
					<p className="seekfix-muted">
						{ __(
							'SeekFix checks the links, buttons and images in the content of these types. Menus, widgets, comments and custom fields are not scanned.',
							'seekfix'
						) }
					</p>
					<fieldset>
						<legend>{ __( 'Content types', 'seekfix' ) }</legend>
						{ postTypes.map( ( type ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ type.value }
								label={ type.label }
								checked={ selected.includes( type.value ) }
								onChange={ ( checked ) =>
									toggleType( type.value, checked )
								}
							/>
						) ) }
					</fieldset>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Include drafts and posts pending review',
							'seekfix'
						) }
						checked={ settings.include_drafts }
						onChange={ ( value ) =>
							setSettings( {
								...settings,
								include_drafts: value,
							} )
						}
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Automatic scans', 'seekfix' ) }</h2>
				</CardHeader>
				<CardBody>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Scan all content', 'seekfix' ) }
						help={ __(
							'Scans start around 3:00 site time and run in the background with WP-Cron, which needs visits to the site or a server cron job.',
							'seekfix'
						) }
						value={ settings.schedule }
						options={ [
							{ value: 'off', label: __( 'Never', 'seekfix' ) },
							{
								value: 'daily',
								label: __( 'Every day', 'seekfix' ),
							},
							{
								value: 'weekly',
								label: __( 'Every week', 'seekfix' ),
							},
						] }
						onChange={ ( value ) =>
							setSettings( { ...settings, schedule: value } )
						}
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'E-mail', 'seekfix' ) }</h2>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'E-mail me when a scan finds new broken links',
							'seekfix'
						) }
						help={ __(
							'One e-mail per scan. Each link is reported once.',
							'seekfix'
						) }
						checked={ settings.email_enabled }
						onChange={ ( value ) =>
							setSettings( {
								...settings,
								email_enabled: value,
							} )
						}
					/>
					{ settings.email_enabled && (
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Send to', 'seekfix' ) }
							help={ sprintf(
								/* translators: %s: the site's admin e-mail address. */
								__(
									'One address per line. Leave it empty to use the site admin address (%s).',
									'seekfix'
								),
								adminEmail
							) }
							value={ recipients }
							onChange={ setRecipients }
							rows={ 3 }
						/>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Fix history', 'seekfix' ) }</h2>
				</CardHeader>
				<CardBody>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Keep fixes in the History tab',
							'seekfix'
						) }
						help={ __(
							'Older fixes are deleted once a day and can no longer be undone from SeekFix. The revisions WordPress saved before each fix stay with the post.',
							'seekfix'
						) }
						value={ String( settings.history_days ) }
						options={ [
							{
								value: '30',
								label: __( 'For 30 days', 'seekfix' ),
							},
							{
								value: '90',
								label: __( 'For 90 days', 'seekfix' ),
							},
							{
								value: '180',
								label: __( 'For 180 days', 'seekfix' ),
							},
							{
								value: '365',
								label: __( 'For 1 year', 'seekfix' ),
							},
							{ value: '0', label: __( 'Forever', 'seekfix' ) },
						] }
						onChange={ ( value ) =>
							setSettings( {
								...settings,
								history_days: Number( value ),
							} )
						}
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Domain rules', 'seekfix' ) }</h2>
				</CardHeader>
				<CardBody>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Always “cannot verify”', 'seekfix' ) }
						help={ __(
							'One domain per line. Sites that block automated checks. Subdomains are included.',
							'seekfix'
						) }
						value={ unverifiable }
						onChange={ setUnverifiable }
						rows={ 4 }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Never check', 'seekfix' ) }
						help={ __(
							'One domain per line. Links to these domains are not checked or listed.',
							'seekfix'
						) }
						value={ exclude }
						onChange={ setExclude }
						rows={ 4 }
					/>
				</CardBody>
			</Card>

			<Flex justify="flex-start">
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving }
					onClick={ save }
				>
					{ __( 'Save settings', 'seekfix' ) }
				</Button>
			</Flex>

			<Card className="seekfix-danger">
				<CardHeader>
					<h2>{ __( 'Delete data', 'seekfix' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Deletes the scan results and the history of fixes. Your posts and their revisions are not changed, and settings are kept. Fixes made so far can no longer be undone from SeekFix.',
							'seekfix'
						) }
					</p>
					<Button
						variant="secondary"
						isDestructive
						onClick={ () => setConfirmReset( true ) }
					>
						{ __( 'Delete all SeekFix data', 'seekfix' ) }
					</Button>
				</CardBody>
			</Card>

			{ confirmReset && (
				<Modal
					title={ __( 'Delete all SeekFix data?', 'seekfix' ) }
					onRequestClose={ () => setConfirmReset( false ) }
					size="small"
				>
					<p>
						{ __(
							'This cannot be undone. A new scan rebuilds the results.',
							'seekfix'
						) }
					</p>
					<Flex justify="flex-end" gap={ 2 }>
						<Button
							variant="tertiary"
							onClick={ () => setConfirmReset( false ) }
						>
							{ __( 'Cancel', 'seekfix' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ reset }
						>
							{ __( 'Delete', 'seekfix' ) }
						</Button>
					</Flex>
				</Modal>
			) }
		</div>
	);
}
